<?php

namespace App\Enums;

enum BroadcastAudience: string
{
    case Customers = 'customers';

    case CustomersAndProspects = 'customers_prospects';

    case Selected = 'selected';

    public function label(): string
    {
        return match ($this) {
            self::Customers => 'All eligible customers',
            self::CustomersAndProspects => 'All eligible customers and prospects',
            self::Selected => 'Selected contacts',
        };
    }

    /**
     * Contact statuses this audience may include. Unknown contacts are never included.
     *
     * @return list<ContactStatus>
     */
    public function statuses(): array
    {
        return match ($this) {
            self::Customers => [ContactStatus::Customer],
            self::CustomersAndProspects, self::Selected => [ContactStatus::Customer, ContactStatus::Prospect],
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
