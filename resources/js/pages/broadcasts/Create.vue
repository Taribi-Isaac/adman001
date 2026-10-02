<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import BroadcastForm from '@/components/broadcasts/BroadcastForm.vue';
import Heading from '@/components/Heading.vue';
import type { BroadcastFormProps } from '@/types/broadcasts';

defineProps<BroadcastFormProps>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Broadcasts', href: '/broadcasts' },
            { title: 'New broadcast', href: '/broadcasts/create' },
        ],
    },
});
</script>

<template>
    <Head title="New broadcast" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
        <Heading
            title="New broadcast"
            description="Saving creates a draft only. You will review the audience and confirm before anything is sent."
        />

        <BroadcastForm v-bind="$props" />
    </div>
</template>
