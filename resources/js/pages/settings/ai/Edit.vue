<script setup lang="ts">
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';

const props = defineProps<{
    enabled: boolean;
    customerResponsesEnabled: boolean;
    providerConfigured: boolean;
    provider: string;
    model: string;
    canManage: boolean;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Settings', href: '/settings/ai' },
            { title: 'AI', href: '/settings/ai' },
        ],
    },
});

const page = usePage();
const flashSuccess = computed(() => page.props.flash?.success);

const form = useForm({
    enabled: Boolean(props.enabled),
    customer_responses_enabled: Boolean(props.customerResponsesEnabled),
});

const submit = () => {
    form.put('/settings/ai', { preserveScroll: true });
};
</script>

<template>
    <Head title="AI settings" />

    <div class="space-y-8">
        <Heading
            variant="small"
            title="AI assistant"
            description="Controlled AI for WhatsApp conversations. Laravel remains the source of truth for invoices, payments, and authorization."
        />

        <p
            v-if="flashSuccess"
            class="rounded-lg border border-border bg-muted/40 px-3 py-2 text-sm"
            role="status"
        >
            {{ flashSuccess }}
        </p>

        <section class="neo-surface space-y-3 p-5">
            <h2 class="text-sm font-semibold">Provider</h2>
            <p class="text-sm text-muted-foreground">
                Provider: {{ provider }} · Model: {{ model }}
            </p>
            <p class="text-sm" :class="providerConfigured ? 'text-muted-foreground' : 'text-destructive'">
                {{
                    providerConfigured
                        ? 'API credentials appear configured (or fake provider is selected).'
                        : 'API key is not configured. Set ADMAN_AI_API_KEY (or ADMAN_AI_PROVIDER=fake for local/testing).'
                }}
            </p>
        </section>

        <form class="space-y-6" @submit.prevent="submit">
            <section class="neo-surface space-y-4 p-5">
                <h2 class="text-sm font-semibold">Feature switches</h2>
                <label class="flex items-center gap-2 text-sm">
                    <input
                        v-model="form.enabled"
                        type="checkbox"
                        class="rounded border-border"
                        :disabled="!canManage"
                    />
                    AI enabled
                </label>
                <InputError :message="form.errors.enabled" />

                <label class="flex items-center gap-2 text-sm">
                    <input
                        v-model="form.customer_responses_enabled"
                        type="checkbox"
                        class="rounded border-border"
                        :disabled="!canManage || !form.enabled"
                    />
                    AI may respond to customers (WhatsApp, AI-mode conversations)
                </label>
                <InputError :message="form.errors.customer_responses_enabled" />

                <p class="text-xs text-muted-foreground">
                    When customer responses are enabled, new inbound WhatsApp conversations open in AI
                    mode. Staff takeover pauses AI. Returning to AI requires an authorized staff action.
                </p>
            </section>

            <div v-if="canManage">
                <Button type="submit" :disabled="form.processing">Save AI settings</Button>
            </div>
        </form>
    </div>
</template>
