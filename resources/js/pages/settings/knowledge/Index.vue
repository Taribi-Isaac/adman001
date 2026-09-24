<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';

type Offering = {
    id: number;
    name: string;
    description: string | null;
    is_active: boolean;
    sort_order: number;
};

type Article = {
    id: number;
    title: string;
    content: string;
    category: string;
    category_label: string;
    is_active: boolean;
    sort_order: number;
};

const props = defineProps<{
    business: {
        description: string | null;
        ai_support_instructions: string | null;
    };
    offerings: Offering[];
    articles: Article[];
    categoryOptions: Array<{ value: string; label: string }>;
    canManage: boolean;
    canUpdateBusiness: boolean;
    businessSettingsHref: string;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Settings', href: '/settings/business' },
            { title: 'Knowledge', href: '/settings/knowledge' },
        ],
    },
});

const offeringForm = useForm({
    name: '',
    description: '',
    is_active: true,
    sort_order: 0,
});

const articleForm = useForm({
    title: '',
    content: '',
    category: 'faq',
    is_active: true,
    sort_order: 0,
});

const submitOffering = () => {
    offeringForm.post('/settings/knowledge/offerings', {
        preserveScroll: true,
        onSuccess: () => offeringForm.reset('name', 'description'),
    });
};

const submitArticle = () => {
    articleForm.post('/settings/knowledge/articles', {
        preserveScroll: true,
        onSuccess: () => articleForm.reset('title', 'content'),
    });
};

const removeOffering = (id: number) => {
    router.delete(`/settings/knowledge/offerings/${id}`, { preserveScroll: true });
};

const removeArticle = (id: number) => {
    router.delete(`/settings/knowledge/articles/${id}`, { preserveScroll: true });
};

const toggleOffering = (offering: Offering) => {
    router.put(
        `/settings/knowledge/offerings/${offering.id}`,
        {
            name: offering.name,
            description: offering.description,
            is_active: !offering.is_active,
            sort_order: offering.sort_order,
        },
        { preserveScroll: true },
    );
};

const toggleArticle = (article: Article) => {
    router.put(
        `/settings/knowledge/articles/${article.id}`,
        {
            title: article.title,
            content: article.content,
            category: article.category,
            is_active: !article.is_active,
            sort_order: article.sort_order,
        },
        { preserveScroll: true },
    );
};
</script>

<template>
    <Head title="Business knowledge" />

    <div class="space-y-8">
        <Heading
            variant="small"
            title="Business knowledge"
            description="Editable facts the AI may use for general customer support. Customer invoices, balances, and payments still come only from controlled Laravel tools."
        />

        <section class="neo-surface space-y-3 p-5">
            <h2 class="text-sm font-semibold">Business profile used by AI</h2>
            <p class="text-sm text-muted-foreground">
                Description and support instructions are edited in Business Settings.
            </p>
            <p class="text-sm whitespace-pre-wrap">
                {{ business.description || 'No business description set yet.' }}
            </p>
            <p class="text-sm whitespace-pre-wrap text-muted-foreground">
                {{
                    business.ai_support_instructions ||
                    'No AI support instructions set yet.'
                }}
            </p>
            <p v-if="canUpdateBusiness" class="text-sm">
                <Link :href="businessSettingsHref" class="underline-offset-4 hover:underline">
                    Edit in Business Settings
                </Link>
            </p>
        </section>

        <section class="neo-surface space-y-4 p-5">
            <h2 class="text-sm font-semibold">Services / products</h2>
            <ul v-if="offerings.length" class="divide-y divide-border">
                <li
                    v-for="offering in offerings"
                    :key="offering.id"
                    class="flex flex-col gap-2 py-3 sm:flex-row sm:items-start sm:justify-between"
                >
                    <div>
                        <p class="text-sm font-medium">
                            {{ offering.name }}
                            <span class="text-xs text-muted-foreground">
                                · {{ offering.is_active ? 'Active' : 'Inactive' }}
                            </span>
                        </p>
                        <p class="text-sm text-muted-foreground">
                            {{ offering.description || 'No description' }}
                        </p>
                    </div>
                    <div v-if="canManage" class="flex gap-2">
                        <Button type="button" variant="outline" size="sm" @click="toggleOffering(offering)">
                            {{ offering.is_active ? 'Deactivate' : 'Activate' }}
                        </Button>
                        <Button type="button" variant="outline" size="sm" @click="removeOffering(offering.id)">
                            Remove
                        </Button>
                    </div>
                </li>
            </ul>
            <p v-else class="text-sm text-muted-foreground">No services/products yet.</p>

            <form v-if="canManage" class="space-y-3 border-t border-border pt-4" @submit.prevent="submitOffering">
                <div class="grid gap-3 sm:grid-cols-2">
                    <div class="space-y-2">
                        <Label for="offering_name">Name</Label>
                        <Input id="offering_name" v-model="offeringForm.name" required />
                        <InputError :message="offeringForm.errors.name" />
                    </div>
                    <div class="space-y-2 sm:col-span-2">
                        <Label for="offering_description">Description</Label>
                        <Textarea id="offering_description" v-model="offeringForm.description" rows="3" />
                    </div>
                </div>
                <Button type="submit" :disabled="offeringForm.processing">Add service/product</Button>
            </form>
        </section>

        <section class="neo-surface space-y-4 p-5">
            <h2 class="text-sm font-semibold">FAQs / policies / support notes</h2>
            <ul v-if="articles.length" class="divide-y divide-border">
                <li
                    v-for="article in articles"
                    :key="article.id"
                    class="flex flex-col gap-2 py-3 sm:flex-row sm:items-start sm:justify-between"
                >
                    <div>
                        <p class="text-sm font-medium">
                            {{ article.title }}
                            <span class="text-xs text-muted-foreground">
                                · {{ article.category_label }} ·
                                {{ article.is_active ? 'Active' : 'Inactive' }}
                            </span>
                        </p>
                        <p class="text-sm text-muted-foreground whitespace-pre-wrap">
                            {{ article.content }}
                        </p>
                    </div>
                    <div v-if="canManage" class="flex gap-2">
                        <Button type="button" variant="outline" size="sm" @click="toggleArticle(article)">
                            {{ article.is_active ? 'Deactivate' : 'Activate' }}
                        </Button>
                        <Button type="button" variant="outline" size="sm" @click="removeArticle(article.id)">
                            Remove
                        </Button>
                    </div>
                </li>
            </ul>
            <p v-else class="text-sm text-muted-foreground">No knowledge articles yet.</p>

            <form v-if="canManage" class="space-y-3 border-t border-border pt-4" @submit.prevent="submitArticle">
                <div class="grid gap-3 sm:grid-cols-2">
                    <div class="space-y-2">
                        <Label for="article_title">Title</Label>
                        <Input id="article_title" v-model="articleForm.title" required />
                    </div>
                    <div class="space-y-2">
                        <Label for="article_category">Category</Label>
                        <select
                            id="article_category"
                            v-model="articleForm.category"
                            class="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm"
                        >
                            <option v-for="option in categoryOptions" :key="option.value" :value="option.value">
                                {{ option.label }}
                            </option>
                        </select>
                    </div>
                    <div class="space-y-2 sm:col-span-2">
                        <Label for="article_content">Content</Label>
                        <Textarea id="article_content" v-model="articleForm.content" rows="4" required />
                        <InputError :message="articleForm.errors.content" />
                    </div>
                </div>
                <Button type="submit" :disabled="articleForm.processing">Add article</Button>
            </form>
        </section>
    </div>
</template>
