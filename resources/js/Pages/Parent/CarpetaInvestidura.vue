<script setup>
import { computed, ref, watch } from 'vue'
import { useForm } from '@inertiajs/vue3'
import PathfinderLayout from '@/Layouts/PathfinderLayout.vue'
import { useGeneral } from '@/Composables/useGeneral'
import { useLocale } from '@/Composables/useLocale'

const props = defineProps({
    children: { type: Array, default: () => [] },
    pathfinderEvidenceLinks: { type: Array, default: () => [] },
})

const { showToast } = useGeneral()
const { tr } = useLocale()
const expandedChildren = ref(new Set())
const expandedRequirements = ref({})
const fileInputs = ref({})
const drafts = ref({})

const form = useForm({
    member_id: '',
    requirement_id: '',
    evidence_type: 'physical_only',
    text_value: '',
    evidence_file: null,
    physical_completed: true,
})

const evidenceTypeLabels = {
    photo: 'Foto',
    file: 'Archivo',
    text: 'Texto',
    video_link: 'Enlace de video',
    external_link: 'Enlace externo',
    physical_only: 'Fisico',
}

const videoPlatformInstructions = [
    tr('YouTube: usa un video No listado y pega el enlace.', 'YouTube: use an Unlisted video and paste the link.'),
    tr('iCloud, Google Drive, Dropbox y OneDrive: comparte el video con enlace publico o con acceso para revisores, y pega ese enlace.', 'iCloud, Google Drive, Dropbox and OneDrive: share the video with a public link or reviewer access, then paste that link.'),
    tr('No subas videos directamente aqui; esto evita archivos pesados y problemas de almacenamiento.', 'Do not upload videos directly here; this avoids heavy files and storage issues.'),
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
    electronic: 'Evidencia electronica',
    physical: 'Requisito fisico',
    hybrid: 'Hibrido',
}[mode] || mode || '—')

const childKey = (child) => `child-${child.member_id}`
const requirementKey = (child, requirement) => `${child.member_id}-${requirement.id}`

const toggleSet = (setRef, key) => {
    const next = new Set(setRef.value)
    next.has(key) ? next.delete(key) : next.add(key)
    setRef.value = next
}

const isRequirementExpanded = (child, requirement) => (
    expandedRequirements.value[child.member_id] === requirementKey(child, requirement)
)

const toggleRequirement = (child, requirement) => {
    const key = requirementKey(child, requirement)
    expandedRequirements.value = {
        ...expandedRequirements.value,
        [child.member_id]: expandedRequirements.value[child.member_id] === key ? null : key,
    }
}

const hasElectronicEvidence = (requirement) => requirement.validation_mode !== 'physical'
const hasPhysicalCompletion = (requirement) => ['physical', 'hybrid'].includes(requirement.validation_mode)

const evidenceOptions = (requirement) => {
    if (requirement.validation_mode === 'physical') return ['physical_only']

    const options = (requirement.allowed_evidence_types || []).filter(type => type !== 'physical_only')
    return options.length ? options : ['text']
}

const requirementActionLabel = (requirement) => {
    if (requirement.completed) return tr('Ver / actualizar evidencia', 'View / update evidence')
    if (requirement.validation_mode === 'physical') return tr('Marcar como completado', 'Mark completed')
    return tr('Subir evidencia', 'Upload evidence')
}

watch(
    () => props.children,
    (children) => {
        expandedChildren.value = new Set(children.map(childKey))
        expandedRequirements.value = {}
    },
    { immediate: true }
)

const getDraft = (child, requirement) => {
    const key = requirementKey(child, requirement)
    if (!drafts.value[key]) {
        drafts.value[key] = {
            evidence_type: evidenceOptions(requirement)[0],
            text_value: requirement.evidence?.text_value || '',
            physical_completed: Boolean(requirement.evidence?.physical_completed || requirement.validation_mode === 'physical'),
        }
    }
    return drafts.value[key]
}

const evidenceSummary = computed(() =>
    props.children
        .filter(child => child.all_completed)
        .map(child => ({
            ...child,
            requirements: child.requirements.filter(req => req.evidence),
        }))
)

const selectFile = (key, event) => {
    fileInputs.value[key] = event.target.files?.[0] || null
}

const updateDraft = (child, requirement, field, value) => {
    getDraft(child, requirement)[field] = value
}

const submitEvidence = (child, requirement) => {
    const key = requirementKey(child, requirement)
    const draft = getDraft(child, requirement)
    const type = requirement.validation_mode === 'physical' ? 'physical_only' : draft.evidence_type

    form.member_id = child.member_id
    form.requirement_id = requirement.id
    form.evidence_type = type
    form.text_value = ['text', 'video_link', 'external_link'].includes(type) ? draft.text_value : ''
    form.evidence_file = ['photo', 'file'].includes(type) ? (fileInputs.value[key] || null) : null
    form.physical_completed = hasPhysicalCompletion(requirement) ? Boolean(draft.physical_completed) : false

    form.post(route('parent.carpeta-investidura.evidence.store'), {
        preserveScroll: true,
        forceFormData: true,
        onSuccess: () => {
            showToast(tr('Evidencia guardada', 'Evidence saved'), 'success')
            fileInputs.value[key] = null
        },
        onError: () => {
            const firstError = Object.values(form.errors)[0]
            showToast(firstError || tr('No se pudo guardar la evidencia', 'Could not save evidence'), 'error')
        },
    })
}
</script>

<template>
    <PathfinderLayout>
        <template #title>{{ tr('Carpeta de investidura', 'Investiture folder') }}</template>

        <div class="space-y-4">
            <div class="rounded-lg border bg-white p-4 shadow-sm sm:p-5">
                <h1 class="text-lg font-semibold text-gray-900 sm:text-xl">{{ tr('Carpeta de investidura', 'Investiture folder') }}</h1>
                <p class="mt-1 text-sm text-gray-600">
                    {{ tr('Sube evidencias o marca requisitos fisicos para los hijos vinculados a tu cuenta.', 'Upload evidence or mark physical requirements for children linked to your account.') }}
                </p>
                <p class="mt-3 rounded bg-blue-50 px-3 py-2 text-sm text-blue-800">
                    {{ tr('Cada hijo aparece abierto. Toca un requisito para expandirlo y subir evidencia o marcarlo como completado.', 'Each child appears open. Tap a requirement to expand it and upload evidence or mark it completed.') }}
                </p>
            </div>

            <div v-if="pathfinderEvidenceLinks.length" class="rounded-lg border border-blue-200 bg-blue-50 p-4 shadow-sm sm:p-5">
                <h2 class="text-base font-semibold text-blue-950">{{ tr('Evidencias de conquistadores', 'Pathfinder evidence') }}</h2>
                <div class="mt-3 space-y-3">
                    <div v-for="link in pathfinderEvidenceLinks" :key="link.member_id" class="rounded border border-blue-100 bg-white p-3">
                        <p class="text-sm font-semibold text-gray-900">{{ link.name }}</p>
                        <p class="mt-1 text-sm text-gray-600">{{ link.club_name }}<span v-if="link.grade"> • {{ link.grade }}</span></p>
                        <p class="mt-2 text-sm text-blue-900">
                            Para subir evidencias de la carpeta de requisitos de conquistador por favor use este enlace
                        </p>
                        <a
                            :href="link.url"
                            target="_blank"
                            rel="noopener"
                            class="mt-2 inline-flex break-all rounded bg-blue-600 px-3 py-2 text-sm font-medium text-white hover:bg-blue-700"
                        >
                            {{ link.url }}
                        </a>
                        <p v-if="link.expires_at" class="mt-2 text-xs text-blue-800">
                            Expira: {{ link.expires_at }}
                        </p>
                    </div>
                </div>
            </div>

            <div v-if="!children.length" class="rounded-lg border bg-white p-5 text-sm text-gray-600">
                {{ tr('No tienes hijos adventureros en clubes con sistema de carpetas o aun no tienen una clase asignada.', 'You do not have adventurer children in carpeta clubs or they do not have an assigned class yet.') }}
            </div>

            <div v-for="child in children" :key="child.member_id" class="overflow-hidden rounded-lg border bg-white shadow-sm">
                <button type="button" class="flex w-full flex-col gap-3 p-4 text-left sm:flex-row sm:items-center sm:justify-between sm:p-5" @click="toggleSet(expandedChildren, childKey(child))">
                    <div class="min-w-0">
                        <h2 class="break-words text-base font-semibold text-gray-900 sm:text-lg">{{ child.name }}</h2>
                        <p class="text-sm text-gray-600">
                            {{ child.club_name }} • {{ child.class_name || 'Sin clase' }} • {{ child.completed_count }}/{{ child.requirements_count }} requisitos
                        </p>
                    </div>
                    <span class="w-fit rounded-full px-3 py-1 text-xs font-medium" :class="child.all_completed ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700'">
                        {{ child.all_completed ? tr('Completa', 'Complete') : tr('En progreso', 'In progress') }}
                    </span>
                </button>

                <div v-if="expandedChildren.has(childKey(child))" class="border-t p-3 sm:p-5">
                    <div v-if="child.has_evidence" class="mb-4 rounded border border-blue-100 bg-blue-50 p-3 text-sm text-blue-900">
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                            <div>
                                <div class="font-semibold">{{ tr('PDF de carpeta disponible', 'Folder PDF available') }}</div>
                                <div>{{ tr('Incluye las evidencias registradas y un código de validación antifalsificación.', 'Includes submitted evidence and an anti-forgery validation code.') }}</div>
                            </div>
                            <a
                                :href="route('parent.carpeta-investidura.pdf', child.member_id)"
                                target="_blank"
                                class="inline-flex w-full justify-center rounded bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 sm:w-auto"
                            >
                                {{ tr('Descargar PDF', 'Download PDF') }}
                            </a>
                        </div>
                    </div>

                    <div v-if="!child.requirements.length" class="text-sm text-gray-600">
                        {{ tr('No hay requisitos publicados para esta clase.', 'No requirements are published for this class.') }}
                    </div>

                    <div v-else class="space-y-3">
                        <div v-for="requirement in child.requirements" :key="requirement.id" class="rounded border border-gray-200">
                            <button type="button" class="flex w-full flex-col gap-3 p-4 text-left sm:flex-row sm:items-start sm:justify-between sm:gap-4" @click="toggleRequirement(child, requirement)">
                                <div class="min-w-0">
                                    <div class="font-semibold text-gray-900">{{ requirement.sort_order }}. {{ requirement.title }}</div>
                                    <div class="mt-1 text-xs text-gray-500">{{ validationModeLabel(requirement.validation_mode) }}</div>
                                </div>
                                <div class="flex flex-wrap gap-2 sm:flex-col sm:items-end">
                                    <span class="rounded-full px-2 py-1 text-xs font-medium" :class="requirement.completed ? 'bg-emerald-100 text-emerald-700' : 'bg-gray-100 text-gray-600'">
                                        {{ requirement.completed ? tr('Entregado', 'Submitted') : tr('Pendiente', 'Pending') }}
                                    </span>
                                    <span class="rounded bg-blue-600 px-3 py-1 text-xs font-medium text-white sm:whitespace-nowrap">
                                        {{ requirementActionLabel(requirement) }}
                                    </span>
                                </div>
                            </button>

                            <div v-if="isRequirementExpanded(child, requirement)" class="border-t bg-gray-50 p-3 sm:p-4">
                                <p v-if="requirement.description" class="text-sm text-gray-700">{{ requirement.description }}</p>
                                <p v-if="requirement.evidence_instructions" class="mt-2 text-sm text-gray-700">
                                    <span class="font-semibold">{{ tr('Instrucciones', 'Instructions') }}:</span> {{ requirement.evidence_instructions }}
                                </p>

                                <div v-if="requirement.evidence" class="mt-3 rounded border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-900">
                                    <div class="font-semibold">{{ tr('Evidencia actual', 'Current evidence') }}</div>
                                    <div>{{ evidenceTypeLabels[requirement.evidence.evidence_type] || requirement.evidence.evidence_type }}</div>
                                    <a
                                        v-if="requirement.evidence.file_url && requirement.evidence.is_image"
                                        :href="requirement.evidence.file_url"
                                        target="_blank"
                                        rel="noopener"
                                        class="mt-2 block w-fit"
                                    >
                                        <img
                                            :src="requirement.evidence.file_url"
                                            :alt="requirement.title"
                                            class="h-24 w-24 rounded border border-emerald-200 object-cover shadow-sm"
                                        />
                                    </a>
                                    <a v-if="requirement.evidence.file_url" :href="requirement.evidence.file_url" target="_blank" rel="noopener" class="text-blue-700 underline">
                                        {{ tr('Ver archivo', 'View file') }}
                                    </a>
                                    <div v-if="requirement.evidence.evidence_type === 'video_link' && requirement.evidence.text_value" class="mt-3">
                                        <p class="text-xs font-semibold text-emerald-900">
                                            {{ tr('Video', 'Video') }}: {{ videoPlatformName(requirement.evidence.text_value) }}
                                        </p>
                                        <div v-if="videoEmbedUrl(requirement.evidence.text_value)" class="mt-2 aspect-video overflow-hidden rounded border bg-black">
                                            <iframe
                                                :src="videoEmbedUrl(requirement.evidence.text_value)"
                                                class="h-full w-full"
                                                allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share"
                                                allowfullscreen
                                            />
                                        </div>
                                        <a
                                            v-else
                                            :href="requirement.evidence.text_value"
                                            target="_blank"
                                            rel="noopener"
                                            class="mt-2 inline-block break-all text-blue-700 underline"
                                        >
                                            {{ tr('Abrir enlace de video', 'Open video link') }}
                                        </a>
                                    </div>
                                    <div v-if="requirement.evidence.text_value" class="break-words">{{ requirement.evidence.text_value }}</div>
                                </div>

                                <div class="mt-4 space-y-3">
                                    <div v-if="hasElectronicEvidence(requirement)">
                                        <label class="block text-sm font-medium text-gray-700">{{ tr('Tipo de evidencia', 'Evidence type') }}</label>
                                        <select
                                            :value="getDraft(child, requirement).evidence_type"
                                            class="mt-1 w-full rounded border p-3 text-base sm:p-2 sm:text-sm"
                                            @change="updateDraft(child, requirement, 'evidence_type', $event.target.value)"
                                        >
                                            <option v-for="type in evidenceOptions(requirement)" :key="type" :value="type">
                                                {{ evidenceTypeLabels[type] || type }}
                                            </option>
                                        </select>
                                    </div>

                                    <div v-if="hasElectronicEvidence(requirement) && ['photo', 'file'].includes(getDraft(child, requirement).evidence_type)">
                                        <label class="block text-sm font-medium text-gray-700">{{ tr('Archivo', 'File') }}</label>
                                        <input type="file" class="mt-1 w-full rounded border bg-white p-3 text-base sm:p-2 sm:text-sm" @change="selectFile(requirementKey(child, requirement), $event)" />
                                    </div>

                                    <div v-if="hasElectronicEvidence(requirement) && ['text', 'video_link', 'external_link'].includes(getDraft(child, requirement).evidence_type)">
                                        <label class="block text-sm font-medium text-gray-700">{{ tr('Evidencia', 'Evidence') }}</label>
                                        <div v-if="getDraft(child, requirement).evidence_type === 'video_link'" class="mt-1 rounded border border-blue-100 bg-blue-50 p-3 text-xs text-blue-900">
                                            <p class="font-semibold">{{ tr('Evidencia en video por enlace', 'Video evidence by link') }}</p>
                                            <ul class="mt-2 list-disc space-y-1 pl-4">
                                                <li v-for="instruction in videoPlatformInstructions" :key="instruction">{{ instruction }}</li>
                                            </ul>
                                        </div>
                                        <textarea
                                            :value="getDraft(child, requirement).text_value"
                                            rows="3"
                                            class="mt-1 w-full rounded border p-3 text-base sm:p-2 sm:text-sm"
                                            @input="updateDraft(child, requirement, 'text_value', $event.target.value)"
                                        />
                                        <div v-if="getDraft(child, requirement).evidence_type === 'video_link' && getDraft(child, requirement).text_value" class="mt-2 rounded border bg-white p-3 text-sm">
                                            <p class="font-medium text-gray-800">
                                                {{ tr('Plataforma detectada', 'Detected platform') }}: {{ videoPlatformName(getDraft(child, requirement).text_value) }}
                                            </p>
                                            <div v-if="videoEmbedUrl(getDraft(child, requirement).text_value)" class="mt-3 aspect-video overflow-hidden rounded border bg-black">
                                                <iframe
                                                    :src="videoEmbedUrl(getDraft(child, requirement).text_value)"
                                                    class="h-full w-full"
                                                    allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share"
                                                    allowfullscreen
                                                />
                                            </div>
                                            <a
                                                v-else
                                                :href="getDraft(child, requirement).text_value"
                                                target="_blank"
                                                rel="noopener"
                                                class="mt-2 inline-block break-all text-blue-700 underline"
                                            >
                                                {{ tr('Abrir enlace de video', 'Open video link') }}
                                            </a>
                                        </div>
                                    </div>

                                    <label v-if="hasPhysicalCompletion(requirement)" class="flex items-start gap-3 rounded border bg-white p-3 text-sm text-gray-700 sm:items-center sm:border-0 sm:bg-transparent sm:p-0">
                                        <input
                                            :checked="getDraft(child, requirement).physical_completed"
                                            type="checkbox"
                                            class="mt-0.5 h-5 w-5 sm:mt-0 sm:h-4 sm:w-4"
                                            @change="updateDraft(child, requirement, 'physical_completed', $event.target.checked)"
                                        />
                                        {{ tr('Marcar requisito fisico como completado', 'Mark physical requirement completed') }}
                                    </label>

                                    <button type="button" class="w-full rounded bg-blue-600 px-4 py-3 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-60 sm:w-auto sm:py-2" :disabled="form.processing" @click="submitEvidence(child, requirement)">
                                        {{ form.processing ? tr('Guardando...', 'Saving...') : tr('Guardar evidencia', 'Save evidence') }}
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div v-if="evidenceSummary.length" class="rounded-lg border bg-white p-4 shadow-sm sm:p-5">
                <h2 class="text-lg font-semibold text-gray-900">{{ tr('Resumen de evidencias completas', 'Completed evidence summary') }}</h2>
                <div v-for="child in evidenceSummary" :key="`summary-${child.member_id}`" class="mt-4">
                    <h3 class="font-semibold text-gray-800">{{ child.name }}</h3>
                    <ul class="mt-2 space-y-2 text-sm">
                        <li v-for="requirement in child.requirements" :key="`summary-${child.member_id}-${requirement.id}`" class="rounded border p-3">
                            <div class="font-medium">{{ requirement.title }}</div>
                            <a v-if="requirement.evidence?.file_url && requirement.evidence?.is_image" :href="requirement.evidence.file_url" target="_blank" rel="noopener" class="mt-2 block w-fit">
                                <img :src="requirement.evidence.file_url" :alt="requirement.title" class="h-20 w-20 rounded border object-cover shadow-sm" />
                            </a>
                            <a v-if="requirement.evidence?.file_url" :href="requirement.evidence.file_url" target="_blank" rel="noopener" class="text-blue-700 underline">{{ tr('Archivo', 'File') }}</a>
                            <div v-if="requirement.evidence?.evidence_type === 'video_link' && requirement.evidence?.text_value" class="mt-2">
                                <div v-if="videoEmbedUrl(requirement.evidence.text_value)" class="aspect-video overflow-hidden rounded border bg-black">
                                    <iframe
                                        :src="videoEmbedUrl(requirement.evidence.text_value)"
                                        class="h-full w-full"
                                        allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share"
                                        allowfullscreen
                                    />
                                </div>
                                <a
                                    v-else
                                    :href="requirement.evidence.text_value"
                                    target="_blank"
                                    rel="noopener"
                                    class="inline-block break-all text-blue-700 underline"
                                >
                                    {{ videoPlatformName(requirement.evidence.text_value) }}
                                </a>
                            </div>
                            <div v-if="requirement.evidence?.text_value" class="break-words text-gray-700">{{ requirement.evidence.text_value }}</div>
                            <div v-if="requirement.evidence?.physical_completed" class="text-gray-700">{{ tr('Completado fisicamente', 'Completed physically') }}</div>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </PathfinderLayout>
</template>
