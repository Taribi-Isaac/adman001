<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';

type ScheduleDetail = {
    id: number;
    status: string;
    status_label: string;
    frequency: string;
    frequency_label: string;
    start_date: string | null;
    end_date: string | null;
    next_generation_date: string | null;
    payment_term_days: number;
    currency_code: string;
    discount_type: string;
    discount_type_label: string;
    discount_value: string;
    discount_amount: string;
    tax_enabled: boolean;
    tax_rate: string | null;
    tax_amount: string;
    subtotal: string;
    total: string;
    notes: string | null;
    terms: string | null;
    paused_at: string | null;
    cancelled_at: string | null;
    contact_detail: {
        id: number;
        display_name: string;
        email: string | null;
        phone: string | null;
        status_label: string;
    } | null;
    items: Array<{
        id: number;
        description: string;
        quantity: string;
        unit: string | null;
        unit_price: string;
        line_subtotal: string;
    }>;
};

type GenerationRow = {
    id: number;
    period_key: string;
    period_start: string | null;
    period_end: string | null;
    status: string;
    status_label: string;
    trigger: string;
    failure_reason: string | null;
    attempted_at: string | null;
    completed_at: string | null;
    invoice: { id: number; number: string } | null;
    can_retry: boolean;
};

const props = defineProps<{
    schedule: ScheduleDetail;
    generations: GenerationRow[];
    permissions: {
        update: boolean;
        pause: boolean;
        resume: boolean;
        cancel: boolean;
        generate: boolean;
    };
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Recurring Billing', href: '/recurring-billing' },
            { title: 'Detail', href: '#' },
        ],
    },
});

const page = usePage();
const flashSuccess = computed(() => page.props.flash?.success as string | undefined);
const flashError = computed(() => page.props.flash?.error as string | undefined);

const formatMoney = (amount: string) =>
    `${props.schedule.currency_code} ${Number(amount).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

const postAction = (path: string, confirmMessage?: string) => {
    if (confirmMessage && !confirm(confirmMessage)) {
        return;
    }
    router.post(path, {}, { preserveScroll: true });
};
</script>

<template>
    <Head :title="`Schedule #${schedule.id}`" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="space-y-2">
                <Heading :title="`Schedule #${schedule.id}`" />
                <div class="flex flex-wrap items-center gap-2">
                    <span
                        class="inline-flex items-center rounded-full border border-border px-2.5 py-0.5 text-xs font-medium"
                    >
                        {{ schedule.status_label }}
                    </span>
                    <span class="text-sm text-muted-foreground">
                        {{ schedule.frequency_label }} · {{ formatMoney(schedule.total) }}
                    </span>
                </div>
            </div>

            <div class="flex flex-wrap gap-2">
                <Button v-if="permissions.update" variant="secondary" as-child>
                    <Link :href="`/recurring-billing/${schedule.id}/edit`">Edit</Link>
                </Button>
                <Button
                    v-if="permissions.generate"
                    type="button"
                    @click="
                        postAction(
                            `/recurring-billing/${schedule.id}/generate`,
                            'Generate an invoice for the current period now? The invoice will be independent of this schedule.',
                        )
                    "
                >
                    Generate now
                </Button>
                <Button
                    v-if="permissions.pause"
                    type="button"
                    variant="secondary"
                    @click="
                        postAction(
                            `/recurring-billing/${schedule.id}/pause`,
                            'Pause this schedule? No new invoices will be generated until you resume.',
                        )
                    "
                >
                    Pause
                </Button>
                <Button
                    v-if="permissions.resume"
                    type="button"
                    variant="secondary"
                    @click="
                        postAction(
                            `/recurring-billing/${schedule.id}/resume`,
                            'Resume this schedule? Missed periods during the pause will not be backfilled.',
                        )
                    "
                >
                    Resume
                </Button>
                <Button
                    v-if="permissions.cancel"
                    type="button"
                    variant="secondary"
                    @click="
                        postAction(
                            `/recurring-billing/${schedule.id}/cancel`,
                            'Cancel this schedule permanently? Existing invoices remain unchanged.',
                        )
                    "
                >
                    Cancel schedule
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
        <p
            v-if="flashError"
            class="rounded-lg border border-destructive/40 bg-destructive/10 px-3 py-2 text-sm text-destructive"
            role="alert"
        >
            {{ flashError }}
        </p>

        <section class="neo-surface space-y-3 border-amber-500/30 p-5">
            <h2 class="text-sm font-semibold">Independent invoices</h2>
            <p class="text-sm text-muted-foreground">
                Each generation creates a normal issued invoice. Editing, pausing, or cancelling this
                schedule does not change invoices that were already generated.
            </p>
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
                                v-for="item in schedule.items"
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
                <h2 class="text-sm font-semibold">Amount preview</h2>
                <p class="text-xs text-muted-foreground">
                    Estimated totals for the next generated invoice.
                </p>
                <dl class="space-y-2 text-sm">
                    <div class="flex justify-between gap-4">
                        <dt class="text-muted-foreground">Subtotal</dt>
                        <dd>{{ formatMoney(schedule.subtotal) }}</dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-muted-foreground">
                            Discount ({{ schedule.discount_type_label }})
                        </dt>
                        <dd>{{ formatMoney(schedule.discount_amount) }}</dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-muted-foreground">
                            Tax{{ schedule.tax_enabled ? ` (${schedule.tax_rate}%)` : '' }}
                        </dt>
                        <dd>{{ formatMoney(schedule.tax_amount) }}</dd>
                    </div>
                    <div class="flex justify-between gap-4 border-t border-border pt-2 font-medium">
                        <dt>Total</dt>
                        <dd>{{ formatMoney(schedule.total) }}</dd>
                    </div>
                </dl>
            </section>

            <section class="neo-surface space-y-3 p-5">
                <h2 class="text-sm font-semibold">Customer</h2>
                <template v-if="schedule.contact_detail">
                    <p class="font-medium">
                        <Link
                            :href="`/contacts/${schedule.contact_detail.id}`"
                            class="underline-offset-4 hover:underline"
                        >
                            {{ schedule.contact_detail.display_name }}
                        </Link>
                    </p>
                    <p class="text-sm text-muted-foreground">
                        {{ schedule.contact_detail.status_label }}
                    </p>
                    <p class="text-sm text-muted-foreground">
                        {{ schedule.contact_detail.email || '—' }}
                    </p>
                </template>
            </section>

            <section class="neo-surface space-y-3 p-5">
                <h2 class="text-sm font-semibold">Schedule</h2>
                <dl class="space-y-2 text-sm">
                    <div class="flex justify-between gap-4">
                        <dt class="text-muted-foreground">Frequency</dt>
                        <dd>{{ schedule.frequency_label }}</dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-muted-foreground">Start</dt>
                        <dd>{{ schedule.start_date || '—' }}</dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-muted-foreground">End</dt>
                        <dd>{{ schedule.end_date || 'Ongoing' }}</dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-muted-foreground">Next generation</dt>
                        <dd>{{ schedule.next_generation_date || '—' }}</dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-muted-foreground">Payment term</dt>
                        <dd>{{ schedule.payment_term_days }} days</dd>
                    </div>
                </dl>
            </section>

            <section
                v-if="schedule.notes || schedule.terms"
                class="neo-surface space-y-3 p-5 lg:col-span-2"
            >
                <div v-if="schedule.notes" class="space-y-1">
                    <h2 class="text-sm font-semibold">Notes</h2>
                    <p class="whitespace-pre-wrap text-sm text-muted-foreground">
                        {{ schedule.notes }}
                    </p>
                </div>
                <div v-if="schedule.terms" class="space-y-1">
                    <h2 class="text-sm font-semibold">Terms</h2>
                    <p class="whitespace-pre-wrap text-sm text-muted-foreground">
                        {{ schedule.terms }}
                    </p>
                </div>
            </section>

            <section class="neo-surface space-y-3 p-5 lg:col-span-3">
                <h2 class="text-sm font-semibold">Generation history</h2>
                <p class="text-xs text-muted-foreground">
                    Successful runs link to the independent invoice that was created.
                </p>
                <div v-if="generations.length === 0" class="text-sm text-muted-foreground">
                    No generations yet.
                </div>
                <div v-else class="overflow-x-auto">
                    <table class="w-full min-w-[720px] text-sm">
                        <thead class="border-b border-border text-left text-muted-foreground">
                            <tr>
                                <th class="py-2 pr-3 font-medium">Period</th>
                                <th class="py-2 pr-3 font-medium">Status</th>
                                <th class="py-2 pr-3 font-medium">Trigger</th>
                                <th class="py-2 pr-3 font-medium">Invoice</th>
                                <th class="py-2 font-medium">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="generation in generations"
                                :key="generation.id"
                                class="border-b border-border/60 last:border-0"
                            >
                                <td class="py-2 pr-3">
                                    <p class="font-medium">{{ generation.period_key }}</p>
                                    <p class="text-xs text-muted-foreground">
                                        {{ generation.period_start }} → {{ generation.period_end }}
                                    </p>
                                </td>
                                <td class="py-2 pr-3">
                                    {{ generation.status_label }}
                                    <p
                                        v-if="generation.failure_reason"
                                        class="mt-0.5 text-xs text-muted-foreground"
                                    >
                                        {{ generation.failure_reason }}
                                    </p>
                                </td>
                                <td class="py-2 pr-3 text-muted-foreground">
                                    {{ generation.trigger }}
                                </td>
                                <td class="py-2 pr-3">
                                    <Link
                                        v-if="generation.invoice"
                                        :href="`/invoices/${generation.invoice.id}`"
                                        class="font-medium underline-offset-4 hover:underline"
                                    >
                                        {{ generation.invoice.number }}
                                    </Link>
                                    <span v-else class="text-muted-foreground">—</span>
                                </td>
                                <td class="py-2">
                                    <Button
                                        v-if="permissions.generate && generation.can_retry"
                                        type="button"
                                        variant="secondary"
                                        size="sm"
                                        @click="
                                            postAction(
                                                `/recurring-billing/generations/${generation.id}/retry`,
                                                'Retry this generation? A successful retry creates an independent invoice.',
                                            )
                                        "
                                    >
                                        Retry
                                    </Button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </div>
</template>
