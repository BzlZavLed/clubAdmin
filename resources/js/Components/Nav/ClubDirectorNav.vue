<script setup>
import { Link } from '@inertiajs/vue3'
import {
    HomeIcon,
    UsersIcon,
    UserGroupIcon,
    BriefcaseIcon,
    ChartBarIcon,
    CogIcon,
    DocumentTextIcon,
    BanknotesIcon,
    CalendarDaysIcon,
    ShoppingCartIcon,
} from '@heroicons/vue/24/outline'
import { computed, ref } from 'vue'
import { useLocale } from '@/Composables/useLocale'

const openDropdown = ref(null)
const { t } = useLocale()

const menuItems = computed(() => [
    { id: 'dashboard', name: t('dashboard'), href: '/club-director/dashboard', route: 'club.dashboard', icon: HomeIcon },
    {
        id: 'my_club',
        name: t('my_club'),
        icon: UsersIcon,
        children: [
            {
                id: 'administration',
                name: t('administration'),
                href: '/club-director/my-club',
                route: 'club.my-club',
                icon: DocumentTextIcon
            },
            {
                id: 'workplan',
                name: t('workplan'),
                href: '/club-director/workplan',
                route: 'club.workplan',
                icon: CalendarDaysIcon
            },
            {
                id: 'attendance_tracker',
                name: t('attendance_tracker'),
                href: '/club-director/assistance-report',
                route: 'club.director.assistance_report',
                icon: DocumentTextIcon
            },
            {
                id: 'events',
                name: t('events'),
                href: '/events',
                route: 'events.index',
                icon: CalendarDaysIcon,
            }
        ]
    },
    {
        id: 'finance',
        name: t('finance'),
        icon: BanknotesIcon,
        children: [
            {
                id: 'cashbox',
                name: t('cashbox'),
                href: '/club-director/finance/cashbox',
                route: 'club.director.finance.cashbox',
                icon: BanknotesIcon
            },
            {
                id: 'accounting_engine',
                name: t('accounting_engine'),
                href: '/club-director/finance/accounting',
                route: 'club.director.finance.accounting',
                icon: ChartBarIcon
            },
            {
                id: 'fundraiser_pos',
                name: t('fundraiser_pos'),
                href: '/club-director/finance/fundraisers',
                route: 'club.director.finance.fundraisers',
                icon: ShoppingCartIcon
            }
        ]
    },
    { id: 'members', name: t('members'), href: '/club-director/members', route: 'club.members', icon: UserGroupIcon },
    { id: 'staff_accounts', name: t('staff_accounts'), href: '/club-director/staff', route: 'club.staff', icon: BriefcaseIcon },
    {
        id: 'reports',
        name: t('reports'),
        icon: ChartBarIcon,
        children: [
            {
                id: 'attendance_reports',
                name: t('attendance_reports'),
                href: '/club-director/reports/assistance',
                route: 'club.reports.assistance',
                icon: DocumentTextIcon
            },
            {
                id: 'honors_requirements',
                name: t('honors_requirements'),
                href: '/club-director/reports/investiture-requirements',
                route: 'club.reports.investiture-requirements',
                icon: ChartBarIcon
            }
            // Add more report types here as needed
        ]
    },
    { id: 'club_settings', name: t('club_settings'), href: '/club-director/settings', route: 'club.settings', icon: CogIcon },
])

defineProps({
    isCollapsed: Boolean,
})

function toggleDropdown(itemId) {
    if (openDropdown.value === itemId) {
        openDropdown.value = null
    } else {
        openDropdown.value = itemId
    }
}
</script>

<template>
    <nav class="flex-1 px-4 py-6 space-y-2">
        <template v-for="item in menuItems" :key="item.id">
            <!-- Regular Link -->
            <Link v-if="!item.children" :href="item.href" class="flex w-full items-center rounded px-2 py-2.5 text-sm touch-manipulation select-none" :class="[
                route().current(item.route)
                    ? 'bg-yellow-100 text-red-700 font-semibold'
                    : 'text-gray-700 hover:text-red-600'
            ]">
            <component :is="item.icon" class="w-6 h-6 text-gray-500 shrink-0" />
            <span v-if="!isCollapsed" class="ml-2 truncate">{{ item.name }}</span>
            </Link>

            <!-- Dropdown Parent -->
            <div v-else>
                <button @click="toggleDropdown(item.id)"
                    class="flex w-full items-center rounded px-2 py-2.5 text-left text-sm touch-manipulation select-none" :class="[
                        openDropdown === item.id || item.children.some(child => route().current(child.route))
                            ? 'bg-yellow-100 text-red-700 font-semibold'
                            : 'text-gray-700 hover:text-red-600'
                    ]">
                    <component :is="item.icon" class="w-6 h-6 text-gray-500 shrink-0" />
                    <span v-if="!isCollapsed" class="ml-2 flex-1 truncate">{{ item.name }}</span>
                    <svg v-if="!isCollapsed" class="w-4 h-4 transform transition-transform duration-200"
                        :class="{ 'rotate-180': openDropdown === item.id }" fill="none" stroke="currentColor"
                        viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                    </svg>
                </button>

                <!-- Dropdown Items -->
                <div v-if="openDropdown === item.id && !isCollapsed" class="ml-8 mt-1 space-y-1">
                    <Link v-for="child in item.children" :key="child.id" :href="child.href"
                        class="flex w-full items-center rounded px-2 py-2 text-sm touch-manipulation select-none" :class="[
                            route().current(child.route)
                                ? 'bg-yellow-100 text-red-700 font-semibold'
                                : 'text-gray-600 hover:text-red-600'
                        ]">
                    <component :is="child.icon" class="w-4 h-4 text-gray-400 shrink-0" />
                    <span class="ml-2 truncate">{{ child.name }}</span>
                    <span
                        v-if="child.badge"
                        class="ml-2 inline-flex items-center rounded-full bg-amber-100 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-amber-800"
                    >
                        {{ child.badge }}
                    </span>
                    </Link>
                </div>
            </div>
        </template>
    </nav>
</template>
