<script setup>
import { ref, watch } from 'vue'
import axios from 'axios'

const props = defineProps({
    eventId: { type: Number, required: true },
    documents: { type: Array, default: () => [] }
})

const emit = defineEmits(['updated'])

const localDocs = ref([...props.documents])
const form = ref({ type: '', title: '', file: null })
const uploading = ref(false)

watch(
    () => props.documents,
    (value) => {
        localDocs.value = [...value]
    }
)

const onFileChange = (event) => {
    form.value.file = event.target.files[0] || null
}

const upload = async () => {
    if (!form.value.file) return
    uploading.value = true

    const fd = new FormData()
    fd.append('type', form.value.type)
    fd.append('title', form.value.title)
    fd.append('file', form.value.file)

    try {
        const { data } = await axios.post(route('event-documents.store', { event: props.eventId }), fd)
        localDocs.value = [data.document, ...localDocs.value]
        emit('updated', localDocs.value)
        form.value = { type: '', title: '', file: null }
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
                <input v-model="form.type" class="border rounded px-3 py-2 text-sm" placeholder="Type (waiver, invoice...)" />
                <input v-model="form.title" class="border rounded px-3 py-2 text-sm" placeholder="Title" />
                <input type="file" @change="onFileChange" class="text-sm" />
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
                        <th class="text-right px-4 py-2">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="doc in localDocs" :key="doc.id" class="border-t">
                        <td class="px-4 py-2">{{ doc.title }}</td>
                        <td class="px-4 py-2">{{ doc.type }}</td>
                        <td class="px-4 py-2 text-right">
                            <button @click="removeDoc(doc)" class="text-red-600 text-sm">Delete</button>
                        </td>
                    </tr>
                    <tr v-if="!localDocs.length">
                        <td colspan="3" class="px-4 py-6 text-center text-gray-500">No documents yet.</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
