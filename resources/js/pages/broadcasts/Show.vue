<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';

type ExclusionRow = { reason: string; label: string; count: number };

type BroadcastDetail = {
    id: number;
    name: string;
    channel: string;
    channel_label: string;
    audience_type: string;
    audience_label: string;
    selected_count: number;
    subject: string | null;
    body: string | null;
    whatsapp_template_name: string | null;
    whatsapp_template_language: string | null;
    status: string;
    status_label: string;
    recipient_count: number;
    recipient_limit: number | null;
    exclusions: ExclusionRow[];
    failure_reason: string | null;
    created_by: string | null;
    sent_by: string | null;
    cancelled_by: string | null;
    created_at: string | null;
    send_requested_at: string | null;
    started_at: string | null;
    completed_at: string | null;
    cancelled_at: string | null;
    failed_at: string | null;
};

type Counts = {
    total: number;
    pending: number;
    queued: number;
    sent: number;
    delivered: number;
    failed: number;
    skipped: number;
    cancelled: number;
};

type Preview = {
    eligible_count: number;
    excluded_count: number;
    exclusions: ExclusionRow[];
    limit: number;
    over_limit: boolean;
    template: { name: string; language: string } | null;
    blockers: string[];
    sample: Array<{ id: number; name: string }>;
};

type RecipientRow = {
    id: number;
    contact_id: number;
    contact_name: string | null;
    address: string | null;
    status: string;
    status_label: string;
    message_id: number | null;
    provider_message_id: string | null;
    failure_reason: string | null;
    queued_at: string | null;
    sent_at: string | null;
    delivered_at: string | null;
};

type PaginatedRecipients = {
    data: RecipientRow[];
    links: Array<{ url: string | null; label: string; active: boolean }>;
    from: number | null;
    to: number | null;
    total: number;
};

const props = defineProps<{
    broadcast: BroadcastDetail;
    counts: Counts;
    recipients: PaginatedRecipients;
    preview: Preview | null;
    permissions: { edit: boolean; send: boolean; cancel: boolean };
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Broadcasts', href: '/broadcasts' },
            { title: 'Detail', href: '#' },
        ],
    },
});

const page = usePage();
const flashSuccess = computed(() => page.props.flash?.success);
const flashError = computed(() => page.props.flash?.error);
const broadcastError = computed(
    () =>
        (page.props.errors as Record<string, string> | undefined)?.broadcast ??
        (page.props.errors as Record<string, string> | undefined)
            ?.confirm_recipient_count,
);

const reviewed = ref(false);
const sending = ref(false);
const cancelling = ref(false);

const isEmail = computed(() => props.broadcast.channel === 'email');
const canSendNow = computed(
    () =>
        props.permissions.send &&
        props.preview !== null &&
        props.preview.blockers.length === 0,
);
const isActive = computed(() =>
    ['queued', 'sending'].includes(props.broadcast.status),
);

const formatDateTime = (iso: string | null) =>
    iso
        ? new Date(iso).toLocaleString(undefined, {
              dateStyle: 'medium',
              timeStyle: 'short',
          })
        : '—';

const send = () => {
    if (!props.preview || !reviewed.value) {
        return;
    }

    const count = props.preview.eligible_count;
    if (
        !window.confirm(
            `Send this broadcast to ${count} recipient${count === 1 ? '' : 's'} now? This cannot be undone.`,
        )
    ) {
        return;
    }

    sending.value = true;
    router.post(
        `/broadcasts/${props.broadcast.id}/send`,
        { confirm_recipient_count: count },
        {
            preserveScroll: true,
            onFinish: () => {
                sending.value = false;
            },
        },
    );
};

const cancel = () => {
    if (
        !window.confirm(
            'Cancel this broadcast? Messages not yet sent will not be sent. Messages already sent stay recorded.',
        )
    ) {
        return;
    }

    cancelling.value = true;
    router.post(
        `/broadcasts/${props.broadcast.id}/cancel`,
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                cancelling.value = false;
            },
        },
    );
};

const refresh = () => {
    router.reload({ only: ['broadcast', 'counts', 'recipients'] });
};
</script>

<template>
    <Head :title="broadcast.name" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <Heading
                :title="broadcast.name"
                :description="`${broadcast.channel_label} · ${broadcast.audience_label}`"
            />
            <div class="flex flex-wrap items-center gap-2">
                <span
                    class="inline-flex items-center rounded-full border border-border px-2.5 py-0.5 text-xs font-medium"
                >
                    {{ broadcast.status_label }}
                </span>
                <Button v-if="isActive" variant="secondary" @click="refresh"
                    >Refresh progress</Button
                >
                <Button v-if="permissions.edit" variant="secondary" as-child>
                    <Link :href="`/broadcasts/${broadcast.id}/edit`"
                        >Edit draft</Link
                    >
                </Button>
                <Button
                    v-if="permissions.cancel"
                    variant="destructive"
                    :disabled="cancelling"
                    @click="cancel"
                >
                    {{ cancelling ? 'Cancelling…' : 'Cancel broadcast' }}
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
        <p
            v-if="flashError || broadcastError"
            class="rounded-lg border border-destructive/40 bg-destructive/10 px-3 py-2 text-sm text-destructive"
            role="alert"
        >
            {{ broadcastError || flashError }}
        </p>
        <p
            v-if="broadcast.failure_reason"
            class="rounded-lg border border-destructive/40 bg-destructive/10 px-3 py-2 text-sm text-destructive"
        >
            {{ broadcast.failure_reason }}
        </p>

        <section class="neo-surface space-y-3 p-5">
            <h2 class="text-sm font-semibold">Message</h2>
            <template v-if="isEmail">
                <p class="text-sm">
                    <span class="text-muted-foreground">Subject:</span>
                    {{ broadcast.subject }}
                </p>
                <div
                    class="rounded-md border border-border bg-muted/20 p-3 text-sm whitespace-pre-line"
                >
                    {{ broadcast.body }}
                </div>
                <p class="text-xs text-muted-foreground">
                    Sent as a plain email with no attachment. Each email
                    includes a personal unsubscribe link.
                </p>
            </template>
            <template v-else>
                <p class="text-sm">
                    <span class="text-muted-foreground"
                        >Approved WhatsApp Marketing template:</span
                    >
                    <span class="font-medium">
                        {{
                            broadcast.whatsapp_template_name ??
                            preview?.template?.name ??
                            'Not configured'
                        }}
                    </span>
                    <span
                        v-if="
                            broadcast.whatsapp_template_language ??
                            preview?.template?.language
                        "
                        class="text-muted-foreground"
                    >
                        ({{
                            broadcast.whatsapp_template_language ??
                            preview?.template?.language
                        }})
                    </span>
                </p>
                <p class="text-xs text-muted-foreground">
                    The customer receives the template text exactly as approved
                    by Meta.
                </p>
            </template>
        </section>

        <section v-if="preview" class="neo-surface space-y-4 p-5">
            <h2 class="text-sm font-semibold">Review before sending</h2>
            <dl class="grid gap-3 text-sm sm:grid-cols-4">
                <div>
                    <dt class="text-muted-foreground">Channel</dt>
                    <dd class="font-medium">{{ broadcast.channel_label }}</dd>
                </div>
                <div>
                    <dt class="text-muted-foreground">Audience</dt>
                    <dd class="font-medium">
                        {{ broadcast.audience_label }}
                        <span
                            v-if="broadcast.audience_type === 'selected'"
                            class="text-muted-foreground"
                        >
                            ({{ broadcast.selected_count }} chosen)
                        </span>
                    </dd>
                </div>
                <div>
                    <dt class="text-muted-foreground">Will receive</dt>
                    <dd class="text-lg font-semibold tabular-nums">
                        {{ preview.eligible_count }}
                    </dd>
                </div>
                <div>
                    <dt class="text-muted-foreground">Excluded</dt>
                    <dd class="text-lg font-semibold tabular-nums">
                        {{ preview.excluded_count }}
                    </dd>
                </div>
            </dl>
            <p class="text-xs text-muted-foreground">
                Recipient limit: {{ preview.limit }} per broadcast. Larger
                audiences are refused, never partly sent.
            </p>

            <div v-if="preview.exclusions.length > 0" class="space-y-1">
                <h3
                    class="text-xs font-semibold tracking-wide text-muted-foreground uppercase"
                >
                    Why contacts are excluded
                </h3>
                <ul class="space-y-1 text-sm">
                    <li
                        v-for="row in preview.exclusions"
                        :key="row.reason"
                        class="flex justify-between gap-4"
                    >
                        <span>{{ row.label }}</span>
                        <span class="text-muted-foreground tabular-nums">{{
                            row.count
                        }}</span>
                    </li>
                </ul>
                <p class="text-xs text-muted-foreground">
                    A contact can be excluded for more than one reason.
                </p>
            </div>

            <div v-if="preview.sample.length > 0" class="text-sm">
                <span class="text-muted-foreground">Recipients include:</span>
                {{ preview.sample.map((c) => c.name).join(', ')
                }}<span v-if="preview.eligible_count > preview.sample.length"
                    >, …</span
                >
            </div>

            <ul
                v-if="preview.blockers.length > 0"
                class="space-y-1 rounded-lg border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-900 dark:border-amber-700 dark:bg-amber-950/40 dark:text-amber-200"
            >
                <li v-for="blocker in preview.blockers" :key="blocker">
                    {{ blocker }}
                </li>
            </ul>

            <div
                v-if="permissions.send"
                class="space-y-3 border-t border-border pt-4"
            >
                <label class="flex items-start gap-2 text-sm">
                    <input
                        v-model="reviewed"
                        type="checkbox"
                        class="mt-0.5 size-4 rounded border-input"
                        :disabled="!canSendNow"
                    />
                    <span>
                        I have reviewed the message and the
                        {{ preview.eligible_count }} recipient{{
                            preview.eligible_count === 1 ? '' : 's'
                        }}, and every one of them agreed to receive broadcasts.
                    </span>
                </label>
                <Button
                    :disabled="!canSendNow || !reviewed || sending"
                    @click="send"
                >
                    {{
                        sending
                            ? 'Starting…'
                            : `Send to ${preview.eligible_count} recipient${preview.eligible_count === 1 ? '' : 's'}`
                    }}
                </Button>
                <InputError :message="broadcastError" />
            </div>
            <p v-else class="text-sm text-muted-foreground">
                Only the business owner or someone with the "Start sending a
                broadcast" permission can send.
            </p>
        </section>

        <section
            v-if="broadcast.status !== 'draft'"
            class="neo-surface space-y-3 p-5"
        >
            <h2 class="text-sm font-semibold">Progress</h2>
            <dl class="grid gap-3 text-sm sm:grid-cols-4 lg:grid-cols-8">
                <div>
                    <dt class="text-muted-foreground">Recipients</dt>
                    <dd class="font-semibold tabular-nums">
                        {{ counts.total }}
                    </dd>
                </div>
                <div>
                    <dt class="text-muted-foreground">Waiting</dt>
                    <dd class="font-semibold tabular-nums">
                        {{ counts.pending }}
                    </dd>
                </div>
                <div>
                    <dt class="text-muted-foreground">Queued</dt>
                    <dd class="font-semibold tabular-nums">
                        {{ counts.queued }}
                    </dd>
                </div>
                <div>
                    <dt class="text-muted-foreground">Sent</dt>
                    <dd class="font-semibold tabular-nums">
                        {{ counts.sent }}
                    </dd>
                </div>
                <div>
                    <dt class="text-muted-foreground">Delivered</dt>
                    <dd class="font-semibold tabular-nums">
                        {{ counts.delivered }}
                    </dd>
                </div>
                <div>
                    <dt class="text-muted-foreground">Failed</dt>
                    <dd class="font-semibold tabular-nums">
                        {{ counts.failed }}
                    </dd>
                </div>
                <div>
                    <dt class="text-muted-foreground">Skipped</dt>
                    <dd class="font-semibold tabular-nums">
                        {{ counts.skipped }}
                    </dd>
                </div>
                <div>
                    <dt class="text-muted-foreground">Cancelled</dt>
                    <dd class="font-semibold tabular-nums">
                        {{ counts.cancelled }}
                    </dd>
                </div>
            </dl>
            <div
                v-if="broadcast.exclusions.length > 0"
                class="text-xs text-muted-foreground"
            >
                Excluded when sending started:
                {{
                    broadcast.exclusions
                        .map((row) => `${row.label} (${row.count})`)
                        .join(', ')
                }}
            </div>
            <dl class="grid gap-2 text-xs text-muted-foreground sm:grid-cols-3">
                <div>
                    Created by {{ broadcast.created_by ?? '—' }} ·
                    {{ formatDateTime(broadcast.created_at) }}
                </div>
                <div>
                    Sent by {{ broadcast.sent_by ?? '—' }} ·
                    {{ formatDateTime(broadcast.send_requested_at) }}
                </div>
                <div v-if="broadcast.completed_at">
                    Completed {{ formatDateTime(broadcast.completed_at) }}
                </div>
                <div v-if="broadcast.cancelled_at">
                    Cancelled by {{ broadcast.cancelled_by ?? '—' }} ·
                    {{ formatDateTime(broadcast.cancelled_at) }}
                </div>
                <div v-if="broadcast.failed_at">
                    Stopped {{ formatDateTime(broadcast.failed_at) }}
                </div>
            </dl>
        </section>

        <section
            v-if="broadcast.status !== 'draft'"
            class="neo-surface overflow-hidden"
        >
            <div class="overflow-x-auto">
                <table class="w-full min-w-[760px] text-sm">
                    <thead class="border-b border-border bg-muted/40 text-left">
                        <tr>
                            <th class="px-4 py-3 font-medium">Contact</th>
                            <th class="px-4 py-3 font-medium">
                                {{ isEmail ? 'Email' : 'WhatsApp number' }}
                            </th>
                            <th class="px-4 py-3 font-medium">Status</th>
                            <th class="px-4 py-3 font-medium">Sent</th>
                            <th class="px-4 py-3 font-medium">Details</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="recipient in recipients.data"
                            :key="recipient.id"
                            class="border-b border-border/70 last:border-0"
                        >
                            <td class="px-4 py-3">
                                <Link
                                    :href="`/contacts/${recipient.contact_id}`"
                                    class="underline-offset-4 hover:underline"
                                >
                                    {{
                                        recipient.contact_name ??
                                        `#${recipient.contact_id}`
                                    }}
                                </Link>
                            </td>
                            <td class="px-4 py-3 text-muted-foreground">
                                {{ recipient.address ?? '—' }}
                            </td>
                            <td class="px-4 py-3">
                                <span
                                    class="inline-flex items-center rounded-full border border-border px-2 py-0.5 text-xs"
                                >
                                    {{ recipient.status_label }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-muted-foreground">
                                {{ formatDateTime(recipient.sent_at) }}
                            </td>
                            <td class="px-4 py-3 text-xs text-muted-foreground">
                                {{ recipient.failure_reason ?? '' }}
                            </td>
                        </tr>
                        <tr v-if="recipients.data.length === 0">
                            <td
                                colspan="5"
                                class="px-4 py-8 text-center text-muted-foreground"
                            >
                                No recipients.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div
                v-if="recipients.total > recipients.data.length"
                class="flex flex-wrap items-center justify-between gap-2 border-t border-border px-4 py-3 text-sm text-muted-foreground"
            >
                <p>
                    Showing {{ recipients.from }}–{{ recipients.to }} of
                    {{ recipients.total }}
                </p>
                <div class="flex flex-wrap gap-1">
                    <template
                        v-for="link in recipients.links"
                        :key="link.label"
                    >
                        <Link
                            v-if="link.url"
                            :href="link.url"
                            class="rounded-md border border-border px-2 py-1 hover:bg-muted"
                            :class="{
                                'bg-muted font-medium text-foreground':
                                    link.active,
                            }"
                            preserve-scroll
                            v-html="link.label"
                        />
                        <span
                            v-else
                            class="rounded-md px-2 py-1 opacity-40"
                            v-html="link.label"
                        />
                    </template>
                </div>
            </div>
        </section>
    </div>
</template>
