<script setup>
import { ref, watch, computed } from 'vue'
import axios from 'axios'

const props = defineProps({
    eventId: { type: Number, required: true },
    documents: { type: Array, default: () => [] },
    participants: { type: Array, default: () => [] },
    staff: { type: Array, default: () => [] },
    parents: { type: Array, default: () => [] },
    preset: { type: Object, default: null },
})

const emit = defineEmits(['updated'])

const localDocs = ref([...props.documents])
const form = ref({
    docType: '',
    title: '',
    file: null,
    participantIds: [],
    staffIds: [],
    parentIds: [],
    otherParticipantIds: [],
    driverParticipantId: null,
})
const uploading = ref(false)
const formErrors = ref({})

const docTypeOptions = [
    'permission_slip',
    'medical_release',
    'insurance_doc',
    'rental_agreement',
    'driver_license',
    'vehicle_insurance',
    'waiver',
    'vendor_invoice',
    'route_map',
    'other',
]

const isMemberDoc = (docType) => {
    if (!docType) return false
    const text = docType.toLowerCase()
    return ['release', 'docs', 'slip', 'permission', 'medical', 'insurance', 'license', 'rental'].some((keyword) => text.includes(keyword))
}

const memberOptions = computed(() => {
    return props.participants
        .filter((p) => p.member_id)
        .map((p) => ({
            id: p.member_id,
            label: p.participant_name,
        }))
})

const staffOptions = computed(() => {
    return props.participants
        .filter((p) => p.role === 'staff')
        .map((p) => {
            const staffMatch = props.staff.find((s) => s.name === p.participant_name)
            return {
                id: staffMatch?.id || null,
                label: p.participant_name,
                disabled: !staffMatch?.id,
            }
        })
})

const parentOptions = computed(() => {
    return props.participants
        .filter((p) => p.role === 'parent')
        .map((p) => {
            const parentMatch = props.parents.find((parent) => parent.name === p.participant_name)
            return {
                id: parentMatch?.id || null,
                label: p.participant_name,
                disabled: !parentMatch?.id,
            }
        })
})

const otherOptions = computed(() => {
    return props.participants
        .filter((p) => !p.member_id && !['staff', 'parent'].includes(p.role))
        .map((p) => ({
            id: p.id,
            label: p.participant_name,
        }))
})

const driverOptions = computed(() => {
    return props.participants
        .filter((p) => p.role === 'driver')
        .map((p) => ({
            id: p.id,
            label: p.participant_name,
        }))
})

const isDriverLicense = computed(() => {
    return (form.value.docType || '').toLowerCase().includes('license')
})

const docParticipantsLabel = (doc) => {
    const labels = []
    const participants = props.participants || []

    if (doc?.member_id) {
        const match = participants.find((p) => p.member_id === doc.member_id)
        labels.push(match?.participant_name || `Member #${doc.member_id}`)
    }

    if (doc?.staff_id) {
        const staffMatch = props.staff.find((staff) => staff.id === doc.staff_id)
        labels.push(staffMatch?.name || `Staff #${doc.staff_id}`)
    }

    if (doc?.parent_id) {
        const parentMatch = props.parents.find((parent) => parent.id === doc.parent_id)
        labels.push(parentMatch?.name || `Parent #${doc.parent_id}`)
    }

    if (doc?.driver_participant_id) {
        const match = participants.find((p) => p.id === doc.driver_participant_id)
        labels.push(match?.participant_name || `Driver #${doc.driver_participant_id}`)
    }

    if (doc?.vehicle_id) {
        labels.push(`Vehicle #${doc.vehicle_id}`)
    }

    const meta = doc?.meta_json || {}
    const metaMemberIds = Array.isArray(meta.member_ids) ? meta.member_ids : []
    const metaParticipantIds = Array.isArray(meta.participant_ids) ? meta.participant_ids : []

    metaMemberIds.forEach((memberId) => {
        const match = participants.find((p) => p.member_id === memberId)
        labels.push(match?.participant_name || `Member #${memberId}`)
    })
    metaParticipantIds.forEach((participantId) => {
        const match = participants.find((p) => p.id === participantId)
        labels.push(match?.participant_name || `Participant #${participantId}`)
    })

    return [...new Set(labels)].join(', ')
}

watch(
    () => props.documents,
    (value) => {
        localDocs.value = [...value]
    }
)

watch(
    () => props.preset,
    (value) => {
        if (!value) return
        form.value.docType = value.docType || form.value.docType
    },
    { deep: true }
)

const onFileChange = (event) => {
    form.value.file = event.target.files[0] || null
}

const upload = async () => {
    if (!form.value.file) return
    uploading.value = true
    formErrors.value = {}

    const fd = new FormData()
    fd.append('doc_type', form.value.docType)
    fd.append('type', form.value.docType)
    fd.append('title', form.value.title)
    fd.append('file', form.value.file)
    form.value.participantIds.forEach((id) => fd.append('member_ids[]', id))
    form.value.staffIds.forEach((id) => fd.append('staff_ids[]', id))
    form.value.parentIds.forEach((id) => fd.append('parent_ids[]', id))
    form.value.otherParticipantIds.forEach((id) => fd.append('participant_ids[]', id))
    if (isDriverLicense.value && form.value.driverParticipantId) {
        fd.append('driver_participant_id', form.value.driverParticipantId)
    }

    try {
        const { data } = await axios.post(route('event-documents.store', { event: props.eventId }), fd)
        localDocs.value = [data.document, ...localDocs.value]
        emit('updated', localDocs.value)
        form.value = {
            docType: '',
            title: '',
            file: null,
            participantIds: [],
            staffIds: [],
            parentIds: [],
            otherParticipantIds: [],
            driverParticipantId: null,
        }
    } catch (err) {
        if (err?.response?.status === 422) {
            formErrors.value = err.response.data.errors || {}
        }
    } finally {
        uploading.value = false
    }
}

const removeDoc = async (doc) => {
    await axios.delete(route('event-documents.destroy', { eventDocument: doc.id }))
    localDocs.value = localDocs.value.filter(item => item.id !== doc.id)
    emit('updated', localDocs.value)
}
</script>

<template>
    <div class="space-y-4">
        <div class="bg-white rounded-lg border p-4 space-y-3">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                <select v-model="form.docType" class="border rounded px-3 py-2 text-sm">
                    <option value="">Select document type</option>
                    <option v-for="option in docTypeOptions" :key="option" :value="option">{{ option }}</option>
                </select>
                <input v-model="form.title" class="border rounded px-3 py-2 text-sm" placeholder="Title" />
                <input type="file" @change="onFileChange" class="text-sm" />
            </div>
            <div v-if="formErrors.title" class="text-xs text-red-600">
                {{ formErrors.title[0] }}
            </div>
            <div v-if="formErrors.doc_type" class="text-xs text-red-600">
                {{ formErrors.doc_type[0] }}
            </div>
            <div v-if="formErrors.file" class="text-xs text-red-600">
                {{ formErrors.file[0] }}
            </div>

            <div v-if="isMemberDoc(form.docType)" class="grid grid-cols-1 md:grid-cols-4 gap-3">
                <div>
                    <div class="text-xs font-semibold text-gray-600 mb-1">Participants</div>
                    <select v-model="form.participantIds" multiple class="w-full border rounded px-2 py-1 text-sm">
                        <option v-for="participant in memberOptions" :key="participant.id" :value="participant.id">
                            {{ participant.label }}
                        </option>
                    </select>
                </div>
                <div>
                    <div class="text-xs font-semibold text-gray-600 mb-1">Staff</div>
                    <select v-model="form.staffIds" multiple class="w-full border rounded px-2 py-1 text-sm">
                        <option v-for="staffMember in staffOptions" :key="staffMember.label" :value="staffMember.id" :disabled="staffMember.disabled">
                            {{ staffMember.label }}{{ staffMember.disabled ? ' (no linked staff record)' : '' }}
                        </option>
                    </select>
                </div>
                <div>
                    <div class="text-xs font-semibold text-gray-600 mb-1">Parents</div>
                    <select v-model="form.parentIds" multiple class="w-full border rounded px-2 py-1 text-sm">
                        <option v-for="parent in parentOptions" :key="parent.label" :value="parent.id" :disabled="parent.disabled">
                            {{ parent.label }}{{ parent.disabled ? ' (no linked parent record)' : '' }}
                        </option>
                    </select>
                </div>
                <div>
                    <div class="text-xs font-semibold text-gray-600 mb-1">Other Participants</div>
                    <select v-model="form.otherParticipantIds" multiple class="w-full border rounded px-2 py-1 text-sm">
                        <option v-for="other in otherOptions" :key="other.id" :value="other.id">
                            {{ other.label }}
                        </option>
                    </select>
                </div>
            </div>
            <div v-if="isDriverLicense" class="mt-3">
                <div class="text-xs font-semibold text-gray-600 mb-1">Driver (license)</div>
                <select v-model="form.driverParticipantId" class="w-full border rounded px-2 py-1 text-sm">
                    <option value="">Select driver</option>
                    <option v-for="driver in driverOptions" :key="driver.id" :value="driver.id">
                        {{ driver.label }}
                    </option>
                </select>
            </div>

            <button @click="upload" :disabled="uploading" class="px-4 py-2 bg-blue-600 text-white rounded text-sm">
                {{ uploading ? 'Uploading...' : 'Upload document' }}
            </button>
        </div>

        <div class="bg-white rounded-lg border">
            <table class="min-w-full text-sm">
                <thead class="bg-gray-50 text-gray-600">
                    <tr>
                        <th class="text-left px-4 py-2">Title</th>
                        <th class="text-left px-4 py-2">Type</th>
                        <th class="text-left px-4 py-2">Participant</th>
                        <th class="text-left px-4 py-2">File</th>
                        <th class="text-right px-4 py-2">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="doc in localDocs" :key="doc.id" class="border-t">
                        <td class="px-4 py-2">{{ doc.title }}</td>
                        <td class="px-4 py-2">{{ doc.doc_type || doc.type }}</td>
                        <td class="px-4 py-2 text-sm text-gray-600">
                            <span v-if="docParticipantsLabel(doc)">{{ docParticipantsLabel(doc) }}</span>
                            <span v-else class="text-gray-400">—</span>
                        </td>
                        <td class="px-4 py-2">
                            <a
                                v-if="doc.path"
                                :href="`/storage/${doc.path}`"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="text-blue-600 text-sm"
                            >
                                Open
                            </a>
                            <span v-else class="text-gray-400 text-sm">—</span>
                        </td>
                        <td class="px-4 py-2 text-right">
                            <button @click="removeDoc(doc)" class="text-red-600 text-sm">Delete</button>
                        </td>
                    </tr>
                    <tr v-if="!localDocs.length">
                        <td colspan="5" class="px-4 py-6 text-center text-gray-500">No documents yet.</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
