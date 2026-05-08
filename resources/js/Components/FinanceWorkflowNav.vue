<script setup>
import { Link } from '@inertiajs/vue3'
import { computed } from 'vue'
import {
    ArrowPathIcon,
    BanknotesIcon,
    BuildingLibraryIcon,
    ChartBarIcon,
    CreditCardIcon,
    CurrencyDollarIcon,
    DocumentTextIcon,
} from '@heroicons/vue/24/outline'
import { useLocale } from '@/Composables/useLocale'

const { t } = useLocale()

const groups = computed(() => [
    {
        id: 'cashbox',
        title: t('cashbox'),
        description: t('cashbox_description'),
        icon: BanknotesIcon,
        items: [
            {
                id: 'cashbox_income',
                name: t('cashbox_income'),
                href: '/club-director/payments',
                route: 'club.director.payments',
                icon: CreditCardIcon,
            },
            {
                id: 'cashbox_expenses',
                name: t('cashbox_expenses'),
                href: '/club-director/expenses',
                route: 'club.director.expenses',
                icon: CurrencyDollarIcon,
            },
        ],
    },
    {
        id: 'balances_transfers',
        title: t('balances_transfers'),
        description: t('balances_transfers_description'),
        icon: BuildingLibraryIcon,
        items: [
            {
                id: 'account_setup',
                name: t('account_setup'),
                href: '/club-director/my-club-finances',
                route: 'club.my-club-finances',
                icon: DocumentTextIcon,
            },
            {
                id: 'treasury_transfers',
                name: t('treasury_transfers'),
                href: '/club-director/treasury',
                route: 'club.director.treasury',
                icon: BuildingLibraryIcon,
            },
        ],
    },
    {
        id: 'financial_reports',
        title: t('financial_reports'),
        description: t('financial_reports_description'),
        icon: ChartBarIcon,
        items: [
            {
                id: 'general_financial_report',
                name: t('general_financial_report'),
                href: '/club-director/reports/finances',
                route: 'club.reports.finances',
                icon: ChartBarIcon,
            },
            {
                id: 'movement_report',
                name: t('movement_report'),
                href: '/club-director/reports/accounts',
                route: 'club.reports.accounts',
                icon: BanknotesIcon,
            },
            {
                id: 'corrections_audit',
                name: t('corrections_audit'),
                href: '/club-director/accounting-corrections',
                route: 'club.director.accounting-corrections',
                icon: ArrowPathIcon,
            },
        ],
    },
])
</script>

<template>
    <section class="border-y border-gray-200 bg-white py-3">
        <div class="grid gap-4 lg:grid-cols-3">
            <div v-for="group in groups" :key="group.id" class="min-w-0">
                <div class="mb-2 flex items-start gap-2">
                    <component :is="group.icon" class="mt-0.5 h-5 w-5 shrink-0 text-gray-500" />
                    <div class="min-w-0">
                        <h2 class="text-sm font-semibold text-gray-900">{{ group.title }}</h2>
                        <p class="text-xs leading-5 text-gray-500">{{ group.description }}</p>
                    </div>
                </div>

                <div class="flex flex-wrap gap-2">
                    <Link
                        v-for="item in group.items"
                        :key="item.id"
                        :href="item.href"
                        class="inline-flex min-h-10 items-center gap-2 rounded-lg border px-3 py-2 text-sm font-medium transition"
                        :class="[
                            route().current(item.route)
                                ? 'border-red-200 bg-red-50 text-red-700'
                                : 'border-gray-200 bg-white text-gray-700 hover:border-gray-300 hover:bg-gray-50'
                        ]"
                    >
                        <component :is="item.icon" class="h-4 w-4 shrink-0" />
                        <span>{{ item.name }}</span>
                    </Link>
                </div>
            </div>
        </div>
    </section>
</template>
