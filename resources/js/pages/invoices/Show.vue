<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';

type InvoiceDetail = {
    id: number;
    number: string;
    lifecycle_status: string;
    lifecycle_status_label: string;
    payment_status: string;
    payment_status_label: string;
    due_state: string;
    due_state_label: string;
    display_status: string;
    display_status_label: string;
    total: string;
    subtotal: string;
    discount_amount: string;
    tax_amount: string;
    tax_enabled: boolean;
    tax_name: string | null;
    tax_rate: string;
    amount_paid: string;
    balance_due: string;
    currency_code: string;
    issue_date: string | null;
    due_date: string | null;
    notes: string | null;
    terms: string | null;
    contact_detail: {
        id: number;
        display_name: string;
        email: string | null;
        phone: string | null;
        status_label: string;
    } | null;
    quote: { id: number; number: string } | null;
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

type PaymentRow = {
    id: number;
    number: string;
    amount: string;
    currency_code: string;
    payment_method_label: string;
    payment_date: string | null;
    status: string;
    status_label: string;
    reference: string | null;
};

type ClaimRow = {
    id: number;
    claimed_amount: string;
    status: string;
    status_label: string;
    claimed_payment_date: string | null;
    customer_reference: string | null;
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

type ReminderRuleRow = {
    id: number;
    offset_days: number;
    is_enabled: boolean;
    label: string;
};

type ReminderOccurrenceRow = {
    id: number;
    channel: string;
    channel_label: string;
    status: string;
    status_label: string;
    occurrence_date: string | null;
    rule_label: string | null;
    offset_days: number | null;
    failure_reason: string | null;
    skip_reason: string | null;
    message_id: number | null;
    message_status: string | null;
    queued_at: string | null;
    completed_at: string | null;
};

const props = defineProps<{
    invoice: InvoiceDetail;
    documents: DocRow[];
    payments: PaymentRow[];
    pendingClaims: ClaimRow[];
    emailDeliveries: EmailDeliveryRow[];
    whatsappDeliveries: EmailDeliveryRow[];
    customerEmail: string | null;
    customerWhatsApp: string | null;
    reminderRules: ReminderRuleRow[];
    reminderOccurrences: ReminderOccurrenceRow[];
    permissions: {
        update: boolean;
        issue: boolean;
        cancel: boolean;
        generate: boolean;
        revoke_link: boolean;
        record_payment: boolean;
        view_payments: boolean;
        view_claims: boolean;
        create_claim: boolean;
        send_email: boolean;
        send_whatsapp: boolean;
    };
    flashSecureUrl?: string | null;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Invoices', href: '/invoices' },
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
    `${props.invoice.currency_code} ${Number(amount).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

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
    <Head :title="invoice.number" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="space-y-2">
                <Heading :title="invoice.number" />
                <div class="flex flex-wrap items-center gap-2">
                    <span class="inline-flex items-center rounded-full border border-border px-2.5 py-0.5 text-xs font-medium">
                        Display: {{ invoice.display_status_label }}
                    </span>
                    <span class="text-sm text-muted-foreground">
                        {{ formatMoney(invoice.total) }}
                    </span>
                </div>
            </div>

            <div class="flex flex-wrap gap-2">
                <Button v-if="permissions.update" variant="secondary" as-child>
                    <Link :href="`/invoices/${invoice.id}/edit`">Edit</Link>
                </Button>
                <Button
                    v-if="permissions.issue"
                    type="button"
                    @click="postAction(`/invoices/${invoice.id}/issue`, 'Issue this invoice? Snapshots will be locked.')"
                >
                    Issue
                </Button>
                <Button
                    v-if="permissions.record_payment"
                    type="button"
                    as-child
                >
                    <Link :href="`/payments/create?invoice_id=${invoice.id}`">Record payment</Link>
                </Button>
                <Button
                    v-if="permissions.generate"
                    type="button"
                    variant="secondary"
                    @click="postAction(`/invoices/${invoice.id}/generate-document`)"
                >
                    Generate PDF
                </Button>
                <Button
                    v-if="permissions.send_email"
                    type="button"
                    @click="
                        postAction(
                            `/invoices/${invoice.id}/send-email`,
                            customerEmail
                                ? `Queue invoice email to ${customerEmail}? Delivery is not confirmed until the provider accepts it.`
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
                            `/invoices/${invoice.id}/send-whatsapp`,
                            customerWhatsApp
                                ? `Queue invoice WhatsApp template to ${customerWhatsApp}? Status will update when the provider confirms it.`
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
                    @click="postAction(`/invoices/${invoice.id}/cancel`, 'Cancel this invoice? This cannot be undone.')"
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

        <section class="neo-surface space-y-4 p-5">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <h2 class="text-sm font-semibold">Payments</h2>
                <div class="flex flex-wrap gap-2">
                    <Button v-if="permissions.record_payment" as-child>
                        <Link :href="`/payments/create?invoice_id=${invoice.id}`">
                            Record payment
                        </Link>
                    </Button>
                    <Button v-if="permissions.create_claim" variant="secondary" as-child>
                        <Link :href="`/payment-claims/create?invoice_id=${invoice.id}`">
                            Log claim
                        </Link>
                    </Button>
                </div>
            </div>

            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-6">
                <div>
                    <p class="text-xs text-muted-foreground">Lifecycle</p>
                    <p class="font-medium">{{ invoice.lifecycle_status_label }}</p>
                </div>
                <div>
                    <p class="text-xs text-muted-foreground">Total</p>
                    <p class="font-medium">{{ formatMoney(invoice.total) }}</p>
                </div>
                <div>
                    <p class="text-xs text-muted-foreground">Amount paid</p>
                    <p class="font-medium">{{ formatMoney(invoice.amount_paid) }}</p>
                </div>
                <div>
                    <p class="text-xs text-muted-foreground">Balance due</p>
                    <p class="font-medium">{{ formatMoney(invoice.balance_due) }}</p>
                </div>
                <div>
                    <p class="text-xs text-muted-foreground">Payment state</p>
                    <p class="font-medium">{{ invoice.payment_status_label }}</p>
                </div>
                <div>
                    <p class="text-xs text-muted-foreground">Due state</p>
                    <p class="font-medium">{{ invoice.due_state_label }}</p>
                </div>
            </div>

            <div>
                <h3 class="mb-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                    Payment history
                </h3>
                <div v-if="payments.length === 0" class="text-sm text-muted-foreground">
                    No payments recorded yet.
                </div>
                <div v-else class="overflow-x-auto">
                    <table class="w-full min-w-[640px] text-sm">
                        <thead class="border-b border-border text-left text-muted-foreground">
                            <tr>
                                <th class="py-2 pr-3 font-medium">Receipt</th>
                                <th class="py-2 pr-3 font-medium">Amount</th>
                                <th class="py-2 pr-3 font-medium">Method</th>
                                <th class="py-2 pr-3 font-medium">Date</th>
                                <th class="py-2 font-medium">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="payment in payments"
                                :key="payment.id"
                                class="border-b border-border/60 last:border-0"
                            >
                                <td class="py-2 pr-3">
                                    <Link
                                        v-if="permissions.view_payments"
                                        :href="`/payments/${payment.id}`"
                                        class="font-medium underline-offset-4 hover:underline"
                                    >
                                        {{ payment.number }}
                                    </Link>
                                    <span v-else>{{ payment.number }}</span>
                                </td>
                                <td class="py-2 pr-3">{{ formatMoney(payment.amount) }}</td>
                                <td class="py-2 pr-3 text-muted-foreground">
                                    {{ payment.payment_method_label }}
                                </td>
                                <td class="py-2 pr-3 text-muted-foreground">
                                    {{ payment.payment_date || '—' }}
                                </td>
                                <td class="py-2">{{ payment.status_label }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div>
                <h3 class="mb-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                    Pending claims
                </h3>
                <p class="mb-2 text-xs text-muted-foreground">
                    Claims are customer assertions — not confirmed payments.
                </p>
                <div v-if="pendingClaims.length === 0" class="text-sm text-muted-foreground">
                    No open claims.
                </div>
                <ul v-else class="divide-y divide-border text-sm">
                    <li
                        v-for="claim in pendingClaims"
                        :key="claim.id"
                        class="flex flex-wrap items-center justify-between gap-2 py-2"
                    >
                        <div>
                            <Link
                                v-if="permissions.view_claims"
                                :href="`/payment-claims/${claim.id}`"
                                class="font-medium underline-offset-4 hover:underline"
                            >
                                Claim #{{ claim.id }}
                            </Link>
                            <span v-else class="font-medium">Claim #{{ claim.id }}</span>
                            <span class="text-muted-foreground">
                                · Claimed {{ formatMoney(claim.claimed_amount) }}
                                · {{ claim.status_label }}
                            </span>
                        </div>
                    </li>
                </ul>
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
                                v-for="item in invoice.items"
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
                        <dd>{{ formatMoney(invoice.subtotal) }}</dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-muted-foreground">Discount</dt>
                        <dd>{{ formatMoney(invoice.discount_amount) }}</dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-muted-foreground">
                            {{ invoice.tax_name || 'Tax'
                            }}{{ invoice.tax_enabled ? ` (${invoice.tax_rate}%)` : '' }}
                        </dt>
                        <dd>{{ formatMoney(invoice.tax_amount) }}</dd>
                    </div>
                    <div class="flex justify-between gap-4 border-t border-border pt-2 font-medium">
                        <dt>Total</dt>
                        <dd>{{ formatMoney(invoice.total) }}</dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-muted-foreground">Amount paid</dt>
                        <dd>{{ formatMoney(invoice.amount_paid) }}</dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-muted-foreground">Balance due</dt>
                        <dd>{{ formatMoney(invoice.balance_due) }}</dd>
                    </div>
                </dl>
            </section>

            <section class="neo-surface space-y-3 p-5">
                <h2 class="text-sm font-semibold">Customer</h2>
                <template v-if="invoice.contact_detail">
                    <p class="font-medium">
                        <Link
                            :href="`/contacts/${invoice.contact_detail.id}`"
                            class="underline-offset-4 hover:underline"
                        >
                            {{ invoice.contact_detail.display_name }}
                        </Link>
                    </p>
                    <p class="text-sm text-muted-foreground">
                        {{ invoice.contact_detail.status_label }}
                    </p>
                    <p class="text-sm text-muted-foreground">
                        {{ invoice.contact_detail.email || '—' }}
                    </p>
                </template>
            </section>

            <section class="neo-surface space-y-3 p-5">
                <h2 class="text-sm font-semibold">Dates</h2>
                <dl class="space-y-2 text-sm">
                    <div class="flex justify-between gap-4">
                        <dt class="text-muted-foreground">Issue date</dt>
                        <dd>{{ invoice.issue_date || '—' }}</dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-muted-foreground">Due date</dt>
                        <dd>{{ invoice.due_date || '—' }}</dd>
                    </div>
                </dl>
                <p v-if="invoice.quote" class="text-sm">
                    From quote:
                    <Link
                        :href="`/quotes/${invoice.quote.id}`"
                        class="font-medium underline-offset-4 hover:underline"
                    >
                        {{ invoice.quote.number }}
                    </Link>
                </p>
            </section>

            <section v-if="invoice.notes || invoice.terms" class="neo-surface space-y-3 p-5 lg:col-span-2">
                <div v-if="invoice.notes" class="space-y-1">
                    <h2 class="text-sm font-semibold">Notes</h2>
                    <p class="whitespace-pre-wrap text-sm text-muted-foreground">{{ invoice.notes }}</p>
                </div>
                <div v-if="invoice.terms" class="space-y-1">
                    <h2 class="text-sm font-semibold">Terms</h2>
                    <p class="whitespace-pre-wrap text-sm text-muted-foreground">{{ invoice.terms }}</p>
                </div>
            </section>

            <section class="neo-surface space-y-3 p-5 lg:col-span-3">
                <h2 class="text-sm font-semibold">Reminders</h2>
                <p class="text-xs text-muted-foreground">
                    Business reminder rules and occurrence history for this invoice. Pending payment
                    claims do not stop reminders; only confirmed payments that clear the balance do.
                </p>
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[480px] text-sm">
                        <thead class="border-b border-border text-left text-muted-foreground">
                            <tr>
                                <th class="py-2 pr-3 font-medium">Rule</th>
                                <th class="py-2 pr-3 font-medium">Enabled</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="rule in reminderRules"
                                :key="rule.id"
                                class="border-b border-border/60 last:border-0"
                            >
                                <td class="py-2 pr-3">{{ rule.label }}</td>
                                <td class="py-2 pr-3">{{ rule.is_enabled ? 'Yes' : 'No' }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div v-if="reminderOccurrences.length === 0" class="text-sm text-muted-foreground">
                    No reminder occurrences yet.
                </div>
                <ul v-else class="divide-y divide-border text-sm">
                    <li
                        v-for="occurrence in reminderOccurrences"
                        :key="occurrence.id"
                        class="py-3"
                    >
                        <p class="font-medium">
                            {{ occurrence.rule_label || 'Rule' }} · {{ occurrence.channel_label }} ·
                            {{ occurrence.status_label }}
                        </p>
                        <p class="text-muted-foreground">
                            Scheduled {{ occurrence.occurrence_date || '—' }}
                            <span v-if="occurrence.message_status">
                                · Message: {{ occurrence.message_status }}
                            </span>
                        </p>
                        <p
                            v-if="occurrence.failure_reason || occurrence.skip_reason"
                            class="mt-1 text-sm text-destructive"
                        >
                            {{ occurrence.failure_reason || occurrence.skip_reason }}
                        </p>
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
                    No email sends yet for this invoice.
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
                <h2 class="text-sm font-semibold">WhatsApp delivery</h2>
                <p class="text-xs text-muted-foreground">
                    Template messages with a secure document link. Customer WhatsApp:
                    {{ customerWhatsApp || 'not set' }}
                </p>
                <div v-if="whatsappDeliveries.length === 0" class="text-sm text-muted-foreground">
                    No WhatsApp sends yet for this invoice.
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
                    Secure share link (not a customer portal). Regenerate to get a new URL (shown once).
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
