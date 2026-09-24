<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { toUrl } from '@/lib/utils';
import { edit as editAppearance } from '@/routes/appearance';
import { edit as editProfile } from '@/routes/profile';
import { edit as editSecurity } from '@/routes/security';
import type { NavItem } from '@/types';

type SettingsGroup = {
    title: string;
    items: NavItem[];
};

const page = usePage();
const permissions = computed(() => page.props.auth.user?.permissions ?? []);

const can = (permission: string) => permissions.value.includes(permission);

const { isCurrentOrParentUrl } = useCurrentUrl();

const groups = computed<SettingsGroup[]>(() => {
    const personal: NavItem[] = [
        { title: 'Profile', href: editProfile() },
        { title: 'Security', href: editSecurity() },
        { title: 'Appearance', href: editAppearance() },
    ];

    const business: NavItem[] = [];
    if (can('settings.access') || can('business.view')) {
        business.push({ title: 'Business', href: '/settings/business' });
    }
    if (can('business.knowledge.view')) {
        business.push({ title: 'Knowledge', href: '/settings/knowledge' });
    }
    if (can('users.view')) {
        business.push({ title: 'Users & Access', href: '/settings/users' });
    }

    const operations: NavItem[] = [];
    if (can('settings.access')) {
        operations.push(
            { title: 'Communication', href: '/settings/communication' },
            { title: 'Automation', href: '/settings/automation' },
            { title: 'AI', href: '/settings/ai' },
        );
    }

    const system: NavItem[] = [];
    if (can('audit.view')) {
        system.push({ title: 'Audit', href: '/settings/audit' });
    }

    return [
        { title: 'Personal', items: personal },
        ...(business.length ? [{ title: 'Business', items: business }] : []),
        ...(operations.length
            ? [{ title: 'Operations', items: operations }]
            : []),
        ...(system.length ? [{ title: 'System', items: system }] : []),
    ];
});
</script>

<template>
    <div class="px-4 py-6">
        <Heading
            title="Settings"
            description="Configure your account, business details, and system preferences"
        />

        <div class="flex flex-col lg:flex-row lg:space-x-12">
            <aside class="w-full max-w-xl lg:w-56">
                <nav class="flex flex-col gap-6" aria-label="Settings">
                    <div
                        v-for="group in groups"
                        :key="group.title"
                        class="space-y-1"
                    >
                        <p
                            class="px-3 text-xs font-medium tracking-wide text-muted-foreground uppercase"
                        >
                            {{ group.title }}
                        </p>
                        <Button
                            v-for="item in group.items"
                            :key="toUrl(item.href)"
                            variant="ghost"
                            :class="[
                                'w-full justify-start',
                                {
                                    'bg-muted': isCurrentOrParentUrl(item.href),
                                },
                            ]"
                            as-child
                        >
                            <Link :href="item.href">
                                {{ item.title }}
                            </Link>
                        </Button>
                    </div>
                </nav>
            </aside>

            <Separator class="my-6 lg:hidden" />

            <div class="flex-1 md:max-w-3xl">
                <section class="space-y-12">
                    <slot />
                </section>
            </div>
        </div>
    </div>
</template>
