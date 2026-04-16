<script setup>
import { Link } from '@inertiajs/vue3'
import {
    HomeIcon,
    Squares2X2Icon,
    FolderOpenIcon,
    DocumentTextIcon,
    BanknotesIcon,
} from '@heroicons/vue/24/outline'

const sections = [
    {
        title: 'Catálogos',
        items: [
            { name: 'Panel', href: '/union/dashboard', route: 'union.dashboard', icon: HomeIcon },
            { name: 'Clubes y clases', href: '/union/catalog/clubs-classes', route: 'union.catalog', icon: Squares2X2Icon },
            { name: 'Carpeta de investidura', href: '/union/carpeta-builder', route: 'union.carpeta-builder', icon: FolderOpenIcon },
        ],
    },
    {
        title: 'Reportes',
        items: [
            { name: 'Reporte de asistencia', href: '/union/reports/assistance', route: 'union.reports.assistance', icon: DocumentTextIcon },
            { name: 'Reporte financiero', href: '/union/reports/finances', route: 'union.reports.finances', icon: BanknotesIcon },
        ],
    },
]

defineProps({
    isCollapsed: Boolean,
})
</script>

<template>
    <nav class="flex-1 px-4 py-6 space-y-2">
        <div v-for="section in sections" :key="section.title" class="space-y-1">
            <div v-if="!isCollapsed" class="px-2 pt-2 text-[11px] font-semibold uppercase tracking-wide text-gray-400">
                {{ section.title }}
            </div>
            <Link
                v-for="item in section.items"
                :key="item.name"
                :href="item.href"
                class="flex items-center px-2 py-2 rounded text-sm"
                :class="[
                    route().current(item.route)
                        ? 'bg-yellow-100 text-red-700 font-semibold'
                        : 'text-gray-700 hover:text-red-600'
                ]"
            >
                <component :is="item.icon" class="w-6 h-6 text-gray-500 shrink-0" />
                <span v-if="!isCollapsed" class="ml-2 truncate">{{ item.name }}</span>
            </Link>
        </div>
    </nav>
</template>
