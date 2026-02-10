<script setup>
import PathfinderLayout from '@/Layouts/PathfinderLayout.vue'
import { useForm } from '@inertiajs/vue3'

const props = defineProps({
    event: Object,
})

const form = useForm({
    title: props.event.title,
    event_type: props.event.event_type,
    start_at: props.event.start_at?.slice(0, 16),
    end_at: props.event.end_at ? props.event.end_at.slice(0, 16) : '',
    timezone: props.event.timezone,
    location_name: props.event.location_name,
    location_address: props.event.location_address,
    status: props.event.status,
    budget_estimated_total: props.event.budget_estimated_total,
    budget_actual_total: props.event.budget_actual_total,
    requires_approval: props.event.requires_approval,
    risk_level: props.event.risk_level,
})

const submit = () => {
    form.put(route('events.update', props.event.id))
}
</script>

<template>
    <PathfinderLayout>
        <template #title>Edit Event</template>

        <div class="bg-white rounded-lg border p-6 space-y-4">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="text-sm text-gray-600">Title</label>
                    <input v-model="form.title" class="w-full border rounded px-3 py-2 text-sm" />
                </div>
                <div>
                    <label class="text-sm text-gray-600">Event Type</label>
                    <input v-model="form.event_type" class="w-full border rounded px-3 py-2 text-sm" />
                </div>
                <div>
                    <label class="text-sm text-gray-600">Start</label>
                    <input v-model="form.start_at" type="datetime-local" class="w-full border rounded px-3 py-2 text-sm" />
                </div>
                <div>
                    <label class="text-sm text-gray-600">End</label>
                    <input v-model="form.end_at" type="datetime-local" class="w-full border rounded px-3 py-2 text-sm" />
                </div>
                <div>
                    <label class="text-sm text-gray-600">Timezone</label>
                    <input v-model="form.timezone" class="w-full border rounded px-3 py-2 text-sm" />
                </div>
                <div>
                    <label class="text-sm text-gray-600">Location Name</label>
                    <input v-model="form.location_name" class="w-full border rounded px-3 py-2 text-sm" />
                </div>
                <div>
                    <label class="text-sm text-gray-600">Location Address</label>
                    <input v-model="form.location_address" class="w-full border rounded px-3 py-2 text-sm" />
                </div>
                <div>
                    <label class="text-sm text-gray-600">Status</label>
                    <input v-model="form.status" class="w-full border rounded px-3 py-2 text-sm" />
                </div>
            </div>
            <button @click="submit" class="px-4 py-2 bg-blue-600 text-white rounded text-sm" :disabled="form.processing">
                {{ form.processing ? 'Saving...' : 'Save Changes' }}
            </button>
        </div>
    </PathfinderLayout>
</template>
