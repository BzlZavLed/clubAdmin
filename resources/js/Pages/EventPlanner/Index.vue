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
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
                <div>
                    <div class="flex flex-wrap items-center gap-3">
                        <span class="inline-flex items-center rounded-full bg-amber-100 px-3 py-1 text-xs font-medium text-amber-800">
                            WIP beta version
                        </span>
                    </div>
                    <div class="mt-1 text-gray-600">{{ tr('Administra los eventos de tu club y planes asistidos por IA.', 'Manage your club events and AI-assisted plans.') }}</div>
                </div>
                <Link :href="route('events.create')" class="px-4 py-2 bg-blue-600 text-white rounded text-sm">{{ tr('Crear evento', 'Create Event') }}</Link>
            </div>

            <div class="bg-white rounded-lg border p-4">
                <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
                    <select v-model="filters.status" class="border rounded px-3 py-2 text-sm">
                        <option value="">{{ tr('Todos los estados', 'All statuses') }}</option>
                        <option value="draft">{{ tr('Borrador', 'Draft') }}</option>
                        <option value="plan_finalized">{{ tr('Plan finalizado', 'Plan finalized') }}</option>
                        <option value="ongoing">{{ tr('En curso', 'Ongoing') }}</option>
                        <option value="past">{{ tr('Pasado', 'Past') }}</option>
                    </select>
                    <input v-model="filters.event_type" class="border rounded px-3 py-2 text-sm" :placeholder="tr('Tipo de evento', 'Event type')" />
                    <input v-model="filters.start_from" type="date" class="border rounded px-3 py-2 text-sm" />
                    <input v-model="filters.start_to" type="date" class="border rounded px-3 py-2 text-sm" />
                </div>
                <div class="mt-3">
                    <button @click="applyFilters" class="px-4 py-2 bg-gray-800 text-white rounded text-sm">{{ tr('Aplicar filtros', 'Apply filters') }}</button>
                </div>
            </div>

            <div class="overflow-x-auto bg-white rounded-lg border">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50 text-gray-600">
                        <tr>
                            <th class="text-left px-4 py-2">{{ tr('Título', 'Title') }}</th>
                            <th class="text-left px-4 py-2">{{ tr('Tipo', 'Type') }}</th>
                            <th class="text-left px-4 py-2">{{ tr('Inicio', 'Start') }}</th>
                            <th class="text-left px-4 py-2">{{ tr('Estado', 'Status') }}</th>
                            <th class="text-left px-4 py-2">{{ tr('Pendientes', 'Missing Items') }}</th>
                            <th class="text-right px-4 py-2">{{ tr('Acciones', 'Actions') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="event in events.data" :key="event.id" class="border-t">
                            <td class="px-4 py-2 font-medium text-gray-800">{{ event.title }}</td>
                            <td class="px-4 py-2">{{ event.event_type }}</td>
                            <td class="px-4 py-2">{{ new Date(event.start_at).toLocaleDateString() }}</td>
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
                            <td colspan="6" class="px-4 py-6 text-center text-gray-500">{{ tr('Aún no hay eventos.', 'No events yet.') }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </PathfinderLayout>
</template>
