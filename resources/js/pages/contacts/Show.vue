<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';

type ContactDetail = {
    id: number;
    display_name: string;
    type: string;
    type_label: string;
    status: string;
    status_label: string;
    first_name: string | null;
    last_name: string | null;
    organization_name: string | null;
    email: string | null;
    phone: string | null;
    whatsapp_id: string | null;
    whatsapp_opt_in?: boolean;
    address_line_1: string | null;
    address_line_2: string | null;
    city: string | null;
    state: string | null;
    postal_code: string | null;
    country: string | null;
    notes: string | null;
    is_archived: boolean;
    archived_at: string | null;
    created_at: string | null;
    updated_at: string | null;
};

const props = defineProps<{
    contact: ContactDetail;
    permissions: {
        update: boolean;
        promote: boolean;
        archive: boolean;
        view_payments?: boolean;
    };
    availablePromotions: Array<{ value: string; label: string }>;
    paymentsSummary?: { count: number } | null;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Customers', href: '/contacts' },
            { title: 'Detail', href: '#' },
        ],
    },
});

const page = usePage();
const flashSuccess = computed(() => page.props.flash?.success);

const statusClass = computed(() => {
    switch (props.contact.status) {
        case 'customer':
            return 'border-emerald-500/30 text-emerald-700 dark:text-emerald-300';
        case 'prospect':
            return 'border-amber-500/30 text-amber-700 dark:text-amber-300';
        default:
            return 'border-border text-muted-foreground';
    }
});

const addressLines = computed(() =>
    [
        props.contact.address_line_1,
        props.contact.address_line_2,
        [props.contact.city, props.contact.state, props.contact.postal_code]
            .filter(Boolean)
            .join(', '),
        props.contact.country,
    ].filter(Boolean),
);

const promote = (status: string) => {
    router.post(`/contacts/${props.contact.id}/promote`, { status }, { preserveScroll: true });
};

const archive = () => {
    if (!confirm(`Archive ${props.contact.display_name}? The record is kept for history.`)) {
        return;
    }
    router.post(`/contacts/${props.contact.id}/archive`);
};

const restore = () => {
    router.post(`/contacts/${props.contact.id}/restore`);
};

const formatDate = (value: string | null) =>
    value ? new Date(value).toLocaleString() : '—';
</script>

<template>
    <Head :title="contact.display_name" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="space-y-2">
                <Heading :title="contact.display_name" />
                <div class="flex flex-wrap items-center gap-2">
                    <span
                        class="inline-flex items-center rounded-full border px-2.5 py-0.5 text-xs font-medium"
                        :class="statusClass"
                    >
                        {{ contact.status_label }}
                    </span>
                    <span class="text-sm text-muted-foreground">{{
                        contact.type_label
                    }}</span>
                    <span
                        v-if="contact.is_archived"
                        class="inline-flex items-center rounded-full border border-border px-2.5 py-0.5 text-xs text-muted-foreground"
                    >
                        Archived
                    </span>
                </div>
            </div>

            <div class="flex flex-wrap gap-2">
                <Button
                    v-if="permissions.update && !contact.is_archived"
                    variant="secondary"
                    as-child
                >
                    <Link :href="`/contacts/${contact.id}/edit`">Edit</Link>
                </Button>
                <Button
                    v-for="promotion in availablePromotions"
                    v-show="permissions.promote"
                    :key="promotion.value"
                    type="button"
                    @click="promote(promotion.value)"
                >
                    Promote to {{ promotion.label }}
                </Button>
                <Button
                    v-if="permissions.archive && !contact.is_archived"
                    type="button"
                    variant="secondary"
                    @click="archive"
                >
                    Archive
                </Button>
                <Button
                    v-if="permissions.archive && contact.is_archived"
                    type="button"
                    @click="restore"
                >
                    Restore
                </Button>
            </div>
        </div>

        <p
            v-if="flashSuccess"
            class="rounded-lg border border-border bg-muted/40 px-3 py-2 text-sm"
            role="status"
        >
            {{ flashSuccess }}
        </p>

        <div class="grid gap-4 lg:grid-cols-3">
            <section class="neo-surface space-y-3 p-5 lg:col-span-2">
                <h2 class="text-sm font-semibold">Contact details</h2>
                <dl class="grid gap-3 sm:grid-cols-2 text-sm">
                    <div>
                        <dt class="text-muted-foreground">Email</dt>
                        <dd>{{ contact.email || '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground">Phone</dt>
                        <dd>{{ contact.phone || '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground">WhatsApp ID</dt>
                        <dd>{{ contact.whatsapp_id || '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground">WhatsApp opt-in</dt>
                        <dd>{{ contact.whatsapp_opt_in ? 'Yes' : 'No' }}</dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground">Organization</dt>
                        <dd>{{ contact.organization_name || '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground">First name</dt>
                        <dd>{{ contact.first_name || '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground">Last name</dt>
                        <dd>{{ contact.last_name || '—' }}</dd>
                    </div>
                </dl>
            </section>

            <section class="neo-surface space-y-3 p-5">
                <h2 class="text-sm font-semibold">Record</h2>
                <dl class="space-y-3 text-sm">
                    <div>
                        <dt class="text-muted-foreground">Created</dt>
                        <dd>{{ formatDate(contact.created_at) }}</dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground">Updated</dt>
                        <dd>{{ formatDate(contact.updated_at) }}</dd>
                    </div>
                    <div v-if="contact.archived_at">
                        <dt class="text-muted-foreground">Archived</dt>
                        <dd>{{ formatDate(contact.archived_at) }}</dd>
                    </div>
                </dl>
            </section>

            <section class="neo-surface space-y-3 p-5 lg:col-span-2">
                <h2 class="text-sm font-semibold">Address</h2>
                <p v-if="addressLines.length" class="text-sm whitespace-pre-line">
                    {{ addressLines.join('\n') }}
                </p>
                <p v-else class="text-sm text-muted-foreground">No address on file.</p>
            </section>

            <section class="neo-surface space-y-3 p-5">
                <h2 class="text-sm font-semibold">Notes</h2>
                <p v-if="contact.notes" class="text-sm whitespace-pre-wrap">
                    {{ contact.notes }}
                </p>
                <p v-else class="text-sm text-muted-foreground">No notes.</p>
            </section>
        </div>

        <section
            v-if="permissions.view_payments && paymentsSummary"
            class="neo-surface space-y-2 p-5"
        >
            <h2 class="text-sm font-semibold">Payments</h2>
            <p class="text-sm text-muted-foreground">
                {{ paymentsSummary.count }}
                payment{{ paymentsSummary.count === 1 ? '' : 's' }} recorded for this customer.
            </p>
            <Button v-if="paymentsSummary.count > 0" variant="secondary" as-child>
                <Link :href="`/payments?customer=${encodeURIComponent(contact.display_name)}`">
                    View payments
                </Link>
            </Button>
        </section>

        <section class="neo-surface empty-state p-6">
            <h2 class="text-sm font-semibold text-foreground">Related activity</h2>
            <p class="mt-2 text-sm">
                Conversations, quotes, invoices, and recurring billing will appear here in later
                tasks.
            </p>
        </section>
    </div>
</template>
