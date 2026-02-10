<script setup>
import PathfinderLayout from '@/Layouts/PathfinderLayout.vue'
import { Link, router } from '@inertiajs/vue3'
import { reactive } from 'vue'

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

const applyFilters = () => {
    router.get(route('events.index'), filters, { preserveState: true, replace: true })
}
</script>

<template>
    <PathfinderLayout>
        <template #title>Event Planner</template>

        <div class="space-y-6">
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
                <div class="text-gray-600">Manage your club events and AI-assisted plans.</div>
                <Link :href="route('events.create')" class="px-4 py-2 bg-blue-600 text-white rounded text-sm">Create Event</Link>
            </div>

            <div class="bg-white rounded-lg border p-4">
                <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
                    <input v-model="filters.status" class="border rounded px-3 py-2 text-sm" placeholder="Status" />
                    <input v-model="filters.event_type" class="border rounded px-3 py-2 text-sm" placeholder="Event type" />
                    <input v-model="filters.start_from" type="date" class="border rounded px-3 py-2 text-sm" />
                    <input v-model="filters.start_to" type="date" class="border rounded px-3 py-2 text-sm" />
                </div>
                <div class="mt-3">
                    <button @click="applyFilters" class="px-4 py-2 bg-gray-800 text-white rounded text-sm">Apply filters</button>
                </div>
            </div>

            <div class="overflow-x-auto bg-white rounded-lg border">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50 text-gray-600">
                        <tr>
                            <th class="text-left px-4 py-2">Title</th>
                            <th class="text-left px-4 py-2">Type</th>
                            <th class="text-left px-4 py-2">Start</th>
                            <th class="text-left px-4 py-2">Status</th>
                            <th class="text-left px-4 py-2">Missing Items</th>
                            <th class="text-right px-4 py-2">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="event in events.data" :key="event.id" class="border-t">
                            <td class="px-4 py-2 font-medium text-gray-800">{{ event.title }}</td>
                            <td class="px-4 py-2">{{ event.event_type }}</td>
                            <td class="px-4 py-2">{{ new Date(event.start_at).toLocaleDateString() }}</td>
                            <td class="px-4 py-2 capitalize">{{ event.status }}</td>
                            <td class="px-4 py-2">
                                {{ event.plan?.missing_items_json?.length || 0 }}
                            </td>
                            <td class="px-4 py-2 text-right">
                                <Link :href="route('events.show', event.id)" class="text-blue-600 text-sm">Open Plan</Link>
                            </td>
                        </tr>
                        <tr v-if="!events.data.length">
                            <td colspan="6" class="px-4 py-6 text-center text-gray-500">No events yet.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </PathfinderLayout>
</template>
