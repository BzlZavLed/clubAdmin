<script setup>
import PathfinderLayout from '@/Layouts/PathfinderLayout.vue'
import { useForm } from '@inertiajs/vue3'

const props = defineProps({
    clubs: Array,
})

const form = useForm({
    club_id: props.clubs?.[0]?.id || '',
    title: '',
    event_type: '',
    start_at: '',
    end_at: '',
    timezone: 'America/New_York',
    location_name: '',
    location_address: '',
    status: 'draft',
    budget_estimated_total: '',
    budget_actual_total: '',
    requires_approval: false,
    is_payable: false,
    payment_amount: '',
    risk_level: '',
})

const submit = () => {
    form.post(route('events.store'))
}
</script>

<template>
    <PathfinderLayout>
        <template #title>Create Event</template>

        <div class="bg-white rounded-lg border p-6 space-y-4">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="text-sm text-gray-600">Club</label>
                    <select v-model="form.club_id" class="w-full border rounded px-3 py-2 text-sm">
                        <option v-for="club in clubs" :key="club.id" :value="club.id">{{ club.club_name }}</option>
                    </select>
                </div>
                <div>
                    <label class="text-sm text-gray-600">Title</label>
                    <input v-model="form.title" class="w-full border rounded px-3 py-2 text-sm" />
                </div>
                <div>
                    <label class="text-sm text-gray-600">Event Type</label>
                    <input v-model="form.event_type" class="w-full border rounded px-3 py-2 text-sm" placeholder="camp, fundraiser..." />
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
                    <label class="text-sm text-gray-600">Location Name (optional)</label>
                    <input v-model="form.location_name" class="w-full border rounded px-3 py-2 text-sm" placeholder="Optional" />
                </div>
                <div>
                    <label class="text-sm text-gray-600">Location Address (optional)</label>
                    <input v-model="form.location_address" class="w-full border rounded px-3 py-2 text-sm" placeholder="Optional" />
                </div>
            </div>
            <div class="flex items-center gap-3">
                <label class="text-sm text-gray-600">Requires approval</label>
                <input type="checkbox" v-model="form.requires_approval" />
            </div>
            <div class="flex items-center gap-6">
                <label class="text-sm text-gray-600 flex items-center gap-2">
                    <input type="checkbox" v-model="form.is_payable" />
                    Payable event
                </label>
                <div class="flex items-center gap-2">
                    <label class="text-sm text-gray-600">Fee amount</label>
                    <input
                        v-model="form.payment_amount"
                        type="number"
                        step="0.01"
                        min="0"
                        :disabled="!form.is_payable"
                        class="w-32 border rounded px-3 py-2 text-sm disabled:bg-gray-100"
                        placeholder="0.00"
                    />
                </div>
            </div>
            <button @click="submit" class="px-4 py-2 bg-blue-600 text-white rounded text-sm" :disabled="form.processing">
                {{ form.processing ? 'Saving...' : 'Create Event' }}
            </button>
        </div>
    </PathfinderLayout>
</template>
