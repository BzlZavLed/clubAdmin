<script setup>
import PathfinderLayout from '@/Layouts/PathfinderLayout.vue'
import { ref, computed, onMounted, nextTick, watch } from 'vue'
import { usePage } from '@inertiajs/vue3'
import MemberRegistrationModal from '@/Components/MemberRegistrationModal.vue'
import PathfinderMemberRegistrationModal from '@/Components/PathfinderMemberRegistrationModal.vue'
import DeleteMemberModal from '@/Components/DeleteMemberModal.vue'
import { 
    PlusIcon,
    MinusIcon,
    PencilIcon,
    CameraIcon,
    DocumentArrowDownIcon,
    TrashIcon,
    ArrowPathIcon 
} from '@heroicons/vue/24/solid'
import { useAuth } from '@/Composables/useAuth'
import { useGeneral } from '@/Composables/useGeneral'
import { formatDate } from '@/Helpers/general'
import {
    fetchClubsByUserId,
    fetchMembersByClub,
    fetchClubClasses,
    assignMemberToClass,
    undoClassAssignment,
    deleteMemberById,
    bulkDeleteMembers,
    downloadMemberZip,
    uploadPathfinderInsuranceCard,
} from '@/Services/api'

// ✅ Auth context
const { user, userClubIds } = useAuth()
const { toast, showToast } = useGeneral()
const page = usePage()
const superadminContext = computed(() => page.props.auth?.superadmin_context ?? null)
const isSuperadmin = computed(() => user.value?.profile_type === 'superadmin')

// State
const clubs = ref([])
const selectedClub = ref(null)
const members = ref([])
const clubClasses = ref([])
const memberSearch = ref('')
const memberPage = ref(1)
const memberPageSize = ref(10)
const expandedRows = ref(new Set())
const showAdventurerRegistrationModal = ref(false)
const showPathfinderRegistrationModal = ref(false)
const editingMember = ref(null)
const registrationFormSection = ref(null)
const showDeleteModal = ref(false)
const deletingMember = ref(null)
const insuranceUploadInput = ref(null)
const insuranceUploadMember = ref(null)
const selectedMemberIds = ref(new Set())
const selectAll = ref(false)
const selectedTab = ref('members')
const classSummaryPdfOptions = ref({
    include_contact: false,
    include_parent: false,
    include_dob: false,
    include_address: false
})
const activeTabClass = 'border-b-2 border-blue-600 text-blue-600 font-semibold pb-2'
const inactiveTabClass = 'text-gray-500 hover:text-gray-700 pb-2'

// Fetch clubs
const fetchClubs = async () => {
    try {
        const loadedClubs = await fetchClubsByUserId(user.value.id)
        clubs.value = Array.isArray(loadedClubs) ? loadedClubs : []

        if (!clubs.value.length) {
            selectedClub.value = null
            members.value = []
            clubClasses.value = []
            return
        }

        const contextClubId = superadminContext.value?.club_id
        const preferredClub = contextClubId
            ? clubs.value.find(club => String(club.id) === String(contextClubId))
            : null

        selectedClub.value = preferredClub || selectedClub.value || clubs.value[0]

        if (selectedClub.value?.id) {
            await onClubChange()
        }
    } catch (error) {
        console.error('Failed to fetch clubs:', error)
        showToast('Error al cargar clubes', 'error')
    }
}

// Fetch members
const fetchMembers = async (clubId) => {
    try {
        const data = await fetchMembersByClub(clubId)
        if (Array.isArray(data) && data.length > 0) {
            members.value = data
            showToast('Miembros cargados', 'success')
        } else {
            members.value = []
            alert('No se encontraron miembros para este club.')
        }
    } catch (error) {
        console.error('Failed to fetch members:', error)
        showToast('Error al cargar miembros', 'error')
    }
}

// Fetch club classes
const fetchClasses = async (clubId) => {
    try {
        clubClasses.value = await fetchClubClasses(clubId)
    } catch (error) {
        console.error('Failed to fetch club classes:', error)
    }
}

// On club selection
const onClubChange = async () => {
    if (selectedClub.value) {
        memberPage.value = 1
        await fetchMembers(selectedClub.value.id)
        await fetchClasses(selectedClub.value.id)
    }
}

// Delete member
const deleteMember = (member) => {
    deletingMember.value = member
    showDeleteModal.value = true
}

const editMember = (member) => {
    if (!selectedClub.value) return
    editingMember.value = member
    showAdventurerRegistrationModal.value = false
    showPathfinderRegistrationModal.value = false

    if (member.member_type === 'temp_pathfinder') {
        showPathfinderRegistrationModal.value = true
        return
    }

    showAdventurerRegistrationModal.value = true
}

const triggerInsuranceUpload = (member) => {
    insuranceUploadMember.value = member
    insuranceUploadInput.value?.click()
}

const onInsuranceCardSelected = async (event) => {
    const file = event.target.files?.[0]
    const member = insuranceUploadMember.value

    if (!file || !member) {
        if (event.target) event.target.value = ''
        return
    }

    try {
        await uploadPathfinderInsuranceCard(member.id, file)
        showToast('Tarjeta de seguro cargada', 'success')
        await fetchMembers(selectedClub.value.id)
    } catch (error) {
        console.error('Failed to upload insurance card', error)
        showToast('No se pudo cargar la tarjeta de seguro', 'error')
    } finally {
        insuranceUploadMember.value = null
        if (event.target) event.target.value = ''
    }
}

const handleMemberDelete = async ({ id, notes }) => {
    try {
        await deleteMemberById(id, notes)
        await fetchMembers(selectedClub.value.id)
        showToast('Miembro eliminado correctamente.', 'success')
        showDeleteModal.value = false
        deletingMember.value = null
    } catch (err) {
        console.error('Failed to delete:', err)
        showToast('Error al eliminar el miembro.', 'error')
    }
}

// Bulk delete or download
const handleBulkAction = async (action, type = null) => {
    if (selectedMemberIds.value.size === 0) {
        alert('No hay miembros seleccionados.')
        return
    }

    const ids = Array.from(selectedMemberIds.value)

    if (action === 'delete') {
        const confirmed = window.confirm('¿Seguro que deseas eliminar los miembros seleccionados?')
        if (!confirmed) return

        try {
            await bulkDeleteMembers(ids)
            await fetchMembers(selectedClub.value.id)
            selectedMemberIds.value.clear()
            selectAll.value = false
            showToast('Miembros seleccionados eliminados.', 'success')
        } catch (error) {
            console.error('Bulk deletion failed:', error)
            showToast('Error al eliminar miembros seleccionados.', 'error')
        }
    }

    if (action === 'download') {
        try {
            await downloadMemberZip(ids, selectedClub.value?.club_type || null)
        } catch (err) {
            console.error(`Failed to download ${type} ZIP:`, err)
        }
    }
}

// Class assignment
const assignToClass = async (member) => {
    if (!member.assigned_class) return
    try {
        const memberId = member.member_id || member.id
        await assignMemberToClass({ memberId, classId: member.assigned_class })
        showToast(`${member.applicant_name} asignado a la clase`, 'success')
        await fetchMembers(selectedClub.value.id)
    } catch (error) {
        console.error('Assignment failed:', error)
        showToast(`No se pudo asignar a ${member.applicant_name}`, 'error')
    }
}

const undoAssignment = async (member) => {
    try {
        const memberId = member.member_id || member.id
        var resp = await undoClassAssignment(memberId)
        showToast(`Se deshizo la ultima asignacion de ${member.applicant_name}`, 'success')
        await fetchMembers(selectedClub.value.id)
    } catch (error) {
        console.error('Undo failed:', error)
        showToast(`No se pudo deshacer la asignacion de ${member.applicant_name}`, 'error')
    }
}

// Row UI actions
const toggleExpanded = (id) => {
    expandedRows.value.has(id) ? expandedRows.value.delete(id) : expandedRows.value.add(id)
}

const toggleSelectAll = () => {
    selectAll.value
        ? (selectedMemberIds.value = new Set(paginatedMembers.value.map(m => m.id)))
        : selectedMemberIds.value.clear()
}

const toggleSelectMember = (id) => {
    selectedMemberIds.value.has(id)
        ? selectedMemberIds.value.delete(id)
        : selectedMemberIds.value.add(id)
}

// Misc
const downloadWord = (member) => {
    if (member.member_type === 'temp_pathfinder') {
        window.open(`/members/${member.id}/export-pathfinder-pdf`, '_blank')
        return
    }

    window.open(`/members/${member.id}/export-word`, '_blank')
}

const toggleRegistrationForm = async () => {
    if (!selectedClub.value) {
        showToast('Selecciona un club primero', 'error')
        return
    }

    showAdventurerRegistrationModal.value = false
    showPathfinderRegistrationModal.value = false
    editingMember.value = null

    if (selectedClub.value.club_type === 'pathfinders') {
        showPathfinderRegistrationModal.value = true
        return
    }

    showAdventurerRegistrationModal.value = true
    await nextTick()
    registrationFormSection.value?.scrollIntoView({ behavior: 'smooth', block: 'start' })
}

// Computed filters
const displayAge = (age) => {
    if (age === null || age === undefined) return '—'
    const n = Number(age)
    if (Number.isNaN(n) || n < 0) return '—'
    return Math.floor(n)
}

const lastCompletedDisplay = (member) => {
    if (member.member_type === 'temp_pathfinder') {
        if (!member.current_class_id) return 'Unassigned'
        const currentClass = clubClasses.value.find(c => String(c.id) === String(member.current_class_id))
        return currentClass?.class_name || 'Unassigned'
    }

    if (Array.isArray(member.investiture_classes) && member.investiture_classes.length) {
        return member.investiture_classes.join(', ')
    }

    return '—'
}

const progressColumnLabel = computed(() =>
    selectedClub.value?.club_type === 'pathfinders' ? 'Clase actual' : 'Ultima completada'
)

const paymentBadgeClass = (paid) => (
    paid
        ? 'inline-flex rounded-full bg-emerald-100 px-2 py-1 text-xs font-medium text-emerald-700'
        : 'inline-flex rounded-full bg-amber-100 px-2 py-1 text-xs font-medium text-amber-700'
)

const normalizedMemberSearch = computed(() => memberSearch.value.trim().toLowerCase())

const filteredMembers = computed(() => {
    if (!normalizedMemberSearch.value) return members.value

    return members.value.filter((member) => {
        const memberName = String(member.applicant_name || '').toLowerCase()
        const className = String(lastCompletedDisplay(member) || '').toLowerCase()
        return memberName.includes(normalizedMemberSearch.value) || className.includes(normalizedMemberSearch.value)
    })
})

const totalMemberPages = computed(() => Math.max(1, Math.ceil(filteredMembers.value.length / memberPageSize.value)))

const paginatedMembers = computed(() => {
    const start = (memberPage.value - 1) * memberPageSize.value
    return filteredMembers.value.slice(start, start + memberPageSize.value)
})

const unassignedMembers = computed(() =>
    members.value.filter(member =>
        !member.class_assignments ||
        member.class_assignments.length === 0 ||
        member.class_assignments.every(assignment => assignment.active === false || assignment.active === 0)
    )
)
const membersInClass = (classId) => {
    return members.value.filter(member =>
        Array.isArray(member.class_assignments) &&
        member.class_assignments.some(
            a => a.active && a.club_class_id === classId
        )
    )
}

const classOptionsExcluding = (currentClassOrder) => {
    const filtered = clubClasses.value.filter(c => c.class_order > currentClassOrder);
    if (filtered.length === 0) {
        return [{ id: '', class_name: 'Sin clases disponibles' }];
    }
    return filtered;
};

const exportClassSummaryPdf = () => {
    if (!selectedClub.value?.id) {
        showToast('Selecciona un club primero', 'error')
        return
    }

    const params = new URLSearchParams()
    Object.entries(classSummaryPdfOptions.value).forEach(([key, enabled]) => {
        if (enabled) params.append(key, '1')
    })

    const base = route('clubs.members.class-summary-pdf', { id: selectedClub.value.id })
    const query = params.toString()
    const url = query ? `${base}?${query}` : base
    window.open(url, '_blank')
}

onMounted(fetchClubs)

const goToPreviousMemberPage = () => {
    memberPage.value = Math.max(1, memberPage.value - 1)
}

const goToNextMemberPage = () => {
    memberPage.value = Math.min(totalMemberPages.value, memberPage.value + 1)
}

watch([memberSearch, memberPageSize], () => {
    memberPage.value = 1
})

watch(filteredMembers, () => {
    if (memberPage.value > totalMemberPages.value) {
        memberPage.value = totalMemberPages.value
    }
    selectAll.value = paginatedMembers.value.length > 0
        && paginatedMembers.value.every(member => selectedMemberIds.value.has(member.id))
})
</script>



<style scoped>
.fade-enter-active,
.fade-leave-active {
    transition: opacity 0.5s;
}

.fade-enter-from,
.fade-leave-to {
    opacity: 0;
}
</style>
<template>
    <PathfinderLayout>
        <div class="p-8">
            <h1 class="text-xl font-bold mb-4">Miembros</h1>

            <!-- Tabs -->
            <div class="mb-4 border-b">
                <nav class="-mb-px flex space-x-6">
                    <button :class="selectedTab === 'members' ? activeTabClass : inactiveTabClass"
                        @click="selectedTab = 'members'">
                        Miembros
                    </button>
                    <button :class="selectedTab === 'classes' ? activeTabClass : inactiveTabClass"
                        @click="selectedTab = 'classes'">
                        Resumen de clases
                    </button>
                </nav>
            </div>

            <!-- Club Selector -->
            <div v-if="clubs.length > 1" class="max-w-xl mb-6">
                <label class="block mb-1 font-medium text-gray-700">Selecciona un club</label>
                <select v-model="selectedClub" @change="onClubChange" class="w-full p-2 border rounded">
                    <option disabled value="">-- Selecciona un club --</option>
                    <option v-for="club in clubs" :key="club.id" :value="club">
                        {{ club.club_name }} ({{ club.club_type }})
                    </option>
                </select>
            </div>
            <div v-else-if="selectedClub" class="mb-6 rounded border bg-white px-4 py-3 text-sm text-gray-700">
                Club activo: <strong>{{ selectedClub.club_name }}</strong>
            </div>

            <!-- Tab 1: Members Table -->
            <div v-if="selectedTab === 'members' && selectedClub">
                <div class="mb-4 flex flex-col gap-3 md:flex-row md:items-end md:justify-between">
                    <div class="grid gap-3 md:grid-cols-2">
                        <div>
                            <label class="mb-1 block text-sm font-medium text-gray-700">Buscar por nombre o clase</label>
                            <input
                                v-model="memberSearch"
                                type="text"
                                class="w-full rounded border p-2 text-sm"
                                placeholder="Ej. Juan o Friend"
                            />
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium text-gray-700">Filas por página</label>
                            <select v-model="memberPageSize" class="w-full rounded border p-2 text-sm">
                                <option :value="10">10</option>
                                <option :value="25">25</option>
                                <option :value="50">50</option>
                            </select>
                        </div>
                    </div>
                    <div class="text-sm text-gray-600">
                        {{ filteredMembers.length }} miembros encontrados
                    </div>
                </div>
                <div class="flex items-center justify-between mb-4">
                    <div class="flex items-center gap-4">
                        <label class="inline-flex items-center">
                            <input type="checkbox" v-model="selectAll" @change="toggleSelectAll" class="mr-2" />
                            <span>Seleccionar todo</span>
                        </label>
                        <select v-if="selectedMemberIds.size > 0"
                            @change="e => handleBulkAction(e.target.value, 'member')"
                            class="border p-2 px-4 rounded w-60 text-sm">
                            <option value="" disabled selected>Acciones masivas</option>
                            <option value="delete">Eliminar seleccionados</option>
                            <option value="download">Descargar formularios</option>
                        </select>
                    </div>
                    <span class="text-sm text-gray-600">{{ selectedMemberIds.size }} seleccionados</span>
                </div>
                <table class="w-full text-sm border rounded overflow-hidden">
                    <thead class="bg-gray-200">
                        <tr>
                            <th class="p-2 text-left"></th>
                            <th class="p-2 text-left">Nombre</th>
                            <th class="p-2 text-left">Direccion</th>
                            <th class="p-2 text-left">{{ progressColumnLabel }}</th>
                            <th class="p-2 text-left">Inscripción</th>
                            <th class="p-2 text-left">Seguro</th>
                            <th class="p-2 text-left">Celular del padre</th>
                            <th class="p-2 text-left">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template v-for="member in paginatedMembers" :key="member.id">
                            <!-- Main Row -->
                            <tr class="border-t">
                                <td class="p-2">
                                    <input type="checkbox" :value="member.id"
                                        :checked="selectedMemberIds.has(member.id)"
                                        @change="() => toggleSelectMember(member.id)" />
                                </td>
                                <td class="p-2 font-semibold">{{ member.applicant_name }}</td>
                                <td class="p-2">{{ member.home_address }}</td>
                                <td class="p-2">{{ lastCompletedDisplay(member) }}</td>
                                <td class="p-2">
                                    <span :class="paymentBadgeClass(member.enrollment_paid)">
                                        {{ member.enrollment_paid ? 'Pagada' : 'Pendiente' }}
                                    </span>
                                </td>
                                <td class="p-2">
                                    <span v-if="selectedClub?.evaluation_system === 'carpetas'" :class="paymentBadgeClass(member.insurance_paid)">
                                        {{ member.insurance_paid ? 'Pagado' : 'Pendiente' }}
                                    </span>
                                    <span v-else class="text-xs text-gray-400">N/A</span>
                                </td>
                                <td class="p-2">{{ member.parent_cell }}</td>
                                <td class="p-2">
                                    <button class="text-green-600 hover:underline" @click="toggleExpanded(member.id)">
                                        <component
                                        :is="expandedRows.has(member.id) ? MinusIcon : PlusIcon"
                                        class="w-4 h-4 inline"
                                        />
                                    </button> &nbsp;&nbsp;
                                    <button class="text-blue-600 hover:underline"
                                        @click="editMember(member)">
                                        <PencilIcon class="w-4 h-4 inline" />
                                    </button>
                                    &nbsp;&nbsp;
                                    <button v-if="member.member_type === 'temp_pathfinder'" class="text-amber-600 hover:underline"
                                        @click="triggerInsuranceUpload(member)">
                                        <CameraIcon class="w-4 h-4 inline" />
                                    </button>
                                    <span v-if="member.member_type === 'temp_pathfinder'">&nbsp;&nbsp;</span>
                                    <button class="text-red-600 hover:underline"
                                        @click="deleteMember(member)">
                                        <TrashIcon class="w-4 h-4 inline" />
                                    </button>
                                    &nbsp;&nbsp;
                                    <button class="text-blue-600 hover:underline"
                                        @click="downloadWord(member)">  
                                        <DocumentArrowDownIcon class="w-4 h-4 inline" />
                                    </button>

                                    
                                </td>
                            </tr>

                            <!-- Expandable Child Row -->
                            <tr v-if="expandedRows.has(member.id)" class="bg-gray-50 border-t">
                                <td colspan="8" class="p-4">
                                    <div v-if="member.member_type === 'temp_pathfinder'" class="grid grid-cols-1 md:grid-cols-2 gap-4 text-gray-700">
                                        <div><strong>Fecha de nacimiento:</strong> {{ member.birthdate ? formatDate(member.birthdate) : '—' }}</div>
                                        <div><strong>Edad:</strong> {{ member.age ?? '—' }}</div>
                                        <div><strong>Grado:</strong> {{ member.grade || '—' }}</div>
                                        <div><strong>Escuela:</strong> {{ member.school || '—' }}</div>
                                        <div><strong>Direccion:</strong> {{ member.mailing_address || '—' }}</div>
                                        <div><strong>Ciudad/Estado/Zip:</strong> {{ [member.city, member.state, member.zip].filter(Boolean).join(', ') || '—' }}</div>
                                        <div><strong>Telefono:</strong> {{ member.cell_number || '—' }}</div>
                                        <div><strong>Email:</strong> {{ member.email_address || '—' }}</div>
                                        <div><strong>Padre/Guardian:</strong> {{ member.father_guardian_name || '—' }}</div>
                                        <div><strong>Email Padre/Guardian:</strong> {{ member.father_guardian_email || '—' }}</div>
                                        <div><strong>Telefono Padre/Guardian:</strong> {{ member.father_guardian_phone || '—' }}</div>
                                        <div><strong>Madre/Guardian:</strong> {{ member.mother_guardian_name || '—' }}</div>
                                        <div><strong>Email Madre/Guardian:</strong> {{ member.mother_guardian_email || '—' }}</div>
                                        <div><strong>Telefono Madre/Guardian:</strong> {{ member.mother_guardian_phone || '—' }}</div>
                                        <div><strong>Contacto de emergencia:</strong> {{ member.emergency_contact_name || member.emergency_contact || '—' }}</div>
                                        <div><strong>Telefono de emergencia:</strong> {{ member.emergency_contact_phone || '—' }}</div>
                                        <div><strong>Medico primario:</strong> {{ member.physician_name || '—' }}</div>
                                        <div><strong>Telefono del medico:</strong> {{ member.physician_phone || '—' }}</div>
                                        <div><strong>Seguro medico:</strong> {{ member.insurance_provider || '—' }}</div>
                                        <div><strong>Numero de poliza:</strong> {{ member.insurance_number || '—' }}</div>
                                        <div><strong>Inscripción:</strong> {{ member.enrollment_paid ? 'Pagada' : 'Pendiente' }}</div>
                                        <div><strong>Seguro:</strong> {{ member.insurance_paid ? 'Pagado' : 'Pendiente' }}</div>
                                        <div class="md:col-span-2">
                                            <strong>Tarjeta de seguro:</strong>
                                            <span v-if="member.insurance_card_url">
                                                <a :href="member.insurance_card_url" target="_blank" rel="noopener" class="text-blue-600 hover:underline">Ver imagen</a>
                                            </span>
                                            <span v-else>—</span>
                                        </div>
                                        <div><strong>Historial de salud:</strong> {{ member.health_history || '—' }}</div>
                                        <div><strong>Discapacidades:</strong> {{ member.disabilities || '—' }}</div>
                                        <div><strong>Alergias a medicamentos:</strong> {{ member.medication_allergies || '—' }}</div>
                                        <div><strong>Alergias a alimentos:</strong> {{ member.food_allergies || '—' }}</div>
                                        <div><strong>Consideraciones dieteticas:</strong> {{ member.dietary_considerations || '—' }}</div>
                                        <div><strong>Restricciones fisicas:</strong> {{ member.physical_restrictions || '—' }}</div>
                                        <div><strong>Vacunas / shot records:</strong> {{ member.immunization_notes || '—' }}</div>
                                        <div><strong>Medicamentos actuales:</strong> {{ member.current_medications || '—' }}</div>
                                        <div class="md:col-span-2"><strong>Personas autorizadas para recoger:</strong> {{ Array.isArray(member.pickup_authorized_people) && member.pickup_authorized_people.length ? member.pickup_authorized_people.join(', ') : '—' }}</div>
                                        <div><strong>Consentimiento firmado:</strong> {{ member.consent_acknowledged ? 'Si' : 'No' }}</div>
                                        <div><strong>Permiso de foto/video:</strong> {{ member.photo_release ? 'Si' : 'No' }}</div>
                                        <div><strong>Firma:</strong> {{ member.signature || '—' }}</div>
                                        <div><strong>Fecha de firma:</strong> {{ member.signed_at ? formatDate(member.signed_at) : '—' }}</div>
                                    </div>
                                    <div v-else class="grid grid-cols-1 md:grid-cols-2 gap-4 text-gray-700">
                                        <div><strong>Fecha de nacimiento:</strong> {{ member.birthdate ? formatDate(member.birthdate) : '—' }}</div>
                                        <div><strong>Edad:</strong> {{ member.age ?? '—' }}</div>
                                        <div><strong>Grado:</strong> {{ member.grade ?? '—' }}</div>
                                        <div><strong>Direccion postal:</strong> {{ member.mailing_address }}</div>
                                        <div><strong>Numero celular:</strong> {{ member.cell_number }}</div>
                                        <div><strong>Contacto de emergencia:</strong> {{ member.emergency_contact }}</div>
                                        <div><strong>Inscripción:</strong> {{ member.enrollment_paid ? 'Pagada' : 'Pendiente' }}</div>
                                        <div><strong>Seguro:</strong> {{ member.insurance_paid ? 'Pagado' : 'Pendiente' }}</div>
                                        <div><strong>Alergias:</strong> {{ member.allergies }}</div>
                                        <div><strong>Restricciones fisicas:</strong> {{ member.physical_restrictions }}
                                        </div>
                                        <div><strong>Historial de salud:</strong> {{ member.health_history }}</div>
                                        <div><strong>Nombre del padre/madre:</strong> {{ member.parent_name }}</div>
                                        <div><strong>Correo electronico:</strong> {{ member.email_address }}</div>
                                        <div><strong>Firma:</strong> {{ member.signature }}</div>
                                    </div>
                                </td>
                            </tr>
                        </template>
                        <tr v-if="paginatedMembers.length === 0">
                            <td colspan="8" class="p-4 text-center text-gray-500">
                                No se encontraron miembros con ese criterio.
                            </td>
                        </tr>
                    </tbody>
                </table>
                <div class="mt-4 flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                    <div class="text-sm text-gray-600">
                        Página {{ memberPage }} de {{ totalMemberPages }}
                    </div>
                    <div class="flex items-center gap-2">
                        <button
                            type="button"
                            @click="goToPreviousMemberPage"
                            :disabled="memberPage <= 1"
                            class="rounded border px-3 py-1.5 text-sm text-gray-700 disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            Anterior
                        </button>
                        <button
                            type="button"
                            @click="goToNextMemberPage"
                            :disabled="memberPage >= totalMemberPages"
                            class="rounded border px-3 py-1.5 text-sm text-gray-700 disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            Siguiente
                        </button>
                    </div>
                </div>
                <div class="mt-6 text-center">
                    <button @click="toggleRegistrationForm"
                        class="bg-green-600 text-white px-4 py-2 rounded hover:bg-green-700">
                        Registrar nuevo miembro
                    </button>
                </div>
            </div>

            <!-- Tab 2: Class Overview -->
            <div v-if="selectedTab === 'classes' && selectedClub">
                <div class="mb-2 flex flex-wrap items-center gap-4 text-sm">
                    <span class="font-medium text-gray-700">Exportar PDF:</span>
                    <label class="inline-flex items-center gap-2">
                        <input v-model="classSummaryPdfOptions.include_contact" type="checkbox" />
                        Contacto
                    </label>
                    <label class="inline-flex items-center gap-2">
                        <input v-model="classSummaryPdfOptions.include_parent" type="checkbox" />
                        Padre/Madre
                    </label>
                    <label class="inline-flex items-center gap-2">
                        <input v-model="classSummaryPdfOptions.include_dob" type="checkbox" />
                        DOB
                    </label>
                    <label class="inline-flex items-center gap-2">
                        <input v-model="classSummaryPdfOptions.include_address" type="checkbox" />
                        Direccion
                    </label>
                    <button
                        type="button"
                        @click="exportClassSummaryPdf"
                        class="px-3 py-1.5 bg-gray-800 text-white rounded text-sm hover:bg-gray-900"
                    >
                        Exportar PDF
                    </button>
                </div>
                <h2 class="text-lg font-semibold mb-4">Resumen de clases</h2>
                <div v-if="clubClasses.length === 0" class="text-gray-600">
                    No se encontraron clases para este club.
                </div>
                <div v-else class="space-y-6">
                    <div v-if="unassignedMembers.length > 0" class="border rounded p-4 bg-gray-100">
                        <h2 class="text-lg font-semibold mb-4">Miembros sin asignar</h2>
                        <table class="w-full border text-sm">
                            <thead class="bg-gray-200">
                                <tr>
                                    <th class="p-2">Nombre</th>
                                    <th class="p-2">Edad</th>
                                    <th class="p-2">Asignar a clase</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="member in unassignedMembers" :key="member.id">
                                    <td class="p-2 text-center">{{ member.applicant_name }}</td>
                                    <td class="p-2 text-center">{{ displayAge(member.age) }}</td>
                                    <td class="p-2 text-center">
                                        <select v-model="member.assigned_class" class="border p-2 rounded">
                                            <option value="" disabled selected>Seleccionar clase</option>
                                            <option v-for="targetClass in clubClasses"
                                                :key="targetClass.id" :value="targetClass.id">
                                                {{ targetClass.class_name }} - {{ targetClass.class_order }}
                                            </option>
                                        </select>
                                        &nbsp;&nbsp;
                                        <button @click="() => assignToClass(member)"
                                            :disabled="!member.assigned_class"
                                            class="px-2 py-1 bg-blue-600 text-white text-xs rounded hover:bg-blue-700">
                                            Asignar
                                        </button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <div v-for="clubClass in clubClasses" :key="clubClass.id" class="border rounded p-4 bg-gray-50">
                        <h3 class="text-md font-bold">
                            {{ clubClass.class_name }} (Orden: {{ clubClass.class_order }})
                        </h3>
                        <p class="text-sm text-gray-700 mb-2" v-if="selectedClub.club_type === 'adventurers'">
                            Personal asignado: {{ clubClass.assigned_staff_name || '—' }}
                        </p>
                        <div v-if="membersInClass(clubClass.id).length === 0" class="text-gray-600">
                            No hay miembros asignados a esta clase.
                        </div>

                        <table v-else class="w-full border text-sm">
                            <thead class="bg-gray-100">
                                <tr>
                                    <th class="p-2">Nombre</th>
                                    <th class="p-2">Edad</th>
                                    <th class="p-2">Mover a clase</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="member in membersInClass(clubClass.id)" :key="member.id">
                                    <td class="p-2 text-center">{{ member.applicant_name }}</td>
                                    <td class="p-2 text-center">{{ displayAge(member.age) }}</td>
                                    <td class="p-2 text-center">
                                        <select v-model="member.assigned_class" class="border p-1 rounded">
                                            <option v-for="targetClass in classOptionsExcluding(clubClass.class_order)"
                                                :key="targetClass.id" :value="targetClass.id">
                                                {{ targetClass.class_name }}
                                            </option>
                                        </select>
                                        &nbsp;&nbsp;
                                        <button @click="() => assignToClass(member)"
                                            :disabled="!member.assigned_class"
                                            class="px-2 py-1 bg-blue-600 text-white text-xs rounded hover:bg-blue-700">
                                            Asignar
                                        </button>
                                        <button @click="() => undoAssignment(member)"
                                            class="ml-2 px-2 py-1 bg-red-500 text-white text-xs rounded hover:bg-red-600">
                                            Deshacer ultimo
                                        </button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>


            <!-- MODALS -->
            <MemberRegistrationModal :show="showAdventurerRegistrationModal" :clubs="clubs" :selectedClub="selectedClub" :editing-member="editingMember"
                @close="showAdventurerRegistrationModal = false; editingMember = null" @submitted="fetchMembers(selectedClub.id); editingMember = null" />
            <PathfinderMemberRegistrationModal :show="showPathfinderRegistrationModal" :selectedClub="selectedClub" :editing-member="editingMember"
                @close="showPathfinderRegistrationModal = false; editingMember = null" @submitted="fetchMembers(selectedClub.id); editingMember = null" />
            <DeleteMemberModal :show="showDeleteModal" :memberId="deletingMember?.id"
                :memberName="deletingMember?.applicant_name" @cancel="showDeleteModal = false"
                @confirm="handleMemberDelete" />
            <input
                ref="insuranceUploadInput"
                type="file"
                accept="image/*"
                capture="environment"
                class="hidden"
                @change="onInsuranceCardSelected"
            />
        </div>
    </PathfinderLayout>
</template>
