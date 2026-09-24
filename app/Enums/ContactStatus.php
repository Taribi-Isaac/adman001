<?php

namespace App\Enums;

enum ContactStatus: string
{
    case Unknown = 'unknown';
    case Prospect = 'prospect';
    case Customer = 'customer';

    public function label(): string
    {
        return match ($this) {
            self::Unknown => 'Unknown',
            self::Prospect => 'Prospect',
            self::Customer => 'Customer',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function canPromoteTo(self $target): bool
    {
        return match ($this) {
            self::Unknown => in_array($target, [self::Prospect, self::Customer], true),
            self::Prospect => $target === self::Customer,
            self::Customer => false,
        };
    }
}
