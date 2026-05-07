<script setup>
import PathfinderLayout from '@/Layouts/PathfinderLayout.vue'
import { Link, router } from '@inertiajs/vue3'
import { computed, ref } from 'vue'
import { useGeneral } from '@/Composables/useGeneral'
import { useLocale } from '@/Composables/useLocale'

const props = defineProps({
    district: { type: Object, required: true },
    association: { type: Object, default: null },
    union: { type: Object, default: null },
    request: { type: Object, required: true },
})

const { showToast } = useGeneral()
const { tr } = useLocale()
const expandedMemberId = ref(props.request.members?.[0]?.id || null)
const expandedRequirement = ref(null)
const reviewNotes = ref({})
const updatingReview = ref(null)
const memberSearch = ref('')

const filteredMembers = computed(() => {
    const search = memberSearch.value.trim().toLowerCase()

    if (!search) return props.request.members || []

    return (props.request.members || []).filter((member) =>
        [member.member_name, member.class_name, member.status]
            .filter(Boolean)
            .some((value) => String(value).toLowerCase().includes(search))
    )
})

const statusLabel = (status) => ({
    pending_review: tr('Pendiente', 'Pending'),
    in_review: tr('En revisión', 'In review'),
    ready: tr('Listo para investidura', 'Ready for investiture'),
    returned: tr('Requiere corrección', 'Requires correction'),
    pending: tr('Pendiente', 'Pending'),
    approved: tr('Aprobado', 'Approved'),
    rejected: tr('Rechazado', 'Rejected'),
    assigned: tr('Asignada', 'Assigned'),
    completed: tr('Completada', 'Completed'),
    authorized: tr('Autorizada por asociación', 'Authorized by association'),
    date_change_requested: tr('Nueva fecha solicitada', 'New date requested'),
}[status] || status)

const statusClass = (status) => ({
    pending_review: 'bg-gray-100 text-gray-700 ring-gray-200',
    pending: 'bg-gray-100 text-gray-700 ring-gray-200',
    assigned: 'bg-blue-50 text-blue-800 ring-blue-200',
    in_review: 'bg-indigo-50 text-indigo-800 ring-indigo-200',
    approved: 'bg-emerald-50 text-emerald-800 ring-emerald-200',
    ready: 'bg-emerald-50 text-emerald-800 ring-emerald-200',
    completed: 'bg-emerald-50 text-emerald-800 ring-emerald-200',
    authorized: 'bg-green-100 text-green-900 ring-green-200',
    date_change_requested: 'bg-amber-50 text-amber-800 ring-amber-200',
    rejected: 'bg-rose-50 text-rose-800 ring-rose-200',
    returned: 'bg-rose-50 text-rose-800 ring-rose-200',
}[status] || 'bg-gray-50 text-gray-700 ring-gray-200')

const evidenceTypeLabel = (type) => ({
    photo: tr('Foto', 'Photo'),
    file: tr('Archivo', 'File'),
    text: tr('Texto', 'Text'),
    video_link: tr('Enlace de video', 'Video link'),
    external_link: tr('Enlace externo', 'External link'),
    physical_only: tr('Físico', 'Physical'),
}[type] || type)

const validationModeLabel = (mode) => ({
    electronic: tr('Evidencia electrónica', 'Electronic evidence'),
    physical: tr('Requisito físico', 'Physical requirement'),
    hybrid: tr('Híbrido', 'Hybrid'),
}[mode] || mode || '—')

const requiresPrintedEvidence = (requirement) => ['physical', 'hybrid'].includes(requirement?.validation_mode)

const videoPlatformName = (url) => {
    const value = String(url || '').toLowerCase()
    if (value.includes('youtube.com') || value.includes('youtu.be')) return 'YouTube'
    if (value.includes('vimeo.com')) return 'Vimeo'
    if (value.includes('icloud.com')) return 'iCloud'
    if (value.includes('drive.google.com') || value.includes('docs.google.com')) return 'Google Drive'
    if (value.includes('dropbox.com')) return 'Dropbox'
    if (value.includes('onedrive.live.com') || value.includes('1drv.ms') || value.includes('sharepoint.com')) return 'OneDrive'
    return tr('Enlace externo', 'External link')
}

const youtubeEmbedUrl = (url) => {
    const value = String(url || '').trim()
    const patterns = [
        /youtu\.be\/([^?&/]+)/i,
        /youtube\.com\/watch\?v=([^?&]+)/i,
        /youtube\.com\/embed\/([^?&/]+)/i,
        /youtube\.com\/shorts\/([^?&/]+)/i,
    ]
    const match = patterns.map(pattern => value.match(pattern)).find(Boolean)
    return match?.[1] ? `https://www.youtube.com/embed/${match[1]}` : null
}

const vimeoEmbedUrl = (url) => {
    const match = String(url || '').trim().match(/vimeo\.com\/(?:video\/)?(\d+)/i)
    return match?.[1] ? `https://player.vimeo.com/video/${match[1]}` : null
}

const videoEmbedUrl = (url) => youtubeEmbedUrl(url) || vimeoEmbedUrl(url)

const getReviewNotes = (review) => {
    if (reviewNotes.value[review.review_id] === undefined) {
        reviewNotes.value[review.review_id] = review.notes || ''
    }

    return reviewNotes.value[review.review_id]
}

const setReviewNotes = (review, value) => {
    reviewNotes.value[review.review_id] = value
}

const updateReview = (review, status) => {
    updatingReview.value = review.review_id
    router.patch(route('district.investiture-requests.reviews.update', {
        investitureRequest: props.request.id,
        review: review.review_id,
    }), {
        status,
        notes: getReviewNotes(review),
    }, {
        preserveScroll: true,
        onSuccess: () => showToast(tr('Evaluación actualizada.', 'Evaluation updated.'), 'success'),
        onError: (errors) => showToast(Object.values(errors || {})[0] || tr('No se pudo actualizar la evaluación.', 'Could not update the evaluation.'), 'error'),
        onFinish: () => {
            updatingReview.value = null
        },
    })
}

const toggleMember = (member) => {
    expandedMemberId.value = expandedMemberId.value === member.id ? null : member.id
    expandedRequirement.value = null
}
</script>

<template>
    <PathfinderLayout>
        <template #title>{{ tr('Evaluar carpeta de investidura', 'Evaluate investiture folder') }}</template>

        <div class="space-y-6">
            <section class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
                <div class="flex flex-col gap-4 md:flex-row md:items-start md:justify-between">
                    <div>
                        <Link :href="route('district.investiture-requests')" class="text-sm font-semibold text-blue-700 hover:underline">
                            {{ tr('Volver a solicitudes', 'Back to requests') }}
                        </Link>
                        <h1 class="mt-2 text-2xl font-semibold text-gray-900">
                            {{ request.club?.club_name || tr('Club', 'Club') }}
                        </h1>
                        <p class="mt-1 text-sm text-gray-600">
                            {{ request.club?.church_name || tr('Iglesia', 'Church') }} · {{ tr('Distrito', 'District') }} {{ district.name }} · {{ tr('Año', 'Year') }} {{ request.carpeta_year }}
                        </p>
                        <p class="mt-1 text-sm text-gray-600">
                            {{ tr('Fecha tentativa', 'Tentative date') }}: {{ request.tentative_investiture_date || '—' }}
                            <template v-if="request.approved_investiture_date">
                                · {{ tr('Fecha autorizada', 'Authorized date') }}: {{ request.approved_investiture_date }}
                            </template>
                        </p>
                        <p v-if="request.ceremony_representative_name" class="mt-1 text-sm font-medium text-green-700">
                            {{ tr('Representante de asociación', 'Association representative') }}: {{ request.ceremony_representative_name }}
                            <template v-if="request.ceremony_representative_email"> · {{ request.ceremony_representative_email }}</template>
                            <template v-if="request.ceremony_representative_phone"> · {{ request.ceremony_representative_phone }}</template>
                        </p>
                    </div>
                    <div class="rounded-xl border border-gray-200 bg-gray-50 px-4 py-3 text-sm text-gray-700">
                        <p class="font-semibold text-gray-900">{{ tr('Solicitud', 'Request') }} #{{ request.id }}</p>
                        <span class="mt-2 inline-flex rounded-full px-2.5 py-1 text-xs font-semibold ring-1" :class="statusClass(request.status)">
                            {{ statusLabel(request.status) }}
                        </span>
                    </div>
                </div>
            </section>

            <section class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm sm:p-5">
                <div class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
                    <div>
                        <h2 class="text-lg font-semibold text-gray-900">{{ tr('Miembros a evaluar', 'Members to evaluate') }}</h2>
                        <p class="mt-1 text-sm text-gray-600">{{ tr('Abra un miembro para revisar y evaluar su carpeta requisito por requisito.', 'Open a member to review and evaluate the folder requirement by requirement.') }}</p>
                    </div>
                    <div class="w-full md:max-w-sm">
                        <label for="member-search" class="sr-only">{{ tr('Buscar miembro', 'Search member') }}</label>
                        <input
                            id="member-search"
                            v-model="memberSearch"
                            type="search"
                            class="w-full rounded-lg border-gray-300 text-sm"
                            :placeholder="tr('Buscar por nombre o clase', 'Search by name or class')"
                        >
                    </div>
                </div>

                <div class="mt-5 space-y-4">
                    <article
                        v-for="member in filteredMembers"
                        :key="member.id"
                        class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm"
                    >
                        <button
                            type="button"
                            class="flex w-full flex-col gap-3 p-4 text-left transition hover:bg-gray-50 sm:flex-row sm:items-start sm:justify-between"
                            :class="expandedMemberId === member.id ? 'bg-blue-50/60' : 'bg-white'"
                            @click="toggleMember(member)"
                        >
                            <div>
                                <h3 class="text-lg font-semibold text-gray-900">{{ member.member_name }}</h3>
                                <p class="mt-1 text-sm text-gray-600">{{ member.class_name || tr('Sin clase', 'No class') }}</p>
                                <p class="mt-2 text-xs uppercase tracking-wide text-gray-400">
                                    {{ member.completed_requirements_count }}/{{ member.requirements_count }} {{ tr('evidencias cargadas', 'evidence items loaded') }}
                                </p>
                            </div>
                            <span class="w-fit rounded-full px-3 py-1 text-xs font-semibold ring-1" :class="statusClass(member.status)">
                                {{ statusLabel(member.status) }}
                            </span>
                        </button>

                        <div v-if="expandedMemberId === member.id" class="space-y-3 border-t bg-gray-50 p-4">
                            <article
                                v-for="item in member.requirements"
                                :key="item.review_id"
                                class="overflow-hidden rounded-xl border border-gray-200 bg-white"
                            >
                                <button
                                    type="button"
                                    class="flex w-full flex-col gap-3 p-4 text-left sm:flex-row sm:items-start sm:justify-between"
                                    @click="expandedRequirement = expandedRequirement === item.review_id ? null : item.review_id"
                                >
                                    <div>
                                        <h4 class="font-semibold text-gray-900">
                                            {{ item.requirement?.sort_order }}. {{ item.requirement?.title || tr('Requisito', 'Requirement') }}
                                        </h4>
                                        <p class="mt-1 text-xs text-gray-500">
                                            {{ validationModeLabel(item.requirement?.validation_mode) }} · {{ item.requirement?.requirement_type || '—' }}
                                        </p>
                                    </div>
                                    <span class="w-fit rounded-full px-2.5 py-1 text-xs font-semibold ring-1" :class="statusClass(item.status)">
                                        {{ statusLabel(item.status) }}
                                    </span>
                                </button>

                                <div v-if="expandedRequirement === item.review_id" class="space-y-4 border-t bg-gray-50 p-4">
                                    <div class="rounded-xl border border-gray-200 bg-white p-4">
                                        <p v-if="item.requirement?.description" class="text-sm text-gray-700">{{ item.requirement.description }}</p>
                                        <p v-if="item.requirement?.evidence_instructions" class="mt-2 text-sm text-gray-700">
                                            <span class="font-semibold">{{ tr('Instrucciones:', 'Instructions:') }}</span> {{ item.requirement.evidence_instructions }}
                                        </p>
                                    </div>

                                    <div
                                        v-if="requiresPrintedEvidence(item.requirement)"
                                        class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-950"
                                    >
                                        <p class="font-semibold">{{ tr('Evidencia física requerida', 'Physical evidence required') }}</p>
                                        <p class="mt-1">
                                            {{ tr('Este requisito es', 'This requirement is') }} {{ item.requirement?.validation_mode === 'hybrid' ? tr('híbrido', 'hybrid') : tr('físico', 'physical') }}.
                                            {{ tr('El miembro debe entregar al evaluador el paquete impreso de evidencias correspondiente a este requisito.', 'The member must give the evaluator the printed evidence packet for this requirement.') }}
                                        </p>
                                        <p class="mt-2 text-amber-800">
                                            {{ tr('Revise la evidencia física recibida antes de aprobar este requisito en el sistema.', 'Review the physical evidence received before approving this requirement in the system.') }}
                                        </p>
                                    </div>

                                    <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-950">
                                        <p class="font-semibold">{{ tr('Evidencia entregada', 'Submitted evidence') }}</p>
                                        <template v-if="item.evidence">
                                            <p class="mt-1">{{ evidenceTypeLabel(item.evidence.evidence_type) }}</p>
                                            <a
                                                v-if="item.evidence.file_url && item.evidence.is_image"
                                                :href="item.evidence.file_url"
                                                target="_blank"
                                                rel="noopener"
                                                class="mt-3 block w-fit"
                                            >
                                                <img :src="item.evidence.file_url" :alt="item.requirement?.title || tr('Evidencia', 'Evidence')" class="h-32 w-32 rounded-lg border border-emerald-200 bg-white object-cover shadow-sm">
                                            </a>
                                            <a v-if="item.evidence.file_url" :href="item.evidence.file_url" target="_blank" rel="noopener" class="mt-2 inline-block text-blue-700 underline">
                                                {{ tr('Ver archivo completo', 'View full file') }}
                                            </a>
                                            <div v-if="item.evidence.evidence_type === 'video_link' && item.evidence.text_value" class="mt-3">
                                                <p class="text-xs font-semibold">Video: {{ videoPlatformName(item.evidence.text_value) }}</p>
                                                <div v-if="videoEmbedUrl(item.evidence.text_value)" class="mt-2 aspect-video overflow-hidden rounded-lg border bg-black">
                                                    <iframe :src="videoEmbedUrl(item.evidence.text_value)" class="h-full w-full" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share" allowfullscreen />
                                                </div>
                                                <a v-else :href="item.evidence.text_value" target="_blank" rel="noopener" class="mt-2 inline-block break-all text-blue-700 underline">
                                                    {{ tr('Abrir enlace de video', 'Open video link') }}
                                                </a>
                                            </div>
                                            <p v-else-if="item.evidence.text_value" class="mt-2 break-words">{{ item.evidence.text_value }}</p>
                                            <p v-if="item.evidence.physical_completed" class="mt-2 font-medium">{{ tr('Requisito físico marcado como completado.', 'Physical requirement marked as completed.') }}</p>
                                        </template>
                                        <p v-else class="mt-1 text-amber-800">{{ tr('No hay evidencia enlazada a este requisito.', 'There is no evidence linked to this requirement.') }}</p>
                                    </div>

                                    <div class="rounded-xl border border-gray-200 bg-white p-4">
                                        <label class="block text-sm font-semibold text-gray-900">{{ tr('Notas del evaluador', 'Evaluator notes') }}</label>
                                        <textarea
                                            :value="getReviewNotes(item)"
                                            rows="3"
                                            class="mt-2 w-full rounded-lg border-gray-300 text-sm"
                                            :placeholder="tr('Observaciones opcionales para este requisito', 'Optional observations for this requirement')"
                                            @input="setReviewNotes(item, $event.target.value)"
                                        />
                                        <div class="mt-3 flex flex-col gap-2 sm:flex-row sm:justify-end">
                                            <button
                                                type="button"
                                                class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 disabled:opacity-60"
                                                :disabled="updatingReview === item.review_id"
                                                @click="updateReview(item, 'pending')"
                                            >
                                                {{ tr('Pendiente', 'Pending') }}
                                            </button>
                                            <button
                                                type="button"
                                                class="rounded-lg border border-rose-200 bg-rose-50 px-4 py-2 text-sm font-semibold text-rose-700 hover:bg-rose-100 disabled:opacity-60"
                                                :disabled="updatingReview === item.review_id"
                                                @click="updateReview(item, 'rejected')"
                                            >
                                                {{ tr('Rechazar', 'Reject') }}
                                            </button>
                                            <button
                                                type="button"
                                                class="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700 disabled:opacity-60"
                                                :disabled="updatingReview === item.review_id"
                                                @click="updateReview(item, 'approved')"
                                            >
                                                {{ tr('Aprobar', 'Approve') }}
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </article>
                        </div>
                    </article>

                    <p v-if="!request.members.length" class="rounded-xl border border-dashed border-gray-300 px-4 py-6 text-sm text-gray-500">
                        {{ tr('No hay miembros en esta solicitud.', 'There are no members in this request.') }}
                    </p>
                    <p v-else-if="!filteredMembers.length" class="rounded-xl border border-dashed border-gray-300 px-4 py-6 text-sm text-gray-500">
                        {{ tr('No hay miembros que coincidan con la búsqueda.', 'No members match the search.') }}
                    </p>
                </div>
            </section>
        </div>
    </PathfinderLayout>
</template>
