<script setup>
import { Link, usePage } from '@inertiajs/vue3'
import {
    HomeIcon,
    UsersIcon,
    UserGroupIcon,
    BuildingOffice2Icon
} from '@heroicons/vue/24/outline'
import { ref } from 'vue'

const routeName = usePage().component
const openDropdown = ref(null)

const menuItems = [
    { name: 'Panel', href: '/super-admin/dashboard', route: 'superadmin.dashboard', icon: HomeIcon },
    {
        name: 'Superadmin',
        icon: UsersIcon,
        children: [
            {
                name: 'Iglesias',
                href: '/super-admin/churches/manage',
                route: 'superadmin.churches.manage',
                icon: BuildingOffice2Icon
            },
            {
                name: 'Clubes',
                href: '/super-admin/clubs',
                route: 'superadmin.clubs.manage',
                icon: UserGroupIcon
            },
            {
                name: 'Usuarios',
                href: '/super-admin/users',
                route: 'superadmin.users.manage',
                icon: UsersIcon
            }
        ]
    },
]

defineProps({
    isCollapsed: Boolean,
})

function toggleDropdown(itemName) {
    if (openDropdown.value === itemName) {
        openDropdown.value = null
    } else {
        openDropdown.value = itemName
    }
}
</script>

<template>
    <nav class="flex-1 px-4 py-6 space-y-2">
        <template v-for="item in menuItems" :key="item.name">
            <!-- Regular Link -->
            <Link v-if="!item.children" :href="item.href" class="flex items-center px-2 py-2 rounded text-sm" :class="[
                route().current(item.route)
                    ? 'bg-yellow-100 text-red-700 font-semibold'
                    : 'text-gray-700 hover:text-red-600'
            ]">
            <component :is="item.icon" class="w-6 h-6 text-gray-500 shrink-0" />
            <span v-if="!isCollapsed" class="ml-2 truncate">{{ item.name }}</span>
            </Link>

            <!-- Dropdown Parent -->
            <div v-else>
                <button @click="toggleDropdown(item.name)"
                    class="w-full flex items-center px-2 py-2 rounded text-sm text-left" :class="[
                        openDropdown === item.name
                            ? 'bg-yellow-100 text-red-700 font-semibold'
                            : 'text-gray-700 hover:text-red-600'
                    ]">
                    <component :is="item.icon" class="w-6 h-6 text-gray-500 shrink-0" />
                    <span v-if="!isCollapsed" class="ml-2 flex-1 truncate">{{ item.name }}</span>
                    <svg v-if="!isCollapsed" class="w-4 h-4 transform transition-transform duration-200"
                        :class="{ 'rotate-180': openDropdown === item.name }" fill="none" stroke="currentColor"
                        viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                    </svg>
                </button>

                <!-- Dropdown Items -->
                <div v-if="openDropdown === item.name && !isCollapsed" class="ml-8 mt-1 space-y-1">
                    <Link v-for="child in item.children" :key="child.name" :href="child.href"
                        class="flex items-center text-sm px-2 py-1 rounded" :class="[
                            route().current(child.route)
                                ? 'bg-yellow-100 text-red-700 font-semibold'
                                : 'text-gray-600 hover:text-red-600'
                        ]">
                    <component :is="child.icon" class="w-4 h-4 text-gray-400 shrink-0" />
                    <span class="ml-2 truncate">{{ child.name }}</span>
                    </Link>
                </div>
            </div>
        </template>
    </nav>
</template>
