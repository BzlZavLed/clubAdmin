<script setup>
import { Link, usePage } from '@inertiajs/vue3'

const routeName = usePage().component

const menuItems = [
    { name: 'Dashboard', href: '/club-personal/dashboard', route: 'dashboard' },
    { name: 'My Class', href: '/club-personal/my-class', route: 'club.my-class' },
    { name: 'Activities', href: '/club-personal/activities', route: 'club.activities' }
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
