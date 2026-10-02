<?php

namespace App\Enums;

enum BroadcastStatus: string
{
    case Draft = 'draft';

    case Queued = 'queued';

    case Sending = 'sending';

    case Completed = 'completed';

    case Cancelled = 'cancelled';

    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Queued => 'Queued',
            self::Sending => 'Sending',
            self::Completed => 'Completed',
            self::Cancelled => 'Cancelled',
            self::Failed => 'Failed',
        };
    }

    public function isActive(): bool
    {
        return $this === self::Queued || $this === self::Sending;
    }

    public function canBeCancelled(): bool
    {
        return in_array($this, [self::Draft, self::Queued, self::Sending], true);
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
