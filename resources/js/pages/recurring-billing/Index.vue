<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

type ScheduleRow = {
    id: number;
    status: string;
    status_label: string;
    frequency: string;
    frequency_label: string;
    start_date: string | null;
    end_date: string | null;
    next_generation_date: string | null;
    currency_code: string;
    preview_total: string;
    contact: { id: number; display_name: string } | null;
};

type PaginatedSchedules = {
    data: ScheduleRow[];
    links: Array<{ url: string | null; label: string; active: boolean }>;
    from: number | null;
    to: number | null;
    total: number;
};

const props = defineProps<{
    schedules: PaginatedSchedules;
    filters: { search: string; status: string };
    statusOptions: Array<{ value: string; label: string }>;
    canCreate: boolean;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Recurring Billing', href: '/recurring-billing' }],
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
        '/recurring-billing',
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
    <Head title="Recurring Billing" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <Heading
                title="Recurring Billing"
                description="Schedules that generate ordinary invoices on a cadence. Each generated invoice is independent."
            />
            <Button v-if="canCreate" as-child>
                <Link href="/recurring-billing/create">New schedule</Link>
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
                        placeholder="Search by customer or schedule ID…"
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
                <table class="w-full min-w-[800px] text-sm">
                    <thead class="border-b border-border bg-muted/40 text-left">
                        <tr>
                            <th class="px-4 py-3 font-medium">Schedule</th>
                            <th class="px-4 py-3 font-medium">Customer</th>
                            <th class="px-4 py-3 font-medium">Frequency</th>
                            <th class="px-4 py-3 font-medium">Status</th>
                            <th class="px-4 py-3 font-medium">Next run</th>
                            <th class="px-4 py-3 font-medium">Preview total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="schedule in schedules.data"
                            :key="schedule.id"
                            class="border-b border-border/70 last:border-0 hover:bg-muted/30"
                        >
                            <td class="px-4 py-3">
                                <Link
                                    :href="`/recurring-billing/${schedule.id}`"
                                    class="font-medium underline-offset-4 hover:underline"
                                >
                                    #{{ schedule.id }}
                                </Link>
                            </td>
                            <td class="px-4 py-3 text-muted-foreground">
                                {{ schedule.contact?.display_name || '—' }}
                            </td>
                            <td class="px-4 py-3 text-muted-foreground">
                                {{ schedule.frequency_label }}
                            </td>
                            <td class="px-4 py-3">
                                <span
                                    class="inline-flex items-center rounded-full border border-border px-2 py-0.5 text-xs"
                                >
                                    {{ schedule.status_label }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-muted-foreground">
                                {{ schedule.next_generation_date || '—' }}
                            </td>
                            <td class="px-4 py-3">
                                {{ formatMoney(schedule.preview_total, schedule.currency_code) }}
                            </td>
                        </tr>
                        <tr v-if="schedules.data.length === 0">
                            <td colspan="6" class="px-4 py-10 text-center text-muted-foreground">
                                <p class="font-medium text-foreground">No schedules yet</p>
                                <p class="mt-1 text-sm">
                                    Create a schedule to automatically generate independent invoices.
                                </p>
                                <Button v-if="canCreate" class="mt-4" as-child>
                                    <Link href="/recurring-billing/create">New schedule</Link>
                                </Button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div
                v-if="schedules.total > 0"
                class="flex flex-wrap items-center justify-between gap-2 border-t border-border px-4 py-3 text-sm text-muted-foreground"
            >
                <p>Showing {{ schedules.from }}–{{ schedules.to }} of {{ schedules.total }}</p>
                <div class="flex flex-wrap gap-1">
                    <template v-for="link in schedules.links" :key="link.label">
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
