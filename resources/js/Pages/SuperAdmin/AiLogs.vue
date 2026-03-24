<script setup>
import PathfinderLayout from '@/Layouts/PathfinderLayout.vue'
import { router } from '@inertiajs/vue3'
import { reactive, watch } from 'vue'

const props = defineProps({
    logs: {
        type: Object,
        required: true,
    },
    filters: {
        type: Object,
        default: () => ({ status: '', source: '' }),
    },
    sources: {
        type: Array,
        default: () => [],
    },
})

const filterForm = reactive({
    status: props.filters?.status || '',
    source: props.filters?.source || '',
})

watch(
    () => [filterForm.status, filterForm.source],
    () => {
        router.get(route('superadmin.ai-logs.index'), {
            status: filterForm.status || undefined,
            source: filterForm.source || undefined,
        }, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        })
    }
)
</script>

<template>
    <PathfinderLayout>
        <template #title>Logs de AI</template>

        <div class="space-y-4">
            <div class="rounded-lg border bg-white p-4 shadow-sm">
                <div class="flex flex-col gap-4 md:flex-row md:items-end">
                    <div>
                        <label class="mb-1 block text-xs font-medium text-gray-600">Estado</label>
                        <select v-model="filterForm.status" class="rounded border px-3 py-2 text-sm">
                            <option value="">Todos</option>
                            <option value="success">Success</option>
                            <option value="error">Error</option>
                            <option value="pending">Pending</option>
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-gray-600">Source</label>
                        <select v-model="filterForm.source" class="rounded border px-3 py-2 text-sm">
                            <option value="">Todos</option>
                            <option v-for="source in sources" :key="source" :value="source">{{ source }}</option>
                        </select>
                    </div>
                </div>
            </div>

            <div class="rounded-lg border bg-white shadow-sm overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-gray-50">
                            <tr class="text-left text-gray-600">
                                <th class="px-4 py-3 font-medium">Fecha</th>
                                <th class="px-4 py-3 font-medium">Source</th>
                                <th class="px-4 py-3 font-medium">Event</th>
                                <th class="px-4 py-3 font-medium">Club</th>
                                <th class="px-4 py-3 font-medium">Model</th>
                                <th class="px-4 py-3 font-medium">Estado</th>
                                <th class="px-4 py-3 font-medium">Tokens</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="log in logs.data" :key="log.id" class="border-t align-top">
                                <td class="px-4 py-3 text-gray-700">
                                    <div>{{ log.created_at }}</div>
                                    <div class="text-xs text-gray-500">#{{ log.id }}</div>
                                </td>
                                <td class="px-4 py-3 text-gray-700">{{ log.source || '—' }}</td>
                                <td class="px-4 py-3 text-gray-700">
                                    <div>{{ log.event?.title || '—' }}</div>
                                    <div class="text-xs text-gray-500">{{ log.event?.event_type || '' }}</div>
                                </td>
                                <td class="px-4 py-3 text-gray-700">{{ log.club?.club_name || '—' }}</td>
                                <td class="px-4 py-3 text-gray-700">
                                    <div>{{ log.provider }}</div>
                                    <div class="text-xs text-gray-500">{{ log.model }}</div>
                                </td>
                                <td class="px-4 py-3">
                                    <span
                                        class="inline-flex rounded-full px-2 py-0.5 text-xs font-semibold"
                                        :class="{
                                            'bg-green-100 text-green-800': log.status === 'success',
                                            'bg-red-100 text-red-800': log.status === 'error',
                                            'bg-amber-100 text-amber-800': log.status === 'pending',
                                        }"
                                    >
                                        {{ log.status }}
                                    </span>
                                    <div v-if="log.error_message" class="mt-1 max-w-xs text-xs text-red-600">
                                        {{ log.error_message }}
                                    </div>
                                </td>
                                <td class="px-4 py-3 text-gray-700">
                                    <div>Total: {{ log.total_tokens ?? '—' }}</div>
                                    <div class="text-xs text-gray-500">
                                        In: {{ log.input_tokens ?? '—' }} / Out: {{ log.output_tokens ?? '—' }}
                                    </div>
                                </td>
                            </tr>
                            <tr v-for="log in logs.data" :key="`detail-${log.id}`" class="border-t bg-gray-50">
                                <td colspan="7" class="px-4 py-3">
                                    <details>
                                        <summary class="cursor-pointer text-sm font-medium text-blue-700">Ver prompt y respuesta</summary>
                                        <div class="mt-3 grid gap-4 lg:grid-cols-2">
                                            <div>
                                                <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500">Prompt</p>
                                                <pre class="max-h-80 overflow-auto rounded border bg-white p-3 text-xs whitespace-pre-wrap">{{ log.prompt || JSON.stringify(log.request_json, null, 2) }}</pre>
                                            </div>
                                            <div>
                                                <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500">Response</p>
                                                <pre class="max-h-80 overflow-auto rounded border bg-white p-3 text-xs whitespace-pre-wrap">{{ JSON.stringify(log.response_json, null, 2) }}</pre>
                                            </div>
                                        </div>
                                    </details>
                                </td>
                            </tr>
                            <tr v-if="!logs.data.length">
                                <td colspan="7" class="px-4 py-8 text-center text-sm text-gray-500">No hay logs de AI.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div v-if="logs.links?.length" class="flex flex-wrap items-center gap-2 border-t px-4 py-3">
                    <button
                        v-for="link in logs.links"
                        :key="link.label"
                        class="rounded border px-3 py-1 text-sm"
                        :class="link.active ? 'bg-blue-600 text-white border-blue-600' : 'bg-white text-gray-700'"
                        :disabled="!link.url"
                        @click="link.url && router.visit(link.url, { preserveScroll: true, preserveState: true })"
                        v-html="link.label"
                    />
                </div>
            </div>
        </div>
    </PathfinderLayout>
</template>
