<script setup>
import PathfinderLayout from '@/Layouts/PathfinderLayout.vue'
import { Link, router } from '@inertiajs/vue3'
import { reactive } from 'vue'
import { useLocale } from '@/Composables/useLocale'

const props = defineProps({
    events: Object,
    filters: Object,
})

const filters = reactive({
    status: props.filters?.status || '',
    event_type: props.filters?.event_type || '',
    start_from: props.filters?.start_from || '',
    start_to: props.filters?.start_to || '',
})
const { tr } = useLocale()

const eventStatusLabel = (status) => {
    switch (status) {
        case 'plan_finalized':
            return tr('Plan finalizado', 'Plan finalized')
        case 'ongoing':
            return tr('En curso', 'Ongoing')
        case 'past':
            return tr('Pasado', 'Past')
        case 'draft':
        default:
            return tr('Borrador', 'Draft')
    }
}

const eventStatusClass = (status) => {
    switch (status) {
        case 'plan_finalized':
            return 'bg-blue-50 text-blue-700'
        case 'ongoing':
            return 'bg-emerald-50 text-emerald-700'
        case 'past':
            return 'bg-gray-100 text-gray-700'
        case 'draft':
        default:
            return 'bg-amber-50 text-amber-700'
    }
}

const applyFilters = () => {
    router.get(route('events.index'), filters, { preserveState: true, replace: true })
}

const deleteEvent = (event) => {
    if (!window.confirm(tr('¿Eliminar este evento? Esta acción no se puede deshacer.', 'Delete this event? This action cannot be undone.'))) {
        return
    }

    router.delete(route('events.destroy', event.id), {
        preserveScroll: true,
    })
}
</script>

<template>
    <PathfinderLayout>
        <template #title>{{ tr('Planificador de Eventos', 'Event Planner') }}</template>

        <div class="space-y-6">
            <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                <div>
                    <div class="flex flex-wrap items-center gap-3">
                        <span class="inline-flex items-center rounded-full bg-blue-100 px-3 py-1 text-xs font-medium text-blue-800">
                            {{ tr('Eventos por jerarquía', 'Hierarchy events') }}
                        </span>
                    </div>
                    <div class="mt-1 text-gray-600">{{ tr('Administra eventos de club, iglesia, distrito, asociación y unión con planes asistidos por IA.', 'Manage club, church, district, association, and union events with AI-assisted plans.') }}</div>
                </div>
                <Link :href="route('events.create')" class="inline-flex w-full items-center justify-center rounded bg-blue-600 px-4 py-2 text-sm font-medium text-white sm:w-auto">
                    {{ tr('Crear evento', 'Create Event') }}
                </Link>
            </div>

            <div class="rounded-lg border bg-white p-4">
                <div class="grid grid-cols-1 gap-3 md:grid-cols-4">
                    <select v-model="filters.status" class="w-full rounded border px-3 py-2 text-sm">
                        <option value="">{{ tr('Todos los estados', 'All statuses') }}</option>
                        <option value="draft">{{ tr('Borrador', 'Draft') }}</option>
                        <option value="plan_finalized">{{ tr('Plan finalizado', 'Plan finalized') }}</option>
                        <option value="ongoing">{{ tr('En curso', 'Ongoing') }}</option>
                        <option value="past">{{ tr('Pasado', 'Past') }}</option>
                    </select>
                    <input v-model="filters.event_type" class="w-full rounded border px-3 py-2 text-sm" :placeholder="tr('Tipo de evento', 'Event type')" />
                    <input v-model="filters.start_from" type="date" class="w-full rounded border px-3 py-2 text-sm" />
                    <input v-model="filters.start_to" type="date" class="w-full rounded border px-3 py-2 text-sm" />
                </div>
                <div class="mt-3">
                    <button @click="applyFilters" class="w-full rounded bg-gray-800 px-4 py-2 text-sm font-medium text-white sm:w-auto">
                        {{ tr('Aplicar filtros', 'Apply filters') }}
                    </button>
                </div>
            </div>

            <div class="space-y-3 sm:hidden">
                <article
                    v-for="event in events.data"
                    :key="event.id"
                    class="rounded-lg border bg-white p-4 shadow-sm"
                >
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <h3 class="break-words text-sm font-semibold text-gray-900">{{ event.title }}</h3>
                            <p class="mt-1 text-xs text-gray-500">{{ event.scope_label || '—' }}</p>
                        </div>
                        <span class="shrink-0 rounded-full px-2.5 py-1 text-xs font-semibold" :class="eventStatusClass(event.effective_status || event.status)">
                            {{ eventStatusLabel(event.effective_status || event.status) }}
                        </span>
                    </div>

                    <dl class="mt-4 grid grid-cols-1 gap-2 text-xs text-gray-600">
                        <div class="flex items-start justify-between gap-3">
                            <dt class="font-medium text-gray-500">{{ tr('Tipo', 'Type') }}</dt>
                            <dd class="max-w-[60%] break-words text-right text-gray-800">{{ event.event_type || '—' }}</dd>
                        </div>
                        <div class="flex items-start justify-between gap-3">
                            <dt class="font-medium text-gray-500">{{ tr('Inicio', 'Start') }}</dt>
                            <dd class="text-right text-gray-800">{{ new Date(event.start_at).toLocaleDateString() }}</dd>
                        </div>
                        <div class="flex items-start justify-between gap-3">
                            <dt class="font-medium text-gray-500">{{ tr('Clubes', 'Clubs') }}</dt>
                            <dd class="text-right text-gray-800">{{ event.target_clubs?.length || 0 }}</dd>
                        </div>
                        <div v-if="event.target_clubs?.length" class="rounded bg-gray-50 px-3 py-2 text-gray-600">
                            {{ event.target_clubs.slice(0, 3).map((club) => club.club_name).join(', ') }}<span v-if="event.target_clubs.length > 3">…</span>
                        </div>
                        <div class="flex items-start justify-between gap-3">
                            <dt class="font-medium text-gray-500">{{ tr('Pendientes', 'Missing Items') }}</dt>
                            <dd class="text-right text-gray-800">{{ event.plan?.missing_items_json?.length || 0 }}</dd>
                        </div>
                    </dl>

                    <div class="mt-4 grid gap-2 border-t border-gray-100 pt-3">
                        <Link :href="route('events.show', event.id)" class="inline-flex items-center justify-center rounded border border-blue-200 px-3 py-2 text-sm font-medium text-blue-600 hover:bg-blue-50">
                            {{ tr('Abrir plan', 'Open Plan') }}
                        </Link>
                        <button
                            type="button"
                            class="rounded border border-red-200 px-3 py-2 text-sm font-medium text-red-600 hover:bg-red-50"
                            @click="deleteEvent(event)"
                        >
                            {{ tr('Eliminar', 'Delete') }}
                        </button>
                    </div>
                </article>

                <div v-if="!events.data.length" class="rounded-lg border border-dashed border-gray-200 bg-white p-6 text-center text-sm text-gray-500">
                    {{ tr('Aún no hay eventos.', 'No events yet.') }}
                </div>
            </div>

            <div class="hidden overflow-x-auto rounded-lg border bg-white sm:block">
                <table class="w-full min-w-[860px] text-sm">
                    <thead class="bg-gray-50 text-gray-600">
                        <tr>
                            <th class="text-left px-4 py-2">{{ tr('Título', 'Title') }}</th>
                            <th class="text-left px-4 py-2">{{ tr('Scope', 'Scope') }}</th>
                            <th class="text-left px-4 py-2">{{ tr('Tipo', 'Type') }}</th>
                            <th class="text-left px-4 py-2">{{ tr('Inicio', 'Start') }}</th>
                            <th class="text-left px-4 py-2">{{ tr('Clubes', 'Clubs') }}</th>
                            <th class="text-left px-4 py-2">{{ tr('Estado', 'Status') }}</th>
                            <th class="text-left px-4 py-2">{{ tr('Pendientes', 'Missing Items') }}</th>
                            <th class="text-right px-4 py-2">{{ tr('Acciones', 'Actions') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="event in events.data" :key="event.id" class="border-t">
                            <td class="px-4 py-2 font-medium text-gray-800">{{ event.title }}</td>
                            <td class="px-4 py-2">{{ event.scope_label || '—' }}</td>
                            <td class="px-4 py-2">{{ event.event_type }}</td>
                            <td class="px-4 py-2">{{ new Date(event.start_at).toLocaleDateString() }}</td>
                            <td class="px-4 py-2">
                                <div class="text-gray-700">{{ event.target_clubs?.length || 0 }}</div>
                                <div v-if="event.target_clubs?.length" class="text-xs text-gray-500">
                                    {{ event.target_clubs.slice(0, 2).map((club) => club.club_name).join(', ') }}<span v-if="event.target_clubs.length > 2">…</span>
                                </div>
                            </td>
                            <td class="px-4 py-2">
                                <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold" :class="eventStatusClass(event.effective_status || event.status)">
                                    {{ eventStatusLabel(event.effective_status || event.status) }}
                                </span>
                            </td>
                            <td class="px-4 py-2">
                                {{ event.plan?.missing_items_json?.length || 0 }}
                            </td>
                            <td class="px-4 py-2 text-right">
                                <div class="inline-flex items-center gap-3">
                                    <Link :href="route('events.show', event.id)" class="text-blue-600 text-sm">{{ tr('Abrir plan', 'Open Plan') }}</Link>
                                    <button
                                        type="button"
                                        class="text-red-600 text-sm"
                                        @click="deleteEvent(event)"
                                    >
                                        {{ tr('Eliminar', 'Delete') }}
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="!events.data.length">
                            <td colspan="8" class="px-4 py-6 text-center text-gray-500">{{ tr('Aún no hay eventos.', 'No events yet.') }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </PathfinderLayout>
</template>
