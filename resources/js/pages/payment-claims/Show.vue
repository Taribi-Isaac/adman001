<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';

type ClaimDetail = {
    id: number;
    claimed_amount: string;
    claimed_payment_date: string | null;
    payment_method_label: string | null;
    customer_reference: string | null;
    supporting_info: string | null;
    source_channel: string | null;
    status: string;
    status_label: string;
    reviewer_notes: string | null;
    reviewed_at: string | null;
    is_financially_confirmed: boolean;
    creator: { id: number; name: string } | null;
    reviewer: { id: number; name: string } | null;
    payment: {
        id: number;
        number: string;
        status: string;
        status_label: string;
    } | null;
    contact_detail: {
        id: number;
        display_name: string;
        email: string | null;
    } | null;
    invoice: { id: number; number: string; currency_code?: string } | null;
};

type InvoiceTotals = {
    id: number;
    number: string;
    total: string;
    amount_paid: string;
    balance_due: string;
    currency_code: string;
    payment_status_label: string;
};

const props = defineProps<{
    claim: ClaimDetail;
    invoiceTotals: InvoiceTotals | null;
    permissions: {
        confirm: boolean;
        reject: boolean;
        request_information: boolean;
    };
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Payments', href: '/payments' },
            { title: 'Claims', href: '/payment-claims' },
            { title: 'Detail', href: '#' },
        ],
    },
});

const page = usePage();
const flashSuccess = computed(() => page.props.flash?.success as string | undefined);

const currency = computed(
    () => props.invoiceTotals?.currency_code || props.claim.invoice?.currency_code || '',
);

const formatMoney = (amount: string) =>
    `${currency.value} ${Number(amount).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

const reviewAction = (
    path: string,
    confirmMessage: string,
    promptLabel = 'Reviewer notes (optional):',
) => {
    if (!confirm(confirmMessage)) {
        return;
    }
    const notes = window.prompt(promptLabel) ?? '';
    router.post(path, { reviewer_notes: notes || null }, { preserveScroll: true });
};
</script>

<template>
    <Head :title="`Claim #${claim.id}`" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="space-y-2">
                <Heading :title="`Claim #${claim.id}`" />
                <div class="flex flex-wrap items-center gap-2">
                    <span
                        class="inline-flex items-center rounded-full border border-border px-2.5 py-0.5 text-xs font-medium"
                    >
                        Claimed — {{ claim.status_label }}
                    </span>
                    <span
                        v-if="claim.is_financially_confirmed"
                        class="inline-flex items-center rounded-full border border-emerald-500/30 px-2.5 py-0.5 text-xs font-medium text-emerald-700 dark:text-emerald-300"
                    >
                        Confirmed payment created
                    </span>
                    <span class="text-sm text-muted-foreground">
                        Claimed {{ formatMoney(claim.claimed_amount) }}
                    </span>
                </div>
            </div>

            <div class="flex flex-wrap gap-2">
                <Button
                    v-if="permissions.confirm"
                    type="button"
                    @click="
                        reviewAction(
                            `/payment-claims/${claim.id}/confirm`,
                            'Confirm this claim? This creates a confirmed payment and updates the invoice balance. Only confirm when payment is verified.',
                        )
                    "
                >
                    Confirm claim
                </Button>
                <Button
                    v-if="permissions.request_information"
                    type="button"
                    variant="secondary"
                    @click="
                        reviewAction(
                            `/payment-claims/${claim.id}/request-information`,
                            'Request more information on this claim?',
                        )
                    "
                >
                    Request information
                </Button>
                <Button
                    v-if="permissions.reject"
                    type="button"
                    variant="secondary"
                    @click="
                        reviewAction(
                            `/payment-claims/${claim.id}/reject`,
                            'Reject this payment claim? No payment will be created.',
                        )
                    "
                >
                    Reject claim
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

        <section class="neo-surface space-y-2 border-amber-500/30 p-5">
            <p class="text-sm font-medium">Claimed vs Confirmed</p>
            <p class="text-sm text-muted-foreground">
                This record is a
                <strong>claimed</strong>
                payment assertion. It does not change invoice balances until a reviewer
                <strong>confirms</strong>
                it and a financial payment is created.
            </p>
        </section>

        <div class="grid gap-6 lg:grid-cols-3">
            <section class="neo-surface space-y-3 p-5 lg:col-span-2">
                <h2 class="text-sm font-semibold">Claim details</h2>
                <dl class="grid gap-3 text-sm sm:grid-cols-2">
                    <div>
                        <dt class="text-muted-foreground">Claimed amount</dt>
                        <dd class="font-medium">{{ formatMoney(claim.claimed_amount) }}</dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground">Claimed payment date</dt>
                        <dd>{{ claim.claimed_payment_date || '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground">Method</dt>
                        <dd>{{ claim.payment_method_label || '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground">Customer reference</dt>
                        <dd>{{ claim.customer_reference || '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground">Source channel</dt>
                        <dd>{{ claim.source_channel || '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground">Created by</dt>
                        <dd>{{ claim.creator?.name || '—' }}</dd>
                    </div>
                    <div v-if="claim.supporting_info" class="sm:col-span-2">
                        <dt class="text-muted-foreground">Supporting information</dt>
                        <dd class="whitespace-pre-wrap">{{ claim.supporting_info }}</dd>
                    </div>
                    <div v-if="claim.reviewer_notes" class="sm:col-span-2">
                        <dt class="text-muted-foreground">Reviewer notes</dt>
                        <dd class="whitespace-pre-wrap">{{ claim.reviewer_notes }}</dd>
                    </div>
                    <div v-if="claim.payment" class="sm:col-span-2">
                        <dt class="text-muted-foreground">Confirmed payment</dt>
                        <dd>
                            <Link
                                :href="`/payments/${claim.payment.id}`"
                                class="font-medium underline-offset-4 hover:underline"
                            >
                                {{ claim.payment.number }}
                            </Link>
                            ({{ claim.payment.status_label }})
                        </dd>
                    </div>
                </dl>
            </section>

            <section class="neo-surface space-y-3 p-5">
                <h2 class="text-sm font-semibold">Invoice totals</h2>
                <template v-if="invoiceTotals">
                    <p class="font-medium">
                        <Link
                            :href="`/invoices/${invoiceTotals.id}`"
                            class="underline-offset-4 hover:underline"
                        >
                            {{ invoiceTotals.number }}
                        </Link>
                    </p>
                    <dl class="space-y-2 text-sm">
                        <div class="flex justify-between gap-4">
                            <dt class="text-muted-foreground">Total</dt>
                            <dd>{{ formatMoney(invoiceTotals.total) }}</dd>
                        </div>
                        <div class="flex justify-between gap-4">
                            <dt class="text-muted-foreground">Amount paid (confirmed)</dt>
                            <dd>{{ formatMoney(invoiceTotals.amount_paid) }}</dd>
                        </div>
                        <div class="flex justify-between gap-4">
                            <dt class="text-muted-foreground">Balance due</dt>
                            <dd class="font-medium">
                                {{ formatMoney(invoiceTotals.balance_due) }}
                            </dd>
                        </div>
                        <div class="flex justify-between gap-4">
                            <dt class="text-muted-foreground">Payment state</dt>
                            <dd>{{ invoiceTotals.payment_status_label }}</dd>
                        </div>
                    </dl>
                </template>

                <div v-if="claim.contact_detail" class="border-t border-border pt-3">
                    <p class="text-xs text-muted-foreground">Customer</p>
                    <p class="font-medium">
                        <Link
                            :href="`/contacts/${claim.contact_detail.id}`"
                            class="underline-offset-4 hover:underline"
                        >
                            {{ claim.contact_detail.display_name }}
                        </Link>
                    </p>
                </div>
            </section>
        </div>
    </div>
</template>
