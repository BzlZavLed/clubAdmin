<script setup>
import { Link, usePage } from '@inertiajs/vue3'
import { HomeIcon, UsersIcon, UserGroupIcon, BriefcaseIcon, ChartBarIcon, CogIcon } from '@heroicons/vue/24/outline'

const routeName = usePage().component

const menuItems = [
    { name: 'Dashboard', href: '/club-director/dashboard', route: 'dashboard', icon: HomeIcon },
    { name: 'My Club', href: '/club-director/my-club', route: 'club.my-club', icon: UsersIcon },
    { name: 'Members', href: '/club-director/members', route: 'club.members', icon: UserGroupIcon },
    { name: 'Staff', href: '/club-director/staff', route: 'club.staff', icon: BriefcaseIcon },
    { name: 'Reports', href: '/club-director/reports', route: 'club.reports', icon: ChartBarIcon },
    { name: 'Settings', href: '/club-director/settings', route: 'club.settings', icon: CogIcon },
]

defineProps({
    isCollapsed: Boolean,
})
</script>

<template>
    <nav class="flex-1 px-4 py-6 space-y-2">
        <!-- Menu Items -->
        <Link
            v-for="item in menuItems"
            :key="item.name"
            :href="item.href"
            class="flex items-center px-2 py-2 rounded text-sm"
            :class="[
                route().current(item.route)
                    ? 'bg-yellow-100 text-red-700 font-semibold'
                    : 'text-gray-700 hover:text-red-600'
            ]"
        >
            <!-- Icon -->
            <component :is="item.icon" class="w-6 h-6 text-gray-500 shrink-0" />

            <!-- Text (hidden when collapsed) -->
            <span
                v-if="!isCollapsed"
                class="ml-2 truncate"
            >
                {{ item.name }}
            </span>
        </Link>
    </nav>
</template>