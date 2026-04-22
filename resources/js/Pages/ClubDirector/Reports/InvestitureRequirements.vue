<script setup>
import PathfinderLayout from '@/Layouts/PathfinderLayout.vue'
import { computed, ref } from 'vue'
import axios from 'axios'
import { router } from '@inertiajs/vue3'

const props = defineProps({
    club: {
        type: Object,
        required: true,
    },
    classes: {
        type: Array,
        default: () => [],
    },
    report_type: {
        type: String,
        default: 'honors',
    },
    investitureRequests: {
        type: Array,
        default: () => [],
    },
})

const itemLabelPlural = computed(() =>
    props.club?.club_type === 'adventurers' ? 'Honores' : 'Requisitos de investidura'
)

const itemLabelSingular = computed(() =>
    props.club?.club_type === 'adventurers' ? 'Honor' : 'Requisito'
)
const showPendingMembers = ref(false)
const expandedMemberKey = ref(null)
const accessLinks = ref({})
const accessLinkLoading = ref({})
const requestNotes = ref('')
const tentativeInvestitureDate = ref('')
const dateUpdateDrafts = ref({})
const dateUpdateLoading = ref({})
const requestSubmitting = ref(false)
const requestError = ref('')

const isCarpetas = computed(() => props.report_type === 'carpetas' || props.club?.evaluation_system === 'carpetas')
const canGeneratePublicLinks = computed(() => props.club?.club_type === 'pathfinders')

const totalRequirements = computed(() =>
    props.classes.reduce((sum, clubClass) => sum + (clubClass.requirements_count || 0), 0)
)

const totalCompletions = computed(() =>
    isCarpetas.value
        ? props.classes.reduce((sum, clubClass) => sum + (clubClass.members || []).reduce((inner, member) => inner + (member.completed_count || 0), 0), 0)
        : props.classes.reduce(
            (sum, clubClass) => sum + (clubClass.requirements || []).reduce((inner, requirement) => inner + (requirement.completed_count || 0), 0),
            0
        )
)

const totalMembers = computed(() =>
    props.classes.reduce((sum, clubClass) => sum + (clubClass.members_count || 0), 0)
)

const flattenedCarpetaMembers = computed(() =>
    props.classes.flatMap((clubClass) =>
        (clubClass.members || []).map((member) => ({
            member_id: member.member_id,
            name: member.name,
            class_name: clubClass.class_name,
            requirements: member.requirements || [],
        }))
    )
)

const hasOpenInvestitureRequest = computed(() =>
    (props.investitureRequests || []).some((request) =>
        ['submitted', 'assigned', 'in_review', 'completed', 'date_change_requested', 'returned', 'authorized'].includes(request.status)
    )
)

const canEditRequestDate = (request) =>
    ['submitted', 'assigned', 'in_review', 'completed', 'date_change_requested', 'returned'].includes(request.status)

const formatDate = (value) => {
    if (!value) return '—'
    const date = new Date(`${value}T00:00:00`)
    return date.toLocaleDateString()
}

const getPendingMembers = (clubClass, requirement) => {
    const completedIds = new Set((requirement?.completions || []).map((entry) => Number(entry.member_id)))
    return (clubClass?.members || []).filter((member) => !completedIds.has(Number(member.id)))
}

const memberKey = (clubClass, member) => `${clubClass.id}-${member.member_id}`

const toggleMember = (clubClass, member) => {
    const key = memberKey(clubClass, member)
    expandedMemberKey.value = expandedMemberKey.value === key ? null : key
}

const evidenceLabel = (requirement) => {
    const type = requirement?.evidence?.evidence_type
    if (!type) return 'Sin evidencia'
    const labels = {
        photo: 'Foto',
        file: 'Archivo',
        text: 'Texto',
        video_link: 'Video',
        external_link: 'Enlace',
        physical_only: 'Fisico',
    }
    return labels[type] || type
}

const createAccessLink = async (member) => {
    const key = member.member_id
    accessLinkLoading.value = { ...accessLinkLoading.value, [key]: true }
    try {
        const { data } = await axios.post(route('club.reports.investiture-requirements.member.access-code.store', { member: member.member_id }), {
            club_id: props.club?.id,
        })
        accessLinks.value = {
            ...accessLinks.value,
            [key]: data.data,
        }
    } finally {
        accessLinkLoading.value = { ...accessLinkLoading.value, [key]: false }
    }
}

const revokeAccessLinks = async (member) => {
    const key = member.member_id
    accessLinkLoading.value = { ...accessLinkLoading.value, [key]: true }
    try {
        await axios.delete(route('club.reports.investiture-requirements.member.access-codes.revoke', { member: member.member_id }), {
            data: { club_id: props.club?.id },
        })
        const next = { ...accessLinks.value }
        delete next[key]
        accessLinks.value = next
    } finally {
        accessLinkLoading.value = { ...accessLinkLoading.value, [key]: false }
    }
}

const copyAccessLink = async (member) => {
    const url = accessLinks.value[member.member_id]?.url
    if (!url || !navigator?.clipboard) return
    await navigator.clipboard.writeText(url)
}

const submitInvestitureRequest = async () => {
    requestSubmitting.value = true
    requestError.value = ''
    try {
        await axios.post(route('club.reports.investiture-requirements.requests.store'), {
            club_id: props.club?.id,
            director_notes: requestNotes.value,
            tentative_investiture_date: tentativeInvestitureDate.value,
            members: flattenedCarpetaMembers.value,
        })
        console.log('Solicitud de investidura enviada');
        requestNotes.value = ''
        tentativeInvestitureDate.value = ''
        router.reload({ only: ['investitureRequests'] })
    } catch (error) {
        requestError.value = error?.response?.data?.message || 'No se pudo crear la solicitud de investidura.'
    } finally {
        requestSubmitting.value = false
    }
}

const getDateDraft = (request) => {
    if (dateUpdateDrafts.value[request.id] === undefined) {
        dateUpdateDrafts.value[request.id] = request.tentative_investiture_date || ''
    }

    return dateUpdateDrafts.value[request.id]
}

const setDateDraft = (request, value) => {
    dateUpdateDrafts.value[request.id] = value
}

const updateTentativeDate = async (request) => {
    dateUpdateLoading.value = { ...dateUpdateLoading.value, [request.id]: true }
    requestError.value = ''
    try {
        await axios.post(`/club-director/reports/investiture-requirements/requests/${request.id}/tentative-date`, {
            club_id: props.club?.id,
            tentative_investiture_date: getDateDraft(request),
        })
        router.reload({ only: ['investitureRequests'] })
    } catch (error) {
        requestError.value = error?.response?.data?.message || 'No se pudo actualizar la fecha tentativa.'
    } finally {
        dateUpdateLoading.value = { ...dateUpdateLoading.value, [request.id]: false }
    }
}
</script>

<template>
    <PathfinderLayout>
        <div class="px-4 sm:px-6 lg:px-8 py-6 space-y-6">
            <div class="flex flex-col gap-2">
                <h1 class="text-xl font-semibold text-gray-900">
                    {{ isCarpetas ? 'Carpetas por clase' : `${itemLabelPlural} por clase` }}
                </h1>
                <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                    <p class="text-sm text-gray-600">
                        Club activo: <span class="font-medium text-gray-800">{{ club?.club_name || '—' }}</span>
                    </p>
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
                        <label class="inline-flex items-center gap-2 text-sm text-gray-700">
                            <input v-model="showPendingMembers" type="checkbox" class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                            Mostrar miembros pendientes
                        </label>
                        <a
                            v-if="!isCarpetas"
                            :href="route('club.reports.investiture-requirements.pdf', { club_id: club?.id, show_pending: showPendingMembers ? 1 : 0 })"
                            class="inline-flex items-center justify-center rounded bg-gray-800 px-4 py-2 text-sm font-medium text-white hover:bg-gray-900"
                        >
                            Exportar PDF
                        </a>
                    </div>
                </div>
            </div>

            <section class="grid gap-4 md:grid-cols-3">
                <div class="rounded-lg border bg-white p-4 shadow-sm">
                    <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">Clases</div>
                    <div class="mt-2 text-2xl font-semibold text-gray-900">{{ classes.length }}</div>
                </div>
                <div class="rounded-lg border bg-white p-4 shadow-sm">
                    <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ isCarpetas ? 'Miembros asignados' : itemLabelPlural }}</div>
                    <div class="mt-2 text-2xl font-semibold text-gray-900">{{ isCarpetas ? totalMembers : totalRequirements }}</div>
                </div>
                <div class="rounded-lg border bg-white p-4 shadow-sm">
                    <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ isCarpetas ? 'Evidencias completadas' : 'Cumplimientos registrados' }}</div>
                    <div class="mt-2 text-2xl font-semibold text-gray-900">{{ totalCompletions }}</div>
                </div>
            </section>

            <section v-if="isCarpetas" class="rounded-lg border bg-white p-5 shadow-sm">
                <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                    <div class="max-w-2xl">
                        <h2 class="text-base font-semibold text-gray-900">Solicitud de investidura</h2>
                        <p class="mt-1 text-sm text-gray-600">
                            Cuando el club esté listo, envía la solicitud para que la asociación asigne la revisión. Por ahora el evaluador sugerido será el pastor del distrito.
                        </p>
                        <div v-if="investitureRequests.length" class="mt-3 space-y-2">
                            <div v-for="request in investitureRequests" :key="request.id" class="rounded border border-gray-200 bg-gray-50 px-3 py-2 text-sm">
                                <div class="font-medium text-gray-900">
                                    Solicitud #{{ request.id }} · {{ request.status }} · {{ request.members_count }} miembro(s)
                                </div>
                                <div class="mt-0.5 text-xs text-gray-500">
                                    Ciclo {{ request.carpeta_year || '—' }} · Enviada {{ request.submitted_at || '—' }}
                                    · Fecha tentativa {{ request.tentative_investiture_date || '—' }}
                                    <template v-if="request.approved_investiture_date">
                                        · Fecha autorizada {{ request.approved_investiture_date }}
                                    </template>
                                    <template v-if="request.assigned_evaluator_name">
                                        · Evaluador: {{ request.assigned_evaluator_name }}
                                    </template>
                                </div>
                                <div v-if="request.ceremony_representative_name" class="mt-3 rounded border border-green-200 bg-green-50 p-3 text-xs text-green-900">
                                    <p class="font-semibold">Representante de asociación para la ceremonia</p>
                                    <p class="mt-1">{{ request.ceremony_representative_name }}</p>
                                    <p v-if="request.ceremony_representative_email" class="mt-1">
                                        Correo: <a :href="`mailto:${request.ceremony_representative_email}`" class="font-medium underline">{{ request.ceremony_representative_email }}</a>
                                    </p>
                                    <p v-if="request.ceremony_representative_phone" class="mt-1">
                                        Teléfono: <a :href="`tel:${request.ceremony_representative_phone}`" class="font-medium underline">{{ request.ceremony_representative_phone }}</a>
                                    </p>
                                </div>
                                <div
                                    v-if="canEditRequestDate(request)"
                                    class="mt-3 rounded border p-3"
                                    :class="request.status === 'date_change_requested' ? 'border-amber-200 bg-amber-50' : 'border-gray-200 bg-white'"
                                >
                                    <p class="font-semibold" :class="request.status === 'date_change_requested' ? 'text-amber-900' : 'text-gray-900'">
                                        {{ request.status === 'date_change_requested' ? 'La asociación solicitó una nueva fecha' : 'Editar fecha tentativa' }}
                                    </p>
                                    <p v-if="request.status === 'date_change_requested'" class="mt-1 text-xs text-amber-800">
                                        {{ request.date_change_reason || 'La fecha propuesta no está disponible para la asociación.' }}
                                    </p>
                                    <p v-else-if="!request.tentative_investiture_date" class="mt-1 text-xs text-gray-600">
                                        Esta solicitud no tiene fecha tentativa registrada.
                                    </p>
                                    <div class="mt-3 flex flex-col gap-2 sm:flex-row sm:items-center">
                                        <input
                                            :value="getDateDraft(request)"
                                            type="date"
                                            class="rounded-md border-gray-300 text-sm"
                                            @input="setDateDraft(request, $event.target.value)"
                                        >
                                        <button
                                            type="button"
                                            class="rounded bg-amber-700 px-3 py-2 text-sm font-medium text-white hover:bg-amber-800 disabled:opacity-60"
                                            :disabled="dateUpdateLoading[request.id]"
                                            @click="updateTentativeDate(request)"
                                        >
                                            {{ dateUpdateLoading[request.id] ? 'Guardando...' : (request.status === 'date_change_requested' ? 'Enviar nueva fecha' : 'Guardar fecha') }}
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div v-if="!hasOpenInvestitureRequest" class="w-full max-w-md space-y-3">
                        <label class="block text-sm font-medium text-gray-700">
                            Fecha tentativa de investidura <span class="font-normal text-gray-500">(opcional)</span>
                            <input
                                v-model="tentativeInvestitureDate"
                                type="date"
                                class="mt-1 w-full rounded-md border-gray-300 text-sm"
                            >
                        </label>
                        <textarea
                            v-model="requestNotes"
                            rows="3"
                            class="w-full rounded-md border-gray-300 text-sm"
                            placeholder="Notas para la asociación o evaluador"
                        />
                        <p v-if="requestError" class="text-sm text-red-600">{{ requestError }}</p>
                        <button
                            type="button"
                            class="w-full rounded bg-red-700 px-4 py-2 text-sm font-medium text-white hover:bg-red-800 disabled:opacity-60"
                            :disabled="requestSubmitting || hasOpenInvestitureRequest || !flattenedCarpetaMembers.length"
                            @click="submitInvestitureRequest"
                        >
                            <template v-if="requestSubmitting">Enviando...</template>
                            <template v-else>Solicitar investidura</template>
                        </button>
                    </div>
                </div>
            </section>

            <section v-if="!classes.length" class="rounded-lg border bg-white p-6 text-sm text-gray-600 shadow-sm">
                No hay clases configuradas para el club activo.
            </section>

            <section v-if="isCarpetas" v-for="clubClass in classes" :key="`carpetas-${clubClass.id}`" class="rounded-lg border bg-white shadow-sm">
                <div class="border-b px-5 py-4">
                    <div class="flex flex-col gap-1 md:flex-row md:items-end md:justify-between">
                        <div>
                            <h2 class="text-lg font-semibold text-gray-900">
                                {{ clubClass.class_order ? `${clubClass.class_order}. ` : '' }}{{ clubClass.class_name }}
                            </h2>
                            <p class="text-sm text-gray-600">
                                {{ clubClass.members_count }} miembro(s) asignados, {{ clubClass.requirements_count }} requisitos definidos por la union
                            </p>
                        </div>
                        <div class="text-sm text-gray-600">
                            {{ clubClass.completed_requirements_count }} evidencia(s) registradas
                        </div>
                    </div>
                </div>

                <div v-if="!clubClass.members.length" class="px-5 py-4 text-sm text-gray-500">
                    No hay miembros asignados a esta clase.
                </div>

                <div v-else class="divide-y">
                    <article v-for="member in clubClass.members" :key="member.member_id" class="px-5 py-4">
                        <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                            <button
                                type="button"
                                class="min-w-0 text-left"
                                @click="toggleMember(clubClass, member)"
                            >
                                <div class="flex flex-wrap items-center gap-2">
                                    <h3 class="text-sm font-semibold text-gray-900">{{ member.name }}</h3>
                                    <span
                                        class="inline-flex rounded-full px-2 py-0.5 text-[11px] font-semibold"
                                        :class="member.all_completed ? 'bg-green-100 text-green-800' : 'bg-amber-100 text-amber-800'"
                                    >
                                        {{ member.completed_count }} / {{ member.requirements_count }}
                                    </span>
                                </div>
                                <p class="mt-1 text-sm text-gray-600">
                                    Grado: {{ member.grade || '—' }} · Asignado: {{ formatDate(member.assigned_at) }}
                                </p>
                            </button>

                            <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
                                <button
                                    type="button"
                                    class="rounded border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
                                    @click="toggleMember(clubClass, member)"
                                >
                                    {{ expandedMemberKey === memberKey(clubClass, member) ? 'Ocultar estado' : 'Ver estado' }}
                                </button>
                                <a
                                    v-if="member.has_evidence"
                                    :href="member.print_url"
                                    class="rounded bg-gray-800 px-3 py-2 text-center text-sm font-medium text-white hover:bg-gray-900"
                                >
                                    Imprimir carpeta
                                </a>
                                <span v-else class="rounded border border-gray-200 px-3 py-2 text-center text-sm text-gray-500">
                                    Sin evidencia para imprimir
                                </span>
                            </div>
                        </div>

                        <div v-if="canGeneratePublicLinks" class="mt-3 rounded-lg border border-slate-200 bg-slate-50 p-3">
                            <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                                <div>
                                    <p class="text-sm font-semibold text-slate-900">Enlace publico temporal</p>
                                    <p class="text-xs text-slate-600">
                                        Permite que este miembro suba evidencias sin crear una cuenta. Puede revocarse en cualquier momento.
                                    </p>
                                </div>
                                <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
                                    <button
                                        type="button"
                                        class="rounded bg-blue-600 px-3 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-60"
                                        :disabled="accessLinkLoading[member.member_id]"
                                        @click="createAccessLink(member)"
                                    >
                                        {{ accessLinkLoading[member.member_id] ? 'Generando...' : 'Generar enlace' }}
                                    </button>
                                    <button
                                        type="button"
                                        class="rounded border border-red-200 px-3 py-2 text-sm font-medium text-red-700 hover:bg-red-50 disabled:opacity-60"
                                        :disabled="accessLinkLoading[member.member_id]"
                                        @click="revokeAccessLinks(member)"
                                    >
                                        Revocar enlaces
                                    </button>
                                </div>
                            </div>
                            <div v-if="accessLinks[member.member_id]" class="mt-3 rounded border bg-white p-3">
                                <div class="break-all text-sm text-gray-800">{{ accessLinks[member.member_id].url }}</div>
                                <div class="mt-1 text-xs text-gray-500">Expira: {{ accessLinks[member.member_id].expires_at || '—' }}</div>
                                <button
                                    type="button"
                                    class="mt-2 rounded border px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-50"
                                    @click="copyAccessLink(member)"
                                >
                                    Copiar enlace
                                </button>
                            </div>
                        </div>

                        <div v-if="expandedMemberKey === memberKey(clubClass, member)" class="mt-4 rounded-lg border bg-gray-50 p-4">
                            <div class="mb-3 flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
                                <p class="text-sm font-semibold text-gray-900">Estado de carpeta</p>
                                <p class="text-xs text-gray-500">{{ member.pending_count }} pendiente(s)</p>
                            </div>

                            <div v-if="!member.requirements.length" class="text-sm text-gray-500">
                                Esta clase no tiene requisitos publicados para carpetas.
                            </div>

                            <div v-else class="space-y-3">
                                <div
                                    v-for="requirement in member.requirements"
                                    :key="`${member.member_id}-${requirement.id}`"
                                    class="rounded border bg-white p-3"
                                >
                                    <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                                        <div>
                                            <div class="text-sm font-semibold text-gray-900">
                                                {{ requirement.sort_order ? `${requirement.sort_order}. ` : '' }}{{ requirement.title }}
                                            </div>
                                            <p v-if="requirement.description" class="mt-1 text-sm text-gray-600">
                                                {{ requirement.description }}
                                            </p>
                                            <p class="mt-1 text-xs text-gray-500">
                                                Tipo: {{ requirement.requirement_type || '—' }} · Validación: {{ requirement.validation_mode || 'electronic' }}
                                            </p>
                                        </div>
                                        <span
                                            class="inline-flex w-fit rounded-full px-2 py-0.5 text-[11px] font-semibold"
                                            :class="requirement.completed ? 'bg-green-100 text-green-800' : 'bg-amber-100 text-amber-800'"
                                        >
                                            {{ requirement.completed ? 'Completado' : 'Pendiente' }}
                                        </span>
                                    </div>

                                    <div v-if="requirement.evidence" class="mt-3 rounded border border-blue-100 bg-blue-50 p-3 text-sm text-blue-950">
                                        <div class="font-medium">{{ evidenceLabel(requirement) }}</div>
                                        <div class="mt-1 text-xs text-blue-800">
                                            Registrada: {{ requirement.evidence.submitted_at || '—' }}
                                        </div>
                                        <img
                                            v-if="requirement.evidence.is_image && requirement.evidence.file_url"
                                            :src="requirement.evidence.file_url"
                                            alt="Evidencia"
                                            class="mt-2 h-20 w-28 rounded border bg-white object-cover"
                                        >
                                        <a
                                            v-else-if="requirement.evidence.file_url"
                                            :href="requirement.evidence.file_url"
                                            target="_blank"
                                            class="mt-2 inline-flex text-blue-700 underline"
                                        >
                                            Ver archivo
                                        </a>
                                        <a
                                            v-if="requirement.evidence.text_value && ['video_link', 'external_link'].includes(requirement.evidence.evidence_type)"
                                            :href="requirement.evidence.text_value"
                                            target="_blank"
                                            class="mt-2 block break-all text-blue-700 underline"
                                        >
                                            {{ requirement.evidence.text_value }}
                                        </a>
                                        <p
                                            v-else-if="requirement.evidence.text_value"
                                            class="mt-2 whitespace-pre-wrap break-words text-blue-950"
                                        >
                                            {{ requirement.evidence.text_value }}
                                        </p>
                                        <p v-if="requirement.evidence.physical_completed" class="mt-2">
                                            Requisito físico marcado como completado.
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </article>
                </div>
            </section>

            <section v-else v-for="clubClass in classes" :key="clubClass.id" class="rounded-lg border bg-white shadow-sm">
                <div class="border-b px-5 py-4">
                    <div class="flex flex-col gap-1 md:flex-row md:items-end md:justify-between">
                        <div>
                            <h2 class="text-lg font-semibold text-gray-900">
                                {{ clubClass.class_order ? `${clubClass.class_order}. ` : '' }}{{ clubClass.class_name }}
                            </h2>
                            <p class="text-sm text-gray-600">
                                {{ clubClass.members_count }} miembro(s) asignados, {{ clubClass.requirements_count }} {{ itemLabelPlural.toLowerCase() }}
                            </p>
                        </div>
                        <div class="text-sm text-gray-600">
                            {{ clubClass.completed_requirements_count }} {{ itemLabelPlural.toLowerCase() }} con al menos un cumplimiento
                        </div>
                    </div>
                </div>

                <div v-if="!clubClass.requirements.length" class="px-5 py-4 text-sm text-gray-500">
                    Esta clase no tiene {{ itemLabelPlural.toLowerCase() }} configurados.
                </div>

                <div v-else class="divide-y">
                    <div v-for="requirement in clubClass.requirements" :key="requirement.id" class="px-5 py-4">
                        <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                            <div class="min-w-0">
                                <div class="flex items-center gap-2">
                                    <h3 class="text-sm font-semibold text-gray-900">
                                        {{ requirement.sort_order ? `${requirement.sort_order}. ` : '' }}{{ requirement.title }}
                                    </h3>
                                    <span
                                        v-if="!requirement.is_active"
                                        class="inline-flex items-center rounded-full bg-gray-100 px-2 py-0.5 text-[11px] font-medium text-gray-700"
                                    >
                                        Inactivo
                                    </span>
                                </div>
                                <p v-if="requirement.description" class="mt-1 text-sm text-gray-600">
                                    {{ requirement.description }}
                                </p>
                            </div>

                            <div class="grid grid-cols-2 gap-3 text-sm lg:min-w-[260px]">
                                <div class="rounded border bg-gray-50 px-3 py-2">
                                    <div class="text-[11px] uppercase tracking-wide text-gray-500">Completados</div>
                                    <div class="font-semibold text-gray-900">{{ requirement.completed_count }}</div>
                                </div>
                                <div class="rounded border bg-gray-50 px-3 py-2">
                                    <div class="text-[11px] uppercase tracking-wide text-gray-500">Pendientes</div>
                                    <div class="font-semibold text-gray-900">{{ requirement.pending_count }}</div>
                                </div>
                            </div>
                        </div>

                        <div class="mt-4">
                            <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">
                                Miembros que han completado este {{ itemLabelSingular.toLowerCase() }}
                            </p>

                            <div v-if="!requirement.completions.length" class="mt-2 text-sm text-gray-500">
                                Nadie lo ha completado todavía.
                            </div>

                            <div v-else class="mt-3 overflow-x-auto">
                                <table class="min-w-full text-sm">
                                    <thead>
                                        <tr class="border-b text-left text-gray-500">
                                            <th class="pb-2 pr-4 font-medium">Miembro</th>
                                            <th class="pb-2 pr-4 font-medium">Fecha</th>
                                            <th class="pb-2 font-medium">Actividad</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr
                                            v-for="completion in requirement.completions"
                                            :key="`${requirement.id}-${completion.member_id}`"
                                            class="border-b last:border-b-0"
                                        >
                                            <td class="py-2 pr-4 text-gray-900">{{ completion.member_name }}</td>
                                            <td class="py-2 pr-4 text-gray-600">{{ formatDate(completion.date) }}</td>
                                            <td class="py-2 text-gray-600">{{ completion.activity_title || '—' }}</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <div v-if="showPendingMembers" class="mt-4">
                            <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">
                                Miembros pendientes
                            </p>
                            <div v-if="!getPendingMembers(clubClass, requirement).length" class="mt-2 text-sm text-gray-500">
                                No hay pendientes para este {{ itemLabelSingular.toLowerCase() }}.
                            </div>
                            <ul v-else class="mt-2 grid gap-2 md:grid-cols-2 xl:grid-cols-3">
                                <li
                                    v-for="member in getPendingMembers(clubClass, requirement)"
                                    :key="`${requirement.id}-pending-${member.id}`"
                                    class="rounded border bg-amber-50 px-3 py-2 text-sm text-amber-900"
                                >
                                    {{ member.name }}
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>
            </section>
        </div>
    </PathfinderLayout>
</template>
