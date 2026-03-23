<script setup>
import PathfinderLayout from '@/Layouts/PathfinderLayout.vue'
import { computed, ref } from 'vue'

const props = defineProps({
    club: {
        type: Object,
        required: true,
    },
    classes: {
        type: Array,
        default: () => [],
    },
})

const itemLabelPlural = computed(() =>
    props.club?.club_type === 'adventurers' ? 'Honores' : 'Requisitos de investidura'
)

const itemLabelSingular = computed(() =>
    props.club?.club_type === 'adventurers' ? 'Honor' : 'Requisito'
)
const showPendingMembers = ref(false)

const totalRequirements = computed(() =>
    props.classes.reduce((sum, clubClass) => sum + (clubClass.requirements_count || 0), 0)
)

const totalCompletions = computed(() =>
    props.classes.reduce(
        (sum, clubClass) => sum + (clubClass.requirements || []).reduce((inner, requirement) => inner + (requirement.completed_count || 0), 0),
        0
    )
)

const formatDate = (value) => {
    if (!value) return '—'
    const date = new Date(`${value}T00:00:00`)
    return date.toLocaleDateString()
}

const getPendingMembers = (clubClass, requirement) => {
    const completedIds = new Set((requirement?.completions || []).map((entry) => Number(entry.member_id)))
    return (clubClass?.members || []).filter((member) => !completedIds.has(Number(member.id)))
}
</script>

<template>
    <PathfinderLayout>
        <div class="px-4 sm:px-6 lg:px-8 py-6 space-y-6">
            <div class="flex flex-col gap-2">
                <h1 class="text-xl font-semibold text-gray-900">
                    {{ itemLabelPlural }} por clase
                </h1>
                <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                    <p class="text-sm text-gray-600">
                        Club activo: <span class="font-medium text-gray-800">{{ club?.club_name || '—' }}</span>
                    </p>
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
                        <label class="inline-flex items-center gap-2 text-sm text-gray-700">
                            <input v-model="showPendingMembers" type="checkbox" class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                            Mostrar miembros pendientes
                        </label>
                        <a
                            :href="route('club.reports.investiture-requirements.pdf', { club_id: club?.id, show_pending: showPendingMembers ? 1 : 0 })"
                            class="inline-flex items-center justify-center rounded bg-gray-800 px-4 py-2 text-sm font-medium text-white hover:bg-gray-900"
                        >
                            Exportar PDF
                        </a>
                    </div>
                </div>
            </div>

            <section class="grid gap-4 md:grid-cols-3">
                <div class="rounded-lg border bg-white p-4 shadow-sm">
                    <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">Clases</div>
                    <div class="mt-2 text-2xl font-semibold text-gray-900">{{ classes.length }}</div>
                </div>
                <div class="rounded-lg border bg-white p-4 shadow-sm">
                    <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ itemLabelPlural }}</div>
                    <div class="mt-2 text-2xl font-semibold text-gray-900">{{ totalRequirements }}</div>
                </div>
                <div class="rounded-lg border bg-white p-4 shadow-sm">
                    <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">Cumplimientos registrados</div>
                    <div class="mt-2 text-2xl font-semibold text-gray-900">{{ totalCompletions }}</div>
                </div>
            </section>

            <section v-if="!classes.length" class="rounded-lg border bg-white p-6 text-sm text-gray-600 shadow-sm">
                No hay clases configuradas para el club activo.
            </section>

            <section v-for="clubClass in classes" :key="clubClass.id" class="rounded-lg border bg-white shadow-sm">
                <div class="border-b px-5 py-4">
                    <div class="flex flex-col gap-1 md:flex-row md:items-end md:justify-between">
                        <div>
                            <h2 class="text-lg font-semibold text-gray-900">
                                {{ clubClass.class_order ? `${clubClass.class_order}. ` : '' }}{{ clubClass.class_name }}
                            </h2>
                            <p class="text-sm text-gray-600">
                                {{ clubClass.members_count }} miembro(s) asignados, {{ clubClass.requirements_count }} {{ itemLabelPlural.toLowerCase() }}
                            </p>
                        </div>
                        <div class="text-sm text-gray-600">
                            {{ clubClass.completed_requirements_count }} {{ itemLabelPlural.toLowerCase() }} con al menos un cumplimiento
                        </div>
                    </div>
                </div>

                <div v-if="!clubClass.requirements.length" class="px-5 py-4 text-sm text-gray-500">
                    Esta clase no tiene {{ itemLabelPlural.toLowerCase() }} configurados.
                </div>

                <div v-else class="divide-y">
                    <div v-for="requirement in clubClass.requirements" :key="requirement.id" class="px-5 py-4">
                        <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                            <div class="min-w-0">
                                <div class="flex items-center gap-2">
                                    <h3 class="text-sm font-semibold text-gray-900">
                                        {{ requirement.sort_order ? `${requirement.sort_order}. ` : '' }}{{ requirement.title }}
                                    </h3>
                                    <span
                                        v-if="!requirement.is_active"
                                        class="inline-flex items-center rounded-full bg-gray-100 px-2 py-0.5 text-[11px] font-medium text-gray-700"
                                    >
                                        Inactivo
                                    </span>
                                </div>
                                <p v-if="requirement.description" class="mt-1 text-sm text-gray-600">
                                    {{ requirement.description }}
                                </p>
                            </div>

                            <div class="grid grid-cols-2 gap-3 text-sm lg:min-w-[260px]">
                                <div class="rounded border bg-gray-50 px-3 py-2">
                                    <div class="text-[11px] uppercase tracking-wide text-gray-500">Completados</div>
                                    <div class="font-semibold text-gray-900">{{ requirement.completed_count }}</div>
                                </div>
                                <div class="rounded border bg-gray-50 px-3 py-2">
                                    <div class="text-[11px] uppercase tracking-wide text-gray-500">Pendientes</div>
                                    <div class="font-semibold text-gray-900">{{ requirement.pending_count }}</div>
                                </div>
                            </div>
                        </div>

                        <div class="mt-4">
                            <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">
                                Miembros que han completado este {{ itemLabelSingular.toLowerCase() }}
                            </p>

                            <div v-if="!requirement.completions.length" class="mt-2 text-sm text-gray-500">
                                Nadie lo ha completado todavía.
                            </div>

                            <div v-else class="mt-3 overflow-x-auto">
                                <table class="min-w-full text-sm">
                                    <thead>
                                        <tr class="border-b text-left text-gray-500">
                                            <th class="pb-2 pr-4 font-medium">Miembro</th>
                                            <th class="pb-2 pr-4 font-medium">Fecha</th>
                                            <th class="pb-2 font-medium">Actividad</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr
                                            v-for="completion in requirement.completions"
                                            :key="`${requirement.id}-${completion.member_id}`"
                                            class="border-b last:border-b-0"
                                        >
                                            <td class="py-2 pr-4 text-gray-900">{{ completion.member_name }}</td>
                                            <td class="py-2 pr-4 text-gray-600">{{ formatDate(completion.date) }}</td>
                                            <td class="py-2 text-gray-600">{{ completion.activity_title || '—' }}</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <div v-if="showPendingMembers" class="mt-4">
                            <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">
                                Miembros pendientes
                            </p>
                            <div v-if="!getPendingMembers(clubClass, requirement).length" class="mt-2 text-sm text-gray-500">
                                No hay pendientes para este {{ itemLabelSingular.toLowerCase() }}.
                            </div>
                            <ul v-else class="mt-2 grid gap-2 md:grid-cols-2 xl:grid-cols-3">
                                <li
                                    v-for="member in getPendingMembers(clubClass, requirement)"
                                    :key="`${requirement.id}-pending-${member.id}`"
                                    class="rounded border bg-amber-50 px-3 py-2 text-sm text-amber-900"
                                >
                                    {{ member.name }}
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>
            </section>
        </div>
    </PathfinderLayout>
</template>
