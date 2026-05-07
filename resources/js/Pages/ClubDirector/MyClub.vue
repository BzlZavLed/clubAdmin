<script setup>
import { useForm, router } from '@inertiajs/vue3'
import CreateClassModal from '@/Components/CreateClassModal.vue'
import PathfinderLayout from '@/Layouts/PathfinderLayout.vue'
import { useAuth } from '@/Composables/useAuth'
import { useGeneral } from '@/Composables/useGeneral'
import { useLocale } from '@/Composables/useLocale'
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
    activateCarpetaClassForClub,
    createOrUpdateClass,
    deactivateCarpetaClassForClub,
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
    districts: {
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
const { tr } = useLocale()
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
const church_name = computed(() => user.value.church_name || tr('Iglesia desconocida', 'Unknown church'))
const clubId = ref(
    isSuperadmin.value
        ? (props.superadmin_context?.club_id || null)
        : (user.value.club_id || null)
)

const clubStaff = computed(() => {
    return clubs.value[0]?.staff_adventurers ?? []
})
if (!isSuperadmin.value && !user.value.pastor_name) {
    showToast(tr('Primero crea la iglesia', 'Create the church first'), 'error')
}

const initialChurch = isSuperadmin.value
    ? (props.churches.find(ch => Number(ch.id) === Number(user.value.church_id)) || props.churches[0] || null)
    : null
const initialDistrict = computed(() => {
    const sourceChurch = isSuperadmin.value
        ? props.churches.find(ch => Number(ch.id) === Number(initialChurch?.id))
        : props.churches.find(ch => Number(ch.id) === Number(user.value.church_id))

    return props.districts.find(d => Number(d.id) === Number(sourceChurch?.district_id)) || null
})

const getChurchById = (churchId) => (
    props.churches.find(ch => Number(ch.id) === Number(churchId)) || null
)

const getDistrictById = (districtId) => (
    props.districts.find(d => Number(d.id) === Number(districtId)) || null
)

const syncChurchFields = (church) => {
    clubForm.church_id = church?.id || ''
    clubForm.church_name = church?.church_name || ''
    clubForm.pastor_name = church?.pastor_name || ''
    churchSearch.value = church?.church_name || ''
}

const syncDistrictFields = (district) => {
    clubForm.district_id = district?.id || ''
    clubForm.district_name = district?.name || ''
    clubForm.conference_name = district?.association_name || ''
    clubForm.union_name = district?.union_name || ''
    clubForm.evaluation_system = district?.evaluation_system || 'honors'
}

// 🧠 Club form
const clubForm = useForm({
    church_id: isSuperadmin.value ? (initialChurch?.id || '') : user.value.church_id,
    club_name: '',
    church_name: isSuperadmin.value ? (initialChurch?.church_name || '') : user.value.church_name,
    director_name: user.value.name,
    creation_date: today,
    pastor_name: isSuperadmin.value
        ? (initialChurch?.pastor_name || '')
        : (user.value.pastor_name || tr('Iglesia no creada', 'Church not created')),
    conference_name: initialDistrict.value?.association_name || (isSuperadmin.value
        ? (initialChurch?.conference || '')
        : (user.value.conference_name || tr('Iglesia no creada', 'Church not created'))),
    conference_region: '',
    club_type: '',
    evaluation_system: 'honors',
    district_id: initialDistrict.value?.id || '',
    district_name: initialDistrict.value?.name || '',
    union_name: initialDistrict.value?.union_name || '',
    enrollment_payment_amount: '',
})
const churchSearch = ref(initialChurch?.church_name || user.value.church_name || '')
const showChurchSuggestions = ref(false)
const filteredChurches = computed(() => {
    const query = String(churchSearch.value || '').trim().toLowerCase()
    if (!query) return props.churches.slice(0, 8)
    return props.churches.filter((church) => {
        const haystack = [
            church.church_name,
            church.pastor_name,
        ].filter(Boolean).join(' ').toLowerCase()
        return haystack.includes(query)
    }).slice(0, 8)
})
const availableEvaluationSystems = computed(() => {
    const value = clubForm.evaluation_system || 'honors'

    return [
        {
            value,
            label: value === 'carpetas' ? tr('Carpetas', 'Folders') : tr('Honores', 'Honors'),
        },
    ]
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

watch(() => clubForm.district_id, (districtId) => {
    syncDistrictFields(getDistrictById(districtId))
})

watch(() => clubForm.church_id, (churchId) => {
    const selectedChurch = getChurchById(churchId)
    syncChurchFields(selectedChurch)
    syncDistrictFields(getDistrictById(selectedChurch?.district_id))
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
        showToast(tr('Clubes cargados correctamente', 'Clubs loaded successfully'))
    } catch (error) {
        console.error('Failed to fetch clubs:', error)
        showToast(tr('Error al cargar clubes', 'Could not load clubs'), 'error')
    }
}

// 🧠 Submit & update club
const submitClub = async () => {
    try {
        await createClub(clubForm)
        showToast(tr('Club creado correctamente', 'Club created successfully'))
        addClub.value = false
        await fetchClubs()
        await router.reload({ only: ['auth'] })
    } catch (error) {
        console.error(error)
        showToast(error?.response?.data?.message || tr('No se pudo crear el club', 'Could not create the club'), 'error')
    }
}

const updateClub = async () => {
    try {
        await updateClubApi(clubForm)
        showToast(tr('Club actualizado correctamente', 'Club updated successfully'))
        isEditing.value = false
        editingClubId.value = null
        fetchClubs()
    } catch (error) {
        console.error(error)
        showToast(tr('No se pudo actualizar el club', 'Could not update the club'), 'error')
    }
}

// 🧠 Editing form
const editClub = (club) => {
    isEditing.value = true
    editingClubId.value = club.id
    clubForm.reset()
    Object.assign(clubForm, { ...club })
    syncChurchFields(getChurchById(club.church_id) || {
        id: club.church_id,
        church_name: club.church_name || '',
        pastor_name: club.pastor_name || '',
    })
    syncDistrictFields(getDistrictById(club.district_id) || {
        id: club.district_id,
        name: club.district_name || '',
        association_name: club.conference_name || '',
        union_name: club.union_name || '',
    })
}

// 🧠 Delete club or class
const deleteClub = async (clubId) => {
    if (!confirm(tr('¿Seguro que deseas eliminar este club?', 'Are you sure you want to delete this club?'))) return
    try {
        await deleteClubById(clubId)
        showToast(tr('Club eliminado correctamente', 'Club deleted successfully'))
        fetchClubs()
    } catch (error) {
        console.error('Failed to delete club:', error)
        showToast(tr('Error al eliminar el club', 'Could not delete the club'), 'error')
    }
}

const deleteCls = async (classID) => {
    if (!confirm(tr('¿Seguro que deseas eliminar esta clase?', 'Are you sure you want to delete this class?'))) return
    try {
        await deleteClassById(classID)
        showToast(tr('Clase eliminada correctamente', 'Class deleted successfully'))
        fetchClubs()
    } catch (error) {
        console.error('Failed to delete class:', error)
        showToast(tr('Error al eliminar la clase', 'Could not delete the class'), 'error')
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

const getCarpetaRequirements = (cls) => {
    if (!Array.isArray(cls?.carpeta_requirements)) return []
    return cls.carpeta_requirements
        .slice()
        .sort((a, b) => {
            const oa = Number(a.sort_order || 0)
            const ob = Number(b.sort_order || 0)
            if (oa !== ob) return oa - ob
            return Number(a.id || 0) - Number(b.id || 0)
        })
}

const isCarpetaClub = (club) => (club?.evaluation_system || 'honors') === 'carpetas'

const getClubClasses = (club) => (
    (club?.club_classes ?? []).slice().sort((a, b) => a.class_order - b.class_order)
)

const getCarpetaClassRows = (club) => {
    return (club?.union_class_catalogs ?? []).slice().sort((a, b) => {
        const oa = Number(a.sort_order || 0)
        const ob = Number(b.sort_order || 0)
        if (oa !== ob) return oa - ob
        return String(a.name || '').localeCompare(String(b.name || ''))
    })
}

const requirementTypeLabel = (value) => {
    const labels = {
        speciality: tr('Especialidad', 'Specialty'),
        event: tr('Evento', 'Event'),
        class: tr('Clase', 'Class'),
        presentation: tr('Presentacion', 'Presentation'),
        other: tr('Otro', 'Other'),
    }

    return labels[value] || value || tr('Otro', 'Other')
}

const validationModeLabel = (value) => {
    const labels = {
        electronic: tr('Validacion electronica', 'Electronic validation'),
        physical: tr('Evidencia fisica', 'Physical evidence'),
        hybrid: tr('Mixto', 'Hybrid'),
    }

    return labels[value] || value || tr('Sin definir', 'Undefined')
}

const evidenceTypeLabel = (value) => {
    const labels = {
        photo: tr('Foto', 'Photo'),
        file: tr('Archivo', 'File'),
        text: tr('Texto', 'Text'),
        video_link: tr('Video', 'Video'),
        external_link: tr('Enlace', 'Link'),
        physical_only: tr('Fisico', 'Physical'),
    }

    return labels[value] || value
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
        showToast(tr('El requisito necesita un titulo', 'The requirement needs a title'), 'error')
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
            showToast(tr('Requisito actualizado', 'Requirement updated'))
        } else {
            await createInvestitureRequirement(classId, payload)
            showToast(tr('Requisito creado', 'Requirement created'))
        }
        cancelRequirementEdit(classId)
        await fetchClubs()
    } catch (error) {
        console.error('Failed to save requirement:', error)
        showToast(tr('No se pudo guardar el requisito', 'Could not save the requirement'), 'error')
    }
}

const removeRequirement = async (requirementId) => {
    if (!confirm(tr('¿Seguro que deseas eliminar este requisito?', 'Are you sure you want to delete this requirement?'))) return
    try {
        await deleteInvestitureRequirement(requirementId)
        showToast(tr('Requisito eliminado', 'Requirement deleted'))
        await fetchClubs()
    } catch (error) {
        console.error('Failed to delete requirement:', error)
        showToast(tr('No se pudo eliminar el requisito', 'Could not delete the requirement'), 'error')
    }
}

const activateCarpetaClass = async (club, catalogClass) => {
    try {
        await activateCarpetaClassForClub(club.id, catalogClass.id)
        showToast(tr('Clase activada correctamente', 'Class activated successfully'))
        await fetchClubs()
    } catch (error) {
        console.error('Failed to activate carpeta class:', error)
        showToast(error?.response?.data?.message || tr('No se pudo activar la clase', 'Could not activate the class'), 'error')
    }
}

const deactivateCarpetaClass = async (activationId) => {
    if (!confirm(tr('¿Seguro que deseas desactivar esta clase del club?', 'Are you sure you want to deactivate this club class?'))) return
    try {
        await deactivateCarpetaClassForClub(activationId)
        showToast(tr('Clase desactivada correctamente', 'Class deactivated successfully'))
        await fetchClubs()
    } catch (error) {
        console.error('Failed to deactivate carpeta class:', error)
        showToast(error?.response?.data?.message || tr('No se pudo desactivar la clase', 'Could not deactivate the class'), 'error')
    }
}

// 🧠 Select club (director choosing one)
const selectClub = async (nextClubId) => {
    try {
        await selectUserClub(nextClubId, user.value.id)
        showToast(tr('Club seleccionado correctamente', 'Club selected successfully'))
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

const getStaffName = (cls, isCarpeta = false) => {
    if (!cls) return '—'
    if (isCarpeta) {
        return cls.activation?.assigned_staff_name || '—'
    }
    if (cls.assigned_staff_name) return cls.assigned_staff_name
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
        showToast(tr('El objetivo necesita un nombre', 'The objective needs a name'), 'error')
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
            showToast(tr('Objetivo actualizado', 'Objective updated'))
        } else {
            await createClubObjective(clubId, payload)
            showToast(tr('Objetivo creado', 'Objective created'))
        }

        cancelObjectiveEdit(clubId)
        await fetchClubs()
    } catch (error) {
        console.error('Failed to save objective:', error)
        showToast(error?.response?.data?.message || tr('No se pudo guardar el objetivo', 'Could not save the objective'), 'error')
    }
}

const removeObjective = async (clubId, objectiveId) => {
    if (!confirm(tr('¿Seguro que deseas eliminar este objetivo?', 'Are you sure you want to delete this objective?'))) return

    try {
        await deleteClubObjective(clubId, objectiveId)
        showToast(tr('Objetivo eliminado', 'Objective deleted'))
        await fetchClubs()
    } catch (error) {
        console.error('Failed to delete objective:', error)
        showToast(error?.response?.data?.message || tr('No se pudo eliminar el objetivo', 'Could not delete the objective'), 'error')
    }
}

// 🧠 Start new form
const startCreatingClub = () => {
    if (!canCreateAnotherClub.value) {
        showToast(tr('Ya tienes el maximo de 2 clubes asignados.', 'You already have the maximum of 2 assigned clubs.'), 'error')
        return
    }
    if (mustAttachInsteadOfCreate.value) {
        showToast(tr('Tu iglesia ya tiene ambos tipos de club. Debes adjuntarte al club existente disponible.', 'Your church already has both club types. You must attach yourself to the available existing club.'), 'error')
        return
    }
    addClub.value = true
    clubForm.reset()
    const selected = props.churches.find(ch => Number(ch.id) === Number(clubForm.church_id))
    const selectedDistrict = getDistrictById(selected?.district_id || initialDistrict.value?.id)
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
        conference_name: selectedDistrict?.association_name || '',
        conference_region: '',
        club_type: '',
        evaluation_system: initialDistrict.value?.evaluation_system || 'honors',
        district_id: selectedDistrict?.id || '',
        district_name: selectedDistrict?.name || '',
        union_name: selectedDistrict?.union_name || '',
        enrollment_payment_amount: '',
    })
    syncChurchFields(selected)
    syncDistrictFields(selectedDistrict)
    if (!isSuperadmin.value && missingChurchClubTypes.value.length === 1) {
        clubForm.club_type = missingChurchClubTypes.value[0]
    }
}

const handleChurchInput = () => {
    showChurchSuggestions.value = true
    const selected = getChurchById(clubForm.church_id)
    if (!selected) {
        clubForm.church_id = ''
        clubForm.church_name = ''
        clubForm.pastor_name = ''
        syncDistrictFields(null)
        return
    }

    if (selected.church_name !== churchSearch.value) {
        clubForm.church_id = ''
        clubForm.church_name = ''
        clubForm.pastor_name = ''
        syncDistrictFields(null)
        return
    }
}

const selectChurch = (church) => {
    syncChurchFields(church)
    syncDistrictFields(getDistrictById(church?.district_id))
    showChurchSuggestions.value = false
}

const attachToExistingClub = async (club) => {
    try {
        await attachDirectorToClub(club.id, user.value.id)
        showToast(tr('Ahora estas vinculado a este club como director', 'You are now linked to this club as director'))
        await fetchClubs()
        await router.reload({ only: ['auth'] })
    } catch (error) {
        console.error('Failed to attach to existing club:', error)
        showToast(error?.response?.data?.message || tr('No se pudo adjuntar al club', 'Could not attach to the club'), 'error')
    }
}

const unlinkFromClub = async (club) => {
    const confirmed = window.confirm(tr(`¿Seguro que deseas desvincularte del club ${club.club_name}?`, `Are you sure you want to unlink yourself from club ${club.club_name}?`))
    if (!confirmed) return

    try {
        await detachDirectorFromClub(club.id, user.value.id)
        showToast(tr('Te desvinculaste del club correctamente', 'You were unlinked from the club successfully'))
        await fetchClubs()
        await router.reload({ only: ['auth'] })
    } catch (error) {
        console.error('Failed to detach from club:', error)
        showToast(error?.response?.data?.message || tr('No se pudo desvincular del club', 'Could not unlink from the club'), 'error')
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
    { value: 'club_wide', label: tr('Todo el club', 'Whole club') },
    { value: 'class', label: tr('Clase especifica', 'Specific class') },
    { value: 'member', label: tr('Miembro especifico', 'Specific member') },
    { value: 'staff_wide', label: tr('Todo el personal', 'All staff') },
    { value: 'staff', label: tr('Personal especifico', 'Specific staff') }
]

const payToOptions = [
    { value: 'church_budget', label: tr('Presupuesto de iglesia', 'Church budget') },
    { value: 'club_budget', label: tr('Presupuesto de club', 'Club budget') },
    { value: 'conference', label: tr('Conferencia', 'Conference') },
    { value: 'reimbursement_to', label: tr('Reembolso a...', 'Reimbursement to...') }
]

const typeOptions = [
    { value: 'mandatory', label: tr('Obligatorio', 'Required') },
    { value: 'optional', label: tr('Opcional', 'Optional') }
]

const statusOptions = [
    { value: 'active', label: tr('Activo', 'Active') },
    { value: 'inactive', label: tr('Inactivo', 'Inactive') }
]

// derive current club name (for sanity)
const conceptClubName = computed(() => {
    const club = clubs.value.find(c => c.id === conceptClubId.value)
    return club?.club_name ?? ''
})

// scope builder actions
function addScope() {
    if (!conceptClubId.value) {
        showToast(tr('Selecciona un club para este concepto primero', 'Select a club for this concept first'), 'error')
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
        showToast(tr('Selecciona el club del concepto', 'Select the concept club'), 'error')
        return
    }
    if (pcForm.scopes.length === 0) {
        showToast(tr('Agrega al menos un alcance', 'Add at least one scope'), 'error')
        return
    }

    // If pay_to != reimbursement_to, clear payee*
    if (pcForm.pay_to !== 'reimbursement_to') {
        pcForm.payee_type = null
        pcForm.payee_id = null
    }

    try {
        // await createPaymentConcept(pcForm) // when backend ready
        showToast(tr('Concepto de pago guardado (stub)', 'Payment concept saved (stub)'), 'success')
        pcForm.reset()
        pcForm.type = 'mandatory'
        pcForm.pay_to = 'club_budget'
        pcForm.status = 'active'
        pcForm.club_id = conceptClubId.value || null
        pcForm.scopes = []
        await loadPaymentConcepts()
    } catch (e) {
        showToast(tr('No se pudo guardar el concepto', 'Could not save the concept'), 'error')
    }
}

async function deleteConcept(id) {
    try {
        // await deletePaymentConcept(id)
        showToast(tr('Concepto eliminado (stub)', 'Concept deleted (stub)'), 'success')
        await loadPaymentConcepts()
    } catch (e) {
        showToast(tr('No se pudo eliminar el concepto', 'Could not delete the concept'), 'error')
    }
}

const staffList = ref([])

const fetchStaff = async (clubId) => {
    try {
        const response = await axios.get(`/clubs/${clubId}/staff`)
        staffList.value = response.data.staff
        if(staffList.value.length === 0) {
            showToast(tr('Crea personal primero, no se encontro ninguno', 'Create staff first; none were found'),'error')
            return
        }
        showToast(tr('Personal cargado', 'Staff loaded'),'success');
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
            showToast(tr('Miembros cargados', 'Members loaded'), 'success')
        } else {
            members.value = []
            showToast(tr('No se encontraron miembros para este club.', 'No members were found for this club.'), 'info')
        }
    } catch (error) {
        console.error('Failed to fetch members:', error)
        showToast(tr('Error al obtener miembros', 'Could not load members'), 'error')
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
        <template #title>{{ tr('Mi club', 'My Club') }}</template>

        <div v-if="isSuperadmin" class="mb-4 rounded border bg-white p-4 space-y-3">
            <p class="text-sm font-semibold text-gray-800">{{ tr('Contexto Superadmin', 'Superadmin Context') }}</p>
            <div class="flex flex-col md:flex-row gap-2 md:items-center md:justify-between">
                <div class="text-sm text-gray-600">
                    {{ tr('Club activo', 'Active club') }}:
                    <span class="font-medium text-gray-900">
                        {{ filteredClubs[0]?.club_name || tr('Selecciona un club desde el selector global', 'Select a club from the global selector') }}
                    </span>
                </div>
                <button
                    v-if="canCreateAnotherClub"
                    type="button"
                    class="px-3 py-2 rounded bg-blue-600 text-white text-sm"
                    @click="startCreatingClub"
                >
                    {{ tr('Crear nuevo club', 'Create new club') }}
                </button>
            </div>
            <p v-if="clubLimitReached" class="text-xs text-amber-700">{{ tr('Este director ya tiene el maximo de 2 clubes asignados.', 'This director already has the maximum of 2 assigned clubs.') }}</p>
        </div>

        <div v-else class="mb-4 rounded border bg-white p-4 space-y-3">
            <p class="text-sm font-semibold text-gray-800">{{ tr('Gestion de clubes', 'Club Management') }}</p>
            <p v-if="mustAttachInsteadOfCreate" class="text-sm text-amber-700">
                {{ tr('Esta iglesia ya tiene clubes de Aventureros y Conquistadores. En lugar de crear otro club, puedes adjuntarte al club existente que aun no diriges.', 'This church already has Adventurer and Pathfinder clubs. Instead of creating another club, you can attach yourself to an existing club you do not lead yet.') }}
            </p>
            <p v-else-if="missingChurchClubTypes.length && canCreateAnotherClub" class="text-sm text-gray-600">
                {{ tr('Puedes crear un club nuevo para el tipo faltante', 'You can create a new club for the missing type') }}:
                <strong>{{ missingChurchClubTypes.join(', ') }}</strong>.
            </p>
            <p v-else-if="clubLimitReached" class="text-sm text-amber-700">
                {{ tr('Ya alcanzaste el maximo de 2 clubes asignados.', 'You already reached the maximum of 2 assigned clubs.') }}
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
                        {{ tr('Adjuntarme como director', 'Attach me as director') }}
                    </button>
                </div>
            </div>
        </div>

        <div v-if="isEditing || addClub || (clubs.length === 0 && !clubId)" class="space-y-6">
            <p class="text-gray-700">
                {{ isEditing ? tr('Edita tu club a continuacion:', 'Edit your club below:') : tr('Crea tu club a continuacion.', 'Create your club below.') }}
            </p>

            <form class="space-y-4" @submit.prevent="isEditing ? updateClub() : submitClub()">
                <div v-for="field in [
                    { key: 'club_name', label: tr('Nombre del club', 'Club name') },
                    { key: 'creation_date', label: tr('Fecha de creacion', 'Creation date'), type: 'date' }
                ]" :key="field.key">
                    <label class="block text-sm font-medium text-gray-700">{{ field.label }}</label>
                    <input v-model="clubForm[field.key]" :type="field.type || 'text'" :readonly="field.readonly"
                        class="w-full mt-1 p-2 border rounded" />
                </div>

                <div class="relative">
                    <label class="block text-sm font-medium text-gray-700">{{ tr('Iglesia', 'Church') }}</label>
                    <input
                        v-model="churchSearch"
                        type="text"
                        class="w-full mt-1 p-2 border rounded"
                        :placeholder="tr('Busca una iglesia', 'Search for a church')"
                        @focus="showChurchSuggestions = true"
                        @input="handleChurchInput"
                        @blur="() => setTimeout(() => { showChurchSuggestions = false }, 150)"
                    />
                    <div
                        v-if="showChurchSuggestions && filteredChurches.length"
                        class="absolute z-10 mt-1 max-h-56 w-full overflow-auto rounded-md border border-gray-200 bg-white shadow-lg"
                    >
                        <button
                            v-for="church in filteredChurches"
                            :key="church.id"
                            type="button"
                            class="block w-full px-3 py-2 text-left text-sm hover:bg-gray-50"
                            @click="selectChurch(church)"
                        >
                            <div class="font-medium text-gray-900">{{ church.church_name }}</div>
                            <div class="text-xs text-gray-500">{{ tr('Pastor', 'Pastor') }}: {{ church.pastor_name || '—' }}</div>
                        </button>
                    </div>
                    <p class="mt-1 text-xs text-gray-500">
                        {{ tr('Selecciona la iglesia para completar automaticamente el nombre del pastor.', 'Select the church to automatically fill the pastor name.') }}
                    </p>
                </div>

                <div v-for="field in [
                    { key: 'pastor_name', label: tr('Nombre del pastor', 'Pastor name'), readonly: true }
                ]" :key="field.key">
                    <label class="block text-sm font-medium text-gray-700">{{ field.label }}</label>
                    <input v-model="clubForm[field.key]" :type="field.type || 'text'" :readonly="field.readonly"
                        class="w-full mt-1 p-2 border rounded" />
                </div>

                <div v-for="field in [
                    { key: 'district_name', label: tr('Distrito', 'District'), readonly: true },
                    { key: 'conference_name', label: tr('Asociacion / Conferencia', 'Association / Conference'), readonly: true },
                    { key: 'union_name', label: tr('Union', 'Union'), readonly: true },
                    { key: 'director_name', label: tr('Nombre del director', 'Director name'), readonly: true }
                ]" :key="field.key">
                    <label class="block text-sm font-medium text-gray-700">{{ field.label }}</label>
                    <input v-model="clubForm[field.key]" :type="field.type || 'text'" :readonly="field.readonly"
                        class="w-full mt-1 p-2 border rounded" />
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700">{{ tr('Tipo de club', 'Club type') }}</label>
                    <select v-model="clubForm.club_type" class="w-full mt-1 p-2 border rounded">
                        <option value="">{{ tr('Seleccionar tipo', 'Select type') }}</option>
                        <option value="adventurers">{{ tr('Aventureros', 'Adventurers') }}</option>
                        <option value="pathfinders">{{ tr('Conquistadores', 'Pathfinders') }}</option>
                        <option value="master_guide">{{ tr('Guia Mayor', 'Master Guide') }}</option>
                    </select>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700">{{ tr('Sistema de evaluacion', 'Evaluation system') }}</label>
                    <select v-model="clubForm.evaluation_system" class="w-full mt-1 p-2 border rounded bg-gray-50" disabled>
                        <option
                            v-for="option in availableEvaluationSystems"
                            :key="option.value"
                            :value="option.value"
                        >
                            {{ option.label }}
                        </option>
                    </select>
                    <p class="mt-1 text-xs text-gray-500">
                        {{ tr('Este valor se toma de la configuracion de la union asociada a la iglesia seleccionada.', 'This value comes from the union configuration linked to the selected church.') }}
                    </p>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700">{{ tr('Costo de inscripción', 'Enrollment cost') }}</label>
                    <input
                        v-model="clubForm.enrollment_payment_amount"
                        type="number"
                        min="0"
                        step="0.01"
                        class="w-full mt-1 p-2 border rounded"
                        placeholder="0.00"
                    />
                    <p class="mt-1 text-xs text-gray-500">
                        {{ tr('Este monto se usa en el formulario de nuevos miembros y actualiza automaticamente el concepto de ingreso de inscripción.', 'This amount is used in the new member form and automatically updates the enrollment income concept.') }}
                    </p>
                </div>

                <div class="flex items-center space-x-4">
                    <button type="submit" class="bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded">
                        {{ isEditing ? tr('Actualizar club', 'Update club') : tr('Guardar club', 'Save club') }}
                    </button>
                    <button v-if="isEditing || addClub" type="button" @click="() => {
                        isEditing = false;
                        addClub = false;
                        editingClubId = null
                    }" class="text-sm text-gray-600 hover:underline">
                        {{ tr('Cancelar edicion', 'Cancel edit') }}
                    </button>
                </div>
            </form>
        </div>
        <div v-else-if="!clubId && clubs.length > 0" class="space-y-6">
            <p class="text-gray-700">{{ tr('Selecciona un club existente de tu iglesia', 'Select an existing club from your church') }}: {{ church_name || tr('Iglesia desconocida', 'Unknown church') }}</p>
            <table class="min-w-full border rounded text-sm">
                <thead class="bg-gray-100">
                    <tr>
                        <th class="p-2 text-left">{{ tr('Nombre', 'Name') }}</th>
                        <th class="p-2 text-left">{{ tr('Tipo', 'Type') }}</th>
                        <th class="p-2 text-left">{{ tr('Acciones', 'Actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="club in clubs" :key="club.id" class="border-t">
                        <td class="p-2">{{ club.club_name }}</td>
                        <td class="p-2 capitalize">{{ club.club_type }}</td>
                        <td class="p-2 space-x-2">
                            <button @click="selectClub(club.id)" class="text-blue-600 hover:underline">{{ tr('Seleccionar', 'Select') }}</button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <div v-else class="space-y-4">
            <details open class="border rounded">
                <summary class="bg-gray-100 px-4 py-2 font-semibold cursor-pointer">{{ tr('Informacion del club', 'Club Information') }}</summary>
                <div class="p-4">
                    <table class="min-w-full border rounded text-sm">
                        <thead class="bg-gray-100">
                            <tr>
                                <th class="p-2 text-left">{{ tr('Nombre', 'Name') }}</th>
                                <th class="p-2 text-left">{{ tr('Distrito', 'District') }}</th>
                                <th class="p-2 text-left">{{ tr('Tipo', 'Type') }}</th>
                                <th class="p-2 text-left">{{ tr('Sistema', 'System') }}</th>
                                <th class="p-2 text-left">{{ tr('Inscripción', 'Enrollment') }}</th>
                                <th class="p-2 text-left">{{ tr('Creado', 'Created') }}</th>
                                <th class="p-2 text-left">{{ tr('Acciones', 'Actions') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="club in clubs" :key="club.id" class="border-t">
                                <td class="p-2">{{ club.club_name }}</td>
                                <td class="p-2">{{ club.district_name || '—' }}</td>
                                <td class="p-2 capitalize">{{ club.club_type }}</td>
                                <td class="p-2 capitalize">{{ club.evaluation_system || 'honors' }}</td>
                                <td class="p-2">{{ club.enrollment_payment_amount || '0.00' }}</td>
                                <td class="p-2">{{ club.creation_date }}</td>
                                <td class="p-2 space-x-2">
                                    <button @click="editClub(club)" class="text-blue-600 hover:underline">{{ tr('Editar', 'Edit') }}</button>
                                    <button
                                        v-if="!isSuperadmin"
                                        @click="unlinkFromClub(club)"
                                        class="text-amber-600 hover:underline"
                                    >
                                        {{ tr('Desvincularme', 'Unlink me') }}
                                    </button>
                                    <button @click="deleteClub(club.id)"
                                        class="text-red-600 hover:underline">{{ tr('Eliminar', 'Delete') }}</button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <div class="mt-4">
                        <button
                            v-if="canCreateAnotherClub && !mustAttachInsteadOfCreate"
                            @click="startCreatingClub"
                            class="px-4 py-2 bg-green-600 text-white rounded hover:bg-green-700">
                            + {{ tr('Crear club', 'Create club') }}
                        </button>
                        <p v-else-if="mustAttachInsteadOfCreate" class="text-sm text-amber-700">
                            {{ tr('Tu iglesia ya tiene ambos tipos de club. Adjuntate al club existente disponible para completar tus 2 clubes.', 'Your church already has both club types. Attach yourself to the available existing club to complete your 2 clubs.') }}
                        </p>
                        <p v-else-if="clubLimitReached" class="text-sm text-amber-700">
                            {{ tr('Ya tienes el maximo de 2 clubes asignados.', 'You already have the maximum of 2 assigned clubs.') }}
                        </p>
                    </div>
                </div>
            </details>

            <details class="border rounded">
                <summary class="bg-gray-100 px-4 py-2 font-semibold cursor-pointer">{{ tr('Clases', 'Classes') }}</summary>
                <div class="p-4">
                    <div class="flex justify-between items-center mb-4">
                        <h3 class="text-lg font-bold">{{ tr('Clases del club', 'Club Classes') }}</h3>
                        <div class="flex items-center gap-2">
                            <button
                                type="button"
                                @click="exportClassesPdf(false)"
                                class="px-3 py-2 bg-gray-700 text-white rounded hover:bg-gray-800 text-sm"
                            >
                                {{ tr('PDF clases', 'Classes PDF') }}
                            </button>
                            <button
                                type="button"
                                @click="exportClassesPdf(true)"
                                class="px-3 py-2 bg-emerald-700 text-white rounded hover:bg-emerald-800 text-sm"
                            >
                                {{ tr('PDF clases + requisitos', 'Classes + requirements PDF') }}
                            </button>
                            <button
                                v-if="!filteredClubs.some(isCarpetaClub)"
                                @click="openNewClassModal"
                                class="px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700">
                                + {{ tr('Agregar clase', 'Add class') }}
                            </button>
                        </div>
                    </div>
                    <div
                        v-for="club in filteredClubs.filter(isCarpetaClub)"
                        :key="`carpeta-banner-${club.id}`"
                        class="rounded-lg border border-amber-200 bg-amber-50 p-3 mb-4"
                    >
                        <div class="flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
                            <div>
                                <p class="text-sm font-semibold text-amber-900">{{ tr('Carpeta de investidura definida por la union', 'Investiture folder defined by the union') }}</p>
                                <p class="text-xs text-amber-800 mt-1">
                                    {{ tr('Esta clase debe cumplir exactamente la lista publicada por la union. Aqui no se editan requisitos locales.', 'This class must follow exactly the list published by the union. Local requirements are not edited here.') }}
                                </p>
                            </div>
                            <div class="text-xs text-amber-900">
                                {{ tr('Ciclo', 'Cycle') }}:
                                <span class="font-semibold">{{ club.published_carpeta_year?.year || tr('Sin publicar', 'Unpublished') }}</span>
                            </div>
                        </div>
                    </div>
                    <table class="min-w-full border rounded text-left border-collapse">
                        <thead class="bg-gray-100">
                            <tr>
                                <th class="border-b px-4 py-2">{{ tr('Club', 'Club') }}</th>
                                <th class="border-b px-4 py-2">{{ tr('Orden', 'Order') }}</th>
                                <th class="border-b px-4 py-2">{{ tr('Nombre', 'Name') }}</th>
                                <th class="border-b px-4 py-2">{{ tr('Instructor', 'Instructor') }}</th>
                                <th class="border-b px-4 py-2">{{ tr('Acciones', 'Actions') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <template v-for="club in filteredClubs" :key="club.id">
                                <tr v-if="isCarpetaClub(club) && !getCarpetaClassRows(club).length">
                                    <td colspan="5" class="px-4 py-6 text-sm text-amber-800 bg-amber-50 border-b">
                                        {{ tr('La union no tiene clases de carpeta configuradas para este tipo de club.', 'The union has no folder classes configured for this club type.') }}
                                    </td>
                                </tr>
                                <template
                                    v-for="cls in isCarpetaClub(club) ? getCarpetaClassRows(club) : getClubClasses(club)"
                                    :key="isCarpetaClub(club) ? `catalog-${club.id}-${cls.id}` : cls.id">
                                    <tr>
                                        <td class="px-4 py-2">{{ club.club_name }}</td>
                                        <td class="px-4 py-2">{{ isCarpetaClub(club) ? cls.sort_order : cls.class_order }}</td>
                                        <td class="px-4 py-2">
                                            <div class="flex items-center gap-2">
                                                <span>{{ isCarpetaClub(club) ? cls.name : cls.class_name }}</span>
                                                <span
                                                    v-if="isCarpetaClub(club)"
                                                    class="inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-medium"
                                                    :class="cls.is_active ? 'bg-emerald-100 text-emerald-800' : 'bg-gray-100 text-gray-700'"
                                                >
                                                    {{ cls.is_active ? tr('Activa', 'Active') : tr('Inactiva', 'Inactive') }}
                                                </span>
                                            </div>
                                        </td>
                                        <td class="px-4 py-2 text-sm text-gray-700">
                                            {{ getStaffName(cls, isCarpetaClub(club)) }}
                                        </td>
                                        <td class="p-2 space-x-2">
                                            <template v-if="isCarpetaClub(club)">
                                                <button
                                                    v-if="!cls.is_active"
                                                    @click="activateCarpetaClass(club, cls)"
                                                    class="text-emerald-700 hover:underline"
                                                >
                                                    {{ tr('Activar', 'Activate') }}
                                                </button>
                                                <button
                                                    v-else
                                                    @click="deactivateCarpetaClass(cls.activation.id)"
                                                    class="text-red-600 hover:underline"
                                                >
                                                    {{ tr('Desactivar', 'Deactivate') }}
                                                </button>
                                            </template>
                                            <template v-else>
                                                <button @click="editCls(cls)"
                                                    class="text-blue-600 hover:underline">{{ tr('Editar', 'Edit') }}</button>
                                                <button @click="deleteCls(cls.id)"
                                                    class="text-red-600 hover:underline">{{ tr('Eliminar', 'Delete') }}</button>
                                            </template>
                                        </td>
                                    </tr>
                                    <tr v-if="!isCarpetaClub(club) || cls.is_active">
                                        <td colspan="5" class="px-4 py-3 bg-gray-50 border-b">
                                            <template v-if="isCarpetaClub(club)">
                                                <ul v-if="getCarpetaRequirements(cls).length" class="space-y-3">
                                                    <li
                                                        v-for="requirement in getCarpetaRequirements(cls)"
                                                        :key="`carpeta-${requirement.id}`"
                                                        class="rounded-lg border border-gray-200 bg-white p-3 shadow-sm"
                                                    >
                                                        <div class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
                                                            <div class="min-w-0">
                                                                <div class="flex flex-wrap items-center gap-2">
                                                                    <span class="inline-flex items-center rounded-full bg-gray-900 px-2 py-0.5 text-[11px] font-medium text-white">
                                                                        {{ requirement.sort_order }}.
                                                                    </span>
                                                                    <p class="text-sm font-semibold text-gray-900">{{ requirement.title }}</p>
                                                                </div>
                                                                <p v-if="requirement.description" class="mt-2 text-xs text-gray-600">
                                                                    {{ requirement.description }}
                                                                </p>
                                                                <p v-if="requirement.evidence_instructions" class="mt-2 text-xs text-gray-700">
                                                                    <span class="font-medium">{{ tr('Instrucciones', 'Instructions') }}:</span> {{ requirement.evidence_instructions }}
                                                                </p>
                                                            </div>
                                                            <div class="grid grid-cols-1 gap-2 text-xs text-gray-700 md:min-w-[220px]">
                                                                <div class="rounded border bg-gray-50 px-2 py-1.5">
                                                                    <span class="font-medium">{{ tr('Tipo', 'Type') }}:</span> {{ requirementTypeLabel(requirement.requirement_type) }}
                                                                </div>
                                                                <div class="rounded border bg-gray-50 px-2 py-1.5">
                                                                    <span class="font-medium">{{ tr('Validacion', 'Validation') }}:</span> {{ validationModeLabel(requirement.validation_mode) }}
                                                                </div>
                                                                <div class="rounded border bg-gray-50 px-2 py-1.5">
                                                                    <span class="font-medium">{{ tr('Evidencias', 'Evidence') }}:</span>
                                                                        {{ (requirement.allowed_evidence_types || []).length
                                                                            ? requirement.allowed_evidence_types.map(evidenceTypeLabel).join(', ')
                                                                            : tr('Segun defina la union', 'As defined by the union') }}
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </li>
                                                </ul>
                                                <div v-else class="rounded border border-dashed border-amber-300 bg-white px-3 py-4 text-sm text-amber-800">
                                                    {{ tr('No hay requisitos publicados para esta clase en el ciclo de carpeta actual de la union.', 'There are no published requirements for this class in the current union folder cycle.') }}
                                                </div>
                                            </template>

                                            <template v-else>
                                                <div class="flex items-center justify-between mb-2">
                                                    <p class="text-sm font-semibold text-gray-800">
                                                        {{ tr('Requisitos de investidura', 'Investiture requirements') }}<span v-if="club.club_type === 'adventurers'"> (Honores/Honors)</span>
                                                    </p>
                                                    <button
                                                        type="button"
                                                        class="text-sm text-blue-700 hover:underline"
                                                        @click="startCreateRequirement(cls.id)"
                                                    >
                                                        + {{ tr('Agregar requisito', 'Add requirement') }}
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
                                                                    {{ tr('Editar', 'Edit') }}
                                                                </button>
                                                                <button
                                                                    type="button"
                                                                    class="text-xs text-red-700 hover:underline"
                                                                    @click="removeRequirement(requirement.id)"
                                                                >
                                                                    {{ tr('Eliminar', 'Delete') }}
                                                                </button>
                                                            </div>
                                                        </div>
                                                    </li>
                                                </ul>
                                                <p v-else class="text-xs text-gray-500 mb-3">{{ tr('No hay requisitos registrados para esta clase.', 'There are no requirements recorded for this class.') }}</p>

                                                <div v-if="showRequirementFormByClass[cls.id]" class="grid grid-cols-1 md:grid-cols-4 gap-2">
                                                    <input
                                                        v-model="getRequirementDraft(cls.id).title"
                                                        type="text"
                                                        :placeholder="tr('Titulo del requisito', 'Requirement title')"
                                                        class="border rounded px-2 py-1 text-sm md:col-span-2"
                                                    />
                                                    <input
                                                        v-model="getRequirementDraft(cls.id).description"
                                                        type="text"
                                                        :placeholder="tr('Descripcion (opcional)', 'Description (optional)')"
                                                        class="border rounded px-2 py-1 text-sm"
                                                    />
                                                    <input
                                                        v-model.number="getRequirementDraft(cls.id).sort_order"
                                                        type="number"
                                                        min="1"
                                                        :placeholder="tr('Orden', 'Order')"
                                                        class="border rounded px-2 py-1 text-sm"
                                                    />
                                                </div>
                                                <div v-if="showRequirementFormByClass[cls.id]" class="mt-2 flex items-center gap-3">
                                                    <button
                                                        type="button"
                                                        class="text-sm bg-green-600 hover:bg-green-700 text-white px-3 py-1 rounded"
                                                        @click="saveRequirement(cls)"
                                                    >
                                                        {{ editingRequirementByClass[cls.id] ? tr('Actualizar', 'Update') : tr('Guardar', 'Save') }}
                                                    </button>
                                                    <button
                                                        type="button"
                                                        class="text-xs text-gray-600 hover:underline"
                                                        @click="cancelRequirementEdit(cls.id)"
                                                    >
                                                        {{ tr('Limpiar', 'Clear') }}
                                                    </button>
                                                </div>
                                            </template>
                                        </td>
                                    </tr>
                                </template>
                            </template>
                        </tbody>
                    </table>
                </div>
            </details>

            <details class="border rounded">
                <summary class="bg-gray-100 px-4 py-2 font-semibold cursor-pointer">{{ tr('Objetivos', 'Objectives') }}</summary>
                <div class="p-4">
                    <div class="flex justify-between items-center mb-4">
                        <div>
                            <h3 class="text-lg font-bold">{{ tr('Objetivos del club', 'Club Objectives') }}</h3>
                            <p class="text-sm text-gray-600">{{ tr('Estos objetivos son locales y luego pueden usarse en el plan de trabajo aun si no se importaron desde mychurchadmin.', 'These objectives are local and can later be used in the workplan even if they were not imported from mychurchadmin.') }}</p>
                        </div>
                    </div>

                    <table class="min-w-full border rounded text-left border-collapse">
                        <thead class="bg-gray-100">
                            <tr>
                                <th class="border-b px-4 py-2">{{ tr('Club', 'Club') }}</th>
                                <th class="border-b px-4 py-2">{{ tr('Objetivos', 'Objectives') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="club in filteredClubs" :key="`objectives-${club.id}`" class="border-b align-top">
                                <td class="px-4 py-3 font-medium text-gray-900">{{ club.club_name }}</td>
                                <td class="px-4 py-3">
                                    <div class="flex items-center justify-between mb-3">
                                        <div class="text-sm text-gray-600">
                                            {{ getClubObjectives(club).length }} {{ tr('objetivo(s) local(es)', 'local objective(s)') }}
                                        </div>
                                        <button
                                            type="button"
                                            class="text-sm text-blue-700 hover:underline"
                                            @click="startCreateObjective(club.id)"
                                        >
                                            + {{ tr('Agregar objetivo', 'Add objective') }}
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
                                                            {{ tr('Vinculado a MCA', 'Linked to MCA') }} #{{ objective.external_objective_id }}
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
                                                        <span class="font-medium">{{ tr('Metrica anual', 'Annual metric') }}:</span> {{ objective.annual_evaluation_metric }}
                                                    </p>
                                                </div>
                                                <div class="flex items-center gap-2">
                                                    <button
                                                        type="button"
                                                        class="text-xs text-blue-700 hover:underline"
                                                        @click="startEditObjective(club.id, objective)"
                                                    >
                                                        {{ tr('Editar', 'Edit') }}
                                                    </button>
                                                    <button
                                                        type="button"
                                                        class="text-xs text-red-700 hover:underline"
                                                        @click="removeObjective(club.id, objective.id)"
                                                    >
                                                        {{ tr('Eliminar', 'Delete') }}
                                                    </button>
                                                </div>
                                            </div>
                                        </li>
                                    </ul>
                                    <p v-else class="text-xs text-gray-500 mb-3">{{ tr('No hay objetivos locales registrados para este club.', 'There are no local objectives recorded for this club.') }}</p>

                                    <div v-if="showObjectiveFormByClub[club.id]" class="space-y-3 border rounded bg-gray-50 p-3">
                                        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                                            <div>
                                                <label class="block text-xs font-medium text-gray-600 mb-1">{{ tr('Iglesia', 'Church') }}</label>
                                                <input
                                                    :value="club.church_name || '—'"
                                                    type="text"
                                                    readonly
                                                    class="w-full border rounded px-2 py-2 text-sm bg-gray-100 text-gray-600"
                                                />
                                            </div>
                                            <div>
                                                <label class="block text-xs font-medium text-gray-600 mb-1">{{ tr('Dpto', 'Dept') }}</label>
                                                <input
                                                    :value="club.club_type || '—'"
                                                    type="text"
                                                    readonly
                                                    class="w-full border rounded px-2 py-2 text-sm bg-gray-100 text-gray-600"
                                                />
                                            </div>
                                            <div>
                                                <label class="block text-xs font-medium text-gray-600 mb-1">{{ tr('Nombre', 'Name') }}</label>
                                                <input
                                                    v-model="getObjectiveDraft(club.id).name"
                                                    type="text"
                                                    :placeholder="tr('Nombre del objetivo', 'Objective name')"
                                                    class="w-full border rounded px-2 py-2 text-sm"
                                                />
                                            </div>
                                            <div>
                                                <label class="block text-xs font-medium text-gray-600 mb-1">{{ tr('Metrica de evaluacion anual', 'Annual evaluation metric') }}</label>
                                                <input
                                                    v-model="getObjectiveDraft(club.id).annual_evaluation_metric"
                                                    type="text"
                                                    :placeholder="tr('Metrica anual', 'Annual metric')"
                                                    class="w-full border rounded px-2 py-2 text-sm"
                                                />
                                            </div>
                                        </div>
                                        <div>
                                            <label class="block text-xs font-medium text-gray-600 mb-1">{{ tr('Descripcion', 'Description') }}</label>
                                            <textarea
                                                v-model="getObjectiveDraft(club.id).description"
                                                rows="3"
                                                :placeholder="tr('Descripcion', 'Description')"
                                                class="w-full border rounded px-2 py-2 text-sm"
                                            />
                                        </div>
                                        <div class="flex items-center gap-3">
                                            <button
                                                type="button"
                                                class="text-sm bg-green-600 hover:bg-green-700 text-white px-3 py-1 rounded"
                                                @click="saveObjective(club)"
                                            >
                                                {{ editingObjectiveByClub[club.id] ? tr('Actualizar', 'Update') : tr('Guardar', 'Save') }}
                                            </button>
                                            <button
                                                type="button"
                                                class="text-xs text-gray-600 hover:underline"
                                                @click="cancelObjectiveEdit(club.id)"
                                            >
                                                {{ tr('Limpiar', 'Clear') }}
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
