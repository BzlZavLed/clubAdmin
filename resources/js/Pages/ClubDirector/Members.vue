<script setup>
import PathfinderLayout from '@/Layouts/PathfinderLayout.vue'
import { ref, computed, onMounted, nextTick, watch } from 'vue'
import { usePage } from '@inertiajs/vue3'
import MemberRegistrationModal from '@/Components/MemberRegistrationModal.vue'
import MasterGuideMemberRegistrationModal from '@/Components/MasterGuideMemberRegistrationModal.vue'
import PathfinderMemberRegistrationModal from '@/Components/PathfinderMemberRegistrationModal.vue'
import DeleteMemberModal from '@/Components/DeleteMemberModal.vue'
import MemberChargesModal from '@/Components/MemberChargesModal.vue'
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
import { useLocale } from '@/Composables/useLocale'
import { formatDate } from '@/Helpers/general'
import {
    fetchClubsByUserId,
    fetchMembersByClub,
    fetchClubClasses,
    assignMemberToClass,
    undoClassAssignment,
    deleteMemberById,
    downloadMemberZip,
    sendMemberZipToConference,
    uploadPathfinderInsuranceCard,
    updateMasterGuideMemberYear,
} from '@/Services/api'

// ✅ Auth context
const { user, userClubIds } = useAuth()
const { toast, showToast } = useGeneral()
const { tr } = useLocale()
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
const showMasterGuideRegistrationModal = ref(false)
const editingMember = ref(null)
const registrationFormSection = ref(null)
const showDeleteModal = ref(false)
const deletingMember = ref(null)
const showChargesModal = ref(false)
const chargesMember = ref(null)
const insuranceUploadInput = ref(null)
const insuranceUploadMember = ref(null)
const selectedMemberIds = ref(new Set())
const selectAll = ref(false)
const selectedTab = ref('members')
const showConferenceEmailForm = ref(false)
const conferenceEmail = ref('')
const sendingConferenceExport = ref(false)
const programYearUpdatingIds = ref(new Set())
const classSummaryPdfOptions = ref({
    include_contact: false,
    include_parent: false,
    include_dob: false,
    include_address: false
})
const activeTabClass = 'border-b-2 border-blue-600 text-blue-600 font-semibold pb-2'
const inactiveTabClass = 'text-gray-500 hover:text-gray-700 pb-2'
const isMasterGuideClub = computed(() => selectedClub.value?.club_type === 'master_guide')
const memberDetailsColspan = computed(() => isMasterGuideClub.value ? 6 : 7)
const programYearOptions = [1, 2]

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
        showToast(tr('Error al cargar clubes', 'Could not load clubs'), 'error')
    }
}

// Fetch members
const fetchMembers = async (clubId) => {
    try {
        const data = await fetchMembersByClub(clubId)
        if (Array.isArray(data) && data.length > 0) {
            members.value = data
            showToast(tr('Miembros cargados', 'Members loaded'), 'success')
        } else {
            members.value = []
            showToast(tr('No se encontraron miembros para este club.', 'No members were found for this club.'), 'info')
        }
    } catch (error) {
        console.error('Failed to fetch members:', error)
        showToast(tr('Error al cargar miembros', 'Could not load members'), 'error')
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

const openMemberCharges = (member) => {
    chargesMember.value = member
    showChargesModal.value = true
}

const editMember = (member) => {
    if (!selectedClub.value) return
    editingMember.value = member
    showAdventurerRegistrationModal.value = false
    showPathfinderRegistrationModal.value = false
    showMasterGuideRegistrationModal.value = false

    if (member.member_type === 'temp_pathfinder') {
        showPathfinderRegistrationModal.value = true
        return
    }

    if (member.member_type === 'master_guide') {
        showMasterGuideRegistrationModal.value = true
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
        showToast(tr('Tarjeta de seguro cargada', 'Insurance card uploaded'), 'success')
        await fetchMembers(selectedClub.value.id)
    } catch (error) {
        console.error('Failed to upload insurance card', error)
        showToast(tr('No se pudo cargar la tarjeta de seguro', 'Could not upload the insurance card'), 'error')
    } finally {
        insuranceUploadMember.value = null
        if (event.target) event.target.value = ''
    }
}

const handleMemberDelete = async ({ id, notes }) => {
    try {
        await deleteMemberById(id, notes, {
            member_type: deletingMember.value?.member_type || 'adventurers',
            member_record_id: deletingMember.value?.member_id || null,
        })
        await fetchMembers(selectedClub.value.id)
        showToast(tr('Miembro eliminado correctamente.', 'Member deleted successfully.'), 'success')
        showDeleteModal.value = false
        deletingMember.value = null
    } catch (err) {
        console.error('Failed to delete:', err)
        showToast(tr('Error al eliminar el miembro.', 'Could not delete the member.'), 'error')
    }
}

// Bulk delete, download, or email export
const handleBulkAction = async (action, type = null) => {
    if (selectedMemberIds.value.size === 0) {
        showToast(tr('No hay miembros seleccionados.', 'No members selected.'), 'info')
        return
    }

    const ids = Array.from(selectedMemberIds.value)

    if (action === 'delete') {
        const confirmed = window.confirm(tr('¿Seguro que deseas eliminar los miembros seleccionados?', 'Are you sure you want to delete the selected members?'))
        if (!confirmed) return

        try {
            const selectedMembers = members.value.filter(member => selectedMemberIds.value.has(member.id))
            for (const member of selectedMembers) {
                await deleteMemberById(member.id, 'Bulk deleted', {
                    member_type: member.member_type || 'adventurers',
                    member_record_id: member.member_id || null,
                })
            }
            await fetchMembers(selectedClub.value.id)
            selectedMemberIds.value.clear()
            selectAll.value = false
            showToast(tr('Miembros seleccionados eliminados.', 'Selected members deleted.'), 'success')
        } catch (error) {
            console.error('Bulk deletion failed:', error)
            showToast(tr('Error al eliminar miembros seleccionados.', 'Could not delete selected members.'), 'error')
        }
    }

    if (action === 'download') {
        try {
            await downloadMemberZip(ids, selectedClub.value?.club_type || null)
        } catch (err) {
            console.error(`Failed to download ${type} ZIP:`, err)
        }
    }

    if (action === 'send_conference') {
        showConferenceEmailForm.value = true
    }
}

const sendSelectedMembersToConference = async () => {
    if (!selectedClub.value?.id) {
        showToast(tr('Selecciona un club primero', 'Select a club first'), 'error')
        return
    }

    if (selectedMemberIds.value.size === 0) {
        showToast(tr('No hay miembros seleccionados.', 'No members selected.'), 'info')
        return
    }

    if (!conferenceEmail.value.trim()) {
        showToast(tr('Ingresa el correo de la conferencia.', 'Enter the conference email.'), 'error')
        return
    }

    try {
        sendingConferenceExport.value = true
        await sendMemberZipToConference({
            ids: Array.from(selectedMemberIds.value),
            clubType: selectedClub.value?.club_type || null,
            clubId: selectedClub.value.id,
            email: conferenceEmail.value.trim(),
        })

        showToast(tr('Paquete enviado a conferencia.', 'Package sent to conference.'), 'success')
        showConferenceEmailForm.value = false
        conferenceEmail.value = ''
    } catch (error) {
        console.error('Failed to send member export to conference:', error)
        showToast(
            error.response?.data?.message || tr('No se pudo enviar el paquete a conferencia.', 'Could not send the package to conference.'),
            'error'
        )
    } finally {
        sendingConferenceExport.value = false
    }
}

// Class assignment
const assignToClass = async (member) => {
    if (!member.assigned_class) return
    try {
        const memberId = member.member_id || member.id
        await assignMemberToClass({ memberId, classId: member.assigned_class })
        showToast(tr(`${member.applicant_name} asignado a la clase`, `${member.applicant_name} assigned to the class`), 'success')
        await fetchMembers(selectedClub.value.id)
    } catch (error) {
        console.error('Assignment failed:', error)
        showToast(tr(`No se pudo asignar a ${member.applicant_name}`, `Could not assign ${member.applicant_name}`), 'error')
    }
}

const undoAssignment = async (member) => {
    try {
        const memberId = member.member_id || member.id
        var resp = await undoClassAssignment(memberId)
        showToast(tr(`Se deshizo la ultima asignacion de ${member.applicant_name}`, `The last assignment for ${member.applicant_name} was undone`), 'success')
        await fetchMembers(selectedClub.value.id)
    } catch (error) {
        console.error('Undo failed:', error)
        showToast(tr(`No se pudo deshacer la asignacion de ${member.applicant_name}`, `Could not undo the assignment for ${member.applicant_name}`), 'error')
    }
}

const programYearLabel = (year) => tr(`Año ${year}`, `Year ${year}`)

const setProgramYearUpdating = (memberId, isUpdating) => {
    const next = new Set(programYearUpdatingIds.value)
    isUpdating ? next.add(memberId) : next.delete(memberId)
    programYearUpdatingIds.value = next
}

const isProgramYearUpdating = (member) => programYearUpdatingIds.value.has(member.id)

const updateMemberProgramYear = async (member, value) => {
    const nextYear = Number(value)
    const currentYear = Number(member.program_year || 1)

    if (member.member_type !== 'master_guide' || !programYearOptions.includes(nextYear) || nextYear === currentYear) {
        return
    }

    try {
        setProgramYearUpdating(member.id, true)
        const updated = await updateMasterGuideMemberYear(member.id, nextYear)
        member.program_year = Number(updated.program_year || nextYear)
        member.program_year_label = updated.program_year_label || `Year ${member.program_year}`
        showToast(tr('Año del programa actualizado', 'Program year updated'), 'success')
        await fetchMembers(selectedClub.value.id)
    } catch (error) {
        console.error('Failed to update Master Guide program year:', error)
        showToast(tr('No se pudo actualizar el año del programa', 'Could not update the program year'), 'error')
    } finally {
        setProgramYearUpdating(member.id, false)
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
    if (member.member_type === 'master_guide') {
        showToast(tr('La exportacion de formulario para Guias Mayores aun no esta disponible.', 'Master Guide form export is not available yet.'), 'info')
        return
    }

    if (member.member_type === 'temp_pathfinder') {
        window.open(`/members/${member.id}/export-pathfinder-pdf`, '_blank')
        return
    }

    window.open(`/members/${member.id}/export-word`, '_blank')
}

const toggleRegistrationForm = async () => {
    if (!selectedClub.value) {
        showToast(tr('Selecciona un club primero', 'Select a club first'), 'error')
        return
    }

    showAdventurerRegistrationModal.value = false
    showPathfinderRegistrationModal.value = false
    showMasterGuideRegistrationModal.value = false
    editingMember.value = null

    if (selectedClub.value.club_type === 'pathfinders') {
        showPathfinderRegistrationModal.value = true
        return
    }

    if (selectedClub.value.club_type === 'master_guide') {
        showMasterGuideRegistrationModal.value = true
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
    if (member.member_type === 'master_guide') {
        return member.program_year_label || tr(`Año ${member.program_year || 1}`, `Year ${member.program_year || 1}`)
    }

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
    selectedClub.value?.club_type === 'pathfinders'
        ? tr('Clase actual', 'Current class')
        : isMasterGuideClub.value
            ? tr('Año', 'Year')
            : tr('Ultima completada', 'Last completed')
)
const fatherName = (member) => member.father_name || member.father_guardian_name || member.parent_name || '—'
const parentPortalUrl = (member) => member.father_portal_url || null
const parentPortalTitle = computed(() => tr('Abrir portal del padre en una nueva pestaña', 'Open parent portal in a new tab'))
const contactColumnLabel = computed(() => isMasterGuideClub.value ? 'Email' : tr('Padre', 'Father'))
const phoneColumnLabel = computed(() => isMasterGuideClub.value ? tr('Telefono', 'Phone') : tr('Celular del padre', 'Parent cell'))
const contactColumnValue = (member) => isMasterGuideClub.value
    ? (member.email || member.email_address || '—')
    : fatherName(member)
const phoneColumnValue = (member) => isMasterGuideClub.value
    ? (member.phone || member.cell_number || '—')
    : (member.parent_cell || '—')

const paymentBadgeClass = (paid) => (
    paid
        ? 'inline-flex rounded-full bg-emerald-100 px-2 py-1 text-xs font-medium text-emerald-700'
        : 'inline-flex rounded-full bg-amber-100 px-2 py-1 text-xs font-medium text-amber-700'
)

const sdaBadgeClass = (isSda) => (
    isSda
        ? 'inline-flex rounded-full bg-blue-100 px-2 py-1 text-xs font-medium text-blue-700'
        : 'inline-flex rounded-full bg-rose-100 px-2 py-1 text-xs font-medium text-rose-700'
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
        member.member_type !== 'master_guide' &&
        (
            !member.class_assignments ||
            member.class_assignments.length === 0 ||
            member.class_assignments.every(assignment => assignment.active === false || assignment.active === 0)
        )
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
        return [{ id: '', class_name: tr('Sin clases disponibles', 'No classes available') }];
    }
    return filtered;
};

const masterGuideYearBuckets = computed(() => [1, 2].map((year) => {
    const yearMembers = members.value
        .filter(member => member.member_type === 'master_guide' && Number(member.program_year || 1) === year)
        .sort((a, b) => String(a.applicant_name || '').localeCompare(String(b.applicant_name || '')))

    return {
        year,
        label: tr(`Año ${year}`, `Year ${year}`),
        members: yearMembers,
    }
}))

const exportClassSummaryPdf = () => {
    if (!selectedClub.value?.id) {
        showToast(tr('Selecciona un club primero', 'Select a club first'), 'error')
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
        <div class="p-4 sm:p-6 lg:p-8">
            <h1 class="text-xl font-bold mb-4">{{ tr('Miembros', 'Members') }}</h1>

            <!-- Tabs -->
            <div class="mb-4 border-b">
                <nav class="-mb-px flex gap-4 overflow-x-auto pb-1">
                    <button class="shrink-0" :class="selectedTab === 'members' ? activeTabClass : inactiveTabClass"
                        @click="selectedTab = 'members'">
                        {{ tr('Miembros', 'Members') }}
                    </button>
                    <button class="shrink-0" :class="selectedTab === 'classes' ? activeTabClass : inactiveTabClass"
                        @click="selectedTab = 'classes'">
                        {{ isMasterGuideClub ? tr('Resumen por año', 'Year Summary') : tr('Resumen de clases', 'Class Summary') }}
                    </button>
                </nav>
            </div>

            <!-- Club Selector -->
            <div v-if="clubs.length > 1" class="max-w-xl mb-6">
                <label class="block mb-1 font-medium text-gray-700">{{ tr('Selecciona un club', 'Select a club') }}</label>
                <select v-model="selectedClub" @change="onClubChange" class="w-full p-2 border rounded">
                    <option disabled value="">-- {{ tr('Selecciona un club', 'Select a club') }} --</option>
                    <option v-for="club in clubs" :key="club.id" :value="club">
                        {{ club.club_name }} ({{ club.club_type }})
                    </option>
                </select>
            </div>
            <div v-else-if="selectedClub" class="mb-6 rounded border bg-white px-4 py-3 text-sm text-gray-700">
                {{ tr('Club activo', 'Active club') }}: <strong>{{ selectedClub.club_name }}</strong>
            </div>

            <!-- Tab 1: Members Table -->
            <div v-if="selectedTab === 'members' && selectedClub">
                <div class="mb-4 flex flex-col gap-3 md:flex-row md:items-end md:justify-between">
                    <div class="grid gap-3 md:grid-cols-2">
                        <div>
                            <label class="mb-1 block text-sm font-medium text-gray-700">{{ isMasterGuideClub ? tr('Buscar por nombre o año', 'Search by name or year') : tr('Buscar por nombre o clase', 'Search by name or class') }}</label>
                            <input
                                v-model="memberSearch"
                                type="text"
                                class="w-full rounded border p-2 text-sm"
                                :placeholder="tr('Ej. Juan o Friend', 'Ex. John or Friend')"
                            />
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium text-gray-700">{{ tr('Filas por página', 'Rows per page') }}</label>
                            <select v-model="memberPageSize" class="w-full rounded border p-2 text-sm">
                                <option :value="10">10</option>
                                <option :value="25">25</option>
                                <option :value="50">50</option>
                            </select>
                        </div>
                    </div>
                    <div class="text-sm text-gray-600">
                        {{ filteredMembers.length }} {{ tr('miembros encontrados', 'members found') }}
                    </div>
                </div>
                <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:gap-4">
                        <label class="inline-flex items-center">
                            <input type="checkbox" v-model="selectAll" @change="toggleSelectAll" class="mr-2" />
                            <span>{{ tr('Seleccionar todo', 'Select all') }}</span>
                        </label>
                        <select v-if="selectedMemberIds.size > 0"
                            @change="e => { handleBulkAction(e.target.value, 'member'); e.target.value = '' }"
                            class="w-full rounded border p-2 px-4 text-sm sm:w-60">
                            <option value="" disabled selected>{{ tr('Acciones masivas', 'Bulk actions') }}</option>
                            <option value="delete">{{ tr('Eliminar seleccionados', 'Delete selected') }}</option>
                            <option v-if="!isMasterGuideClub" value="download">{{ tr('Descargar formularios', 'Download forms') }}</option>
                            <option v-if="!isMasterGuideClub" value="send_conference">{{ tr('Enviar a conferencia', 'Send to conference') }}</option>
                        </select>
                    </div>
                    <span class="text-sm text-gray-600">{{ selectedMemberIds.size }} {{ tr('seleccionados', 'selected') }}</span>
                </div>
                <div v-if="showConferenceEmailForm" class="mb-4 rounded-lg border border-blue-100 bg-blue-50 p-4">
                    <div class="grid gap-3 md:grid-cols-[1fr_auto_auto] md:items-end">
                        <div>
                            <label class="mb-1 block text-sm font-medium text-blue-950">{{ tr('Correo de la conferencia', 'Conference email') }}</label>
                            <input
                                v-model="conferenceEmail"
                                type="email"
                                class="w-full rounded border-blue-200 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500"
                                placeholder="conference@example.com"
                            />
                        </div>
                        <button
                            type="button"
                            class="rounded bg-blue-700 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-800 disabled:cursor-not-allowed disabled:opacity-60"
                            :disabled="sendingConferenceExport"
                            @click="sendSelectedMembersToConference"
                        >
                            {{ sendingConferenceExport ? tr('Enviando...', 'Sending...') : tr('Enviar', 'Send') }}
                        </button>
                        <button
                            type="button"
                            class="rounded border border-blue-200 bg-white px-4 py-2 text-sm font-semibold text-blue-800 hover:bg-blue-50"
                            :disabled="sendingConferenceExport"
                            @click="showConferenceEmailForm = false"
                        >
                            {{ tr('Cancelar', 'Cancel') }}
                        </button>
                    </div>
                    <p class="mt-2 text-xs text-blue-900">
                        {{ tr('Se generara el mismo ZIP de formularios seleccionados y se enviara como adjunto.', 'The same ZIP of selected forms will be generated and sent as an attachment.') }}
                    </p>
                </div>
                <div class="space-y-3 sm:hidden">
                    <article v-for="member in paginatedMembers" :key="`mobile-member-${member.id}`" class="rounded-lg border bg-white p-3 shadow-sm">
                        <div class="flex items-start gap-3">
                            <input
                                type="checkbox"
                                :value="member.id"
                                :checked="selectedMemberIds.has(member.id)"
                                class="mt-1"
                                @change="() => toggleSelectMember(member.id)"
                            />
                            <div class="min-w-0 flex-1">
                                <div class="flex items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <h3 class="break-words text-sm font-semibold text-gray-900">{{ member.applicant_name }}</h3>
                                        <p v-if="!isMasterGuideClub" class="mt-1 break-words text-xs text-gray-500">{{ member.home_address || member.mailing_address || '—' }}</p>
                                    </div>
                                    <span :class="sdaBadgeClass(member.is_sda !== false)">
                                        {{ member.is_sda !== false ? 'SDA' : tr('Cuidado pastoral', 'Pastoral care') }}
                                    </span>
                                </div>
                                <dl class="mt-3 grid grid-cols-2 gap-2 text-xs">
                                    <div>
                                        <dt class="text-gray-500">{{ progressColumnLabel }}</dt>
                                        <dd v-if="member.member_type === 'master_guide'">
                                            <select
                                                :value="Number(member.program_year || 1)"
                                                :disabled="isProgramYearUpdating(member)"
                                                class="w-full rounded border px-2 py-1 text-xs font-medium text-gray-900 disabled:opacity-60"
                                                @change="event => updateMemberProgramYear(member, event.target.value)"
                                            >
                                                <option v-for="year in programYearOptions" :key="year" :value="year">{{ programYearLabel(year) }}</option>
                                            </select>
                                        </dd>
                                        <dd v-else class="font-medium text-gray-900">{{ lastCompletedDisplay(member) }}</dd>
                                    </div>
                                    <div v-if="!isMasterGuideClub">
                                        <dt class="text-gray-500">{{ contactColumnLabel }}</dt>
                                        <dd class="font-medium text-gray-900">
                                            <a
                                                v-if="!isMasterGuideClub && parentPortalUrl(member)"
                                                :href="parentPortalUrl(member)"
                                                target="_blank"
                                                rel="noopener"
                                                class="text-blue-700 underline decoration-blue-200 underline-offset-2 hover:text-blue-900"
                                                :title="parentPortalTitle"
                                            >
                                                {{ contactColumnValue(member) }}
                                            </a>
                                            <span v-else>{{ contactColumnValue(member) }}</span>
                                        </dd>
                                    </div>
                                    <div v-if="!isMasterGuideClub">
                                        <dt class="text-gray-500">{{ phoneColumnLabel }}</dt>
                                        <dd class="font-medium text-gray-900">{{ phoneColumnValue(member) }}</dd>
                                    </div>
                                    <div>
                                        <dt class="text-gray-500">{{ tr('Inscripción', 'Enrollment') }}</dt>
                                        <dd><span :class="paymentBadgeClass(member.enrollment_paid)">{{ member.enrollment_paid ? tr('Pagada', 'Paid') : tr('Pendiente', 'Pending') }}</span></dd>
                                    </div>
                                    <div>
                                        <dt class="text-gray-500">{{ tr('Seguro', 'Insurance') }}</dt>
                                        <dd>
                                            <span v-if="selectedClub?.evaluation_system === 'carpetas'" :class="paymentBadgeClass(member.insurance_paid)">
                                                {{ member.insurance_paid ? tr('Pagado', 'Paid') : tr('Pendiente', 'Pending') }}
                                            </span>
                                            <span v-else class="text-gray-400">N/A</span>
                                        </dd>
                                    </div>
                                </dl>
                                <div class="mt-3 grid grid-cols-6 gap-2">
                                    <button class="rounded border px-2 py-2 text-green-700" @click="toggleExpanded(member.id)" :title="tr('Ver detalles', 'View details')">
                                        <component :is="expandedRows.has(member.id) ? MinusIcon : PlusIcon" class="mx-auto h-4 w-4" />
                                    </button>
                                    <button class="rounded border px-2 py-2 text-blue-700" @click="editMember(member)" :title="tr('Editar', 'Edit')">
                                        <PencilIcon class="mx-auto h-4 w-4" />
                                    </button>
                                    <button
                                        class="rounded border px-2 py-2 text-amber-700 disabled:cursor-not-allowed disabled:opacity-40"
                                        :disabled="member.member_type !== 'temp_pathfinder'"
                                        @click="triggerInsuranceUpload(member)"
                                        :title="tr('Subir seguro', 'Upload insurance')"
                                    >
                                        <CameraIcon class="mx-auto h-4 w-4" />
                                    </button>
                                    <button class="rounded border px-2 py-2 text-red-700" @click="deleteMember(member)" :title="tr('Eliminar', 'Delete')">
                                        <TrashIcon class="mx-auto h-4 w-4" />
                                    </button>
                                    <button class="rounded border px-2 py-2 text-blue-700" @click="downloadWord(member)" :title="tr('Descargar formulario', 'Download form')">
                                        <DocumentArrowDownIcon class="mx-auto h-4 w-4" />
                                    </button>
                                    <button class="rounded border px-2 py-2 text-emerald-700" @click="openMemberCharges(member)" :title="tr('Cargos y pagos', 'Charges and payments')">$</button>
                                </div>
                                <div v-if="expandedRows.has(member.id)" class="mt-3 rounded bg-gray-50 p-3 text-xs text-gray-700">
                                    <div class="grid gap-2">
                                        <template v-if="member.member_type === 'master_guide'">
                                            <div><strong>{{ tr('Año del programa', 'Program year') }}:</strong> {{ member.program_year_label || lastCompletedDisplay(member) }}</div>
                                            <div><strong>{{ tr('Telefono', 'Phone') }}:</strong> {{ member.phone || member.cell_number || '—' }}</div>
                                            <div><strong>Email:</strong> {{ member.email || member.email_address || '—' }}</div>
                                            <div><strong>{{ tr('Direccion', 'Address') }}:</strong> {{ member.address || member.home_address || '—' }}</div>
                                            <div><strong>{{ tr('Contacto de emergencia', 'Emergency contact') }}:</strong> {{ member.emergency_contact_name || member.emergency_contact || '—' }}</div>
                                            <div><strong>{{ tr('Telefono de emergencia', 'Emergency phone') }}:</strong> {{ member.emergency_contact_phone || '—' }}</div>
                                            <div><strong>{{ tr('Correo de emergencia', 'Emergency email') }}:</strong> {{ member.emergency_contact_email || '—' }}</div>
                                            <div><strong>{{ tr('Miembro SDA', 'SDA member') }}:</strong> {{ member.is_sda !== false ? tr('Si', 'Yes') : tr('No', 'No') }}</div>
                                            <div><strong>{{ tr('Fecha de bautismo', 'Baptism date') }}:</strong> {{ member.baptism_date ? formatDate(member.baptism_date) : '—' }}</div>
                                        </template>
                                        <template v-else>
                                            <div><strong>{{ tr('Fecha de nacimiento', 'Date of birth') }}:</strong> {{ member.birthdate ? formatDate(member.birthdate) : '—' }}</div>
                                            <div><strong>{{ tr('Edad', 'Age') }}:</strong> {{ member.age ?? '—' }}</div>
                                            <div><strong>{{ tr('Email', 'Email') }}:</strong> {{ member.email_address || '—' }}</div>
                                            <div><strong>{{ tr('Contacto de emergencia', 'Emergency contact') }}:</strong> {{ member.emergency_contact_name || member.emergency_contact || '—' }}</div>
                                            <div><strong>{{ tr('Telefono de emergencia', 'Emergency phone') }}:</strong> {{ member.emergency_contact_phone || '—' }}</div>
                                            <div><strong>{{ tr('Miembro SDA', 'SDA member') }}:</strong> {{ member.is_sda !== false ? tr('Si', 'Yes') : tr('No', 'No') }}</div>
                                            <div><strong>{{ tr('Fecha de bautismo', 'Baptism date') }}:</strong> {{ member.baptism_date ? formatDate(member.baptism_date) : '—' }}</div>
                                        </template>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </article>
                    <div v-if="paginatedMembers.length === 0" class="rounded border border-dashed p-4 text-center text-sm text-gray-500">
                        {{ tr('No se encontraron miembros con ese criterio.', 'No members matched that criteria.') }}
                    </div>
                </div>
                <div class="hidden rounded border sm:block">
                <table class="w-full table-fixed text-sm">
                    <thead class="bg-gray-200">
                        <tr>
                            <th class="w-10 p-2 text-left"></th>
                            <th class="p-2 text-left">{{ tr('Nombre', 'Name') }}</th>
                            <th v-if="!isMasterGuideClub" class="w-[18%] p-2 text-left">{{ contactColumnLabel }}</th>
                            <th class="w-[16%] p-2 text-left">{{ progressColumnLabel }}</th>
                            <th class="w-28 p-2 text-left">{{ tr('Inscripción', 'Enrollment') }}</th>
                            <th class="w-24 p-2 text-left">{{ tr('Seguro', 'Insurance') }}</th>
                            <th class="w-36 p-2 text-left">{{ tr('Acciones', 'Actions') }}</th>
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
                                <td class="p-2 align-top">
                                    <div class="break-words font-semibold text-gray-900">{{ member.applicant_name }}</div>
                                    <div class="mt-1 text-xs text-gray-500">{{ member.is_sda !== false ? 'SDA' : tr('Cuidado pastoral', 'Pastoral care') }}</div>
                                </td>
                                <td v-if="!isMasterGuideClub" class="p-2 align-top">
                                    <a
                                        v-if="!isMasterGuideClub && parentPortalUrl(member)"
                                        :href="parentPortalUrl(member)"
                                        target="_blank"
                                        rel="noopener"
                                        class="font-medium text-blue-700 underline decoration-blue-200 underline-offset-2 hover:text-blue-900"
                                        :title="parentPortalTitle"
                                    >
                                        {{ contactColumnValue(member) }}
                                    </a>
                                    <span v-else class="break-words">{{ contactColumnValue(member) }}</span>
                                    <div class="mt-1 break-words text-xs text-gray-500">{{ phoneColumnValue(member) }}</div>
                                </td>
                                <td class="p-2 align-top">
                                    <select
                                        v-if="member.member_type === 'master_guide'"
                                        :value="Number(member.program_year || 1)"
                                        :disabled="isProgramYearUpdating(member)"
                                        class="w-full min-w-[6rem] rounded border px-2 py-1 text-sm disabled:opacity-60"
                                        @change="event => updateMemberProgramYear(member, event.target.value)"
                                    >
                                        <option v-for="year in programYearOptions" :key="year" :value="year">{{ programYearLabel(year) }}</option>
                                    </select>
                                    <span v-else class="break-words">{{ lastCompletedDisplay(member) }}</span>
                                </td>
                                <td class="p-2 align-top">
                                    <span :class="paymentBadgeClass(member.enrollment_paid)">
                                        {{ member.enrollment_paid ? tr('Pagada', 'Paid') : tr('Pendiente', 'Pending') }}
                                    </span>
                                </td>
                                <td class="p-2 align-top">
                                    <span v-if="selectedClub?.evaluation_system === 'carpetas'" :class="paymentBadgeClass(member.insurance_paid)">
                                        {{ member.insurance_paid ? tr('Pagado', 'Paid') : tr('Pendiente', 'Pending') }}
                                    </span>
                                    <span v-else class="text-xs text-gray-400">N/A</span>
                                </td>
                                <td class="p-2 align-top">
                                    <div class="flex flex-wrap items-center gap-3">
                                    <button class="text-green-600 hover:underline" @click="toggleExpanded(member.id)" :title="tr('Ver detalles', 'View details')">
                                        <component
                                        :is="expandedRows.has(member.id) ? MinusIcon : PlusIcon"
                                        class="w-4 h-4 inline"
                                        />
                                    </button>
                                    <button class="text-blue-600 hover:underline"
                                        @click="editMember(member)"
                                        :title="tr('Editar', 'Edit')">
                                        <PencilIcon class="w-4 h-4 inline" />
                                    </button>
                                    <button v-if="member.member_type === 'temp_pathfinder'" class="text-amber-600 hover:underline"
                                        @click="triggerInsuranceUpload(member)"
                                        :title="tr('Subir seguro', 'Upload insurance')">
                                        <CameraIcon class="w-4 h-4 inline" />
                                    </button>
                                    <button class="text-red-600 hover:underline"
                                        @click="deleteMember(member)"
                                        :title="tr('Eliminar', 'Delete')">
                                        <TrashIcon class="w-4 h-4 inline" />
                                    </button>
                                    <button class="text-blue-600 hover:underline"
                                        @click="downloadWord(member)"
                                        :title="tr('Descargar formulario', 'Download form')">  
                                        <DocumentArrowDownIcon class="w-4 h-4 inline" />
                                    </button>
                                    <button class="text-emerald-700 hover:underline text-xs font-semibold"
                                        @click="openMemberCharges(member)"
                                        :title="tr('Cargos y pagos', 'Charges and payments')">
                                        {{ tr('Cobros', 'Charges') }}
                                    </button>
                                    </div>
                                </td>
                            </tr>

                            <!-- Expandable Child Row -->
                            <tr v-if="expandedRows.has(member.id)" class="bg-gray-50 border-t">
                                <td :colspan="memberDetailsColspan" class="p-4">
                                    <div v-if="member.member_type === 'temp_pathfinder'" class="grid grid-cols-1 md:grid-cols-2 gap-4 text-gray-700">
                                        <div><strong>{{ tr('Fecha de nacimiento', 'Date of birth') }}:</strong> {{ member.birthdate ? formatDate(member.birthdate) : '—' }}</div>
                                        <div><strong>{{ tr('Edad', 'Age') }}:</strong> {{ member.age ?? '—' }}</div>
                                        <div><strong>{{ tr('Grado', 'Grade') }}:</strong> {{ member.grade || '—' }}</div>
                                        <div><strong>{{ tr('Escuela', 'School') }}:</strong> {{ member.school || '—' }}</div>
                                        <div><strong>{{ tr('Direccion', 'Address') }}:</strong> {{ member.mailing_address || '—' }}</div>
                                        <div><strong>{{ tr('Ciudad/Estado/Zip', 'City/State/ZIP') }}:</strong> {{ [member.city, member.state, member.zip].filter(Boolean).join(', ') || '—' }}</div>
                                        <div><strong>{{ tr('Telefono', 'Phone') }}:</strong> {{ member.cell_number || '—' }}</div>
                                        <div><strong>Email:</strong> {{ member.email_address || '—' }}</div>
                                        <div><strong>{{ tr('Padre/Guardian', 'Father/Guardian') }}:</strong> {{ member.father_guardian_name || '—' }}</div>
                                        <div><strong>{{ tr('Email Padre/Guardian', 'Father/Guardian email') }}:</strong> {{ member.father_guardian_email || '—' }}</div>
                                        <div><strong>{{ tr('Telefono Padre/Guardian', 'Father/Guardian phone') }}:</strong> {{ member.father_guardian_phone || '—' }}</div>
                                        <div><strong>{{ tr('Madre/Guardian', 'Mother/Guardian') }}:</strong> {{ member.mother_guardian_name || '—' }}</div>
                                        <div><strong>{{ tr('Email Madre/Guardian', 'Mother/Guardian email') }}:</strong> {{ member.mother_guardian_email || '—' }}</div>
                                        <div><strong>{{ tr('Telefono Madre/Guardian', 'Mother/Guardian phone') }}:</strong> {{ member.mother_guardian_phone || '—' }}</div>
                                        <div><strong>{{ tr('Contacto de emergencia', 'Emergency contact') }}:</strong> {{ member.emergency_contact_name || member.emergency_contact || '—' }}</div>
                                        <div><strong>{{ tr('Telefono de emergencia', 'Emergency phone') }}:</strong> {{ member.emergency_contact_phone || '—' }}</div>
                                        <div><strong>{{ tr('Medico primario', 'Primary physician') }}:</strong> {{ member.physician_name || '—' }}</div>
                                        <div><strong>{{ tr('Telefono del medico', 'Physician phone') }}:</strong> {{ member.physician_phone || '—' }}</div>
                                        <div><strong>{{ tr('Seguro medico', 'Medical insurance') }}:</strong> {{ member.insurance_provider || '—' }}</div>
                                        <div><strong>{{ tr('Numero de poliza', 'Policy number') }}:</strong> {{ member.insurance_number || '—' }}</div>
                                        <div><strong>{{ tr('Inscripción', 'Enrollment') }}:</strong> {{ member.enrollment_paid ? tr('Pagada', 'Paid') : tr('Pendiente', 'Pending') }}</div>
                                        <div><strong>{{ tr('Seguro', 'Insurance') }}:</strong> {{ member.insurance_paid ? tr('Pagado', 'Paid') : tr('Pendiente', 'Pending') }}</div>
                                        <div><strong>{{ tr('Miembro SDA', 'SDA member') }}:</strong> {{ member.is_sda !== false ? tr('Si', 'Yes') : tr('No', 'No') }}</div>
                                        <div><strong>{{ tr('Fecha de bautismo', 'Baptism date') }}:</strong> {{ member.baptism_date ? formatDate(member.baptism_date) : '—' }}</div>
                                        <div class="md:col-span-2">
                                            <strong>{{ tr('Tarjeta de seguro', 'Insurance card') }}:</strong>
                                            <span v-if="member.insurance_card_url">
                                                <a :href="member.insurance_card_url" target="_blank" rel="noopener" class="text-blue-600 hover:underline">{{ tr('Ver imagen', 'View image') }}</a>
                                            </span>
                                            <span v-else>—</span>
                                        </div>
                                        <div><strong>{{ tr('Historial de salud', 'Health history') }}:</strong> {{ member.health_history || '—' }}</div>
                                        <div><strong>{{ tr('Discapacidades', 'Disabilities') }}:</strong> {{ member.disabilities || '—' }}</div>
                                        <div><strong>{{ tr('Alergias a medicamentos', 'Medication allergies') }}:</strong> {{ member.medication_allergies || '—' }}</div>
                                        <div><strong>{{ tr('Alergias a alimentos', 'Food allergies') }}:</strong> {{ member.food_allergies || '—' }}</div>
                                        <div><strong>{{ tr('Consideraciones dieteticas', 'Dietary considerations') }}:</strong> {{ member.dietary_considerations || '—' }}</div>
                                        <div><strong>{{ tr('Restricciones fisicas', 'Physical restrictions') }}:</strong> {{ member.physical_restrictions || '—' }}</div>
                                        <div><strong>{{ tr('Vacunas / shot records', 'Immunizations / shot records') }}:</strong> {{ member.immunization_notes || '—' }}</div>
                                        <div><strong>{{ tr('Medicamentos actuales', 'Current medications') }}:</strong> {{ member.current_medications || '—' }}</div>
                                        <div class="md:col-span-2"><strong>{{ tr('Personas autorizadas para recoger', 'Authorized pickup people') }}:</strong> {{ Array.isArray(member.pickup_authorized_people) && member.pickup_authorized_people.length ? member.pickup_authorized_people.join(', ') : '—' }}</div>
                                        <div><strong>{{ tr('Consentimiento firmado', 'Consent signed') }}:</strong> {{ member.consent_acknowledged ? tr('Si', 'Yes') : tr('No', 'No') }}</div>
                                        <div><strong>{{ tr('Permiso de foto/video', 'Photo/video release') }}:</strong> {{ member.photo_release ? tr('Si', 'Yes') : tr('No', 'No') }}</div>
                                        <div><strong>{{ tr('Firma', 'Signature') }}:</strong> {{ member.signature || '—' }}</div>
                                        <div><strong>{{ tr('Fecha de firma', 'Signature date') }}:</strong> {{ member.signed_at ? formatDate(member.signed_at) : '—' }}</div>
                                    </div>
                                    <div v-else-if="member.member_type === 'master_guide'" class="grid grid-cols-1 md:grid-cols-2 gap-4 text-gray-700">
                                        <div><strong>{{ tr('Año del programa', 'Program year') }}:</strong> {{ member.program_year_label || lastCompletedDisplay(member) }}</div>
                                        <div><strong>{{ tr('Telefono', 'Phone') }}:</strong> {{ member.phone || member.cell_number || '—' }}</div>
                                        <div><strong>Email:</strong> {{ member.email || member.email_address || '—' }}</div>
                                        <div><strong>{{ tr('Direccion', 'Address') }}:</strong> {{ member.address || member.home_address || '—' }}</div>
                                        <div><strong>{{ tr('Contacto de emergencia', 'Emergency contact') }}:</strong> {{ member.emergency_contact_name || member.emergency_contact || '—' }}</div>
                                        <div><strong>{{ tr('Telefono de emergencia', 'Emergency phone') }}:</strong> {{ member.emergency_contact_phone || '—' }}</div>
                                        <div><strong>{{ tr('Correo de emergencia', 'Emergency email') }}:</strong> {{ member.emergency_contact_email || '—' }}</div>
                                        <div><strong>{{ tr('Inscripción', 'Enrollment') }}:</strong> {{ member.enrollment_paid ? tr('Pagada', 'Paid') : tr('Pendiente', 'Pending') }}</div>
                                        <div><strong>{{ tr('Miembro SDA', 'SDA member') }}:</strong> {{ member.is_sda !== false ? tr('Si', 'Yes') : tr('No', 'No') }}</div>
                                        <div><strong>{{ tr('Fecha de bautismo', 'Baptism date') }}:</strong> {{ member.baptism_date ? formatDate(member.baptism_date) : '—' }}</div>
                                        <div v-if="member.custom_fields_display?.length" class="md:col-span-2">
                                            <strong>{{ tr('Campos adicionales', 'Extra fields') }}:</strong>
                                            <div class="mt-2 grid gap-2 md:grid-cols-2">
                                                <div v-for="field in member.custom_fields_display" :key="field.key" class="rounded border bg-white px-3 py-2">
                                                    <span class="font-medium text-gray-700">{{ field.label }}:</span>
                                                    <span class="ml-1">{{ field.value === true ? tr('Si', 'Yes') : field.value === false ? tr('No', 'No') : (field.value || '—') }}</span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <div v-else class="grid grid-cols-1 md:grid-cols-2 gap-4 text-gray-700">
                                        <div><strong>{{ tr('Fecha de nacimiento', 'Date of birth') }}:</strong> {{ member.birthdate ? formatDate(member.birthdate) : '—' }}</div>
                                        <div><strong>{{ tr('Edad', 'Age') }}:</strong> {{ member.age ?? '—' }}</div>
                                        <div><strong>{{ tr('Grado', 'Grade') }}:</strong> {{ member.grade ?? '—' }}</div>
                                        <div><strong>{{ tr('Direccion postal', 'Mailing address') }}:</strong> {{ member.mailing_address }}</div>
                                        <div><strong>{{ tr('Numero celular', 'Cell number') }}:</strong> {{ member.cell_number }}</div>
                                        <div><strong>{{ tr('Contacto de emergencia', 'Emergency contact') }}:</strong> {{ member.emergency_contact }}</div>
                                        <div><strong>{{ tr('Inscripción', 'Enrollment') }}:</strong> {{ member.enrollment_paid ? tr('Pagada', 'Paid') : tr('Pendiente', 'Pending') }}</div>
                                        <div><strong>{{ tr('Seguro', 'Insurance') }}:</strong> {{ member.insurance_paid ? tr('Pagado', 'Paid') : tr('Pendiente', 'Pending') }}</div>
                                        <div><strong>{{ tr('Miembro SDA', 'SDA member') }}:</strong> {{ member.is_sda !== false ? tr('Si', 'Yes') : tr('No', 'No') }}</div>
                                        <div><strong>{{ tr('Fecha de bautismo', 'Baptism date') }}:</strong> {{ member.baptism_date ? formatDate(member.baptism_date) : '—' }}</div>
                                        <div><strong>{{ tr('Alergias', 'Allergies') }}:</strong> {{ member.allergies }}</div>
                                        <div><strong>{{ tr('Restricciones fisicas', 'Physical restrictions') }}:</strong> {{ member.physical_restrictions }}
                                        </div>
                                        <div><strong>{{ tr('Historial de salud', 'Health history') }}:</strong> {{ member.health_history }}</div>
                                        <div><strong>{{ tr('Nombre del padre/madre', 'Parent name') }}:</strong> {{ member.parent_name }}</div>
                                        <div><strong>{{ tr('Correo electronico', 'Email') }}:</strong> {{ member.email_address }}</div>
                                        <div><strong>{{ tr('Firma', 'Signature') }}:</strong> {{ member.signature }}</div>
                                    </div>
                                </td>
                            </tr>
                        </template>
                        <tr v-if="paginatedMembers.length === 0">
                            <td :colspan="memberDetailsColspan" class="p-4 text-center text-gray-500">
                                {{ tr('No se encontraron miembros con ese criterio.', 'No members matched that criteria.') }}
                            </td>
                        </tr>
                    </tbody>
                </table>
                </div>
                <div class="mt-4 flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                    <div class="text-sm text-gray-600">
                        {{ tr('Página', 'Page') }} {{ memberPage }} {{ tr('de', 'of') }} {{ totalMemberPages }}
                    </div>
                    <div class="flex items-center gap-2">
                        <button
                            type="button"
                            @click="goToPreviousMemberPage"
                            :disabled="memberPage <= 1"
                            class="rounded border px-3 py-1.5 text-sm text-gray-700 disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            {{ tr('Anterior', 'Previous') }}
                        </button>
                        <button
                            type="button"
                            @click="goToNextMemberPage"
                            :disabled="memberPage >= totalMemberPages"
                            class="rounded border px-3 py-1.5 text-sm text-gray-700 disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            {{ tr('Siguiente', 'Next') }}
                        </button>
                    </div>
                </div>
                <div class="mt-6 text-center">
                    <button @click="toggleRegistrationForm"
                        class="w-full bg-green-600 px-4 py-2 text-white hover:bg-green-700 sm:w-auto sm:rounded">
                        {{ tr('Registrar nuevo miembro', 'Register new member') }}
                    </button>
                </div>
            </div>

            <!-- Tab 2: Class Overview -->
            <div v-if="selectedTab === 'classes' && selectedClub">
                <div class="mb-4 rounded-lg border bg-white p-4 shadow-sm">
                    <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                        <div>
                            <h2 class="text-lg font-semibold text-gray-900">{{ isMasterGuideClub ? tr('Resumen por año', 'Year Summary') : tr('Resumen de clases', 'Class Summary') }}</h2>
                            <p class="mt-1 text-sm text-gray-600">
                                {{ selectedClub.club_name }} •
                                <template v-if="isMasterGuideClub">{{ tr('Programa de 2 años', '2-year program') }}</template>
                                <template v-else>{{ clubClasses.length }} {{ tr('clases', 'classes') }}</template>
                                • {{ members.length }} {{ tr('miembros', 'members') }}
                            </p>
                        </div>

                        <div v-if="!isMasterGuideClub" class="rounded border border-gray-200 bg-gray-50 p-3">
                            <div class="text-sm font-medium text-gray-700">{{ tr('Exportar PDF', 'Export PDF') }}</div>
                            <div class="mt-2 grid grid-cols-2 gap-2 text-sm sm:flex sm:flex-wrap sm:items-center">
                                <label class="inline-flex items-center gap-2 rounded bg-white px-2 py-2">
                                    <input v-model="classSummaryPdfOptions.include_contact" type="checkbox" />
                                    {{ tr('Contacto', 'Contact') }}
                                </label>
                                <label class="inline-flex items-center gap-2 rounded bg-white px-2 py-2">
                                    <input v-model="classSummaryPdfOptions.include_parent" type="checkbox" />
                                    {{ tr('Padre/Madre', 'Parent') }}
                                </label>
                                <label class="inline-flex items-center gap-2 rounded bg-white px-2 py-2">
                                    <input v-model="classSummaryPdfOptions.include_dob" type="checkbox" />
                                    DOB
                                </label>
                                <label class="inline-flex items-center gap-2 rounded bg-white px-2 py-2">
                                    <input v-model="classSummaryPdfOptions.include_address" type="checkbox" />
                                    {{ tr('Direccion', 'Address') }}
                                </label>
                            </div>
                            <button
                                type="button"
                                @click="exportClassSummaryPdf"
                                class="mt-3 w-full rounded bg-gray-800 px-3 py-3 text-sm text-white hover:bg-gray-900 sm:w-auto sm:py-2"
                            >
                                {{ tr('Exportar PDF', 'Export PDF') }}
                            </button>
                        </div>
                    </div>
                </div>

                <div v-if="isMasterGuideClub" class="grid gap-4 md:grid-cols-2">
                    <article v-for="bucket in masterGuideYearBuckets" :key="bucket.year" class="rounded-lg border bg-white p-4 shadow-sm">
                        <div class="mb-3 flex items-center justify-between gap-3">
                            <div>
                                <h3 class="font-semibold text-gray-900">{{ bucket.label }}</h3>
                                <p class="text-sm text-gray-500">{{ tr('Guias Mayores registrados en este año del programa.', 'Master Guides registered in this program year.') }}</p>
                            </div>
                            <span class="rounded-full bg-blue-100 px-3 py-1 text-xs font-medium text-blue-700">
                                {{ bucket.members.length }} {{ tr('miembros', 'members') }}
                            </span>
                        </div>
                        <div v-if="bucket.members.length" class="space-y-2">
                            <div v-for="member in bucket.members" :key="`mg-year-${bucket.year}-${member.id}`" class="rounded border bg-gray-50 px-3 py-2">
                                <div class="font-medium text-gray-900">{{ member.applicant_name }}</div>
                                <div class="mt-1 text-xs text-gray-500">
                                    {{ member.phone || member.cell_number || '—' }} • {{ member.email || member.email_address || '—' }}
                                </div>
                            </div>
                        </div>
                        <div v-else class="rounded border border-dashed p-4 text-sm text-gray-500">
                            {{ tr('No hay miembros registrados en este año.', 'No members registered in this year.') }}
                        </div>
                    </article>
                </div>
                <div v-else-if="clubClasses.length === 0" class="text-gray-600">
                    {{ tr('No se encontraron clases para este club.', 'No classes were found for this club.') }}
                </div>
                <div v-else class="space-y-6">
                    <div v-if="unassignedMembers.length > 0" class="rounded-lg border bg-gray-100 p-4">
                        <div class="mb-4 flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
                            <h2 class="text-lg font-semibold">{{ tr('Miembros sin asignar', 'Unassigned Members') }}</h2>
                            <span class="text-sm text-gray-600">{{ unassignedMembers.length }} {{ tr('miembros', 'members') }}</span>
                        </div>

                        <div class="space-y-3 sm:hidden">
                            <article v-for="member in unassignedMembers" :key="`unassigned-mobile-${member.id}`" class="rounded-lg border bg-white p-3 shadow-sm">
                                <div class="flex items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <h3 class="break-words text-sm font-semibold text-gray-900">{{ member.applicant_name }}</h3>
                                        <p class="mt-1 text-xs text-gray-500">{{ tr('Edad', 'Age') }}: {{ displayAge(member.age) }}</p>
                                    </div>
                                    <span class="shrink-0 rounded-full bg-amber-100 px-2 py-1 text-xs font-medium text-amber-700">
                                        {{ tr('Sin asignar', 'Unassigned') }}
                                    </span>
                                </div>
                                <div class="mt-3 space-y-2">
                                    <label class="block text-xs font-medium text-gray-600">{{ tr('Asignar a clase', 'Assign to class') }}</label>
                                    <select v-model="member.assigned_class" class="w-full rounded border p-3 text-base">
                                        <option value="" disabled selected>{{ tr('Seleccionar clase', 'Select class') }}</option>
                                        <option v-for="targetClass in clubClasses" :key="targetClass.id" :value="targetClass.id">
                                            {{ targetClass.class_name }} - {{ targetClass.class_order }}
                                        </option>
                                    </select>
                                    <button
                                        @click="() => assignToClass(member)"
                                        :disabled="!member.assigned_class"
                                        class="w-full rounded bg-blue-600 px-3 py-3 text-sm font-medium text-white hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-50"
                                    >
                                        {{ tr('Asignar', 'Assign') }}
                                    </button>
                                </div>
                            </article>
                        </div>

                        <div class="hidden overflow-x-auto sm:block">
                        <table class="min-w-[640px] w-full border text-sm">
                            <thead class="bg-gray-200">
                                <tr>
                                    <th class="p-2">{{ tr('Nombre', 'Name') }}</th>
                                    <th class="p-2">{{ tr('Edad', 'Age') }}</th>
                                    <th class="p-2">{{ tr('Asignar a clase', 'Assign to class') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="member in unassignedMembers" :key="member.id">
                                    <td class="p-2 text-center">{{ member.applicant_name }}</td>
                                    <td class="p-2 text-center">{{ displayAge(member.age) }}</td>
                                    <td class="p-2 text-center">
                                        <select v-model="member.assigned_class" class="border p-2 rounded">
                                            <option value="" disabled selected>{{ tr('Seleccionar clase', 'Select class') }}</option>
                                            <option v-for="targetClass in clubClasses"
                                                :key="targetClass.id" :value="targetClass.id">
                                                {{ targetClass.class_name }} - {{ targetClass.class_order }}
                                            </option>
                                        </select>
                                        &nbsp;&nbsp;
                                        <button @click="() => assignToClass(member)"
                                            :disabled="!member.assigned_class"
                                            class="px-2 py-1 bg-blue-600 text-white text-xs rounded hover:bg-blue-700">
                                            {{ tr('Asignar', 'Assign') }}
                                        </button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                        </div>
                    </div>
                    <div v-for="clubClass in clubClasses" :key="clubClass.id" class="rounded-lg border bg-gray-50 p-4">
                        <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                            <div>
                                <h3 class="text-md font-bold">
                                    {{ clubClass.class_name }}
                                </h3>
                                <p class="text-sm text-gray-600">{{ tr('Orden', 'Order') }}: {{ clubClass.class_order }}</p>
                                <p class="text-sm text-gray-700" v-if="selectedClub.club_type === 'adventurers'">
                                    {{ tr('Personal asignado', 'Assigned staff') }}: {{ clubClass.assigned_staff_name || '—' }}
                                </p>
                            </div>
                            <span class="w-fit rounded-full bg-white px-3 py-1 text-xs font-medium text-gray-700">
                                {{ membersInClass(clubClass.id).length }} {{ tr('miembros', 'members') }}
                            </span>
                        </div>
                        <div v-if="membersInClass(clubClass.id).length === 0" class="mt-4 rounded border border-dashed border-gray-200 bg-white p-4 text-sm text-gray-600">
                            {{ tr('No hay miembros asignados a esta clase.', 'No members are assigned to this class.') }}
                        </div>

                        <div v-else class="mt-4 space-y-3 sm:hidden">
                            <article v-for="member in membersInClass(clubClass.id)" :key="`class-mobile-${clubClass.id}-${member.id}`" class="rounded-lg border bg-white p-3 shadow-sm">
                                <div class="flex items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <h4 class="break-words text-sm font-semibold text-gray-900">{{ member.applicant_name }}</h4>
                                        <p class="mt-1 text-xs text-gray-500">{{ tr('Edad', 'Age') }}: {{ displayAge(member.age) }}</p>
                                    </div>
                                    <span class="shrink-0 rounded-full bg-blue-100 px-2 py-1 text-xs font-medium text-blue-700">
                                        {{ clubClass.class_name }}
                                    </span>
                                </div>

                                <div class="mt-3 space-y-2">
                                    <label class="block text-xs font-medium text-gray-600">{{ tr('Mover a clase', 'Move to class') }}</label>
                                    <select v-model="member.assigned_class" class="w-full rounded border p-3 text-base">
                                        <option v-for="targetClass in classOptionsExcluding(clubClass.class_order)" :key="targetClass.id" :value="targetClass.id">
                                            {{ targetClass.class_name }}
                                        </option>
                                    </select>
                                    <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                                        <button
                                            @click="() => assignToClass(member)"
                                            :disabled="!member.assigned_class"
                                            class="rounded bg-blue-600 px-3 py-3 text-sm font-medium text-white hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-50"
                                        >
                                            {{ tr('Asignar', 'Assign') }}
                                        </button>
                                        <button
                                            @click="() => undoAssignment(member)"
                                            class="rounded bg-red-500 px-3 py-3 text-sm font-medium text-white hover:bg-red-600"
                                        >
                                            {{ tr('Deshacer ultimo', 'Undo last') }}
                                        </button>
                                    </div>
                                </div>
                            </article>
                        </div>

                        <div v-if="membersInClass(clubClass.id).length" class="mt-4 hidden overflow-x-auto sm:block">
                        <table class="min-w-[720px] w-full border text-sm">
                            <thead class="bg-gray-100">
                                <tr>
                                    <th class="p-2">{{ tr('Nombre', 'Name') }}</th>
                                    <th class="p-2">{{ tr('Edad', 'Age') }}</th>
                                    <th class="p-2">{{ tr('Mover a clase', 'Move to class') }}</th>
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
                                            {{ tr('Asignar', 'Assign') }}
                                        </button>
                                        <button @click="() => undoAssignment(member)"
                                            class="ml-2 px-2 py-1 bg-red-500 text-white text-xs rounded hover:bg-red-600">
                                            {{ tr('Deshacer ultimo', 'Undo last') }}
                                        </button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                        </div>
                    </div>
                </div>
            </div>


            <!-- MODALS -->
            <MemberRegistrationModal :show="showAdventurerRegistrationModal" :clubs="clubs" :selectedClub="selectedClub" :editing-member="editingMember"
                @close="showAdventurerRegistrationModal = false; editingMember = null" @submitted="fetchMembers(selectedClub.id); editingMember = null" />
            <PathfinderMemberRegistrationModal :show="showPathfinderRegistrationModal" :selectedClub="selectedClub" :editing-member="editingMember"
                @close="showPathfinderRegistrationModal = false; editingMember = null" @submitted="fetchMembers(selectedClub.id); editingMember = null" />
            <MasterGuideMemberRegistrationModal :show="showMasterGuideRegistrationModal" :selected-club="selectedClub" :editing-member="editingMember"
                @close="showMasterGuideRegistrationModal = false; editingMember = null" @submitted="fetchMembers(selectedClub.id); editingMember = null" />
            <DeleteMemberModal :show="showDeleteModal" :memberId="deletingMember?.id"
                :memberName="deletingMember?.applicant_name" @cancel="showDeleteModal = false"
                @confirm="handleMemberDelete" />
            <MemberChargesModal
                :show="showChargesModal"
                :member="chargesMember"
                @close="showChargesModal = false; chargesMember = null"
                @updated="fetchMembers(selectedClub.id)"
            />
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
