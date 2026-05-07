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
    CurrencyDollarIcon,
    BanknotesIcon,
    CalendarDaysIcon,
    BuildingOffice2Icon,
    ArrowPathIcon,
} from '@heroicons/vue/24/outline'
import { computed, ref } from 'vue'
import { useLocale } from '@/Composables/useLocale'

const openDropdown = ref(null)
const { t } = useLocale()

const menuItems = computed(() => [
    { id: 'dashboard', name: t('dashboard'), href: '/super-admin/dashboard', route: 'superadmin.dashboard', icon: HomeIcon },
    {
        id: 'superadmin',
        name: t('superadmin'),
        icon: UsersIcon,
        children: [
            {
                id: 'unions',
                name: t('unions'),
                href: '/super-admin/unions',
                route: 'superadmin.unions.manage',
                icon: BuildingOffice2Icon
            },
            {
                id: 'conferences_associations',
                name: t('conferences_associations'),
                href: '/super-admin/associations',
                route: 'superadmin.associations.manage',
                icon: BuildingOffice2Icon
            },
            {
                id: 'districts',
                name: t('districts'),
                href: '/super-admin/districts',
                route: 'superadmin.districts.manage',
                icon: BuildingOffice2Icon
            },
            {
                id: 'churches',
                name: t('churches'),
                href: '/super-admin/churches/manage',
                route: 'superadmin.churches.manage',
                icon: BuildingOffice2Icon
            },
            {
                id: 'clubs',
                name: t('clubs'),
                href: '/super-admin/clubs',
                route: 'superadmin.clubs.manage',
                icon: UserGroupIcon
            },
            {
                id: 'users',
                name: t('users'),
                href: '/super-admin/users',
                route: 'superadmin.users.manage',
                icon: UsersIcon
            },
            {
                id: 'ai_logs',
                name: t('ai_logs'),
                href: '/super-admin/ai-logs',
                route: 'superadmin.ai-logs.index',
                icon: DocumentTextIcon
            },
            {
                id: 'task_forms',
                name: t('task_forms'),
                href: '/super-admin/event-task-forms',
                route: 'superadmin.event-task-forms.index',
                icon: DocumentTextIcon
            }
        ]
    },
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
                id: 'accounts_concepts',
                name: t('accounts_concepts'),
                href: '/club-director/my-club-finances',
                route: 'club.my-club-finances',
                icon: CurrencyDollarIcon
            },
            {
                id: 'income',
                name: t('income'),
                href: '/club-director/payments',
                route: 'club.director.payments',
                icon: BanknotesIcon
            },
            {
                id: 'treasury',
                name: t('treasury'),
                href: '/club-director/treasury',
                route: 'club.director.treasury',
                icon: BanknotesIcon
            },
            {
                id: 'expenses',
                name: t('expenses'),
                href: '/club-director/expenses',
                route: 'club.director.expenses',
                icon: CurrencyDollarIcon
            },
            {
                id: 'accounting_corrections',
                name: t('accounting_corrections'),
                href: '/club-director/accounting-corrections',
                route: 'club.director.accounting-corrections',
                icon: ArrowPathIcon
            },
            {
                id: 'workplan',
                name: t('workplan'),
                href: '/club-director/workplan',
                route: 'club.workplan',
                icon: CalendarDaysIcon
            },
            {
                id: 'events',
                name: t('events'),
                href: '/events',
                route: 'events.index',
                icon: CalendarDaysIcon,
                badge: 'WIP',
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
                id: 'financial_reports',
                name: t('financial_reports'),
                href: '/club-director/reports/finances',
                route: 'club.reports.finances',
                icon: BanknotesIcon
            },
            {
                id: 'account_balances',
                name: t('account_balances'),
                href: '/club-director/reports/accounts',
                route: 'club.reports.accounts',
                icon: CurrencyDollarIcon
            },
            {
                id: 'honors_requirements',
                name: t('honors_requirements'),
                href: '/club-director/reports/investiture-requirements',
                route: 'club.reports.investiture-requirements',
                icon: ChartBarIcon
            }
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
                        openDropdown === item.id
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
