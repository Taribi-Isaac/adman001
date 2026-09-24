<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';

const props = defineProps<{
    contact: {
        id: number;
        type: string;
        first_name: string | null;
        last_name: string | null;
        organization_name: string | null;
        email: string | null;
        phone: string | null;
        whatsapp_id: string | null;
        whatsapp_opt_in?: boolean;
        reminder_channel?: string;
        address_line_1: string | null;
        address_line_2: string | null;
        city: string | null;
        state: string | null;
        postal_code: string | null;
        country: string | null;
        notes: string | null;
        display_name: string;
    };
    typeOptions: Array<{ value: string; label: string }>;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Customers', href: '/contacts' },
            { title: 'Edit', href: '#' },
        ],
    },
});

const form = useForm({
    type: props.contact.type,
    first_name: props.contact.first_name ?? '',
    last_name: props.contact.last_name ?? '',
    organization_name: props.contact.organization_name ?? '',
    email: props.contact.email ?? '',
    phone: props.contact.phone ?? '',
    whatsapp_id: props.contact.whatsapp_id ?? '',
    whatsapp_opt_in: Boolean(props.contact.whatsapp_opt_in),
    reminder_channel: props.contact.reminder_channel ?? 'email',
    address_line_1: props.contact.address_line_1 ?? '',
    address_line_2: props.contact.address_line_2 ?? '',
    city: props.contact.city ?? '',
    state: props.contact.state ?? '',
    postal_code: props.contact.postal_code ?? '',
    country: props.contact.country ?? '',
    notes: props.contact.notes ?? '',
});

const submit = () => {
    form.put(`/contacts/${props.contact.id}`);
};
</script>

<template>
    <Head :title="`Edit ${contact.display_name}`" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
        <Heading
            :title="`Edit ${contact.display_name}`"
            description="Lifecycle status is changed with Promote actions, not from this form."
        />

        <form class="mx-auto w-full max-w-3xl space-y-6" @submit.prevent="submit">
            <section class="neo-surface space-y-4 p-5">
                <h2 class="text-sm font-semibold">Identity</h2>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="space-y-2 sm:col-span-2">
                        <Label for="type">Contact type</Label>
                        <select
                            id="type"
                            v-model="form.type"
                            class="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm"
                            required
                        >
                            <option
                                v-for="option in typeOptions"
                                :key="option.value"
                                :value="option.value"
                            >
                                {{ option.label }}
                            </option>
                        </select>
                    </div>
                    <div class="space-y-2">
                        <Label for="first_name">First name</Label>
                        <Input id="first_name" v-model="form.first_name" />
                    </div>
                    <div class="space-y-2">
                        <Label for="last_name">Last name</Label>
                        <Input id="last_name" v-model="form.last_name" />
                    </div>
                    <div class="space-y-2 sm:col-span-2">
                        <Label for="organization_name">Organization</Label>
                        <Input id="organization_name" v-model="form.organization_name" />
                    </div>
                </div>
            </section>

            <section class="neo-surface space-y-4 p-5">
                <h2 class="text-sm font-semibold">Contact details</h2>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="space-y-2">
                        <Label for="email">Email</Label>
                        <Input id="email" v-model="form.email" type="email" />
                        <InputError :message="form.errors.email" />
                    </div>
                    <div class="space-y-2">
                        <Label for="phone">Phone</Label>
                        <Input id="phone" v-model="form.phone" />
                        <InputError :message="form.errors.phone" />
                    </div>
                    <div class="space-y-2 sm:col-span-2">
                        <Label for="whatsapp_id">WhatsApp ID</Label>
                        <Input id="whatsapp_id" v-model="form.whatsapp_id" />
                        <InputError :message="form.errors.whatsapp_id" />
                        <p class="text-xs text-muted-foreground">
                            Digits with country code (e.g. 2348012345678). Used for WhatsApp
                            messaging.
                        </p>
                    </div>
                    <div class="space-y-2 sm:col-span-2">
                        <label class="flex items-center gap-2 text-sm">
                            <input
                                v-model="form.whatsapp_opt_in"
                                type="checkbox"
                                class="rounded border-border"
                            />
                            WhatsApp business messaging opt-in
                        </label>
                        <InputError :message="form.errors.whatsapp_opt_in" />
                    </div>
                    <div class="space-y-2 sm:col-span-2">
                        <Label for="reminder_channel">Invoice reminder channel</Label>
                        <select
                            id="reminder_channel"
                            v-model="form.reminder_channel"
                            class="flex h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm"
                        >
                            <option value="email">Email</option>
                            <option value="whatsapp">WhatsApp</option>
                            <option value="both">Both</option>
                        </select>
                        <InputError :message="form.errors.reminder_channel" />
                    </div>
                </div>
            </section>

            <section class="neo-surface space-y-4 p-5">
                <h2 class="text-sm font-semibold">Address</h2>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="space-y-2 sm:col-span-2">
                        <Label for="address_line_1">Address line 1</Label>
                        <Input id="address_line_1" v-model="form.address_line_1" />
                    </div>
                    <div class="space-y-2 sm:col-span-2">
                        <Label for="address_line_2">Address line 2</Label>
                        <Input id="address_line_2" v-model="form.address_line_2" />
                    </div>
                    <div class="space-y-2">
                        <Label for="city">City</Label>
                        <Input id="city" v-model="form.city" />
                    </div>
                    <div class="space-y-2">
                        <Label for="state">State</Label>
                        <Input id="state" v-model="form.state" />
                    </div>
                    <div class="space-y-2">
                        <Label for="postal_code">Postal code</Label>
                        <Input id="postal_code" v-model="form.postal_code" />
                    </div>
                    <div class="space-y-2">
                        <Label for="country">Country</Label>
                        <Input id="country" v-model="form.country" />
                    </div>
                </div>
            </section>

            <section class="neo-surface space-y-4 p-5">
                <h2 class="text-sm font-semibold">Notes</h2>
                <Textarea id="notes" v-model="form.notes" rows="4" />
            </section>

            <div class="flex justify-end gap-2">
                <Button type="button" variant="secondary" as-child>
                    <a :href="`/contacts/${contact.id}`">Cancel</a>
                </Button>
                <Button type="submit" :disabled="form.processing">
                    {{ form.processing ? 'Saving…' : 'Save changes' }}
                </Button>
            </div>
        </form>
    </div>
</template>
