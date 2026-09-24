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
    canConfirmImmediately: boolean;
    defaults: {
        payment_date: string;
        payment_method: string;
    };
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Payments', href: '/payments' },
            { title: 'Record payment', href: '/payments/create' },
        ],
    },
});

const initialInvoiceId =
    props.selectedInvoiceId && props.invoices.some((i) => i.id === props.selectedInvoiceId)
        ? props.selectedInvoiceId
        : (props.invoices[0]?.id ?? ('' as number | ''));

const form = useForm({
    invoice_id: initialInvoiceId,
    amount: props.invoices.find((i) => i.id === initialInvoiceId)?.balance_due ?? '',
    payment_method: props.defaults.payment_method,
    payment_date: props.defaults.payment_date,
    reference: '',
    notes: '',
    confirm_immediately: false,
});

const selectedInvoice = computed(
    () => props.invoices.find((i) => i.id === Number(form.invoice_id)) ?? null,
);

const onInvoiceChange = () => {
    if (selectedInvoice.value) {
        form.amount = selectedInvoice.value.balance_due;
    }
};

const formatMoney = (amount: string, currency: string) =>
    `${currency} ${Number(amount).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

const submit = () => {
    if (
        form.confirm_immediately &&
        !confirm(
            'Confirm this payment immediately? Confirmed payments update the invoice balance and cannot be rejected.',
        )
    ) {
        return;
    }
    form.post('/payments');
};
</script>

<template>
    <Head title="Record payment" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
        <Heading
            title="Record payment"
            description="Enter a payment against an issued invoice. Pending until confirmed unless you confirm immediately."
        />

        <form class="mx-auto w-full max-w-2xl space-y-6" @submit.prevent="submit">
            <section class="neo-surface space-y-4 p-5">
                <h2 class="text-sm font-semibold">Invoice</h2>
                <div class="space-y-2">
                    <Label for="invoice_id">Issued invoice with balance</Label>
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
                    <p v-if="invoices.length === 0" class="text-xs text-muted-foreground">
                        No issued invoices with an outstanding balance.
                    </p>
                    <dl
                        v-if="selectedInvoice"
                        class="mt-3 grid gap-2 rounded-md border border-border bg-muted/30 p-3 text-sm sm:grid-cols-3"
                    >
                        <div>
                            <dt class="text-xs text-muted-foreground">Total</dt>
                            <dd>
                                {{
                                    formatMoney(
                                        selectedInvoice.total,
                                        selectedInvoice.currency_code,
                                    )
                                }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs text-muted-foreground">Paid</dt>
                            <dd>
                                {{
                                    formatMoney(
                                        selectedInvoice.amount_paid,
                                        selectedInvoice.currency_code,
                                    )
                                }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs text-muted-foreground">Balance due</dt>
                            <dd class="font-medium">
                                {{
                                    formatMoney(
                                        selectedInvoice.balance_due,
                                        selectedInvoice.currency_code,
                                    )
                                }}
                            </dd>
                        </div>
                    </dl>
                </div>
            </section>

            <section class="neo-surface space-y-4 p-5">
                <h2 class="text-sm font-semibold">Payment details</h2>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="space-y-2">
                        <Label for="amount">Amount</Label>
                        <Input
                            id="amount"
                            v-model="form.amount"
                            type="number"
                            min="0.01"
                            step="any"
                            required
                        />
                        <InputError :message="form.errors.amount" />
                    </div>
                    <div class="space-y-2">
                        <Label for="payment_date">Payment date</Label>
                        <Input id="payment_date" v-model="form.payment_date" type="date" required />
                        <InputError :message="form.errors.payment_date" />
                    </div>
                    <div class="space-y-2">
                        <Label for="payment_method">Method</Label>
                        <select
                            id="payment_method"
                            v-model="form.payment_method"
                            class="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm"
                            required
                        >
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
                        <Label for="reference">Reference</Label>
                        <Input id="reference" v-model="form.reference" placeholder="optional" />
                        <InputError :message="form.errors.reference" />
                    </div>
                    <div class="space-y-2 sm:col-span-2">
                        <Label for="notes">Notes</Label>
                        <Textarea id="notes" v-model="form.notes" rows="3" />
                        <InputError :message="form.errors.notes" />
                    </div>
                    <label
                        v-if="canConfirmImmediately"
                        class="flex items-start gap-2 text-sm sm:col-span-2"
                    >
                        <input
                            v-model="form.confirm_immediately"
                            type="checkbox"
                            class="mt-0.5 size-4 rounded border-input"
                        />
                        <span>
                            Confirm immediately
                            <span class="block text-xs text-muted-foreground">
                                Applies the payment to the invoice balance now. Only use when
                                payment is verified.
                            </span>
                        </span>
                    </label>
                </div>
            </section>

            <div class="flex justify-end gap-2">
                <Button type="button" variant="secondary" as-child>
                    <Link href="/payments">Cancel</Link>
                </Button>
                <Button type="submit" :disabled="form.processing || invoices.length === 0">
                    {{ form.processing ? 'Saving…' : 'Record payment' }}
                </Button>
            </div>
        </form>
    </div>
</template>
