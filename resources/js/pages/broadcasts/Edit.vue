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
            { title: 'Edit draft', href: '#' },
        ],
    },
});
</script>

<template>
    <Head title="Edit broadcast" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
        <Heading
            title="Edit broadcast draft"
            description="Changes keep the broadcast as a draft. Review and confirm again before sending."
        />

        <BroadcastForm v-bind="$props" />
    </div>
</template>
