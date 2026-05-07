<script setup>
import PathfinderLayout from '@/Layouts/PathfinderLayout.vue'
import { computed, ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { useGeneral } from '@/Composables/useGeneral'

const props = defineProps({
    district: { type: Object, required: true },
    association: { type: Object, default: null },
    summary: { type: Object, default: () => ({}) },
    members: { type: Array, default: () => [] },
})

const { showToast } = useGeneral()
const search = ref('')
const statusFilter = ref('all')
const expandedRows = ref(new Set())
const forms = ref({})
const noteForms = ref({})
const noteSearches = ref({})
const noteColorFilters = ref({})
const noteVisibleCounts = ref({})
const defaultNoteVisibleCount = 6

const getForm = (member) => {
    if (!forms.value[member.id]) {
        forms.value[member.id] = useForm({
            bible_study_active: Boolean(member.pastoral_care?.bible_study_active),
            bible_study_teacher: member.pastoral_care?.bible_study_teacher || '',
            bible_study_started_at: member.pastoral_care?.bible_study_started_at || '',
            baptism_date: member.baptism_date || member.pastoral_care?.baptized_at || '',
            mentor_member_id: member.pastoral_care?.mentor_member_id || '',
        })
    }

    return forms.value[member.id]
}

const getNoteForm = (member) => {
    if (!noteForms.value[member.id]) {
        noteForms.value[member.id] = useForm({
            subject: '',
            body: '',
            color: 'yellow',
        })
    }

    return noteForms.value[member.id]
}

const normalizedSearch = computed(() => search.value.trim().toLowerCase())

const filteredMembers = computed(() => props.members.filter((member) => {
    if (statusFilter.value !== 'all' && member.status_key !== statusFilter.value) {
        return false
    }

    if (!normalizedSearch.value) return true

    return [
        member.name,
        member.club?.name,
        member.club?.church_name,
        member.parent_name,
        member.phone,
        member.email,
    ]
        .filter(Boolean)
        .some((value) => String(value).toLowerCase().includes(normalizedSearch.value))
}))

const toggleExpanded = (memberId) => {
    const next = new Set(expandedRows.value)
    next.has(memberId) ? next.delete(memberId) : next.add(memberId)
    expandedRows.value = next
}

const ensureNoteUiState = (member) => {
    if (noteSearches.value[member.id] === undefined) {
        noteSearches.value[member.id] = ''
    }

    if (noteColorFilters.value[member.id] === undefined) {
        noteColorFilters.value[member.id] = ''
    }

    if (!noteVisibleCounts.value[member.id]) {
        noteVisibleCounts.value[member.id] = defaultNoteVisibleCount
    }
}

const saveMember = (member) => {
    const form = getForm(member)

    form.patch(route('district.pastoral-care.update', member.id), {
        preserveScroll: true,
        onSuccess: () => showToast('Seguimiento pastoral actualizado.', 'success'),
        onError: (errors) => showToast(Object.values(errors || {})[0] || 'No se pudo actualizar el seguimiento.', 'error'),
    })
}

const addNote = (member) => {
    const form = getNoteForm(member)

    form.post(route('district.pastoral-care.notes.store', member.id), {
        preserveScroll: true,
        onSuccess: () => {
            form.reset()
            form.color = 'yellow'
            showToast('Nota agregada.', 'success')
        },
        onError: (errors) => showToast(Object.values(errors || {})[0] || 'No se pudo agregar la nota.', 'error'),
    })
}

const deleteNote = (note) => {
    if (!confirm('Eliminar esta nota?')) return

    router.delete(route('district.pastoral-care.notes.destroy', note.id), {
        preserveScroll: true,
        onSuccess: () => showToast('Nota eliminada.', 'success'),
        onError: () => showToast('No se pudo eliminar la nota.', 'error'),
    })
}

const formatDate = (value) => {
    if (!value) return '—'
    return new Date(`${value}T00:00:00`).toLocaleDateString()
}

const noteTimestamp = (note) => {
    if (!note?.created_at) return 0
    return Date.parse(String(note.created_at).replace(' ', 'T')) || 0
}

const statusClass = (statusKey) => (
    statusKey === 'new_believer'
        ? 'bg-emerald-100 text-emerald-700'
        : 'bg-rose-100 text-rose-700'
)

const noteClass = (color) => ({
    yellow: 'border-yellow-200 bg-yellow-50 text-yellow-950',
    blue: 'border-blue-200 bg-blue-50 text-blue-950',
    green: 'border-emerald-200 bg-emerald-50 text-emerald-950',
    rose: 'border-rose-200 bg-rose-50 text-rose-950',
    slate: 'border-slate-200 bg-slate-50 text-slate-950',
}[color] || 'border-yellow-200 bg-yellow-50 text-yellow-950')

const noteColorLabel = (color) => ({
    yellow: 'Amarillo',
    blue: 'Azul',
    green: 'Verde',
    rose: 'Rojo',
    slate: 'Gris',
}[color] || 'Sin color')

const sortedNotes = (member) => [...(member.notes || [])]
    .sort((a, b) => noteTimestamp(b) - noteTimestamp(a) || Number(b.id || 0) - Number(a.id || 0))

const filteredNotes = (member) => {
    ensureNoteUiState(member)

    const query = String(noteSearches.value[member.id] || '').trim().toLowerCase()
    const color = noteColorFilters.value[member.id] || ''

    return sortedNotes(member).filter((note) => {
        if (color && note.color !== color) {
            return false
        }

        if (!query) {
            return true
        }

        return [note.subject, note.body]
            .filter(Boolean)
            .some((value) => String(value).toLowerCase().includes(query))
    })
}

const visibleNoteCount = (member) => {
    ensureNoteUiState(member)
    return noteVisibleCounts.value[member.id] || defaultNoteVisibleCount
}

const visibleNotes = (member) => filteredNotes(member).slice(0, visibleNoteCount(member))

const resetVisibleNotes = (member) => {
    noteVisibleCounts.value[member.id] = defaultNoteVisibleCount
}

const loadMoreNotes = (member) => {
    noteVisibleCounts.value[member.id] = visibleNoteCount(member) + defaultNoteVisibleCount
}
</script>

<template>
    <PathfinderLayout>
        <template #title>Cuidado pastoral</template>

        <div class="space-y-6">
            <section class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
                <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                    <div>
                        <h2 class="text-lg font-semibold text-gray-900">{{ district.name }}</h2>
                        <p class="mt-1 text-sm text-gray-500">
                            Asociación: {{ association?.name || '—' }}
                            <span v-if="district.pastor_name"> · Pastor: {{ district.pastor_name }}</span>
                        </p>
                        <p class="mt-2 max-w-3xl text-sm text-gray-600">
                            Este módulo muestra miembros de clubes del distrito que no son SDA y nuevos creyentes que siguen en sus primeros 18 meses después del bautismo.
                        </p>
                    </div>
                </div>
            </section>

            <section class="grid gap-4 md:grid-cols-4">
                <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Total en seguimiento</p>
                    <p class="mt-2 text-2xl font-bold text-gray-900">{{ summary.total || 0 }}</p>
                </div>
                <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">No SDA</p>
                    <p class="mt-2 text-2xl font-bold text-rose-700">{{ summary.non_sda || 0 }}</p>
                </div>
                <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Nuevos creyentes</p>
                    <p class="mt-2 text-2xl font-bold text-emerald-700">{{ summary.new_believers || 0 }}</p>
                </div>
                <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Con estudio bíblico</p>
                    <p class="mt-2 text-2xl font-bold text-blue-700">{{ summary.bible_studies || 0 }}</p>
                </div>
            </section>

            <section class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
                <div class="grid gap-4 md:grid-cols-[1fr_220px]">
                    <div>
                        <label class="mb-1 block text-sm font-medium text-gray-700">Buscar</label>
                        <input
                            v-model="search"
                            type="search"
                            class="w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500"
                            placeholder="Nombre, club, iglesia, contacto"
                        />
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-gray-700">Estado</label>
                        <select v-model="statusFilter" class="w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            <option value="all">Todos</option>
                            <option value="non_sda">No SDA</option>
                            <option value="new_believer">Nuevos creyentes</option>
                        </select>
                    </div>
                </div>
            </section>

            <section class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
                <div v-if="!filteredMembers.length" class="p-8 text-center text-sm text-gray-500">
                    No hay miembros en seguimiento con estos filtros.
                </div>

                <table v-else class="w-full text-sm">
                    <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                        <tr>
                            <th class="px-4 py-3">Miembro</th>
                            <th class="px-4 py-3">Club</th>
                            <th class="px-4 py-3">Contacto</th>
                            <th class="px-4 py-3">Estado</th>
                            <th class="px-4 py-3 text-right">Acción</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <template v-for="member in filteredMembers" :key="member.id">
                            <tr>
                                <td class="px-4 py-3">
                                    <p class="font-semibold text-gray-900">{{ member.name }}</p>
                                    <p class="text-xs text-gray-500">
                                        {{ member.member_type }} · {{ member.class_name || 'Sin clase' }}
                                    </p>
                                </td>
                                <td class="px-4 py-3">
                                    <p class="font-medium text-gray-800">{{ member.club?.name || '—' }}</p>
                                    <p class="text-xs text-gray-500">{{ member.club?.church_name || '—' }}</p>
                                </td>
                                <td class="px-4 py-3 text-gray-600">
                                    <p>{{ member.phone || '—' }}</p>
                                    <p class="text-xs">{{ member.email || '—' }}</p>
                                </td>
                                <td class="px-4 py-3">
                                    <span class="rounded-full px-2 py-1 text-xs font-semibold" :class="statusClass(member.status_key)">
                                        {{ member.status_label }}
                                    </span>
                                    <p v-if="member.pastoral_care?.new_believer_until" class="mt-1 text-xs text-gray-500">
                                        Hasta {{ formatDate(member.pastoral_care.new_believer_until) }}
                                    </p>
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <button type="button" class="text-sm font-medium text-blue-600 hover:underline" @click="toggleExpanded(member.id)">
                                        {{ expandedRows.has(member.id) ? 'Cerrar' : 'Ver seguimiento' }}
                                    </button>
                                </td>
                            </tr>
                            <tr v-if="expandedRows.has(member.id)" class="bg-gray-50">
                                <td colspan="5" class="px-4 py-5">
                                    <div class="grid gap-5 lg:grid-cols-[1fr_1fr]">
                                        <div class="rounded-lg border border-gray-200 bg-white p-4">
                                            <h3 class="mb-3 text-sm font-semibold text-gray-900">Datos del miembro</h3>
                                            <div class="grid gap-3 text-sm text-gray-700 sm:grid-cols-2">
                                                <p><span class="font-medium text-gray-900">Nacimiento:</span> {{ formatDate(member.birthdate) }}</p>
                                                <p><span class="font-medium text-gray-900">Edad:</span> {{ member.age ?? '—' }}</p>
                                                <p><span class="font-medium text-gray-900">Grado:</span> {{ member.grade || '—' }}</p>
                                                <p><span class="font-medium text-gray-900">Dirección:</span> {{ member.address || '—' }}</p>
                                                <p><span class="font-medium text-gray-900">Padre/Madre:</span> {{ member.parent_name || '—' }}</p>
                                                <p><span class="font-medium text-gray-900">Tel. padre:</span> {{ member.parent_phone || '—' }}</p>
                                                <p class="sm:col-span-2"><span class="font-medium text-gray-900">Emergencia:</span> {{ member.emergency_contact || '—' }}</p>
                                                <p class="sm:col-span-2"><span class="font-medium text-gray-900">Salud:</span> {{ member.health_notes || '—' }}</p>
                                            </div>
                                        </div>

                                        <form class="space-y-4 rounded-lg border border-gray-200 bg-white p-4" @submit.prevent="saveMember(member)">
                                            <h3 class="text-sm font-semibold text-gray-900">Seguimiento pastoral</h3>

                                            <label class="flex items-start gap-3 rounded border border-gray-200 px-3 py-2 text-sm">
                                                <input v-model="getForm(member).bible_study_active" type="checkbox" class="mt-1 h-4 w-4 accent-blue-600" />
                                                <span>
                                                    <span class="block font-medium text-gray-900">Tiene estudios bíblicos</span>
                                                    <span class="text-gray-500">Permite registrar responsable y fecha de inicio.</span>
                                                </span>
                                            </label>

                                            <div v-if="getForm(member).bible_study_active" class="grid gap-3 rounded-md border border-blue-100 bg-blue-50 p-3 sm:grid-cols-2">
                                                <div>
                                                    <label class="mb-1 block text-sm font-medium text-gray-700">Quién da el estudio</label>
                                                    <input v-model="getForm(member).bible_study_teacher" type="text" class="w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" />
                                                    <p v-if="getForm(member).errors.bible_study_teacher" class="mt-1 text-xs text-red-600">{{ getForm(member).errors.bible_study_teacher }}</p>
                                                </div>
                                                <div>
                                                    <label class="mb-1 block text-sm font-medium text-gray-700">Inicio del estudio</label>
                                                    <input v-model="getForm(member).bible_study_started_at" type="date" class="w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" />
                                                    <p v-if="getForm(member).errors.bible_study_started_at" class="mt-1 text-xs text-red-600">{{ getForm(member).errors.bible_study_started_at }}</p>
                                                </div>
                                            </div>

                                            <div class="grid gap-3 sm:grid-cols-2">
                                                <div>
                                                    <label class="mb-1 block text-sm font-medium text-gray-700">Fecha de bautismo</label>
                                                    <input v-model="getForm(member).baptism_date" type="date" class="w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" />
                                                    <p v-if="getForm(member).errors.baptism_date" class="mt-1 text-xs text-red-600">{{ getForm(member).errors.baptism_date }}</p>
                                                </div>
                                                <div>
                                                    <label class="mb-1 block text-sm font-medium text-gray-700">Mentor SDA del club</label>
                                                    <select v-model="getForm(member).mentor_member_id" class="w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                                        <option value="">Sin mentor asignado</option>
                                                        <option v-for="mentor in member.mentor_options" :key="mentor.id" :value="mentor.id">
                                                            {{ mentor.name }}
                                                        </option>
                                                    </select>
                                                    <p v-if="!member.mentor_options.length" class="mt-1 text-xs text-amber-700">Este club no tiene miembros SDA disponibles como mentor.</p>
                                                    <p v-if="getForm(member).errors.mentor_member_id" class="mt-1 text-xs text-red-600">{{ getForm(member).errors.mentor_member_id }}</p>
                                                </div>
                                            </div>

                                            <div class="flex justify-end">
                                                <button
                                                    type="submit"
                                                    :disabled="getForm(member).processing"
                                                    class="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-60"
                                                >
                                                    Guardar seguimiento
                                                    </button>
                                                </div>
                                            </form>

                                            <div class="space-y-3 lg:col-span-2">
                                                <div class="flex items-center justify-between">
                                                    <h3 class="text-sm font-semibold text-gray-900">Notas del miembro</h3>
                                                    <span class="text-xs text-gray-500">{{ filteredNotes(member).length }} de {{ member.notes?.length || 0 }} notas</span>
                                                </div>

                                                <div class="grid gap-3 sm:grid-cols-[1fr_150px]">
                                                    <div>
                                                        <label class="mb-1 block text-sm font-medium text-gray-700">Buscar notas</label>
                                                        <input
                                                            v-model="noteSearches[member.id]"
                                                            type="search"
                                                            class="w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500"
                                                            placeholder="Asunto o nota"
                                                            @input="resetVisibleNotes(member)"
                                                        />
                                                    </div>
                                                    <div>
                                                        <label class="mb-1 block text-sm font-medium text-gray-700">Color</label>
                                                        <select
                                                            v-model="noteColorFilters[member.id]"
                                                            class="w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500"
                                                            @change="resetVisibleNotes(member)"
                                                        >
                                                            <option value="">Todos</option>
                                                            <option value="yellow">Amarillo</option>
                                                            <option value="blue">Azul</option>
                                                            <option value="green">Verde</option>
                                                            <option value="rose">Rojo</option>
                                                            <option value="slate">Gris</option>
                                                        </select>
                                                    </div>
                                                </div>

                                                <div v-if="filteredNotes(member).length" class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                                                    <article
                                                        v-for="note in visibleNotes(member)"
                                                        :key="note.id"
                                                        class="rounded-md border p-3 shadow-sm"
                                                        :class="noteClass(note.color)"
                                                    >
                                                        <div class="mb-2 flex items-start justify-between gap-3">
                                                            <div>
                                                                <h4 class="text-sm font-semibold">{{ note.subject || 'Nota' }}</h4>
                                                                <p class="text-[11px] opacity-70">
                                                                    {{ note.author_name || 'Distrito' }} · {{ formatDate(note.created_at?.slice(0, 10)) }} · {{ noteColorLabel(note.color) }}
                                                                </p>
                                                            </div>
                                                            <button type="button" class="text-xs font-semibold opacity-70 hover:opacity-100" @click="deleteNote(note)">
                                                                X
                                                            </button>
                                                        </div>
                                                        <p class="whitespace-pre-line text-sm leading-5">{{ note.body }}</p>
                                                    </article>
                                                </div>
                                                <div v-if="filteredNotes(member).length > visibleNotes(member).length" class="flex items-center justify-between rounded-md border border-gray-200 bg-white px-3 py-2 text-xs text-gray-600">
                                                    <span>Mostrando {{ visibleNotes(member).length }} de {{ filteredNotes(member).length }}</span>
                                                    <button type="button" class="font-semibold text-blue-600 hover:underline" @click="loadMoreNotes(member)">
                                                        Cargar más notas
                                                    </button>
                                                </div>
                                                <div v-if="!filteredNotes(member).length" class="rounded-md border border-dashed border-gray-200 bg-white p-4 text-sm text-gray-500">
                                                    {{ member.notes?.length ? 'No hay notas que coincidan con la búsqueda o color.' : 'No hay notas registradas para este miembro.' }}
                                                </div>

                                                <form class="grid gap-3 rounded-lg border border-gray-200 bg-white p-4" @submit.prevent="addNote(member)">
                                                    <div class="grid gap-3 sm:grid-cols-[1fr_150px]">
                                                        <div>
                                                            <label class="mb-1 block text-sm font-medium text-gray-700">Asunto</label>
                                                            <input v-model="getNoteForm(member).subject" type="text" class="w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" placeholder="Ej. Visita familiar" />
                                                            <p v-if="getNoteForm(member).errors.subject" class="mt-1 text-xs text-red-600">{{ getNoteForm(member).errors.subject }}</p>
                                                        </div>
                                                        <div>
                                                            <label class="mb-1 block text-sm font-medium text-gray-700">Color</label>
                                                            <select v-model="getNoteForm(member).color" class="w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                                                <option value="yellow">Amarillo</option>
                                                                <option value="blue">Azul</option>
                                                                <option value="green">Verde</option>
                                                                <option value="rose">Rojo</option>
                                                                <option value="slate">Gris</option>
                                                            </select>
                                                        </div>
                                                    </div>
                                                    <div>
                                                        <label class="mb-1 block text-sm font-medium text-gray-700">Nota</label>
                                                        <textarea v-model="getNoteForm(member).body" rows="3" class="w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500"></textarea>
                                                        <p v-if="getNoteForm(member).errors.body" class="mt-1 text-xs text-red-600">{{ getNoteForm(member).errors.body }}</p>
                                                    </div>
                                                    <div class="flex justify-end">
                                                        <button
                                                            type="submit"
                                                            :disabled="getNoteForm(member).processing"
                                                            class="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-60"
                                                        >
                                                            Agregar nota
                                                        </button>
                                                    </div>
                                                </form>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                        </template>
                    </tbody>
                </table>
            </section>
        </div>
    </PathfinderLayout>
</template>
