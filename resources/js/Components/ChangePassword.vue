<script setup>
import { ref,onMounted } from 'vue'

const props = defineProps({
    show: Boolean,
    userId: {
        type: [Number, String],
        required: true
    }
})

const emit = defineEmits(['close', 'updated'])

const newPassword = ref('')

const updatePassword = async () => {
    try {
        const resp = await axios.put(`/users/${props.userId}/password`, {
            password: newPassword.value
        })
        emit('updated')
        emit('close')
        newPassword.value = ''
    } catch (err) {
        console.error('Password update error:', err)
        alert('Failed to update password')
    }
}
</script>

<template>
    <div v-if="show" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
        <div class="bg-white p-6 rounded shadow-lg w-full max-w-sm">
            <h2 class="text-lg font-semibold mb-4">Update Password</h2>
            <form @submit.prevent="updatePassword">
                <div class="mb-4">
                    <label class="block mb-1 text-sm">New Password</label>
                    <input v-model="newPassword" type="password" class="w-full border rounded px-3 py-2 text-sm" required />
                </div>
                <div class="flex justify-end gap-2">
                    <button type="button" @click="$emit('close')"
                        class="bg-gray-300 text-gray-800 px-3 py-1 rounded hover:bg-gray-400 text-sm">
                        Cancel
                    </button>
                    <button type="submit"
                        class="bg-blue-600 text-white px-3 py-1 rounded hover:bg-blue-700 text-sm">
                        Update
                    </button>
                </div>
            </form>
        </div>
    </div>
</template>
