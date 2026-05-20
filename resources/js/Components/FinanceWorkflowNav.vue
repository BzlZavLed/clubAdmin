<script setup>
import { Link } from '@inertiajs/vue3'
import { computed } from 'vue'
import {
    BanknotesIcon,
    ChartBarIcon,
    DocumentChartBarIcon,
    ShoppingCartIcon,
} from '@heroicons/vue/24/outline'
import { useLocale } from '@/Composables/useLocale'

const { t } = useLocale()

const groups = computed(() => [
    {
        id: 'finance',
        title: t('finance'),
        description: t('finance_description'),
        icon: BanknotesIcon,
        items: [
            {
                id: 'cashbox',
                name: t('cashbox'),
                href: '/club-director/finance/cashbox',
                route: 'club.director.finance.cashbox',
                icon: BanknotesIcon,
            },
            {
                id: 'accounting_engine',
                name: t('accounting_engine'),
                href: '/club-director/finance/accounting',
                route: 'club.director.finance.accounting',
                icon: ChartBarIcon,
            },
            {
                id: 'finance_reports',
                name: t('financial_reports'),
                href: '/club-director/finance/reports',
                route: 'club.director.finance.reports',
                icon: DocumentChartBarIcon,
            },
            {
                id: 'fundraiser_pos',
                name: t('fundraiser_pos'),
                href: '/club-director/finance/fundraisers',
                route: 'club.director.finance.fundraisers',
                icon: ShoppingCartIcon,
            },
        ],
    },
])
</script>

<template>
    <section class="border-y border-gray-200 bg-white py-3">
        <div class="grid gap-4">
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
