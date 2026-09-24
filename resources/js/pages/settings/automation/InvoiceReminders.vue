<script setup lang="ts">
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type RuleRow = {
    id: number | null;
    offset_days: number;
    is_enabled: boolean;
    label?: string;
};

const props = defineProps<{
    enabled: boolean;
    timezone: string;
    rules: RuleRow[];
    offsetMin: number;
    offsetMax: number;
    canManage: boolean;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Settings', href: '/settings/automation' },
            { title: 'Invoice Reminders', href: '/settings/automation' },
        ],
    },
});

const page = usePage();
const flashSuccess = computed(() => page.props.flash?.success);

const form = useForm({
    enabled: Boolean(props.enabled),
    rules: props.rules.map((rule) => ({
        id: rule.id,
        offset_days: rule.offset_days,
        is_enabled: Boolean(rule.is_enabled),
    })),
});

const addRule = () => {
    form.rules.push({
        id: null,
        offset_days: -1,
        is_enabled: true,
    });
};

const removeRule = (index: number) => {
    form.rules.splice(index, 1);
};

const submit = () => {
    form.put('/settings/automation', { preserveScroll: true });
};

const offsetLabel = (offset: number) => {
    if (offset === 0) {
        return 'On due date';
    }
    if (offset < 0) {
        const days = Math.abs(offset);
        return `${days} day${days === 1 ? '' : 's'} before due`;
    }
    return `${offset} day${offset === 1 ? '' : 's'} after due`;
};
</script>

<template>
    <Head title="Invoice reminders" />

    <div class="space-y-8">
        <Heading
            variant="small"
            title="Invoice reminders"
            description="Business-wide reminder offsets relative to each invoice due date. Uses the business timezone. Delivery uses existing Email and WhatsApp channels."
        />

        <p
            v-if="flashSuccess"
            class="rounded-lg border border-border bg-muted/40 px-3 py-2 text-sm"
            role="status"
        >
            {{ flashSuccess }}
        </p>

        <form class="space-y-6" @submit.prevent="submit">
            <section class="neo-surface space-y-4 p-5">
                <h2 class="text-sm font-semibold">Feature</h2>
                <p class="text-xs text-muted-foreground">Business timezone: {{ timezone }}</p>
                <label class="flex items-center gap-2 text-sm">
                    <input
                        v-model="form.enabled"
                        type="checkbox"
                        class="rounded border-border"
                        :disabled="!canManage"
                    />
                    Invoice reminders enabled
                </label>
                <InputError :message="form.errors.enabled" />
            </section>

            <section class="neo-surface space-y-4 p-5">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <h2 class="text-sm font-semibold">Reminder rules</h2>
                    <Button
                        v-if="canManage"
                        type="button"
                        variant="secondary"
                        @click="addRule"
                    >
                        Add rule
                    </Button>
                </div>
                <p class="text-xs text-muted-foreground">
                    Negative offsets are before the due date; positive offsets are after. Duplicate
                    offsets are not allowed. Defaults: -7, -2, +1.
                </p>
                <InputError :message="form.errors.rules" />

                <div
                    v-for="(rule, index) in form.rules"
                    :key="rule.id ?? `new-${index}`"
                    class="grid gap-3 border-b border-border/60 py-3 last:border-0 sm:grid-cols-[140px_1fr_auto_auto]"
                >
                    <div class="space-y-1">
                        <Label :for="`offset-${index}`">Offset (days)</Label>
                        <Input
                            :id="`offset-${index}`"
                            v-model.number="rule.offset_days"
                            type="number"
                            :min="offsetMin"
                            :max="offsetMax"
                            :disabled="!canManage"
                            required
                        />
                        <InputError :message="form.errors[`rules.${index}.offset_days`]" />
                    </div>
                    <div class="flex items-end pb-2 text-sm text-muted-foreground">
                        {{ offsetLabel(Number(rule.offset_days) || 0) }}
                    </div>
                    <label class="flex items-center gap-2 self-end pb-2 text-sm">
                        <input
                            v-model="rule.is_enabled"
                            type="checkbox"
                            class="rounded border-border"
                            :disabled="!canManage"
                        />
                        Enabled
                    </label>
                    <div class="self-end pb-1">
                        <Button
                            v-if="canManage && form.rules.length > 1"
                            type="button"
                            variant="secondary"
                            @click="removeRule(index)"
                        >
                            Remove
                        </Button>
                    </div>
                </div>
            </section>

            <div v-if="canManage">
                <Button type="submit" :disabled="form.processing">Save reminder settings</Button>
            </div>
        </form>
    </div>
</template>
