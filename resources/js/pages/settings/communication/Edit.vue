<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';

type ChannelEmail = {
    outbound_enabled: boolean;
    from_address: string | null;
    reply_to: string | null;
    mailer: string;
    provider_configured: boolean;
    settings_href: string;
};

type ChannelWhatsApp = {
    outbound_enabled: boolean;
    feature_enabled: boolean;
    phone_number_id_set: boolean;
    access_token_set: boolean;
    app_secret_set: boolean;
    verify_token_set: boolean;
    provider_configured: boolean;
    document_delivery: string;
    settings_href: string;
};

defineProps<{
    channels: {
        email: ChannelEmail;
        whatsapp: ChannelWhatsApp;
    };
    message_types: Array<{
        key: string;
        label: string;
        email: boolean;
        whatsapp: boolean;
        delivery: string;
    }>;
    whatsapp_templates: Array<{
        key: string;
        label: string;
        configured_name: string;
    }>;
    related: {
        business: string;
        automation: string;
        ai: string;
        knowledge: string;
    };
    canUpdateBusiness: boolean;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Settings', href: '/settings/business' },
            { title: 'Communication', href: '/settings/communication' },
        ],
    },
});

const statusLabel = (ok: boolean) => (ok ? 'Ready' : 'Needs attention');
</script>

<template>
    <Head title="Communication" />

    <div class="space-y-8">
        <Heading
            variant="small"
            title="Communication"
            description="Configure and monitor the channels ADMAN uses to communicate with customers. Manage email and WhatsApp delivery settings, review connection status, and control how business documents and system messages are sent."
        />

        <section class="neo-surface space-y-3 p-5">
            <h2 class="text-sm font-semibold">Email</h2>
            <p class="text-sm text-muted-foreground">
                Document emails attach the generated PDF. A secure browser link remains available as a secondary option.
                Provider credentials stay in server environment variables.
            </p>
            <dl class="grid gap-2 text-sm sm:grid-cols-2">
                <div>
                    <dt class="text-muted-foreground">Outbound enabled</dt>
                    <dd class="font-medium">
                        {{ channels.email.outbound_enabled ? 'Yes' : 'No' }}
                    </dd>
                </div>
                <div>
                    <dt class="text-muted-foreground">Mailer</dt>
                    <dd class="font-medium">{{ channels.email.mailer || '—' }}</dd>
                </div>
                <div>
                    <dt class="text-muted-foreground">From address</dt>
                    <dd class="font-medium">{{ channels.email.from_address || 'Not set' }}</dd>
                </div>
                <div>
                    <dt class="text-muted-foreground">Reply-to</dt>
                    <dd class="font-medium">{{ channels.email.reply_to || 'Not set' }}</dd>
                </div>
                <div>
                    <dt class="text-muted-foreground">Status</dt>
                    <dd class="font-medium">
                        {{ statusLabel(channels.email.provider_configured && channels.email.outbound_enabled) }}
                    </dd>
                </div>
            </dl>
            <p v-if="canUpdateBusiness" class="text-sm">
                <Link :href="channels.email.settings_href" class="underline-offset-4 hover:underline">
                    Edit email toggles in Business Settings
                </Link>
            </p>
        </section>

        <section class="neo-surface space-y-3 p-5">
            <h2 class="text-sm font-semibold">WhatsApp</h2>
            <p class="text-sm text-muted-foreground">
                Business documents are sent as WhatsApp PDF document attachments. Meta credentials remain in
                environment variables. Session chat (AI/staff) uses free-form text within messaging windows.
            </p>
            <dl class="grid gap-2 text-sm sm:grid-cols-2">
                <div>
                    <dt class="text-muted-foreground">Outbound enabled</dt>
                    <dd class="font-medium">
                        {{ channels.whatsapp.outbound_enabled ? 'Yes' : 'No' }}
                    </dd>
                </div>
                <div>
                    <dt class="text-muted-foreground">Feature flag</dt>
                    <dd class="font-medium">
                        {{ channels.whatsapp.feature_enabled ? 'Enabled' : 'Disabled' }}
                    </dd>
                </div>
                <div>
                    <dt class="text-muted-foreground">Access token</dt>
                    <dd class="font-medium">
                        {{ channels.whatsapp.access_token_set ? 'Configured' : 'Missing' }}
                    </dd>
                </div>
                <div>
                    <dt class="text-muted-foreground">Phone number ID</dt>
                    <dd class="font-medium">
                        {{ channels.whatsapp.phone_number_id_set ? 'Configured' : 'Missing' }}
                    </dd>
                </div>
                <div>
                    <dt class="text-muted-foreground">App secret / verify token</dt>
                    <dd class="font-medium">
                        {{
                            channels.whatsapp.app_secret_set && channels.whatsapp.verify_token_set
                                ? 'Configured'
                                : 'Incomplete'
                        }}
                    </dd>
                </div>
                <div>
                    <dt class="text-muted-foreground">Document delivery</dt>
                    <dd class="font-medium">PDF attachment</dd>
                </div>
            </dl>
            <p v-if="canUpdateBusiness" class="text-sm">
                <Link :href="channels.whatsapp.settings_href" class="underline-offset-4 hover:underline">
                    Edit WhatsApp toggle in Business Settings
                </Link>
            </p>
        </section>

        <section class="neo-surface space-y-3 p-5">
            <h2 class="text-sm font-semibold">System message types</h2>
            <ul class="divide-y divide-border">
                <li
                    v-for="item in message_types"
                    :key="item.key"
                    class="flex flex-col gap-1 py-3 sm:flex-row sm:items-center sm:justify-between"
                >
                    <div>
                        <p class="text-sm font-medium">{{ item.label }}</p>
                        <p class="text-xs text-muted-foreground">{{ item.delivery }}</p>
                    </div>
                    <p class="text-xs text-muted-foreground">
                        <span v-if="item.email">Email</span>
                        <span v-if="item.email && item.whatsapp"> · </span>
                        <span v-if="item.whatsapp">WhatsApp</span>
                    </p>
                </li>
            </ul>
        </section>

        <section class="neo-surface space-y-3 p-5">
            <h2 class="text-sm font-semibold">Related settings</h2>
            <ul class="space-y-2 text-sm">
                <li>
                    <Link :href="related.knowledge" class="underline-offset-4 hover:underline">
                        Business knowledge (AI FAQs / services)
                    </Link>
                </li>
                <li>
                    <Link :href="related.automation" class="underline-offset-4 hover:underline">
                        Invoice reminder automation
                    </Link>
                </li>
                <li>
                    <Link :href="related.ai" class="underline-offset-4 hover:underline">
                        AI assistant settings
                    </Link>
                </li>
                <li>
                    <Link :href="related.business" class="underline-offset-4 hover:underline">
                        Business identity & channel toggles
                    </Link>
                </li>
            </ul>
        </section>
    </div>
</template>
