<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import {
    ChartColumn,
    FileText,
    Files,
    LayoutDashboard,
    MessageSquare,
    Receipt,
    RefreshCw,
    Settings,
    Users,
    Wallet,
} from '@lucide/vue';
import { computed } from 'vue';
import AppLogo from '@/components/AppLogo.vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { dashboard } from '@/routes';
import type { NavItem } from '@/types';

const page = usePage();

const businessName = computed(
    () => page.props.business?.name ?? page.props.name ?? 'ADMAN',
);

const mainNavItems: NavItem[] = [
    {
        title: 'Dashboard',
        href: dashboard(),
        icon: LayoutDashboard,
    },
    {
        title: 'Customers',
        href: '/contacts',
        icon: Users,
    },
    {
        title: 'Conversations',
        href: '/conversations',
        icon: MessageSquare,
    },
    {
        title: 'Quotes',
        href: '/quotes',
        icon: FileText,
    },
    {
        title: 'Invoices',
        href: '/invoices',
        icon: Receipt,
    },
    {
        title: 'Recurring Billing',
        href: '/recurring-billing',
        icon: RefreshCw,
    },
    {
        title: 'Payments',
        href: '/payments',
        icon: Wallet,
    },
    {
        title: 'Documents',
        href: '/documents',
        icon: Files,
    },
    {
        title: 'Reports',
        href: '#',
        icon: ChartColumn,
        disabled: true,
        comingSoon: true,
    },
];

const settingsNavItems: NavItem[] = [
    {
        title: 'Settings',
        href: '/settings/business',
        icon: Settings,
    },
];
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="dashboard()">
                            <AppLogo />
                            <div class="grid flex-1 text-left text-sm leading-tight">
                                <span class="truncate font-semibold">{{
                                    businessName
                                }}</span>
                                <span class="truncate text-xs text-muted-foreground"
                                    >Business administration</span
                                >
                            </div>
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <NavMain :items="mainNavItems" label="Workspace" />
            <NavMain :items="settingsNavItems" label="Administration" />
        </SidebarContent>

        <SidebarFooter>
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
