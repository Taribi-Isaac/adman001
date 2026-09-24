<?php

namespace App\Enums;

enum ReminderOccurrenceStatus: string
{
    case Pending = 'pending';

    case Queued = 'queued';

    case Sent = 'sent';

    case Failed = 'failed';

    case Skipped = 'skipped';

    case NotDeliverable = 'not_deliverable';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Queued => 'Queued',
            self::Sent => 'Sent',
            self::Failed => 'Failed',
            self::Skipped => 'Skipped',
            self::NotDeliverable => 'Not deliverable',
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
