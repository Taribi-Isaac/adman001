<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';

type AuditRow = {
    id: number;
    event: string;
    description: string | null;
    actor: { id: number; name: string; email: string } | null;
    auditable_type: string | null;
    auditable_id: number | null;
    created_at: string | null;
};

defineProps<{
    events: AuditRow[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Settings', href: '/settings/audit' },
            { title: 'Audit', href: '/settings/audit' },
        ],
    },
});
</script>

<template>
    <Head title="Audit" />

    <div class="space-y-6">
        <Heading
            variant="small"
            title="Audit"
            description="Recent security and configuration events. Domain events will appear here as features are added."
        />

        <section class="neo-surface overflow-hidden">
            <table class="w-full text-sm">
                <thead class="border-b border-border bg-muted/40 text-left">
                    <tr>
                        <th class="px-4 py-3 font-medium">When</th>
                        <th class="px-4 py-3 font-medium">Event</th>
                        <th class="px-4 py-3 font-medium">Actor</th>
                        <th class="px-4 py-3 font-medium">Details</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="event in events"
                        :key="event.id"
                        class="border-b border-border/70 last:border-0"
                    >
                        <td class="px-4 py-3 whitespace-nowrap text-muted-foreground">
                            {{
                                event.created_at
                                    ? new Date(event.created_at).toLocaleString()
                                    : '—'
                            }}
                        </td>
                        <td class="px-4 py-3 font-medium">{{ event.event }}</td>
                        <td class="px-4 py-3">
                            {{ event.actor?.name ?? 'System' }}
                        </td>
                        <td class="px-4 py-3 text-muted-foreground">
                            {{ event.description ?? '—' }}
                        </td>
                    </tr>
                    <tr v-if="events.length === 0">
                        <td
                            colspan="4"
                            class="px-4 py-8 text-center text-muted-foreground"
                        >
                            No audit events yet.
                        </td>
                    </tr>
                </tbody>
            </table>
        </section>
    </div>
</template>
