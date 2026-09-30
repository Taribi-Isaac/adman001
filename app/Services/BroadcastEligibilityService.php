<?php

namespace App\Services;

use App\Enums\BroadcastIneligibilityReason;
use App\Enums\CommunicationChannel;
use App\Enums\ContactStatus;
use App\Models\Business;
use App\Models\CommunicationIdentity;
use App\Models\Contact;
use App\Support\BroadcastEligibilityResult;
use App\Support\WhatsAppPhone;
use InvalidArgumentException;

/**
 * Decides whether a contact may receive a future marketing broadcast on a channel.
 *
 * Broadcast-only: transactional sends (quotes, invoices, reminders, payment
 * acknowledgements) keep their own checks in the outbound services and never consult this.
 */
class BroadcastEligibilityService
{
    /**
     * @param  list<ContactStatus>|null  $audienceStatuses  Defaults to Customers only.
     */
    public function check(
        Contact $contact,
        CommunicationChannel $channel,
        ?array $audienceStatuses = null,
    ): BroadcastEligibilityResult {
        return match ($channel) {
            CommunicationChannel::WhatsApp => $this->forWhatsApp($contact, $audienceStatuses),
            CommunicationChannel::Email => $this->forEmail($contact, $audienceStatuses),
        };
    }

    /**
     * @param  list<ContactStatus>|null  $audienceStatuses
     */
    public function forWhatsApp(Contact $contact, ?array $audienceStatuses = null): BroadcastEligibilityResult
    {
        $reasons = $this->audienceReasons($contact, $audienceStatuses);

        if (! (bool) config('adman.whatsapp.enabled', true) || ! Business::current()->outbound_whatsapp_enabled) {
            $reasons[] = BroadcastIneligibilityReason::ChannelDisabled;
        }

        $number = WhatsAppPhone::fromContact($contact);
        if ($number === null) {
            $reasons[] = BroadcastIneligibilityReason::NoWhatsAppNumber;
        } else {
            $identity = CommunicationIdentity::query()
                ->where('channel', CommunicationChannel::WhatsApp->value)
                ->where('external_id', $number)
                ->first();

            if ($identity !== null && ! $identity->is_active) {
                $reasons[] = BroadcastIneligibilityReason::WhatsAppIdentityInactive;
            }

            if ($identity !== null && $identity->contact_id !== null && (int) $identity->contact_id !== (int) $contact->id) {
                $reasons[] = BroadcastIneligibilityReason::WhatsAppIdentityLinkedElsewhere;
            }
        }

        if (! $contact->whatsapp_opt_in) {
            $reasons[] = BroadcastIneligibilityReason::NoWhatsAppOptIn;
        } elseif ($contact->whatsapp_opt_in_at === null) {
            // Opt-ins recorded before Task 035 have no evidence; they stay valid for
            // transactional sends but must be re-recorded before any broadcast.
            $reasons[] = BroadcastIneligibilityReason::WhatsAppOptInNotRecorded;
        }

        if ($contact->whatsapp_broadcast_opt_out_at !== null) {
            $reasons[] = BroadcastIneligibilityReason::WhatsAppBroadcastOptedOut;
        }

        return new BroadcastEligibilityResult(CommunicationChannel::WhatsApp, $reasons);
    }

    /**
     * @param  list<ContactStatus>|null  $audienceStatuses
     */
    public function forEmail(Contact $contact, ?array $audienceStatuses = null): BroadcastEligibilityResult
    {
        $reasons = $this->audienceReasons($contact, $audienceStatuses);

        if (! (bool) config('adman.email.enabled', true) || ! Business::current()->outbound_email_enabled) {
            $reasons[] = BroadcastIneligibilityReason::ChannelDisabled;
        }

        $email = strtolower(trim((string) $contact->email));
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $reasons[] = BroadcastIneligibilityReason::NoValidEmail;
        }

        if ($contact->email_broadcast_opt_in_at === null) {
            $reasons[] = BroadcastIneligibilityReason::NoEmailBroadcastOptIn;
        }

        if ($contact->email_broadcast_unsubscribed_at !== null) {
            $reasons[] = BroadcastIneligibilityReason::EmailBroadcastUnsubscribed;
        }

        return new BroadcastEligibilityResult(CommunicationChannel::Email, $reasons);
    }

    /**
     * @param  list<ContactStatus>|null  $audienceStatuses
     * @return list<BroadcastIneligibilityReason>
     */
    private function audienceReasons(Contact $contact, ?array $audienceStatuses): array
    {
        $statuses = $audienceStatuses ?? [ContactStatus::Customer];

        if (in_array(ContactStatus::Unknown, $statuses, true)) {
            throw new InvalidArgumentException('Unknown contacts can never be a broadcast audience.');
        }

        $reasons = [];

        if ($contact->isArchived()) {
            $reasons[] = BroadcastIneligibilityReason::Archived;
        }

        if (! in_array($contact->status, $statuses, true)) {
            $reasons[] = BroadcastIneligibilityReason::StatusNotInAudience;
        }

        return $reasons;
    }
}
