<?php

namespace Database\Seeders;

use App\Models\User;
use App\Support\Permissions;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        foreach (Permissions::all() as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $superAdmin = Role::findOrCreate(User::ROLE_SUPER_ADMINISTRATOR, 'web');
        $superAdmin->syncPermissions(Permissions::all());

        $staff = Role::findOrCreate(User::ROLE_STAFF, 'web');
        $staff->syncPermissions([
            Permissions::SETTINGS_ACCESS,
            Permissions::BUSINESS_VIEW,
            Permissions::CONTACTS_VIEW,
            Permissions::CONTACTS_CREATE,
            Permissions::CONTACTS_UPDATE,
            Permissions::CONTACTS_PROMOTE,
            Permissions::CONTACTS_ARCHIVE,
            Permissions::CONVERSATIONS_VIEW,
            Permissions::CONVERSATIONS_MANAGE,
            Permissions::CONVERSATIONS_TAKEOVER,
            Permissions::CONVERSATIONS_CLOSE,
            Permissions::CONVERSATIONS_LINK_CONTACT,
            Permissions::MESSAGES_VIEW,
            Permissions::MESSAGES_COMPOSE,
            Permissions::MESSAGES_SEND,
            Permissions::MESSAGES_RETRY,
            Permissions::QUOTES_VIEW,
            Permissions::QUOTES_CREATE,
            Permissions::QUOTES_UPDATE,
            Permissions::QUOTES_ISSUE,
            Permissions::QUOTES_ACCEPT,
            Permissions::QUOTES_REJECT,
            Permissions::QUOTES_CANCEL,
            Permissions::QUOTES_CONVERT,
            Permissions::INVOICES_VIEW,
            Permissions::INVOICES_CREATE,
            Permissions::INVOICES_UPDATE,
            Permissions::INVOICES_ISSUE,
            Permissions::INVOICES_CANCEL,
            Permissions::DOCUMENTS_VIEW,
            Permissions::DOCUMENTS_GENERATE,
            Permissions::DOCUMENTS_REVOKE_LINK,
            Permissions::PAYMENTS_VIEW,
            Permissions::PAYMENTS_RECORD,
            Permissions::PAYMENTS_CONFIRM,
            Permissions::PAYMENTS_REJECT,
            Permissions::PAYMENTS_CLAIMS_VIEW,
            Permissions::PAYMENTS_CLAIMS_CREATE,
            Permissions::PAYMENTS_CLAIMS_REVIEW,
            Permissions::PAYMENTS_ACKNOWLEDGEMENTS_GENERATE,
            Permissions::RECURRING_BILLING_VIEW,
            Permissions::RECURRING_BILLING_CREATE,
            Permissions::RECURRING_BILLING_UPDATE,
            Permissions::RECURRING_BILLING_PAUSE,
            Permissions::RECURRING_BILLING_RESUME,
            Permissions::RECURRING_BILLING_CANCEL,
            Permissions::RECURRING_BILLING_GENERATE,
            Permissions::AUTOMATION_REMINDERS_VIEW,
            Permissions::AUTOMATION_REMINDERS_MANAGE,
            Permissions::AI_VIEW,
            Permissions::AI_MANAGE,
            Permissions::AI_USE,
            Permissions::BUSINESS_KNOWLEDGE_VIEW,
            Permissions::BUSINESS_KNOWLEDGE_MANAGE,
            Permissions::ATTACHMENTS_VIEW,
            Permissions::ATTACHMENTS_REVIEW,
        ]);
    }
}
