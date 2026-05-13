<script setup>
import PathfinderLayout from '@/Layouts/PathfinderLayout.vue'
import { useLocale } from '@/Composables/useLocale'
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
const { tr } = useLocale()

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
        <template #title>{{ tr('Logs de AI', 'AI logs') }}</template>

        <div class="space-y-4 px-3 sm:px-4 lg:px-0">
            <div class="rounded-lg border bg-white p-4 shadow-sm">
                <div class="flex flex-col gap-4 md:flex-row md:items-end">
                    <div class="w-full md:w-auto">
                        <label class="mb-1 block text-xs font-medium text-gray-600">{{ tr('Estado', 'Status') }}</label>
                        <select v-model="filterForm.status" class="w-full rounded border px-3 py-3 text-base md:w-auto md:py-2 md:text-sm">
                            <option value="">{{ tr('Todos', 'All') }}</option>
                            <option value="success">{{ tr('Exitoso', 'Success') }}</option>
                            <option value="error">Error</option>
                            <option value="pending">{{ tr('Pendiente', 'Pending') }}</option>
                        </select>
                    </div>
                    <div class="w-full md:w-auto">
                        <label class="mb-1 block text-xs font-medium text-gray-600">Source</label>
                        <select v-model="filterForm.source" class="w-full rounded border px-3 py-3 text-base md:w-auto md:py-2 md:text-sm">
                            <option value="">{{ tr('Todos', 'All') }}</option>
                            <option v-for="source in sources" :key="source" :value="source">{{ source }}</option>
                        </select>
                    </div>
                </div>
            </div>

            <div class="overflow-hidden rounded-lg border bg-white shadow-sm">
                <div v-if="!logs.data.length" class="px-4 py-8 text-center text-sm text-gray-500">
                    {{ tr('No hay logs de AI.', 'There are no AI logs.') }}
                </div>

                <div v-else class="space-y-3 p-3 md:hidden">
                    <article v-for="log in logs.data" :key="`mobile-${log.id}`" class="rounded-lg border border-gray-200 p-4">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <h3 class="break-words font-semibold text-gray-900">{{ log.event?.title || log.source || `#${log.id}` }}</h3>
                                <p class="mt-1 text-xs text-gray-500">{{ log.created_at }} • #{{ log.id }}</p>
                            </div>
                            <span
                                class="shrink-0 rounded-full px-2 py-0.5 text-xs font-semibold"
                                :class="{
                                    'bg-green-100 text-green-800': log.status === 'success',
                                    'bg-red-100 text-red-800': log.status === 'error',
                                    'bg-amber-100 text-amber-800': log.status === 'pending',
                                }"
                            >
                                {{ log.status }}
                            </span>
                        </div>
                        <dl class="mt-3 grid grid-cols-1 gap-2 text-sm sm:grid-cols-2">
                            <div class="rounded bg-gray-50 p-2">
                                <dt class="text-xs text-gray-500">Source</dt>
                                <dd class="break-words text-gray-900">{{ log.source || '—' }}</dd>
                            </div>
                            <div class="rounded bg-gray-50 p-2">
                                <dt class="text-xs text-gray-500">Club</dt>
                                <dd class="break-words text-gray-900">{{ log.club?.club_name || '—' }}</dd>
                            </div>
                            <div class="rounded bg-gray-50 p-2">
                                <dt class="text-xs text-gray-500">Model</dt>
                                <dd class="break-words text-gray-900">{{ log.provider }} / {{ log.model }}</dd>
                            </div>
                            <div class="rounded bg-gray-50 p-2">
                                <dt class="text-xs text-gray-500">Tokens</dt>
                                <dd class="text-gray-900">{{ log.total_tokens ?? '—' }} total</dd>
                            </div>
                        </dl>
                        <p v-if="log.error_message" class="mt-3 rounded border border-red-100 bg-red-50 p-2 text-xs text-red-700">
                            {{ log.error_message }}
                        </p>
                        <details class="mt-3 rounded border border-gray-200 bg-gray-50 p-3">
                            <summary class="cursor-pointer text-sm font-medium text-blue-700">{{ tr('Ver prompt y respuesta', 'View prompt and response') }}</summary>
                            <div class="mt-3 space-y-3">
                                <div>
                                    <p class="mb-1 text-xs font-semibold uppercase tracking-wide text-gray-500">Prompt</p>
                                    <pre class="max-h-72 overflow-auto rounded border bg-white p-3 text-xs whitespace-pre-wrap">{{ log.prompt || JSON.stringify(log.request_json, null, 2) }}</pre>
                                </div>
                                <div>
                                    <p class="mb-1 text-xs font-semibold uppercase tracking-wide text-gray-500">Response</p>
                                    <pre class="max-h-72 overflow-auto rounded border bg-white p-3 text-xs whitespace-pre-wrap">{{ JSON.stringify(log.response_json, null, 2) }}</pre>
                                </div>
                            </div>
                        </details>
                    </article>
                </div>

                <div v-if="logs.data.length" class="hidden overflow-x-auto md:block">
                    <table class="min-w-full text-sm">
                        <thead class="bg-gray-50">
                            <tr class="text-left text-gray-600">
                                <th class="px-4 py-3 font-medium">{{ tr('Fecha', 'Date') }}</th>
                                <th class="px-4 py-3 font-medium">Source</th>
                                <th class="px-4 py-3 font-medium">Event</th>
                                <th class="px-4 py-3 font-medium">Club</th>
                                <th class="px-4 py-3 font-medium">Model</th>
                                <th class="px-4 py-3 font-medium">{{ tr('Estado', 'Status') }}</th>
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
                                    <div>{{ tr('Total', 'Total') }}: {{ log.total_tokens ?? '—' }}</div>
                                    <div class="text-xs text-gray-500">
                                        In: {{ log.input_tokens ?? '—' }} / Out: {{ log.output_tokens ?? '—' }}
                                    </div>
                                </td>
                            </tr>
                            <tr v-for="log in logs.data" :key="`detail-${log.id}`" class="border-t bg-gray-50">
                                <td colspan="7" class="px-4 py-3">
                                    <details>
                                        <summary class="cursor-pointer text-sm font-medium text-blue-700">{{ tr('Ver prompt y respuesta', 'View prompt and response') }}</summary>
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
