<?php

namespace App\Services;

use App\Enums\CommunicationChannel;
use App\Enums\MessageActorType;
use App\Enums\RecurringBillingDeliveryStatus;
use App\Enums\RecurringBillingGenerationStatus;
use App\Models\Document;
use App\Models\Invoice;
use App\Models\Message;
use App\Models\RecurringBillingDelivery;
use App\Models\RecurringBillingGeneration;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * After a recurring invoice is committed: generate its PDF once, then hand it to each configured
 * channel through the existing invoice email / WhatsApp paths (all their consent, window and
 * template rules apply).
 *
 * Never touches the invoice or the generation status: a delivery problem is recorded on the
 * delivery row (or `pdf_failure_reason`) and the invoice stays issued.
 */
class RecurringInvoiceDeliveryService
{
    public function __construct(
        private readonly DocumentService $documents,
        private readonly EmailOutboundService $emails,
        private readonly WhatsAppOutboundService $whatsapp,
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * Safe to run any number of times: the PDF is generated once and each channel is queued at most once.
     *
     * @throws Throwable unexpected errors, so the queue retries; channels already handled are skipped on retry
     */
    public function process(int $generationId): void
    {
        $generation = RecurringBillingGeneration::query()->find($generationId);
        if ($generation === null
            || $generation->status !== RecurringBillingGenerationStatus::Succeeded
            || $generation->invoice_id === null) {
            return;
        }

        try {
            $actor = $this->systemActor();
        } catch (Throwable $e) {
            $this->recordPdfFailure($generation, 'No user is available to act for automated invoice delivery.');

            throw $e;
        }

        $this->ensurePdf($generation, $actor);

        $failure = null;
        foreach ($generation->delivery_channel?->channels() ?? [] as $channel) {
            try {
                $this->deliver($generation, $channel, $actor);
            } catch (Throwable $e) {
                report($e);
                $failure ??= $e;
            }
        }

        if ($failure !== null) {
            throw $failure;
        }
    }

    /**
     * Called when the job has used up its attempts: nothing may stay silently pending.
     */
    public function markUndelivered(int $generationId, string $reason): void
    {
        $generation = RecurringBillingGeneration::query()->find($generationId);
        if ($generation === null) {
            return;
        }

        foreach ($generation->delivery_channel?->channels() ?? [] as $channel) {
            DB::transaction(function () use ($generation, $channel, $reason) {
                $delivery = $this->claim($generation, $channel);
                if ($delivery->status === RecurringBillingDeliveryStatus::Pending) {
                    $this->markNotDeliverable($generation, $delivery, $reason);
                }
            });
        }
    }

    private function ensurePdf(RecurringBillingGeneration $generation, User $actor): Document
    {
        try {
            return DB::transaction(function () use ($generation, $actor) {
                $locked = RecurringBillingGeneration::query()->whereKey($generation->id)->lockForUpdate()->firstOrFail();

                $existing = $locked->document_id !== null ? Document::query()->find($locked->document_id) : null;
                if ($existing !== null && Storage::disk($existing->disk)->exists($existing->path)) {
                    return $existing;
                }

                $invoice = Invoice::query()->with('items')->findOrFail($locked->invoice_id);
                $document = $this->documents->generateInvoicePdf($invoice, $actor, createSecureLink: false)['document'];

                $locked->forceFill(['document_id' => $document->id, 'pdf_failure_reason' => null])->save();

                $this->auditLogger->record(
                    event: 'recurring_billing.invoice_pdf_generated',
                    description: 'Recurring invoice PDF generated',
                    auditable: $locked->schedule,
                    newValues: [
                        'generation_id' => $locked->id,
                        'invoice_id' => $invoice->id,
                        'document_id' => $document->id,
                    ],
                );

                return $document;
            });
        } catch (Throwable $e) {
            $this->recordPdfFailure($generation, $e->getMessage());

            throw $e;
        }
    }

    private function recordPdfFailure(RecurringBillingGeneration $generation, string $reason): void
    {
        RecurringBillingGeneration::query()->whereKey($generation->id)
            ->update(['pdf_failure_reason' => mb_substr($reason, 0, 1000), 'updated_at' => now()]);
    }

    private function deliver(RecurringBillingGeneration $generation, CommunicationChannel $channel, User $actor): void
    {
        DB::transaction(function () use ($generation, $channel, $actor) {
            $delivery = $this->claim($generation, $channel);
            if ($delivery->status !== RecurringBillingDeliveryStatus::Pending) {
                return;
            }

            $invoice = Invoice::query()->with(['contact', 'documents'])->findOrFail($generation->invoice_id);

            try {
                $message = match ($channel) {
                    CommunicationChannel::Email => $this->emails->queueInvoiceEmail($invoice, $actor, MessageActorType::System),
                    CommunicationChannel::WhatsApp => $this->whatsapp->queueInvoiceWhatsApp($invoice, $actor, MessageActorType::System),
                    default => throw ValidationException::withMessages(['channel' => 'Unsupported delivery channel.']),
                };
            } catch (ValidationException $e) {
                $reason = collect($e->errors())->flatten()->first();
                $this->markNotDeliverable($generation, $delivery, is_string($reason) ? $reason : 'Delivery was refused.');

                return;
            }

            $this->markQueued($generation, $delivery, $message);
        });
    }

    /**
     * The delivery row for (generation, channel), created on first use and locked for this transaction.
     */
    private function claim(RecurringBillingGeneration $generation, CommunicationChannel $channel): RecurringBillingDelivery
    {
        try {
            RecurringBillingDelivery::query()->firstOrCreate(
                ['generation_id' => $generation->id, 'channel' => $channel->value],
                ['status' => RecurringBillingDeliveryStatus::Pending],
            );
        } catch (UniqueConstraintViolationException) {
            // Another worker created it first; the locked read below sees its row.
        }

        return RecurringBillingDelivery::query()
            ->where('generation_id', $generation->id)
            ->where('channel', $channel->value)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function markQueued(RecurringBillingGeneration $generation, RecurringBillingDelivery $delivery, Message $message): void
    {
        $delivery->forceFill([
            'status' => RecurringBillingDeliveryStatus::Queued,
            'message_id' => $message->id,
            'failure_reason' => null,
            'queued_at' => now(),
        ])->save();

        $this->auditLogger->record(
            event: 'recurring_billing.delivery_queued',
            description: 'Recurring invoice queued for customer delivery',
            auditable: $generation->schedule,
            newValues: [
                'generation_id' => $generation->id,
                'invoice_id' => $generation->invoice_id,
                'channel' => $delivery->channel->value,
                'message_id' => $message->id,
            ],
        );
    }

    private function markNotDeliverable(RecurringBillingGeneration $generation, RecurringBillingDelivery $delivery, string $reason): void
    {
        $delivery->forceFill([
            'status' => RecurringBillingDeliveryStatus::NotDeliverable,
            'failure_reason' => mb_substr($reason, 0, 1000),
            'completed_at' => now(),
        ])->save();

        $this->auditLogger->record(
            event: 'recurring_billing.delivery_failed',
            description: 'Recurring invoice could not be delivered on this channel',
            auditable: $generation->schedule,
            newValues: [
                'generation_id' => $generation->id,
                'invoice_id' => $generation->invoice_id,
                'channel' => $delivery->channel->value,
            ],
            meta: ['reason' => $delivery->failure_reason],
        );
    }

    /**
     * Same rule as invoice reminders: automated sends act as the first Super Administrator.
     */
    private function systemActor(): User
    {
        return User::query()
            ->whereHas('roles', fn ($q) => $q->where('name', User::ROLE_SUPER_ADMINISTRATOR))
            ->orderBy('id')
            ->first()
            ?? User::query()->orderBy('id')->firstOrFail();
    }
}
