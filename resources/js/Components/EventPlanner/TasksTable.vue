<script setup>
const props = defineProps({
    tasks: {
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
                    <th class="text-left px-4 py-2">Task</th>
                    <th class="text-left px-4 py-2">Status</th>
                    <th class="text-left px-4 py-2">Due</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="task in tasks" :key="task.id" class="border-t">
                    <td class="px-4 py-2">
                        <div class="font-medium text-gray-800">{{ task.title }}</div>
                        <div v-if="task.description" class="text-xs text-gray-500">{{ task.description }}</div>
                    </td>
                    <td class="px-4 py-2 capitalize">{{ task.status }}</td>
                    <td class="px-4 py-2">{{ task.due_at ? new Date(task.due_at).toLocaleDateString() : '—' }}</td>
                </tr>
                <tr v-if="!tasks.length">
                    <td colspan="3" class="px-4 py-6 text-center text-gray-500">No tasks yet.</td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
