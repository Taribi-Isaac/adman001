<?php

namespace App\Enums;

enum ConversationMode: string
{
    case Ai = 'ai';
    case Human = 'human';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Ai => 'AI',
            self::Human => 'Human',
            self::Closed => 'Closed',
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
