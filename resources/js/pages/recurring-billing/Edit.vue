<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';

type Customer = { id: number; display_name: string; email: string | null };
type LineItem = { description: string; quantity: string; unit_price: string; unit: string };

type ScheduleDetail = {
    id: number;
    contact: { id: number; display_name: string } | null;
    frequency: string;
    start_date: string | null;
    end_date: string | null;
    payment_term_days: number;
    discount_type: string;
    discount_value: string;
    tax_enabled: boolean;
    tax_rate: string | null;
    notes: string | null;
    terms: string | null;
    currency_code: string;
    items: Array<{
        description: string;
        quantity: string;
        unit_price: string;
        unit: string | null;
    }>;
};

const props = defineProps<{
    schedule: ScheduleDetail;
    customers: Customer[];
    frequencyOptions: Array<{ value: string; label: string }>;
    discountTypeOptions: Array<{ value: string; label: string }>;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Recurring Billing', href: '/recurring-billing' },
            { title: 'Edit', href: '#' },
        ],
    },
});

const form = useForm({
    contact_id: props.schedule.contact?.id ?? '',
    frequency: props.schedule.frequency,
    start_date: props.schedule.start_date ?? '',
    end_date: props.schedule.end_date ?? '',
    payment_term_days: props.schedule.payment_term_days,
    discount_type: props.schedule.discount_type,
    discount_value: props.schedule.discount_value,
    tax_enabled: props.schedule.tax_enabled,
    tax_rate: props.schedule.tax_rate ?? '0',
    notes: props.schedule.notes ?? '',
    terms: props.schedule.terms ?? '',
    items: props.schedule.items.map((item) => ({
        description: item.description,
        quantity: String(item.quantity),
        unit_price: String(item.unit_price),
        unit: item.unit ?? '',
    })) as LineItem[],
});

const addItem = () => {
    form.items.push({ description: '', quantity: '1', unit_price: '0', unit: '' });
};

const removeItem = (index: number) => {
    if (form.items.length <= 1) {
        return;
    }
    form.items.splice(index, 1);
};

const preview = computed(() => {
    const subtotal = form.items.reduce((sum, item) => {
        const qty = Number(item.quantity) || 0;
        const price = Number(item.unit_price) || 0;
        return sum + qty * price;
    }, 0);

    let discount = 0;
    const discountValue = Number(form.discount_value) || 0;
    if (form.discount_type === 'percentage') {
        discount = (subtotal * discountValue) / 100;
    } else if (form.discount_type === 'fixed') {
        discount = discountValue;
    }
    discount = Math.min(discount, subtotal);
    const taxable = Math.max(subtotal - discount, 0);
    const taxRate = form.tax_enabled ? Number(form.tax_rate) || 0 : 0;
    const tax = (taxable * taxRate) / 100;

    return { subtotal, discount, tax, total: taxable + tax };
});

const formatMoney = (amount: number) =>
    `${props.schedule.currency_code} ${amount.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

const submit = () => {
    form.put(`/recurring-billing/${props.schedule.id}`);
};
</script>

<template>
    <Head :title="`Edit schedule #${schedule.id}`" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
        <Heading
            :title="`Edit schedule #${schedule.id}`"
            description="Changes apply to future invoices only. Already generated invoices stay unchanged."
        />

        <form class="mx-auto w-full max-w-4xl space-y-6" @submit.prevent="submit">
            <section class="neo-surface space-y-4 p-5">
                <h2 class="text-sm font-semibold">Customer & schedule</h2>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="space-y-2 sm:col-span-2">
                        <Label for="contact_id">Customer</Label>
                        <select
                            id="contact_id"
                            v-model="form.contact_id"
                            class="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm"
                            required
                        >
                            <option disabled value="">Select a customer</option>
                            <option
                                v-for="customer in customers"
                                :key="customer.id"
                                :value="customer.id"
                            >
                                {{ customer.display_name
                                }}{{ customer.email ? ` (${customer.email})` : '' }}
                            </option>
                        </select>
                        <InputError :message="form.errors.contact_id" />
                    </div>
                    <div class="space-y-2">
                        <Label for="frequency">Frequency</Label>
                        <select
                            id="frequency"
                            v-model="form.frequency"
                            class="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm"
                            required
                        >
                            <option
                                v-for="option in frequencyOptions"
                                :key="option.value"
                                :value="option.value"
                            >
                                {{ option.label }}
                            </option>
                        </select>
                        <InputError :message="form.errors.frequency" />
                    </div>
                    <div class="space-y-2">
                        <Label for="payment_term_days">Payment term (days)</Label>
                        <Input
                            id="payment_term_days"
                            v-model="form.payment_term_days"
                            type="number"
                            min="0"
                            max="365"
                            required
                        />
                        <InputError :message="form.errors.payment_term_days" />
                    </div>
                    <div class="space-y-2">
                        <Label for="start_date">Start date</Label>
                        <Input id="start_date" v-model="form.start_date" type="date" required />
                        <p class="text-xs text-muted-foreground">
                            Preferred day-of-month is derived from the original start date.
                        </p>
                        <InputError :message="form.errors.start_date" />
                    </div>
                    <div class="space-y-2">
                        <Label for="end_date">End date</Label>
                        <Input id="end_date" v-model="form.end_date" type="date" />
                        <InputError :message="form.errors.end_date" />
                    </div>
                </div>
            </section>

            <section class="neo-surface space-y-4 p-5">
                <div class="flex items-center justify-between gap-2">
                    <h2 class="text-sm font-semibold">Line items</h2>
                    <Button type="button" variant="secondary" @click="addItem">Add line</Button>
                </div>
                <div
                    v-for="(item, index) in form.items"
                    :key="index"
                    class="grid gap-3 border-b border-border/60 pb-4 last:border-0 sm:grid-cols-12"
                >
                    <div class="space-y-2 sm:col-span-5">
                        <Label :for="`desc-${index}`">Description</Label>
                        <Input :id="`desc-${index}`" v-model="item.description" required />
                    </div>
                    <div class="space-y-2 sm:col-span-2">
                        <Label :for="`qty-${index}`">Qty</Label>
                        <Input
                            :id="`qty-${index}`"
                            v-model="item.quantity"
                            type="number"
                            min="0.0001"
                            step="any"
                            required
                        />
                    </div>
                    <div class="space-y-2 sm:col-span-2">
                        <Label :for="`price-${index}`">Unit price</Label>
                        <Input
                            :id="`price-${index}`"
                            v-model="item.unit_price"
                            type="number"
                            min="0"
                            step="any"
                            required
                        />
                    </div>
                    <div class="space-y-2 sm:col-span-2">
                        <Label :for="`unit-${index}`">Unit</Label>
                        <Input :id="`unit-${index}`" v-model="item.unit" placeholder="optional" />
                    </div>
                    <div class="flex items-end sm:col-span-1">
                        <Button
                            type="button"
                            variant="secondary"
                            class="w-full"
                            :disabled="form.items.length <= 1"
                            @click="removeItem(index)"
                        >
                            Remove
                        </Button>
                    </div>
                </div>
                <InputError :message="form.errors.items" />
            </section>

            <section class="neo-surface space-y-4 p-5">
                <h2 class="text-sm font-semibold">Discount & tax</h2>
                <div class="grid gap-4 sm:grid-cols-3">
                    <div class="space-y-2">
                        <Label for="discount_type">Discount type</Label>
                        <select
                            id="discount_type"
                            v-model="form.discount_type"
                            class="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm"
                        >
                            <option
                                v-for="option in discountTypeOptions"
                                :key="option.value"
                                :value="option.value"
                            >
                                {{ option.label }}
                            </option>
                        </select>
                    </div>
                    <div class="space-y-2">
                        <Label for="discount_value">Discount value</Label>
                        <Input
                            id="discount_value"
                            v-model="form.discount_value"
                            type="number"
                            min="0"
                            step="any"
                        />
                    </div>
                    <div class="space-y-2">
                        <Label for="tax_rate">Tax rate (%)</Label>
                        <Input
                            id="tax_rate"
                            v-model="form.tax_rate"
                            type="number"
                            min="0"
                            step="any"
                            :disabled="!form.tax_enabled"
                        />
                    </div>
                    <label class="flex items-center gap-2 text-sm sm:col-span-3">
                        <input
                            v-model="form.tax_enabled"
                            type="checkbox"
                            class="size-4 rounded border-input"
                        />
                        Enable tax
                    </label>
                </div>
            </section>

            <section class="neo-surface space-y-4 p-5">
                <h2 class="text-sm font-semibold">Notes & terms</h2>
                <div class="grid gap-4">
                    <div class="space-y-2">
                        <Label for="notes">Notes</Label>
                        <Textarea id="notes" v-model="form.notes" rows="3" />
                    </div>
                    <div class="space-y-2">
                        <Label for="terms">Terms</Label>
                        <Textarea id="terms" v-model="form.terms" rows="3" />
                    </div>
                </div>
            </section>

            <section class="neo-surface space-y-3 p-5">
                <h2 class="text-sm font-semibold">Totals preview</h2>
                <dl class="grid gap-2 text-sm sm:grid-cols-2">
                    <div class="flex justify-between gap-4 sm:block">
                        <dt class="text-muted-foreground">Subtotal</dt>
                        <dd>{{ formatMoney(preview.subtotal) }}</dd>
                    </div>
                    <div class="flex justify-between gap-4 sm:block">
                        <dt class="text-muted-foreground">Discount</dt>
                        <dd>{{ formatMoney(preview.discount) }}</dd>
                    </div>
                    <div class="flex justify-between gap-4 sm:block">
                        <dt class="text-muted-foreground">Tax</dt>
                        <dd>{{ formatMoney(preview.tax) }}</dd>
                    </div>
                    <div class="flex justify-between gap-4 font-medium sm:block">
                        <dt>Total</dt>
                        <dd>{{ formatMoney(preview.total) }}</dd>
                    </div>
                </dl>
            </section>

            <div class="flex justify-end gap-2">
                <Button type="button" variant="secondary" as-child>
                    <Link :href="`/recurring-billing/${schedule.id}`">Cancel</Link>
                </Button>
                <Button type="submit" :disabled="form.processing">
                    {{ form.processing ? 'Saving…' : 'Save changes' }}
                </Button>
            </div>
        </form>
    </div>
</template>
