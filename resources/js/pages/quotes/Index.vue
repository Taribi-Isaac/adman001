<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

type QuoteRow = {
    id: number;
    number: string;
    status: string;
    status_label: string;
    total: string;
    currency_code: string;
    issue_date: string | null;
    expiry_date: string | null;
    contact: { id: number; display_name: string } | null;
};

type PaginatedQuotes = {
    data: QuoteRow[];
    links: Array<{ url: string | null; label: string; active: boolean }>;
    from: number | null;
    to: number | null;
    total: number;
};

const props = defineProps<{
    quotes: PaginatedQuotes;
    filters: { search: string; status: string };
    statusOptions: Array<{ value: string; label: string }>;
    canCreate: boolean;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Quotes', href: '/quotes' }],
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
        '/quotes',
        {
            search: search.value || undefined,
            status: status.value || undefined,
        },
        { preserveState: true, replace: true },
    );
};

const statusClass = (value: string) => {
    switch (value) {
        case 'issued':
            return 'border-sky-500/30 text-sky-700 dark:text-sky-300';
        case 'accepted':
            return 'border-emerald-500/30 text-emerald-700 dark:text-emerald-300';
        case 'rejected':
        case 'cancelled':
        case 'expired':
            return 'border-border text-muted-foreground';
        default:
            return 'border-amber-500/30 text-amber-700 dark:text-amber-300';
    }
};

const formatMoney = (amount: string, currency: string) =>
    `${currency} ${Number(amount).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
</script>

<template>
    <Head title="Quotes" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <Heading title="Quotes" description="Create and issue commercial quotes for customers." />
            <Button v-if="canCreate" as-child>
                <Link href="/quotes/create">New quote</Link>
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
            <form class="grid gap-3 md:grid-cols-4" @submit.prevent="applyFilters">
                <div class="md:col-span-2">
                    <label for="search" class="sr-only">Search</label>
                    <Input
                        id="search"
                        v-model="search"
                        placeholder="Search number or customer…"
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
                            <th class="px-4 py-3 font-medium">Number</th>
                            <th class="px-4 py-3 font-medium">Customer</th>
                            <th class="px-4 py-3 font-medium">Status</th>
                            <th class="px-4 py-3 font-medium">Total</th>
                            <th class="px-4 py-3 font-medium">Expiry</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="quote in quotes.data"
                            :key="quote.id"
                            class="border-b border-border/70 last:border-0 hover:bg-muted/30"
                        >
                            <td class="px-4 py-3">
                                <Link
                                    :href="`/quotes/${quote.id}`"
                                    class="font-medium underline-offset-4 hover:underline"
                                >
                                    {{ quote.number }}
                                </Link>
                            </td>
                            <td class="px-4 py-3 text-muted-foreground">
                                {{ quote.contact?.display_name || '—' }}
                            </td>
                            <td class="px-4 py-3">
                                <span
                                    class="inline-flex items-center rounded-full border px-2 py-0.5 text-xs"
                                    :class="statusClass(quote.status)"
                                >
                                    {{ quote.status_label }}
                                </span>
                            </td>
                            <td class="px-4 py-3">
                                {{ formatMoney(quote.total, quote.currency_code) }}
                            </td>
                            <td class="px-4 py-3 text-muted-foreground">
                                {{ quote.expiry_date || '—' }}
                            </td>
                        </tr>
                        <tr v-if="quotes.data.length === 0">
                            <td colspan="5" class="px-4 py-10 text-center text-muted-foreground">
                                <p class="font-medium text-foreground">No quotes yet</p>
                                <p class="mt-1 text-sm">Create a quote for a customer to get started.</p>
                                <Button v-if="canCreate" class="mt-4" as-child>
                                    <Link href="/quotes/create">New quote</Link>
                                </Button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div
                v-if="quotes.total > 0"
                class="flex flex-wrap items-center justify-between gap-2 border-t border-border px-4 py-3 text-sm text-muted-foreground"
            >
                <p>Showing {{ quotes.from }}–{{ quotes.to }} of {{ quotes.total }}</p>
                <div class="flex flex-wrap gap-1">
                    <template v-for="link in quotes.links" :key="link.label">
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
