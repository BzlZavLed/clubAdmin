<script setup>
import { ref, watch } from 'vue'
import { Link, usePage } from '@inertiajs/vue3'
import { useGeneral } from '@/Composables/useGeneral'
import {
    HomeIcon,
    UsersIcon,
    UserGroupIcon,
    BriefcaseIcon,
    ChartBarIcon,
    CogIcon,
    ChevronDownIcon,
    ChevronRightIcon,
    BanknotesIcon,

} from '@heroicons/vue/24/outline'

const { showToast } = useGeneral()
const page = usePage()
const showDropdown = ref(false)

const menuItems = [
    { name: 'Dashboard', href: '/club-personal/dashboard', route: 'dashboard', icon: HomeIcon },
]

const clubSubItems = [
    { name: 'Assistance Report', href: '/club-personal/assistance-report', route: 'club.assistance_report', icon: BriefcaseIcon },
    { name: 'Payments', href: '/club-personal/payments', route: 'club.payments.index', icon: BanknotesIcon },
]

const props = defineProps({
    isCollapsed: Boolean,
})

watch(
    () => page.props.toast,
    (toast) => {
        if (toast?.message) {
            showToast(toast.message, toast.type || 'info')
        }
    },
    { immediate: true }
)
</script>

<template>
    <nav class="flex-1 px-2 py-4 space-y-1">
        <!-- Main menu items -->
        <Link v-for="item in menuItems" :key="item.name" :href="item.href"
            class="flex items-center space-x-2 px-3 py-2 rounded hover:bg-gray-100"
            :class="route().current(item.route) ? 'bg-yellow-100 text-red-700 font-semibold' : 'text-gray-700'">
        <component :is="item.icon" class="w-5 h-5" />
        <span v-if="!isCollapsed" class="text-sm">{{ item.name }}</span>
        </Link>

        <!-- Dropdown: My Class -->
        <div class="relative">
            <button @click="showDropdown = !showDropdown"
                class="flex items-center w-full px-3 py-2 rounded text-sm text-gray-700 hover:bg-gray-100">
                <UserGroupIcon class="w-5 h-5" />
                <span v-if="!isCollapsed" class="ml-2">My Class</span>
                <span class="ml-auto" v-if="!isCollapsed">
                    <component :is="showDropdown ? ChevronDownIcon : ChevronRightIcon" class="w-4 h-4" />
                </span>
            </button>

            <!-- Sub-items -->
            <div v-if="showDropdown && !isCollapsed" class="ml-6 mt-1 space-y-1">
                <Link v-for="sub in clubSubItems" :key="sub.name" :href="sub.href"
                    class="flex items-center space-x-2 text-sm text-gray-700 hover:text-red-600"
                    :class="{ 'font-semibold text-red-700': route().current(sub.route) }">
                <component :is="sub.icon" class="w-5 h-5" />
                <span>{{ sub.name }}</span>
                </Link>
            </div>
        </div>
    </nav>
</template>
