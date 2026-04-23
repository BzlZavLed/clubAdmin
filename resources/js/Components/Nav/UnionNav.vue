<script setup>
import { Link, usePage } from '@inertiajs/vue3'
import {
    HomeIcon,
    Squares2X2Icon,
    FolderOpenIcon,
    DocumentTextIcon,
    CalendarDaysIcon,
    MapIcon,
    BuildingLibraryIcon,
    UserGroupIcon,
    Cog6ToothIcon,
    ChartBarIcon,
    ClipboardDocumentListIcon,
} from '@heroicons/vue/24/outline'
import { computed } from 'vue'

const page = usePage()
const effectiveRole = computed(() => page.props.auth?.effective_role || page.props.auth?.user?.effective_role || 'union_youth_director')
const effectiveScope = computed(() => page.props.auth?.effective_scope_summary || page.props.auth?.user?.effective_scope_summary || {})
const evaluationSystem = computed(() => effectiveScope.value?.evaluation_system || page.props.auth?.superadmin_context?.evaluation_system || 'honors')

const sections = computed(() => {
    if (effectiveRole.value === 'association_youth_director') {
        return [
            {
                title: 'Asociación',
                items: [
                    { name: 'Panel', href: '/association/dashboard', route: 'association.dashboard', icon: HomeIcon },
                    { name: 'Plan de trabajo', href: '/association/workplan', route: 'association.workplan', icon: ClipboardDocumentListIcon },
                    {
                        name: evaluationSystem.value === 'carpetas' ? 'Requisitos de carpeta' : 'Planificación de clases',
                        href: '/association/programs',
                        route: 'association.programs',
                        icon: evaluationSystem.value === 'carpetas' ? FolderOpenIcon : CalendarDaysIcon,
                    },
                    ...(evaluationSystem.value === 'carpetas' ? [
                        {
                            name: 'Distritos',
                            href: '/association/districts',
                            route: 'association.districts',
                            icon: MapIcon,
                        },
                        {
                            name: 'Solicitudes de investidura',
                            href: '/association/investiture-requests',
                            route: 'association.investiture-requests',
                            icon: ClipboardDocumentListIcon,
                        },
                    ] : []),
                ],
            },
            {
                title: 'Administración',
                items: [
                    { name: 'Configuración', href: '/association/settings', route: 'association.settings', icon: Cog6ToothIcon },
                ],
            },
        ]
    }

    if (effectiveRole.value === 'district_pastor' || effectiveRole.value === 'district_secretary') {
        return [
            {
                title: 'Distrito',
                items: [
                    { name: 'Panel', href: '/district/dashboard', route: 'district.dashboard', icon: HomeIcon },
                    { name: 'Iglesias', href: '/district/churches', route: 'district.churches', icon: BuildingLibraryIcon },
                    { name: 'Clubes', href: '/district/clubs', route: 'district.clubs', icon: UserGroupIcon },
                    { name: 'Plan de trabajo', href: '/district/workplan', route: 'district.workplan', icon: ClipboardDocumentListIcon },
                    ...(evaluationSystem.value === 'carpetas' ? [
                        { name: 'Evaluaciones de investidura', href: '/district/investiture-requests', route: 'district.investiture-requests', icon: FolderOpenIcon },
                    ] : []),
                ],
            },
        ]
    }

    return [
        {
            title: 'Catálogos',
            items: [
                { name: 'Panel', href: '/union/dashboard', route: 'union.dashboard', icon: HomeIcon },
                { name: 'Asociaciones', href: '/union/associations', route: 'union.associations', icon: MapIcon },
                { name: 'Clubes y clases', href: '/union/catalog/clubs-classes', route: 'union.catalog', icon: Squares2X2Icon },
                { name: 'Carpeta de investidura', href: '/union/carpeta-builder', route: 'union.carpeta-builder', icon: FolderOpenIcon },
                { name: 'Plan de trabajo', href: '/union/workplan', route: 'union.workplan', icon: ClipboardDocumentListIcon },
            ],
        },
        {
            title: 'Reportes',
            items: [
                { name: 'Reporte de asistencia', href: '/union/reports/assistance', route: 'union.reports.assistance', icon: DocumentTextIcon },
                ...(evaluationSystem.value === 'carpetas' ? [
                    { name: 'Reportes de requisitos', href: '/union/reports/progress', route: 'union.reports.progress', icon: ChartBarIcon },
                ] : []),
            ],
        },
    ]
})

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
