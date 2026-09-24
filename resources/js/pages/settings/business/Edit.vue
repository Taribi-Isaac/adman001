<script setup lang="ts">
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';

type Business = {
    name: string;
    legal_name: string | null;
    registration_number: string | null;
    email: string | null;
    outbound_email_enabled: boolean;
    email_reply_to: string | null;
    outbound_whatsapp_enabled: boolean;
    phone: string | null;
    website: string | null;
    description: string | null;
    ai_support_instructions: string | null;
    address_line_1: string | null;
    address_line_2: string | null;
    city: string | null;
    state: string | null;
    postal_code: string | null;
    country: string | null;
    tax_enabled: boolean;
    tax_name: string | null;
    tax_rate: string | number | null;
    tax_identification: string | null;
    currency_code: string;
    timezone: string;
    bank_name: string | null;
    bank_account_name: string | null;
    bank_account_number: string | null;
    payment_instructions: string | null;
    default_terms: string | null;
    invoice_number_prefix: string | null;
    quote_number_prefix: string | null;
    receipt_number_prefix: string | null;
    default_payment_term_days: number;
};

type LogoProps = {
    has_custom: boolean;
    preview_url: string;
};

const props = defineProps<{
    business: Business;
    timezones: string[];
    canUpdate: boolean;
    logo: LogoProps;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Settings', href: '/settings/business' },
            { title: 'Business', href: '/settings/business' },
        ],
    },
});

const page = usePage();
const flashSuccess = computed(() => page.props.flash?.success);

const form = useForm({
    name: props.business.name ?? '',
    legal_name: props.business.legal_name ?? '',
    registration_number: props.business.registration_number ?? '',
    email: props.business.email ?? '',
    outbound_email_enabled: Boolean(props.business.outbound_email_enabled ?? true),
    email_reply_to: props.business.email_reply_to ?? '',
    outbound_whatsapp_enabled: Boolean(props.business.outbound_whatsapp_enabled ?? true),
    phone: props.business.phone ?? '',
    website: props.business.website ?? '',
    description: props.business.description ?? '',
    ai_support_instructions: props.business.ai_support_instructions ?? '',
    address_line_1: props.business.address_line_1 ?? '',
    address_line_2: props.business.address_line_2 ?? '',
    city: props.business.city ?? '',
    state: props.business.state ?? '',
    postal_code: props.business.postal_code ?? '',
    country: props.business.country ?? '',
    tax_enabled: Boolean(props.business.tax_enabled),
    tax_name: props.business.tax_name ?? 'VAT',
    tax_rate: props.business.tax_rate ?? '',
    tax_identification: props.business.tax_identification ?? '',
    currency_code: props.business.currency_code ?? 'NGN',
    timezone: props.business.timezone ?? 'UTC',
    bank_name: props.business.bank_name ?? '',
    bank_account_name: props.business.bank_account_name ?? '',
    bank_account_number: props.business.bank_account_number ?? '',
    payment_instructions: props.business.payment_instructions ?? '',
    default_terms: props.business.default_terms ?? '',
    invoice_number_prefix: props.business.invoice_number_prefix ?? 'INV-',
    quote_number_prefix: props.business.quote_number_prefix ?? 'QT-',
    receipt_number_prefix: props.business.receipt_number_prefix ?? 'RCPT-',
    default_payment_term_days: props.business.default_payment_term_days ?? 14,
});

const logoForm = useForm<{ logo: File | null }>({
    logo: null,
});

const logoBusy = ref(false);

const onLogoSelected = (event: Event) => {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0] ?? null;
    logoForm.logo = file;
};

const uploadLogo = () => {
    if (!logoForm.logo) {
        return;
    }

    logoBusy.value = true;
    logoForm.post('/settings/business/logo', {
        forceFormData: true,
        preserveScroll: true,
        onFinish: () => {
            logoBusy.value = false;
            logoForm.reset('logo');
        },
    });
};

const removeLogo = () => {
    if (!props.logo.has_custom) {
        return;
    }

    logoBusy.value = true;
    router.delete('/settings/business/logo', {
        preserveScroll: true,
        onFinish: () => {
            logoBusy.value = false;
        },
    });
};

const submit = () => {
    form.put('/settings/business', { preserveScroll: true });
};
</script>

<template>
    <Head title="Business settings" />

    <div class="space-y-8">
        <Heading
            variant="small"
            title="Business"
            description="Identity, branding, tax, payment instructions, timezone, and document defaults. Changes affect future records; issued document snapshots stay frozen."
        />

        <p
            v-if="flashSuccess"
            class="rounded-lg border border-border bg-muted/40 px-3 py-2 text-sm text-foreground"
            role="status"
        >
            {{ flashSuccess }}
        </p>

        <section class="neo-surface space-y-4 p-5">
            <h2 class="text-sm font-semibold">Branding</h2>
            <p class="text-sm text-muted-foreground">
                Used on generated quotes, invoices, and payment acknowledgements. When no custom logo
                is set, ADMAN uses the default logo.
            </p>
            <div class="flex flex-col gap-4 sm:flex-row sm:items-start">
                <div
                    class="flex h-24 w-40 items-center justify-center overflow-hidden rounded-md border border-border bg-muted/30 p-2"
                >
                    <img
                        :src="logo.preview_url"
                        alt="Business logo preview"
                        class="max-h-full max-w-full object-contain"
                    />
                </div>
                <div class="space-y-3">
                    <p class="text-xs text-muted-foreground">
                        {{
                            logo.has_custom
                                ? 'Custom logo is active for newly issued documents.'
                                : 'Showing the ADMAN default logo.'
                        }}
                    </p>
                    <div v-if="canUpdate" class="space-y-2">
                        <Label for="logo">Upload logo</Label>
                        <Input
                            id="logo"
                            type="file"
                            accept="image/jpeg,image/png,image/webp,image/gif"
                            :disabled="logoBusy || logoForm.processing"
                            @change="onLogoSelected"
                        />
                        <InputError :message="logoForm.errors.logo" />
                        <p class="text-xs text-muted-foreground">
                            JPEG, PNG, WebP, or GIF. Max 2 MB.
                        </p>
                        <div class="flex flex-wrap gap-2">
                            <Button
                                type="button"
                                :disabled="!logoForm.logo || logoBusy || logoForm.processing"
                                @click="uploadLogo"
                            >
                                {{ logoForm.processing ? 'Uploading…' : 'Save logo' }}
                            </Button>
                            <Button
                                v-if="logo.has_custom"
                                type="button"
                                variant="outline"
                                :disabled="logoBusy || logoForm.processing"
                                @click="removeLogo"
                            >
                                Reset to default
                            </Button>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <form class="space-y-8" @submit.prevent="submit">
            <section class="neo-surface space-y-4 p-5">
                <h2 class="text-sm font-semibold">Identity</h2>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="space-y-2 sm:col-span-2">
                        <Label for="name">Business name</Label>
                        <Input id="name" v-model="form.name" :disabled="!canUpdate" required />
                        <InputError :message="form.errors.name" />
                    </div>
                    <div class="space-y-2">
                        <Label for="legal_name">Legal name</Label>
                        <Input id="legal_name" v-model="form.legal_name" :disabled="!canUpdate" />
                    </div>
                    <div class="space-y-2">
                        <Label for="registration_number">Registration number</Label>
                        <Input
                            id="registration_number"
                            v-model="form.registration_number"
                            :disabled="!canUpdate"
                        />
                    </div>
                    <div class="space-y-2">
                        <Label for="email">Email (used as From when set)</Label>
                        <Input id="email" v-model="form.email" type="email" :disabled="!canUpdate" />
                        <InputError :message="form.errors.email" />
                    </div>
                    <div class="space-y-2">
                        <Label for="email_reply_to">Reply-to email</Label>
                        <Input
                            id="email_reply_to"
                            v-model="form.email_reply_to"
                            type="email"
                            :disabled="!canUpdate"
                        />
                        <InputError :message="form.errors.email_reply_to" />
                    </div>
                    <div class="space-y-2 sm:col-span-2">
                        <label class="flex items-center gap-2 text-sm">
                            <input
                                v-model="form.outbound_email_enabled"
                                type="checkbox"
                                class="rounded border-border"
                                :disabled="!canUpdate"
                            />
                            Outbound email enabled
                        </label>
                        <InputError :message="form.errors.outbound_email_enabled" />
                        <p class="text-xs text-muted-foreground">
                            Provider credentials stay in server environment variables (MAIL_*), not
                            in this form.
                        </p>
                    </div>
                    <div class="space-y-2 sm:col-span-2">
                        <label class="flex items-center gap-2 text-sm">
                            <input
                                v-model="form.outbound_whatsapp_enabled"
                                type="checkbox"
                                class="rounded border-border"
                                :disabled="!canUpdate"
                            />
                            Outbound WhatsApp enabled
                        </label>
                        <InputError :message="form.errors.outbound_whatsapp_enabled" />
                        <p class="text-xs text-muted-foreground">
                            WhatsApp Cloud API credentials stay in WHATSAPP_* environment variables.
                        </p>
                    </div>
                    <div class="space-y-2">
                        <Label for="phone">Phone</Label>
                        <Input id="phone" v-model="form.phone" :disabled="!canUpdate" />
                    </div>
                    <div class="space-y-2 sm:col-span-2">
                        <Label for="website">Website</Label>
                        <Input id="website" v-model="form.website" :disabled="!canUpdate" />
                        <InputError :message="form.errors.website" />
                    </div>
                    <div class="space-y-2 sm:col-span-2">
                        <Label for="description">Business description</Label>
                        <Textarea
                            id="description"
                            v-model="form.description"
                            :disabled="!canUpdate"
                            rows="3"
                        />
                        <p class="text-xs text-muted-foreground">
                            Used by the AI for general customer questions about what the business does.
                        </p>
                        <InputError :message="form.errors.description" />
                    </div>
                    <div class="space-y-2 sm:col-span-2">
                        <Label for="ai_support_instructions">AI support instructions</Label>
                        <Textarea
                            id="ai_support_instructions"
                            v-model="form.ai_support_instructions"
                            :disabled="!canUpdate"
                            rows="3"
                        />
                        <p class="text-xs text-muted-foreground">
                            Optional customer-support guidance for the AI (tone, escalation, house rules).
                        </p>
                        <InputError :message="form.errors.ai_support_instructions" />
                    </div>
                </div>
            </section>

            <section class="neo-surface space-y-4 p-5">
                <h2 class="text-sm font-semibold">Address</h2>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="space-y-2 sm:col-span-2">
                        <Label for="address_line_1">Address line 1</Label>
                        <Input
                            id="address_line_1"
                            v-model="form.address_line_1"
                            :disabled="!canUpdate"
                        />
                    </div>
                    <div class="space-y-2 sm:col-span-2">
                        <Label for="address_line_2">Address line 2</Label>
                        <Input
                            id="address_line_2"
                            v-model="form.address_line_2"
                            :disabled="!canUpdate"
                        />
                    </div>
                    <div class="space-y-2">
                        <Label for="city">City</Label>
                        <Input id="city" v-model="form.city" :disabled="!canUpdate" />
                    </div>
                    <div class="space-y-2">
                        <Label for="state">State</Label>
                        <Input id="state" v-model="form.state" :disabled="!canUpdate" />
                    </div>
                    <div class="space-y-2">
                        <Label for="postal_code">Postal code</Label>
                        <Input id="postal_code" v-model="form.postal_code" :disabled="!canUpdate" />
                    </div>
                    <div class="space-y-2">
                        <Label for="country">Country</Label>
                        <Input id="country" v-model="form.country" :disabled="!canUpdate" />
                    </div>
                </div>
            </section>

            <section class="neo-surface space-y-4 p-5">
                <h2 class="text-sm font-semibold">Tax & currency</h2>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="flex items-center gap-3 sm:col-span-2">
                        <input
                            id="tax_enabled"
                            v-model="form.tax_enabled"
                            type="checkbox"
                            class="size-4 rounded border-input"
                            :disabled="!canUpdate"
                        />
                        <Label for="tax_enabled">Enable tax / VAT</Label>
                    </div>
                    <div class="space-y-2">
                        <Label for="tax_name">Tax name</Label>
                        <Input id="tax_name" v-model="form.tax_name" :disabled="!canUpdate" />
                    </div>
                    <div class="space-y-2">
                        <Label for="tax_rate">Tax rate (%)</Label>
                        <Input id="tax_rate" v-model="form.tax_rate" :disabled="!canUpdate" />
                        <InputError :message="form.errors.tax_rate" />
                    </div>
                    <div class="space-y-2">
                        <Label for="tax_identification">Tax ID</Label>
                        <Input
                            id="tax_identification"
                            v-model="form.tax_identification"
                            :disabled="!canUpdate"
                        />
                    </div>
                    <div class="space-y-2">
                        <Label for="currency_code">Currency code</Label>
                        <Input
                            id="currency_code"
                            v-model="form.currency_code"
                            maxlength="3"
                            :disabled="!canUpdate"
                            required
                        />
                        <InputError :message="form.errors.currency_code" />
                    </div>
                    <div class="space-y-2 sm:col-span-2">
                        <Label for="timezone">Business timezone</Label>
                        <select
                            id="timezone"
                            v-model="form.timezone"
                            class="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px] disabled:opacity-50"
                            :disabled="!canUpdate"
                            required
                        >
                            <option v-for="tz in timezones" :key="tz" :value="tz">
                                {{ tz }}
                            </option>
                        </select>
                        <InputError :message="form.errors.timezone" />
                        <p class="text-xs text-muted-foreground">
                            Used for recurring billing, due dates, reminders, and scheduled automation.
                        </p>
                    </div>
                </div>
            </section>

            <section class="neo-surface space-y-4 p-5">
                <h2 class="text-sm font-semibold">Payment information</h2>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="space-y-2">
                        <Label for="bank_name">Bank name</Label>
                        <Input id="bank_name" v-model="form.bank_name" :disabled="!canUpdate" />
                    </div>
                    <div class="space-y-2">
                        <Label for="bank_account_name">Account name</Label>
                        <Input
                            id="bank_account_name"
                            v-model="form.bank_account_name"
                            :disabled="!canUpdate"
                        />
                    </div>
                    <div class="space-y-2 sm:col-span-2">
                        <Label for="bank_account_number">Account number</Label>
                        <Input
                            id="bank_account_number"
                            v-model="form.bank_account_number"
                            :disabled="!canUpdate"
                        />
                    </div>
                    <div class="space-y-2 sm:col-span-2">
                        <Label for="payment_instructions">Payment instructions</Label>
                        <Textarea
                            id="payment_instructions"
                            v-model="form.payment_instructions"
                            :disabled="!canUpdate"
                            rows="4"
                        />
                    </div>
                </div>
            </section>

            <section class="neo-surface space-y-4 p-5">
                <h2 class="text-sm font-semibold">Document defaults</h2>
                <div class="grid gap-4 sm:grid-cols-3">
                    <div class="space-y-2">
                        <Label for="invoice_number_prefix">Invoice prefix</Label>
                        <Input
                            id="invoice_number_prefix"
                            v-model="form.invoice_number_prefix"
                            :disabled="!canUpdate"
                        />
                    </div>
                    <div class="space-y-2">
                        <Label for="quote_number_prefix">Quote prefix</Label>
                        <Input
                            id="quote_number_prefix"
                            v-model="form.quote_number_prefix"
                            :disabled="!canUpdate"
                        />
                    </div>
                    <div class="space-y-2">
                        <Label for="receipt_number_prefix">Receipt prefix</Label>
                        <Input
                            id="receipt_number_prefix"
                            v-model="form.receipt_number_prefix"
                            :disabled="!canUpdate"
                        />
                    </div>
                    <div class="space-y-2">
                        <Label for="default_payment_term_days">Default payment term (days)</Label>
                        <Input
                            id="default_payment_term_days"
                            v-model="form.default_payment_term_days"
                            type="number"
                            min="0"
                            max="365"
                            :disabled="!canUpdate"
                        />
                        <InputError :message="form.errors.default_payment_term_days" />
                        <p class="text-xs text-muted-foreground">
                            Used when issuing invoices without an explicit due date.
                        </p>
                    </div>
                    <div class="space-y-2 sm:col-span-3">
                        <Label for="default_terms">Default terms</Label>
                        <Textarea
                            id="default_terms"
                            v-model="form.default_terms"
                            :disabled="!canUpdate"
                            rows="4"
                        />
                    </div>
                </div>
            </section>

            <div v-if="canUpdate" class="flex justify-end">
                <Button type="submit" :disabled="form.processing">
                    {{ form.processing ? 'Saving…' : 'Save business settings' }}
                </Button>
            </div>
        </form>
    </div>
</template>
