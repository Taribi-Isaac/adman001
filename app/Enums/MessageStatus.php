<?php

namespace App\Enums;

enum MessageStatus: string
{
    /**
     * Stored in ADMAN history only — not delivered by an external provider.
     */
    case Recorded = 'recorded';

    case Pending = 'pending';

    case Processing = 'processing';

    case Sent = 'sent';

    case Delivered = 'delivered';

    case Read = 'read';

    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Recorded => 'Internal record',
            self::Pending => 'Queued',
            self::Processing => 'Processing',
            self::Sent => 'Sent',
            self::Delivered => 'Delivered',
            self::Read => 'Read',
            self::Failed => 'Failed',
        };
    }

    public function isTerminalSuccess(): bool
    {
        return in_array($this, [self::Sent, self::Delivered, self::Read], true);
    }

    public function canRetryDelivery(): bool
    {
        return $this === self::Failed || $this === self::Pending;
    }

    /**
     * Provider status rank for forward-only updates (higher = later).
     */
    public function deliveryRank(): int
    {
        return match ($this) {
            self::Pending, self::Processing, self::Failed, self::Recorded => 0,
            self::Sent => 1,
            self::Delivered => 2,
            self::Read => 3,
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
