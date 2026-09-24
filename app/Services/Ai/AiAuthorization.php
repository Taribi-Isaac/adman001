<?php

namespace App\Services\Ai;

use App\Enums\ContactStatus;
use App\Enums\InvoiceLifecycleStatus;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Invoice;

/**
 * Server-side authorization for AI tools. The model never decides access.
 */
class AiAuthorization
{
    public function authorizedContact(Conversation $conversation): ?Contact
    {
        $conversation->loadMissing('identity.contact', 'contact');

        $contact = $conversation->contact ?? $conversation->identity?->contact;
        if ($contact === null) {
            return null;
        }

        if ($contact->isArchived()) {
            return null;
        }

        // Customer-specific tools require Prospect or Customer (not Unknown).
        if (! in_array($contact->status, [ContactStatus::Prospect, ContactStatus::Customer], true)) {
            return null;
        }

        return $contact;
    }

    public function authorizedInvoice(Conversation $conversation, string $invoiceNumber): ?Invoice
    {
        $contact = $this->authorizedContact($conversation);
        if ($contact === null) {
            return null;
        }

        $number = trim($invoiceNumber);
        if ($number === '') {
            return null;
        }

        /** @var Invoice|null $invoice */
        $invoice = Invoice::query()
            ->where('contact_id', $contact->id)
            ->where('number', $number)
            ->where('lifecycle_status', '!=', InvoiceLifecycleStatus::Draft->value)
            ->first();

        return $invoice;
    }
}
