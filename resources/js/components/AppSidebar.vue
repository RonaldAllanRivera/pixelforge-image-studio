<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import {
    BookOpen,
    ChartColumn,
    FolderGit2,
    Sparkles,
    Wallet,
} from '@lucide/vue';
import { computed } from 'vue';
import AppLogo from '@/components/AppLogo.vue';
import NavFooter from '@/components/NavFooter.vue';
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
import { studio } from '@/routes';
import { report } from '@/routes/admin';
import { index as credits } from '@/routes/credits';
import type { NavItem } from '@/types';

const page = usePage();

const mainNavItems = computed<NavItem[]>(() => [
    {
        title: 'Studio',
        href: studio(),
        icon: Sparkles,
    },
    {
        title: 'Credits',
        href: credits(),
        icon: Wallet,
    },
    // Operators only. The server checks too: anyone else gets a 403.
    ...(page.props.auth.user?.is_admin
        ? [{ title: 'Admin report', href: report(), icon: ChartColumn }]
        : []),
]);

const footerNavItems: NavItem[] = [
    {
        title: 'Repository',
        href: 'https://github.com/RonaldAllanRivera/pixelforge-image-studio',
        icon: FolderGit2,
    },
    {
        title: 'How it works',
        href: 'https://github.com/RonaldAllanRivera/pixelforge-image-studio#readme',
        icon: BookOpen,
    },
];
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="studio()">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <NavMain :items="mainNavItems" />
        </SidebarContent>

        <SidebarFooter>
            <NavFooter :items="footerNavItems" />
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
