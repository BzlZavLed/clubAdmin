<script setup>
import { Link, usePage } from '@inertiajs/vue3'
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
    BuildingOffice2Icon
} from '@heroicons/vue/24/outline'
import { ref } from 'vue'

const routeName = usePage().component
const openDropdown = ref(null)

const menuItems = [
    { name: 'Panel', href: '/super-admin/dashboard', route: 'superadmin.dashboard', icon: HomeIcon },
    { name: 'Crear iglesia', href: '/church-form', route: 'church.form', icon: BuildingOffice2Icon },
    {
        name: 'Mi club',
        icon: UsersIcon,
        children: [
            {
                name: 'Administración',
                href: '/club-director/my-club',
                route: 'club.my-club',
                icon: DocumentTextIcon
            },
            {
                name: 'Finanzas',
                href: '/club-director/my-club-finances',
                route: 'club.my-club-finances',
                icon: CurrencyDollarIcon
            },
            {
                name: 'Pagos',
                href: '/club-director/payments',
                route: 'club.director.payments',
                icon: BanknotesIcon
            },
            {
                name: 'Gastos',
                href: '/club-director/expenses',
                route: 'club.director.expenses',
                icon: CurrencyDollarIcon
            },
            {
                name: 'Plan de trabajo',
                href: '/club-director/workplan',
                route: 'club.workplan',
                icon: CalendarDaysIcon
            }
        ]
    },
    { name: 'Miembros', href: '/club-director/members', route: 'club.members', icon: UserGroupIcon },
    { name: 'Personal y cuentas', href: '/club-director/staff', route: 'club.staff', icon: BriefcaseIcon },
    {
        name: 'Reportes',
        icon: ChartBarIcon,
        children: [
            {
                name: 'Reportes de asistencia',
                href: '/club-director/reports/assistance',
                route: 'club.reports.assistance',
                icon: DocumentTextIcon
            },
            {
                name: 'Reportes financieros',
                href: '/club-director/reports/finances',
                route: 'club.reports.finances',
                icon: BanknotesIcon
            },
            {
                name: 'Saldos de cuentas',
                href: '/club-director/reports/accounts',
                route: 'club.reports.accounts',
                icon: CurrencyDollarIcon
            }
        ]
    },
    { name: 'Configuración', href: '/club-director/settings', route: 'club.settings', icon: CogIcon },
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
