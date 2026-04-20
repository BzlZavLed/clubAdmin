<script setup>
import PathfinderLayout from '@/Layouts/PathfinderLayout.vue'
import HierarchyScopeWidget from '@/Components/HierarchyScopeWidget.vue'
import { usePage } from '@inertiajs/vue3'
import { useLocale } from '@/Composables/useLocale'

const page = usePage()
const user = page.props.auth?.user ?? null
const scope = page.props.auth?.effective_scope_summary ?? user?.scope_summary ?? null
const effectiveRole = page.props.auth?.effective_role ?? user?.effective_role ?? user?.role_key ?? user?.profile_type ?? null
const { tr } = useLocale()
</script>

<template>
    <PathfinderLayout>
        <div class="space-y-6">
            <div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
                <h2 class="text-lg font-semibold text-gray-900">{{ tr('Distrito', 'District') }}</h2>
                <p class="mt-2 text-sm text-gray-600">
                    {{ tr('Acceso inicial habilitado para roles distritales. La definicion funcional detallada sigue pendiente.', 'Initial access is enabled for district roles. Detailed functional definition is still pending.') }}
                </p>

                <div class="mt-4 space-y-2 text-sm text-gray-700">
                    <div><span class="font-medium">{{ tr('Usuario:', 'User:') }}</span> {{ user?.name || '—' }}</div>
                    <div><span class="font-medium">{{ tr('Rol:', 'Role:') }}</span> {{ effectiveRole || '—' }}</div>
                    <div><span class="font-medium">{{ tr('Union:', 'Union:') }}</span> {{ scope?.union_name || '—' }}</div>
                    <div><span class="font-medium">{{ tr('Asociación:', 'Association:') }}</span> {{ scope?.association_name || '—' }}</div>
                    <div><span class="font-medium">{{ tr('Distrito:', 'District:') }}</span> {{ scope?.district_name || scope?.name || '—' }}</div>
                    <div><span class="font-medium">{{ tr('Iglesia:', 'Church:') }}</span> {{ scope?.church_name || '—' }}</div>
                    <div><span class="font-medium">{{ tr('Club:', 'Club:') }}</span> {{ scope?.club_name || '—' }}</div>
                    <div><span class="font-medium">{{ tr('Nombre del scope:', 'Scope name:') }}</span> {{ scope?.name || '—' }}</div>
                    <div><span class="font-medium">{{ tr('Iglesias accesibles:', 'Accessible churches:') }}</span> {{ user?.accessible_church_count ?? 0 }}</div>
                    <div><span class="font-medium">{{ tr('Clubes accesibles:', 'Accessible clubs:') }}</span> {{ user?.accessible_club_count ?? 0 }}</div>
                </div>

                <div class="mt-6 flex flex-wrap gap-3">
                    <a :href="route('district.reports.assistance')" class="inline-flex items-center rounded bg-gray-800 px-4 py-2 text-sm font-medium text-white hover:bg-gray-900">
                        {{ tr('Reporte de asistencia', 'Attendance report') }}
                    </a>
                    <a :href="route('district.reports.finances')" class="inline-flex items-center rounded border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                        {{ tr('Reporte financiero', 'Financial report') }}
                    </a>
                </div>
            </div>
            <HierarchyScopeWidget />
        </div>
    </PathfinderLayout>
</template>
