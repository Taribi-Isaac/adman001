<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';

defineProps<{
    section: {
        title: string;
        description: string;
    };
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Settings', href: '/settings/profile' }],
    },
});
</script>

<template>
    <Head :title="section.title" />

    <div class="space-y-6">
        <Heading
            variant="small"
            :title="section.title"
            :description="section.description"
        />

        <div class="neo-surface empty-state p-8 text-center">
            <p class="text-sm font-medium">Not available yet</p>
            <p class="mt-2 text-sm text-muted-foreground">
                This settings area is reserved for a later implementation task.
                No configuration is active here.
            </p>
        </div>
    </div>
</template>
