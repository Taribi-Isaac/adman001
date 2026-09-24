<?php

namespace App\Services\Ai;

use App\Ai\Tools\AiTool;
use App\Ai\Tools\CreatePaymentClaimTool;
use App\Ai\Tools\GetBusinessInformationTool;
use App\Ai\Tools\GetCustomerContextTool;
use App\Ai\Tools\GetCustomerInvoicesTool;
use App\Ai\Tools\GetCustomerQuotesTool;
use App\Ai\Tools\GetCustomerRecurringBillingTool;
use App\Ai\Tools\GetInvoicePaymentStatusTool;
use App\Ai\Tools\GetInvoicePaymentsTool;
use App\Ai\Tools\GetInvoiceTool;
use App\Ai\Tools\GetPaymentAcknowledgementTool;
use App\Ai\Tools\GetSecureDocumentLinkTool;
use App\Ai\Tools\RequestHumanHandoffTool;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\Log;

class AiToolRegistry
{
    /** @var array<string, AiTool>|null */
    private ?array $tools = null;

    public function __construct(private readonly AuditLogger $auditLogger) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function openAiToolSchemas(): array
    {
        $schemas = [];
        foreach ($this->all() as $tool) {
            $schemas[] = [
                'type' => 'function',
                'function' => [
                    'name' => $tool->name(),
                    'description' => $tool->description(),
                    'parameters' => $tool->parametersSchema(),
                ],
            ];
        }

        return $schemas;
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    public function execute(string $name, Conversation $conversation, Message $inbound, array $arguments): array
    {
        $tool = $this->all()[$name] ?? null;
        if ($tool === null) {
            $this->auditLogger->record(
                event: 'ai.tool_denied',
                description: 'Unknown AI tool requested',
                auditable: $conversation,
                meta: ['tool' => $name],
            );

            return ['ok' => false, 'error' => 'Unknown tool.'];
        }

        try {
            $result = $tool->execute($conversation, $inbound, $arguments);
            $this->auditLogger->record(
                event: 'ai.tool_called',
                description: 'AI tool executed',
                auditable: $conversation,
                meta: [
                    'tool' => $name,
                    'ok' => (bool) ($result['ok'] ?? false),
                ],
            );

            return $result;
        } catch (\Throwable $e) {
            report($e);
            Log::warning('ai.tool_failed', ['tool' => $name, 'message' => $e->getMessage()]);
            $this->auditLogger->record(
                event: 'ai.tool_denied',
                description: 'AI tool failed',
                auditable: $conversation,
                meta: ['tool' => $name],
            );

            return ['ok' => false, 'error' => 'Tool execution failed.'];
        }
    }

    /**
     * @return array<string, AiTool>
     */
    private function all(): array
    {
        if ($this->tools !== null) {
            return $this->tools;
        }

        $instances = [
            app(GetBusinessInformationTool::class),
            app(GetCustomerContextTool::class),
            app(GetCustomerInvoicesTool::class),
            app(GetInvoiceTool::class),
            app(GetInvoicePaymentStatusTool::class),
            app(GetInvoicePaymentsTool::class),
            app(GetCustomerQuotesTool::class),
            app(GetCustomerRecurringBillingTool::class),
            app(GetPaymentAcknowledgementTool::class),
            app(GetSecureDocumentLinkTool::class),
            app(CreatePaymentClaimTool::class),
            app(RequestHumanHandoffTool::class),
        ];

        $map = [];
        foreach ($instances as $tool) {
            $map[$tool->name()] = $tool;
        }

        return $this->tools = $map;
    }
}
