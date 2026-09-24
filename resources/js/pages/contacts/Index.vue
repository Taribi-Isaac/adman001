<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

type ContactRow = {
    id: number;
    display_name: string;
    type: string;
    type_label: string;
    status: string;
    status_label: string;
    email: string | null;
    phone: string | null;
    organization_name: string | null;
    is_archived: boolean;
};

type PaginatedContacts = {
    data: ContactRow[];
    links: Array<{ url: string | null; label: string; active: boolean }>;
    from: number | null;
    to: number | null;
    total: number;
};

const props = defineProps<{
    contacts: PaginatedContacts;
    filters: {
        search: string;
        status: string;
        type: string;
        archived: boolean;
    };
    statusOptions: Array<{ value: string; label: string }>;
    typeOptions: Array<{ value: string; label: string }>;
    canCreate: boolean;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Customers', href: '/contacts' }],
    },
});

const page = usePage();
const flashSuccess = computed(() => page.props.flash?.success);

const search = ref(props.filters.search);
const status = ref(props.filters.status);
const type = ref(props.filters.type);
const archived = ref(props.filters.archived);

watch(
    () => props.filters,
    (value) => {
        search.value = value.search;
        status.value = value.status;
        type.value = value.type;
        archived.value = value.archived;
    },
);

const applyFilters = () => {
    router.get(
        '/contacts',
        {
            search: search.value || undefined,
            status: status.value || undefined,
            type: type.value || undefined,
            archived: archived.value ? '1' : undefined,
        },
        { preserveState: true, replace: true },
    );
};

const statusClass = (value: string) => {
    switch (value) {
        case 'customer':
            return 'border-emerald-500/30 text-emerald-700 dark:text-emerald-300';
        case 'prospect':
            return 'border-amber-500/30 text-amber-700 dark:text-amber-300';
        default:
            return 'border-border text-muted-foreground';
    }
};
</script>

<template>
    <Head title="Customers" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <Heading
                title="Customers"
                description="Manage contacts through Unknown → Prospect → Customer."
            />
            <Button v-if="canCreate" as-child>
                <Link href="/contacts/create">Add contact</Link>
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
                        placeholder="Search name, email, phone…"
                    />
                </div>
                <div>
                    <label for="status" class="sr-only">Status</label>
                    <select
                        id="status"
                        v-model="status"
                        class="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm"
                    >
                        <option value="">All statuses</option>
                        <option
                            v-for="option in statusOptions"
                            :key="option.value"
                            :value="option.value"
                        >
                            {{ option.label }}
                        </option>
                    </select>
                </div>
                <div class="flex gap-2">
                    <select
                        id="type"
                        v-model="type"
                        class="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm"
                        aria-label="Contact type"
                    >
                        <option value="">All types</option>
                        <option
                            v-for="option in typeOptions"
                            :key="option.value"
                            :value="option.value"
                        >
                            {{ option.label }}
                        </option>
                    </select>
                    <Button type="submit" variant="secondary">Filter</Button>
                </div>
            </form>
            <label class="flex items-center gap-2 text-sm text-muted-foreground">
                <input v-model="archived" type="checkbox" class="size-4 rounded border-input" @change="applyFilters" />
                Show archived only
            </label>
        </section>

        <section class="neo-surface overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full min-w-[640px] text-sm">
                    <thead class="border-b border-border bg-muted/40 text-left">
                        <tr>
                            <th class="px-4 py-3 font-medium">Name</th>
                            <th class="px-4 py-3 font-medium">Status</th>
                            <th class="px-4 py-3 font-medium">Type</th>
                            <th class="px-4 py-3 font-medium">Email</th>
                            <th class="px-4 py-3 font-medium">Phone</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="contact in contacts.data"
                            :key="contact.id"
                            class="border-b border-border/70 last:border-0 hover:bg-muted/30"
                        >
                            <td class="px-4 py-3">
                                <Link
                                    :href="`/contacts/${contact.id}`"
                                    class="font-medium text-foreground underline-offset-4 hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                >
                                    {{ contact.display_name }}
                                </Link>
                            </td>
                            <td class="px-4 py-3">
                                <span
                                    class="inline-flex items-center rounded-full border px-2 py-0.5 text-xs"
                                    :class="statusClass(contact.status)"
                                >
                                    {{ contact.status_label }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-muted-foreground">
                                {{ contact.type_label }}
                            </td>
                            <td class="px-4 py-3 text-muted-foreground">
                                {{ contact.email || '—' }}
                            </td>
                            <td class="px-4 py-3 text-muted-foreground">
                                {{ contact.phone || '—' }}
                            </td>
                        </tr>
                        <tr v-if="contacts.data.length === 0">
                            <td
                                colspan="5"
                                class="px-4 py-10 text-center text-muted-foreground"
                            >
                                <p class="font-medium text-foreground">No contacts yet</p>
                                <p class="mt-1 text-sm">
                                    Add a contact when someone reaches out or when you start a
                                    customer relationship.
                                </p>
                                <Button v-if="canCreate" class="mt-4" as-child>
                                    <Link href="/contacts/create">Add contact</Link>
                                </Button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div
                v-if="contacts.total > 0"
                class="flex flex-wrap items-center justify-between gap-2 border-t border-border px-4 py-3 text-sm text-muted-foreground"
            >
                <p>
                    Showing {{ contacts.from }}–{{ contacts.to }} of
                    {{ contacts.total }}
                </p>
                <div class="flex flex-wrap gap-1">
                    <template v-for="link in contacts.links" :key="link.label">
                        <Link
                            v-if="link.url"
                            :href="link.url"
                            class="rounded-md border border-border px-2 py-1 hover:bg-muted"
                            :class="{ 'bg-muted font-medium text-foreground': link.active }"
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
