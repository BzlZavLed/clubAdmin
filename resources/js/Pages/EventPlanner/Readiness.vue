<script setup>
import { computed, ref } from 'vue'
import { Link } from '@inertiajs/vue3'
import PathfinderLayout from '@/Layouts/PathfinderLayout.vue'
import { useLocale } from '@/Composables/useLocale'

const props = defineProps({
    event: {
        type: Object,
        required: true,
    },
    readiness: {
        type: Object,
        required: true,
    },
})

const { tr } = useLocale()

const money = (value) => {
    const amount = Number(value || 0)
    return new Intl.NumberFormat(undefined, { style: 'currency', currency: 'USD' }).format(amount)
}

const dateText = (value) => {
    if (!value) return '—'
    return new Date(value).toLocaleString()
}

const statusClass = (status) => ({
    ready: 'border-emerald-200 bg-emerald-50 text-emerald-700',
    pending: 'border-amber-200 bg-amber-50 text-amber-700',
    blocked: 'border-rose-200 bg-rose-50 text-rose-700',
}[status] || 'border-gray-200 bg-gray-50 text-gray-700')

const severityClass = (severity) => ({
    blocking: 'border-rose-200 bg-rose-50 text-rose-700',
    pending: 'border-amber-200 bg-amber-50 text-amber-700',
}[severity] || 'border-gray-200 bg-gray-50 text-gray-700')

const closeoutReady = computed(() => props.readiness?.closeout?.status === 'ready_to_close')
const generatedAt = computed(() => dateText(props.readiness?.generated_at))
const financialReport = computed(() => props.readiness?.financial_report || { components: [], clubs: [], participants: [], totals: {} })
const financeComponents = computed(() => financialReport.value?.components || [])
const includeTargetedClubs = ref(true)
const includeFinancialBreakdownPdf = ref(true)
const visibleFinancialClubs = computed(() => {
    const clubs = financialReport.value?.clubs || []
    return includeTargetedClubs.value
        ? clubs
        : clubs.filter((club) => Number(club?.paid_amount || 0) > 0)
})
const visibleFinancialClubIds = computed(() => new Set(visibleFinancialClubs.value.map((club) => Number(club.club_id))))
const visibleFinancialParticipants = computed(() => {
    const participants = financialReport.value?.participants || []
    if (includeTargetedClubs.value) return participants

    return participants.filter((participant) => visibleFinancialClubIds.value.has(Number(participant.club_id)))
})
const visibleFinancialTotals = computed(() => ({
    clubs: visibleFinancialClubs.value.length,
    participants: visibleFinancialParticipants.value.length,
    expected_amount: visibleFinancialClubs.value.reduce((total, club) => total + Number(club?.expected_amount || 0), 0),
    paid_amount: visibleFinancialClubs.value.reduce((total, club) => total + Number(club?.paid_amount || 0), 0),
    pending_settlement_amount: visibleFinancialClubs.value.reduce((total, club) => total + Number(club?.pending_settlement_amount || 0), 0),
}))
const financialPdfUrl = computed(() => route('events.readiness.financial.pdf', {
    event: props.event.id,
    include_targeted: includeTargetedClubs.value ? 1 : 0,
    include_breakdown: includeFinancialBreakdownPdf.value ? 1 : 0,
}))

const componentAmount = (row, componentId) => {
    const amounts = row?.component_amounts || {}
    return amounts[String(componentId)] || amounts[componentId] || { paid_amount: 0, expected_amount: 0, remaining_amount: 0 }
}
</script>

<template>
    <PathfinderLayout>
        <template #title>{{ tr('Preparación del evento', 'Event Readiness') }}</template>

        <div class="space-y-6">
            <div class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
                <div>
                    <div class="text-sm text-gray-500">{{ tr('Evento', 'Event') }}</div>
                    <h1 class="text-2xl font-semibold text-gray-900">{{ event.title }}</h1>
                    <div class="mt-1 flex flex-wrap gap-2 text-sm text-gray-600">
                        <span>{{ event.event_type || '—' }}</span>
                        <span>·</span>
                        <span>{{ dateText(event.start_at) }}</span>
                        <span>·</span>
                        <span>{{ tr('Generado', 'Generated') }} {{ generatedAt }}</span>
                    </div>
                </div>
                <div class="flex flex-wrap gap-2">
                    <Link
                        :href="route('events.show', event.id)"
                        class="rounded border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
                    >
                        {{ tr('Volver al plan', 'Back to plan') }}
                    </Link>
                    <a
                        :href="route('events.readiness.pdf', { event: event.id })"
                        target="_blank"
                        rel="noopener"
                        class="rounded bg-indigo-600 px-3 py-2 text-sm font-semibold text-white hover:bg-indigo-700"
                    >
                        {{ tr('Exportar preparación PDF', 'Export readiness PDF') }}
                    </a>
                </div>
            </div>

            <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-5">
                <div class="rounded-lg border bg-white p-4">
                    <div class="text-xs font-semibold uppercase text-gray-500">{{ tr('Clubes', 'Clubs') }}</div>
                    <div class="mt-2 text-2xl font-semibold text-gray-900">{{ readiness.totals.clubs }}</div>
                </div>
                <div class="rounded-lg border bg-white p-4">
                    <div class="text-xs font-semibold uppercase text-gray-500">{{ tr('Preparación completa', 'Ready') }}</div>
                    <div class="mt-2 text-2xl font-semibold text-emerald-700">{{ readiness.totals.ready_clubs }}</div>
                </div>
                <div class="rounded-lg border bg-white p-4">
                    <div class="text-xs font-semibold uppercase text-gray-500">{{ tr('Pendientes por completar', 'Pending') }}</div>
                    <div class="mt-2 text-2xl font-semibold text-amber-700">{{ readiness.totals.pending_clubs }}</div>
                </div>
                <div class="rounded-lg border bg-white p-4">
                    <div class="text-xs font-semibold uppercase text-gray-500">{{ tr('Atención crítica', 'Critical attention') }}</div>
                    <div class="mt-2 text-2xl font-semibold text-rose-700">{{ readiness.totals.blocked_clubs }}</div>
                </div>
                <div class="rounded-lg border bg-white p-4">
                    <div class="text-xs font-semibold uppercase text-gray-500">{{ tr('Pendiente depositar', 'Pending deposit') }}</div>
                    <div class="mt-2 text-2xl font-semibold text-gray-900">{{ money(readiness.totals.pending_settlement_amount) }}</div>
                </div>
            </div>

            <div class="overflow-hidden rounded-lg border bg-white">
                <div class="flex flex-col gap-3 border-b px-4 py-3 xl:flex-row xl:items-center xl:justify-between">
                    <h2 class="text-base font-semibold text-gray-900">{{ tr('Reporte financiero del evento', 'Event financial report') }}</h2>
                    <div class="flex flex-col gap-2 lg:flex-row lg:items-center">
                        <label class="inline-flex items-center gap-2 text-sm text-gray-700">
                            <input
                                v-model="includeTargetedClubs"
                                type="checkbox"
                                class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
                            >
                            <span>{{ tr('Incluir clubes targeted sin pagos', 'Include targeted clubs without payments') }}</span>
                        </label>
                        <label class="inline-flex items-center gap-2 text-sm text-gray-700">
                            <input
                                v-model="includeFinancialBreakdownPdf"
                                type="checkbox"
                                class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
                            >
                            <span>{{ tr('PDF con desglose por miembros/staff', 'PDF with member/staff breakdown') }}</span>
                        </label>
                        <a
                            :href="financialPdfUrl"
                            target="_blank"
                            rel="noopener"
                            class="w-fit rounded border border-indigo-200 bg-indigo-50 px-3 py-2 text-sm font-semibold text-indigo-700 hover:bg-indigo-100"
                        >
                            {{ tr('Exportar financiero PDF', 'Export financial PDF') }}
                        </a>
                    </div>
                </div>
                <div class="grid gap-3 border-b bg-gray-50 px-4 py-3 sm:grid-cols-2 xl:grid-cols-5">
                    <div>
                        <div class="text-xs font-semibold uppercase text-gray-500">{{ tr('Clubes', 'Clubs') }}</div>
                        <div class="mt-1 text-lg font-semibold text-gray-900">{{ visibleFinancialTotals.clubs }}</div>
                    </div>
                    <div>
                        <div class="text-xs font-semibold uppercase text-gray-500">{{ tr('Participantes', 'Participants') }}</div>
                        <div class="mt-1 text-lg font-semibold text-gray-900">{{ visibleFinancialTotals.participants }}</div>
                    </div>
                    <div>
                        <div class="text-xs font-semibold uppercase text-gray-500">{{ tr('Esperado obligatorio', 'Required expected') }}</div>
                        <div class="mt-1 text-lg font-semibold text-gray-900">{{ money(visibleFinancialTotals.expected_amount) }}</div>
                    </div>
                    <div>
                        <div class="text-xs font-semibold uppercase text-gray-500">{{ tr('Pagado', 'Paid') }}</div>
                        <div class="mt-1 text-lg font-semibold text-gray-900">{{ money(visibleFinancialTotals.paid_amount) }}</div>
                    </div>
                    <div>
                        <div class="text-xs font-semibold uppercase text-gray-500">{{ tr('Pendiente depositar', 'Pending deposit') }}</div>
                        <div class="mt-1 text-lg font-semibold text-gray-900">{{ money(visibleFinancialTotals.pending_settlement_amount) }}</div>
                    </div>
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-gray-50 text-left text-gray-600">
                            <tr>
                                <th class="sticky left-0 z-10 bg-gray-50 px-4 py-3 font-medium">{{ tr('Club', 'Club') }}</th>
                                <th class="px-4 py-3 font-medium">{{ tr('Estado', 'Status') }}</th>
                                <th class="px-4 py-3 text-right font-medium">{{ tr('Esperado', 'Expected') }}</th>
                                <th class="px-4 py-3 text-right font-medium">{{ tr('Pagado', 'Paid') }}</th>
                                <th class="px-4 py-3 text-right font-medium">{{ tr('Pendiente', 'Pending') }}</th>
                                <th v-for="component in financeComponents" :key="`club-component-${component.id}`" class="min-w-[9rem] px-4 py-3 text-right font-medium">
                                    <div>{{ component.label }}</div>
                                    <div class="text-[11px] font-semibold" :class="component.is_required ? 'text-rose-600' : 'text-indigo-600'">
                                        {{ component.is_required ? tr('Obligatorio', 'Required') : tr('Opcional', 'Optional') }}
                                    </div>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="club in visibleFinancialClubs" :key="`finance-club-${club.club_id}`" class="border-t align-top">
                                <td class="sticky left-0 z-10 bg-white px-4 py-3">
                                    <div class="font-medium text-gray-900">{{ club.club_name }}</div>
                                    <div class="text-xs text-gray-500">{{ club.district_name || '—' }}</div>
                                </td>
                                <td class="px-4 py-3">
                                    <span class="inline-flex rounded-full border px-2 py-0.5 text-xs font-semibold" :class="statusClass(club.status)">
                                        {{ club.status_label }}
                                    </span>
                                    <div class="mt-1 text-xs text-gray-500">{{ club.signup_status }}</div>
                                </td>
                                <td class="px-4 py-3 text-right font-semibold text-gray-900">{{ money(club.expected_amount) }}</td>
                                <td class="px-4 py-3 text-right font-semibold text-gray-900">{{ money(club.paid_amount) }}</td>
                                <td class="px-4 py-3 text-right font-semibold text-gray-900">{{ money(club.pending_settlement_amount) }}</td>
                                <td v-for="component in financeComponents" :key="`finance-club-${club.club_id}-${component.id}`" class="px-4 py-3 text-right">
                                    <div class="font-semibold text-gray-900">{{ money(componentAmount(club, component.id).paid_amount) }}</div>
                                    <div v-if="Number(componentAmount(club, component.id).expected_amount || 0) > 0" class="text-xs text-gray-500">
                                        {{ tr('de', 'of') }} {{ money(componentAmount(club, component.id).expected_amount) }}
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="!visibleFinancialClubs.length">
                                <td :colspan="5 + financeComponents.length" class="px-4 py-8 text-center text-gray-500">
                                    {{ includeTargetedClubs ? tr('No hay clubes visibles para este reporte.', 'No visible clubs for this report.') : tr('No hay clubes con pagos registrados.', 'No clubs with recorded payments.') }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <details class="border-t">
                    <summary class="cursor-pointer list-none px-4 py-3 text-sm font-semibold text-gray-900">
                        {{ tr('Desglose por miembros y staff', 'Member and staff breakdown') }}
                    </summary>
                    <div class="overflow-x-auto border-t">
                        <table class="min-w-full text-sm">
                            <thead class="bg-gray-50 text-left text-gray-600">
                                <tr>
                                    <th class="sticky left-0 z-10 bg-gray-50 px-4 py-3 font-medium">{{ tr('Participante', 'Participant') }}</th>
                                    <th class="px-4 py-3 font-medium">{{ tr('Tipo', 'Type') }}</th>
                                    <th class="px-4 py-3 font-medium">{{ tr('Club', 'Club') }}</th>
                                    <th class="px-4 py-3 font-medium">{{ tr('Estado', 'Status') }}</th>
                                    <th class="px-4 py-3 text-right font-medium">{{ tr('Pagado', 'Paid') }}</th>
                                    <th v-for="component in financeComponents" :key="`participant-component-${component.id}`" class="min-w-[9rem] px-4 py-3 text-right font-medium">
                                        <div>{{ component.label }}</div>
                                        <div class="text-[11px] font-semibold" :class="component.is_required ? 'text-rose-600' : 'text-indigo-600'">
                                            {{ component.is_required ? tr('Obligatorio', 'Required') : tr('Opcional', 'Optional') }}
                                        </div>
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="participant in visibleFinancialParticipants" :key="`finance-participant-${participant.participant_key}`" class="border-t align-top">
                                    <td class="sticky left-0 z-10 bg-white px-4 py-3">
                                        <div class="font-medium text-gray-900">{{ participant.name }}</div>
                                        <div class="text-xs text-gray-500">{{ participant.participant_key }}</div>
                                    </td>
                                    <td class="px-4 py-3 text-gray-700">{{ participant.participant_type_label }}</td>
                                    <td class="px-4 py-3 text-gray-700">
                                        <div>{{ participant.club_name }}</div>
                                        <div class="text-xs text-gray-500">{{ participant.district_name || '—' }}</div>
                                    </td>
                                    <td class="px-4 py-3">
                                        <div class="flex flex-wrap gap-1">
                                            <span v-if="participant.is_enrolled" class="rounded-full border border-emerald-200 bg-emerald-50 px-2 py-0.5 text-xs font-semibold text-emerald-700">{{ tr('Inscrito', 'Enrolled') }}</span>
                                            <span v-if="participant.is_confirmed" class="rounded-full border border-blue-200 bg-blue-50 px-2 py-0.5 text-xs font-semibold text-blue-700">{{ tr('Confirmado', 'Confirmed') }}</span>
                                            <span v-if="!participant.is_enrolled && !participant.is_confirmed" class="rounded-full border border-gray-200 bg-gray-50 px-2 py-0.5 text-xs font-semibold text-gray-600">{{ tr('Pago registrado', 'Payment recorded') }}</span>
                                        </div>
                                    </td>
                                    <td class="px-4 py-3 text-right font-semibold text-gray-900">{{ money(participant.paid_amount) }}</td>
                                    <td v-for="component in financeComponents" :key="`finance-participant-${participant.participant_key}-${component.id}`" class="px-4 py-3 text-right">
                                        <div class="font-semibold text-gray-900">{{ money(componentAmount(participant, component.id).paid_amount) }}</div>
                                        <div v-if="Number(componentAmount(participant, component.id).expected_amount || 0) > 0" class="text-xs text-gray-500">
                                            {{ tr('de', 'of') }} {{ money(componentAmount(participant, component.id).expected_amount) }}
                                        </div>
                                    </td>
                                </tr>
                                <tr v-if="!visibleFinancialParticipants.length">
                                    <td :colspan="5 + financeComponents.length" class="px-4 py-8 text-center text-gray-500">
                                        {{ includeTargetedClubs ? tr('No hay pagos o participantes confirmados para desglosar.', 'No payments or confirmed participants to break down.') : tr('No hay participantes en clubes con pagos registrados.', 'No participants in clubs with recorded payments.') }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </details>
            </div>

            <div class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_360px]">
                <div class="space-y-4">
                    <div class="overflow-hidden rounded-lg border bg-white">
                        <div class="border-b px-4 py-3">
                            <h2 class="text-base font-semibold text-gray-900">{{ tr('Estado por club', 'Status by club') }}</h2>
                        </div>
                        <div class="overflow-x-auto">
                            <table class="min-w-full text-sm">
                                <thead class="bg-gray-50 text-left text-gray-600">
                                    <tr>
                                        <th class="px-4 py-3 font-medium">{{ tr('Club', 'Club') }}</th>
                                        <th class="px-4 py-3 font-medium">{{ tr('Estado', 'Status') }}</th>
                                        <th class="px-4 py-3 font-medium">{{ tr('Inscritos', 'Enrolled') }}</th>
                                        <th class="px-4 py-3 font-medium">{{ tr('Tareas', 'Tasks') }}</th>
                                        <th class="px-4 py-3 font-medium">{{ tr('Documentos', 'Documents') }}</th>
                                        <th class="px-4 py-3 text-right font-medium">{{ tr('Pendiente', 'Pending') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="club in readiness.clubs" :key="club.club_id" class="border-t align-top">
                                        <td class="px-4 py-3">
                                            <div class="font-medium text-gray-900">{{ club.club_name }}</div>
                                            <div class="text-xs text-gray-500">{{ club.district_name || club.church_name || '—' }}</div>
                                        </td>
                                        <td class="px-4 py-3">
                                            <span class="inline-flex rounded-full border px-2 py-0.5 text-xs font-semibold" :class="statusClass(club.status)">
                                                {{ club.status_label }}
                                            </span>
                                            <div class="mt-1 text-xs text-gray-500">{{ club.signup_status }}</div>
                                        </td>
                                        <td class="px-4 py-3 text-gray-700">
                                            <div>{{ tr('Miembros', 'Members') }}: {{ club.participants.enrolled_members }} / {{ club.participants.confirmed_members }}</div>
                                            <div>{{ tr('Staff', 'Staff') }}: {{ club.participants.enrolled_staff }} / {{ club.participants.confirmed_staff }}</div>
                                        </td>
                                        <td class="px-4 py-3 text-gray-700">
                                            {{ club.tasks.done }} / {{ club.tasks.total }}
                                        </td>
                                        <td class="px-4 py-3 text-gray-700">
                                            {{ club.documents.uploaded }}
                                        </td>
                                        <td class="px-4 py-3 text-right font-semibold text-gray-900">
                                            {{ money(club.finance.pending_settlement_amount) }}
                                        </td>
                                    </tr>
                                    <tr v-if="!readiness.clubs.length">
                                        <td colspan="6" class="px-4 py-8 text-center text-gray-500">
                                            {{ tr('No hay clubes visibles para este evento.', 'No visible clubs for this event.') }}
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="rounded-lg border bg-white">
                        <div class="border-b px-4 py-3">
                            <h2 class="text-base font-semibold text-gray-900">{{ tr('Alertas críticas y pendientes', 'Critical alerts and pending items') }}</h2>
                        </div>
                        <div class="divide-y">
                            <details v-for="club in readiness.clubs" :key="`blockers-${club.club_id}`" class="group">
                                <summary class="flex cursor-pointer list-none items-center justify-between gap-3 px-4 py-3">
                                    <div>
                                        <div class="font-medium text-gray-900">{{ club.club_name }}</div>
                                        <div class="text-xs text-gray-500">{{ club.blockers.length }} {{ tr('pendientes', 'items') }}</div>
                                    </div>
                                    <span class="rounded-full border px-2 py-0.5 text-xs font-semibold" :class="statusClass(club.status)">
                                        {{ club.status_label }}
                                    </span>
                                </summary>
                                <div class="space-y-2 px-4 pb-4">
                                    <div v-if="!club.blockers.length" class="rounded border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-700">
                                        {{ tr('Este club no tiene alertas activas.', 'This club has no active alerts.') }}
                                    </div>
                                    <div v-for="blocker in club.blockers" :key="`${club.club_id}-${blocker.type}`" class="rounded border px-3 py-2" :class="severityClass(blocker.severity)">
                                        <div class="font-semibold">{{ blocker.label }}</div>
                                        <div class="mt-1 text-sm">{{ blocker.message }}</div>
                                        <ul v-if="blocker.items?.length" class="mt-2 list-disc space-y-1 pl-5 text-xs">
                                            <li v-for="item in blocker.items" :key="item.id">
                                                {{ item.title }} <span v-if="item.due_at">· {{ dateText(item.due_at) }}</span>
                                            </li>
                                        </ul>
                                    </div>
                                </div>
                            </details>
                        </div>
                    </div>
                </div>

                <div class="space-y-4">
                    <div class="rounded-lg border bg-white p-4">
                        <h2 class="text-base font-semibold text-gray-900">{{ tr('Cierre del evento', 'Event closeout') }}</h2>
                        <div class="mt-3 rounded border px-3 py-2 text-sm" :class="closeoutReady ? 'border-emerald-200 bg-emerald-50 text-emerald-700' : 'border-amber-200 bg-amber-50 text-amber-700'">
                            {{ closeoutReady ? tr('Listo para cierre final', 'Ready for final closeout') : tr('Todavía no está listo para cierre', 'Not ready for closeout yet') }}
                        </div>
                        <div class="mt-3 space-y-2">
                            <div v-for="check in readiness.closeout.checks" :key="check.key" class="flex items-center justify-between gap-3 text-sm">
                                <span class="text-gray-700">{{ check.label }}</span>
                                <span :class="check.complete ? 'text-emerald-700' : 'text-amber-700'" class="font-semibold">
                                    {{ check.complete ? tr('Completo', 'Complete') : tr('Pendiente', 'Pending') }}
                                </span>
                            </div>
                        </div>
                        <p class="mt-3 text-xs text-gray-500">{{ readiness.closeout.instructions }}</p>
                    </div>

                    <div class="rounded-lg border bg-white p-4">
                        <h2 class="text-base font-semibold text-gray-900">{{ tr('Recordatorios', 'Reminders') }}</h2>
                        <div class="mt-2 rounded border border-blue-200 bg-blue-50 px-3 py-2 text-xs text-blue-800">
                            {{ readiness.reminder_processor.message }}
                        </div>
                        <div class="mt-3 max-h-[520px] space-y-2 overflow-auto pr-1">
                            <div v-for="reminder in readiness.reminders" :key="`${reminder.scope_type}-${reminder.scope_id}-${reminder.reason}`" class="rounded border border-gray-200 px-3 py-2 text-sm">
                                <div class="flex items-start justify-between gap-2">
                                    <div class="font-medium text-gray-900">{{ reminder.recipient_label }}</div>
                                    <span class="rounded-full border px-2 py-0.5 text-[11px] font-semibold" :class="severityClass(reminder.severity)">
                                        {{ reminder.processor_status }}
                                    </span>
                                </div>
                                <div class="mt-1 text-xs font-semibold uppercase text-gray-500">{{ reminder.reason }}</div>
                                <div class="mt-1 text-gray-700">{{ reminder.message }}</div>
                            </div>
                            <div v-if="!readiness.reminders.length" class="rounded border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-700">
                                {{ tr('No hay recordatorios pendientes.', 'No pending reminders.') }}
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </PathfinderLayout>
</template>
