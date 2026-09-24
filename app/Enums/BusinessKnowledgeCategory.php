<?php

namespace App\Enums;

enum BusinessKnowledgeCategory: string
{
    case Faq = 'faq';
    case Policy = 'policy';
    case Support = 'support';
    case General = 'general';

    public function label(): string
    {
        return match ($this) {
            self::Faq => 'FAQ',
            self::Policy => 'Policy',
            self::Support => 'Customer support',
            self::General => 'General',
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
