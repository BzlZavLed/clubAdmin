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
    HeartIcon,
} from '@heroicons/vue/24/outline'
import { computed } from 'vue'
import { useLocale } from '@/Composables/useLocale'

const page = usePage()
const { t } = useLocale()
const effectiveRole = computed(() => page.props.auth?.effective_role || page.props.auth?.user?.effective_role || 'union_youth_director')
const effectiveScope = computed(() => page.props.auth?.effective_scope_summary || page.props.auth?.user?.effective_scope_summary || {})
const evaluationSystem = computed(() => effectiveScope.value?.evaluation_system || page.props.auth?.superadmin_context?.evaluation_system || 'honors')

const sections = computed(() => {
    if (effectiveRole.value === 'association_youth_director') {
        return [
            {
                id: 'association',
                title: t('association'),
                items: [
                    { id: 'dashboard', name: t('dashboard'), href: '/association/dashboard', route: 'association.dashboard', icon: HomeIcon },
                    { id: 'workplan', name: t('workplan'), href: '/association/workplan', route: 'association.workplan', icon: ClipboardDocumentListIcon },
                    { id: 'events', name: t('events'), href: '/events', route: 'events.index', icon: CalendarDaysIcon },
                    {
                        id: 'programs',
                        name: evaluationSystem.value === 'carpetas' ? t('folder_requirements') : t('class_planning'),
                        href: '/association/programs',
                        route: 'association.programs',
                        icon: evaluationSystem.value === 'carpetas' ? FolderOpenIcon : CalendarDaysIcon,
                    },
                    ...(evaluationSystem.value === 'carpetas' ? [
                        {
                            id: 'districts',
                            name: t('districts'),
                            href: '/association/districts',
                            route: 'association.districts',
                            icon: MapIcon,
                        },
                        {
                            id: 'clubs',
                            name: t('clubs'),
                            href: '/association/clubs',
                            route: 'association.clubs',
                            icon: UserGroupIcon,
                        },
                        {
                            id: 'investiture_requests',
                            name: t('investiture_requests'),
                            href: '/association/investiture-requests',
                            route: 'association.investiture-requests',
                            icon: ClipboardDocumentListIcon,
                        },
                    ] : []),
                ],
            },
            {
                id: 'administration',
                title: t('administration'),
                items: [
                    { id: 'club_settings', name: t('club_settings'), href: '/association/settings', route: 'association.settings', icon: Cog6ToothIcon },
                ],
            },
        ]
    }

    if (effectiveRole.value === 'district_pastor' || effectiveRole.value === 'district_secretary') {
        return [
            {
                id: 'district',
                title: t('district'),
                items: [
                    { id: 'dashboard', name: t('dashboard'), href: '/district/dashboard', route: 'district.dashboard', icon: HomeIcon },
                    { id: 'churches', name: t('churches'), href: '/district/churches', route: 'district.churches', icon: BuildingLibraryIcon },
                    { id: 'clubs', name: t('clubs'), href: '/district/clubs', route: 'district.clubs', icon: UserGroupIcon },
                    { id: 'pastoral_care', name: t('pastoral_care'), href: '/district/pastoral-care', route: 'district.pastoral-care', icon: HeartIcon },
                    { id: 'workplan', name: t('workplan'), href: '/district/workplan', route: 'district.workplan', icon: ClipboardDocumentListIcon },
                    { id: 'events', name: t('events'), href: '/events', route: 'events.index', icon: CalendarDaysIcon },
                    ...(evaluationSystem.value === 'carpetas' ? [
                        { id: 'investiture_evaluations', name: t('investiture_evaluations'), href: '/district/investiture-requests', route: 'district.investiture-requests', icon: FolderOpenIcon },
                    ] : []),
                ],
            },
        ]
    }

    return [
        {
            id: 'catalogs',
            title: t('catalogs'),
            items: [
                { id: 'dashboard', name: t('dashboard'), href: '/union/dashboard', route: 'union.dashboard', icon: HomeIcon },
                { id: 'associations', name: t('associations'), href: '/union/associations', route: 'union.associations', icon: MapIcon },
                { id: 'clubs_classes', name: t('clubs_classes'), href: '/union/catalog/clubs-classes', route: 'union.catalog', icon: Squares2X2Icon },
                { id: 'investiture_folder', name: t('investiture_folder'), href: '/union/carpeta-builder', route: 'union.carpeta-builder', icon: FolderOpenIcon },
                { id: 'workplan', name: t('workplan'), href: '/union/workplan', route: 'union.workplan', icon: ClipboardDocumentListIcon },
                { id: 'events', name: t('events'), href: '/events', route: 'events.index', icon: CalendarDaysIcon },
            ],
        },
        {
            id: 'reports',
            title: t('reports'),
            items: [
                { id: 'attendance_report', name: t('attendance_report'), href: '/union/reports/assistance', route: 'union.reports.assistance', icon: DocumentTextIcon },
                ...(evaluationSystem.value === 'carpetas' ? [
                    { id: 'requirements_reports', name: t('requirements_reports'), href: '/union/reports/progress', route: 'union.reports.progress', icon: ChartBarIcon },
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
        <div v-for="section in sections" :key="section.id" class="space-y-1">
            <div v-if="!isCollapsed" class="px-2 pt-2 text-[11px] font-semibold uppercase tracking-wide text-gray-400">
                {{ section.title }}
            </div>
            <Link
                v-for="item in section.items"
                :key="item.id"
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
