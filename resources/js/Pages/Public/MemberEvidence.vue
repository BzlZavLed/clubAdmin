<script setup>
import { ref } from 'vue'
import { useForm } from '@inertiajs/vue3'
import GuestLayout from '@/Layouts/GuestLayout.vue'
import { useLocale } from '@/Composables/useLocale'

const props = defineProps({
    code: { type: String, required: true },
    member: { type: Object, required: true },
    expires_at: { type: String, default: null },
    club_logo_url: { type: String, default: null },
})

const expandedRequirement = ref(null)
const fileInputs = ref({})
const drafts = ref({})
const { tr } = useLocale()

const form = useForm({
    member_id: props.member.member_id,
    requirement_id: '',
    evidence_type: 'physical_only',
    text_value: '',
    evidence_file: null,
    physical_completed: true,
})

const evidenceTypeLabel = (type) => ({
    photo: tr('Foto', 'Photo'),
    file: tr('Archivo', 'File'),
    text: tr('Texto', 'Text'),
    video_link: tr('Enlace de video', 'Video link'),
    external_link: tr('Enlace externo', 'External link'),
    physical_only: tr('Físico', 'Physical'),
}[type] || type)

const videoPlatformInstructions = () => [
    tr('YouTube: usa un video No listado y pega el enlace.', 'YouTube: use an unlisted video and paste the link.'),
    tr('iCloud, Google Drive, Dropbox y OneDrive: comparte el video con enlace público o con acceso para revisores, y pega ese enlace.', 'iCloud, Google Drive, Dropbox and OneDrive: share the video with a public link or reviewer access, then paste that link.'),
    tr('No subas videos directamente aquí; esto evita archivos pesados y problemas de almacenamiento.', 'Do not upload videos directly here; this avoids large files and storage issues.'),
]

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

const validationModeLabel = (mode) => ({
    electronic: tr('Evidencia electrónica', 'Electronic evidence'),
    physical: tr('Requisito físico', 'Physical requirement'),
    hybrid: tr('Híbrido', 'Hybrid'),
}[mode] || mode || '—')

const requirementKey = (requirement) => `${props.member.member_id}-${requirement.id}`
const hasElectronicEvidence = (requirement) => requirement.validation_mode !== 'physical'
const hasPhysicalCompletion = (requirement) => ['physical', 'hybrid'].includes(requirement.validation_mode)

const evidenceOptions = (requirement) => {
    if (requirement.validation_mode === 'physical') return ['physical_only']
    const options = (requirement.allowed_evidence_types || []).filter(type => type !== 'physical_only')
    return options.length ? options : ['text']
}

const getDraft = (requirement) => {
    const key = requirementKey(requirement)
    if (!drafts.value[key]) {
        drafts.value[key] = {
            evidence_type: evidenceOptions(requirement)[0],
            text_value: requirement.evidence?.text_value || '',
            physical_completed: Boolean(requirement.evidence?.physical_completed || requirement.validation_mode === 'physical'),
        }
    }
    return drafts.value[key]
}

const updateDraft = (requirement, field, value) => {
    getDraft(requirement)[field] = value
}

const selectFile = (key, event) => {
    fileInputs.value[key] = event.target.files?.[0] || null
}

const submitEvidence = (requirement) => {
    const key = requirementKey(requirement)
    const draft = getDraft(requirement)
    const type = requirement.validation_mode === 'physical' ? 'physical_only' : draft.evidence_type

    form.member_id = props.member.member_id
    form.requirement_id = requirement.id
    form.evidence_type = type
    form.text_value = ['text', 'video_link', 'external_link'].includes(type) ? draft.text_value : ''
    form.evidence_file = ['photo', 'file'].includes(type) ? (fileInputs.value[key] || null) : null
    form.physical_completed = hasPhysicalCompletion(requirement) ? Boolean(draft.physical_completed) : false

    form.post(route('public.member-evidence.store', { code: props.code }), {
        preserveScroll: true,
        forceFormData: true,
        onSuccess: () => {
            fileInputs.value[key] = null
        },
    })
}
</script>

<template>
    <GuestLayout>
        <template #brand>
            <div class="flex min-h-20 min-w-20 flex-col items-center justify-center gap-2">
                <img
                    v-if="club_logo_url"
                    :src="club_logo_url"
                    :alt="member.club_name || tr('Logo del club', 'Club logo')"
                    class="max-h-20 max-w-40 rounded-lg object-contain"
                >
                <div
                    :class="club_logo_url ? 'text-center text-sm font-semibold text-gray-800' : 'max-w-72 rounded-lg border border-gray-200 bg-white px-4 py-3 text-center text-sm font-semibold text-gray-800 shadow-sm'"
                >
                    {{ member.club_name || tr('Club', 'Club') }}
                </div>
            </div>
        </template>

        <div class="mx-auto max-w-4xl space-y-4 px-4 py-6 sm:px-6">
            <section class="rounded-lg border bg-white p-4 shadow-sm sm:p-5">
                <p class="text-xs font-semibold uppercase tracking-wide text-blue-700">{{ tr('Carga pública de evidencias', 'Public evidence upload') }}</p>
                <h1 class="mt-1 text-xl font-semibold text-gray-900">{{ member.name }}</h1>
                <p class="mt-1 text-sm text-gray-600">
                    {{ member.club_name }} · {{ member.class_name || tr('Sin clase', 'No class') }} · {{ member.completed_count }}/{{ member.requirements_count }} {{ tr('requisitos', 'requirements') }}
                </p>
                <div v-if="member.has_evidence" class="mt-4 rounded border border-blue-100 bg-blue-50 p-3">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <p class="text-sm font-semibold text-blue-950">{{ tr('PDF de carpeta disponible', 'Folder PDF available') }}</p>
                            <p class="text-sm text-blue-800">{{ tr('Descarga tu carpeta con las evidencias registradas y validación digital.', 'Download your folder with registered evidence and digital validation.') }}</p>
                        </div>
                        <a
                            :href="route('public.member-evidence.pdf', { code })"
                            target="_blank"
                            rel="noopener"
                            class="inline-flex justify-center rounded bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700"
                        >
                            {{ tr('Descargar PDF', 'Download PDF') }}
                        </a>
                    </div>
                </div>
                <p v-if="expires_at" class="mt-3 rounded bg-amber-50 px-3 py-2 text-sm text-amber-800">
                    {{ tr('Este enlace temporal expira:', 'This temporary link expires:') }} {{ expires_at }}.
                </p>
            </section>

            <section v-if="!member.requirements.length" class="rounded-lg border bg-white p-5 text-sm text-gray-600">
                {{ tr('No hay requisitos publicados para esta clase.', 'There are no published requirements for this class.') }}
            </section>

            <section v-else class="space-y-3">
                <article v-for="requirement in member.requirements" :key="requirement.id" class="overflow-hidden rounded-lg border bg-white shadow-sm">
                    <button type="button" class="flex w-full flex-col gap-3 p-4 text-left sm:flex-row sm:items-start sm:justify-between" @click="expandedRequirement = expandedRequirement === requirementKey(requirement) ? null : requirementKey(requirement)">
                        <div>
                            <div class="font-semibold text-gray-900">{{ requirement.sort_order }}. {{ requirement.title }}</div>
                            <div class="mt-1 text-xs text-gray-500">{{ validationModeLabel(requirement.validation_mode) }}</div>
                        </div>
                        <span class="w-fit rounded-full px-2 py-1 text-xs font-medium" :class="requirement.completed ? 'bg-emerald-100 text-emerald-700' : 'bg-gray-100 text-gray-600'">
                            {{ requirement.completed ? tr('Entregado', 'Submitted') : tr('Pendiente', 'Pending') }}
                        </span>
                    </button>

                    <div v-if="expandedRequirement === requirementKey(requirement)" class="border-t bg-gray-50 p-4">
                        <p v-if="requirement.description" class="text-sm text-gray-700">{{ requirement.description }}</p>
                        <p v-if="requirement.evidence_instructions" class="mt-2 text-sm text-gray-700">
                            <span class="font-semibold">{{ tr('Instrucciones:', 'Instructions:') }}</span> {{ requirement.evidence_instructions }}
                        </p>

                        <div v-if="requirement.evidence" class="mt-3 rounded border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-900">
                            <div class="font-semibold">{{ tr('Evidencia actual', 'Current evidence') }}</div>
                            <div>{{ evidenceTypeLabel(requirement.evidence.evidence_type) }}</div>
                            <a v-if="requirement.evidence.file_url && requirement.evidence.is_image" :href="requirement.evidence.file_url" target="_blank" rel="noopener" class="mt-2 block w-fit">
                                <img :src="requirement.evidence.file_url" :alt="requirement.title" class="h-24 w-24 rounded border border-emerald-200 object-cover shadow-sm" />
                            </a>
                            <a v-if="requirement.evidence.file_url" :href="requirement.evidence.file_url" target="_blank" rel="noopener" class="text-blue-700 underline">{{ tr('Ver archivo', 'View file') }}</a>
                            <div v-if="requirement.evidence.evidence_type === 'video_link' && requirement.evidence.text_value" class="mt-3">
                                <p class="text-xs font-semibold text-emerald-900">Video: {{ videoPlatformName(requirement.evidence.text_value) }}</p>
                                <div v-if="videoEmbedUrl(requirement.evidence.text_value)" class="mt-2 aspect-video overflow-hidden rounded border bg-black">
                                    <iframe :src="videoEmbedUrl(requirement.evidence.text_value)" class="h-full w-full" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share" allowfullscreen />
                                </div>
                                <a v-else :href="requirement.evidence.text_value" target="_blank" rel="noopener" class="mt-2 inline-block break-all text-blue-700 underline">{{ tr('Abrir enlace de video', 'Open video link') }}</a>
                            </div>
                            <div v-if="requirement.evidence.text_value" class="break-words">{{ requirement.evidence.text_value }}</div>
                        </div>

                        <div class="mt-4 space-y-3">
                            <div v-if="hasElectronicEvidence(requirement)">
                                <label class="block text-sm font-medium text-gray-700">{{ tr('Tipo de evidencia', 'Evidence type') }}</label>
                                <select :value="getDraft(requirement).evidence_type" class="mt-1 w-full rounded border p-3 text-base sm:p-2 sm:text-sm" @change="updateDraft(requirement, 'evidence_type', $event.target.value)">
                                    <option v-for="type in evidenceOptions(requirement)" :key="type" :value="type">
                                        {{ evidenceTypeLabel(type) }}
                                    </option>
                                </select>
                            </div>

                            <div v-if="hasElectronicEvidence(requirement) && ['photo', 'file'].includes(getDraft(requirement).evidence_type)">
                                <label class="block text-sm font-medium text-gray-700">{{ tr('Archivo', 'File') }}</label>
                                <input type="file" class="mt-1 w-full rounded border bg-white p-3 text-base sm:p-2 sm:text-sm" @change="selectFile(requirementKey(requirement), $event)" />
                            </div>

                            <div v-if="hasElectronicEvidence(requirement) && ['text', 'video_link', 'external_link'].includes(getDraft(requirement).evidence_type)">
                                <label class="block text-sm font-medium text-gray-700">{{ tr('Evidencia', 'Evidence') }}</label>
                                <div v-if="getDraft(requirement).evidence_type === 'video_link'" class="mt-1 rounded border border-blue-100 bg-blue-50 p-3 text-xs text-blue-900">
                                    <p class="font-semibold">{{ tr('Evidencia en video por enlace', 'Video evidence by link') }}</p>
                                    <ul class="mt-2 list-disc space-y-1 pl-4">
                                        <li v-for="instruction in videoPlatformInstructions()" :key="instruction">{{ instruction }}</li>
                                    </ul>
                                </div>
                                <textarea :value="getDraft(requirement).text_value" rows="3" class="mt-1 w-full rounded border p-3 text-base sm:p-2 sm:text-sm" @input="updateDraft(requirement, 'text_value', $event.target.value)" />
                            </div>

                            <label v-if="hasPhysicalCompletion(requirement)" class="flex items-start gap-3 rounded border bg-white p-3 text-sm text-gray-700 sm:items-center sm:border-0 sm:bg-transparent sm:p-0">
                                <input :checked="getDraft(requirement).physical_completed" type="checkbox" class="mt-0.5 h-5 w-5 sm:mt-0 sm:h-4 sm:w-4" @change="updateDraft(requirement, 'physical_completed', $event.target.checked)" />
                                {{ tr('Marcar requisito físico como completado', 'Mark physical requirement as completed') }}
                            </label>

                            <div v-if="Object.keys(form.errors).length" class="rounded border border-red-200 bg-red-50 p-3 text-sm text-red-700">
                                {{ Object.values(form.errors)[0] }}
                            </div>

                            <button type="button" class="w-full rounded bg-blue-600 px-4 py-3 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-60 sm:w-auto sm:py-2" :disabled="form.processing" @click="submitEvidence(requirement)">
                                {{ form.processing ? tr('Guardando...', 'Saving...') : tr('Guardar evidencia', 'Save evidence') }}
                            </button>
                        </div>
                    </div>
                </article>
            </section>
        </div>
    </GuestLayout>
</template>
