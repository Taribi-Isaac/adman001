<?php

namespace App\Enums;

enum AttachmentReviewStatus: string
{
    case PendingReview = 'pending_review';
    case Reviewed = 'reviewed';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::PendingReview => 'Pending review',
            self::Reviewed => 'Reviewed',
            self::Archived => 'Archived',
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
