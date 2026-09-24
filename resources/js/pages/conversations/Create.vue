<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';

type ContactOption = {
    id: number;
    display_name: string;
    status: string;
    email: string | null;
    phone: string | null;
};

const props = defineProps<{
    channelOptions: Array<{ value: string; label: string }>;
    contacts: ContactOption[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Conversations', href: '/conversations' },
            { title: 'New conversation', href: '/conversations/create' },
        ],
    },
});

const form = useForm({
    channel: props.channelOptions[0]?.value ?? 'whatsapp',
    external_id: '',
    display_name: '',
    contact_id: '',
    subject: '',
    initial_message: '',
});

const submit = () => {
    form
        .transform((data) => ({
            ...data,
            contact_id: data.contact_id ? Number(data.contact_id) : null,
            display_name: data.display_name || null,
            subject: data.subject || null,
            initial_message: data.initial_message || null,
        }))
        .post('/conversations');
};
</script>

<template>
    <Head title="New conversation" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
        <Heading
            title="New conversation"
            description="Create a conversation for a communication identity. Linking a contact is optional and does not change lifecycle status."
        />

        <form class="mx-auto w-full max-w-2xl space-y-6" @submit.prevent="submit">
            <section class="neo-surface space-y-4 p-5">
                <h2 class="text-sm font-semibold">Communication identity</h2>

                <div class="space-y-2">
                    <Label for="channel">Channel</Label>
                    <select
                        id="channel"
                        v-model="form.channel"
                        class="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm"
                        required
                    >
                        <option
                            v-for="option in channelOptions"
                            :key="option.value"
                            :value="option.value"
                        >
                            {{ option.label }}
                        </option>
                    </select>
                    <InputError :message="form.errors.channel" />
                </div>

                <div class="space-y-2">
                    <Label for="external_id">External identifier</Label>
                    <Input
                        id="external_id"
                        v-model="form.external_id"
                        required
                        placeholder="Phone, WhatsApp ID, or email address"
                    />
                    <InputError :message="form.errors.external_id" />
                </div>

                <div class="space-y-2">
                    <Label for="display_name">Display name (optional)</Label>
                    <Input id="display_name" v-model="form.display_name" />
                    <InputError :message="form.errors.display_name" />
                </div>

                <div class="space-y-2">
                    <Label for="contact_id">Link to contact (optional)</Label>
                    <select
                        id="contact_id"
                        v-model="form.contact_id"
                        class="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm"
                    >
                        <option value="">Unknown — no contact linked</option>
                        <option
                            v-for="contact in contacts"
                            :key="contact.id"
                            :value="String(contact.id)"
                        >
                            {{ contact.display_name }}
                        </option>
                    </select>
                    <InputError :message="form.errors.contact_id" />
                </div>

                <div class="space-y-2">
                    <Label for="subject">Subject (optional)</Label>
                    <Input id="subject" v-model="form.subject" />
                    <InputError :message="form.errors.subject" />
                </div>

                <div class="space-y-2">
                    <Label for="initial_message">Initial inbound message (optional)</Label>
                    <Textarea
                        id="initial_message"
                        v-model="form.initial_message"
                        rows="3"
                        placeholder="Optional seed message for testing the thread"
                    />
                    <p class="text-xs text-muted-foreground">
                        Stored as an inbound history record. No external provider is contacted.
                    </p>
                    <InputError :message="form.errors.initial_message" />
                </div>
            </section>

            <div class="flex flex-wrap gap-2">
                <Button type="submit" :disabled="form.processing">
                    Create conversation
                </Button>
                <Button variant="secondary" as-child>
                    <Link href="/conversations">Cancel</Link>
                </Button>
            </div>
        </form>
    </div>
</template>
