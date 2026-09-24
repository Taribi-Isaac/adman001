<?php

namespace App\Enums;

enum MessageActorType: string
{
    case External = 'external';
    case Staff = 'staff';
    case System = 'system';
    case Ai = 'ai';

    public function label(): string
    {
        return match ($this) {
            self::External => 'External',
            self::Staff => 'Staff',
            self::System => 'System',
            self::Ai => 'AI',
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
