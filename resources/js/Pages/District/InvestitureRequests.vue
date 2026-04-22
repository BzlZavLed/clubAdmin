<script setup>
import PathfinderLayout from '@/Layouts/PathfinderLayout.vue'
import { Link } from '@inertiajs/vue3'

defineProps({
    district: { type: Object, required: true },
    association: { type: Object, default: null },
    union: { type: Object, default: null },
    requests: { type: Array, default: () => [] },
})

const statusLabels = {
    assigned: 'Asignada',
    in_review: 'En revisión',
    completed: 'Completada',
    authorized: 'Autorizada por asociación',
    date_change_requested: 'Nueva fecha solicitada',
    returned: 'Devuelta',
}

const statusClass = (status) => ({
    assigned: 'bg-blue-50 text-blue-800 ring-blue-200',
    in_review: 'bg-indigo-50 text-indigo-800 ring-indigo-200',
    completed: 'bg-emerald-50 text-emerald-800 ring-emerald-200',
    authorized: 'bg-green-100 text-green-900 ring-green-200',
    date_change_requested: 'bg-amber-50 text-amber-800 ring-amber-200',
    returned: 'bg-rose-50 text-rose-800 ring-rose-200',
}[status] || 'bg-gray-50 text-gray-700 ring-gray-200')

const progressText = (request) => {
    if (!request.requirements_count) return 'Sin requisitos registrados'
    return `${request.completed_requirements_count || 0}/${request.requirements_count} requisitos cargados`
}
</script>

<template>
    <PathfinderLayout>
        <template #title>Evaluaciones de investidura</template>

        <div class="space-y-6">
            <section class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
                <div class="flex flex-col gap-4 md:flex-row md:items-start md:justify-between">
                    <div>
                        <p class="text-sm font-semibold uppercase tracking-wide text-gray-400">Distrito</p>
                        <h1 class="mt-1 text-2xl font-semibold text-gray-900">{{ district.name }}</h1>
                        <p class="mt-2 text-sm text-gray-600">
                            Aquí solo aparecen solicitudes que la asociación ya asignó formalmente al pastor distrital.
                        </p>
                    </div>
                    <div class="rounded-xl border border-gray-200 bg-gray-50 px-4 py-3 text-sm text-gray-700">
                        <p class="font-semibold text-gray-900">{{ district.pastor_name || 'Pastor distrital' }}</p>
                        <p>{{ district.pastor_email || 'Sin correo configurado' }}</p>
                    </div>
                </div>
            </section>

            <section class="grid gap-4 xl:grid-cols-2">
                <article
                    v-for="request in requests"
                    :key="request.id"
                    class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm"
                >
                    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                        <div>
                            <div class="flex flex-wrap items-center gap-2">
                                <h2 class="text-base font-semibold text-gray-900">Solicitud #{{ request.id }}</h2>
                                <span class="rounded-full px-2.5 py-1 text-xs font-semibold ring-1" :class="statusClass(request.status)">
                                    {{ statusLabels[request.status] || request.status }}
                                </span>
                            </div>
                            <p class="mt-1 text-sm text-gray-600">{{ request.club?.club_name || 'Club' }} · {{ request.club?.church_name || 'Iglesia' }}</p>
                            <p class="mt-1 text-sm text-gray-600">
                                Fecha tentativa: {{ request.tentative_investiture_date || '—' }}
                                <template v-if="request.approved_investiture_date">
                                    · Fecha autorizada: {{ request.approved_investiture_date }}
                                </template>
                            </p>
                            <p class="mt-2 text-xs uppercase tracking-wide text-gray-400">
                                Año {{ request.carpeta_year }} · {{ request.club_type }} · {{ request.members_count }} miembros
                            </p>
                        </div>
                        <p class="text-sm text-gray-500">Asignada: {{ request.assigned_at || '—' }}</p>
                    </div>

                    <div class="mt-4 grid gap-3 sm:grid-cols-2">
                        <div class="rounded-xl border border-gray-100 bg-gray-50 p-3 text-sm text-gray-700">
                            <p class="font-semibold text-gray-900">Progreso cargado</p>
                            <p class="mt-1">{{ progressText(request) }}</p>
                        </div>
                        <div class="rounded-xl border border-gray-100 bg-gray-50 p-3 text-sm text-gray-700">
                            <p class="font-semibold text-gray-900">{{ request.status === 'authorized' ? 'Autorización' : 'Siguiente paso' }}</p>
                            <p v-if="request.status === 'authorized'" class="mt-1 text-green-800">
                                La asociación autorizó esta investidura.
                                <span v-if="request.ceremony_representative_name">
                                    Representante: {{ request.ceremony_representative_name }}.
                                    <template v-if="request.ceremony_representative_email"> {{ request.ceremony_representative_email }}.</template>
                                    <template v-if="request.ceremony_representative_phone"> {{ request.ceremony_representative_phone }}.</template>
                                </span>
                            </p>
                            <p v-else class="mt-1">Revise la carpeta requisito por requisito.</p>
                        </div>
                    </div>

                    <div class="mt-4 flex justify-end">
                        <Link
                            :href="route('district.investiture-requests.show', request.id)"
                            class="inline-flex rounded-lg bg-gray-900 px-4 py-2 text-sm font-semibold text-white hover:bg-gray-800"
                        >
                            Evaluar carpeta
                        </Link>
                    </div>
                </article>

                <p v-if="!requests.length" class="rounded-2xl border border-dashed border-gray-300 bg-white px-5 py-8 text-sm text-gray-500">
                    No hay solicitudes de investidura asignadas a este distrito.
                </p>
            </section>
        </div>
    </PathfinderLayout>
</template>
