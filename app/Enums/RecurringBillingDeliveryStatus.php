<?php

namespace App\Enums;

/**
 * Whether a recurring invoice was handed to a channel. What happened afterwards
 * (sent, delivered, failed) is the linked Message's status.
 */
enum RecurringBillingDeliveryStatus: string
{
    case Pending = 'pending';
    case Queued = 'queued';
    case NotDeliverable = 'not_deliverable';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Queued => 'Queued',
            self::NotDeliverable => 'Not deliverable',
        };
    }
}
