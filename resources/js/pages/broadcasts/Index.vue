<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';

type BroadcastRow = {
    id: number;
    name: string;
    channel: string;
    channel_label: string;
    audience_label: string;
    status: string;
    status_label: string;
    recipient_count: number;
    sent_count: number;
    failed_count: number;
    created_at: string | null;
};

type PaginatedBroadcasts = {
    data: BroadcastRow[];
    links: Array<{ url: string | null; label: string; active: boolean }>;
    from: number | null;
    to: number | null;
    total: number;
};

defineProps<{
    broadcasts: PaginatedBroadcasts;
    broadcastsEnabled: boolean;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Broadcasts', href: '/broadcasts' }],
    },
});

const page = usePage();
const flashSuccess = computed(() => page.props.flash?.success);

const formatDate = (iso: string | null) =>
    iso
        ? new Date(iso).toLocaleDateString(undefined, { dateStyle: 'medium' })
        : '—';
</script>

<template>
    <Head title="Broadcasts" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <Heading
                title="Broadcasts"
                description="One-off messages to customers who agreed to receive them. Nothing is sent until an authorised person reviews and confirms."
            />
            <Button as-child>
                <Link href="/broadcasts/create">New broadcast</Link>
            </Button>
        </div>

        <p
            v-if="flashSuccess"
            class="rounded-lg border border-border bg-muted/40 px-3 py-2 text-sm"
            role="status"
        >
            {{ flashSuccess }}
        </p>

        <p
            v-if="!broadcastsEnabled"
            class="rounded-lg border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-900 dark:border-amber-700 dark:bg-amber-950/40 dark:text-amber-200"
        >
            Broadcasts are turned off in Business settings. You can prepare
            drafts, but nothing can be sent until they are turned on.
        </p>

        <section class="neo-surface overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full min-w-[820px] text-sm">
                    <thead class="border-b border-border bg-muted/40 text-left">
                        <tr>
                            <th class="px-4 py-3 font-medium">Name</th>
                            <th class="px-4 py-3 font-medium">Channel</th>
                            <th class="px-4 py-3 font-medium">Audience</th>
                            <th class="px-4 py-3 font-medium">Status</th>
                            <th class="px-4 py-3 text-right font-medium">
                                Recipients
                            </th>
                            <th class="px-4 py-3 text-right font-medium">
                                Sent
                            </th>
                            <th class="px-4 py-3 text-right font-medium">
                                Failed
                            </th>
                            <th class="px-4 py-3 font-medium">Created</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="broadcast in broadcasts.data"
                            :key="broadcast.id"
                            class="border-b border-border/70 last:border-0 hover:bg-muted/30"
                        >
                            <td class="px-4 py-3">
                                <Link
                                    :href="`/broadcasts/${broadcast.id}`"
                                    class="font-medium underline-offset-4 hover:underline"
                                >
                                    {{ broadcast.name }}
                                </Link>
                            </td>
                            <td class="px-4 py-3 text-muted-foreground">
                                {{ broadcast.channel_label }}
                            </td>
                            <td class="px-4 py-3 text-muted-foreground">
                                {{ broadcast.audience_label }}
                            </td>
                            <td class="px-4 py-3">
                                <span
                                    class="inline-flex items-center rounded-full border border-border px-2 py-0.5 text-xs"
                                >
                                    {{ broadcast.status_label }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-right tabular-nums">
                                {{
                                    broadcast.status === 'draft'
                                        ? '—'
                                        : broadcast.recipient_count
                                }}
                            </td>
                            <td class="px-4 py-3 text-right tabular-nums">
                                {{ broadcast.sent_count }}
                            </td>
                            <td class="px-4 py-3 text-right tabular-nums">
                                {{ broadcast.failed_count }}
                            </td>
                            <td class="px-4 py-3 text-muted-foreground">
                                {{ formatDate(broadcast.created_at) }}
                            </td>
                        </tr>
                        <tr v-if="broadcasts.data.length === 0">
                            <td
                                colspan="8"
                                class="px-4 py-10 text-center text-muted-foreground"
                            >
                                <p class="font-medium text-foreground">
                                    No broadcasts yet
                                </p>
                                <p class="mt-1 text-sm">
                                    Create a draft to see who would receive it.
                                </p>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div
                v-if="broadcasts.total > 0"
                class="flex flex-wrap items-center justify-between gap-2 border-t border-border px-4 py-3 text-sm text-muted-foreground"
            >
                <p>
                    Showing {{ broadcasts.from }}–{{ broadcasts.to }} of
                    {{ broadcasts.total }}
                </p>
                <div class="flex flex-wrap gap-1">
                    <template
                        v-for="link in broadcasts.links"
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
