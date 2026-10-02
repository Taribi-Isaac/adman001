<?php

namespace App\Enums;

enum BroadcastIneligibilityReason: string
{
    case Archived = 'archived';
    case StatusNotInAudience = 'status_not_in_audience';
    case ChannelDisabled = 'channel_disabled';
    case NoWhatsAppNumber = 'no_whatsapp_number';
    case WhatsAppIdentityInactive = 'whatsapp_identity_inactive';
    case WhatsAppIdentityLinkedElsewhere = 'whatsapp_identity_linked_elsewhere';
    case NoWhatsAppOptIn = 'no_whatsapp_opt_in';
    case WhatsAppOptInNotRecorded = 'whatsapp_opt_in_not_recorded';
    case NoWhatsAppBroadcastOptIn = 'no_whatsapp_broadcast_opt_in';
    case WhatsAppBroadcastOptedOut = 'whatsapp_broadcast_opted_out';
    case NoValidEmail = 'no_valid_email';
    case NoEmailBroadcastOptIn = 'no_email_broadcast_opt_in';
    case EmailBroadcastUnsubscribed = 'email_broadcast_unsubscribed';

    public function label(): string
    {
        return match ($this) {
            self::Archived => 'Contact is archived',
            self::StatusNotInAudience => 'Contact status is not in the selected audience',
            self::ChannelDisabled => 'Outbound messaging on this channel is disabled',
            self::NoWhatsAppNumber => 'No valid WhatsApp number',
            self::WhatsAppIdentityInactive => 'WhatsApp communication is disabled for this number',
            self::WhatsAppIdentityLinkedElsewhere => 'WhatsApp number is linked to a different contact',
            self::NoWhatsAppOptIn => 'No WhatsApp opt-in',
            self::WhatsAppOptInNotRecorded => 'WhatsApp opt-in has no recorded date and source',
            self::NoWhatsAppBroadcastOptIn => 'No WhatsApp broadcast opt-in',
            self::WhatsAppBroadcastOptedOut => 'Opted out of WhatsApp broadcasts',
            self::NoValidEmail => 'No valid email address',
            self::NoEmailBroadcastOptIn => 'No email broadcast opt-in',
            self::EmailBroadcastUnsubscribed => 'Unsubscribed from email broadcasts',
        };
    }
}
