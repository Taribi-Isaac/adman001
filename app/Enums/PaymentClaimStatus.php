<?php

namespace App\Enums;

enum PaymentClaimStatus: string
{
    case PendingVerification = 'pending_verification';
    case AwaitingInformation = 'awaiting_information';
    case Confirmed = 'confirmed';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::PendingVerification => 'Pending verification',
            self::AwaitingInformation => 'Awaiting information',
            self::Confirmed => 'Confirmed',
            self::Rejected => 'Rejected',
        };
    }

    public function isOpen(): bool
    {
        return in_array($this, [self::PendingVerification, self::AwaitingInformation], true);
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
