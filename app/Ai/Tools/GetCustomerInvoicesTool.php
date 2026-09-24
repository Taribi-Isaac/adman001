<?php

namespace App\Ai\Tools;

use App\Enums\InvoiceLifecycleStatus;
use App\Models\Conversation;
use App\Models\Invoice;
use App\Models\Message;
use App\Services\Ai\AiAuthorization;

final class GetCustomerInvoicesTool implements AiTool
{
    public function __construct(private readonly AiAuthorization $auth) {}

    public function name(): string
    {
        return 'get_customer_invoices';
    }

    public function description(): string
    {
        return 'List the authorized customer\'s invoices (number, due date, totals, payment status). Does not invent data.';
    }

    public function parametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'outstanding_only' => [
                    'type' => 'boolean',
                    'description' => 'If true, only invoices with an outstanding balance.',
                ],
            ],
            'additionalProperties' => false,
        ];
    }

    public function execute(Conversation $conversation, Message $inbound, array $arguments): array
    {
        $contact = $this->auth->authorizedContact($conversation);
        if ($contact === null) {
            return ['ok' => false, 'error' => 'Customer-specific invoice data is not available for this sender.'];
        }

        $outstandingOnly = (bool) ($arguments['outstanding_only'] ?? false);

        $query = Invoice::query()
            ->where('contact_id', $contact->id)
            ->where('lifecycle_status', '!=', InvoiceLifecycleStatus::Draft->value)
            ->orderByDesc('id')
            ->limit(25);

        $invoices = $query->get()->filter(function (Invoice $invoice) use ($outstandingOnly) {
            if ($outstandingOnly) {
                return $invoice->payment_status->isOutstanding()
                    && (float) $invoice->balance_due > 0
                    && $invoice->lifecycle_status === InvoiceLifecycleStatus::Issued;
            }

            return true;
        })->values();

        return [
            'ok' => true,
            'count' => $invoices->count(),
            'invoices' => $invoices->map(fn (Invoice $invoice) => [
                'number' => $invoice->number,
                'lifecycle_status' => $invoice->lifecycle_status->value,
                'payment_status' => $invoice->payment_status->value,
                'payment_status_label' => $invoice->payment_status->label(),
                'total' => (string) $invoice->total,
                'amount_paid' => (string) $invoice->amount_paid,
                'balance_due' => (string) $invoice->balance_due,
                'currency_code' => $invoice->currency_code,
                'due_date' => $invoice->due_date?->toDateString(),
                'due_state' => $invoice->dueState()?->value,
            ])->all(),
        ];
    }
}
