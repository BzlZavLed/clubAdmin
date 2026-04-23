<script setup>
import PathfinderLayout from '@/Layouts/PathfinderLayout.vue'
import InputError from '@/Components/InputError.vue'
import InputLabel from '@/Components/InputLabel.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import { useForm, router } from '@inertiajs/vue3'
import { ref, computed, watch } from 'vue'
import { useLocale } from '@/Composables/useLocale'

const props = defineProps({
    association: { type: Object, required: true },
    union:       { type: Object, required: true },
    districts:   { type: Array, default: () => [] },
    churches:    { type: Array, default: () => [] },
    clubs:       { type: Array, default: () => [] },
})

const { tr } = useLocale()

const view         = ref('district')   // 'district' | 'club'
const showAddForm  = ref(false)
const search       = ref('')
const directorForms = ref({})          // keyed by club.id — open when user clicks assign

// ── Add club form ─────────────────────────────────────────────
const addForm = useForm({
    church_id:     '',
    club_name:     '',
    club_type:     '',
    creation_date: '',
    // read-only display
    _church_name:  '',
    _district_name:'',
    _pastor_name:  '',
    _evaluation:   '',
})

const districtMap = computed(() =>
    Object.fromEntries(props.districts.map(d => [d.id, d]))
)
const churchMap = computed(() =>
    Object.fromEntries(props.churches.map(c => [c.id, c]))
)

watch(() => addForm.church_id, (id) => {
    const church   = props.churches.find(c => c.id === Number(id) || c.id === id)
    const district = church ? districtMap.value[church.district_id] : null
    addForm._church_name   = church?.church_name ?? ''
    addForm._district_name = district?.name ?? ''
    addForm._pastor_name   = church?.pastor_name ?? ''
    addForm._evaluation    = props.union.evaluation_system ?? 'honors'
})

const submitAdd = () => {
    addForm.post(route('association.clubs.store'), {
        preserveScroll: true,
        onSuccess: () => { addForm.reset(); showAddForm.value = false },
    })
}

// ── Director assignment forms (one per club, lazy-created) ────
const getDirectorForm = (clubId) => {
    if (!directorForms.value[clubId]) {
        directorForms.value[clubId] = useForm({ name: '', email: '', password: '' })
    }
    return directorForms.value[clubId]
}
const assigningDirectorFor = ref(null)

const openDirectorForm = (clubId) => { assigningDirectorFor.value = clubId }
const closeDirectorForm = ()      => { assigningDirectorFor.value = null }

const submitDirector = (club) => {
    const form = getDirectorForm(club.id)
    form.post(route('association.clubs.director.store', club.id), {
        preserveScroll: true,
        onSuccess: () => {
            form.reset()
            assigningDirectorFor.value = null
        },
    })
}

// ── Helpers ───────────────────────────────────────────────────
const clubsByDistrict = computed(() => {
    const map = {}
    for (const d of props.districts) map[d.id] = []
    for (const c of props.clubs) {
        if (map[c.district_id] !== undefined) map[c.district_id].push(c)
    }
    return map
})

const filteredClubs = computed(() => {
    const q = search.value.trim().toLowerCase()
    if (!q) return props.clubs
    return props.clubs.filter(c =>
        c.club_name?.toLowerCase().includes(q) ||
        c.church_name?.toLowerCase().includes(q) ||
        districtMap.value[c.district_id]?.name?.toLowerCase().includes(q) ||
        c.director_name?.toLowerCase().includes(q)
    )
})

const clubTypeLabel = (type) => ({
    adventurers: tr('Aventureros', 'Adventurers'),
    pathfinders:  tr('Conquistadores', 'Pathfinders'),
    master_guide: tr('Guía Mayor', 'Master Guide'),
})[type] ?? type

const evalLabel = (v) => v === 'carpetas' ? 'Carpetas' : 'Honores'
const formatMoney = (value) => Number(value || 0).toFixed(2)

const today = new Date().toISOString().split('T')[0]

// ── Member collapsible ────────────────────────────────────────
const expandedClubs = ref(new Set())

const toggleMembers = (clubId) => {
    const s = new Set(expandedClubs.value)
    s.has(clubId) ? s.delete(clubId) : s.add(clubId)
    expandedClubs.value = s
}

const toggleInsurance = (club, member) => {
    router.patch(
        route('association.clubs.members.insurance', { club: club.id, member: member.id }),
        {},
        { preserveScroll: true }
    )
}
</script>

<template>
    <PathfinderLayout>
        <template #title>{{ tr('Clubes', 'Clubs') }}</template>

        <div class="space-y-6">

            <!-- Header -->
            <div class="flex items-start justify-between rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
                <div>
                    <h2 class="text-lg font-semibold text-gray-900">{{ association.name }}</h2>
                    <p class="mt-2 text-sm text-gray-600">
                        {{ tr('Crea y administra los clubes de la asociación. Los clubes quedan inactivos hasta que se asigne un director.', 'Create and manage clubs in the association. Clubs stay inactive until a director is assigned.') }}
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

            <!-- Add club form -->
            <div v-if="showAddForm" class="rounded-2xl border border-blue-200 bg-blue-50 p-6 shadow-sm">
                <h3 class="mb-4 text-sm font-semibold text-blue-900">{{ tr('Nuevo club', 'New club') }}</h3>
                <form class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3" @submit.prevent="submitAdd">
                    <!-- Church selector -->
                    <div class="sm:col-span-2 lg:col-span-1">
                        <InputLabel :value="tr('Iglesia *', 'Church *')" />
                        <select
                            v-model="addForm.church_id"
                            class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500"
                        >
                            <option value="">{{ tr('Selecciona iglesia', 'Select church') }}</option>
                            <optgroup
                                v-for="district in districts"
                                :key="district.id"
                                :label="district.name"
                            >
                                <option
                                    v-for="church in churches.filter(c => c.district_id === district.id)"
                                    :key="church.id"
                                    :value="church.id"
                                >
                                    {{ church.church_name }}
                                </option>
                            </optgroup>
                        </select>
                        <InputError class="mt-1" :message="addForm.errors.church_id" />
                    </div>

                    <!-- Club name -->
                    <div>
                        <InputLabel :value="tr('Nombre del club *', 'Club name *')" />
                        <input
                            v-model="addForm.club_name"
                            type="text"
                            class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500"
                        />
                        <InputError class="mt-1" :message="addForm.errors.club_name" />
                    </div>

                    <!-- Club type -->
                    <div>
                        <InputLabel :value="tr('Tipo *', 'Type *')" />
                        <select
                            v-model="addForm.club_type"
                            class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500"
                        >
                            <option value="">{{ tr('Selecciona tipo', 'Select type') }}</option>
                            <option value="adventurers">{{ tr('Aventureros', 'Adventurers') }}</option>
                            <option value="pathfinders">{{ tr('Conquistadores', 'Pathfinders') }}</option>
                            <option value="master_guide">{{ tr('Guía Mayor', 'Master Guide') }}</option>
                        </select>
                        <InputError class="mt-1" :message="addForm.errors.club_type" />
                    </div>

                    <!-- Creation date -->
                    <div>
                        <InputLabel :value="tr('Fecha de creación', 'Creation date')" />
                        <input
                            v-model="addForm.creation_date"
                            type="date"
                            :max="today"
                            class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500"
                        />
                    </div>

                    <!-- Auto-filled read-only fields -->
                    <div v-if="addForm.church_id" class="sm:col-span-2 lg:col-span-3 grid gap-3 sm:grid-cols-3 rounded-xl border border-blue-100 bg-white p-4">
                        <div>
                            <p class="text-xs font-medium text-gray-500">{{ tr('Distrito', 'District') }}</p>
                            <p class="text-sm text-gray-800">{{ addForm._district_name || '—' }}</p>
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
            </div>

            <!-- View tabs -->
            <div class="flex gap-1 rounded-xl border border-gray-200 bg-gray-100 p-1 w-fit">
                <button
                    type="button"
                    :class="['rounded-lg px-4 py-1.5 text-sm font-medium transition-colors', view === 'district' ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-500 hover:text-gray-700']"
                    @click="view = 'district'"
                >
                    {{ tr('Por distrito', 'By district') }}
                </button>
                <button
                    type="button"
                    :class="['rounded-lg px-4 py-1.5 text-sm font-medium transition-colors', view === 'club' ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-500 hover:text-gray-700']"
                    @click="view = 'club'"
                >
                    {{ tr('Por club', 'By club') }}
                </button>
            </div>

            <!-- ── By district ── -->
            <div v-if="view === 'district'" class="space-y-4">
                <div v-if="!districts.length" class="rounded-2xl border border-dashed border-gray-200 p-8 text-center text-sm text-gray-400">
                    {{ tr('No hay distritos registrados aún.', 'No districts registered yet.') }}
                </div>
                <article
                    v-for="district in districts"
                    :key="district.id"
                    class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm"
                >
                    <div class="flex items-center justify-between border-b border-gray-100 bg-gray-50 px-6 py-4">
                        <div>
                            <h3 class="font-semibold text-gray-900">{{ district.name }}</h3>
                            <p v-if="district.pastor_name" class="mt-0.5 text-xs text-gray-500">
                                {{ tr('Pastor', 'Pastor') }}: {{ district.pastor_name }}
                            </p>
                        </div>
                        <span class="text-xs text-gray-400">
                            {{ (clubsByDistrict[district.id] || []).length }} {{ tr('club(s)', 'club(s)') }}
                        </span>
                    </div>

                    <div v-if="(clubsByDistrict[district.id] || []).length" class="divide-y divide-gray-100">
                        <div
                            v-for="club in clubsByDistrict[district.id]"
                            :key="club.id"
                            class="px-6 py-4"
                        >
                            <div class="flex items-start justify-between gap-4">
                                <div class="min-w-0">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <p class="text-sm font-medium text-gray-900">{{ club.club_name }}</p>
                                        <span class="text-xs text-gray-500">{{ clubTypeLabel(club.club_type) }}</span>
                                        <span
                                            :class="[
                                                'rounded-full px-2 py-0.5 text-xs font-medium',
                                                club.status === 'active'
                                                    ? 'bg-green-100 text-green-800'
                                                    : 'bg-amber-100 text-amber-800',
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
                                            {{ club.members.length }} {{ tr('miembro(s)', 'member(s)') }}
                                            {{ expandedClubs.has(club.id) ? '▲' : '▼' }}
                                        </button>
                                    </div>
                                    <p class="mt-0.5 text-xs text-gray-500">
                                        {{ club.church_name || '—' }}
                                        <span v-if="club.director_name"> · {{ tr('Director', 'Director') }}: {{ club.director_name }}</span>
                                        <span v-else class="italic text-amber-600"> · {{ tr('Sin director', 'No director') }}</span>
                                    </p>
                                    <p v-if="club.insurance_summary && association.insurance_payment_amount" class="mt-1 text-xs text-gray-500">
                                        {{ tr('Seguro esperado', 'Expected insurance') }}:
                                        ${{ formatMoney(club.insurance_summary.expected_amount) }}
                                        · {{ tr('Pagado', 'Paid') }}:
                                        ${{ formatMoney(club.insurance_summary.paid_amount) }}
                                        · {{ tr('Pendiente', 'Outstanding') }}:
                                        ${{ formatMoney(club.insurance_summary.outstanding_amount) }}
                                        · {{ club.insurance_summary.insured_count }}/{{ club.insurance_summary.member_count }} {{ tr('miembros', 'members') }}
                                    </p>
                                </div>
                                <button
                                    v-if="!club.has_director"
                                    type="button"
                                    class="shrink-0 text-xs text-blue-600 hover:underline"
                                    @click="openDirectorForm(club.id)"
                                >
                                    {{ tr('Asignar director', 'Assign director') }}
                                </button>
                            </div>

                            <!-- Member list -->
                            <div v-if="expandedClubs.has(club.id) && club.members?.length" class="mt-3 overflow-hidden rounded-xl border border-gray-100">
                                <table class="min-w-full text-xs">
                                    <thead class="bg-gray-50">
                                        <tr>
                                            <th class="px-4 py-2 text-left font-medium text-gray-500">{{ tr('Nombre', 'Name') }}</th>
                                            <th class="px-4 py-2 text-left font-medium text-gray-500">{{ tr('Edad', 'Age') }}</th>
                                            <th class="px-4 py-2 text-left font-medium text-gray-500">{{ tr('Correo', 'Email') }}</th>
                                            <th class="px-4 py-2 text-left font-medium text-gray-500">{{ tr('Teléfono', 'Phone') }}</th>
                                            <th class="px-4 py-2 text-center font-medium text-gray-500">
                                                {{ tr('Seguro', 'Insurance') }}
                                                <span v-if="association.insurance_payment_amount" class="ml-1 text-gray-400">($ {{ association.insurance_payment_amount }})</span>
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
                                                        member.insurance_paid
                                                            ? 'bg-green-100 text-green-800 hover:bg-green-200'
                                                            : 'bg-gray-100 text-gray-500 hover:bg-gray-200',
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

                            <!-- Director assignment form -->
                            <div
                                v-if="assigningDirectorFor === club.id"
                                class="mt-4 rounded-xl border border-blue-200 bg-blue-50 p-4"
                            >
                                <p class="mb-3 text-xs font-semibold text-blue-900">{{ tr('Crear cuenta de director', 'Create director account') }}</p>
                                <form class="grid gap-3 sm:grid-cols-3" @submit.prevent="submitDirector(club)">
                                    <div>
                                        <InputLabel :value="tr('Nombre *', 'Name *')" />
                                        <input
                                            v-model="getDirectorForm(club.id).name"
                                            type="text"
                                            class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm"
                                        />
                                        <InputError class="mt-1" :message="getDirectorForm(club.id).errors.name" />
                                    </div>
                                    <div>
                                        <InputLabel :value="tr('Correo *', 'Email *')" />
                                        <input
                                            v-model="getDirectorForm(club.id).email"
                                            type="email"
                                            class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm"
                                        />
                                        <InputError class="mt-1" :message="getDirectorForm(club.id).errors.email" />
                                    </div>
                                    <div>
                                        <InputLabel :value="tr('Contraseña *', 'Password *')" />
                                        <input
                                            v-model="getDirectorForm(club.id).password"
                                            type="password"
                                            class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm"
                                        />
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
                        </div>
                    </div>
                    <div v-else class="px-6 py-4 text-sm italic text-gray-400">
                        {{ tr('Sin clubes en este distrito.', 'No clubs in this district.') }}
                    </div>
                </article>
            </div>

            <!-- ── By club ── -->
            <div v-else class="space-y-4">
                <input
                    v-model="search"
                    type="search"
                    :placeholder="tr('Buscar por nombre, iglesia, distrito o director…', 'Search by name, church, district or director…')"
                    class="block w-full rounded-xl border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500"
                />

                <div v-if="!filteredClubs.length" class="rounded-2xl border border-dashed border-gray-200 p-8 text-center text-sm text-gray-400">
                    {{ tr('Sin resultados.', 'No results.') }}
                </div>

                <div v-else class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm">
                    <table class="min-w-full divide-y divide-gray-100">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">{{ tr('Club', 'Club') }}</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">{{ tr('Distrito / Iglesia', 'District / Church') }}</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">{{ tr('Director', 'Director') }}</th>
                                <th class="px-6 py-3 text-center text-xs font-semibold uppercase tracking-wide text-gray-500">{{ tr('Estado', 'Status') }}</th>
                                <th class="px-6 py-3" />
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <template v-for="club in filteredClubs" :key="club.id">
                                <tr :class="club.status !== 'active' ? 'bg-amber-50/40' : ''">
                                    <td class="px-6 py-4">
                                        <p class="text-sm font-medium text-gray-900">{{ club.club_name }}</p>
                                        <p class="text-xs text-gray-400">{{ clubTypeLabel(club.club_type) }} · {{ evalLabel(club.evaluation_system) }}</p>
                                    </td>
                                    <td class="px-6 py-4 text-sm text-gray-700">
                                        <p>{{ districtMap[club.district_id]?.name || '—' }}</p>
                                        <p class="text-xs text-gray-400">{{ club.church_name || '—' }}</p>
                                    </td>
                                    <td class="px-6 py-4 text-sm text-gray-700">
                                        <span v-if="club.director_name">{{ club.director_name }}</span>
                                        <span v-else class="italic text-amber-600 text-xs">{{ tr('Sin director', 'No director') }}</span>
                                        <p v-if="club.insurance_summary && association.insurance_payment_amount" class="mt-1 text-xs text-gray-400">
                                            {{ club.insurance_summary.insured_count }}/{{ club.insurance_summary.member_count }} {{ tr('con seguro', 'insured') }}
                                            · ${{ formatMoney(club.insurance_summary.outstanding_amount) }} {{ tr('pendiente', 'outstanding') }}
                                        </p>
                                    </td>
                                    <td class="px-6 py-4 text-center">
                                        <span
                                            :class="[
                                                'rounded-full px-2 py-0.5 text-xs font-medium',
                                                club.status === 'active' ? 'bg-green-100 text-green-800' : 'bg-amber-100 text-amber-800',
                                            ]"
                                        >
                                            {{ club.status === 'active' ? tr('Activo', 'Active') : tr('Inactivo', 'Inactive') }}
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 text-right space-y-1">
                                        <button
                                            v-if="!club.has_director"
                                            type="button"
                                            class="block text-sm text-blue-600 hover:underline"
                                            @click="openDirectorForm(club.id)"
                                        >
                                            {{ tr('Asignar director', 'Assign director') }}
                                        </button>
                                        <button
                                            v-if="club.members?.length"
                                            type="button"
                                            class="block text-xs text-gray-500 hover:underline"
                                            @click="toggleMembers(club.id)"
                                        >
                                            {{ club.members.length }} {{ tr('miembro(s)', 'member(s)') }}
                                            {{ expandedClubs.has(club.id) ? '▲' : '▼' }}
                                        </button>
                                    </td>
                                </tr>

                                <!-- Member list row -->
                                <tr v-if="expandedClubs.has(club.id) && club.members?.length" class="bg-gray-50">
                                    <td colspan="5" class="px-6 py-3">
                                        <table class="min-w-full text-xs">
                                            <thead>
                                                <tr class="text-gray-500">
                                                    <th class="pb-1 text-left font-medium">{{ tr('Nombre', 'Name') }}</th>
                                                    <th class="pb-1 text-left font-medium">{{ tr('Edad', 'Age') }}</th>
                                                    <th class="pb-1 text-left font-medium">{{ tr('Correo', 'Email') }}</th>
                                                    <th class="pb-1 text-left font-medium">{{ tr('Teléfono', 'Phone') }}</th>
                                                    <th class="pb-1 text-center font-medium">
                                                        {{ tr('Seguro', 'Insurance') }}
                                                        <span v-if="association.insurance_payment_amount" class="ml-1 text-gray-400">($ {{ association.insurance_payment_amount }})</span>
                                                    </th>
                                                </tr>
                                            </thead>
                                            <tbody class="divide-y divide-gray-100">
                                                <tr v-for="member in club.members" :key="member.id">
                                                    <td class="py-1.5 font-medium text-gray-800">{{ member.name }}</td>
                                                    <td class="py-1.5 text-gray-600">{{ member.age ?? '—' }}</td>
                                                    <td class="py-1.5 text-gray-600">{{ member.email || '—' }}</td>
                                                    <td class="py-1.5 text-gray-600">{{ member.phone || '—' }}</td>
                                                    <td class="py-1.5 text-center">
                                                        <button
                                                            type="button"
                                                            :class="[
                                                                'rounded-full px-2 py-0.5 text-xs font-medium transition-colors',
                                                                member.insurance_paid
                                                                    ? 'bg-green-100 text-green-800 hover:bg-green-200'
                                                                    : 'bg-gray-100 text-gray-500 hover:bg-gray-200',
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
                                    </td>
                                </tr>

                                <!-- Director assignment row -->
                                <tr v-if="assigningDirectorFor === club.id" class="bg-blue-50">
                                    <td colspan="5" class="px-6 py-4">
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
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </PathfinderLayout>
</template>
