<?php

namespace App\Ai\Tools;

use App\Models\Conversation;
use App\Models\Message;
use App\Services\AuditLogger;
use App\Services\ConversationService;

final class RequestHumanHandoffTool implements AiTool
{
    public function __construct(
        private readonly ConversationService $conversations,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function name(): string
    {
        return 'request_human_handoff';
    }

    public function description(): string
    {
        return 'Escalate the conversation to a human staff member. Use when the customer asks for a person, the request is ambiguous/financially risky, or authorized tools cannot answer.';
    }

    public function parametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'reason' => [
                    'type' => 'string',
                    'description' => 'Short reason for staff',
                ],
            ],
            'required' => ['reason'],
            'additionalProperties' => false,
        ];
    }

    public function execute(Conversation $conversation, Message $inbound, array $arguments): array
    {
        $reason = trim((string) ($arguments['reason'] ?? 'Customer needs staff assistance'));
        $this->conversations->escalateToHuman($conversation, $reason);

        $this->auditLogger->record(
            event: 'ai.handoff_requested',
            description: 'AI requested human handoff',
            auditable: $conversation,
            meta: [
                'reason' => $reason,
                'inbound_message_id' => $inbound->id,
            ],
        );

        return [
            'ok' => true,
            'handed_off' => true,
            'mode' => 'human',
            'message_for_customer' => 'I am connecting you with a team member who can help further. Someone will follow up with you shortly.',
        ];
    }
}
