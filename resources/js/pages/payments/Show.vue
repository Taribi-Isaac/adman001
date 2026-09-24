<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';

type PaymentDetail = {
    id: number;
    number: string;
    amount: string;
    currency_code: string;
    payment_method_label: string;
    payment_date: string | null;
    status: string;
    status_label: string;
    reference: string | null;
    notes: string | null;
    rejection_notes: string | null;
    coverage: 'partial' | 'full' | null;
    coverage_label: string | null;
    balance_due_after: string | null;
    amount_paid_after: string | null;
    confirmed_at: string | null;
    rejected_at: string | null;
    created_at: string | null;
    recorder: { id: number; name: string } | null;
    confirmer: { id: number; name: string } | null;
    claim: { id: number; status: string; status_label: string } | null;
    contact_detail: {
        id: number;
        display_name: string;
        email: string | null;
    } | null;
    invoice: { id: number; number: string } | null;
};

type DocRow = {
    id: number;
    type_label: string;
    filename: string;
    generated_at: string | null;
    has_active_link: boolean;
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

type InvoiceContext = {
    id: number;
    number: string;
    total: string;
    amount_paid: string;
    balance_due: string;
    currency_code: string;
    payment_status_label: string;
};

const props = defineProps<{
    payment: PaymentDetail;
    documents: DocRow[];
    emailDeliveries: EmailDeliveryRow[];
    whatsappDeliveries: EmailDeliveryRow[];
    customerEmail: string | null;
    customerWhatsApp: string | null;
    permissions: {
        confirm: boolean;
        reject: boolean;
        generate_acknowledgement: boolean;
        revoke_link: boolean;
        create_link: boolean;
        send_email: boolean;
        send_whatsapp: boolean;
    };
    invoiceContext: InvoiceContext | null;
    flashSecureUrl?: string | null;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Payments', href: '/payments' },
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
    `${props.payment.currency_code} ${Number(amount).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

const postAction = (path: string, confirmMessage?: string, data: Record<string, unknown> = {}) => {
    if (confirmMessage && !confirm(confirmMessage)) {
        return;
    }
    router.post(path, data, { preserveScroll: true });
};

const rejectPayment = () => {
    if (
        !confirm(
            'Reject this payment? It will not affect the invoice balance. This cannot be undone for this record.',
        )
    ) {
        return;
    }
    const notes = window.prompt('Rejection notes (optional):') ?? '';
    router.post(
        `/payments/${props.payment.id}/reject`,
        { rejection_notes: notes || null },
        { preserveScroll: true },
    );
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
    <Head :title="payment.number" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="space-y-2">
                <Heading :title="payment.number" />
                <div class="flex flex-wrap items-center gap-2">
                    <span
                        class="inline-flex items-center rounded-full border border-border px-2.5 py-0.5 text-xs font-medium"
                    >
                        {{ payment.status_label }}
                    </span>
                    <span
                        v-if="payment.coverage_label"
                        class="inline-flex items-center rounded-full border px-2.5 py-0.5 text-xs font-medium"
                        :class="
                            payment.coverage === 'full'
                                ? 'border-emerald-500/30 text-emerald-700 dark:text-emerald-300'
                                : 'border-amber-500/30 text-amber-700 dark:text-amber-300'
                        "
                    >
                        {{ payment.coverage_label }}
                    </span>
                    <span class="text-sm text-muted-foreground">
                        {{ formatMoney(payment.amount) }}
                    </span>
                </div>
            </div>

            <div class="flex flex-wrap gap-2">
                <Button
                    v-if="permissions.confirm"
                    type="button"
                    @click="
                        postAction(
                            `/payments/${payment.id}/confirm`,
                            'Confirm this payment? The invoice balance will be updated. Confirmed payments cannot be rejected.',
                        )
                    "
                >
                    Confirm
                </Button>
                <Button
                    v-if="permissions.reject"
                    type="button"
                    variant="secondary"
                    @click="rejectPayment"
                >
                    Reject
                </Button>
                <Button
                    v-if="permissions.generate_acknowledgement"
                    type="button"
                    variant="secondary"
                    @click="postAction(`/payments/${payment.id}/generate-acknowledgement`)"
                >
                    Generate acknowledgement
                </Button>
                <Button
                    v-if="permissions.send_email"
                    type="button"
                    @click="
                        postAction(
                            `/payments/${payment.id}/send-email`,
                            customerEmail
                                ? `Queue payment acknowledgement email to ${customerEmail}? Delivery is not confirmed until the provider accepts it.`
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
                            `/payments/${payment.id}/send-whatsapp`,
                            customerWhatsApp
                                ? `Queue payment acknowledgement WhatsApp template to ${customerWhatsApp}?`
                                : undefined,
                        )
                    "
                >
                    Send via WhatsApp
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
                <h2 class="text-sm font-semibold">Payment</h2>
                <dl class="grid gap-3 text-sm sm:grid-cols-2">
                    <div>
                        <dt class="text-muted-foreground">Method</dt>
                        <dd>{{ payment.payment_method_label }}</dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground">Payment date</dt>
                        <dd>{{ payment.payment_date || '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground">Reference</dt>
                        <dd>{{ payment.reference || '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground">Recorded by</dt>
                        <dd>{{ payment.recorder?.name || '—' }}</dd>
                    </div>
                    <div v-if="payment.confirmer">
                        <dt class="text-muted-foreground">Confirmed by</dt>
                        <dd>{{ payment.confirmer.name }}</dd>
                    </div>
                    <div v-if="payment.confirmed_at">
                        <dt class="text-muted-foreground">Confirmed at</dt>
                        <dd>{{ new Date(payment.confirmed_at).toLocaleString() }}</dd>
                    </div>
                    <div v-if="payment.rejection_notes" class="sm:col-span-2">
                        <dt class="text-muted-foreground">Rejection notes</dt>
                        <dd class="whitespace-pre-wrap">{{ payment.rejection_notes }}</dd>
                    </div>
                    <div v-if="payment.notes" class="sm:col-span-2">
                        <dt class="text-muted-foreground">Notes</dt>
                        <dd class="whitespace-pre-wrap">{{ payment.notes }}</dd>
                    </div>
                    <div v-if="payment.claim" class="sm:col-span-2">
                        <dt class="text-muted-foreground">From claim</dt>
                        <dd>
                            <Link
                                :href="`/payment-claims/${payment.claim.id}`"
                                class="font-medium underline-offset-4 hover:underline"
                            >
                                Claim #{{ payment.claim.id }}
                            </Link>
                            ({{ payment.claim.status_label }})
                        </dd>
                    </div>
                </dl>

                <div
                    v-if="payment.coverage"
                    class="rounded-md border border-border bg-muted/30 p-3 text-sm"
                >
                    <p class="font-medium">
                        {{
                            payment.coverage === 'full'
                                ? 'This confirmed payment cleared the invoice balance (full).'
                                : 'This confirmed payment left a remaining balance (partial).'
                        }}
                    </p>
                    <p v-if="payment.amount_paid_after" class="mt-1 text-muted-foreground">
                        Amount paid after: {{ formatMoney(payment.amount_paid_after) }}
                        · Balance due after:
                        {{ formatMoney(payment.balance_due_after || '0') }}
                    </p>
                </div>
            </section>

            <section class="neo-surface space-y-3 p-5">
                <h2 class="text-sm font-semibold">Invoice context</h2>
                <template v-if="invoiceContext">
                    <p class="font-medium">
                        <Link
                            :href="`/invoices/${invoiceContext.id}`"
                            class="underline-offset-4 hover:underline"
                        >
                            {{ invoiceContext.number }}
                        </Link>
                    </p>
                    <dl class="space-y-2 text-sm">
                        <div class="flex justify-between gap-4">
                            <dt class="text-muted-foreground">Total</dt>
                            <dd>{{ formatMoney(invoiceContext.total) }}</dd>
                        </div>
                        <div class="flex justify-between gap-4">
                            <dt class="text-muted-foreground">Amount paid</dt>
                            <dd>{{ formatMoney(invoiceContext.amount_paid) }}</dd>
                        </div>
                        <div class="flex justify-between gap-4">
                            <dt class="text-muted-foreground">Balance due</dt>
                            <dd class="font-medium">
                                {{ formatMoney(invoiceContext.balance_due) }}
                            </dd>
                        </div>
                        <div class="flex justify-between gap-4">
                            <dt class="text-muted-foreground">Payment state</dt>
                            <dd>{{ invoiceContext.payment_status_label }}</dd>
                        </div>
                    </dl>
                </template>
                <p v-else class="text-sm text-muted-foreground">No invoice linked.</p>

                <div v-if="payment.contact_detail" class="border-t border-border pt-3">
                    <p class="text-xs text-muted-foreground">Customer</p>
                    <p class="font-medium">
                        <Link
                            :href="`/contacts/${payment.contact_detail.id}`"
                            class="underline-offset-4 hover:underline"
                        >
                            {{ payment.contact_detail.display_name }}
                        </Link>
                    </p>
                </div>
            </section>

            <section class="neo-surface space-y-3 p-5 lg:col-span-3">
                <h2 class="text-sm font-semibold">WhatsApp delivery</h2>
                <p class="text-xs text-muted-foreground">
                    Customer WhatsApp: {{ customerWhatsApp || 'not set' }}
                </p>
                <div v-if="whatsappDeliveries.length === 0" class="text-sm text-muted-foreground">
                    No WhatsApp sends yet for this payment acknowledgement.
                </div>
                <ul v-else class="divide-y divide-border text-sm">
                    <li
                        v-for="delivery in whatsappDeliveries"
                        :key="delivery.id"
                        class="flex flex-wrap items-start justify-between gap-3 py-3"
                    >
                        <div>
                            <p class="font-medium">{{ delivery.status_label }}</p>
                            <p class="text-muted-foreground">{{ delivery.to || '—' }}</p>
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
                    No email sends yet for this payment acknowledgement.
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
                    Secure share link (not a customer portal). Regenerate to get a new URL (shown
                    once).
                </p>
                <div v-if="documents.length === 0" class="text-sm text-muted-foreground">
                    No acknowledgement PDFs generated yet.
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
                                <a :href="`/documents/${doc.id}/download`" target="_blank">
                                    Download
                                </a>
                            </Button>
                            <Button
                                v-if="permissions.create_link"
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
