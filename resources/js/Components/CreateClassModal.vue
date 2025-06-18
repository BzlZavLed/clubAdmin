<template>
<div class="fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-50">
    <div class="bg-white rounded-lg shadow-lg w-full max-w-lg p-6">
        <h2 class="text-xl font-semibold mb-4">Create New Class</h2>

        <form @submit.prevent="submit">
            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700">Class Order</label>
                <input v-model="form.class_order" type="number" class="w-full mt-1 p-2 border rounded" />
                <p v-if="form.errors.class_order" class="text-red-500 text-sm">{{ form.errors.class_order }}</p>
            </div>

            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700">Class Name</label>
                <input v-model="form.class_name" type="text" class="w-full mt-1 p-2 border rounded" />
                <p v-if="form.errors.class_name" class="text-red-500 text-sm">{{ form.errors.class_name }}</p>
            </div>

            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700">Assign Staff</label>
                <select v-model="form.assigned_staff_id" class="w-full mt-1 p-2 border rounded">
                    <option value="">-- Select Staff --</option>
                    <option v-for="staff in staffList" :key="staff.id" :value="staff.id">
                        {{ staff.name }}
                    </option>
                </select>
                <p v-if="form.errors.assigned_staff_id" class="text-red-500 text-sm">{{ form.errors.assigned_staff_id }}</p>
            </div>

            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700">Select Club</label>
                <select v-model="form.club_id" class="w-full mt-1 p-2 border rounded">
                    <option value="">-- Select Club --</option>
                    <option v-for="club in clubList" :key="club.id" :value="club.id">
                        {{ club.club_name }}
                    </option>
                </select>
                <p v-if="form.errors.club_id" class="text-red-500 text-sm">{{ form.errors.club_id }}</p>
            </div>

            <div class="flex justify-end space-x-2 mt-6">
                <button type="button" @click="$emit('close')" class="px-4 py-2 bg-gray-300 rounded hover:bg-gray-400">
                    Cancel
                </button>
                <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700">
                    Create
                </button>
            </div>
        </form>
    </div>
</div>
</template>

  
  
<script setup>
import { useForm } from '@inertiajs/vue3'
import { defineProps, defineEmits } from 'vue'

const props = defineProps({
    clubs: Array,
    staff: Array
})

const emit = defineEmits(['close', 'created'])

const clubList = props.clubs || []
const staffList = props.staff || []

const form = useForm({
    class_order: '',
    class_name: '',
    assigned_staff_id: '',
    club_id: ''
})

const submit = () => {
    // Placeholder until backend is connected
    emit('created', form)
    emit('close')
}
</script>
