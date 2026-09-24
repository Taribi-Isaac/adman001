<?php

namespace App\Support;

use App\Models\Business;
use App\Models\Contact;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

final class DocumentSnapshots
{
    /**
     * @return array<string, mixed>
     */
    public static function business(Business $business): array
    {
        return [
            'name' => $business->name,
            'legal_name' => $business->legal_name,
            'registration_number' => $business->registration_number,
            'email' => $business->email,
            'phone' => $business->phone,
            'website' => $business->website,
            'address_line_1' => $business->address_line_1,
            'address_line_2' => $business->address_line_2,
            'city' => $business->city,
            'state' => $business->state,
            'postal_code' => $business->postal_code,
            'country' => $business->country,
            'logo_path' => $business->logo_path,
            'tax_enabled' => $business->tax_enabled,
            'tax_name' => $business->tax_name,
            'tax_rate' => $business->tax_rate,
            'tax_identification' => $business->tax_identification,
            'currency_code' => $business->currency_code,
            'timezone' => $business->timezone,
            'bank_name' => $business->bank_name,
            'bank_account_name' => $business->bank_account_name,
            'bank_account_number' => $business->bank_account_number,
            'payment_instructions' => $business->payment_instructions,
            'default_terms' => $business->default_terms,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function customer(Contact $contact): array
    {
        return [
            'id' => $contact->id,
            'display_name' => $contact->display_name,
            'type' => $contact->type->value,
            'status' => $contact->status->value,
            'first_name' => $contact->first_name,
            'last_name' => $contact->last_name,
            'organization_name' => $contact->organization_name,
            'email' => $contact->email,
            'phone' => $contact->phone,
            'whatsapp_id' => $contact->whatsapp_id,
            'address_line_1' => $contact->address_line_1,
            'address_line_2' => $contact->address_line_2,
            'city' => $contact->city,
            'state' => $contact->state,
            'postal_code' => $contact->postal_code,
            'country' => $contact->country,
        ];
    }

    public static function businessToday(?Business $business = null): CarbonInterface
    {
        $business ??= Business::current();

        return Carbon::now($business->timezone)->startOfDay();
    }
}
