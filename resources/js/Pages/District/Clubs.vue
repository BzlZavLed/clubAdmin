<script setup>
import PathfinderLayout from '@/Layouts/PathfinderLayout.vue'
import InputError from '@/Components/InputError.vue'
import InputLabel from '@/Components/InputLabel.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import { useForm, router } from '@inertiajs/vue3'
import { ref, computed } from 'vue'
import { useLocale } from '@/Composables/useLocale'

const props = defineProps({
    district: { type: Object, required: true },
    association: { type: Object, default: null },
    union: { type: Object, default: null },
    churches: { type: Array, default: () => [] },
    clubs: { type: Array, default: () => [] },
})

const { tr } = useLocale()

const showAddForm = ref(false)
const search = ref('')
const directorForms = ref({})
const assigningDirectorFor = ref(null)
const expandedClubs = ref(new Set())

const addForm = useForm({
    church_id: '',
    club_name: '',
    club_type: '',
    creation_date: '',
    _church_name: '',
    _pastor_name: '',
    _evaluation: '',
})

const churchMap = computed(() => Object.fromEntries(props.churches.map((church) => [church.id, church])))

const filteredClubs = computed(() => {
    const query = search.value.trim().toLowerCase()
    if (!query) return props.clubs

    return props.clubs.filter((club) =>
        [
            club.club_name,
            club.church_name,
            club.director_name,
        ]
            .filter(Boolean)
            .some((value) => String(value).toLowerCase().includes(query))
    )
})

const clubTypeLabel = (type) => ({
    adventurers: tr('Aventureros', 'Adventurers'),
    pathfinders: tr('Conquistadores', 'Pathfinders'),
    master_guide: tr('Guía Mayor', 'Master Guide'),
})[type] ?? type

const evalLabel = (value) => value === 'carpetas' ? 'Carpetas' : 'Honores'
const today = new Date().toISOString().split('T')[0]

const updateSelectedChurch = (id) => {
    const church = props.churches.find((item) => item.id === Number(id) || item.id === id)
    addForm._church_name = church?.church_name ?? ''
    addForm._pastor_name = church?.pastor_name ?? ''
    addForm._evaluation = props.union?.evaluation_system ?? 'honors'
}

const submitAdd = () => {
    addForm.post(route('district.clubs.store'), {
        preserveScroll: true,
        onSuccess: () => {
            addForm.reset()
            showAddForm.value = false
        },
    })
}

const getDirectorForm = (clubId) => {
    if (!directorForms.value[clubId]) {
        directorForms.value[clubId] = useForm({ name: '', email: '', password: '' })
    }

    return directorForms.value[clubId]
}

const openDirectorForm = (clubId) => {
    assigningDirectorFor.value = clubId
}

const closeDirectorForm = () => {
    assigningDirectorFor.value = null
}

const submitDirector = (club) => {
    const form = getDirectorForm(club.id)
    form.post(route('district.clubs.director.store', club.id), {
        preserveScroll: true,
        onSuccess: () => {
            form.reset()
            assigningDirectorFor.value = null
        },
    })
}

const toggleMembers = (clubId) => {
    const next = new Set(expandedClubs.value)
    next.has(clubId) ? next.delete(clubId) : next.add(clubId)
    expandedClubs.value = next
}

const toggleInsurance = (club, member) => {
    router.patch(
        route('district.clubs.members.insurance', { club: club.id, member: member.id }),
        {},
        { preserveScroll: true }
    )
}
</script>

<template>
    <PathfinderLayout>
        <template #title>{{ tr('Clubes del distrito', 'District clubs') }}</template>

        <div class="space-y-6">
            <section class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <h2 class="text-lg font-semibold text-gray-900">{{ district.name }}</h2>
                        <p class="mt-1 text-sm text-gray-500">
                            {{ tr('Asociación', 'Association') }}: {{ association?.name || '—' }}
                            <span v-if="union?.name"> · {{ tr('Unión', 'Union') }}: {{ union.name }}</span>
                        </p>
                        <p class="mt-2 text-sm text-gray-600">
                            {{ tr('El distrito administra los clubes de sus iglesias. Los clubes quedan inactivos hasta que se asigne un director.', 'The district manages club creation for its churches. Clubs remain inactive until a director is assigned.') }}
                        </p>
                    </div>
                    <button
                        type="button"
                        class="shrink-0 rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50"
                        @click="showAddForm = !showAddForm"
                    >
                        {{ showAddForm ? tr('Cancelar', 'Cancel') : tr('+ Agregar club', '+ Add club') }}
                    </button>
                </div>
            </section>

            <section v-if="showAddForm" class="rounded-2xl border border-blue-200 bg-blue-50 p-6 shadow-sm">
                <h3 class="mb-4 text-sm font-semibold text-blue-900">{{ tr('Nuevo club', 'New club') }}</h3>
                <form class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3" @submit.prevent="submitAdd">
                    <div class="sm:col-span-2 lg:col-span-1">
                        <InputLabel :value="tr('Iglesia *', 'Church *')" />
                        <select
                            v-model="addForm.church_id"
                            class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500"
                            @change="updateSelectedChurch($event.target.value)"
                        >
                            <option value="">{{ tr('Selecciona iglesia', 'Select church') }}</option>
                            <option v-for="church in churches" :key="church.id" :value="church.id">
                                {{ church.church_name }}
                            </option>
                        </select>
                        <InputError class="mt-1" :message="addForm.errors.church_id" />
                    </div>

                    <div>
                        <InputLabel :value="tr('Nombre del club *', 'Club name *')" />
                        <input v-model="addForm.club_name" type="text" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" />
                        <InputError class="mt-1" :message="addForm.errors.club_name" />
                    </div>

                    <div>
                        <InputLabel :value="tr('Tipo *', 'Type *')" />
                        <select v-model="addForm.club_type" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            <option value="">{{ tr('Selecciona tipo', 'Select type') }}</option>
                            <option value="adventurers">{{ tr('Aventureros', 'Adventurers') }}</option>
                            <option value="pathfinders">{{ tr('Conquistadores', 'Pathfinders') }}</option>
                            <option value="master_guide">{{ tr('Guía Mayor', 'Master Guide') }}</option>
                        </select>
                        <InputError class="mt-1" :message="addForm.errors.club_type" />
                    </div>

                    <div>
                        <InputLabel :value="tr('Fecha de creación', 'Creation date')" />
                        <input v-model="addForm.creation_date" type="date" :max="today" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" />
                    </div>

                    <div v-if="addForm.church_id" class="sm:col-span-2 lg:col-span-3 grid gap-3 sm:grid-cols-3 rounded-xl border border-blue-100 bg-white p-4">
                        <div>
                            <p class="text-xs font-medium text-gray-500">{{ tr('Iglesia', 'Church') }}</p>
                            <p class="text-sm text-gray-800">{{ addForm._church_name || '—' }}</p>
                        </div>
                        <div>
                            <p class="text-xs font-medium text-gray-500">{{ tr('Pastor', 'Pastor') }}</p>
                            <p class="text-sm text-gray-800">{{ addForm._pastor_name || '—' }}</p>
                        </div>
                        <div>
                            <p class="text-xs font-medium text-gray-500">{{ tr('Sistema', 'System') }}</p>
                            <p class="text-sm text-gray-800">{{ evalLabel(addForm._evaluation) }}</p>
                        </div>
                    </div>

                    <div class="sm:col-span-2 lg:col-span-3">
                        <PrimaryButton type="submit" :disabled="addForm.processing">
                            {{ tr('Crear club (inactivo)', 'Create club (inactive)') }}
                        </PrimaryButton>
                    </div>
                </form>
            </section>

            <section class="space-y-4">
                <input
                    v-model="search"
                    type="search"
                    :placeholder="tr('Buscar por nombre, iglesia o director…', 'Search by name, church or director…')"
                    class="block w-full rounded-xl border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500"
                />

                <div v-if="!filteredClubs.length" class="rounded-2xl border border-dashed border-gray-200 bg-white p-8 text-center text-sm text-gray-400 shadow-sm">
                    {{ tr('No hay clubes registrados en este distrito.', 'There are no clubs registered in this district.') }}
                </div>

                <div v-else class="space-y-4">
                    <article
                        v-for="club in filteredClubs"
                        :key="club.id"
                        class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm"
                    >
                        <div class="flex items-start justify-between gap-4">
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <h3 class="text-base font-semibold text-gray-900">{{ club.club_name }}</h3>
                                    <span class="text-xs text-gray-500">{{ clubTypeLabel(club.club_type) }}</span>
                                    <span
                                        :class="[
                                            'rounded-full px-2 py-0.5 text-xs font-medium',
                                            club.status === 'active' ? 'bg-green-100 text-green-800' : 'bg-amber-100 text-amber-800',
                                        ]"
                                    >
                                        {{ club.status === 'active' ? tr('Activo', 'Active') : tr('Inactivo', 'Inactive') }}
                                    </span>
                                    <button
                                        v-if="club.members?.length"
                                        type="button"
                                        class="rounded-full bg-gray-100 px-2 py-0.5 text-xs text-gray-600 hover:bg-gray-200"
                                        @click="toggleMembers(club.id)"
                                    >
                                        {{ club.members.length }} {{ tr('miembro(s)', 'member(s)') }} {{ expandedClubs.has(club.id) ? '▲' : '▼' }}
                                    </button>
                                </div>
                                <p class="mt-1 text-sm text-gray-600">
                                    {{ churchMap[club.church_id]?.church_name || club.church_name || '—' }}
                                    <span v-if="club.director_name"> · {{ tr('Director', 'Director') }}: {{ club.director_name }}</span>
                                    <span v-else class="italic text-amber-600"> · {{ tr('Sin director', 'No director') }}</span>
                                </p>
                            </div>
                            <button
                                v-if="!club.has_director"
                                type="button"
                                class="shrink-0 text-sm text-blue-600 hover:underline"
                                @click="openDirectorForm(club.id)"
                            >
                                {{ tr('Asignar director', 'Assign director') }}
                            </button>
                        </div>

                        <div v-if="expandedClubs.has(club.id) && club.members?.length" class="mt-4 overflow-hidden rounded-xl border border-gray-100">
                            <table class="min-w-full text-xs">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-4 py-2 text-left font-medium text-gray-500">{{ tr('Nombre', 'Name') }}</th>
                                        <th class="px-4 py-2 text-left font-medium text-gray-500">{{ tr('Edad', 'Age') }}</th>
                                        <th class="px-4 py-2 text-left font-medium text-gray-500">{{ tr('Correo', 'Email') }}</th>
                                        <th class="px-4 py-2 text-left font-medium text-gray-500">{{ tr('Teléfono', 'Phone') }}</th>
                                        <th class="px-4 py-2 text-center font-medium text-gray-500">
                                            {{ tr('Seguro', 'Insurance') }}
                                            <span v-if="association?.insurance_payment_amount" class="ml-1 text-gray-400">($ {{ association.insurance_payment_amount }})</span>
                                        </th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-50">
                                    <tr v-for="member in club.members" :key="member.id" class="hover:bg-gray-50">
                                        <td class="px-4 py-2 font-medium text-gray-800">{{ member.name }}</td>
                                        <td class="px-4 py-2 text-gray-600">{{ member.age ?? '—' }}</td>
                                        <td class="px-4 py-2 text-gray-600">{{ member.email || '—' }}</td>
                                        <td class="px-4 py-2 text-gray-600">{{ member.phone || '—' }}</td>
                                        <td class="px-4 py-2 text-center">
                                            <button
                                                type="button"
                                                :class="[
                                                    'rounded-full px-2 py-0.5 text-xs font-medium transition-colors',
                                                    member.insurance_paid ? 'bg-green-100 text-green-800 hover:bg-green-200' : 'bg-gray-100 text-gray-500 hover:bg-gray-200',
                                                ]"
                                                :title="member.insurance_paid ? member.insurance_paid_at : tr('Marcar como pagado', 'Mark as paid')"
                                                @click="toggleInsurance(club, member)"
                                            >
                                                {{ member.insurance_paid ? tr('Pagado', 'Paid') : tr('Pendiente', 'Pending') }}
                                            </button>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <div v-if="assigningDirectorFor === club.id" class="mt-4 rounded-xl border border-blue-200 bg-blue-50 p-4">
                            <p class="mb-3 text-xs font-semibold text-blue-900">{{ tr('Crear cuenta de director', 'Create director account') }}</p>
                            <form class="grid gap-3 sm:grid-cols-3" @submit.prevent="submitDirector(club)">
                                <div>
                                    <InputLabel :value="tr('Nombre *', 'Name *')" />
                                    <input v-model="getDirectorForm(club.id).name" type="text" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm" />
                                    <InputError class="mt-1" :message="getDirectorForm(club.id).errors.name" />
                                </div>
                                <div>
                                    <InputLabel :value="tr('Correo *', 'Email *')" />
                                    <input v-model="getDirectorForm(club.id).email" type="email" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm" />
                                    <InputError class="mt-1" :message="getDirectorForm(club.id).errors.email" />
                                </div>
                                <div>
                                    <InputLabel :value="tr('Contraseña *', 'Password *')" />
                                    <input v-model="getDirectorForm(club.id).password" type="password" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm" />
                                    <InputError class="mt-1" :message="getDirectorForm(club.id).errors.password" />
                                </div>
                                <div class="sm:col-span-3 flex gap-3">
                                    <PrimaryButton type="submit" :disabled="getDirectorForm(club.id).processing">
                                        {{ tr('Crear y activar club', 'Create & activate club') }}
                                    </PrimaryButton>
                                    <button type="button" class="text-sm text-gray-500 hover:underline" @click="closeDirectorForm">
                                        {{ tr('Cancelar', 'Cancel') }}
                                    </button>
                                </div>
                            </form>
                        </div>
                    </article>
                </div>
            </section>
        </div>
    </PathfinderLayout>
</template>
