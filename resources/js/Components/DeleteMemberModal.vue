<template>
<div v-if="show" class="fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-50">
    <div class="bg-white rounded-lg shadow-xl w-full max-w-lg p-6">
        <h2 class="text-lg font-bold mb-4">Confirm Deletion</h2>
        <p class="mb-2">Are you sure you want to delete <strong>{{ memberName }}</strong>?</p>

        <div class="mb-4">
            <label class="block mb-1 font-medium text-gray-700">Optional Note</label>
            <textarea v-model="notes" class="w-full border p-2 rounded" rows="3" placeholder="Reason for deletion (optional)"></textarea>
        </div>

        <div class="flex justify-end gap-2">
            <button @click="$emit('cancel')" class="px-4 py-2 rounded bg-gray-300 hover:bg-gray-400">Cancel</button>
            <button @click="confirmDelete" class="px-4 py-2 rounded bg-red-600 text-white hover:bg-red-700">Delete</button>
        </div>
    </div>
</div>
</template>

<script setup>
import { ref } from 'vue'
const props = defineProps(['show', 'memberId', 'memberName'])
const emit = defineEmits(['confirm', 'cancel'])

const notes = ref('')

const confirmDelete = () => {
    emit('confirm', { id: props.memberId, notes: notes.value })
}
</script>
