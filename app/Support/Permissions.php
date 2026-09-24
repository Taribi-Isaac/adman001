<?php

namespace App\Support;

/**
 * Foundation permission names for ADMAN.
 *
 * Only permissions required by implemented domains are defined here.
 */
final class Permissions
{
    public const USERS_VIEW = 'users.view';

    public const USERS_CREATE = 'users.create';

    public const USERS_UPDATE = 'users.update';

    public const USERS_MANAGE_ROLES = 'users.manage_roles';

    public const ROLES_VIEW = 'roles.view';

    public const ROLES_MANAGE = 'roles.manage';

    public const BUSINESS_VIEW = 'business.view';

    public const BUSINESS_UPDATE = 'business.update';

    public const AUDIT_VIEW = 'audit.view';

    public const SETTINGS_ACCESS = 'settings.access';

    public const SYSTEM_HORIZON = 'system.horizon';

    public const CONTACTS_VIEW = 'contacts.view';

    public const CONTACTS_CREATE = 'contacts.create';

    public const CONTACTS_UPDATE = 'contacts.update';

    public const CONTACTS_PROMOTE = 'contacts.promote';

    public const CONTACTS_ARCHIVE = 'contacts.archive';

    public const CONVERSATIONS_VIEW = 'conversations.view';

    public const CONVERSATIONS_MANAGE = 'conversations.manage';

    public const CONVERSATIONS_TAKEOVER = 'conversations.takeover';

    public const CONVERSATIONS_CLOSE = 'conversations.close';

    public const CONVERSATIONS_LINK_CONTACT = 'conversations.link_contact';

    public const MESSAGES_VIEW = 'messages.view';

    public const MESSAGES_COMPOSE = 'messages.compose';

    public const MESSAGES_SEND = 'messages.send';

    public const MESSAGES_RETRY = 'messages.retry';

    public const QUOTES_VIEW = 'quotes.view';

    public const QUOTES_CREATE = 'quotes.create';

    public const QUOTES_UPDATE = 'quotes.update';

    public const QUOTES_ISSUE = 'quotes.issue';

    public const QUOTES_ACCEPT = 'quotes.accept';

    public const QUOTES_REJECT = 'quotes.reject';

    public const QUOTES_CANCEL = 'quotes.cancel';

    public const QUOTES_CONVERT = 'quotes.convert';

    public const INVOICES_VIEW = 'invoices.view';

    public const INVOICES_CREATE = 'invoices.create';

    public const INVOICES_UPDATE = 'invoices.update';

    public const INVOICES_ISSUE = 'invoices.issue';

    public const INVOICES_CANCEL = 'invoices.cancel';

    public const DOCUMENTS_VIEW = 'documents.view';

    public const DOCUMENTS_GENERATE = 'documents.generate';

    public const DOCUMENTS_REVOKE_LINK = 'documents.revoke_link';

    public const PAYMENTS_VIEW = 'payments.view';

    public const PAYMENTS_RECORD = 'payments.record';

    public const PAYMENTS_CONFIRM = 'payments.confirm';

    public const PAYMENTS_REJECT = 'payments.reject';

    public const PAYMENTS_CLAIMS_VIEW = 'payments.claims.view';

    public const PAYMENTS_CLAIMS_CREATE = 'payments.claims.create';

    public const PAYMENTS_CLAIMS_REVIEW = 'payments.claims.review';

    public const PAYMENTS_ACKNOWLEDGEMENTS_GENERATE = 'payments.acknowledgements.generate';

    public const RECURRING_BILLING_VIEW = 'recurring_billing.view';

    public const RECURRING_BILLING_CREATE = 'recurring_billing.create';

    public const RECURRING_BILLING_UPDATE = 'recurring_billing.update';

    public const RECURRING_BILLING_PAUSE = 'recurring_billing.pause';

    public const RECURRING_BILLING_RESUME = 'recurring_billing.resume';

    public const RECURRING_BILLING_CANCEL = 'recurring_billing.cancel';

    public const RECURRING_BILLING_GENERATE = 'recurring_billing.generate';

    public const AUTOMATION_REMINDERS_VIEW = 'automation.reminders.view';

    public const AUTOMATION_REMINDERS_MANAGE = 'automation.reminders.manage';

    public const AI_VIEW = 'ai.view';

    public const AI_MANAGE = 'ai.manage';

    public const AI_USE = 'ai.use';

    public const BUSINESS_KNOWLEDGE_VIEW = 'business.knowledge.view';

    public const BUSINESS_KNOWLEDGE_MANAGE = 'business.knowledge.manage';

    public const ATTACHMENTS_VIEW = 'attachments.view';

    public const ATTACHMENTS_REVIEW = 'attachments.review';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::USERS_VIEW,
            self::USERS_CREATE,
            self::USERS_UPDATE,
            self::USERS_MANAGE_ROLES,
            self::ROLES_VIEW,
            self::ROLES_MANAGE,
            self::BUSINESS_VIEW,
            self::BUSINESS_UPDATE,
            self::AUDIT_VIEW,
            self::SETTINGS_ACCESS,
            self::SYSTEM_HORIZON,
            self::CONTACTS_VIEW,
            self::CONTACTS_CREATE,
            self::CONTACTS_UPDATE,
            self::CONTACTS_PROMOTE,
            self::CONTACTS_ARCHIVE,
            self::CONVERSATIONS_VIEW,
            self::CONVERSATIONS_MANAGE,
            self::CONVERSATIONS_TAKEOVER,
            self::CONVERSATIONS_CLOSE,
            self::CONVERSATIONS_LINK_CONTACT,
            self::MESSAGES_VIEW,
            self::MESSAGES_COMPOSE,
            self::MESSAGES_SEND,
            self::MESSAGES_RETRY,
            self::QUOTES_VIEW,
            self::QUOTES_CREATE,
            self::QUOTES_UPDATE,
            self::QUOTES_ISSUE,
            self::QUOTES_ACCEPT,
            self::QUOTES_REJECT,
            self::QUOTES_CANCEL,
            self::QUOTES_CONVERT,
            self::INVOICES_VIEW,
            self::INVOICES_CREATE,
            self::INVOICES_UPDATE,
            self::INVOICES_ISSUE,
            self::INVOICES_CANCEL,
            self::DOCUMENTS_VIEW,
            self::DOCUMENTS_GENERATE,
            self::DOCUMENTS_REVOKE_LINK,
            self::PAYMENTS_VIEW,
            self::PAYMENTS_RECORD,
            self::PAYMENTS_CONFIRM,
            self::PAYMENTS_REJECT,
            self::PAYMENTS_CLAIMS_VIEW,
            self::PAYMENTS_CLAIMS_CREATE,
            self::PAYMENTS_CLAIMS_REVIEW,
            self::PAYMENTS_ACKNOWLEDGEMENTS_GENERATE,
            self::RECURRING_BILLING_VIEW,
            self::RECURRING_BILLING_CREATE,
            self::RECURRING_BILLING_UPDATE,
            self::RECURRING_BILLING_PAUSE,
            self::RECURRING_BILLING_RESUME,
            self::RECURRING_BILLING_CANCEL,
            self::RECURRING_BILLING_GENERATE,
            self::AUTOMATION_REMINDERS_VIEW,
            self::AUTOMATION_REMINDERS_MANAGE,
            self::AI_VIEW,
            self::AI_MANAGE,
            self::AI_USE,
            self::BUSINESS_KNOWLEDGE_VIEW,
            self::BUSINESS_KNOWLEDGE_MANAGE,
            self::ATTACHMENTS_VIEW,
            self::ATTACHMENTS_REVIEW,
        ];
    }

    /**
     * @return array<string, array<string, string>>
     */
    public static function labelsByGroup(): array
    {
        return [
            'Users & Access' => [
                self::USERS_VIEW => 'View users',
                self::USERS_CREATE => 'Create users',
                self::USERS_UPDATE => 'Update users',
                self::USERS_MANAGE_ROLES => 'Assign roles',
                self::ROLES_VIEW => 'View roles',
                self::ROLES_MANAGE => 'Manage roles',
            ],
            'Business' => [
                self::BUSINESS_VIEW => 'View business settings',
                self::BUSINESS_UPDATE => 'Update business settings',
                self::BUSINESS_KNOWLEDGE_VIEW => 'View business knowledge',
                self::BUSINESS_KNOWLEDGE_MANAGE => 'Manage business knowledge',
            ],
            'Contacts' => [
                self::CONTACTS_VIEW => 'View contacts',
                self::CONTACTS_CREATE => 'Create contacts',
                self::CONTACTS_UPDATE => 'Update contacts',
                self::CONTACTS_PROMOTE => 'Promote contacts',
                self::CONTACTS_ARCHIVE => 'Archive contacts',
            ],
            'Communication' => [
                self::CONVERSATIONS_VIEW => 'View conversations',
                self::CONVERSATIONS_MANAGE => 'Manage conversations',
                self::CONVERSATIONS_TAKEOVER => 'Take over / return conversations',
                self::CONVERSATIONS_CLOSE => 'Close / reopen conversations',
                self::CONVERSATIONS_LINK_CONTACT => 'Link communication identities to contacts',
                self::MESSAGES_VIEW => 'View messages',
                self::MESSAGES_COMPOSE => 'Compose internal outbound records',
                self::MESSAGES_SEND => 'Send documents by email or WhatsApp',
                self::MESSAGES_RETRY => 'Retry failed external message delivery',
                self::ATTACHMENTS_VIEW => 'View inbound attachments',
                self::ATTACHMENTS_REVIEW => 'Review inbound attachments',
            ],
            'Quotes' => [
                self::QUOTES_VIEW => 'View quotes',
                self::QUOTES_CREATE => 'Create quotes',
                self::QUOTES_UPDATE => 'Update draft quotes',
                self::QUOTES_ISSUE => 'Issue quotes',
                self::QUOTES_ACCEPT => 'Accept quotes',
                self::QUOTES_REJECT => 'Reject quotes',
                self::QUOTES_CANCEL => 'Cancel quotes',
                self::QUOTES_CONVERT => 'Convert quotes to invoices',
            ],
            'Invoices' => [
                self::INVOICES_VIEW => 'View invoices',
                self::INVOICES_CREATE => 'Create invoices',
                self::INVOICES_UPDATE => 'Update draft invoices',
                self::INVOICES_ISSUE => 'Issue invoices',
                self::INVOICES_CANCEL => 'Cancel invoices',
            ],
            'Payments' => [
                self::PAYMENTS_VIEW => 'View payments',
                self::PAYMENTS_RECORD => 'Record payments',
                self::PAYMENTS_CONFIRM => 'Confirm payments',
                self::PAYMENTS_REJECT => 'Reject payments',
                self::PAYMENTS_CLAIMS_VIEW => 'View payment claims',
                self::PAYMENTS_CLAIMS_CREATE => 'Create payment claims',
                self::PAYMENTS_CLAIMS_REVIEW => 'Review payment claims',
                self::PAYMENTS_ACKNOWLEDGEMENTS_GENERATE => 'Generate payment acknowledgements',
            ],
            'Recurring Billing' => [
                self::RECURRING_BILLING_VIEW => 'View recurring billing',
                self::RECURRING_BILLING_CREATE => 'Create recurring schedules',
                self::RECURRING_BILLING_UPDATE => 'Update recurring schedules',
                self::RECURRING_BILLING_PAUSE => 'Pause recurring schedules',
                self::RECURRING_BILLING_RESUME => 'Resume recurring schedules',
                self::RECURRING_BILLING_CANCEL => 'Cancel recurring schedules',
                self::RECURRING_BILLING_GENERATE => 'Generate / retry recurring invoices',
            ],
            'Automation' => [
                self::AUTOMATION_REMINDERS_VIEW => 'View invoice reminder settings',
                self::AUTOMATION_REMINDERS_MANAGE => 'Manage invoice reminder rules',
            ],
            'AI' => [
                self::AI_VIEW => 'View AI settings',
                self::AI_MANAGE => 'Manage AI settings',
                self::AI_USE => 'Use AI-assisted conversation features',
            ],
            'Documents' => [
                self::DOCUMENTS_VIEW => 'View documents',
                self::DOCUMENTS_GENERATE => 'Generate documents',
                self::DOCUMENTS_REVOKE_LINK => 'Revoke secure document links',
            ],
            'System' => [
                self::SETTINGS_ACCESS => 'Access settings',
                self::AUDIT_VIEW => 'View audit history',
                self::SYSTEM_HORIZON => 'Access queue monitoring',
            ],
        ];
    }
}
