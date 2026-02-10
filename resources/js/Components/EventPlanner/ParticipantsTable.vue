<script setup>
const props = defineProps({
    participants: {
        type: Array,
        default: () => []
    }
})
</script>

<template>
    <div class="overflow-x-auto bg-white rounded-lg border">
        <table class="min-w-full text-sm">
            <thead class="bg-gray-50 text-gray-600">
                <tr>
                    <th class="text-left px-4 py-2">Name</th>
                    <th class="text-left px-4 py-2">Role</th>
                    <th class="text-left px-4 py-2">Status</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="participant in participants" :key="participant.id" class="border-t">
                    <td class="px-4 py-2">{{ participant.participant_name }}</td>
                    <td class="px-4 py-2 capitalize">{{ participant.role }}</td>
                    <td class="px-4 py-2 capitalize">{{ participant.status }}</td>
                </tr>
                <tr v-if="!participants.length">
                    <td colspan="3" class="px-4 py-6 text-center text-gray-500">No participants yet.</td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
