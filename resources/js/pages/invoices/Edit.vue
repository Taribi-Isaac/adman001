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

type InvoiceDetail = {
    id: number;
    number: string;
    contact: { id: number; display_name: string } | null;
    due_date: string | null;
    discount_type: string;
    discount_value: string;
    tax_enabled: boolean;
    tax_rate: string;
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
    invoice: InvoiceDetail;
    customers: Customer[];
    discountTypeOptions: Array<{ value: string; label: string }>;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Invoices', href: '/invoices' },
            { title: 'Edit', href: '#' },
        ],
    },
});

const form = useForm({
    contact_id: props.invoice.contact?.id ?? '',
    due_date: props.invoice.due_date ?? '',
    discount_type: props.invoice.discount_type,
    discount_value: props.invoice.discount_value,
    tax_enabled: props.invoice.tax_enabled,
    tax_rate: props.invoice.tax_rate,
    notes: props.invoice.notes ?? '',
    terms: props.invoice.terms ?? '',
    items: props.invoice.items.map((item) => ({
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
    `${props.invoice.currency_code} ${amount.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

const submit = () => {
    form.put(`/invoices/${props.invoice.id}`);
};
</script>

<template>
    <Head :title="`Edit ${invoice.number}`" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
        <Heading :title="`Edit ${invoice.number}`" description="Only draft invoices can be edited." />

        <form class="mx-auto w-full max-w-4xl space-y-6" @submit.prevent="submit">
            <section class="neo-surface space-y-4 p-5">
                <h2 class="text-sm font-semibold">Customer</h2>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="space-y-2 sm:col-span-2">
                        <Label for="contact_id">Customer</Label>
                        <select
                            id="contact_id"
                            v-model="form.contact_id"
                            class="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm"
                            required
                        >
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
                        <Label for="due_date">Due date</Label>
                        <Input id="due_date" v-model="form.due_date" type="date" />
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
                        <Input :id="`qty-${index}`" v-model="item.quantity" type="number" min="0.0001" step="any" required />
                    </div>
                    <div class="space-y-2 sm:col-span-2">
                        <Label :for="`price-${index}`">Unit price</Label>
                        <Input :id="`price-${index}`" v-model="item.unit_price" type="number" min="0" step="any" required />
                    </div>
                    <div class="space-y-2 sm:col-span-2">
                        <Label :for="`unit-${index}`">Unit</Label>
                        <Input :id="`unit-${index}`" v-model="item.unit" />
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
                        <Input id="discount_value" v-model="form.discount_value" type="number" min="0" step="any" />
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
                        <input v-model="form.tax_enabled" type="checkbox" class="size-4 rounded border-input" />
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
                <p class="text-xs text-muted-foreground">Client-side preview only — final totals are calculated on save.</p>
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
                    <Link :href="`/invoices/${invoice.id}`">Cancel</Link>
                </Button>
                <Button type="submit" :disabled="form.processing">
                    {{ form.processing ? 'Saving…' : 'Save invoice' }}
                </Button>
            </div>
        </form>
    </div>
</template>
