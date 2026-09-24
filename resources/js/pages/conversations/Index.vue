<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

type ConversationRow = {
    id: number;
    channel: string;
    channel_label: string;
    mode: string;
    mode_label: string;
    subject: string | null;
    last_message_at: string | null;
    is_closed: boolean;
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
    assigned_user: { id: number; name: string } | null;
};

type PaginatedConversations = {
    data: ConversationRow[];
    links: Array<{ url: string | null; label: string; active: boolean }>;
    from: number | null;
    to: number | null;
    total: number;
};

const props = defineProps<{
    conversations: PaginatedConversations;
    filters: {
        search: string;
        mode: string;
        channel: string;
    };
    modeOptions: Array<{ value: string; label: string }>;
    channelOptions: Array<{ value: string; label: string }>;
    canManage: boolean;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Conversations', href: '/conversations' }],
    },
});

const page = usePage();
const flashSuccess = computed(() => page.props.flash?.success);

const search = ref(props.filters.search);
const mode = ref(props.filters.mode);
const channel = ref(props.filters.channel);

watch(
    () => props.filters,
    (value) => {
        search.value = value.search;
        mode.value = value.mode;
        channel.value = value.channel;
    },
);

const applyFilters = () => {
    router.get(
        '/conversations',
        {
            search: search.value || undefined,
            mode: mode.value || undefined,
            channel: channel.value || undefined,
        },
        { preserveState: true, replace: true },
    );
};

const modeClass = (value: string) => {
    switch (value) {
        case 'human':
            return 'border-sky-500/30 text-sky-800 dark:text-sky-300';
        case 'closed':
            return 'border-border text-muted-foreground';
        default:
            return 'border-violet-500/30 text-violet-800 dark:text-violet-300';
    }
};

const formatDate = (value: string | null) =>
    value ? new Date(value).toLocaleString() : '—';
</script>

<template>
    <Head title="Conversations" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <Heading
                title="Conversations"
                description="Provider-neutral communication threads with AI, Human, and Closed control."
            />
            <Button v-if="canManage" as-child>
                <Link href="/conversations/create">New conversation</Link>
            </Button>
        </div>

        <p
            v-if="flashSuccess"
            class="rounded-lg border border-border bg-muted/40 px-3 py-2 text-sm"
            role="status"
        >
            {{ flashSuccess }}
        </p>

        <section class="neo-surface space-y-4 p-4">
            <form
                class="grid gap-3 md:grid-cols-4"
                @submit.prevent="applyFilters"
            >
                <div class="md:col-span-2">
                    <label for="search" class="sr-only">Search</label>
                    <Input
                        id="search"
                        v-model="search"
                        type="search"
                        placeholder="Search name, contact, or identifier"
                    />
                </div>
                <div>
                    <label for="mode" class="sr-only">Mode</label>
                    <select
                        id="mode"
                        v-model="mode"
                        class="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm"
                    >
                        <option value="">All modes</option>
                        <option
                            v-for="option in modeOptions"
                            :key="option.value"
                            :value="option.value"
                        >
                            {{ option.label }}
                        </option>
                    </select>
                </div>
                <div class="flex gap-2">
                    <select
                        id="channel"
                        v-model="channel"
                        class="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm"
                        aria-label="Channel"
                    >
                        <option value="">All channels</option>
                        <option
                            v-for="option in channelOptions"
                            :key="option.value"
                            :value="option.value"
                        >
                            {{ option.label }}
                        </option>
                    </select>
                    <Button type="submit" variant="secondary">Filter</Button>
                </div>
            </form>

            <div class="overflow-x-auto">
                <table class="w-full min-w-[720px] text-left text-sm">
                    <thead class="border-b border-border text-muted-foreground">
                        <tr>
                            <th class="px-2 py-2 font-medium">Conversation</th>
                            <th class="px-2 py-2 font-medium">Channel</th>
                            <th class="px-2 py-2 font-medium">Mode</th>
                            <th class="px-2 py-2 font-medium">Contact</th>
                            <th class="px-2 py-2 font-medium">Last activity</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="conversation in conversations.data"
                            :key="conversation.id"
                            class="border-b border-border/70 hover:bg-muted/30"
                        >
                            <td class="px-2 py-3">
                                <Link
                                    :href="`/conversations/${conversation.id}`"
                                    class="font-medium text-foreground underline-offset-4 hover:underline"
                                >
                                    {{
                                        conversation.identity.display_name ||
                                        conversation.subject ||
                                        `Conversation #${conversation.id}`
                                    }}
                                </Link>
                                <p class="mt-0.5 text-xs text-muted-foreground">
                                    {{ conversation.identity.external_id }}
                                </p>
                            </td>
                            <td class="px-2 py-3">
                                {{ conversation.channel_label }}
                            </td>
                            <td class="px-2 py-3">
                                <span
                                    class="inline-flex items-center rounded-full border px-2.5 py-0.5 text-xs font-medium"
                                    :class="modeClass(conversation.mode)"
                                >
                                    {{ conversation.mode_label }}
                                </span>
                            </td>
                            <td class="px-2 py-3">
                                <template v-if="conversation.contact">
                                    <span>{{ conversation.contact.display_name }}</span>
                                    <span class="block text-xs text-muted-foreground">
                                        {{ conversation.contact.status_label }}
                                    </span>
                                </template>
                                <span
                                    v-else
                                    class="inline-flex items-center rounded-full border border-border px-2 py-0.5 text-xs text-muted-foreground"
                                >
                                    Unknown contact
                                </span>
                            </td>
                            <td class="px-2 py-3 text-muted-foreground">
                                {{ formatDate(conversation.last_message_at) }}
                            </td>
                        </tr>
                        <tr v-if="conversations.data.length === 0">
                            <td
                                colspan="5"
                                class="px-2 py-8 text-center text-muted-foreground"
                            >
                                No conversations match these filters.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div
                v-if="conversations.total > 0"
                class="flex flex-wrap items-center justify-between gap-2 text-sm text-muted-foreground"
            >
                <p>
                    Showing {{ conversations.from }}–{{ conversations.to }} of
                    {{ conversations.total }}
                </p>
                <div class="flex flex-wrap gap-1">
                    <template
                        v-for="(link, index) in conversations.links"
                        :key="index"
                    >
                        <Button
                            v-if="link.url"
                            as-child
                            size="sm"
                            :variant="link.active ? 'default' : 'secondary'"
                        >
                            <Link
                                :href="link.url"
                                v-html="link.label"
                                preserve-scroll
                            />
                        </Button>
                        <span
                            v-else
                            class="inline-flex h-8 items-center px-2 text-xs"
                            v-html="link.label"
                        />
                    </template>
                </div>
            </div>
        </section>
    </div>
</template>
