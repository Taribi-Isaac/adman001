<script setup lang="ts">
import { Form, Head, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type ManagedUser = {
    id: number;
    name: string;
    email: string;
    is_active: boolean;
    roles: string[];
    email_verified_at: string | null;
};

const props = defineProps<{
    users: ManagedUser[];
    roles: string[];
    canCreate: boolean;
    canUpdate: boolean;
    canManageRoles: boolean;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Settings', href: '/settings/users' },
            { title: 'Users & Access', href: '/settings/users' },
        ],
    },
});

const page = usePage();
const flashSuccess = computed(() => page.props.flash?.success);
const showCreate = ref(false);
const editingId = ref<number | null>(null);

const editingUser = computed(
    () => props.users.find((user) => user.id === editingId.value) ?? null,
);
</script>

<template>
    <Head title="Users & Access" />

    <div class="space-y-8">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <Heading
                variant="small"
                title="Users & Access"
                description="Manage staff accounts and roles. Access is permission-based."
            />
            <Button
                v-if="canCreate"
                type="button"
                @click="showCreate = !showCreate"
            >
                {{ showCreate ? 'Close' : 'Add user' }}
            </Button>
        </div>

        <p
            v-if="flashSuccess"
            class="rounded-lg border border-border bg-muted/40 px-3 py-2 text-sm"
            role="status"
        >
            {{ flashSuccess }}
        </p>

        <section v-if="showCreate && canCreate" class="neo-surface space-y-4 p-5">
            <h2 class="text-sm font-semibold">Create staff user</h2>
            <Form
                action="/settings/users"
                method="post"
                class="grid gap-4 sm:grid-cols-2"
                #default="{ errors, processing }"
                @success="showCreate = false"
            >
                <div class="space-y-2">
                    <Label for="create_name">Name</Label>
                    <Input id="create_name" name="name" required />
                    <InputError :message="errors.name" />
                </div>
                <div class="space-y-2">
                    <Label for="create_email">Email</Label>
                    <Input id="create_email" name="email" type="email" required />
                    <InputError :message="errors.email" />
                </div>
                <div class="space-y-2">
                    <Label for="create_password">Password</Label>
                    <Input
                        id="create_password"
                        name="password"
                        type="password"
                        required
                        autocomplete="new-password"
                    />
                    <InputError :message="errors.password" />
                </div>
                <div class="space-y-2">
                    <Label for="create_password_confirmation">Confirm password</Label>
                    <Input
                        id="create_password_confirmation"
                        name="password_confirmation"
                        type="password"
                        required
                        autocomplete="new-password"
                    />
                </div>
                <div v-if="canManageRoles" class="space-y-2 sm:col-span-2">
                    <Label for="create_role">Role</Label>
                    <select
                        id="create_role"
                        name="role"
                        class="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm"
                    >
                        <option v-for="role in roles" :key="role" :value="role">
                            {{ role }}
                        </option>
                    </select>
                </div>
                <div class="sm:col-span-2">
                    <Button type="submit" :disabled="processing">
                        {{ processing ? 'Creating…' : 'Create user' }}
                    </Button>
                </div>
            </Form>
        </section>

        <section class="neo-surface overflow-hidden">
            <table class="w-full text-sm">
                <thead class="border-b border-border bg-muted/40 text-left">
                    <tr>
                        <th class="px-4 py-3 font-medium">Name</th>
                        <th class="px-4 py-3 font-medium">Email</th>
                        <th class="px-4 py-3 font-medium">Role</th>
                        <th class="px-4 py-3 font-medium">Status</th>
                        <th v-if="canUpdate" class="px-4 py-3 font-medium">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="user in users"
                        :key="user.id"
                        class="border-b border-border/70 last:border-0"
                    >
                        <td class="px-4 py-3">{{ user.name }}</td>
                        <td class="px-4 py-3">{{ user.email }}</td>
                        <td class="px-4 py-3">
                            {{ user.roles.join(', ') || '—' }}
                        </td>
                        <td class="px-4 py-3">
                            <span
                                class="inline-flex items-center rounded-full border px-2 py-0.5 text-xs"
                                :class="
                                    user.is_active
                                        ? 'border-emerald-500/30 text-emerald-700 dark:text-emerald-300'
                                        : 'border-border text-muted-foreground'
                                "
                            >
                                {{ user.is_active ? 'Active' : 'Inactive' }}
                            </span>
                        </td>
                        <td v-if="canUpdate" class="px-4 py-3">
                            <Button
                                variant="ghost"
                                size="sm"
                                type="button"
                                @click="
                                    editingId =
                                        editingId === user.id ? null : user.id
                                "
                            >
                                {{ editingId === user.id ? 'Close' : 'Edit' }}
                            </Button>
                        </td>
                    </tr>
                    <tr v-if="users.length === 0">
                        <td
                            colspan="5"
                            class="px-4 py-8 text-center text-muted-foreground"
                        >
                            No users yet.
                        </td>
                    </tr>
                </tbody>
            </table>
        </section>

        <section
            v-if="editingUser && canUpdate"
            class="neo-surface space-y-4 p-5"
        >
            <h2 class="text-sm font-semibold">Edit {{ editingUser.name }}</h2>
            <Form
                :action="`/settings/users/${editingUser.id}`"
                method="put"
                class="grid gap-4 sm:grid-cols-2"
                #default="{ errors, processing }"
                @success="editingId = null"
            >
                <div class="space-y-2">
                    <Label for="edit_name">Name</Label>
                    <Input
                        id="edit_name"
                        name="name"
                        :default-value="editingUser.name"
                        required
                    />
                    <InputError :message="errors.name" />
                </div>
                <div class="space-y-2">
                    <Label for="edit_email">Email</Label>
                    <Input
                        id="edit_email"
                        name="email"
                        type="email"
                        :default-value="editingUser.email"
                        required
                    />
                    <InputError :message="errors.email" />
                </div>
                <div class="space-y-2">
                    <Label for="edit_password">New password (optional)</Label>
                    <Input
                        id="edit_password"
                        name="password"
                        type="password"
                        autocomplete="new-password"
                    />
                    <InputError :message="errors.password" />
                </div>
                <div class="space-y-2">
                    <Label for="edit_password_confirmation">Confirm password</Label>
                    <Input
                        id="edit_password_confirmation"
                        name="password_confirmation"
                        type="password"
                        autocomplete="new-password"
                    />
                </div>
                <div v-if="canManageRoles" class="space-y-2">
                    <Label for="edit_role">Role</Label>
                    <select
                        id="edit_role"
                        name="role"
                        class="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm"
                        :value="editingUser.roles[0] ?? ''"
                    >
                        <option v-for="role in roles" :key="role" :value="role">
                            {{ role }}
                        </option>
                    </select>
                </div>
                <div class="flex items-center gap-3">
                    <input type="hidden" name="is_active" value="0" />
                    <input
                        id="edit_is_active"
                        name="is_active"
                        type="checkbox"
                        value="1"
                        class="size-4 rounded border-input"
                        :checked="editingUser.is_active"
                    />
                    <Label for="edit_is_active">Active</Label>
                </div>
                <div class="sm:col-span-2">
                    <Button type="submit" :disabled="processing">
                        {{ processing ? 'Saving…' : 'Save user' }}
                    </Button>
                </div>
            </Form>
        </section>
    </div>
</template>
