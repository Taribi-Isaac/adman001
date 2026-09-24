<script setup lang="ts">
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';

type ConversationDetail = {
    id: number;
    channel: string;
    channel_label: string;
    mode: string;
    mode_label: string;
    subject: string | null;
    last_message_at: string | null;
    is_closed: boolean;
    created_at: string | null;
    closed_at: string | null;
    identity: {
        id: number | null;
        external_id: string | null;
        display_name: string | null;
        is_linked: boolean;
    };
    contact: {
        id: number;
        display_name: string;
        status: string;
        status_label: string;
    } | null;
    contact_detail: {
        id: number;
        display_name: string;
        type: string;
        type_label: string;
        status: string;
        status_label: string;
        email: string | null;
        phone: string | null;
        whatsapp_id: string | null;
        organization_name: string | null;
    } | null;
    assigned_user: { id: number; name: string } | null;
};

type MessageRow = {
    id: number;
    direction: string;
    direction_label: string;
    channel: string;
    channel_label: string;
    body: string;
    subject: string | null;
    status: string;
    status_label: string;
    actor_type: string;
    actor_type_label: string;
    actor_name: string | null;
    occurred_at: string | null;
    sent_at: string | null;
    failed_at: string | null;
    failure_reason: string | null;
    is_external_delivery: boolean;
    can_retry: boolean;
};

type LinkableContact = {
    id: number;
    display_name: string;
    status: string;
    status_label: string;
    email: string | null;
    phone: string | null;
    type: string;
};

type AttachmentRow = {
    id: number;
    message_id: number;
    original_filename: string | null;
    mime_type: string | null;
    byte_size: number | null;
    media_kind: string | null;
    processing_status: string;
    review_status: string;
    review_status_label: string;
    failure_reason: string | null;
    created_at: string | null;
    downloadable: boolean;
    download_url: string;
};

const props = defineProps<{
    conversation: ConversationDetail;
    messages: MessageRow[];
    attachments: AttachmentRow[];
    permissions: {
        takeover: boolean;
        close: boolean;
        link: boolean;
        compose: boolean;
        retry_email: boolean;
        attachments_view: boolean;
        attachments_review: boolean;
    };
    linkableContacts: LinkableContact[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Conversations', href: '/conversations' },
            { title: 'Thread', href: '#' },
        ],
    },
});

const page = usePage();
const flashSuccess = computed(() => page.props.flash?.success);
const selectedContactId = ref(
    props.linkableContacts[0]?.id ? String(props.linkableContacts[0].id) : '',
);

const composeForm = useForm({
    body: '',
});

const modeClass = computed(() => {
    switch (props.conversation.mode) {
        case 'human':
            return 'border-sky-500/30 text-sky-800 dark:text-sky-300';
        case 'closed':
            return 'border-border text-muted-foreground';
        default:
            return 'border-violet-500/30 text-violet-800 dark:text-violet-300';
    }
});

const title = computed(
    () =>
        props.conversation.identity.display_name ||
        props.conversation.subject ||
        `Conversation #${props.conversation.id}`,
);

const formatDate = (value: string | null) =>
    value ? new Date(value).toLocaleString() : '—';

const takeOver = () => {
    router.post(`/conversations/${props.conversation.id}/take-over`, {}, { preserveScroll: true });
};

const returnToAi = () => {
    router.post(
        `/conversations/${props.conversation.id}/return-to-ai`,
        {},
        { preserveScroll: true },
    );
};

const closeConversation = () => {
    if (!confirm('Close this conversation? History is preserved.')) {
        return;
    }
    router.post(`/conversations/${props.conversation.id}/close`, {}, { preserveScroll: true });
};

const reopen = () => {
    router.post(`/conversations/${props.conversation.id}/reopen`, {}, { preserveScroll: true });
};

const linkContact = () => {
    if (!selectedContactId.value) {
        return;
    }
    router.post(
        `/conversations/${props.conversation.id}/link-contact`,
        { contact_id: Number(selectedContactId.value) },
        { preserveScroll: true },
    );
};

const unlinkContact = () => {
    if (!confirm('Unlink this identity from the contact? Lifecycle status is unchanged.')) {
        return;
    }
    router.post(
        `/conversations/${props.conversation.id}/unlink-contact`,
        {},
        { preserveScroll: true },
    );
};

const submitCompose = () => {
    composeForm.post(`/conversations/${props.conversation.id}/messages`, {
        preserveScroll: true,
        onSuccess: () => composeForm.reset('body'),
    });
};
</script>

<template>
    <Head :title="title" />

    <div class="flex h-full flex-1 flex-col gap-4 p-4 md:p-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="space-y-2">
                <Heading :title="title" />
                <div class="flex flex-wrap items-center gap-2 text-sm">
                    <span
                        class="inline-flex items-center rounded-full border px-2.5 py-0.5 text-xs font-medium"
                        :class="modeClass"
                        :aria-label="`Mode: ${conversation.mode_label}`"
                    >
                        Mode: {{ conversation.mode_label }}
                    </span>
                    <span class="text-muted-foreground">{{
                        conversation.channel_label
                    }}</span>
                    <span
                        v-if="conversation.assigned_user"
                        class="text-muted-foreground"
                    >
                        Assigned: {{ conversation.assigned_user.name }}
                    </span>
                </div>
                <p class="max-w-xl text-xs text-muted-foreground">
                    AI replies only in AI mode. Take over to pause AI; return to AI when ready.
                </p>
            </div>

            <div class="flex flex-wrap gap-2">
                <Button
                    v-if="permissions.takeover && !conversation.is_closed && conversation.mode !== 'human'"
                    type="button"
                    @click="takeOver"
                >
                    Take over
                </Button>
                <Button
                    v-if="permissions.takeover && !conversation.is_closed && conversation.mode === 'human'"
                    type="button"
                    variant="secondary"
                    @click="returnToAi"
                >
                    Return to AI
                </Button>
                <Button
                    v-if="permissions.close && !conversation.is_closed"
                    type="button"
                    variant="secondary"
                    @click="closeConversation"
                >
                    Close
                </Button>
                <Button
                    v-if="permissions.close && conversation.is_closed"
                    type="button"
                    @click="reopen"
                >
                    Reopen
                </Button>
                <Button variant="secondary" as-child>
                    <Link href="/conversations">Back to list</Link>
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

        <div class="grid flex-1 gap-4 lg:grid-cols-[minmax(0,1fr)_280px]">
            <section class="neo-surface flex min-h-[420px] flex-col p-4">
                <h2 class="mb-3 text-sm font-semibold">Message history</h2>

                <div class="flex-1 space-y-3 overflow-y-auto pr-1">
                    <article
                        v-for="message in messages"
                        :key="message.id"
                        class="rounded-lg border border-border/80 px-3 py-2.5"
                        :class="
                            message.direction === 'outbound'
                                ? 'ml-6 bg-primary/5'
                                : 'mr-6 bg-muted/30'
                        "
                    >
                        <div
                            class="mb-1 flex flex-wrap items-center gap-2 text-xs text-muted-foreground"
                        >
                            <span class="font-medium text-foreground">{{
                                message.direction_label
                            }}</span>
                            <span aria-hidden="true">·</span>
                            <span>{{ message.actor_type_label }}</span>
                            <span
                                v-if="message.actor_type === 'ai'"
                                class="rounded border border-violet-500/30 px-1.5 py-0.5 text-violet-800 dark:text-violet-300"
                            >
                                AI
                            </span>
                            <span v-if="message.actor_name"
                                >({{ message.actor_name }})</span
                            >
                            <span aria-hidden="true">·</span>
                            <span>{{ formatDate(message.occurred_at) }}</span>
                            <span aria-hidden="true">·</span>
                            <span>{{ message.status_label }}</span>
                            <span
                                v-if="message.channel === 'email'"
                                class="rounded border border-border px-1.5 py-0.5"
                            >
                                Email
                            </span>
                            <span
                                v-if="message.channel === 'whatsapp'"
                                class="rounded border border-border px-1.5 py-0.5"
                            >
                                WhatsApp
                            </span>
                            <span
                                v-if="!message.is_external_delivery"
                                class="rounded border border-border px-1.5 py-0.5"
                            >
                                Internal record
                            </span>
                        </div>
                        <p
                            v-if="message.subject"
                            class="mb-1 text-sm font-medium"
                        >
                            {{ message.subject }}
                        </p>
                        <p class="whitespace-pre-wrap text-sm">{{ message.body }}</p>
                        <p
                            v-if="message.failure_reason"
                            class="mt-2 text-xs text-destructive"
                        >
                            {{ message.failure_reason }}
                        </p>
                        <div
                            v-if="permissions.retry_email && message.can_retry"
                            class="mt-2"
                        >
                            <Button
                                type="button"
                                size="sm"
                                variant="secondary"
                                @click="
                                    router.post(
                                        `/messages/${message.id}/retry-email`,
                                        {},
                                        { preserveScroll: true },
                                    )
                                "
                            >
                                Retry message
                            </Button>
                        </div>
                    </article>

                    <p
                        v-if="messages.length === 0"
                        class="py-8 text-center text-sm text-muted-foreground"
                    >
                        No messages in this conversation yet.
                    </p>
                </div>

                <form
                    v-if="permissions.compose && !conversation.is_closed"
                    class="mt-4 space-y-2 border-t border-border pt-4"
                    @submit.prevent="submitCompose"
                >
                    <Label for="body">Compose internal outbound record</Label>
                    <p class="text-xs text-muted-foreground">
                        Creates a message history record only. Nothing is sent to
                        WhatsApp or email.
                    </p>
                    <Textarea
                        id="body"
                        v-model="composeForm.body"
                        rows="3"
                        required
                        placeholder="Type an internal outbound message record…"
                    />
                    <InputError :message="composeForm.errors.body" />
                    <Button
                        type="submit"
                        :disabled="composeForm.processing"
                    >
                        Record outbound message
                    </Button>
                </form>
                <p
                    v-else-if="conversation.is_closed"
                    class="mt-4 border-t border-border pt-4 text-sm text-muted-foreground"
                >
                    This conversation is closed. Reopen to compose messages.
                </p>
            </section>

            <aside class="space-y-4">
                <section
                    v-if="permissions.attachments_view"
                    class="neo-surface space-y-3 p-4"
                >
                    <h2 class="text-sm font-semibold">Inbound files</h2>
                    <p class="text-xs text-muted-foreground">
                        Customer/unknown-contact files are stored privately for staff review.
                        Receipt does not confirm payment.
                    </p>
                    <ul v-if="attachments.length" class="space-y-3 text-sm">
                        <li
                            v-for="file in attachments"
                            :key="file.id"
                            class="rounded-lg border border-border/80 px-3 py-2"
                        >
                            <p class="font-medium break-all">
                                {{ file.original_filename || 'Attachment' }}
                            </p>
                            <p class="text-xs text-muted-foreground">
                                {{ file.media_kind || 'file' }}
                                · {{ file.review_status_label }}
                                · {{ formatDate(file.created_at) }}
                            </p>
                            <p
                                v-if="file.failure_reason"
                                class="mt-1 text-xs text-destructive"
                            >
                                {{ file.failure_reason }}
                            </p>
                            <div class="mt-2 flex flex-wrap gap-2">
                                <Button
                                    v-if="file.downloadable"
                                    variant="secondary"
                                    size="sm"
                                    as-child
                                >
                                    <a :href="file.download_url">Download</a>
                                </Button>
                                <Button
                                    v-if="
                                        permissions.attachments_review &&
                                        file.review_status === 'pending_review'
                                    "
                                    type="button"
                                    size="sm"
                                    @click="
                                        router.post(
                                            `/attachments/${file.id}/review`,
                                            {
                                                review_status: 'reviewed',
                                                reviewer_notes: '',
                                            },
                                            { preserveScroll: true },
                                        )
                                    "
                                >
                                    Mark reviewed
                                </Button>
                            </div>
                        </li>
                    </ul>
                    <p v-else class="text-sm text-muted-foreground">
                        No inbound files for this conversation.
                    </p>
                </section>

                <section class="neo-surface space-y-3 p-4">
                    <h2 class="text-sm font-semibold">Communication identity</h2>
                    <dl class="space-y-2 text-sm">
                        <div>
                            <dt class="text-muted-foreground">Channel</dt>
                            <dd>{{ conversation.channel_label }}</dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground">External ID</dt>
                            <dd class="break-all">
                                {{ conversation.identity.external_id || '—' }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground">Display name</dt>
                            <dd>
                                {{ conversation.identity.display_name || '—' }}
                            </dd>
                        </div>
                    </dl>
                </section>

                <section class="neo-surface space-y-3 p-4">
                    <h2 class="text-sm font-semibold">Contact context</h2>

                    <template v-if="conversation.contact_detail">
                        <p class="text-sm font-medium">
                            {{ conversation.contact_detail.display_name }}
                        </p>
                        <dl class="space-y-2 text-sm">
                            <div>
                                <dt class="text-muted-foreground">Type</dt>
                                <dd>{{ conversation.contact_detail.type_label }}</dd>
                            </div>
                            <div>
                                <dt class="text-muted-foreground">Lifecycle</dt>
                                <dd>
                                    {{ conversation.contact_detail.status_label }}
                                </dd>
                            </div>
                            <div v-if="conversation.contact_detail.phone">
                                <dt class="text-muted-foreground">Phone</dt>
                                <dd>{{ conversation.contact_detail.phone }}</dd>
                            </div>
                            <div v-if="conversation.contact_detail.email">
                                <dt class="text-muted-foreground">Email</dt>
                                <dd>{{ conversation.contact_detail.email }}</dd>
                            </div>
                            <div v-if="conversation.contact_detail.whatsapp_id">
                                <dt class="text-muted-foreground">WhatsApp ID</dt>
                                <dd>{{ conversation.contact_detail.whatsapp_id }}</dd>
                            </div>
                        </dl>
                        <Button
                            v-if="permissions.link"
                            type="button"
                            variant="secondary"
                            class="w-full"
                            @click="unlinkContact"
                        >
                            Unlink contact
                        </Button>
                        <Button variant="secondary" as-child class="w-full">
                            <Link
                                :href="`/contacts/${conversation.contact_detail.id}`"
                            >
                                Open contact
                            </Link>
                        </Button>
                    </template>

                    <template v-else>
                        <p
                            class="inline-flex items-center rounded-full border border-border px-2.5 py-0.5 text-xs text-muted-foreground"
                        >
                            Unknown contact
                        </p>
                        <p class="text-sm text-muted-foreground">
                            This sender is not linked to a Contact. Linking does
                            not change contact lifecycle status.
                        </p>

                        <div v-if="permissions.link" class="space-y-2">
                            <Label for="contact_id">Link to contact</Label>
                            <select
                                id="contact_id"
                                v-model="selectedContactId"
                                class="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm"
                            >
                                <option
                                    v-for="contact in linkableContacts"
                                    :key="contact.id"
                                    :value="String(contact.id)"
                                >
                                    {{ contact.display_name }}
                                    ({{ contact.status_label }})
                                </option>
                            </select>
                            <Button
                                type="button"
                                class="w-full"
                                :disabled="!selectedContactId"
                                @click="linkContact"
                            >
                                Link to contact
                            </Button>
                        </div>
                    </template>
                </section>

                <section class="neo-surface space-y-2 p-4 text-sm">
                    <h2 class="text-sm font-semibold">Thread meta</h2>
                    <p>
                        <span class="text-muted-foreground">Created:</span>
                        {{ formatDate(conversation.created_at) }}
                    </p>
                    <p>
                        <span class="text-muted-foreground">Last activity:</span>
                        {{ formatDate(conversation.last_message_at) }}
                    </p>
                    <p v-if="conversation.closed_at">
                        <span class="text-muted-foreground">Closed:</span>
                        {{ formatDate(conversation.closed_at) }}
                    </p>
                </section>
            </aside>
        </div>
    </div>
</template>
