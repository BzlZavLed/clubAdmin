<script setup>
import { useForm, router } from '@inertiajs/vue3'
import CreateClassModal from '@/Components/CreateClassModal.vue'
import PathfinderLayout from '@/Layouts/PathfinderLayout.vue'
import { useAuth } from '@/Composables/useAuth'
import { useGeneral } from '@/Composables/useGeneral'
import { refreshPage } from '@/Helpers/general'
import { computed, ref, watch, onMounted } from 'vue'

import {
    fetchClubsByUserId,
    fetchClubsByChurchId,
    deleteClubById,
    selectUserClub,
    attachDirectorToClub,
    detachDirectorFromClub,
    createClub,
    updateClub as updateClubApi,
    createClubObjective,
    updateClubObjective,
    deleteClubObjective,
    deleteClassById,
    fetchMembersByClub,
    createInvestitureRequirement,
    updateInvestitureRequirement,
    deleteInvestitureRequirement
} from '@/Services/api'

const props = defineProps({
    churches: {
        type: Array,
        default: () => []
    },
    superadmin_context: {
        type: Object,
        default: null
    }
})

// 🧠 Auth state
const { user } = useAuth()
const isSuperadmin = computed(() => user.value?.profile_type === 'superadmin')
const directorClubCount = computed(() => new Set((clubs.value || []).map(club => Number(club.id))).size)
const canCreateAnotherClub = computed(() => isSuperadmin.value || directorClubCount.value < 2)
const clubLimitReached = computed(() => !isSuperadmin.value && directorClubCount.value >= 2)

const { showToast } = useGeneral()
const today = new Date().toISOString().split("T")[0]

// 🧠 UI & state
const isEditing = ref(false)
const addClub = ref(false)
const editingClubId = ref(null)
const clubs = ref([])
const churchClubs = ref([])
const showClassModal = ref(false)
const classToEdit = ref(null)
const hasClub = ref(false)
const requirementDraftByClass = ref({})
const editingRequirementByClass = ref({})
const showRequirementFormByClass = ref({})
const objectiveDraftByClub = ref({})
const editingObjectiveByClub = ref({})
const showObjectiveFormByClub = ref({})

// 🧠 Derived data
const church_name = user.value.church_name || 'Iglesia desconocida'
const clubId = ref(
    isSuperadmin.value
        ? (props.superadmin_context?.club_id || null)
        : (user.value.club_id || null)
)

const clubStaff = computed(() => {
    return clubs.value[0]?.staff_adventurers ?? []
})
if (!isSuperadmin.value && !user.value.pastor_name) {
    showToast('Primero crea la iglesia', 'error')
}

const initialChurch = isSuperadmin.value
    ? (props.churches.find(ch => Number(ch.id) === Number(user.value.church_id)) || props.churches[0] || null)
    : null

// 🧠 Club form
const clubForm = useForm({
    church_id: isSuperadmin.value ? (initialChurch?.id || '') : user.value.church_id,
    club_name: '',
    church_name: isSuperadmin.value ? (initialChurch?.church_name || '') : user.value.church_name,
    director_name: user.value.name,
    creation_date: today,
    pastor_name: isSuperadmin.value
        ? (initialChurch?.pastor_name || '')
        : (user.value.pastor_name || 'Iglesia no creada'),
    conference_name: isSuperadmin.value
        ? (initialChurch?.conference || '')
        : (user.value.conference_name || 'Iglesia no creada'),
    conference_region: '',
    club_type: ''
})

const activeClubId = computed(() => {
    if (isSuperadmin.value) {
        return props.superadmin_context?.club_id
            ? Number(props.superadmin_context.club_id)
            : null
    }

    return clubId.value ? Number(clubId.value) : null
})

const filteredClubs = computed(() => {
    return activeClubId.value
        ? clubs.value.filter(club => Number(club.id) === Number(activeClubId.value))
        : clubs.value
})
const churchClubTypes = computed(() => new Set(
    churchClubs.value
        .map(club => club.club_type)
        .filter(type => ['adventurers', 'pathfinders'].includes(type))
))
const missingChurchClubTypes = computed(() =>
    ['adventurers', 'pathfinders'].filter(type => !churchClubTypes.value.has(type))
)
const eligibleAttachClubs = computed(() =>
    churchClubs.value.filter(club =>
        ['adventurers', 'pathfinders'].includes(club.club_type) &&
        !clubs.value.some(ownedClub => Number(ownedClub.id) === Number(club.id))
    )
)
const canUnlinkFromClub = computed(() => clubs.value.length > 0)
const mustAttachInsteadOfCreate = computed(() =>
    !isSuperadmin.value &&
    canCreateAnotherClub.value &&
    missingChurchClubTypes.value.length === 0 &&
    eligibleAttachClubs.value.length > 0
)

watch(() => clubForm.church_id, (churchId) => {
    if (!isSuperadmin.value) return
    const selected = props.churches.find(ch => Number(ch.id) === Number(churchId))
    clubForm.church_name = selected?.church_name || ''
    clubForm.pastor_name = selected?.pastor_name || ''
    clubForm.conference_name = selected?.conference || ''
})

// 🧠 Load clubs on mount
const fetchClubs = async () => {
    try {
        const data = await fetchClubsByUserId(user.value.id)
        clubs.value = Array.isArray(data) ? data : []
        hasClub.value = clubs.value.length > 0
        if (!clubId.value && clubs.value.length && !isSuperadmin.value) {
            clubId.value = clubs.value[0].id
        }
        if (isSuperadmin.value && props.superadmin_context?.club_id) {
            clubId.value = Number(props.superadmin_context.club_id)
        }
        if (!isSuperadmin.value && user.value?.church_id) {
            const churchData = await fetchClubsByChurchId(user.value.church_id)
            churchClubs.value = Array.isArray(churchData) ? churchData : []
        } else {
            churchClubs.value = []
        }
        showToast('Clubes cargados correctamente')
    } catch (error) {
        console.error('Failed to fetch clubs:', error)
        showToast('Error al cargar clubes', 'error')
    }
}

// 🧠 Submit & update club
const submitClub = async () => {
    try {
        await createClub(clubForm)
        showToast('Club creado correctamente')
        addClub.value = false
        await fetchClubs()
        await router.reload({ only: ['auth'] })
    } catch (error) {
        console.error(error)
        showToast(error?.response?.data?.message || 'No se pudo crear el club', 'error')
    }
}

const updateClub = async () => {
    try {
        await updateClubApi(clubForm)
        showToast('Club actualizado correctamente')
        isEditing.value = false
        editingClubId.value = null
        fetchClubs()
    } catch (error) {
        console.error(error)
        showToast('No se pudo actualizar el club', 'error')
    }
}

// 🧠 Editing form
const editClub = (club) => {
    isEditing.value = true
    editingClubId.value = club.id
    clubForm.reset()
    Object.assign(clubForm, { ...club })
}

// 🧠 Delete club or class
const deleteClub = async (clubId) => {
    if (!confirm('¿Seguro que deseas eliminar este club?')) return
    try {
        await deleteClubById(clubId)
        showToast('Club eliminado correctamente')
        fetchClubs()
    } catch (error) {
        console.error('Failed to delete club:', error)
        showToast('Error al eliminar el club', 'error')
    }
}

const deleteCls = async (classID) => {
    if (!confirm('¿Seguro que deseas eliminar esta clase?')) return
    try {
        await deleteClassById(classID)
        showToast('Clase eliminada correctamente')
        fetchClubs()
    } catch (error) {
        console.error('Failed to delete class:', error)
        showToast('Error al eliminar la clase', 'error')
    }
}

const getClassRequirements = (cls) => {
    if (!Array.isArray(cls?.investiture_requirements)) return []
    return cls.investiture_requirements
        .slice()
        .sort((a, b) => {
            const oa = Number(a.sort_order || 0)
            const ob = Number(b.sort_order || 0)
            if (oa !== ob) return oa - ob
            return Number(a.id || 0) - Number(b.id || 0)
        })
}

const getRequirementDraft = (classId) => {
    if (!requirementDraftByClass.value[classId]) {
        requirementDraftByClass.value[classId] = {
            title: '',
            description: '',
            sort_order: ''
        }
    }
    return requirementDraftByClass.value[classId]
}

const startCreateRequirement = (classId) => {
    showRequirementFormByClass.value[classId] = true
    editingRequirementByClass.value[classId] = null
    requirementDraftByClass.value[classId] = {
        title: '',
        description: '',
        sort_order: ''
    }
}

const startEditRequirement = (classId, requirement) => {
    showRequirementFormByClass.value[classId] = true
    editingRequirementByClass.value[classId] = requirement.id
    requirementDraftByClass.value[classId] = {
        title: requirement.title || '',
        description: requirement.description || '',
        sort_order: requirement.sort_order || ''
    }
}

const cancelRequirementEdit = (classId) => {
    showRequirementFormByClass.value[classId] = false
    editingRequirementByClass.value[classId] = null
    requirementDraftByClass.value[classId] = {
        title: '',
        description: '',
        sort_order: ''
    }
}

const saveRequirement = async (cls) => {
    const classId = cls?.id
    if (!classId) return
    const draft = getRequirementDraft(classId)
    if (!draft.title?.trim()) {
        showToast('El requisito necesita un titulo', 'error')
        return
    }

    const payload = {
        title: draft.title.trim(),
        description: draft.description?.trim() || null,
        sort_order: draft.sort_order ? Number(draft.sort_order) : null
    }

    try {
        const editingId = editingRequirementByClass.value[classId]
        if (editingId) {
            await updateInvestitureRequirement(editingId, payload)
            showToast('Requisito actualizado')
        } else {
            await createInvestitureRequirement(classId, payload)
            showToast('Requisito creado')
        }
        cancelRequirementEdit(classId)
        await fetchClubs()
    } catch (error) {
        console.error('Failed to save requirement:', error)
        showToast('No se pudo guardar el requisito', 'error')
    }
}

const removeRequirement = async (requirementId) => {
    if (!confirm('¿Seguro que deseas eliminar este requisito?')) return
    try {
        await deleteInvestitureRequirement(requirementId)
        showToast('Requisito eliminado')
        await fetchClubs()
    } catch (error) {
        console.error('Failed to delete requirement:', error)
        showToast('No se pudo eliminar el requisito', 'error')
    }
}

// 🧠 Select club (director choosing one)
const selectClub = async (nextClubId) => {
    try {
        await selectUserClub(nextClubId, user.value.id)
        showToast('Club seleccionado correctamente')
        clubId.value = Number(nextClubId)
        await router.reload({ only: ['auth'] })
        if (!isSuperadmin.value) {
            refreshPage()
        }
    } catch (error) {
        console.error('Failed to select club:', error)
        if (!isSuperadmin.value) {
            refreshPage()
        }
    }
}

// 🧠 Get assigned staff name by class (prefers staff.assigned_class mapping)
const getStaffName = (cls) => {
    if (!cls) return '—'
    if (cls.assigned_staff_name) return cls.assigned_staff_name
    const byClass = clubStaff.value.find(s => String(s.assigned_class) === String(cls.id))
    if (byClass) return byClass.name
    if (cls.assigned_staff_id) {
        const legacy = clubStaff.value.find(s => s.id === cls.assigned_staff_id)
        if (legacy) return legacy.name
    }
    return '—'
}

// 🧠 Modal handling
const openNewClassModal = () => {
    classToEdit.value = null
    showClassModal.value = true
}

const editCls = (cls) => {
    classToEdit.value = cls
    showClassModal.value = true
}

const exportClassesPdf = (withRequirements = false) => {
    const routeName = withRequirements
        ? 'club-classes.pdf-with-requirements'
        : 'club-classes.pdf'
    const clubId = activeClubId.value ? Number(activeClubId.value) : null
    const url = clubId
        ? route(routeName, { club_id: clubId })
        : route(routeName)
    window.open(url, '_blank')
}

const getClubObjectives = (club) => {
    if (!Array.isArray(club?.local_objectives)) return []
    return club.local_objectives
        .filter(objective => objective.status !== 'inactive')
        .slice()
        .sort((a, b) => String(a.name || '').localeCompare(String(b.name || '')))
}

const getObjectiveDraft = (clubId) => {
    if (!objectiveDraftByClub.value[clubId]) {
        objectiveDraftByClub.value[clubId] = {
            name: '',
            description: '',
            annual_evaluation_metric: '',
            external_objective_id: '',
        }
    }

    return objectiveDraftByClub.value[clubId]
}

const startCreateObjective = (clubId) => {
    showObjectiveFormByClub.value[clubId] = true
    editingObjectiveByClub.value[clubId] = null
    objectiveDraftByClub.value[clubId] = {
        name: '',
        description: '',
        annual_evaluation_metric: '',
        external_objective_id: '',
    }
}

const startEditObjective = (clubId, objective) => {
    showObjectiveFormByClub.value[clubId] = true
    editingObjectiveByClub.value[clubId] = objective.id
    objectiveDraftByClub.value[clubId] = {
        name: objective.name || '',
        description: objective.description || '',
        annual_evaluation_metric: objective.annual_evaluation_metric || '',
        external_objective_id: objective.external_objective_id || '',
    }
}

const cancelObjectiveEdit = (clubId) => {
    showObjectiveFormByClub.value[clubId] = false
    editingObjectiveByClub.value[clubId] = null
    objectiveDraftByClub.value[clubId] = {
        name: '',
        description: '',
        annual_evaluation_metric: '',
        external_objective_id: '',
    }
}

const saveObjective = async (club) => {
    const clubId = club?.id
    if (!clubId) return

    const draft = getObjectiveDraft(clubId)
    if (!draft.name?.trim()) {
        showToast('El objetivo necesita un nombre', 'error')
        return
    }

    const payload = {
        name: draft.name.trim(),
        annual_evaluation_metric: draft.annual_evaluation_metric?.trim() || null,
        description: draft.description?.trim() || null,
        external_objective_id: draft.external_objective_id ? Number(draft.external_objective_id) : null,
    }

    try {
        const editingId = editingObjectiveByClub.value[clubId]
        if (editingId) {
            await updateClubObjective(clubId, editingId, payload)
            showToast('Objetivo actualizado')
        } else {
            await createClubObjective(clubId, payload)
            showToast('Objetivo creado')
        }

        cancelObjectiveEdit(clubId)
        await fetchClubs()
    } catch (error) {
        console.error('Failed to save objective:', error)
        showToast(error?.response?.data?.message || 'No se pudo guardar el objetivo', 'error')
    }
}

const removeObjective = async (clubId, objectiveId) => {
    if (!confirm('¿Seguro que deseas eliminar este objetivo?')) return

    try {
        await deleteClubObjective(clubId, objectiveId)
        showToast('Objetivo eliminado')
        await fetchClubs()
    } catch (error) {
        console.error('Failed to delete objective:', error)
        showToast(error?.response?.data?.message || 'No se pudo eliminar el objetivo', 'error')
    }
}

// 🧠 Start new form
const startCreatingClub = () => {
    if (!canCreateAnotherClub.value) {
        showToast('Ya tienes el maximo de 2 clubes asignados.', 'error')
        return
    }
    if (mustAttachInsteadOfCreate.value) {
        showToast('Tu iglesia ya tiene ambos tipos de club. Debes adjuntarte al club existente disponible.', 'error')
        return
    }
    addClub.value = true
    clubForm.reset()
    const selected = props.churches.find(ch => Number(ch.id) === Number(clubForm.church_id))
    Object.assign(clubForm, {
        church_id: isSuperadmin.value ? (selected?.id || props.churches?.[0]?.id || '') : user.value.church_id,
        club_name: '',
        church_name: isSuperadmin.value
            ? (selected?.church_name || props.churches?.[0]?.church_name || '')
            : user.value.church_name,
        director_name: user.value.name,
        creation_date: today,
        pastor_name: isSuperadmin.value
            ? (selected?.pastor_name || props.churches?.[0]?.pastor_name || '')
            : user.value.pastor_name,
        conference_name: isSuperadmin.value
            ? (selected?.conference || props.churches?.[0]?.conference || '')
            : (user.value.conference_name || ''),
        conference_region: '',
        club_type: ''
    })
    if (!isSuperadmin.value && missingChurchClubTypes.value.length === 1) {
        clubForm.club_type = missingChurchClubTypes.value[0]
    }
}

const attachToExistingClub = async (club) => {
    try {
        await attachDirectorToClub(club.id, user.value.id)
        showToast('Ahora estas vinculado a este club como director')
        await fetchClubs()
        await router.reload({ only: ['auth'] })
    } catch (error) {
        console.error('Failed to attach to existing club:', error)
        showToast(error?.response?.data?.message || 'No se pudo adjuntar al club', 'error')
    }
}

const unlinkFromClub = async (club) => {
    const confirmed = window.confirm(`¿Seguro que deseas desvincularte del club ${club.club_name}?`)
    if (!confirmed) return

    try {
        await detachDirectorFromClub(club.id, user.value.id)
        showToast('Te desvinculaste del club correctamente')
        await fetchClubs()
        await router.reload({ only: ['auth'] })
    } catch (error) {
        console.error('Failed to detach from club:', error)
        showToast(error?.response?.data?.message || 'No se pudo desvincular del club', 'error')
    }
}


//PAYMENT CONCEPTS

const conceptClubId = ref('')
const conceptMembers = ref([]) // members for selected club (for 'member' scope or reimbursement payee)
const conceptStaff = computed(() => {
    if (!conceptClubId.value) return []
    const club = clubs.value.find(c => c.id === conceptClubId.value)
    return club?.staff_adventurers ?? []
})
const conceptClasses = computed(() => {
    if (!conceptClubId.value) return []
    const club = clubs.value.find(c => c.id === conceptClubId.value)
    return (club?.club_classes ?? []).slice().sort((a, b) => a.class_order - b.class_order)
})

const paymentConcepts = ref([]) // table data

// Form (useForm for nice error handling later)
const pcForm = useForm({
    concept: '',
    payment_expected_by: '', // yyyy-mm-dd
    type: 'mandatory',       // mandatory|optional
    pay_to: 'club_budget',   // church_budget|club_budget|conference|reimbursement_to
    payee_type: null,        // 'App\\Models\\MemberAdventurer' | 'App\\Models\\StaffAdventurer' | null
    payee_id: null,
    status: 'active',        // active|inactive
    club_id: null,           // the club to which this concept belongs
    // Multi-scope:
    // Each item: { scope_type: 'club_wide'|'class'|'member'|'staff_wide'|'staff', club_id?, class_id?, member_id?, staff_id? }
    scopes: []
})

// Small helpers for labels
const scopeTypeOptions = [
    { value: 'club_wide', label: 'Todo el club' },
    { value: 'class', label: 'Clase especifica' },
    { value: 'member', label: 'Miembro especifico' },
    { value: 'staff_wide', label: 'Todo el personal' },
    { value: 'staff', label: 'Personal especifico' }
]

const payToOptions = [
    { value: 'church_budget', label: 'Presupuesto de iglesia' },
    { value: 'club_budget', label: 'Presupuesto de club' },
    { value: 'conference', label: 'Conferencia' },
    { value: 'reimbursement_to', label: 'Reembolso a…' }
]

const typeOptions = [
    { value: 'mandatory', label: 'Obligatorio' },
    { value: 'optional', label: 'Opcional' }
]

const statusOptions = [
    { value: 'active', label: 'Activo' },
    { value: 'inactive', label: 'Inactivo' }
]

// derive current club name (for sanity)
const conceptClubName = computed(() => {
    const club = clubs.value.find(c => c.id === conceptClubId.value)
    return club?.club_name ?? ''
})

// scope builder actions
function addScope() {
    if (!conceptClubId.value) {
        showToast('Selecciona un club para este concepto primero', 'error')
        return
    }
    pcForm.scopes.push({ scope_type: 'club_wide', club_id: conceptClubId.value })
}

function removeScope(idx) {
    pcForm.scopes.splice(idx, 1)
}

function onScopeTypeChange(scope) {
    // Clean fields not used by the selected type
    scope.club_id = null
    scope.class_id = null
    scope.member_id = null
    scope.staff_id = null

    if (scope.scope_type === 'club_wide' || scope.scope_type === 'staff_wide') {
        scope.club_id = conceptClubId.value || null
    }
}

// Fetch members whenever the concept club changes
watch(conceptClubId, async (id) => {
    pcForm.club_id = id || null
    if (!id) {
        conceptMembers.value = []
        return
    }
    try {
        const data = await fetchMembersByClub(id)
        conceptMembers.value = Array.isArray(data) ? data : []
    } catch (e) {
        conceptMembers.value = []
    }
})

// (Later) API calls — stubbed now
async function loadPaymentConcepts() {
    paymentConcepts.value = [] // default
    // if (!conceptClubId.value) return
    // const { data } = await listPaymentConceptsByClub(conceptClubId.value)
    // paymentConcepts.value = data?.data ?? []
}

async function savePaymentConcept() {
    if (!pcForm.club_id) {
        showToast('Selecciona el club del concepto', 'error')
        return
    }
    if (pcForm.scopes.length === 0) {
        showToast('Agrega al menos un alcance', 'error')
        return
    }

    // If pay_to != reimbursement_to, clear payee*
    if (pcForm.pay_to !== 'reimbursement_to') {
        pcForm.payee_type = null
        pcForm.payee_id = null
    }

    try {
        // await createPaymentConcept(pcForm) // when backend ready
        showToast('Concepto de pago guardado (stub)', 'success')
        pcForm.reset()
        pcForm.type = 'mandatory'
        pcForm.pay_to = 'club_budget'
        pcForm.status = 'active'
        pcForm.club_id = conceptClubId.value || null
        pcForm.scopes = []
        await loadPaymentConcepts()
    } catch (e) {
        showToast('No se pudo guardar el concepto', 'error')
    }
}

async function deleteConcept(id) {
    try {
        // await deletePaymentConcept(id)
        showToast('Concepto eliminado (stub)', 'success')
        await loadPaymentConcepts()
    } catch (e) {
        showToast('No se pudo eliminar el concepto', 'error')
    }
}

const staffList = ref([])

const fetchStaff = async (clubId) => {
    try {
        const response = await axios.get(`/clubs/${clubId}/staff`)
        staffList.value = response.data.staff
        if(staffList.value.length === 0) {
            showToast('Crea personal primero, no se encontro ninguno','error')
            return
        }
        showToast('Personal cargado','success');
        console.log(staffList.value);
    } catch (error) {
        console.error('Failed to fetch staff:', error)
    }
};
const members = ref([])

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
        showToast('Error al obtener miembros', 'error')
    }
};

// When the concept club changes, refresh lists if reimbursement mode is on
watch([conceptClubId, () => pcForm.pay_to], async ([clubId, payTo]) => {
    if (!clubId) { staffList.value = []; members.value = []; return }
    if (payTo === 'reimbursement_to') {
        await Promise.all([fetchStaff(clubId), fetchMembers(clubId)])
    }
})

// Clear the selected payee when changing type (prevents stale ids)
watch(() => pcForm.payee_type, () => { pcForm.payee_id = null })

// Also clear payee entirely when switching away from reimbursement
watch(() => pcForm.pay_to, (val) => {
    if (val !== 'reimbursement_to') { pcForm.payee_type = null; pcForm.payee_id = null }
})


onMounted(fetchClubs);
</script>


<template>
    <PathfinderLayout>
        <template #title>Mi club</template>

        <div v-if="isSuperadmin" class="mb-4 rounded border bg-white p-4 space-y-3">
            <p class="text-sm font-semibold text-gray-800">Contexto Superadmin</p>
            <div class="flex flex-col md:flex-row gap-2 md:items-center md:justify-between">
                <div class="text-sm text-gray-600">
                    Club activo:
                    <span class="font-medium text-gray-900">
                        {{ filteredClubs[0]?.club_name || 'Selecciona un club desde el selector global' }}
                    </span>
                </div>
                <button
                    v-if="canCreateAnotherClub"
                    type="button"
                    class="px-3 py-2 rounded bg-blue-600 text-white text-sm"
                    @click="startCreatingClub"
                >
                    Crear nuevo club
                </button>
            </div>
            <p v-if="clubLimitReached" class="text-xs text-amber-700">Este director ya tiene el maximo de 2 clubes asignados.</p>
        </div>

        <div v-else class="mb-4 rounded border bg-white p-4 space-y-3">
            <p class="text-sm font-semibold text-gray-800">Gestion de clubes</p>
            <p v-if="mustAttachInsteadOfCreate" class="text-sm text-amber-700">
                Esta iglesia ya tiene clubes de Aventureros y Conquistadores. En lugar de crear otro club, puedes adjuntarte al club existente que aun no diriges.
            </p>
            <p v-else-if="missingChurchClubTypes.length && canCreateAnotherClub" class="text-sm text-gray-600">
                Puedes crear un club nuevo para el tipo faltante:
                <strong>{{ missingChurchClubTypes.join(', ') }}</strong>.
            </p>
            <p v-else-if="clubLimitReached" class="text-sm text-amber-700">
                Ya alcanzaste el maximo de 2 clubes asignados.
            </p>

            <div v-if="mustAttachInsteadOfCreate" class="space-y-2">
                <div
                    v-for="club in eligibleAttachClubs"
                    :key="club.id"
                    class="flex flex-col gap-2 rounded border border-amber-200 bg-amber-50 px-3 py-3 md:flex-row md:items-center md:justify-between"
                >
                    <div>
                        <div class="font-medium text-gray-900">{{ club.club_name }}</div>
                        <div class="text-sm text-gray-600 capitalize">{{ club.club_type }} | {{ club.church_name }}</div>
                    </div>
                    <button
                        type="button"
                        class="rounded bg-blue-600 px-3 py-2 text-sm text-white hover:bg-blue-700"
                        @click="attachToExistingClub(club)"
                    >
                        Adjuntarme como director
                    </button>
                </div>
            </div>
        </div>

        <div v-if="isEditing || addClub || (clubs.length === 0 && !clubId)" class="space-y-6">
            <p class="text-gray-700">
                {{ isEditing ? 'Edita tu club a continuacion:' : 'Crea tu club a continuacion.' }}
            </p>

            <form class="space-y-4" @submit.prevent="isEditing ? updateClub() : submitClub()">
                <div v-for="field in [
                    { key: 'club_name', label: 'Nombre del club' },
                    { key: 'church_name', label: 'Nombre de la iglesia' , readonly: true },
                    { key: 'director_name', label: 'Nombre del director', readonly: true },
                    { key: 'creation_date', label: 'Fecha de creacion', type: 'date' },
                    { key: 'pastor_name', label: 'Nombre del pastor', readonly: true },
                    { key: 'conference_name', label: 'Nombre de la conferencia', readonly: true },
                    { key: 'conference_region', label: 'Region de la conferencia' }
                ]" :key="field.key">
                    <label class="block text-sm font-medium text-gray-700">{{ field.label }}</label>
                    <template v-if="field.key === 'church_name' && isSuperadmin">
                        <select v-model="clubForm.church_id" class="w-full mt-1 p-2 border rounded">
                            <option value="">Selecciona una iglesia</option>
                            <option v-for="church in props.churches" :key="church.id" :value="church.id">
                                {{ church.church_name }}
                            </option>
                        </select>
                    </template>
                    <template v-else>
                        <input v-model="clubForm[field.key]" :type="field.type || 'text'" :readonly="field.readonly"
                            class="w-full mt-1 p-2 border rounded" />
                    </template>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700">Tipo de club</label>
                    <select v-model="clubForm.club_type" class="w-full mt-1 p-2 border rounded">
                        <option value="">Seleccionar tipo</option>
                        <option value="adventurers">Aventureros</option>
                        <option value="pathfinders">Conquistadores</option>
                        <option value="master_guide">Guia Mayor</option>
                    </select>
                </div>

                <div class="flex items-center space-x-4">
                    <button type="submit" class="bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded">
                        {{ isEditing ? 'Actualizar club' : 'Guardar club' }}
                    </button>
                    <button v-if="isEditing || addClub" type="button" @click="() => {
                        isEditing = false;
                        addClub = false;
                        editingClubId = null
                    }" class="text-sm text-gray-600 hover:underline">
                        Cancelar edicion
                    </button>
                </div>
            </form>
        </div>
        <div v-else-if="!clubId && clubs.length > 0" class="space-y-6">
            <p class="text-gray-700">Selecciona un club existente de tu iglesia: {{ church_name || 'Iglesia desconocida' }}</p>
            <table class="min-w-full border rounded text-sm">
                <thead class="bg-gray-100">
                    <tr>
                        <th class="p-2 text-left">Nombre</th>
                        <th class="p-2 text-left">Tipo</th>
                        <th class="p-2 text-left">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="club in clubs" :key="club.id" class="border-t">
                        <td class="p-2">{{ club.club_name }}</td>
                        <td class="p-2 capitalize">{{ club.club_type }}</td>
                        <td class="p-2 space-x-2">
                            <button @click="selectClub(club.id)" class="text-blue-600 hover:underline">Seleccionar</button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <div v-else class="space-y-4">
            <details open class="border rounded">
                <summary class="bg-gray-100 px-4 py-2 font-semibold cursor-pointer">Informacion del club</summary>
                <div class="p-4">
                    <table class="min-w-full border rounded text-sm">
                        <thead class="bg-gray-100">
                            <tr>
                                <th class="p-2 text-left">Nombre</th>
                                <th class="p-2 text-left">Iglesia</th>
                                <th class="p-2 text-left">Tipo</th>
                                <th class="p-2 text-left">Creado</th>
                                <th class="p-2 text-left">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="club in clubs" :key="club.id" class="border-t">
                                <td class="p-2">{{ club.club_name }}</td>
                                <td class="p-2">{{ club.church_name }}</td>
                                <td class="p-2 capitalize">{{ club.club_type }}</td>
                                <td class="p-2">{{ club.creation_date }}</td>
                                <td class="p-2 space-x-2">
                                    <button @click="editClub(club)" class="text-blue-600 hover:underline">Editar</button>
                                    <button
                                        v-if="!isSuperadmin"
                                        @click="unlinkFromClub(club)"
                                        class="text-amber-600 hover:underline"
                                    >
                                        Desvincularme
                                    </button>
                                    <button @click="deleteClub(club.id)"
                                        class="text-red-600 hover:underline">Eliminar</button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <div class="mt-4">
                        <button
                            v-if="canCreateAnotherClub && !mustAttachInsteadOfCreate"
                            @click="startCreatingClub"
                            class="px-4 py-2 bg-green-600 text-white rounded hover:bg-green-700">
                            + Crear club
                        </button>
                        <p v-else-if="mustAttachInsteadOfCreate" class="text-sm text-amber-700">
                            Tu iglesia ya tiene ambos tipos de club. Adjuntate al club existente disponible para completar tus 2 clubes.
                        </p>
                        <p v-else-if="clubLimitReached" class="text-sm text-amber-700">
                            Ya tienes el maximo de 2 clubes asignados.
                        </p>
                    </div>
                </div>
            </details>

            <details class="border rounded">
                <summary class="bg-gray-100 px-4 py-2 font-semibold cursor-pointer">Clases</summary>
                <div class="p-4">
                    <div class="flex justify-between items-center mb-4">
                        <h3 class="text-lg font-bold">Clases del club</h3>
                        <div class="flex items-center gap-2">
                            <button
                                type="button"
                                @click="exportClassesPdf(false)"
                                class="px-3 py-2 bg-gray-700 text-white rounded hover:bg-gray-800 text-sm"
                            >
                                PDF clases
                            </button>
                            <button
                                type="button"
                                @click="exportClassesPdf(true)"
                                class="px-3 py-2 bg-emerald-700 text-white rounded hover:bg-emerald-800 text-sm"
                            >
                                PDF clases + requisitos
                            </button>
                            <button @click="openNewClassModal"
                                class="px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700">
                                + Agregar clase
                            </button>
                        </div>
                    </div>
                    <table class="min-w-full border rounded text-left border-collapse">
                        <thead class="bg-gray-100">
                            <tr>
                                <th class="border-b px-4 py-2">Club</th>
                                <th class="border-b px-4 py-2">Orden</th>
                                <th class="border-b px-4 py-2">Nombre</th>
                                <th class="border-b px-4 py-2">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <template v-for="club in filteredClubs" :key="club.id">
                                <template
                                    v-for="cls in club.club_classes.slice().sort((a, b) => a.class_order - b.class_order)"
                                    :key="cls.id">
                                    <tr>
                                        <td class="px-4 py-2">{{ club.club_name }}</td>
                                        <td class="px-4 py-2">{{ cls.class_order }}</td>
                                        <td class="px-4 py-2">{{ cls.class_name }}</td>
                                        <td class="p-2 space-x-2">
                                            <button @click="editCls(cls)"
                                                class="text-blue-600 hover:underline">Editar</button>
                                            <button @click="deleteCls(cls.id)"
                                                class="text-red-600 hover:underline">Eliminar</button>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td colspan="4" class="px-4 py-3 bg-gray-50 border-b">
                                            <div class="flex items-center justify-between mb-2">
                                                <p class="text-sm font-semibold text-gray-800">
                                                    Requisitos de investidura<span v-if="club.club_type === 'adventurers'"> (Honores/Honors)</span>
                                                </p>
                                                <button
                                                    type="button"
                                                    class="text-sm text-blue-700 hover:underline"
                                                    @click="startCreateRequirement(cls.id)"
                                                >
                                                    + Agregar requisito
                                                </button>
                                            </div>

                                            <ul v-if="getClassRequirements(cls).length" class="space-y-2 mb-3">
                                                <li
                                                    v-for="requirement in getClassRequirements(cls)"
                                                    :key="requirement.id"
                                                    class="border rounded p-2 bg-white"
                                                >
                                                    <div class="flex items-start justify-between gap-3">
                                                        <div>
                                                            <p class="text-sm font-medium text-gray-900">
                                                                {{ requirement.sort_order }}. {{ requirement.title }}
                                                            </p>
                                                            <p v-if="requirement.description" class="text-xs text-gray-600 mt-1">
                                                                {{ requirement.description }}
                                                            </p>
                                                        </div>
                                                        <div class="flex items-center gap-2">
                                                            <button
                                                                type="button"
                                                                class="text-xs text-blue-700 hover:underline"
                                                                @click="startEditRequirement(cls.id, requirement)"
                                                            >
                                                                Editar
                                                            </button>
                                                            <button
                                                                type="button"
                                                                class="text-xs text-red-700 hover:underline"
                                                                @click="removeRequirement(requirement.id)"
                                                            >
                                                                Eliminar
                                                            </button>
                                                        </div>
                                                    </div>
                                                </li>
                                            </ul>
                                            <p v-else class="text-xs text-gray-500 mb-3">No hay requisitos registrados para esta clase.</p>

                                            <div v-if="showRequirementFormByClass[cls.id]" class="grid grid-cols-1 md:grid-cols-4 gap-2">
                                                <input
                                                    v-model="getRequirementDraft(cls.id).title"
                                                    type="text"
                                                    placeholder="Titulo del requisito"
                                                    class="border rounded px-2 py-1 text-sm md:col-span-2"
                                                />
                                                <input
                                                    v-model="getRequirementDraft(cls.id).description"
                                                    type="text"
                                                    placeholder="Descripcion (opcional)"
                                                    class="border rounded px-2 py-1 text-sm"
                                                />
                                                <input
                                                    v-model.number="getRequirementDraft(cls.id).sort_order"
                                                    type="number"
                                                    min="1"
                                                    placeholder="Orden"
                                                    class="border rounded px-2 py-1 text-sm"
                                                />
                                            </div>
                                            <div v-if="showRequirementFormByClass[cls.id]" class="mt-2 flex items-center gap-3">
                                                <button
                                                    type="button"
                                                    class="text-sm bg-green-600 hover:bg-green-700 text-white px-3 py-1 rounded"
                                                    @click="saveRequirement(cls)"
                                                >
                                                    {{ editingRequirementByClass[cls.id] ? 'Actualizar' : 'Guardar' }}
                                                </button>
                                                <button
                                                    type="button"
                                                    class="text-xs text-gray-600 hover:underline"
                                                    @click="cancelRequirementEdit(cls.id)"
                                                >
                                                    Limpiar
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                </template>
                            </template>
                        </tbody>
                    </table>
                </div>
            </details>

            <details class="border rounded">
                <summary class="bg-gray-100 px-4 py-2 font-semibold cursor-pointer">Objetivos</summary>
                <div class="p-4">
                    <div class="flex justify-between items-center mb-4">
                        <div>
                            <h3 class="text-lg font-bold">Objetivos del club</h3>
                            <p class="text-sm text-gray-600">Estos objetivos son locales y luego pueden usarse en el plan de trabajo aun si no se importaron desde mychurchadmin.</p>
                        </div>
                    </div>

                    <table class="min-w-full border rounded text-left border-collapse">
                        <thead class="bg-gray-100">
                            <tr>
                                <th class="border-b px-4 py-2">Club</th>
                                <th class="border-b px-4 py-2">Objetivos</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="club in filteredClubs" :key="`objectives-${club.id}`" class="border-b align-top">
                                <td class="px-4 py-3 font-medium text-gray-900">{{ club.club_name }}</td>
                                <td class="px-4 py-3">
                                    <div class="flex items-center justify-between mb-3">
                                        <div class="text-sm text-gray-600">
                                            {{ getClubObjectives(club).length }} objetivo(s) local(es)
                                        </div>
                                        <button
                                            type="button"
                                            class="text-sm text-blue-700 hover:underline"
                                            @click="startCreateObjective(club.id)"
                                        >
                                            + Agregar objetivo
                                        </button>
                                    </div>

                                    <ul v-if="getClubObjectives(club).length" class="space-y-2 mb-3">
                                        <li
                                            v-for="objective in getClubObjectives(club)"
                                            :key="objective.id"
                                            class="border rounded p-3 bg-white"
                                        >
                                            <div class="flex items-start justify-between gap-4">
                                                <div>
                                                    <div class="flex items-center gap-2">
                                                        <p class="text-sm font-medium text-gray-900">{{ objective.name }}</p>
                                                        <span
                                                            v-if="objective.external_objective_id"
                                                            class="inline-flex items-center rounded-full bg-emerald-100 px-2 py-0.5 text-[11px] font-medium text-emerald-800"
                                                        >
                                                            Vinculado a MCA #{{ objective.external_objective_id }}
                                                        </span>
                                                        <span
                                                            v-else
                                                            class="inline-flex items-center rounded-full bg-blue-100 px-2 py-0.5 text-[11px] font-medium text-blue-800"
                                                        >
                                                            Local
                                                        </span>
                                                    </div>
                                                    <p v-if="objective.description" class="text-xs text-gray-600 mt-1">
                                                        {{ objective.description }}
                                                    </p>
                                                    <p v-if="objective.annual_evaluation_metric" class="text-xs text-gray-600 mt-1">
                                                        <span class="font-medium">Metrica anual:</span> {{ objective.annual_evaluation_metric }}
                                                    </p>
                                                </div>
                                                <div class="flex items-center gap-2">
                                                    <button
                                                        type="button"
                                                        class="text-xs text-blue-700 hover:underline"
                                                        @click="startEditObjective(club.id, objective)"
                                                    >
                                                        Editar
                                                    </button>
                                                    <button
                                                        type="button"
                                                        class="text-xs text-red-700 hover:underline"
                                                        @click="removeObjective(club.id, objective.id)"
                                                    >
                                                        Eliminar
                                                    </button>
                                                </div>
                                            </div>
                                        </li>
                                    </ul>
                                    <p v-else class="text-xs text-gray-500 mb-3">No hay objetivos locales registrados para este club.</p>

                                    <div v-if="showObjectiveFormByClub[club.id]" class="space-y-3 border rounded bg-gray-50 p-3">
                                        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                                            <div>
                                                <label class="block text-xs font-medium text-gray-600 mb-1">Iglesia</label>
                                                <input
                                                    :value="club.church_name || '—'"
                                                    type="text"
                                                    readonly
                                                    class="w-full border rounded px-2 py-2 text-sm bg-gray-100 text-gray-600"
                                                />
                                            </div>
                                            <div>
                                                <label class="block text-xs font-medium text-gray-600 mb-1">Dpto</label>
                                                <input
                                                    :value="club.club_type || '—'"
                                                    type="text"
                                                    readonly
                                                    class="w-full border rounded px-2 py-2 text-sm bg-gray-100 text-gray-600"
                                                />
                                            </div>
                                            <div>
                                                <label class="block text-xs font-medium text-gray-600 mb-1">Nombre</label>
                                                <input
                                                    v-model="getObjectiveDraft(club.id).name"
                                                    type="text"
                                                    placeholder="Nombre del objetivo"
                                                    class="w-full border rounded px-2 py-2 text-sm"
                                                />
                                            </div>
                                            <div>
                                                <label class="block text-xs font-medium text-gray-600 mb-1">Metrica de evaluacion anual</label>
                                                <input
                                                    v-model="getObjectiveDraft(club.id).annual_evaluation_metric"
                                                    type="text"
                                                    placeholder="Metrica anual"
                                                    class="w-full border rounded px-2 py-2 text-sm"
                                                />
                                            </div>
                                        </div>
                                        <div>
                                            <label class="block text-xs font-medium text-gray-600 mb-1">Descripcion</label>
                                            <textarea
                                                v-model="getObjectiveDraft(club.id).description"
                                                rows="3"
                                                placeholder="Descripcion"
                                                class="w-full border rounded px-2 py-2 text-sm"
                                            />
                                        </div>
                                        <div class="flex items-center gap-3">
                                            <button
                                                type="button"
                                                class="text-sm bg-green-600 hover:bg-green-700 text-white px-3 py-1 rounded"
                                                @click="saveObjective(club)"
                                            >
                                                {{ editingObjectiveByClub[club.id] ? 'Actualizar' : 'Guardar' }}
                                            </button>
                                            <button
                                                type="button"
                                                class="text-xs text-gray-600 hover:underline"
                                                @click="cancelObjectiveEdit(club.id)"
                                            >
                                                Limpiar
                                            </button>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </details>
        <CreateClassModal v-if="showClassModal" v-model:visible="showClassModal" :clubs="clubs"
                :staff="clubStaff" :user="user" :classToEdit="classToEdit" @created="refreshPage" />
        </div>
    </PathfinderLayout>
</template>
