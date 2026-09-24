<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';

type QuoteDetail = {
    id: number;
    number: string;
    status: string;
    status_label: string;
    total: string;
    subtotal: string;
    discount_amount: string;
    tax_amount: string;
    tax_enabled: boolean;
    tax_name: string | null;
    tax_rate: string;
    currency_code: string;
    issue_date: string | null;
    expiry_date: string | null;
    notes: string | null;
    terms: string | null;
    contact_detail: {
        id: number;
        display_name: string;
        email: string | null;
        phone: string | null;
        status_label: string;
    } | null;
    invoice: { id: number; number: string } | null;
    items: Array<{
        id: number;
        description: string;
        quantity: string;
        unit: string | null;
        unit_price: string;
        line_subtotal: string;
    }>;
};

type DocRow = {
    id: number;
    type_label: string;
    filename: string;
    generated_at: string | null;
    has_active_link: boolean;
    access_expires_at: string | null;
};

type EmailDeliveryRow = {
    id: number;
    status: string;
    status_label: string;
    subject: string | null;
    to: string | null;
    failure_reason: string | null;
    occurred_at: string | null;
    sent_at: string | null;
    failed_at: string | null;
    can_retry: boolean;
    conversation_id: number;
};

const props = defineProps<{
    quote: QuoteDetail;
    documents: DocRow[];
    emailDeliveries: EmailDeliveryRow[];
    whatsappDeliveries: EmailDeliveryRow[];
    customerEmail: string | null;
    customerWhatsApp: string | null;
    permissions: {
        update: boolean;
        issue: boolean;
        accept: boolean;
        reject: boolean;
        cancel: boolean;
        convert: boolean;
        generate: boolean;
        revoke_link: boolean;
        send_email: boolean;
        send_whatsapp: boolean;
    };
    flashSecureUrl?: string | null;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Quotes', href: '/quotes' },
            { title: 'Detail', href: '#' },
        ],
    },
});

const page = usePage();
const flashSuccess = computed(() => page.props.flash?.success as string | undefined);
const secureUrl = computed(
    () =>
        props.flashSecureUrl ||
        (page.props.flash?.secure_url as string | undefined) ||
        null,
);
const copied = ref(false);

const formatMoney = (amount: string) =>
    `${props.quote.currency_code} ${Number(amount).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

const statusClass = computed(() => {
    switch (props.quote.status) {
        case 'issued':
            return 'border-sky-500/30 text-sky-700 dark:text-sky-300';
        case 'accepted':
            return 'border-emerald-500/30 text-emerald-700 dark:text-emerald-300';
        case 'draft':
            return 'border-amber-500/30 text-amber-700 dark:text-amber-300';
        default:
            return 'border-border text-muted-foreground';
    }
});

const postAction = (path: string, confirmMessage?: string) => {
    if (confirmMessage && !confirm(confirmMessage)) {
        return;
    }
    router.post(path, {}, { preserveScroll: true });
};

const copySecureUrl = async () => {
    if (!secureUrl.value) {
        return;
    }
    await navigator.clipboard.writeText(secureUrl.value);
    copied.value = true;
    setTimeout(() => {
        copied.value = false;
    }, 2000);
};
</script>

<template>
    <Head :title="quote.number" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="space-y-2">
                <Heading :title="quote.number" />
                <div class="flex flex-wrap items-center gap-2">
                    <span
                        class="inline-flex items-center rounded-full border px-2.5 py-0.5 text-xs font-medium"
                        :class="statusClass"
                    >
                        Status: {{ quote.status_label }}
                    </span>
                    <span class="text-sm text-muted-foreground">
                        {{ formatMoney(quote.total) }}
                    </span>
                </div>
            </div>

            <div class="flex flex-wrap gap-2">
                <Button v-if="permissions.update" variant="secondary" as-child>
                    <Link :href="`/quotes/${quote.id}/edit`">Edit</Link>
                </Button>
                <Button
                    v-if="permissions.issue"
                    type="button"
                    @click="postAction(`/quotes/${quote.id}/issue`, 'Issue this quote? Snapshots will be locked.')"
                >
                    Issue
                </Button>
                <Button
                    v-if="permissions.accept"
                    type="button"
                    @click="postAction(`/quotes/${quote.id}/accept`, 'Mark this quote as accepted?')"
                >
                    Accept
                </Button>
                <Button
                    v-if="permissions.reject"
                    type="button"
                    variant="secondary"
                    @click="postAction(`/quotes/${quote.id}/reject`, 'Reject this quote?')"
                >
                    Reject
                </Button>
                <Button
                    v-if="permissions.convert"
                    type="button"
                    @click="postAction(`/quotes/${quote.id}/convert`, 'Convert this accepted quote to a draft invoice?')"
                >
                    Convert to invoice
                </Button>
                <Button
                    v-if="permissions.generate"
                    type="button"
                    variant="secondary"
                    @click="postAction(`/quotes/${quote.id}/generate-document`)"
                >
                    Generate PDF
                </Button>
                <Button
                    v-if="permissions.send_email"
                    type="button"
                    @click="
                        postAction(
                            `/quotes/${quote.id}/send-email`,
                            customerEmail
                                ? `Queue quote email to ${customerEmail}? Delivery is not confirmed until the provider accepts it.`
                                : undefined,
                        )
                    "
                >
                    Send by Email
                </Button>
                <Button
                    v-if="permissions.send_whatsapp"
                    type="button"
                    variant="secondary"
                    @click="
                        postAction(
                            `/quotes/${quote.id}/send-whatsapp`,
                            customerWhatsApp
                                ? `Queue quote WhatsApp template to ${customerWhatsApp}? Status will update when the provider confirms it.`
                                : undefined,
                        )
                    "
                >
                    Send via WhatsApp
                </Button>
                <Button
                    v-if="permissions.cancel"
                    type="button"
                    variant="secondary"
                    @click="postAction(`/quotes/${quote.id}/cancel`, 'Cancel this quote? This cannot be undone.')"
                >
                    Cancel
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

        <section v-if="secureUrl" class="neo-surface space-y-3 border-sky-500/30 p-5">
            <h2 class="text-sm font-semibold">Secure share link (not a customer portal)</h2>
            <p class="text-sm text-muted-foreground">
                Copy this URL now. The plain token is shown once and is not stored.
            </p>
            <div class="flex flex-wrap gap-2">
                <code class="flex-1 break-all rounded-md border border-border bg-muted/40 px-3 py-2 text-xs">
                    {{ secureUrl }}
                </code>
                <Button type="button" variant="secondary" @click="copySecureUrl">
                    {{ copied ? 'Copied' : 'Copy URL' }}
                </Button>
            </div>
        </section>

        <div class="grid gap-6 lg:grid-cols-3">
            <section class="neo-surface space-y-3 p-5 lg:col-span-2">
                <h2 class="text-sm font-semibold">Line items</h2>
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[560px] text-sm">
                        <thead class="border-b border-border text-left text-muted-foreground">
                            <tr>
                                <th class="py-2 pr-3 font-medium">Description</th>
                                <th class="py-2 pr-3 font-medium">Qty</th>
                                <th class="py-2 pr-3 font-medium">Unit price</th>
                                <th class="py-2 font-medium">Line total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="item in quote.items"
                                :key="item.id"
                                class="border-b border-border/60 last:border-0"
                            >
                                <td class="py-2 pr-3">
                                    {{ item.description }}
                                    <span v-if="item.unit" class="text-muted-foreground">
                                        ({{ item.unit }})
                                    </span>
                                </td>
                                <td class="py-2 pr-3">{{ item.quantity }}</td>
                                <td class="py-2 pr-3">{{ formatMoney(item.unit_price) }}</td>
                                <td class="py-2">{{ formatMoney(item.line_subtotal) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="neo-surface space-y-3 p-5">
                <h2 class="text-sm font-semibold">Totals</h2>
                <dl class="space-y-2 text-sm">
                    <div class="flex justify-between gap-4">
                        <dt class="text-muted-foreground">Subtotal</dt>
                        <dd>{{ formatMoney(quote.subtotal) }}</dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-muted-foreground">Discount</dt>
                        <dd>{{ formatMoney(quote.discount_amount) }}</dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-muted-foreground">
                            {{ quote.tax_name || 'Tax'
                            }}{{ quote.tax_enabled ? ` (${quote.tax_rate}%)` : '' }}
                        </dt>
                        <dd>{{ formatMoney(quote.tax_amount) }}</dd>
                    </div>
                    <div class="flex justify-between gap-4 border-t border-border pt-2 font-medium">
                        <dt>Total</dt>
                        <dd>{{ formatMoney(quote.total) }}</dd>
                    </div>
                </dl>
            </section>

            <section class="neo-surface space-y-3 p-5">
                <h2 class="text-sm font-semibold">Customer</h2>
                <template v-if="quote.contact_detail">
                    <p class="font-medium">
                        <Link
                            :href="`/contacts/${quote.contact_detail.id}`"
                            class="underline-offset-4 hover:underline"
                        >
                            {{ quote.contact_detail.display_name }}
                        </Link>
                    </p>
                    <p class="text-sm text-muted-foreground">
                        {{ quote.contact_detail.status_label }}
                    </p>
                    <p class="text-sm text-muted-foreground">
                        {{ quote.contact_detail.email || '—' }}
                    </p>
                    <p class="text-sm text-muted-foreground">
                        {{ quote.contact_detail.phone || '—' }}
                    </p>
                </template>
                <p v-else class="text-sm text-muted-foreground">No customer</p>
            </section>

            <section class="neo-surface space-y-3 p-5">
                <h2 class="text-sm font-semibold">Dates</h2>
                <dl class="space-y-2 text-sm">
                    <div class="flex justify-between gap-4">
                        <dt class="text-muted-foreground">Issue date</dt>
                        <dd>{{ quote.issue_date || '—' }}</dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-muted-foreground">Expiry</dt>
                        <dd>{{ quote.expiry_date || '—' }}</dd>
                    </div>
                </dl>
                <p v-if="quote.invoice" class="text-sm">
                    Invoice:
                    <Link
                        :href="`/invoices/${quote.invoice.id}`"
                        class="font-medium underline-offset-4 hover:underline"
                    >
                        {{ quote.invoice.number }}
                    </Link>
                </p>
            </section>

            <section v-if="quote.notes || quote.terms" class="neo-surface space-y-3 p-5 lg:col-span-2">
                <div v-if="quote.notes" class="space-y-1">
                    <h2 class="text-sm font-semibold">Notes</h2>
                    <p class="whitespace-pre-wrap text-sm text-muted-foreground">{{ quote.notes }}</p>
                </div>
                <div v-if="quote.terms" class="space-y-1">
                    <h2 class="text-sm font-semibold">Terms</h2>
                    <p class="whitespace-pre-wrap text-sm text-muted-foreground">{{ quote.terms }}</p>
                </div>
            </section>

            <section class="neo-surface space-y-3 p-5 lg:col-span-3">
                <h2 class="text-sm font-semibold">WhatsApp delivery</h2>
                <p class="text-xs text-muted-foreground">
                    Template messages with a secure document link. Customer WhatsApp:
                    {{ customerWhatsApp || 'not set' }}
                </p>
                <div v-if="whatsappDeliveries.length === 0" class="text-sm text-muted-foreground">
                    No WhatsApp sends yet for this quote.
                </div>
                <ul v-else class="divide-y divide-border text-sm">
                    <li
                        v-for="delivery in whatsappDeliveries"
                        :key="delivery.id"
                        class="flex flex-wrap items-start justify-between gap-3 py-3"
                    >
                        <div>
                            <p class="font-medium">{{ delivery.status_label }}</p>
                            <p class="text-muted-foreground">
                                {{ delivery.to || '—' }}
                            </p>
                            <p
                                v-if="delivery.failure_reason"
                                class="mt-1 text-sm text-destructive"
                            >
                                {{ delivery.failure_reason }}
                            </p>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <Button variant="secondary" as-child>
                                <Link :href="`/conversations/${delivery.conversation_id}`">
                                    Conversation
                                </Link>
                            </Button>
                            <Button
                                v-if="delivery.can_retry"
                                type="button"
                                variant="secondary"
                                @click="postAction(`/messages/${delivery.id}/retry-email`)"
                            >
                                Retry
                            </Button>
                        </div>
                    </li>
                </ul>
            </section>

            <section class="neo-surface space-y-3 p-5 lg:col-span-3">
                <h2 class="text-sm font-semibold">Email delivery</h2>
                <p class="text-xs text-muted-foreground">
                    Status shows queue progress, not guaranteed mailbox delivery.
                    Customer email: {{ customerEmail || 'not set' }}
                </p>
                <div v-if="emailDeliveries.length === 0" class="text-sm text-muted-foreground">
                    No email sends yet for this quote.
                </div>
                <ul v-else class="divide-y divide-border text-sm">
                    <li
                        v-for="delivery in emailDeliveries"
                        :key="delivery.id"
                        class="flex flex-wrap items-start justify-between gap-3 py-3"
                    >
                        <div>
                            <p class="font-medium">{{ delivery.status_label }}</p>
                            <p class="text-muted-foreground">
                                {{ delivery.to || '—' }}
                                ·
                                {{
                                    delivery.sent_at
                                        ? new Date(delivery.sent_at).toLocaleString()
                                        : delivery.occurred_at
                                          ? new Date(delivery.occurred_at).toLocaleString()
                                          : '—'
                                }}
                            </p>
                            <p
                                v-if="delivery.failure_reason"
                                class="mt-1 text-sm text-destructive"
                            >
                                {{ delivery.failure_reason }}
                            </p>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <Button variant="secondary" as-child>
                                <Link :href="`/conversations/${delivery.conversation_id}`">
                                    Conversation
                                </Link>
                            </Button>
                            <Button
                                v-if="delivery.can_retry"
                                type="button"
                                variant="secondary"
                                @click="postAction(`/messages/${delivery.id}/retry-email`)"
                            >
                                Retry
                            </Button>
                        </div>
                    </li>
                </ul>
            </section>

            <section class="neo-surface space-y-3 p-5 lg:col-span-3">
                <h2 class="text-sm font-semibold">Documents</h2>
                <p class="text-xs text-muted-foreground">
                    Secure share links are not a customer portal. Regenerate to get a new URL (shown once).
                </p>
                <div v-if="documents.length === 0" class="text-sm text-muted-foreground">
                    No PDFs generated yet.
                </div>
                <ul v-else class="divide-y divide-border text-sm">
                    <li
                        v-for="doc in documents"
                        :key="doc.id"
                        class="flex flex-wrap items-center justify-between gap-3 py-3"
                    >
                        <div>
                            <p class="font-medium">{{ doc.filename }}</p>
                            <p class="text-muted-foreground">
                                {{ doc.type_label }}
                                ·
                                {{
                                    doc.has_active_link
                                        ? 'Secure link active'
                                        : 'No active secure link'
                                }}
                            </p>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <Button variant="secondary" as-child>
                                <a :href="`/documents/${doc.id}/download`" target="_blank">Download</a>
                            </Button>
                            <Button
                                v-if="permissions.generate"
                                type="button"
                                variant="secondary"
                                @click="postAction(`/documents/${doc.id}/create-link`)"
                            >
                                Create secure link
                            </Button>
                            <Button
                                v-if="permissions.revoke_link && doc.has_active_link"
                                type="button"
                                variant="secondary"
                                @click="
                                    postAction(
                                        `/documents/${doc.id}/revoke-link`,
                                        'Revoke the secure share link?',
                                    )
                                "
                            >
                                Revoke link
                            </Button>
                        </div>
                    </li>
                </ul>
            </section>
        </div>
    </div>
</template>
