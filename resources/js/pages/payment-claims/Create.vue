<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';

type InvoiceOption = {
    id: number;
    number: string;
    total: string;
    amount_paid: string;
    balance_due: string;
    currency_code: string;
    contact: { id: number; display_name: string } | null;
};

const props = defineProps<{
    invoices: InvoiceOption[];
    methodOptions: Array<{ value: string; label: string }>;
    selectedInvoiceId: number | null;
    defaults: {
        claimed_payment_date: string;
        source_channel: string;
    };
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Payments', href: '/payments' },
            { title: 'Claims', href: '/payment-claims' },
            { title: 'New claim', href: '/payment-claims/create' },
        ],
    },
});

const initialInvoiceId =
    props.selectedInvoiceId && props.invoices.some((i) => i.id === props.selectedInvoiceId)
        ? props.selectedInvoiceId
        : (props.invoices[0]?.id ?? ('' as number | ''));

const form = useForm({
    invoice_id: initialInvoiceId,
    claimed_amount: props.invoices.find((i) => i.id === initialInvoiceId)?.balance_due ?? '',
    claimed_payment_date: props.defaults.claimed_payment_date,
    payment_method: '' as string,
    customer_reference: '',
    supporting_info: '',
    source_channel: props.defaults.source_channel,
});

const selectedInvoice = computed(
    () => props.invoices.find((i) => i.id === Number(form.invoice_id)) ?? null,
);

const onInvoiceChange = () => {
    if (selectedInvoice.value) {
        form.claimed_amount = selectedInvoice.value.balance_due;
    }
};

const formatMoney = (amount: string, currency: string) =>
    `${currency} ${Number(amount).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

const submit = () => {
    form.post('/payment-claims');
};
</script>

<template>
    <Head title="New payment claim" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
        <Heading
            title="New payment claim"
            description="Record a customer assertion of payment. Claims are not confirmed payments and do not change invoice balances."
        />

        <form class="mx-auto w-full max-w-2xl space-y-6" @submit.prevent="submit">
            <section class="neo-surface space-y-3 border-amber-500/30 p-5">
                <p class="text-sm font-medium">Claimed ≠ Confirmed</p>
                <p class="text-sm text-muted-foreground">
                    Creating a claim does not mark the invoice as paid. A reviewer must confirm the
                    claim before a financial payment is created.
                </p>
            </section>

            <section class="neo-surface space-y-4 p-5">
                <h2 class="text-sm font-semibold">Invoice</h2>
                <div class="space-y-2">
                    <Label for="invoice_id">Invoice</Label>
                    <select
                        id="invoice_id"
                        v-model="form.invoice_id"
                        class="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm"
                        required
                        @change="onInvoiceChange"
                    >
                        <option disabled value="">Select an invoice</option>
                        <option
                            v-for="invoice in invoices"
                            :key="invoice.id"
                            :value="invoice.id"
                        >
                            {{ invoice.number }}
                            —
                            {{ invoice.contact?.display_name || 'Unknown' }}
                            (balance
                            {{ formatMoney(invoice.balance_due, invoice.currency_code) }})
                        </option>
                    </select>
                    <InputError :message="form.errors.invoice_id" />
                </div>
            </section>

            <section class="neo-surface space-y-4 p-5">
                <h2 class="text-sm font-semibold">Claimed payment</h2>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="space-y-2">
                        <Label for="claimed_amount">Claimed amount</Label>
                        <Input
                            id="claimed_amount"
                            v-model="form.claimed_amount"
                            type="number"
                            min="0.01"
                            step="any"
                            required
                        />
                        <InputError :message="form.errors.claimed_amount" />
                    </div>
                    <div class="space-y-2">
                        <Label for="claimed_payment_date">Claimed payment date</Label>
                        <Input
                            id="claimed_payment_date"
                            v-model="form.claimed_payment_date"
                            type="date"
                        />
                        <InputError :message="form.errors.claimed_payment_date" />
                    </div>
                    <div class="space-y-2">
                        <Label for="payment_method">Method</Label>
                        <select
                            id="payment_method"
                            v-model="form.payment_method"
                            class="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm"
                        >
                            <option value="">Unknown / not stated</option>
                            <option
                                v-for="option in methodOptions"
                                :key="option.value"
                                :value="option.value"
                            >
                                {{ option.label }}
                            </option>
                        </select>
                        <InputError :message="form.errors.payment_method" />
                    </div>
                    <div class="space-y-2">
                        <Label for="customer_reference">Customer reference</Label>
                        <Input id="customer_reference" v-model="form.customer_reference" />
                        <InputError :message="form.errors.customer_reference" />
                    </div>
                    <div class="space-y-2 sm:col-span-2">
                        <Label for="supporting_info">Supporting information</Label>
                        <Textarea id="supporting_info" v-model="form.supporting_info" rows="3" />
                        <InputError :message="form.errors.supporting_info" />
                    </div>
                    <div class="space-y-2">
                        <Label for="source_channel">Source channel</Label>
                        <Input id="source_channel" v-model="form.source_channel" required />
                        <InputError :message="form.errors.source_channel" />
                    </div>
                </div>
            </section>

            <div class="flex justify-end gap-2">
                <Button type="button" variant="secondary" as-child>
                    <Link href="/payment-claims">Cancel</Link>
                </Button>
                <Button type="submit" :disabled="form.processing || invoices.length === 0">
                    {{ form.processing ? 'Saving…' : 'Create claim' }}
                </Button>
            </div>
        </form>
    </div>
</template>
