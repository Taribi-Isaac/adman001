<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

type PaymentRow = {
    id: number;
    number: string;
    amount: string;
    currency_code: string;
    payment_method_label: string;
    payment_date: string | null;
    status: string;
    status_label: string;
    invoice: { id: number; number: string } | null;
    contact: { id: number; display_name: string } | null;
};

type PaginatedPayments = {
    data: PaymentRow[];
    links: Array<{ url: string | null; label: string; active: boolean }>;
    from: number | null;
    to: number | null;
    total: number;
};

const props = defineProps<{
    payments: PaginatedPayments;
    filters: {
        search: string;
        status: string;
        method: string;
        invoice: string;
        customer: string;
    };
    statusOptions: Array<{ value: string; label: string }>;
    methodOptions: Array<{ value: string; label: string }>;
    canRecord: boolean;
    canViewClaims: boolean;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Payments', href: '/payments' }],
    },
});

const page = usePage();
const flashSuccess = computed(() => page.props.flash?.success);

const search = ref(props.filters.search);
const status = ref(props.filters.status);
const method = ref(props.filters.method);
const invoice = ref(props.filters.invoice);
const customer = ref(props.filters.customer);

watch(
    () => props.filters,
    (value) => {
        search.value = value.search;
        status.value = value.status;
        method.value = value.method;
        invoice.value = value.invoice;
        customer.value = value.customer;
    },
);

const applyFilters = () => {
    router.get(
        '/payments',
        {
            search: search.value || undefined,
            status: status.value || undefined,
            method: method.value || undefined,
            invoice: invoice.value || undefined,
            customer: customer.value || undefined,
        },
        { preserveState: true, replace: true },
    );
};

const formatMoney = (amount: string, currency: string) =>
    `${currency} ${Number(amount).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
</script>

<template>
    <Head title="Payments" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <Heading
                title="Payments"
                description="Record and confirm manual or offline payments against invoices."
            />
            <div class="flex flex-wrap gap-2">
                <Button v-if="canViewClaims" variant="secondary" as-child>
                    <Link href="/payment-claims">Payment claims</Link>
                </Button>
                <Button v-if="canRecord" as-child>
                    <Link href="/payments/create">Record payment</Link>
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

        <section class="neo-surface space-y-4 p-4">
            <form class="grid gap-3 md:grid-cols-6" @submit.prevent="applyFilters">
                <div class="md:col-span-2">
                    <label for="search" class="sr-only">Search</label>
                    <Input
                        id="search"
                        v-model="search"
                        placeholder="Search receipt, reference, customer…"
                    />
                </div>
                <div>
                    <label for="status" class="sr-only">Status</label>
                    <select
                        id="status"
                        v-model="status"
                        class="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm"
                    >
                        <option value="">All statuses</option>
                        <option
                            v-for="option in statusOptions"
                            :key="option.value"
                            :value="option.value"
                        >
                            {{ option.label }}
                        </option>
                    </select>
                </div>
                <div>
                    <label for="method" class="sr-only">Method</label>
                    <select
                        id="method"
                        v-model="method"
                        class="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm"
                    >
                        <option value="">All methods</option>
                        <option
                            v-for="option in methodOptions"
                            :key="option.value"
                            :value="option.value"
                        >
                            {{ option.label }}
                        </option>
                    </select>
                </div>
                <div>
                    <label for="invoice" class="sr-only">Invoice</label>
                    <Input id="invoice" v-model="invoice" placeholder="Invoice #" />
                </div>
                <Button type="submit" variant="secondary">Filter</Button>
            </form>
        </section>

        <section class="neo-surface overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full min-w-[800px] text-sm">
                    <thead class="border-b border-border bg-muted/40 text-left">
                        <tr>
                            <th class="px-4 py-3 font-medium">Receipt</th>
                            <th class="px-4 py-3 font-medium">Invoice</th>
                            <th class="px-4 py-3 font-medium">Customer</th>
                            <th class="px-4 py-3 font-medium">Amount</th>
                            <th class="px-4 py-3 font-medium">Method</th>
                            <th class="px-4 py-3 font-medium">Date</th>
                            <th class="px-4 py-3 font-medium">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="payment in payments.data"
                            :key="payment.id"
                            class="border-b border-border/70 last:border-0 hover:bg-muted/30"
                        >
                            <td class="px-4 py-3">
                                <Link
                                    :href="`/payments/${payment.id}`"
                                    class="font-medium underline-offset-4 hover:underline"
                                >
                                    {{ payment.number }}
                                </Link>
                            </td>
                            <td class="px-4 py-3 text-muted-foreground">
                                <Link
                                    v-if="payment.invoice"
                                    :href="`/invoices/${payment.invoice.id}`"
                                    class="underline-offset-4 hover:underline"
                                >
                                    {{ payment.invoice.number }}
                                </Link>
                                <span v-else>—</span>
                            </td>
                            <td class="px-4 py-3 text-muted-foreground">
                                {{ payment.contact?.display_name || '—' }}
                            </td>
                            <td class="px-4 py-3">
                                {{ formatMoney(payment.amount, payment.currency_code) }}
                            </td>
                            <td class="px-4 py-3 text-muted-foreground">
                                {{ payment.payment_method_label }}
                            </td>
                            <td class="px-4 py-3 text-muted-foreground">
                                {{ payment.payment_date || '—' }}
                            </td>
                            <td class="px-4 py-3">
                                <span
                                    class="inline-flex items-center rounded-full border border-border px-2 py-0.5 text-xs"
                                >
                                    {{ payment.status_label }}
                                </span>
                            </td>
                        </tr>
                        <tr v-if="payments.data.length === 0">
                            <td colspan="7" class="px-4 py-10 text-center text-muted-foreground">
                                <p class="font-medium text-foreground">No payments yet</p>
                                <p class="mt-1 text-sm">
                                    Record a payment against an issued invoice with a balance.
                                </p>
                                <Button v-if="canRecord" class="mt-4" as-child>
                                    <Link href="/payments/create">Record payment</Link>
                                </Button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div
                v-if="payments.total > 0"
                class="flex flex-wrap items-center justify-between gap-2 border-t border-border px-4 py-3 text-sm text-muted-foreground"
            >
                <p>Showing {{ payments.from }}–{{ payments.to }} of {{ payments.total }}</p>
                <div class="flex flex-wrap gap-1">
                    <template v-for="link in payments.links" :key="link.label">
                        <Link
                            v-if="link.url"
                            :href="link.url"
                            class="rounded-md border border-border px-2 py-1 hover:bg-muted"
                            :class="{ 'bg-muted font-medium text-foreground': link.active }"
                            preserve-scroll
                            v-html="link.label"
                        />
                        <span v-else class="rounded-md px-2 py-1 opacity-40" v-html="link.label" />
                    </template>
                </div>
            </div>
        </section>
    </div>
</template>
