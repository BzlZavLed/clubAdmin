<script setup>
const props = defineProps({
    tasks: {
        type: Array,
        default: () => []
    }
})
</script>

<template>
    <div class="space-y-3 sm:hidden">
        <article v-for="task in tasks" :key="task.id" class="rounded-lg border bg-white p-4 shadow-sm">
            <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                    <div class="break-words text-sm font-semibold text-gray-900">{{ task.title }}</div>
                    <div v-if="task.description" class="mt-1 break-words text-xs text-gray-500">{{ task.description }}</div>
                </div>
                <span class="shrink-0 rounded-full bg-gray-100 px-2.5 py-1 text-xs font-semibold capitalize text-gray-700">
                    {{ task.status }}
                </span>
            </div>
            <div class="mt-3 text-xs text-gray-500">
                {{ task.due_at ? new Date(task.due_at).toLocaleDateString() : '—' }}
            </div>
        </article>
        <div v-if="!tasks.length" class="rounded-lg border border-dashed border-gray-200 bg-white p-6 text-center text-sm text-gray-500">
            No tasks yet.
        </div>
    </div>

    <div class="hidden overflow-x-auto bg-white rounded-lg border sm:block">
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
