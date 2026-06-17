<script setup>
import { computed, reactive, ref, watch } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import PathfinderLayout from '@/Layouts/PathfinderLayout.vue'
import { useLocale } from '@/Composables/useLocale'

const props = defineProps({
    logs: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    mail_keys: { type: Array, default: () => [] },
    summary: { type: Object, required: true },
})

const { tr } = useLocale()
const openLogIds = ref({})

const filterForm = reactive({
    status: props.filters?.status || '',
    mail_key: props.filters?.mail_key || '',
    search: props.filters?.search || '',
    month: props.filters?.month || new Date().toISOString().slice(0, 7),
})

let searchTimer = null
watch(
    () => ({ ...filterForm }),
    () => {
        window.clearTimeout(searchTimer)
        searchTimer = window.setTimeout(() => {
            router.get(route('superadmin.mail-logs.index'), {
                status: filterForm.status || undefined,
                mail_key: filterForm.mail_key || undefined,
                search: filterForm.search || undefined,
                month: filterForm.month || undefined,
            }, {
                preserveState: true,
                preserveScroll: true,
                replace: true,
            })
        }, 250)
    }
)

const usageTone = computed(() => {
    const percent = Number(props.summary?.usage_percent || 0)
    if (percent >= 90) return 'bg-rose-600'
    if (percent >= 75) return 'bg-amber-500'
    return 'bg-emerald-600'
})

const statusLabel = (status) => ({
    sent: tr('Enviado', 'Sent'),
    queued: tr('En cola', 'Queued'),
    failed: tr('Fallido', 'Failed'),
    manual_required: tr('Manual', 'Manual'),
})[status] || status || '—'

const statusClass = (status) => ({
    sent: 'bg-emerald-100 text-emerald-800',
    queued: 'bg-blue-100 text-blue-800',
    failed: 'bg-rose-100 text-rose-800',
    manual_required: 'bg-amber-100 text-amber-800',
})[status] || 'bg-gray-100 text-gray-700'

const displayDate = (log) => log.sent_at || log.failed_at || log.queued_at || log.created_at || '—'
const openSourceLabel = (source) => ({
    resend_webhook: tr('Webhook Resend', 'Resend webhook'),
    tracking_pixel: tr('Pixel local', 'Local pixel'),
})[source] || null
const openedLabel = (log) => log.opened_at
    ? tr(`Abierto ${log.open_count || 1} vez/veces`, `Opened ${log.open_count || 1} time(s)`)
    : tr('No abierto', 'Not opened')
const toggleLog = (logId) => {
    openLogIds.value = {
        ...openLogIds.value,
        [logId]: !openLogIds.value[logId],
    }
}
</script>

<template>
    <PathfinderLayout>
        <template #title>{{ tr('Correos enviados', 'Sent emails') }}</template>

        <div class="space-y-4 px-3 sm:px-4 lg:px-0">
            <section class="rounded-lg border bg-white p-4 shadow-sm">
                <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                    <div>
                        <p class="text-sm font-semibold uppercase tracking-wide text-gray-500">{{ tr('Uso mensual', 'Monthly usage') }}</p>
                        <div class="mt-1 flex flex-wrap items-baseline gap-x-2">
                            <span class="text-3xl font-semibold text-gray-950">{{ summary.billable_this_month }}</span>
                            <span class="text-sm text-gray-600">/ {{ summary.monthly_limit }} {{ tr('correos usados', 'emails used') }}</span>
                        </div>
                        <p class="mt-1 text-xs text-gray-500">
                            {{ summary.sent_this_month }} {{ tr('enviados', 'sent') }} · {{ summary.queued_this_month }} {{ tr('en cola', 'queued') }}
                        </p>
                    </div>
                    <div class="grid gap-2 text-sm sm:grid-cols-3 lg:min-w-[28rem]">
                        <div class="rounded-lg bg-gray-50 px-3 py-2">
                            <p class="text-xs text-gray-500">{{ tr('Disponibles', 'Remaining') }}</p>
                            <p class="font-semibold text-gray-950">{{ summary.remaining_this_month }}</p>
                        </div>
                        <div class="rounded-lg bg-gray-50 px-3 py-2">
                            <p class="text-xs text-gray-500">{{ tr('Fallidos', 'Failed') }}</p>
                            <p class="font-semibold text-rose-700">{{ summary.failed_this_month }}</p>
                        </div>
                        <div class="rounded-lg bg-gray-50 px-3 py-2">
                            <p class="text-xs text-gray-500">{{ tr('Manual', 'Manual') }}</p>
                            <p class="font-semibold text-amber-700">{{ summary.manual_required_this_month }}</p>
                        </div>
                    </div>
                </div>
                <div class="mt-4 h-3 overflow-hidden rounded-full bg-gray-100">
                    <div class="h-full rounded-full transition-all" :class="usageTone" :style="{ width: `${summary.usage_percent}%` }"></div>
                </div>
                <p class="mt-2 text-xs text-gray-500">
                    {{ summary.usage_percent }}% {{ tr('del limite configurado para', 'of the configured limit for') }} {{ summary.month }}.
                </p>
            </section>

            <section v-if="summary.by_mail_type.length" class="grid gap-3 sm:grid-cols-2">
                <article v-for="row in summary.by_mail_type" :key="row.mail_key" class="rounded-lg border bg-white p-4 shadow-sm">
                    <p class="text-sm font-semibold text-gray-900">{{ row.label }}</p>
                    <p class="mt-1 text-sm text-gray-500">{{ row.billable }} {{ tr('usados', 'used') }} · {{ row.sent }} {{ tr('enviados', 'sent') }} · {{ row.total }} {{ tr('intentos', 'attempts') }}</p>
                </article>
            </section>

            <section class="rounded-lg border bg-white p-4 shadow-sm">
                <div class="grid gap-3 md:grid-cols-4">
                    <div>
                        <label class="mb-1 block text-xs font-medium text-gray-600">{{ tr('Mes', 'Month') }}</label>
                        <input v-model="filterForm.month" type="month" class="w-full rounded border-gray-300 text-sm shadow-sm focus:border-red-500 focus:ring-red-500" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-gray-600">{{ tr('Estado', 'Status') }}</label>
                        <select v-model="filterForm.status" class="w-full rounded border-gray-300 text-sm shadow-sm focus:border-red-500 focus:ring-red-500">
                            <option value="">{{ tr('Todos', 'All') }}</option>
                            <option value="sent">{{ tr('Enviado', 'Sent') }}</option>
                            <option value="queued">{{ tr('En cola', 'Queued') }}</option>
                            <option value="failed">{{ tr('Fallido', 'Failed') }}</option>
                            <option value="manual_required">{{ tr('Manual requerido', 'Manual required') }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-gray-600">{{ tr('Tipo', 'Type') }}</label>
                        <select v-model="filterForm.mail_key" class="w-full rounded border-gray-300 text-sm shadow-sm focus:border-red-500 focus:ring-red-500">
                            <option value="">{{ tr('Todos', 'All') }}</option>
                            <option v-for="mailKey in mail_keys" :key="mailKey.value" :value="mailKey.value">{{ mailKey.label }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-gray-600">{{ tr('Buscar', 'Search') }}</label>
                        <input v-model="filterForm.search" type="search" class="w-full rounded border-gray-300 text-sm shadow-sm focus:border-red-500 focus:ring-red-500" :placeholder="tr('ID, correo, asunto, cuerpo, error', 'ID, email, subject, body, error')" />
                    </div>
                </div>
            </section>

            <section class="overflow-hidden rounded-lg border bg-white shadow-sm">
                <div v-if="!logs.data.length" class="px-4 py-8 text-center text-sm text-gray-500">
                    {{ tr('No hay correos registrados.', 'There are no email logs.') }}
                </div>

                <div v-else class="space-y-3 p-3 md:hidden">
                    <article v-for="log in logs.data" :key="`mobile-${log.id}`" class="rounded-lg border border-gray-200 p-4">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <h3 class="break-words font-semibold text-gray-900">{{ log.subject || log.label }}</h3>
                                <p class="mt-1 break-all text-xs text-gray-500">{{ log.recipient_email || tr('Sin destinatario', 'No recipient') }}</p>
                            </div>
                            <span class="shrink-0 rounded-full px-2 py-0.5 text-xs font-semibold" :class="statusClass(log.status)">
                                {{ statusLabel(log.status) }}
                            </span>
                        </div>
                        <dl class="mt-3 grid gap-2 text-sm">
                            <div class="rounded bg-gray-50 p-2">
                                <dt class="text-xs text-gray-500">{{ tr('Tipo', 'Type') }}</dt>
                                <dd class="text-gray-900">{{ log.label }}</dd>
                            </div>
                            <div class="rounded bg-gray-50 p-2">
                                <dt class="text-xs text-gray-500">{{ tr('ID de correo', 'Email ID') }}</dt>
                                <dd class="break-all font-mono text-xs text-gray-900">{{ log.email_uid || '—' }}</dd>
                                <dd v-if="log.provider_message_id" class="mt-1 break-all text-xs text-gray-500">
                                    {{ log.provider || 'provider' }}: {{ log.provider_message_id }}
                                </dd>
                            </div>
                            <div class="rounded bg-gray-50 p-2">
                                <dt class="text-xs text-gray-500">Club</dt>
                                <dd class="text-gray-900">{{ log.club?.club_name || '—' }}</dd>
                            </div>
                            <div class="rounded bg-gray-50 p-2">
                                <dt class="text-xs text-gray-500">{{ tr('Desde', 'From') }}</dt>
                                <dd class="break-all text-gray-900">{{ log.from_email || '—' }}</dd>
                            </div>
                            <div class="rounded bg-gray-50 p-2">
                                <dt class="text-xs text-gray-500">{{ tr('Origen', 'Source') }}</dt>
                                <dd class="text-gray-900">{{ log.source_label || '—' }}</dd>
                            </div>
                            <div class="rounded bg-gray-50 p-2">
                                <dt class="text-xs text-gray-500">{{ tr('Destino', 'Destination') }}</dt>
                                <dd class="text-gray-900">{{ log.destination_label || '—' }}</dd>
                            </div>
                            <div class="rounded bg-gray-50 p-2">
                                <dt class="text-xs text-gray-500">{{ tr('Fecha', 'Date') }}</dt>
                                <dd class="text-gray-900">{{ displayDate(log) }}</dd>
                            </div>
                            <div class="rounded bg-gray-50 p-2">
                                <dt class="text-xs text-gray-500">{{ tr('Apertura', 'Open') }}</dt>
                                <dd class="text-gray-900">{{ openedLabel(log) }}</dd>
                                <dd v-if="log.last_opened_at" class="text-xs text-gray-500">{{ log.last_opened_at }}</dd>
                                <dd v-if="openSourceLabel(log.open_source)" class="text-xs text-gray-500">{{ openSourceLabel(log.open_source) }}</dd>
                            </div>
                        </dl>
                        <details v-if="log.body_html || log.body_text" class="mt-3 rounded border border-gray-200 bg-gray-50 p-3">
                            <summary class="cursor-pointer text-sm font-semibold text-gray-800">{{ tr('Cuerpo del correo', 'Email body') }}</summary>
                            <div class="mt-3 rounded border bg-white p-3 text-sm text-gray-800" v-html="log.body_html || log.body_text"></div>
                        </details>
                        <p v-if="log.error_message" class="mt-3 rounded border border-rose-100 bg-rose-50 p-2 text-xs text-rose-700">
                            {{ log.error_message }}
                        </p>
                    </article>
                </div>

                <div v-else class="hidden overflow-x-auto md:block">
                    <table class="min-w-[96rem] table-fixed divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                            <tr>
                                <th class="w-36 px-4 py-3">{{ tr('Fecha', 'Date') }}</th>
                                <th class="w-64 px-4 py-3">{{ tr('ID de correo', 'Email ID') }}</th>
                                <th class="w-44 px-4 py-3">{{ tr('Tipo', 'Type') }}</th>
                                <th class="w-56 px-4 py-3">{{ tr('Desde', 'From') }}</th>
                                <th class="w-56 px-4 py-3">{{ tr('Destinatario', 'Recipient') }}</th>
                                <th class="w-60 px-4 py-3">{{ tr('Origen / destino', 'Source / destination') }}</th>
                                <th class="w-72 px-4 py-3">{{ tr('Asunto', 'Subject') }}</th>
                                <th class="w-44 px-4 py-3">Club</th>
                                <th class="w-44 px-4 py-3">{{ tr('Apertura', 'Open') }}</th>
                                <th class="w-32 px-4 py-3">{{ tr('Estado', 'Status') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <template v-for="log in logs.data" :key="log.id">
                                <tr class="align-top">
                                    <td class="whitespace-nowrap px-4 py-3 text-gray-600">{{ displayDate(log) }}</td>
                                    <td class="px-4 py-3">
                                        <div class="break-all font-mono text-xs leading-5 text-gray-700">{{ log.email_uid || '—' }}</div>
                                        <div v-if="log.provider_message_id" class="mt-1 break-all text-xs leading-5 text-gray-500">
                                            {{ log.provider || 'provider' }}: {{ log.provider_message_id }}
                                        </div>
                                    </td>
                                    <td class="px-4 py-3">
                                        <div class="font-medium text-gray-900">{{ log.label }}</div>
                                        <div class="text-xs text-gray-500">#{{ log.loggable_id }} {{ log.loggable_type }}</div>
                                    </td>
                                    <td class="px-4 py-3">
                                        <div class="max-w-xs break-all text-gray-900">{{ log.from_email || '—' }}</div>
                                        <div v-if="log.from_name" class="text-xs text-gray-500">{{ log.from_name }}</div>
                                    </td>
                                    <td class="px-4 py-3">
                                        <div class="max-w-xs break-all text-gray-900">{{ log.recipient_email || tr('Sin destinatario', 'No recipient') }}</div>
                                        <div v-if="log.user" class="text-xs text-gray-500">{{ log.user.name }}</div>
                                    </td>
                                    <td class="px-4 py-3">
                                        <div class="max-w-xs break-words text-gray-900">{{ log.source_label || '—' }}</div>
                                        <div class="max-w-xs break-words text-xs text-gray-500">{{ log.destination_label || '—' }}</div>
                                    </td>
                                    <td class="px-4 py-3">
                                        <div class="max-w-sm break-words text-gray-900">{{ log.subject || '—' }}</div>
                                        <div v-if="log.error_message" class="mt-1 max-w-sm break-words text-xs text-rose-700">{{ log.error_message }}</div>
                                        <button
                                            v-if="log.body_html || log.body_text"
                                            type="button"
                                            class="mt-2 text-xs font-semibold text-blue-700 hover:underline"
                                            @click="toggleLog(log.id)"
                                        >
                                            {{ openLogIds[log.id] ? tr('Ocultar cuerpo', 'Hide body') : tr('Ver cuerpo', 'View body') }}
                                        </button>
                                    </td>
                                    <td class="px-4 py-3 text-gray-600">{{ log.club?.club_name || '—' }}</td>
                                    <td class="px-4 py-3">
                                        <div class="text-gray-900">{{ openedLabel(log) }}</div>
                                        <div v-if="log.last_opened_at" class="text-xs text-gray-500">{{ log.last_opened_at }}</div>
                                        <div v-if="openSourceLabel(log.open_source)" class="text-xs text-gray-500">{{ openSourceLabel(log.open_source) }}</div>
                                        <div v-if="log.last_open_ip" class="text-xs text-gray-400">{{ log.last_open_ip }}</div>
                                    </td>
                                    <td class="px-4 py-3">
                                        <span class="rounded-full px-2 py-0.5 text-xs font-semibold" :class="statusClass(log.status)">
                                            {{ statusLabel(log.status) }}
                                        </span>
                                    </td>
                                </tr>
                                <tr v-if="openLogIds[log.id]" :key="`body-${log.id}`">
                                    <td colspan="10" class="border-t border-gray-100 bg-gray-50 px-4 py-4">
                                        <div class="rounded-lg border bg-white p-4">
                                            <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500">{{ tr('Cuerpo del correo', 'Email body') }}</p>
                                            <div class="prose prose-sm max-w-none text-gray-800" v-html="log.body_html || log.body_text"></div>
                                        </div>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>

                <div v-if="logs.links?.length" class="flex flex-wrap gap-2 border-t bg-gray-50 px-4 py-3">
                    <Link
                        v-for="link in logs.links"
                        :key="link.label"
                        :href="link.url || '#'"
                        preserve-scroll
                        class="rounded border px-3 py-1 text-sm"
                        :class="[
                            link.active ? 'border-red-600 bg-red-600 text-white' : 'border-gray-300 bg-white text-gray-700',
                            !link.url ? 'pointer-events-none opacity-50' : 'hover:bg-gray-100'
                        ]"
                        v-html="link.label"
                    />
                </div>
            </section>
        </div>
    </PathfinderLayout>
</template>
