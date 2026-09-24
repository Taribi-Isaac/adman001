<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

type DocumentRow = {
    id: number;
    type_label: string;
    filename: string;
    byte_size: number | null;
    generated_at: string | null;
    generator: { id: number; name: string } | null;
    has_active_link: boolean;
    access_expires_at: string | null;
    source_label: string;
    source_url: string | null;
};

type PaginatedDocuments = {
    data: DocumentRow[];
    links: Array<{ url: string | null; label: string; active: boolean }>;
    from: number | null;
    to: number | null;
    total: number;
};

const props = defineProps<{
    documents: PaginatedDocuments;
    filters: { search: string };
    permissions: {
        revoke_link: boolean;
        generate: boolean;
    };
    flashSecureUrl?: string | null;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Documents', href: '/documents' }],
    },
});

const page = usePage();
const flashSuccess = computed(() => page.props.flash?.success as string | undefined);
const secureUrl = computed(
    () =>
        props.flashSecureUrl ||
        (page.props.flash?.secure_url as string | undefined) ||
        null,
);
const copied = ref(false);
const search = ref(props.filters.search);

watch(
    () => props.filters,
    (value) => {
        search.value = value.search;
    },
);

const applyFilters = () => {
    router.get(
        '/documents',
        { search: search.value || undefined },
        { preserveState: true, replace: true },
    );
};

const postAction = (path: string, confirmMessage?: string) => {
    if (confirmMessage && !confirm(confirmMessage)) {
        return;
    }
    router.post(path, {}, { preserveScroll: true });
};

const copySecureUrl = async () => {
    if (!secureUrl.value) {
        return;
    }
    await navigator.clipboard.writeText(secureUrl.value);
    copied.value = true;
    setTimeout(() => {
        copied.value = false;
    }, 2000);
};

const formatDate = (value: string | null) =>
    value ? new Date(value).toLocaleString() : '—';
</script>

<template>
    <Head title="Documents" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
        <Heading
            title="Documents"
            description="Generated PDFs and secure share links for staff."
        />

        <p
            v-if="flashSuccess"
            class="rounded-lg border border-border bg-muted/40 px-3 py-2 text-sm"
            role="status"
        >
            {{ flashSuccess }}
        </p>

        <section v-if="secureUrl" class="neo-surface space-y-3 border-sky-500/30 p-5">
            <h2 class="text-sm font-semibold">Secure share link (not a customer portal)</h2>
            <p class="text-sm text-muted-foreground">
                Copy this URL now. The plain token is shown once and is not stored.
            </p>
            <div class="flex flex-wrap gap-2">
                <code class="flex-1 break-all rounded-md border border-border bg-muted/40 px-3 py-2 text-xs">
                    {{ secureUrl }}
                </code>
                <Button type="button" variant="secondary" @click="copySecureUrl">
                    {{ copied ? 'Copied' : 'Copy URL' }}
                </Button>
            </div>
        </section>

        <section class="neo-surface space-y-4 p-4">
            <form class="flex flex-wrap gap-3" @submit.prevent="applyFilters">
                <div class="min-w-[220px] flex-1">
                    <label for="search" class="sr-only">Search</label>
                    <Input
                        id="search"
                        v-model="search"
                        placeholder="Search filename or number…"
                    />
                </div>
                <Button type="submit" variant="secondary">Filter</Button>
            </form>
        </section>

        <section class="neo-surface overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full min-w-[800px] text-sm">
                    <thead class="border-b border-border bg-muted/40 text-left">
                        <tr>
                            <th class="px-4 py-3 font-medium">Document</th>
                            <th class="px-4 py-3 font-medium">Source</th>
                            <th class="px-4 py-3 font-medium">Link</th>
                            <th class="px-4 py-3 font-medium">Generated</th>
                            <th class="px-4 py-3 font-medium">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="doc in documents.data"
                            :key="doc.id"
                            class="border-b border-border/70 last:border-0 hover:bg-muted/30"
                        >
                            <td class="px-4 py-3">
                                <p class="font-medium">{{ doc.filename }}</p>
                                <p class="text-xs text-muted-foreground">{{ doc.type_label }}</p>
                            </td>
                            <td class="px-4 py-3">
                                <Link
                                    v-if="doc.source_url"
                                    :href="doc.source_url"
                                    class="underline-offset-4 hover:underline"
                                >
                                    {{ doc.source_label }}
                                </Link>
                                <span v-else>{{ doc.source_label }}</span>
                            </td>
                            <td class="px-4 py-3 text-muted-foreground">
                                {{ doc.has_active_link ? 'Active' : 'None / revoked' }}
                            </td>
                            <td class="px-4 py-3 text-muted-foreground">
                                {{ formatDate(doc.generated_at) }}
                                <span v-if="doc.generator" class="block text-xs">
                                    by {{ doc.generator.name }}
                                </span>
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex flex-wrap gap-2">
                                    <Button variant="secondary" size="sm" as-child>
                                        <a :href="`/documents/${doc.id}/download`" target="_blank">
                                            Download
                                        </a>
                                    </Button>
                                    <Button
                                        v-if="permissions.generate"
                                        type="button"
                                        variant="secondary"
                                        size="sm"
                                        @click="postAction(`/documents/${doc.id}/create-link`)"
                                    >
                                        Create link
                                    </Button>
                                    <Button
                                        v-if="permissions.revoke_link && doc.has_active_link"
                                        type="button"
                                        variant="secondary"
                                        size="sm"
                                        @click="
                                            postAction(
                                                `/documents/${doc.id}/revoke-link`,
                                                'Revoke the secure share link?',
                                            )
                                        "
                                    >
                                        Revoke
                                    </Button>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="documents.data.length === 0">
                            <td colspan="5" class="px-4 py-10 text-center text-muted-foreground">
                                <p class="font-medium text-foreground">No documents yet</p>
                                <p class="mt-1 text-sm">
                                    Generate a PDF from an issued quote or invoice.
                                </p>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div
                v-if="documents.total > 0"
                class="flex flex-wrap items-center justify-between gap-2 border-t border-border px-4 py-3 text-sm text-muted-foreground"
            >
                <p>Showing {{ documents.from }}–{{ documents.to }} of {{ documents.total }}</p>
                <div class="flex flex-wrap gap-1">
                    <template v-for="link in documents.links" :key="link.label">
                        <Link
                            v-if="link.url"
                            :href="link.url"
                            class="rounded-md border border-border px-2 py-1 hover:bg-muted"
                            :class="{ 'bg-muted font-medium text-foreground': link.active }"
                            preserve-scroll
                            v-html="link.label"
                        />
                        <span v-else class="rounded-md px-2 py-1 opacity-40" v-html="link.label" />
                    </template>
                </div>
            </div>
        </section>
    </div>
</template>
