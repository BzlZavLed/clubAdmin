<script setup>
import { Link, usePage } from '@inertiajs/vue3'

const routeName = usePage().component

const menuItems = [
    { name: 'Dashboard', href: '/club-director/dashboard', route: 'dashboard' },
    { name: 'My Club', href: '/club-director/my-club', route: 'club.my-club' },
    { name: 'Members', href: '/club-director/members', route: 'club.members' },
    { name: 'Staff', href: '/club-director/staff', route: 'club.staff' },
    { name: 'Reports', href: '/club-director/reports', route: 'club.reports' },
    { name: 'Settings', href: '/club-director/settings', route: 'club.settings' },
]
</script>

<template>
    <nav class="flex-1 px-4 py-6 space-y-2">
        <Link v-for="item in menuItems" :key="item.name" :href="item.href" class="block px-2 py-2 rounded text-sm"
            :class="[
                route().current(item.route)
                    ? 'bg-yellow-100 text-red-700 font-semibold'
                    : 'text-gray-700 hover:text-red-600'
            ]">
        {{ item.name }}
        </Link>
    </nav>
</template>
