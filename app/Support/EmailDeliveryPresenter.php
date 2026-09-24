<?php

namespace App\Support;

use App\Enums\CommunicationChannel;
use App\Enums\MessageStatus;
use App\Models\Invoice;
use App\Models\Message;
use App\Models\Payment;
use App\Models\Quote;
use Illuminate\Support\Collection;

final class EmailDeliveryPresenter
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function recentFor(
        Quote|Invoice|Payment $documentable,
        int $limit = 10,
        CommunicationChannel $channel = CommunicationChannel::Email,
    ): array {
        $documentIds = $documentable->documents->pluck('id');

        if ($documentIds->isEmpty()) {
            $documentIds = $documentable->documents()->pluck('id');
        }

        if ($documentIds->isEmpty()) {
            return [];
        }

        /** @var Collection<int, Message> $messages */
        $messages = Message::query()
            ->where('channel', $channel->value)
            ->whereIn('document_id', $documentIds)
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        return $messages->map(fn (Message $message) => self::row($message))->values()->all();
    }

    /**
     * @return array<string, mixed>
     */
    public static function row(Message $message): array
    {
        return [
            'id' => $message->id,
            'status' => $message->status->value,
            'status_label' => $message->status->label(),
            'subject' => $message->subject,
            'to' => is_array($message->meta) ? ($message->meta['to'] ?? null) : null,
            'failure_reason' => $message->failure_reason,
            'occurred_at' => $message->occurred_at?->toIso8601String(),
            'sent_at' => $message->sent_at?->toIso8601String(),
            'failed_at' => $message->failed_at?->toIso8601String(),
            'can_retry' => $message->status === MessageStatus::Failed
                || $message->status === MessageStatus::Pending,
            'conversation_id' => $message->conversation_id,
        ];
    }
}
