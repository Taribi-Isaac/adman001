<?php

namespace App\Services;

use App\Enums\BroadcastRecipientStatus;
use App\Enums\BroadcastStatus;
use App\Models\BroadcastRecipient;
use App\Models\Message;
use Illuminate\Support\Facades\DB;

/**
 * Final check immediately before a broadcast message reaches the provider. Consent can be
 * withdrawn, or the broadcast cancelled, between queueing and the worker picking it up.
 */
class BroadcastDeliveryGuard
{
    public function __construct(
        private readonly BroadcastEligibilityService $eligibility,
    ) {}

    public static function isBroadcastMessage(Message $message): bool
    {
        return is_array($message->meta) && isset($message->meta['broadcast_recipient_id']);
    }

    /**
     * Why this broadcast message must not be sent now, or null when it may be sent.
     * Updates the recipient row when the answer is "cancelled" or "skipped".
     */
    public function blockReason(Message $message): ?string
    {
        $recipientId = (int) ($message->meta['broadcast_recipient_id'] ?? 0);

        return DB::transaction(function () use ($message, $recipientId): ?string {
            /** @var BroadcastRecipient|null $recipient */
            $recipient = BroadcastRecipient::query()->whereKey($recipientId)->lockForUpdate()->first();

            if ($recipient === null || (int) $recipient->message_id !== (int) $message->id) {
                return 'This message is not the intended message for a broadcast recipient.';
            }

            // Broadcast messages are sent at most once: anything but a freshly queued recipient is
            // blocked, including failures (a provider timeout may still have delivered the message).
            if ($recipient->status !== BroadcastRecipientStatus::Queued) {
                return 'Broadcast recipient is '.strtolower($recipient->status->label()).'; broadcast messages are never resent.';
            }

            $broadcast = $recipient->broadcast()->first();
            if ($broadcast === null || $broadcast->status !== BroadcastStatus::Sending) {
                $reason = 'Broadcast was '.strtolower($broadcast?->status->label() ?? 'removed').' before this message was sent.';
                $recipient->forceFill([
                    'status' => BroadcastRecipientStatus::Cancelled,
                    'failure_reason' => $reason,
                ])->save();

                return $reason;
            }

            $contact = $recipient->contact()->first();
            $result = $contact === null
                ? null
                : $this->eligibility->check($contact, $broadcast->channel, $broadcast->audience_type->statuses());

            if ($result === null || ! $result->eligible()) {
                $reason = 'No longer eligible: '.($result?->firstReason()?->label() ?? 'contact removed').'.';
                $recipient->forceFill([
                    'status' => BroadcastRecipientStatus::Skipped,
                    'failure_reason' => $reason,
                ])->save();

                return $reason;
            }

            return null;
        });
    }
}
