<script setup>
import PathfinderLayout from '@/Layouts/PathfinderLayout.vue'
import { ArrowDownTrayIcon, ClockIcon, DocumentTextIcon, EyeIcon } from '@heroicons/vue/24/outline'
import { computed, ref, watch } from 'vue'
import { useLocale } from '@/Composables/useLocale'

const props = defineProps({
    association: { type: Object, required: true },
    clubs: { type: Array, default: () => [] },
    submissions: { type: Array, default: () => [] },
    latest_by_club: { type: Array, default: () => [] },
    form_types: { type: Object, default: () => ({ adventurers: [], pathfinders: [] }) },
})

const { tr, locale } = useLocale()
const clubType = ref('adventurers')
const selectedClubId = ref('all')
const mode = ref('all')

const clubsForType = computed(() => props.clubs.filter(club => club.club_type === clubType.value))
const visibleClubs = computed(() => clubsForType.value.filter(club =>
    selectedClubId.value === 'all' || String(club.id) === String(selectedClubId.value)
))
const visibleClubIds = computed(() => new Set(visibleClubs.value.map(club => club.id)))
const currentFormTypes = computed(() => props.form_types[clubType.value] || [])

const groupedSubmissions = computed(() => visibleClubs.value.map(club => ({
    ...club,
    submissions: props.submissions
        .filter(item => item.club_id === club.id)
        .sort((a, b) => String(b.submitted_at).localeCompare(String(a.submitted_at))),
})))

const latestRows = computed(() => props.latest_by_club.filter(row => visibleClubIds.value.has(row.club_id)))
const visibleSubmissionCount = computed(() => groupedSubmissions.value.reduce((sum, club) => sum + club.submissions.length, 0))
const latestSubmissionCount = computed(() => latestRows.value.reduce((sum, club) => sum + Object.keys(club.latest || {}).length, 0))

watch(clubType, () => {
    selectedClubId.value = 'all'
})

const formLabel = item => locale.value === 'en' ? item.form_label_en : item.form_label_es
const typeLabel = item => locale.value === 'en' ? item.label_en : item.label_es
const formatDate = value => {
    if (!value) return '—'
    return new Intl.DateTimeFormat(locale.value === 'en' ? 'en-US' : 'es-US', {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(new Date(value))
}
const statusLabel = status => ({
    sent: tr('Enviado', 'Sent'),
    saved: tr('Guardado', 'Saved'),
    submitted: tr('Enviado', 'Submitted'),
    on_time: tr('A tiempo', 'On time'),
    late: tr('Tardío', 'Late'),
    pending: tr('Pendiente', 'Pending'),
    failed: tr('Falló', 'Failed'),
})[status] || status || tr('Guardado', 'Saved')
const statusClass = status => ({
    sent: 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
    submitted: 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
    on_time: 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
    late: 'bg-amber-50 text-amber-700 ring-amber-600/20',
    failed: 'bg-red-50 text-red-700 ring-red-600/20',
})[status] || 'bg-gray-50 text-gray-700 ring-gray-600/20'
</script>

<template>
    <PathfinderLayout>
        <template #title>{{ tr('Formas', 'Forms') }}</template>

        <div class="space-y-6">
            <section class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm sm:p-6">
                <div class="flex flex-col gap-5 lg:flex-row lg:items-end lg:justify-between">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wider text-red-600">{{ association.name }}</p>
                        <h1 class="mt-1 text-2xl font-bold tracking-tight text-gray-900">
                            {{ tr('Formas de los clubes', 'Club forms') }}
                        </h1>
                        <p class="mt-2 max-w-2xl text-sm text-gray-600">
                            {{ tr('Consulta las entregas de cada club y compara la entrega más reciente de cada tipo de forma.', 'Review each club’s submissions and compare the latest submission for every form type.') }}
                        </p>
                    </div>

                    <div class="grid gap-3 sm:grid-cols-2 lg:min-w-[34rem]">
                        <label class="block">
                            <span class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ tr('Tipo de club', 'Club type') }}</span>
                            <select v-model="clubType" class="mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-red-500 focus:ring-red-500">
                                <option value="adventurers">{{ tr('Aventureros', 'Adventurers') }}</option>
                                <option value="pathfinders">{{ tr('Conquistadores', 'Pathfinders') }}</option>
                            </select>
                        </label>
                        <label class="block">
                            <span class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ tr('Club', 'Club') }}</span>
                            <select v-model="selectedClubId" class="mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-red-500 focus:ring-red-500">
                                <option value="all">{{ tr('Todos los clubes', 'All clubs') }}</option>
                                <option v-for="club in clubsForType" :key="club.id" :value="club.id">{{ club.club_name }}</option>
                            </select>
                        </label>
                    </div>
                </div>
            </section>

            <section class="grid gap-4 sm:grid-cols-3">
                <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
                    <p class="text-sm text-gray-500">{{ tr('Clubes mostrados', 'Clubs shown') }}</p>
                    <p class="mt-1 text-2xl font-bold text-gray-900">{{ visibleClubs.length }}</p>
                </div>
                <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
                    <p class="text-sm text-gray-500">{{ tr('Entregas guardadas', 'Saved submissions') }}</p>
                    <p class="mt-1 text-2xl font-bold text-gray-900">{{ visibleSubmissionCount }}</p>
                </div>
                <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
                    <p class="text-sm text-gray-500">{{ tr('Últimas formas disponibles', 'Latest forms available') }}</p>
                    <p class="mt-1 text-2xl font-bold text-gray-900">{{ latestSubmissionCount }}</p>
                </div>
            </section>

            <section class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm">
                <div class="border-b border-gray-200 p-3 sm:p-4">
                    <div class="inline-flex w-full rounded-xl bg-gray-100 p-1 sm:w-auto">
                        <button
                            type="button"
                            class="flex-1 rounded-lg px-4 py-2 text-sm font-semibold transition sm:flex-none"
                            :class="mode === 'all' ? 'bg-white text-red-700 shadow-sm' : 'text-gray-600 hover:text-gray-900'"
                            @click="mode = 'all'"
                        >
                            {{ tr('Todas por club', 'All by club') }}
                        </button>
                        <button
                            type="button"
                            class="flex-1 rounded-lg px-4 py-2 text-sm font-semibold transition sm:flex-none"
                            :class="mode === 'latest' ? 'bg-white text-red-700 shadow-sm' : 'text-gray-600 hover:text-gray-900'"
                            @click="mode = 'latest'"
                        >
                            {{ tr('Última por tipo', 'Latest by type') }}
                        </button>
                    </div>
                </div>

                <div v-if="mode === 'all'" class="divide-y divide-gray-200">
                    <article v-for="club in groupedSubmissions" :key="club.id" class="p-4 sm:p-6">
                        <div class="mb-4 flex flex-col gap-1 sm:flex-row sm:items-start sm:justify-between">
                            <div>
                                <h2 class="font-semibold text-gray-900">{{ club.club_name }}</h2>
                                <p class="text-sm text-gray-500">{{ club.church_name || '—' }}<span v-if="club.district_name"> · {{ club.district_name }}</span></p>
                            </div>
                            <span class="text-xs font-medium text-gray-500">
                                {{ club.submissions.length }} {{ tr('entregas', 'submissions') }}
                            </span>
                        </div>

                        <div v-if="club.submissions.length" class="overflow-x-auto rounded-xl border border-gray-200">
                            <table class="min-w-full divide-y divide-gray-200 text-left text-sm">
                                <thead class="bg-gray-50 text-xs uppercase tracking-wide text-gray-500">
                                    <tr>
                                        <th class="px-4 py-3 font-semibold">{{ tr('Fecha de entrega', 'Submitted') }}</th>
                                        <th class="px-4 py-3 font-semibold">{{ tr('Tipo de forma', 'Form type') }}</th>
                                        <th class="px-4 py-3 font-semibold">{{ tr('Período / evento', 'Period / event') }}</th>
                                        <th class="px-4 py-3 font-semibold">{{ tr('Estado', 'Status') }}</th>
                                        <th class="px-4 py-3 text-right font-semibold">{{ tr('Documento', 'Document') }}</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100 bg-white">
                                    <tr v-for="submission in club.submissions" :key="`${submission.form_type}-${submission.id}`">
                                        <td class="whitespace-nowrap px-4 py-3 text-gray-600">{{ formatDate(submission.submitted_at) }}</td>
                                        <td class="px-4 py-3 font-medium text-gray-900">{{ formLabel(submission) }}</td>
                                        <td class="px-4 py-3 text-gray-600">{{ submission.period || '—' }}</td>
                                        <td class="px-4 py-3">
                                            <span class="inline-flex rounded-full px-2 py-1 text-xs font-medium ring-1 ring-inset" :class="statusClass(submission.status)">
                                                {{ statusLabel(submission.status) }}
                                            </span>
                                        </td>
                                        <td class="px-4 py-3 text-right">
                                            <div class="inline-flex items-center gap-3">
                                                <a :href="submission.view_url" class="inline-flex items-center gap-1.5 font-semibold text-blue-700 hover:text-blue-900">
                                                    <EyeIcon class="h-4 w-4" />{{ tr('Ver', 'View') }}
                                                </a>
                                                <a v-if="submission.download_url" :href="submission.download_url" class="inline-flex items-center gap-1.5 font-semibold text-red-700 hover:text-red-900">
                                                    <ArrowDownTrayIcon class="h-4 w-4" />{{ tr('Descargar', 'Download') }}
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <div v-else class="rounded-xl border border-dashed border-gray-300 bg-gray-50 px-4 py-8 text-center text-sm text-gray-500">
                            {{ tr('Este club todavía no ha enviado formas.', 'This club has not submitted any forms yet.') }}
                        </div>
                    </article>

                    <div v-if="!groupedSubmissions.length" class="px-6 py-16 text-center">
                        <DocumentTextIcon class="mx-auto h-10 w-10 text-gray-300" />
                        <p class="mt-3 text-sm text-gray-500">{{ tr('No hay clubes activos de este tipo.', 'There are no active clubs of this type.') }}</p>
                    </div>
                </div>

                <div v-else class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 text-left text-sm">
                        <thead class="bg-gray-50 text-xs uppercase tracking-wide text-gray-500">
                            <tr>
                                <th class="sticky left-0 z-10 min-w-56 bg-gray-50 px-5 py-3 font-semibold">{{ tr('Club', 'Club') }}</th>
                                <th v-for="type in currentFormTypes" :key="type.key" class="min-w-64 px-5 py-3 font-semibold">{{ typeLabel(type) }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 bg-white">
                            <tr v-for="club in latestRows" :key="club.club_id" class="align-top">
                                <td class="sticky left-0 bg-white px-5 py-4">
                                    <p class="font-semibold text-gray-900">{{ club.club_name }}</p>
                                    <p class="mt-1 text-xs text-gray-500">{{ club.church_name || '—' }}</p>
                                </td>
                                <td v-for="type in currentFormTypes" :key="type.key" class="px-5 py-4">
                                    <template v-if="club.latest?.[type.key]">
                                        <div class="flex items-start gap-2">
                                            <ClockIcon class="mt-0.5 h-4 w-4 shrink-0 text-gray-400" />
                                            <div>
                                                <p class="font-medium text-gray-900">{{ formatDate(club.latest[type.key].submitted_at) }}</p>
                                                <p class="mt-1 text-xs text-gray-500">{{ club.latest[type.key].period || '—' }}</p>
                                                <div class="mt-2 flex items-center gap-3">
                                                    <a :href="club.latest[type.key].view_url" class="inline-flex items-center gap-1 text-xs font-semibold text-blue-700 hover:text-blue-900">
                                                        <EyeIcon class="h-3.5 w-3.5" />{{ tr('Ver', 'View') }}
                                                    </a>
                                                    <a v-if="club.latest[type.key].download_url" :href="club.latest[type.key].download_url" class="inline-flex items-center gap-1 text-xs font-semibold text-red-700 hover:text-red-900">
                                                        <ArrowDownTrayIcon class="h-3.5 w-3.5" />{{ tr('Descargar', 'Download') }}
                                                    </a>
                                                </div>
                                            </div>
                                        </div>
                                    </template>
                                    <span v-else class="text-gray-400">{{ tr('Sin entrega', 'No submission') }}</span>
                                </td>
                            </tr>
                            <tr v-if="!latestRows.length">
                                <td :colspan="currentFormTypes.length + 1" class="px-6 py-16 text-center text-gray-500">
                                    {{ tr('No hay clubes activos de este tipo.', 'There are no active clubs of this type.') }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </PathfinderLayout>
</template>
