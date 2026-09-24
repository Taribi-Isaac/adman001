<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

type InvoiceRow = {
    id: number;
    number: string;
    lifecycle_status: string;
    lifecycle_status_label: string;
    payment_status: string;
    payment_status_label: string;
    due_state_label: string;
    display_status_label: string;
    total: string;
    currency_code: string;
    issue_date: string | null;
    due_date: string | null;
    contact: { id: number; display_name: string } | null;
};

type PaginatedInvoices = {
    data: InvoiceRow[];
    links: Array<{ url: string | null; label: string; active: boolean }>;
    from: number | null;
    to: number | null;
    total: number;
};

const props = defineProps<{
    invoices: PaginatedInvoices;
    filters: { search: string; lifecycle: string; payment: string; due?: string };
    lifecycleOptions: Array<{ value: string; label: string }>;
    paymentOptions: Array<{ value: string; label: string }>;
    canCreate: boolean;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Invoices', href: '/invoices' }],
    },
});

const page = usePage();
const flashSuccess = computed(() => page.props.flash?.success);

const search = ref(props.filters.search);
const lifecycle = ref(props.filters.lifecycle);
const payment = ref(props.filters.payment);
const due = ref(props.filters.due ?? '');

watch(
    () => props.filters,
    (value) => {
        search.value = value.search;
        lifecycle.value = value.lifecycle;
        payment.value = value.payment;
        due.value = value.due ?? '';
    },
);

const applyFilters = () => {
    router.get(
        '/invoices',
        {
            search: search.value || undefined,
            lifecycle: lifecycle.value || undefined,
            payment: payment.value || undefined,
            due: due.value || undefined,
        },
        { preserveState: true, replace: true },
    );
};

const formatMoney = (amount: string, currency: string) =>
    `${currency} ${Number(amount).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
</script>

<template>
    <Head title="Invoices" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <Heading title="Invoices" description="Draft, issue, and track commercial invoices." />
            <Button v-if="canCreate" as-child>
                <Link href="/invoices/create">New invoice</Link>
            </Button>
        </div>

        <p
            v-if="flashSuccess"
            class="rounded-lg border border-border bg-muted/40 px-3 py-2 text-sm"
            role="status"
        >
            {{ flashSuccess }}
        </p>

        <section class="neo-surface space-y-4 p-4">
            <form class="grid gap-3 md:grid-cols-5" @submit.prevent="applyFilters">
                <div class="md:col-span-2">
                    <label for="search" class="sr-only">Search</label>
                    <Input
                        id="search"
                        v-model="search"
                        placeholder="Search number or customer…"
                    />
                </div>
                <div>
                    <label for="lifecycle" class="sr-only">Lifecycle</label>
                    <select
                        id="lifecycle"
                        v-model="lifecycle"
                        class="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm"
                    >
                        <option value="">All lifecycle</option>
                        <option
                            v-for="option in lifecycleOptions"
                            :key="option.value"
                            :value="option.value"
                        >
                            {{ option.label }}
                        </option>
                    </select>
                </div>
                <div>
                    <label for="payment" class="sr-only">Payment</label>
                    <select
                        id="payment"
                        v-model="payment"
                        class="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm"
                    >
                        <option value="">All payment</option>
                        <option
                            v-for="option in paymentOptions"
                            :key="option.value"
                            :value="option.value"
                        >
                            {{ option.label }}
                        </option>
                    </select>
                </div>
                <Button type="submit" variant="secondary">Filter</Button>
            </form>
        </section>

        <section class="neo-surface overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full min-w-[800px] text-sm">
                    <thead class="border-b border-border bg-muted/40 text-left">
                        <tr>
                            <th class="px-4 py-3 font-medium">Number</th>
                            <th class="px-4 py-3 font-medium">Customer</th>
                            <th class="px-4 py-3 font-medium">Display</th>
                            <th class="px-4 py-3 font-medium">Lifecycle</th>
                            <th class="px-4 py-3 font-medium">Payment</th>
                            <th class="px-4 py-3 font-medium">Total</th>
                            <th class="px-4 py-3 font-medium">Due</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="invoice in invoices.data"
                            :key="invoice.id"
                            class="border-b border-border/70 last:border-0 hover:bg-muted/30"
                        >
                            <td class="px-4 py-3">
                                <Link
                                    :href="`/invoices/${invoice.id}`"
                                    class="font-medium underline-offset-4 hover:underline"
                                >
                                    {{ invoice.number }}
                                </Link>
                            </td>
                            <td class="px-4 py-3 text-muted-foreground">
                                {{ invoice.contact?.display_name || '—' }}
                            </td>
                            <td class="px-4 py-3">
                                <span class="inline-flex items-center rounded-full border border-border px-2 py-0.5 text-xs">
                                    {{ invoice.display_status_label }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-muted-foreground">
                                {{ invoice.lifecycle_status_label }}
                            </td>
                            <td class="px-4 py-3 text-muted-foreground">
                                {{ invoice.payment_status_label }}
                            </td>
                            <td class="px-4 py-3">
                                {{ formatMoney(invoice.total, invoice.currency_code) }}
                            </td>
                            <td class="px-4 py-3 text-muted-foreground">
                                {{ invoice.due_date || '—' }}
                            </td>
                        </tr>
                        <tr v-if="invoices.data.length === 0">
                            <td colspan="7" class="px-4 py-10 text-center text-muted-foreground">
                                <p class="font-medium text-foreground">No invoices yet</p>
                                <p class="mt-1 text-sm">Create an invoice or convert an accepted quote.</p>
                                <Button v-if="canCreate" class="mt-4" as-child>
                                    <Link href="/invoices/create">New invoice</Link>
                                </Button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div
                v-if="invoices.total > 0"
                class="flex flex-wrap items-center justify-between gap-2 border-t border-border px-4 py-3 text-sm text-muted-foreground"
            >
                <p>Showing {{ invoices.from }}–{{ invoices.to }} of {{ invoices.total }}</p>
                <div class="flex flex-wrap gap-1">
                    <template v-for="link in invoices.links" :key="link.label">
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
