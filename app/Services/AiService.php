<?php

namespace App\Services;

use App\Contracts\AiProvider;
use App\Enums\AiProcessingStatus;
use App\Enums\CommunicationChannel;
use App\Enums\ConversationMode;
use App\Enums\MessageActorType;
use App\Enums\MessageDirection;
use App\Models\AiMessageProcessing;
use App\Models\Business;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\Ai\AiToolRegistry;
use Illuminate\Database\UniqueConstraintViolationException;
use Throwable;

/**
 * Controlled AI conversation assistant. Laravel remains authoritative for all business truth.
 */
class AiService
{
    private const MAX_TOOL_ROUNDS = 5;

    public function __construct(
        private readonly AiProvider $provider,
        private readonly AiToolRegistry $tools,
        private readonly ConversationService $conversations,
        private readonly WhatsAppOutboundService $whatsapp,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function isCustomerResponseEnabled(?Business $business = null): bool
    {
        $business ??= Business::current();

        return (bool) $business->ai_enabled
            && (bool) $business->ai_customer_responses_enabled
            && (bool) config('adman.ai.enabled', true);
    }

    /**
     * Claim + process an inbound message for AI (idempotent).
     */
    public function processInboundMessage(Message $inbound): ?AiMessageProcessing
    {
        $inbound->loadMissing(['conversation.identity.contact', 'conversation.contact']);
        $conversation = $inbound->conversation;
        if ($conversation === null) {
            return null;
        }

        $processing = $this->claimProcessing($inbound, $conversation);
        if ($processing === null) {
            return AiMessageProcessing::query()->where('inbound_message_id', $inbound->id)->first();
        }

        if ($processing->status !== AiProcessingStatus::Pending) {
            return $processing;
        }

        try {
            return $this->runProcessing($processing, $inbound, $conversation);
        } catch (Throwable $e) {
            report($e);
            $processing->status = AiProcessingStatus::Failed;
            $processing->failure_reason = 'AI processing failed unexpectedly.';
            $processing->completed_at = now();
            $processing->save();

            $this->auditLogger->record(
                event: 'ai.processing_failed',
                description: 'AI processing failed',
                auditable: $conversation,
                meta: ['inbound_message_id' => $inbound->id],
            );

            return $processing->refresh();
        }
    }

    private function claimProcessing(Message $inbound, Conversation $conversation): ?AiMessageProcessing
    {
        try {
            return AiMessageProcessing::query()->create([
                'inbound_message_id' => $inbound->id,
                'conversation_id' => $conversation->id,
                'status' => AiProcessingStatus::Pending,
            ]);
        } catch (UniqueConstraintViolationException) {
            return null;
        }
    }

    private function runProcessing(
        AiMessageProcessing $processing,
        Message $inbound,
        Conversation $conversation,
    ): AiMessageProcessing {
        $conversation->refresh();

        if (! $this->isCustomerResponseEnabled()) {
            return $this->skip($processing, 'AI customer responses are disabled');
        }

        if ($conversation->mode !== ConversationMode::Ai) {
            return $this->skip($processing, 'Conversation is not in AI mode');
        }

        if ($inbound->direction !== MessageDirection::Inbound) {
            return $this->skip($processing, 'Not an inbound message');
        }

        if ($conversation->channel !== CommunicationChannel::WhatsApp) {
            return $this->skip($processing, 'AI auto-response currently supports WhatsApp inbound only');
        }

        // Explicit human request shortcut (still uses handoff tool semantics).
        if ($this->looksLikeHumanRequest((string) $inbound->body)) {
            $handoff = $this->tools->execute(
                'request_human_handoff',
                $conversation,
                $inbound,
                ['reason' => 'Customer requested a human'],
            );
            $reply = (string) ($handoff['message_for_customer']
                ?? 'I am connecting you with a team member who can help further.');

            return $this->deliverReply($processing, $conversation, $inbound, $reply);
        }

        $result = $this->generateReply($conversation, $inbound);
        if ($result['failed'] ?? false) {
            $processing->status = AiProcessingStatus::Failed;
            $processing->failure_reason = (string) ($result['error'] ?? 'Provider failure');
            $processing->completed_at = now();
            $processing->save();

            $this->auditLogger->record(
                event: 'ai.processing_failed',
                description: 'AI provider failure',
                auditable: $conversation,
                meta: ['inbound_message_id' => $inbound->id],
            );

            // Leave conversation available for humans; do not fabricate a customer reply.
            return $processing->refresh();
        }

        $reply = trim((string) ($result['text'] ?? ''));
        if ($reply === '') {
            return $this->skip($processing, 'Empty AI response');
        }

        return $this->deliverReply($processing, $conversation, $inbound, $reply);
    }

    /**
     * @return array{text?: string, failed?: bool, error?: string}
     */
    private function generateReply(Conversation $conversation, Message $inbound): array
    {
        $business = Business::current();
        $history = $this->buildHistory($conversation, $inbound);
        $messages = [
            ['role' => 'system', 'content' => $this->systemPrompt($business, $conversation)],
            ...$history,
        ];

        $tools = $this->tools->openAiToolSchemas();
        $rounds = 0;

        while ($rounds < self::MAX_TOOL_ROUNDS) {
            $rounds++;
            $response = $this->provider->complete($messages, $tools);

            if ($response->failed) {
                return [
                    'failed' => true,
                    'error' => $response->failureReason ?? 'AI provider failed',
                ];
            }

            if (! $response->hasToolCalls()) {
                return ['text' => (string) $response->text];
            }

            $assistantToolMessage = [
                'role' => 'assistant',
                'content' => $response->text,
                'tool_calls' => array_map(fn (array $call) => [
                    'id' => $call['id'],
                    'type' => 'function',
                    'function' => [
                        'name' => $call['name'],
                        'arguments' => json_encode($call['arguments'], JSON_THROW_ON_ERROR),
                    ],
                ], $response->toolCalls),
            ];
            $messages[] = $assistantToolMessage;

            $handedOff = false;
            $handoffCustomerMessage = null;

            foreach ($response->toolCalls as $call) {
                $toolResult = $this->tools->execute(
                    $call['name'],
                    $conversation->fresh(['identity.contact', 'contact']) ?? $conversation,
                    $inbound,
                    $call['arguments'],
                );

                $messages[] = [
                    'role' => 'tool',
                    'tool_call_id' => $call['id'],
                    'content' => json_encode($toolResult, JSON_THROW_ON_ERROR),
                ];

                if ($call['name'] === 'request_human_handoff' && ($toolResult['ok'] ?? false)) {
                    $handedOff = true;
                    $handoffCustomerMessage = $toolResult['message_for_customer'] ?? null;
                }
            }

            if ($handedOff) {
                return [
                    'text' => is_string($handoffCustomerMessage) && $handoffCustomerMessage !== ''
                        ? $handoffCustomerMessage
                        : 'I am connecting you with a team member who can help further.',
                ];
            }

            // Re-check mode after tools (handoff may have switched to Human — stop further tools next loop via fresh).
            $conversation->refresh();
            if ($conversation->mode !== ConversationMode::Ai) {
                // Already handed off; if we somehow continue without handoff message, stop cleanly.
                return [
                    'text' => $handoffCustomerMessage
                        ?? 'A team member will continue from here.',
                ];
            }
        }

        return [
            'text' => 'I need a team member to help with this request. Someone will follow up with you shortly.',
        ];
    }

    private function deliverReply(
        AiMessageProcessing $processing,
        Conversation $conversation,
        Message $inbound,
        string $reply,
    ): AiMessageProcessing {
        $conversation->refresh();
        // After handoff, still allow the handoff acknowledgement to send once.
        if ($conversation->mode === ConversationMode::Closed) {
            return $this->skip($processing, 'Conversation closed before reply');
        }

        try {
            $outbound = $this->whatsapp->queueAiSessionReply($conversation, $reply);
        } catch (Throwable $e) {
            report($e);
            $processing->status = AiProcessingStatus::Failed;
            $processing->failure_reason = 'Failed to queue AI WhatsApp reply.';
            $processing->completed_at = now();
            $processing->save();

            return $processing->refresh();
        }

        $processing->status = AiProcessingStatus::Completed;
        $processing->outbound_message_id = $outbound->id;
        $processing->completed_at = now();
        $processing->failure_reason = null;
        $processing->skip_reason = null;
        $processing->save();

        $this->auditLogger->record(
            event: 'ai.response_generated',
            description: 'AI response queued for delivery',
            auditable: $conversation,
            newValues: [
                'inbound_message_id' => $inbound->id,
                'outbound_message_id' => $outbound->id,
            ],
        );

        return $processing->refresh();
    }

    private function skip(AiMessageProcessing $processing, string $reason): AiMessageProcessing
    {
        $processing->status = AiProcessingStatus::Skipped;
        $processing->skip_reason = $reason;
        $processing->completed_at = now();
        $processing->save();

        return $processing->refresh();
    }

    /**
     * @return list<array{role: string, content: string}>
     */
    private function buildHistory(Conversation $conversation, Message $inbound): array
    {
        $recent = Message::query()
            ->where('conversation_id', $conversation->id)
            ->where('id', '<=', $inbound->id)
            ->orderByDesc('id')
            ->limit(12)
            ->get()
            ->reverse()
            ->values();

        $history = [];
        foreach ($recent as $message) {
            $role = match ($message->actor_type) {
                MessageActorType::External => 'user',
                MessageActorType::Ai, MessageActorType::System, MessageActorType::Staff => 'assistant',
            };
            $body = trim((string) $message->body);
            if ($body === '') {
                continue;
            }
            $history[] = ['role' => $role, 'content' => mb_substr($body, 0, 2000)];
        }

        return $history;
    }

    private function systemPrompt(Business $business, Conversation $conversation): string
    {
        $context = app(\App\Services\Ai\AiBusinessContextAssembler::class)
            ->assemble($business, $conversation);

        return <<<PROMPT
You are the ADMAN assistant for {$business->name}. You help customers and unknown contacts over WhatsApp.

Rules:
- Be concise, professional, and factual.
- Laravel tools are the only source of customer-specific and financial truth. Never invent amounts, invoices, payments, or links.
- Never claim a payment is confirmed because the customer says they paid. Payment claims require staff verification.
- If multiple outstanding invoices could match a payment, ask which invoice — do not guess.
- If you cannot answer with authorized tools or approved business knowledge, say so and call request_human_handoff.
- If the customer asks for a human, call request_human_handoff.
- Do not expose internal IDs, tool names, permissions, stack traces, or secrets.
- Do not change business settings or confirm payments.
- Do not invent services, policies, or operating details that are not in the business knowledge below.
- If the customer sent a file/media and you cannot interpret its contents, acknowledge receipt and say the team will review it. Never claim you reviewed the file.

Approved business knowledge:
{$context}
PROMPT;
    }

    private function looksLikeHumanRequest(string $body): bool
    {
        return preg_match(
            '/\b(human|real person|speak to (someone|a person|an? agent|support)|talk to (someone|a person|an? agent|support)|customer service|representative)\b/i',
            $body,
        ) === 1;
    }
}
