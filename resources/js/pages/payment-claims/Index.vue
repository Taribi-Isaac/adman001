<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

type ClaimRow = {
    id: number;
    claimed_amount: string;
    claimed_payment_date: string | null;
    payment_method_label: string | null;
    customer_reference: string | null;
    status: string;
    status_label: string;
    invoice: { id: number; number: string; currency_code: string } | null;
    contact: { id: number; display_name: string } | null;
};

type PaginatedClaims = {
    data: ClaimRow[];
    links: Array<{ url: string | null; label: string; active: boolean }>;
    from: number | null;
    to: number | null;
    total: number;
};

const props = defineProps<{
    claims: PaginatedClaims;
    filters: { status: string; search: string };
    statusOptions: Array<{ value: string; label: string }>;
    canCreate: boolean;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Payments', href: '/payments' },
            { title: 'Claims', href: '/payment-claims' },
        ],
    },
});

const page = usePage();
const flashSuccess = computed(() => page.props.flash?.success);

const search = ref(props.filters.search);
const status = ref(props.filters.status);

watch(
    () => props.filters,
    (value) => {
        search.value = value.search;
        status.value = value.status;
    },
);

const applyFilters = () => {
    router.get(
        '/payment-claims',
        {
            search: search.value || undefined,
            status: status.value || undefined,
        },
        { preserveState: true, replace: true },
    );
};

const formatMoney = (amount: string, currency: string) =>
    `${currency} ${Number(amount).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
</script>

<template>
    <Head title="Payment claims" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <Heading
                title="Payment claims"
                description="Customer assertions of payment — not financially authoritative until confirmed."
            />
            <div class="flex flex-wrap gap-2">
                <Button variant="secondary" as-child>
                    <Link href="/payments">Payments</Link>
                </Button>
                <Button v-if="canCreate" as-child>
                    <Link href="/payment-claims/create">New claim</Link>
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
            <form class="grid gap-3 md:grid-cols-4" @submit.prevent="applyFilters">
                <div class="md:col-span-2">
                    <label for="search" class="sr-only">Search</label>
                    <Input
                        id="search"
                        v-model="search"
                        placeholder="Search invoice, customer, reference…"
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
                <Button type="submit" variant="secondary">Filter</Button>
            </form>
        </section>

        <section class="neo-surface overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full min-w-[720px] text-sm">
                    <thead class="border-b border-border bg-muted/40 text-left">
                        <tr>
                            <th class="px-4 py-3 font-medium">Claim</th>
                            <th class="px-4 py-3 font-medium">Invoice</th>
                            <th class="px-4 py-3 font-medium">Customer</th>
                            <th class="px-4 py-3 font-medium">Claimed amount</th>
                            <th class="px-4 py-3 font-medium">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="claim in claims.data"
                            :key="claim.id"
                            class="border-b border-border/70 last:border-0 hover:bg-muted/30"
                        >
                            <td class="px-4 py-3">
                                <Link
                                    :href="`/payment-claims/${claim.id}`"
                                    class="font-medium underline-offset-4 hover:underline"
                                >
                                    #{{ claim.id }}
                                </Link>
                                <p
                                    v-if="claim.customer_reference"
                                    class="text-xs text-muted-foreground"
                                >
                                    Ref: {{ claim.customer_reference }}
                                </p>
                            </td>
                            <td class="px-4 py-3 text-muted-foreground">
                                <Link
                                    v-if="claim.invoice"
                                    :href="`/invoices/${claim.invoice.id}`"
                                    class="underline-offset-4 hover:underline"
                                >
                                    {{ claim.invoice.number }}
                                </Link>
                                <span v-else>—</span>
                            </td>
                            <td class="px-4 py-3 text-muted-foreground">
                                {{ claim.contact?.display_name || '—' }}
                            </td>
                            <td class="px-4 py-3">
                                {{
                                    claim.invoice
                                        ? formatMoney(
                                              claim.claimed_amount,
                                              claim.invoice.currency_code,
                                          )
                                        : claim.claimed_amount
                                }}
                            </td>
                            <td class="px-4 py-3">
                                <span
                                    class="inline-flex items-center rounded-full border border-border px-2 py-0.5 text-xs"
                                >
                                    {{ claim.status_label }}
                                </span>
                            </td>
                        </tr>
                        <tr v-if="claims.data.length === 0">
                            <td colspan="5" class="px-4 py-10 text-center text-muted-foreground">
                                <p class="font-medium text-foreground">No payment claims</p>
                                <p class="mt-1 text-sm">
                                    Claims are customer assertions — not confirmed payments.
                                </p>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div
                v-if="claims.total > 0"
                class="flex flex-wrap items-center justify-between gap-2 border-t border-border px-4 py-3 text-sm text-muted-foreground"
            >
                <p>Showing {{ claims.from }}–{{ claims.to }} of {{ claims.total }}</p>
                <div class="flex flex-wrap gap-1">
                    <template v-for="link in claims.links" :key="link.label">
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
