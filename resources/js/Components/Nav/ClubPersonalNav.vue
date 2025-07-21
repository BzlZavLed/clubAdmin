<script setup>
import { ref } from 'vue'
import { Link, usePage } from '@inertiajs/vue3'

const routeName = usePage().component
const showDropdown = ref(false)

const menuItems = [
    { name: 'Dashboard', href: '/club-personal/dashboard', route: 'dashboard' },
]

const clubSubItems = [
    { name: 'Assistance Report', href: '/club-personal/assistance-report', route: 'club.assistance_report' },
    // You can add more later here
]
</script>

<template>
    <nav class="flex-1 px-4 py-6 space-y-2">
        <!-- Standard Menu Items -->
        <Link
            v-for="item in menuItems"
            :key="item.name"
            :href="item.href"
            class="block px-2 py-2 rounded text-sm"
            :class="[
                route().current(item.route)
                    ? 'bg-yellow-100 text-red-700 font-semibold'
                    : 'text-gray-700 hover:text-red-600'
            ]">
            {{ item.name }}
        </Link>

        <!-- Dropdown Trigger -->
        <div class="relative">
            <button
                @click="showDropdown = !showDropdown"
                class="w-full text-left px-2 py-2 rounded text-sm font-medium text-gray-700 hover:text-red-600"
            >
                My Class ▾
            </button>

            <!-- Dropdown Items -->
            <div
                v-if="showDropdown"
                class="ml-4 mt-1 space-y-1 border-l border-gray-300 pl-4"
            >
                <Link
                    v-for="sub in clubSubItems"
                    :key="sub.name"
                    :href="sub.href"
                    class="block text-sm text-gray-700 hover:text-red-600"
                    :class="{ 'font-semibold text-red-700': route().current(sub.route) }"
                >
                    {{ sub.name }}
                </Link>
            </div>
        </div>
    </nav>
</template>
