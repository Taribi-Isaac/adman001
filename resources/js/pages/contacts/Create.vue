<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';

defineProps<{
    typeOptions: Array<{ value: string; label: string }>;
    statusOptions: Array<{ value: string; label: string }>;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Customers', href: '/contacts' },
            { title: 'Add contact', href: '/contacts/create' },
        ],
    },
});

const form = useForm({
    type: 'individual',
    status: 'unknown',
    first_name: '',
    last_name: '',
    organization_name: '',
    email: '',
    phone: '',
    whatsapp_id: '',
    address_line_1: '',
    address_line_2: '',
    city: '',
    state: '',
    postal_code: '',
    country: '',
    notes: '',
});

const submit = () => {
    form.post('/contacts');
};
</script>

<template>
    <Head title="Add contact" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
        <Heading
            title="Add contact"
            description="Unknown contacts can be created with limited information. Promote them later when the relationship is established."
        />

        <form class="mx-auto w-full max-w-3xl space-y-6" @submit.prevent="submit">
            <section class="neo-surface space-y-4 p-5">
                <h2 class="text-sm font-semibold">Identity</h2>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="space-y-2">
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
                        <InputError :message="form.errors.type" />
                    </div>
                    <div class="space-y-2">
                        <Label for="status">Initial status</Label>
                        <select
                            id="status"
                            v-model="form.status"
                            class="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm"
                        >
                            <option
                                v-for="option in statusOptions"
                                :key="option.value"
                                :value="option.value"
                            >
                                {{ option.label }}
                            </option>
                        </select>
                        <p class="text-xs text-muted-foreground">
                            Prefer Unknown unless you are deliberately starting as Prospect or Customer.
                        </p>
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
                        <InputError :message="form.errors.organization_name" />
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
                            Communication identity only — does not make this a customer.
                        </p>
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
                <InputError :message="form.errors.notes" />
            </section>

            <div class="flex justify-end gap-2">
                <Button type="button" variant="secondary" as-child>
                    <a href="/contacts">Cancel</a>
                </Button>
                <Button type="submit" :disabled="form.processing">
                    {{ form.processing ? 'Saving…' : 'Create contact' }}
                </Button>
            </div>
        </form>
    </div>
</template>
