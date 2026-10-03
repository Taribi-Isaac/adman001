<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import type { BroadcastFormProps } from '@/types/broadcasts';

const props = defineProps<BroadcastFormProps>();

const form = useForm({
    name: props.broadcast?.name ?? '',
    channel: props.broadcast?.channel ?? 'email',
    audience_type: props.broadcast?.audience_type ?? 'customers',
    selected_contact_ids: [
        ...(props.broadcast?.selected_contact_ids ?? []),
    ] as number[],
    subject: props.broadcast?.subject ?? '',
    body: props.broadcast?.body ?? '',
    whatsapp_message: props.broadcast?.whatsapp_message ?? '',
});

const isEmail = computed(() => form.channel === 'email');
const needsWhatsAppMessage = computed(
    () =>
        !isEmail.value &&
        props.whatsappTemplate.configured &&
        props.whatsappTemplate.uses_message,
);
const whatsappMessageLength = computed(() => form.whatsapp_message.length);
const whatsappMessageHasPlaceholder = computed(() =>
    /\{\{|\}\}/.test(form.whatsapp_message),
);
const whatsappMessageError = computed(() => {
    if (whatsappMessageHasPlaceholder.value) {
        return 'The campaign message cannot contain {{ or }}. Write plain text; the customer name is added automatically.';
    }

    return form.errors.whatsapp_message;
});
const isSelected = computed(() => form.audience_type === 'selected');

const contactFilter = ref('');
const filteredContacts = computed(() => {
    const needle = contactFilter.value.trim().toLowerCase();
    if (needle === '') {
        return props.contactOptions;
    }

    return props.contactOptions.filter((c) =>
        [c.name, c.email ?? '', c.phone ?? ''].some((v) =>
            v.toLowerCase().includes(needle),
        ),
    );
});

const toggleContact = (id: number) => {
    const index = form.selected_contact_ids.indexOf(id);
    if (index === -1) {
        form.selected_contact_ids.push(id);
    } else {
        form.selected_contact_ids.splice(index, 1);
    }
};

const selectedContactErrors = computed(() => {
    const errors = form.errors as Record<string, string | undefined>;

    return (
        errors.selected_contact_ids ??
        Object.entries(errors).find(([key]) =>
            key.startsWith('selected_contact_ids.'),
        )?.[1]
    );
});

const submit = () => {
    if (needsWhatsAppMessage.value && whatsappMessageHasPlaceholder.value) {
        return;
    }

    form.transform((data) => ({
        ...data,
        selected_contact_ids:
            data.audience_type === 'selected' ? data.selected_contact_ids : [],
        subject: data.channel === 'email' ? data.subject : null,
        body: data.channel === 'email' ? data.body : null,
        whatsapp_message: needsWhatsAppMessage.value
            ? data.whatsapp_message
            : null,
    }));

    if (props.broadcast) {
        form.put(`/broadcasts/${props.broadcast.id}`);
    } else {
        form.post('/broadcasts');
    }
};
</script>

<template>
    <form class="space-y-6" @submit.prevent="submit">
        <p
            v-if="!broadcastsEnabled"
            class="rounded-lg border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-900 dark:border-amber-700 dark:bg-amber-950/40 dark:text-amber-200"
        >
            Broadcasts are turned off in Business settings. You can save a
            draft, but it cannot be sent until they are turned on.
        </p>

        <section class="neo-surface space-y-4 p-5">
            <div class="space-y-2">
                <Label for="name">Broadcast name</Label>
                <Input id="name" v-model="form.name" required maxlength="150" />
                <p class="text-xs text-muted-foreground">
                    For your records only; customers do not see it.
                </p>
                <InputError :message="form.errors.name" />
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div class="space-y-2">
                    <Label for="channel">Channel</Label>
                    <select
                        id="channel"
                        v-model="form.channel"
                        class="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm"
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
                    <Label for="audience_type">Audience</Label>
                    <select
                        id="audience_type"
                        v-model="form.audience_type"
                        class="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm"
                    >
                        <option
                            v-for="option in audienceOptions"
                            :key="option.value"
                            :value="option.value"
                        >
                            {{ option.label }}
                        </option>
                    </select>
                    <InputError :message="form.errors.audience_type" />
                </div>
            </div>
            <p class="text-xs text-muted-foreground">
                Only contacts who agreed to receive broadcasts on this channel
                will get it. Archived and Unknown contacts are never included.
                Limit: {{ recipientLimit }} recipients per broadcast.
            </p>
        </section>

        <section v-if="isSelected" class="neo-surface space-y-3 p-5">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 class="text-sm font-semibold">Select contacts</h2>
                <span class="text-xs text-muted-foreground">
                    {{ form.selected_contact_ids.length }} selected
                </span>
            </div>
            <Input
                v-model="contactFilter"
                placeholder="Filter by name, email or phone…"
            />
            <div
                class="max-h-80 divide-y divide-border/70 overflow-y-auto rounded-md border border-border"
            >
                <label
                    v-for="contact in filteredContacts"
                    :key="contact.id"
                    class="flex cursor-pointer items-center gap-3 px-3 py-2 text-sm hover:bg-muted/40"
                >
                    <input
                        type="checkbox"
                        class="size-4 rounded border-input"
                        :checked="
                            form.selected_contact_ids.includes(contact.id)
                        "
                        @change="toggleContact(contact.id)"
                    />
                    <span class="flex-1">
                        <span class="font-medium">{{ contact.name }}</span>
                        <span class="ml-2 text-xs text-muted-foreground">{{
                            contact.status_label
                        }}</span>
                    </span>
                    <span class="text-xs text-muted-foreground">
                        {{
                            isEmail
                                ? contact.email || 'No email'
                                : contact.phone || 'No number'
                        }}
                    </span>
                </label>
                <p
                    v-if="filteredContacts.length === 0"
                    class="px-3 py-6 text-center text-sm text-muted-foreground"
                >
                    No matching customers or prospects.
                </p>
            </div>
            <p class="text-xs text-muted-foreground">
                Selected contacts without broadcast consent are excluded
                automatically at review.
            </p>
            <InputError :message="selectedContactErrors" />
        </section>

        <section v-if="isEmail" class="neo-surface space-y-4 p-5">
            <h2 class="text-sm font-semibold">Email content</h2>
            <div class="space-y-2">
                <Label for="subject">Subject</Label>
                <Input id="subject" v-model="form.subject" maxlength="200" />
                <InputError :message="form.errors.subject" />
            </div>
            <div class="space-y-2">
                <Label for="body">Message</Label>
                <Textarea id="body" v-model="form.body" :rows="8" />
                <p class="text-xs text-muted-foreground">
                    Plain text. No attachments. An unsubscribe link is added
                    automatically.
                </p>
                <InputError :message="form.errors.body" />
            </div>
        </section>

        <section v-else class="neo-surface space-y-3 p-5">
            <h2 class="text-sm font-semibold">WhatsApp content</h2>
            <p
                v-if="needsWhatsAppMessage"
                class="text-sm text-muted-foreground"
            >
                WhatsApp broadcasts use a Marketing template approved by Meta.
                The template greets each customer by name and includes the
                message you write below.
            </p>
            <p v-else class="text-sm text-muted-foreground">
                WhatsApp broadcasts can only use a Marketing template approved
                by Meta. The message text is the approved template; it cannot be
                written here.
            </p>
            <div v-if="needsWhatsAppMessage" class="space-y-2">
                <Label for="whatsapp_message">Message</Label>
                <Textarea
                    id="whatsapp_message"
                    v-model="form.whatsapp_message"
                    :rows="6"
                    required
                    :maxlength="whatsappTemplate.message_max_length"
                />
                <div
                    class="flex flex-wrap justify-between gap-2 text-xs text-muted-foreground"
                >
                    <p>
                        This message is inserted into the approved WhatsApp
                        template. The customer name is added automatically. The
                        same message is sent to every selected recipient. Plain
                        text only; line breaks are sent as spaces.
                    </p>
                    <span class="tabular-nums">
                        {{ whatsappMessageLength }} /
                        {{ whatsappTemplate.message_max_length }}
                    </span>
                </div>
                <InputError :message="whatsappMessageError" />
            </div>
            <p v-if="whatsappTemplate.configured" class="text-sm">
                Template:
                <span class="font-medium">{{ whatsappTemplate.name }}</span>
                <span class="text-muted-foreground">
                    ({{ whatsappTemplate.language }})</span
                >
            </p>
            <p
                v-else
                class="rounded-lg border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-900 dark:border-amber-700 dark:bg-amber-950/40 dark:text-amber-200"
            >
                {{ whatsappTemplate.problem }}
            </p>
        </section>

        <div class="flex flex-wrap justify-end gap-2">
            <Button variant="secondary" as-child>
                <Link
                    :href="
                        broadcast
                            ? `/broadcasts/${broadcast.id}`
                            : '/broadcasts'
                    "
                    >Cancel</Link
                >
            </Button>
            <Button type="submit" :disabled="form.processing">
                {{ form.processing ? 'Saving…' : 'Save draft and review' }}
            </Button>
        </div>
    </form>
</template>
