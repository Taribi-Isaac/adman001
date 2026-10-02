<?php

namespace App\Enums;

enum BroadcastRecipientStatus: string
{
    /** In the snapshot, not yet handed to the channel service. */
    case Pending = 'pending';

    /** Outbound message created and queued for delivery. */
    case Queued = 'queued';

    case Sent = 'sent';

    case Delivered = 'delivered';

    case Failed = 'failed';

    /** Not sent because the contact was no longer eligible at send time. */
    case Skipped = 'skipped';

    /** Not sent because the broadcast was cancelled or stopped first. */
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Queued => 'Queued',
            self::Sent => 'Sent',
            self::Delivered => 'Delivered',
            self::Failed => 'Failed',
            self::Skipped => 'Skipped',
            self::Cancelled => 'Cancelled',
        };
    }

    /**
     * Still waiting for work; a broadcast cannot complete while any recipient is in one of these.
     *
     * @return list<self>
     */
    public static function open(): array
    {
        return [self::Pending, self::Queued];
    }

    /**
     * States the delivery status of the linked message may still change.
     *
     * @return list<self>
     */
    public static function syncable(): array
    {
        return [self::Queued, self::Sent, self::Delivered, self::Failed];
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
