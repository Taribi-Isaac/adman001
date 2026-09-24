<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import { dashboard } from '@/routes';

type Metric = {
    key: string;
    label: string;
    count: number;
    hint: string;
    href: string;
    empty: string;
    visible: boolean;
};

type ActivityItem = {
    id: number;
    event: string;
    description: string | null;
    actor_name: string | null;
    created_at: string | null;
};

const props = defineProps<{
    greeting: {
        user_name: string;
        business_name: string;
    };
    metrics: Metric[];
    recent_activity: {
        items: ActivityItem[];
        visible: boolean;
        href: string;
    };
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Dashboard',
                href: dashboard(),
            },
        ],
    },
});

const visibleMetrics = computed(() => props.metrics.filter((metric) => metric.visible));

const formatWhen = (iso: string | null): string => {
    if (!iso) {
        return '';
    }

    try {
        return new Date(iso).toLocaleString(undefined, {
            dateStyle: 'medium',
            timeStyle: 'short',
        });
    } catch {
        return iso;
    }
};
</script>

<template>
    <Head title="Dashboard" />

    <div class="flex h-full flex-1 flex-col gap-6 overflow-x-auto p-4 md:p-6">
        <div class="neo-surface p-6 md:p-8">
            <p class="text-sm text-muted-foreground">Operations</p>
            <h1 class="mt-2 text-2xl font-semibold tracking-tight">
                What needs attention today
            </h1>
            <p class="mt-3 max-w-2xl text-sm leading-relaxed text-muted-foreground">
                Welcome, {{ greeting.user_name }}. Here is a concise view of work waiting on
                {{ greeting.business_name }}.
            </p>
        </div>

        <div
            v-if="visibleMetrics.length > 0"
            class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3"
        >
            <Link
                v-for="metric in visibleMetrics"
                :key="metric.key"
                :href="metric.href"
                class="neo-surface block p-5 transition hover:bg-muted/30 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
            >
                <p class="text-sm font-semibold">{{ metric.label }}</p>
                <p class="mt-3 text-3xl font-semibold tracking-tight tabular-nums">
                    {{ metric.count }}
                </p>
                <p class="mt-2 text-sm text-muted-foreground">
                    {{ metric.count === 0 ? metric.empty : metric.hint }}
                </p>
            </Link>
        </div>

        <div
            v-else
            class="neo-surface p-5 text-sm text-muted-foreground"
        >
            No operational metrics are available for your permissions.
        </div>

        <div v-if="recent_activity.visible" class="neo-surface p-5 md:p-6">
            <div class="flex items-center justify-between gap-3">
                <div>
                    <h2 class="text-sm font-semibold">Recent activity</h2>
                    <p class="mt-1 text-sm text-muted-foreground">
                        Latest audited actions across the business.
                    </p>
                </div>
                <Link
                    :href="recent_activity.href"
                    class="text-sm font-medium text-foreground underline-offset-4 hover:underline"
                >
                    View audit log
                </Link>
            </div>

            <ul v-if="recent_activity.items.length > 0" class="mt-4 divide-y divide-border">
                <li
                    v-for="item in recent_activity.items"
                    :key="item.id"
                    class="flex flex-col gap-1 py-3 sm:flex-row sm:items-start sm:justify-between"
                >
                    <div>
                        <p class="text-sm font-medium">
                            {{ item.description || item.event }}
                        </p>
                        <p class="text-xs text-muted-foreground">
                            {{ item.event }}
                            <span v-if="item.actor_name"> · {{ item.actor_name }}</span>
                        </p>
                    </div>
                    <p class="shrink-0 text-xs text-muted-foreground">
                        {{ formatWhen(item.created_at) }}
                    </p>
                </li>
            </ul>
            <p v-else class="mt-4 text-sm text-muted-foreground">
                No recent activity recorded yet.
            </p>
        </div>
    </div>
</template>
