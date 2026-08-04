<script setup>
import { useForm, router } from '@inertiajs/vue3'
import CreateClassModal from '@/Components/CreateClassModal.vue'
import PathfinderLayout from '@/Layouts/PathfinderLayout.vue'
import { useAuth } from '@/Composables/useAuth'
import { useGeneral } from '@/Composables/useGeneral'
import { useLocale } from '@/Composables/useLocale'
import { refreshPage } from '@/Helpers/general'
import { computed, nextTick, ref, watch, onMounted } from 'vue'

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
    saveAdventurerYearlyApplication,
    sendAdventurerYearlyApplication,
    saveAdventurerYearlyApplicationDirectorSignature,
    requestAdventurerYearlyApplicationSignature,
    savePathfinderAnnualApplication,
    sendPathfinderAnnualApplication,
    savePathfinderAnnualApplicationDirectorSignature,
    requestPathfinderAnnualApplicationSignature,
    savePathfinderMonthlyReport,
    sendPathfinderMonthlyReport,
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
const adventurerYearlyDraftByClub = ref({})
const adventurerYearlyEmailByApplication = ref({})
const adventurerYearlyDefaultRecipient = 'areynolds@ccosda.org'
const savingAdventurerYearlyByClub = ref({})
const sendingAdventurerYearlyByApplication = ref({})
const adventurerSignerDraftByRole = ref({})
const adventurerDirectorSignatureMode = ref({})
const adventurerDirectorCanvas = ref({})
const adventurerDirectorDrawing = ref({})
const adventurerDirectorHasDrawing = ref({})
const savingAdventurerDirectorSignature = ref({})
const requestingAdventurerSignature = ref({})
const annualApplicationDraftByClub = ref({})
const annualApplicationEmailByClub = ref({})
const savingAnnualApplicationByClub = ref({})
const sendingAnnualApplicationByClub = ref({})
const annualApplicationPastorEmailByClub = ref({})
const annualApplicationHeadElderEmailByClub = ref({})
const annualApplicationHeadElderNameByClub = ref({})
const annualApplicationDirectorSignatureModeByClub = ref({})
const savingDirectorSignatureByClub = ref({})
const requestingAnnualApplicationSignatureByClub = ref({})
const annualApplicationJotformModeByClub = ref({})
const directorSignatureCanvasByClub = ref({})
const directorSignatureDrawingByClub = ref({})
const directorSignatureHasDrawingByClub = ref({})
const monthlyReportDraftByClub = ref({})
const monthlyReportEmailByClub = ref({})
const savingMonthlyReportByClub = ref({})
const sendingMonthlyReportByClub = ref({})
const monthlyReportVolunteerFilesByClub = ref({})
const monthlyReportActivityFilesByClub = ref({})
const monthlyReportJotformModeByClub = ref({})

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
        clubs.value.forEach(club => {
            getAdventurerYearlyApplications(club).forEach(preloadAdventurerYearlyRecipient)
        })
        annualApplicationDraftByClub.value = {}
        adventurerYearlyDraftByClub.value = {}
        monthlyReportDraftByClub.value = {}
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

const isAdventurerHonorsClub = (club) => club?.club_type === 'adventurers' && (club?.evaluation_system || 'honors') === 'honors'
const adventurerHonorsClubs = computed(() => filteredClubs.value.filter(isAdventurerHonorsClub))

const getAdventurerYearlyApplications = (club) => (
    Array.isArray(club?.adventurer_yearly_applications)
        ? club.adventurer_yearly_applications.slice().sort((a, b) => Number(b.application_year) - Number(a.application_year))
        : []
)

const adventurerYearlyDefaults = (club) => ({
    application_year: String(new Date().getFullYear()),
    application_date: today,
    club_name: club?.club_name || '',
    sponsoring_church: club?.church_name || '',
    pastor: club?.pastor_name || '',
    elected_club_director: club?.director_name || user.value?.name || '',
    email_address: user.value?.email || '',
    cell_number: '',
    home_address: '',
    church_pastor_signature: '',
    head_elder_signature: '',
    church_clerk_signature: '',
    club_director_signature: '',
    signature_date: today,
    other_board_members: ['', '', '', '', ''],
    signatures_complete: false,
    signatures: [],
})

const getAdventurerYearlyDraft = (club) => {
    if (!club?.id) return adventurerYearlyDefaults(club)
    if (!adventurerYearlyDraftByClub.value[club.id]) {
        adventurerYearlyDraftByClub.value[club.id] = adventurerYearlyDefaults(club)
    }
    return adventurerYearlyDraftByClub.value[club.id]
}

const clearAdventurerYearlyForm = (club) => {
    adventurerYearlyDraftByClub.value[club.id] = adventurerYearlyDefaults(club)
}

const syncAdventurerYearlyApplication = (clubId, application) => {
    const club = clubs.value.find(item => Number(item.id) === Number(clubId))
    if (!club) return
    const existing = getAdventurerYearlyApplications(club)
        .filter(item => Number(item.id) !== Number(application.id))
    club.adventurer_yearly_applications = [application, ...existing]
    preloadAdventurerYearlyRecipient(application)
}

const saveAdventurerYearly = async (club) => {
    const draft = getAdventurerYearlyDraft(club)
    if (!draft.application_year || !draft.application_date || !draft.club_name?.trim() || !draft.sponsoring_church?.trim()) {
        showToast(tr('Completa el año, la fecha, el club y la iglesia patrocinadora', 'Complete the year, date, club, and sponsoring church'), 'error')
        return
    }

    savingAdventurerYearlyByClub.value[club.id] = true
    try {
        const response = await saveAdventurerYearlyApplication(club.id, draft)
        syncAdventurerYearlyApplication(club.id, response.data)
        showToast(tr('Solicitud anual guardada', 'Yearly application saved'), 'success')
    } catch (error) {
        console.error('Failed to save Adventurer yearly application:', error)
        showToast(error?.response?.data?.message || tr('No se pudo guardar la solicitud anual', 'Could not save the yearly application'), 'error')
    } finally {
        savingAdventurerYearlyByClub.value[club.id] = false
    }
}

const downloadAdventurerYearly = (club, application) => {
    window.open(route('clubs.adventurer-yearly-applications.download', {
        club: club.id,
        application: application.id,
    }), '_blank')
}

const sendAdventurerYearly = async (club, application) => {
    const email = String(adventurerYearlyEmailByApplication.value[application.id] || '').trim()
    if (!adventurerSignaturesComplete(application)) {
        showToast(tr('Completa las cuatro firmas antes de enviar', 'Complete all four signatures before sending'), 'error')
        return
    }
    if (!email) {
        showToast(tr('Indica el correo destino', 'Enter the recipient email'), 'error')
        return
    }

    sendingAdventurerYearlyByApplication.value[application.id] = true
    try {
        const response = await sendAdventurerYearlyApplication(club.id, application.id, email)
        syncAdventurerYearlyApplication(club.id, response.data)
        adventurerYearlyEmailByApplication.value[application.id] = response.data.last_sent_to_email || email
        showToast(tr('Solicitud anual enviada', 'Yearly application sent'), 'success')
    } catch (error) {
        console.error('Failed to send Adventurer yearly application:', error)
        showToast(error?.response?.data?.message || tr('No se pudo enviar la solicitud anual', 'Could not send the yearly application'), 'error')
    } finally {
        sendingAdventurerYearlyByApplication.value[application.id] = false
    }
}

const adventurerDeliveryLabel = (application) => {
    if (application.delivery_status === 'sent') return tr('Enviada', 'Sent')
    if (application.delivery_status === 'failed') return tr('Falló', 'Failed')
    return tr('Guardada', 'Saved')
}

const adventurerSignature = (application, role) => (
    (application?.signatures || []).find(signature => signature.role === role) || {
        role,
        signer_name: '',
        signer_email: '',
        status: 'pending',
        requested_at: null,
        signed_at: null,
        signature_url: null,
    }
)

const adventurerSignaturesComplete = (application) => {
    const signedRoles = new Set((application?.signatures || [])
        .filter(signature => signature?.signed_at)
        .map(signature => signature.role))
    return ['director', 'pastor', 'head_elder', 'church_clerk'].every(role => signedRoles.has(role))
}

const preloadAdventurerYearlyRecipient = (application) => {
    if (!application?.id || !adventurerSignaturesComplete(application)) return
    if (String(adventurerYearlyEmailByApplication.value[application.id] || '').trim()) return
    adventurerYearlyEmailByApplication.value[application.id] = application.last_sent_to_email || adventurerYearlyDefaultRecipient
}

const adventurerSignerDraft = (club, application, role) => {
    const key = `${application.id}-${role}`
    if (!adventurerSignerDraftByRole.value[key]) {
        const signature = adventurerSignature(application, role)
        const defaultName = role === 'director'
            ? (application.elected_club_director || club.director_name || user.value?.name || '')
            : role === 'pastor'
                ? (application.pastor || club.pastor_name || '')
                : ''
        adventurerSignerDraftByRole.value[key] = {
            signer_name: signature.signer_name || defaultName,
            signer_email: signature.signer_email || (role === 'pastor' ? club?.church?.pastor_email || '' : ''),
        }
    }
    return adventurerSignerDraftByRole.value[key]
}

const adventurerSignatureStatusLabel = (signature) => {
    if (signature?.signed_at) return tr('Firmado', 'Signed')
    if (signature?.requested_at) return tr('Solicitud enviada', 'Request sent')
    return tr('Pendiente', 'Pending')
}

const adventurerSignatureStatusClass = (signature) => {
    if (signature?.signed_at) return 'bg-emerald-100 text-emerald-800'
    if (signature?.requested_at) return 'bg-amber-100 text-amber-800'
    return 'bg-gray-100 text-gray-700'
}

const adventurerRoleLabel = (role) => ({
    pastor: tr('Pastor de la iglesia', 'Church Pastor'),
    head_elder: tr('Anciano principal', 'Head Elder'),
    church_clerk: tr('Secretario de iglesia', 'Church Clerk'),
    director: tr('Director del club', 'Club Director'),
}[role] || role)

const setAdventurerDirectorCanvas = (applicationId, element) => {
    if (!element || adventurerDirectorCanvas.value[applicationId] === element) return
    adventurerDirectorCanvas.value[applicationId] = element
    nextTick(() => configureAdventurerDirectorCanvas(applicationId))
}

const configureAdventurerDirectorCanvas = (applicationId) => {
    const canvas = adventurerDirectorCanvas.value[applicationId]
    if (!canvas) return
    const rect = canvas.getBoundingClientRect()
    const ratio = window.devicePixelRatio || 1
    canvas.width = Math.max(1, Math.floor(rect.width * ratio))
    canvas.height = Math.max(1, Math.floor(rect.height * ratio))
    const context = canvas.getContext('2d')
    context.fillStyle = '#ffffff'
    context.fillRect(0, 0, canvas.width, canvas.height)
    context.strokeStyle = '#111827'
    context.lineWidth = 2.5 * ratio
    context.lineCap = 'round'
    context.lineJoin = 'round'
    adventurerDirectorHasDrawing.value[applicationId] = false
}

const adventurerDirectorPoint = (applicationId, event) => {
    const canvas = adventurerDirectorCanvas.value[applicationId]
    const rect = canvas.getBoundingClientRect()
    return {
        x: (event.clientX - rect.left) * (canvas.width / rect.width),
        y: (event.clientY - rect.top) * (canvas.height / rect.height),
    }
}

const startAdventurerDirectorSignature = (applicationId, event) => {
    const canvas = adventurerDirectorCanvas.value[applicationId]
    if (!canvas) return
    adventurerDirectorDrawing.value[applicationId] = true
    adventurerDirectorHasDrawing.value[applicationId] = true
    const point = adventurerDirectorPoint(applicationId, event)
    const context = canvas.getContext('2d')
    context.beginPath()
    context.moveTo(point.x, point.y)
}

const drawAdventurerDirectorSignature = (applicationId, event) => {
    if (!adventurerDirectorDrawing.value[applicationId]) return
    const canvas = adventurerDirectorCanvas.value[applicationId]
    const point = adventurerDirectorPoint(applicationId, event)
    const context = canvas.getContext('2d')
    context.lineTo(point.x, point.y)
    context.stroke()
}

const saveAdventurerDirector = async (club, application) => {
    const draft = adventurerSignerDraft(club, application, 'director')
    const mode = adventurerDirectorSignatureMode.value[application.id] || 'typed'
    if (!draft.signer_name?.trim()) {
        showToast(tr('Indica el nombre del director', 'Enter the director name'), 'error')
        return
    }
    if (mode === 'drawn' && !adventurerDirectorHasDrawing.value[application.id]) {
        showToast(tr('Dibuja la firma del director', 'Draw the director signature'), 'error')
        return
    }

    savingAdventurerDirectorSignature.value[application.id] = true
    try {
        const response = await saveAdventurerYearlyApplicationDirectorSignature(club.id, application.id, {
            signature_type: mode,
            signer_name: draft.signer_name.trim(),
            signature_text: mode === 'typed' ? draft.signer_name.trim() : null,
            signature_data: mode === 'drawn' ? adventurerDirectorCanvas.value[application.id]?.toDataURL('image/png') : null,
        })
        syncAdventurerYearlyApplication(club.id, response.data)
        showToast(tr('Firma del director guardada', 'Director signature saved'), 'success')
    } catch (error) {
        showToast(error?.response?.data?.message || tr('No se pudo guardar la firma', 'Could not save the signature'), 'error')
    } finally {
        savingAdventurerDirectorSignature.value[application.id] = false
    }
}

const requestAdventurerSignature = async (club, application, role) => {
    const draft = adventurerSignerDraft(club, application, role)
    if (!draft.signer_email?.trim()) {
        showToast(tr('Indica el correo destino', 'Enter the recipient email'), 'error')
        return
    }

    const key = `${application.id}-${role}`
    requestingAdventurerSignature.value[key] = true
    try {
        const response = await requestAdventurerYearlyApplicationSignature(club.id, application.id, {
            role,
            name: draft.signer_name?.trim() || null,
            email: draft.signer_email.trim(),
        })
        syncAdventurerYearlyApplication(club.id, response.data)
        showToast(tr('Solicitud de firma enviada', 'Signature request sent'), 'success')
    } catch (error) {
        showToast(error?.response?.data?.message || tr('No se pudo enviar la solicitud de firma', 'Could not send the signature request'), 'error')
    } finally {
        requestingAdventurerSignature.value[key] = false
    }
}

const isPathfinderHonorsClub = (club) => club?.club_type === 'pathfinders' && (club?.evaluation_system || 'honors') === 'honors'
const pathfinderClubs = computed(() => filteredClubs.value.filter(isPathfinderHonorsClub))
const currentApplicationYear = computed(() => {
    return String(new Date().getFullYear())
})
const annualApplicationYearOptions = (club) => {
    const currentYear = new Date().getFullYear()
    const baseYears = Array.from({ length: 8 }, (_, index) => String(currentYear + 1 - index))
    const existingYears = getAnnualApplications(club).map(application => String(application.application_year))

    return Array.from(new Set([...baseYears, ...existingYears]))
        .sort((a, b) => Number(b) - Number(a))
}
const defaultDueDateForApplicationYear = (year = currentApplicationYear.value) => {
    const numericYear = Number.parseInt(String(year), 10)
    const dueYear = Number.isFinite(numericYear) ? numericYear - 1 : new Date().getFullYear() - 1

    return `${dueYear}-10-10`
}
const annualApplicationJotformUrl = 'https://form.jotform.com/252098248383061'

const getAnnualApplications = (club) => (
    Array.isArray(club?.pathfinder_annual_applications)
        ? club.pathfinder_annual_applications
        : []
)

const findAnnualApplication = (club, year = currentApplicationYear.value) => (
    getAnnualApplications(club).find(application => application.application_year === year) || null
)

const annualApplicationDefaults = (club, year = currentApplicationYear.value) => ({
    id: null,
    application_year: year,
    due_date: defaultDueDateForApplicationYear(year),
    sponsoring_church: club?.church_name || '',
    pastor: club?.pastor_name || '',
    elected_club_director: club?.director_name || user.value?.name || '',
    mailing_address: '',
    director_phone_number: '',
    church_pastor_signature: '',
    head_elder_signature: '',
    club_director_signature: club?.director_name || user.value?.name || '',
    board_approval_date: '',
    pdf_url: null,
    delivery_status: null,
    last_sent_to_email: null,
    sent_at: null,
    signatures_complete: false,
    signatures: [],
})

const normalizeAnnualApplicationDraft = (club, application) => ({
    ...annualApplicationDefaults(club, application?.application_year || currentApplicationYear.value),
    ...(application || {}),
    signatures_complete: application?.signatures_complete ?? hasCompleteAnnualApplicationSignatures(application),
})

const hasCompleteAnnualApplicationSignatures = (application) => {
    const signedRoles = new Set((application?.signatures || [])
        .filter(signature => signature?.signed_at)
        .map(signature => signature.role))

    return ['director', 'pastor', 'head_elder'].every(role => signedRoles.has(role))
}

const hydrateAnnualApplicationSignatureState = (clubId, club, application) => {
    annualApplicationDirectorSignatureModeByClub.value[clubId] ||= 'typed'
    annualApplicationPastorEmailByClub.value[clubId] ||= club?.church?.pastor_email || ''
    const pastorSignature = (application?.signatures || []).find(signature => signature.role === 'pastor')
    const headElderSignature = (application?.signatures || []).find(signature => signature.role === 'head_elder')
    if (pastorSignature?.signer_email) {
        annualApplicationPastorEmailByClub.value[clubId] = pastorSignature.signer_email
    }
    if (headElderSignature?.signer_name) {
        annualApplicationHeadElderNameByClub.value[clubId] = headElderSignature.signer_name
    }
    if (headElderSignature?.signer_email) {
        annualApplicationHeadElderEmailByClub.value[clubId] = headElderSignature.signer_email
    }
}

const getAnnualApplicationDraft = (club) => {
    const clubKey = club?.id
    if (!clubKey) return annualApplicationDefaults(club)

    if (!annualApplicationDraftByClub.value[clubKey]) {
        const existing = findAnnualApplication(club)
        annualApplicationDraftByClub.value[clubKey] = normalizeAnnualApplicationDraft(club, existing)
        hydrateAnnualApplicationSignatureState(clubKey, club, annualApplicationDraftByClub.value[clubKey])
    }

    return annualApplicationDraftByClub.value[clubKey]
}

const selectAnnualApplication = (club, application) => {
    if (!club?.id || !application) return
    annualApplicationDraftByClub.value[club.id] = normalizeAnnualApplicationDraft(club, application)
    hydrateAnnualApplicationSignatureState(club.id, club, annualApplicationDraftByClub.value[club.id])
}

const selectAnnualApplicationYear = (club, year) => {
    if (!club?.id || !year) return
    const existing = findAnnualApplication(club, year)
    annualApplicationDraftByClub.value[club.id] = normalizeAnnualApplicationDraft(club, existing || { application_year: year })
    hydrateAnnualApplicationSignatureState(club.id, club, annualApplicationDraftByClub.value[club.id])
}

const cleanAnnualApplicationForm = (club) => {
    if (!club?.id) return
    const year = getAnnualApplicationDraft(club).application_year || currentApplicationYear.value
    annualApplicationDraftByClub.value[club.id] = annualApplicationDefaults(club, year)
    annualApplicationDirectorSignatureModeByClub.value[club.id] = 'typed'
    annualApplicationPastorEmailByClub.value[club.id] = club?.church?.pastor_email || ''
    annualApplicationHeadElderNameByClub.value[club.id] = ''
    annualApplicationHeadElderEmailByClub.value[club.id] = ''
    directorSignatureHasDrawingByClub.value[club.id] = false
    nextTick(() => configureDirectorSignatureCanvas(club.id))
}

const syncAnnualApplicationToClub = (clubId, application) => {
    const club = clubs.value.find(item => Number(item.id) === Number(clubId))
    if (!club) return
    const existing = getAnnualApplications(club)
    const next = existing.filter(item => Number(item.id) !== Number(application.id))
    next.unshift(application)
    club.pathfinder_annual_applications = next
    annualApplicationDraftByClub.value[clubId] = normalizeAnnualApplicationDraft(club, application)
    hydrateAnnualApplicationSignatureState(clubId, club, application)
}

const annualApplicationSignatures = (club) => getAnnualApplicationDraft(club).signatures || []

const annualApplicationSignature = (club, role) => (
    annualApplicationSignatures(club).find(signature => signature.role === role) || {
        role,
        status: 'pending',
        signer_name: '',
        signer_email: '',
        signed_at: null,
    }
)

const signatureStatusLabel = (signature) => {
    if (signature?.signed_at) return tr('Firmado', 'Signed')
    if (signature?.requested_at) return tr('Solicitud enviada', 'Request sent')
    return tr('Pendiente', 'Pending')
}

const signatureStatusClass = (signature) => {
    if (signature?.signed_at) return 'bg-emerald-100 text-emerald-800'
    if (signature?.requested_at) return 'bg-amber-100 text-amber-800'
    return 'bg-gray-100 text-gray-700'
}

const setDirectorSignatureCanvas = (clubId, element) => {
    if (!element) return
    if (directorSignatureCanvasByClub.value[clubId] === element) return
    directorSignatureCanvasByClub.value[clubId] = element
    nextTick(() => configureDirectorSignatureCanvas(clubId))
}

const configureDirectorSignatureCanvas = (clubId) => {
    const canvas = directorSignatureCanvasByClub.value[clubId]
    if (!canvas) return
    const rect = canvas.getBoundingClientRect()
    const ratio = window.devicePixelRatio || 1
    canvas.width = Math.max(1, Math.floor(rect.width * ratio))
    canvas.height = Math.max(1, Math.floor(rect.height * ratio))
    const context = canvas.getContext('2d')
    context.fillStyle = '#ffffff'
    context.fillRect(0, 0, canvas.width, canvas.height)
    context.strokeStyle = '#111827'
    context.lineWidth = 2.5 * ratio
    context.lineCap = 'round'
    context.lineJoin = 'round'
    directorSignatureHasDrawingByClub.value[clubId] = false
}

const directorSignaturePoint = (clubId, event) => {
    const canvas = directorSignatureCanvasByClub.value[clubId]
    const rect = canvas.getBoundingClientRect()
    return {
        x: (event.clientX - rect.left) * (canvas.width / rect.width),
        y: (event.clientY - rect.top) * (canvas.height / rect.height),
    }
}

const startDirectorSignature = (clubId, event) => {
    const canvas = directorSignatureCanvasByClub.value[clubId]
    if (!canvas) return
    directorSignatureDrawingByClub.value[clubId] = true
    directorSignatureHasDrawingByClub.value[clubId] = true
    const context = canvas.getContext('2d')
    const point = directorSignaturePoint(clubId, event)
    context.beginPath()
    context.moveTo(point.x, point.y)
}

const drawDirectorSignature = (clubId, event) => {
    if (!directorSignatureDrawingByClub.value[clubId]) return
    const canvas = directorSignatureCanvasByClub.value[clubId]
    if (!canvas) return
    const context = canvas.getContext('2d')
    const point = directorSignaturePoint(clubId, event)
    context.lineTo(point.x, point.y)
    context.stroke()
}

const stopDirectorSignature = (clubId) => {
    directorSignatureDrawingByClub.value[clubId] = false
}

const saveDirectorSignature = async (club) => {
    let draft = getAnnualApplicationDraft(club)
    if (!draft.id) {
        const savedApplication = await saveAnnualApplication(club)
        if (!savedApplication) return
        draft = getAnnualApplicationDraft(club)
    }

    const mode = annualApplicationDirectorSignatureModeByClub.value[club.id] || 'typed'
    const signerName = String(draft.club_director_signature || draft.elected_club_director || user.value?.name || '').trim()

    if (!signerName) {
        showToast(tr('Indica el nombre del director', 'Enter the director name'), 'error')
        return
    }

    if (mode === 'drawn' && !directorSignatureHasDrawingByClub.value[club.id]) {
        showToast(tr('Dibuja la firma del director', 'Draw the director signature'), 'error')
        return
    }

    savingDirectorSignatureByClub.value[club.id] = true
    try {
        const payload = {
            signature_type: mode,
            signer_name: signerName,
            signature_text: mode === 'typed' ? signerName : null,
            signature_data: mode === 'drawn' ? directorSignatureCanvasByClub.value[club.id]?.toDataURL('image/png') : null,
        }
        const response = await savePathfinderAnnualApplicationDirectorSignature(club.id, draft.id, payload)
        syncAnnualApplicationToClub(club.id, response.data)
        showToast(tr('Firma del director guardada', 'Director signature saved'), 'success')
    } catch (error) {
        console.error('Failed to save director signature:', error)
        showToast(error?.response?.data?.message || tr('No se pudo guardar la firma', 'Could not save the signature'), 'error')
    } finally {
        savingDirectorSignatureByClub.value[club.id] = false
    }
}

const requestAnnualApplicationSignature = async (club, role) => {
    const draft = getAnnualApplicationDraft(club)
    if (!draft.id) {
        showToast(tr('Guarda la aplicacion antes de pedir firmas', 'Save the application before requesting signatures'), 'error')
        return
    }

    const email = role === 'pastor'
        ? String(annualApplicationPastorEmailByClub.value[club.id] || club?.church?.pastor_email || '').trim()
        : String(annualApplicationHeadElderEmailByClub.value[club.id] || '').trim()
    const name = role === 'pastor'
        ? String(draft.pastor || club?.church?.pastor_name || '').trim()
        : String(annualApplicationHeadElderNameByClub.value[club.id] || '').trim()

    if (!email) {
        showToast(tr('Indica el correo destino', 'Enter the recipient email'), 'error')
        return
    }

    const key = `${club.id}-${role}`
    requestingAnnualApplicationSignatureByClub.value[key] = true
    try {
        const response = await requestPathfinderAnnualApplicationSignature(club.id, draft.id, { role, email, name })
        syncAnnualApplicationToClub(club.id, response.data)
        showToast(tr('Solicitud de firma enviada', 'Signature request sent'), 'success')
    } catch (error) {
        console.error('Failed to request signature:', error)
        showToast(error?.response?.data?.message || tr('No se pudo enviar la solicitud de firma', 'Could not send the signature request'), 'error')
    } finally {
        requestingAnnualApplicationSignatureByClub.value[key] = false
    }
}

const saveAnnualApplication = async (club) => {
    const draft = getAnnualApplicationDraft(club)
    if (!draft.application_year?.trim()) {
        showToast(tr('Indica el año de aplicacion', 'Enter the application year'), 'error')
        return
    }
    if (!draft.sponsoring_church?.trim()) {
        showToast(tr('Indica la iglesia patrocinadora', 'Enter the sponsoring church'), 'error')
        return
    }

    savingAnnualApplicationByClub.value[club.id] = true
    try {
        const payload = {
            ...draft,
        }
        const response = await savePathfinderAnnualApplication(club.id, payload)
        syncAnnualApplicationToClub(club.id, response.data)
        showToast(tr('Aplicacion anual guardada', 'Annual application saved'), 'success')
        return response.data
    } catch (error) {
        console.error('Failed to save annual application:', error)
        showToast(error?.response?.data?.message || tr('No se pudo guardar la aplicacion anual', 'Could not save the annual application'), 'error')
        return null
    } finally {
        savingAnnualApplicationByClub.value[club.id] = false
    }
}

const downloadAnnualApplication = (club) => {
    const draft = getAnnualApplicationDraft(club)
    if (!draft.id) {
        showToast(tr('Guarda la aplicacion antes de descargar el PDF', 'Save the application before downloading the PDF'), 'error')
        return
    }
    window.open(route('clubs.pathfinder-annual-applications.download', {
        club: club.id,
        application: draft.id,
    }), '_blank')
}

const sendAnnualApplication = async (club) => {
    const draft = getAnnualApplicationDraft(club)
    const email = String(annualApplicationEmailByClub.value[club.id] || '').trim()
    if (!draft.id) {
        showToast(tr('Guarda la aplicacion antes de enviarla', 'Save the application before sending it'), 'error')
        return
    }
    if (!draft.signatures_complete) {
        showToast(tr('La aplicacion requiere las tres firmas antes de enviarse', 'The application requires all three signatures before sending'), 'error')
        return
    }
    if (!email) {
        showToast(tr('Indica el correo destino', 'Enter the recipient email'), 'error')
        return
    }

    sendingAnnualApplicationByClub.value[club.id] = true
    try {
        const response = await sendPathfinderAnnualApplication(club.id, draft.id, email)
        syncAnnualApplicationToClub(club.id, response.data)
        annualApplicationEmailByClub.value[club.id] = ''
        showToast(tr('Aplicacion anual enviada', 'Annual application sent'), 'success')
    } catch (error) {
        console.error('Failed to send annual application:', error)
        showToast(error?.response?.data?.message || tr('No se pudo enviar la aplicacion anual', 'Could not send the annual application'), 'error')
    } finally {
        sendingAnnualApplicationByClub.value[club.id] = false
    }
}

const monthlyReportAreas = ['West', 'Central-West', 'Central-East', 'North-East', 'North-West', 'Eastern Shore', 'South']
const monthlyReportMonths = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December']
const pathfinderMonthlyReportClubs = computed(() => filteredClubs.value.filter(isPathfinderHonorsClub))
const currentReportYear = computed(() => String(new Date().getFullYear()))
const currentReportMonth = computed(() => monthlyReportMonths[new Date().getMonth()])
const monthlyReportJotformUrl = 'https://form.jotform.com/252724787908169'

const getMonthlyReports = (club) => (
    Array.isArray(club?.pathfinder_monthly_reports)
        ? club.pathfinder_monthly_reports
        : []
)

const findMonthlyReport = (club, year = currentReportYear.value, month = currentReportMonth.value) => (
    getMonthlyReports(club).find(report => String(report.report_year) === String(year) && report.report_month === month) || null
)

const monthlyReportDefaults = (club, year = currentReportYear.value, month = currentReportMonth.value) => ({
    id: null,
    report_year: year,
    report_month: month,
    full_name: user.value?.name || '',
    email: user.value?.email || '',
    area: '',
    church_and_club_name: [club?.church_name, club?.club_name].filter(Boolean).join(' - '),
    pathfinders_count: '',
    tlt_count: '',
    staff_count: '',
    meetings_count: '',
    bible_studies_count: '',
    baptisms_count: '',
    campouts_count: '',
    field_trips_count: '',
    honors_completed_count: '',
    honors_completed_list: '',
    outreach_activities: '',
    notable_activities: '',
    may_share_photos: '',
    pdf_url: null,
    last_sent_to_email: null,
    delivery_status: null,
    sent_at: null,
    attachments: [],
})

const getMonthlyReportDraft = (club) => {
    const clubKey = club?.id
    if (!clubKey) return monthlyReportDefaults(club)

    if (!monthlyReportDraftByClub.value[clubKey]) {
        const existing = findMonthlyReport(club)
        monthlyReportDraftByClub.value[clubKey] = {
            ...monthlyReportDefaults(club, existing?.report_year || currentReportYear.value, existing?.report_month || currentReportMonth.value),
            ...(existing || {}),
            may_share_photos: existing?.may_share_photos === null || existing?.may_share_photos === undefined
                ? ''
                : String(existing.may_share_photos),
        }
    }

    return monthlyReportDraftByClub.value[clubKey]
}

const normalizeMonthlyReportDraft = (club, report) => ({
    ...monthlyReportDefaults(club, report?.report_year || currentReportYear.value, report?.report_month || currentReportMonth.value),
    ...(report || {}),
    may_share_photos: report?.may_share_photos === null || report?.may_share_photos === undefined
        ? ''
        : String(report.may_share_photos),
})

const selectMonthlyReport = (club, report) => {
    if (!club?.id || !report) return
    monthlyReportDraftByClub.value[club.id] = normalizeMonthlyReportDraft(club, report)
    monthlyReportVolunteerFilesByClub.value[club.id] = []
    monthlyReportActivityFilesByClub.value[club.id] = []
}

const startNewMonthlyReport = (club) => {
    if (!club?.id) return
    monthlyReportDraftByClub.value[club.id] = monthlyReportDefaults(club)
    monthlyReportVolunteerFilesByClub.value[club.id] = []
    monthlyReportActivityFilesByClub.value[club.id] = []
}

const syncMonthlyReportToClub = (clubId, report) => {
    const club = clubs.value.find(item => Number(item.id) === Number(clubId))
    if (!club) return
    const existing = getMonthlyReports(club)
    const next = existing.filter(item => Number(item.id) !== Number(report.id))
    next.unshift(report)
    club.pathfinder_monthly_reports = next
    monthlyReportDraftByClub.value[clubId] = normalizeMonthlyReportDraft(club, report)
}

const setMonthlyReportFiles = (target, clubId, event) => {
    const files = Array.from(event.target.files || [])

    if (target?.value) {
        target.value[clubId] = files
        return
    }

    target[clubId] = files
}

const monthlyReportAttachments = (club, kind) => (
    (getMonthlyReportDraft(club).attachments || []).filter(attachment => attachment.kind === kind)
)

const selectedMonthlyReportFileCount = (target, clubId) => {
    const source = target?.value || target || {}

    return (source[clubId] || []).length
}

const monthlyReportFormData = (club, draft) => {
    const formData = new FormData()
    const scalarFields = [
        'report_year',
        'report_month',
        'full_name',
        'email',
        'area',
        'church_and_club_name',
        'pathfinders_count',
        'tlt_count',
        'staff_count',
        'meetings_count',
        'bible_studies_count',
        'baptisms_count',
        'campouts_count',
        'field_trips_count',
        'honors_completed_count',
        'honors_completed_list',
        'outreach_activities',
        'notable_activities',
    ]

    scalarFields.forEach(field => {
        formData.append(field, draft[field] ?? '')
    })

    if (draft.may_share_photos !== '') {
        formData.append('may_share_photos', draft.may_share_photos)
    }

    ;(monthlyReportVolunteerFilesByClub.value[club.id] || []).forEach(file => {
        formData.append('volunteer_proofs[]', file)
    })
    ;(monthlyReportActivityFilesByClub.value[club.id] || []).forEach(file => {
        formData.append('activity_photos[]', file)
    })

    return formData
}

const saveMonthlyReport = async (club) => {
    const draft = getMonthlyReportDraft(club)
    if (!draft.report_year?.trim() || !draft.report_month?.trim()) {
        showToast(tr('Indica año y mes', 'Enter year and month'), 'error')
        return
    }
    if (!draft.church_and_club_name?.trim()) {
        showToast(tr('Indica iglesia y club', 'Enter church and club name'), 'error')
        return
    }

    savingMonthlyReportByClub.value[club.id] = true
    try {
        const response = await savePathfinderMonthlyReport(club.id, monthlyReportFormData(club, draft))
        syncMonthlyReportToClub(club.id, response.data)
        monthlyReportVolunteerFilesByClub.value[club.id] = []
        monthlyReportActivityFilesByClub.value[club.id] = []
        showToast(tr('Reporte mensual guardado', 'Monthly report saved'), 'success')
        return response.data
    } catch (error) {
        console.error('Failed to save monthly report:', error)
        showToast(error?.response?.data?.message || tr('No se pudo guardar el reporte mensual', 'Could not save the monthly report'), 'error')
        return null
    } finally {
        savingMonthlyReportByClub.value[club.id] = false
    }
}

const downloadMonthlyReport = (club) => {
    const draft = getMonthlyReportDraft(club)
    if (!draft.id) {
        showToast(tr('Guarda el reporte antes de descargar', 'Save the report before downloading'), 'error')
        return
    }
    window.open(route('clubs.pathfinder-monthly-reports.download', {
        club: club.id,
        report: draft.id,
    }), '_blank')
}

const sendMonthlyReport = async (club) => {
    const draft = getMonthlyReportDraft(club)
    const email = String(monthlyReportEmailByClub.value[club.id] || '').trim()
    if (!draft.id) {
        showToast(tr('Guarda el reporte antes de enviarlo', 'Save the report before sending it'), 'error')
        return
    }
    if (!email) {
        showToast(tr('Indica el correo destino', 'Enter the recipient email'), 'error')
        return
    }

    sendingMonthlyReportByClub.value[club.id] = true
    try {
        const response = await sendPathfinderMonthlyReport(club.id, draft.id, email)
        syncMonthlyReportToClub(club.id, response.data)
        monthlyReportEmailByClub.value[club.id] = ''
        showToast(tr('Reporte mensual enviado', 'Monthly report sent'), 'success')
    } catch (error) {
        console.error('Failed to send monthly report:', error)
        showToast(error?.response?.data?.message || tr('No se pudo enviar el reporte mensual', 'Could not send the monthly report'), 'error')
    } finally {
        sendingMonthlyReportByClub.value[club.id] = false
    }
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

                <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
                    <button type="submit" class="rounded bg-red-600 px-4 py-2 text-white hover:bg-red-700">
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
            <div class="overflow-x-auto rounded border bg-white">
            <table class="min-w-[560px] w-full text-sm">
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
        </div>
        <div v-else class="space-y-4">
            <details open class="border rounded">
                <summary class="bg-gray-100 px-4 py-2 font-semibold cursor-pointer">{{ tr('Informacion del club', 'Club Information') }}</summary>
                <div class="p-4">
                    <div class="overflow-x-auto rounded border bg-white">
                    <table class="min-w-[860px] w-full text-sm">
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
                    </div>
                    <div class="mt-4">
                        <button
                            v-if="canCreateAnotherClub && !mustAttachInsteadOfCreate"
                            @click="startCreatingClub"
                            class="w-full rounded bg-green-600 px-4 py-2 text-white hover:bg-green-700 sm:w-auto">
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

            <details v-if="adventurerHonorsClubs.length" class="border rounded">
                <summary class="bg-gray-100 px-4 py-2 font-semibold cursor-pointer">
                    {{ tr('Solicitud anual de Aventureros', 'Adventurer Yearly Application') }}
                </summary>
                <div class="space-y-5 p-4">
                    <div
                        v-for="club in adventurerHonorsClubs"
                        :key="`adventurer-yearly-${club.id}`"
                        class="rounded border bg-white p-4"
                    >
                        <div class="mb-4 flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                            <div>
                                <h3 class="text-lg font-semibold text-gray-900">{{ club.club_name }}</h3>
                                <p class="text-sm text-gray-600">
                                    {{ tr('Disponible para clubes de Aventureros con evaluación por honores.', 'Available for Adventurer clubs using the honors evaluation system.') }}
                                </p>
                            </div>
                            <button
                                type="button"
                                class="w-fit rounded bg-gray-700 px-3 py-2 text-sm text-white hover:bg-gray-800"
                                @click="clearAdventurerYearlyForm(club)"
                            >
                                {{ tr('Limpiar formulario', 'Clear form') }}
                            </button>
                        </div>

                        <div class="grid gap-4 md:grid-cols-2">
                            <label class="text-sm font-medium text-gray-700">
                                {{ tr('Año de solicitud', 'Application year') }}
                                <input v-model="getAdventurerYearlyDraft(club).application_year" type="number" min="2000" max="2100" class="mt-1 w-full rounded border p-2" />
                            </label>
                            <label class="text-sm font-medium text-gray-700">
                                {{ tr('Fecha', 'Date') }}
                                <input v-model="getAdventurerYearlyDraft(club).application_date" type="date" class="mt-1 w-full rounded border p-2" />
                            </label>
                            <label class="text-sm font-medium text-gray-700">
                                {{ tr('Nombre del club', 'Club Name') }}
                                <input v-model="getAdventurerYearlyDraft(club).club_name" type="text" class="mt-1 w-full rounded border p-2" />
                            </label>
                            <label class="text-sm font-medium text-gray-700">
                                {{ tr('Iglesia patrocinadora', 'Sponsoring Church') }}
                                <input v-model="getAdventurerYearlyDraft(club).sponsoring_church" type="text" class="mt-1 w-full rounded border p-2" />
                            </label>
                            <label class="text-sm font-medium text-gray-700">
                                {{ tr('Pastor', 'Pastor') }}
                                <input v-model="getAdventurerYearlyDraft(club).pastor" type="text" class="mt-1 w-full rounded border p-2" />
                            </label>
                            <label class="text-sm font-medium text-gray-700">
                                {{ tr('Director electo del club', 'Elected Club Director') }}
                                <input v-model="getAdventurerYearlyDraft(club).elected_club_director" type="text" class="mt-1 w-full rounded border p-2" />
                            </label>
                            <label class="text-sm font-medium text-gray-700">
                                {{ tr('Correo electrónico', 'Email Address') }}
                                <input v-model="getAdventurerYearlyDraft(club).email_address" type="email" class="mt-1 w-full rounded border p-2" />
                            </label>
                            <label class="text-sm font-medium text-gray-700">
                                {{ tr('Número celular', 'Cell Number') }}
                                <input v-model="getAdventurerYearlyDraft(club).cell_number" type="tel" class="mt-1 w-full rounded border p-2" />
                            </label>
                            <label class="text-sm font-medium text-gray-700 md:col-span-2">
                                {{ tr('Dirección residencial', 'Home Address') }}
                                <input v-model="getAdventurerYearlyDraft(club).home_address" type="text" class="mt-1 w-full rounded border p-2" />
                            </label>
                        </div>

                        <div class="mt-5">
                            <h4 class="mb-3 font-semibold text-gray-900">{{ tr('Otros miembros de la junta de iglesia', 'Other Church Board Members') }}</h4>
                            <div class="grid gap-3 md:grid-cols-2">
                                <label
                                    v-for="(_, index) in getAdventurerYearlyDraft(club).other_board_members"
                                    :key="`board-member-${club.id}-${index}`"
                                    class="text-sm font-medium text-gray-700"
                                >
                                    {{ tr('Miembro', 'Member') }} {{ index + 1 }}
                                    <input v-model="getAdventurerYearlyDraft(club).other_board_members[index]" type="text" class="mt-1 w-full rounded border p-2" />
                                </label>
                            </div>
                        </div>

                        <div class="mt-5 flex justify-end">
                            <button
                                type="button"
                                class="rounded bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-60"
                                :disabled="savingAdventurerYearlyByClub[club.id]"
                                @click="saveAdventurerYearly(club)"
                            >
                                {{ savingAdventurerYearlyByClub[club.id] ? tr('Guardando...', 'Saving...') : tr('Enviar formulario', 'Submit form') }}
                            </button>
                        </div>

                        <div class="mt-6 border-t pt-5">
                            <h4 class="font-semibold text-gray-900">{{ tr('Solicitudes enviadas', 'Submitted applications') }}</h4>
                            <p class="mb-3 text-xs text-gray-500">
                                {{ tr('Después de enviar el formulario, puedes generar el Word o enviarlo por correo.', 'After submitting the form, you can generate the Word document or email it.') }}
                            </p>

                            <div v-if="getAdventurerYearlyApplications(club).length" class="overflow-x-auto rounded border border-gray-200">
                                <table class="min-w-full text-left text-sm">
                                    <thead class="bg-gray-50 text-xs uppercase text-gray-600">
                                        <tr>
                                            <th class="px-3 py-2">{{ tr('Año', 'Year') }}</th>
                                            <th class="px-3 py-2">{{ tr('Fecha', 'Date') }}</th>
                                            <th class="px-3 py-2">{{ tr('Estado', 'Status') }}</th>
                                            <th class="px-3 py-2">{{ tr('Último envío', 'Last sent') }}</th>
                                            <th class="px-3 py-2">{{ tr('Documento', 'Document') }}</th>
                                            <th class="min-w-[280px] px-3 py-2">{{ tr('Enviar por correo', 'Send by email') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-200">
                                        <tr v-for="application in getAdventurerYearlyApplications(club)" :key="application.id">
                                            <td class="px-3 py-3 font-medium">{{ application.application_year }}</td>
                                            <td class="px-3 py-3">{{ application.application_date }}</td>
                                            <td class="px-3 py-3">
                                                <span :class="adventurerSignaturesComplete(application) ? 'text-emerald-700' : 'text-amber-700'">
                                                    {{ adventurerSignaturesComplete(application) ? tr('Firmas completas', 'Signatures complete') : tr('Firmas pendientes', 'Signatures pending') }}
                                                </span>
                                                <div class="text-xs text-gray-500">{{ adventurerDeliveryLabel(application) }}</div>
                                            </td>
                                            <td class="px-3 py-3 text-xs text-gray-600">{{ application.last_sent_to_email || '—' }}</td>
                                            <td class="px-3 py-3">
                                                <button
                                                    type="button"
                                                    class="whitespace-nowrap rounded bg-indigo-600 px-3 py-2 text-xs font-semibold text-white hover:bg-indigo-700"
                                                    @click="downloadAdventurerYearly(club, application)"
                                                >
                                                    {{ tr('Generar Word', 'Generate Word') }}
                                                </button>
                                            </td>
                                            <td class="px-3 py-3">
                                                <div class="flex min-w-[260px] gap-2">
                                                    <input
                                                        v-model="adventurerYearlyEmailByApplication[application.id]"
                                                        type="email"
                                                        class="min-w-0 flex-1 rounded border p-2 text-sm"
                                                        :placeholder="tr('Correo destino', 'Recipient email')"
                                                    />
                                                    <button
                                                        type="button"
                                                        class="rounded bg-emerald-700 px-3 py-2 text-xs font-semibold text-white hover:bg-emerald-800 disabled:cursor-not-allowed disabled:opacity-60"
                                                        :disabled="sendingAdventurerYearlyByApplication[application.id] || !adventurerSignaturesComplete(application)"
                                                        @click="sendAdventurerYearly(club, application)"
                                                    >
                                                        {{ sendingAdventurerYearlyByApplication[application.id] ? tr('Enviando...', 'Sending...') : tr('Enviar', 'Send') }}
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                            <p v-else class="rounded bg-amber-50 px-3 py-2 text-sm text-amber-800">
                                {{ tr('Envía el formulario para habilitar la generación del documento Word.', 'Submit the form to enable Word document generation.') }}
                            </p>

                            <div
                                v-for="application in getAdventurerYearlyApplications(club)"
                                :key="`adventurer-signatures-${application.id}`"
                                class="mt-5 rounded border border-gray-200 p-4"
                            >
                                <div class="mb-4 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                                    <div>
                                        <h5 class="font-semibold text-gray-900">{{ tr('Firmas de la solicitud', 'Application signatures') }} {{ application.application_year }}</h5>
                                        <p class="text-xs text-gray-500">{{ tr('Los enlaces públicos vencen 30 días después de enviarse.', 'Public links expire 30 days after they are sent.') }}</p>
                                    </div>
                                    <span class="w-fit rounded px-2 py-1 text-xs font-semibold" :class="adventurerSignaturesComplete(application) ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-800'">
                                        {{ adventurerSignaturesComplete(application) ? tr('Completa', 'Complete') : tr('Pendiente', 'Pending') }}
                                    </span>
                                </div>

                                <div class="grid gap-4 lg:grid-cols-2">
                                    <div class="rounded border border-gray-200 bg-gray-50 p-4">
                                        <div class="flex items-center justify-between gap-3">
                                            <h6 class="font-semibold text-gray-900">{{ adventurerRoleLabel('director') }}</h6>
                                            <span class="rounded px-2 py-1 text-xs font-semibold" :class="adventurerSignatureStatusClass(adventurerSignature(application, 'director'))">
                                                {{ adventurerSignatureStatusLabel(adventurerSignature(application, 'director')) }}
                                            </span>
                                        </div>
                                        <input v-model="adventurerSignerDraft(club, application, 'director').signer_name" type="text" class="mt-3 w-full rounded border p-2 text-sm" :placeholder="tr('Nombre del director', 'Director name')" />
                                        <div class="mt-3 inline-flex rounded bg-gray-200 p-1 text-xs">
                                            <button type="button" class="rounded px-3 py-1" :class="(adventurerDirectorSignatureMode[application.id] || 'typed') === 'typed' ? 'bg-white font-semibold shadow-sm' : ''" @click="adventurerDirectorSignatureMode[application.id] = 'typed'">{{ tr('Escrita', 'Typed') }}</button>
                                            <button type="button" class="rounded px-3 py-1" :class="adventurerDirectorSignatureMode[application.id] === 'drawn' ? 'bg-white font-semibold shadow-sm' : ''" @click="() => { adventurerDirectorSignatureMode[application.id] = 'drawn'; nextTick(() => configureAdventurerDirectorCanvas(application.id)) }">{{ tr('Dibujada', 'Drawn') }}</button>
                                        </div>
                                        <div v-if="adventurerDirectorSignatureMode[application.id] === 'drawn'" class="mt-3">
                                            <canvas
                                                :ref="element => setAdventurerDirectorCanvas(application.id, element)"
                                                class="h-32 w-full touch-none rounded border bg-white"
                                                @pointerdown.prevent="startAdventurerDirectorSignature(application.id, $event)"
                                                @pointermove.prevent="drawAdventurerDirectorSignature(application.id, $event)"
                                                @pointerup.prevent="adventurerDirectorDrawing[application.id] = false"
                                                @pointerleave.prevent="adventurerDirectorDrawing[application.id] = false"
                                            ></canvas>
                                            <button type="button" class="mt-2 text-xs font-semibold text-gray-600" @click="configureAdventurerDirectorCanvas(application.id)">{{ tr('Limpiar firma', 'Clear signature') }}</button>
                                        </div>
                                        <img v-if="adventurerSignature(application, 'director').signature_url" :src="adventurerSignature(application, 'director').signature_url" alt="" class="mt-3 max-h-20 rounded border bg-white" />
                                        <button type="button" class="mt-3 rounded bg-blue-700 px-3 py-2 text-sm font-semibold text-white disabled:opacity-60" :disabled="savingAdventurerDirectorSignature[application.id]" @click="saveAdventurerDirector(club, application)">
                                            {{ savingAdventurerDirectorSignature[application.id] ? tr('Guardando...', 'Saving...') : tr('Guardar firma', 'Save signature') }}
                                        </button>
                                    </div>

                                    <div
                                        v-for="role in ['pastor', 'head_elder', 'church_clerk']"
                                        :key="`${application.id}-${role}`"
                                        class="rounded border border-gray-200 bg-gray-50 p-4"
                                    >
                                        <div class="flex items-center justify-between gap-3">
                                            <h6 class="font-semibold text-gray-900">{{ adventurerRoleLabel(role) }}</h6>
                                            <span class="rounded px-2 py-1 text-xs font-semibold" :class="adventurerSignatureStatusClass(adventurerSignature(application, role))">
                                                {{ adventurerSignatureStatusLabel(adventurerSignature(application, role)) }}
                                            </span>
                                        </div>
                                        <input v-model="adventurerSignerDraft(club, application, role).signer_name" type="text" class="mt-3 w-full rounded border p-2 text-sm" :placeholder="tr('Nombre del firmante', 'Signer name')" />
                                        <input v-model="adventurerSignerDraft(club, application, role).signer_email" type="email" class="mt-2 w-full rounded border p-2 text-sm" :placeholder="tr('Correo del firmante', 'Signer email')" />
                                        <img v-if="adventurerSignature(application, role).signature_url" :src="adventurerSignature(application, role).signature_url" alt="" class="mt-3 max-h-20 rounded border bg-white" />
                                        <button type="button" class="mt-3 rounded bg-amber-600 px-3 py-2 text-sm font-semibold text-white disabled:opacity-60" :disabled="requestingAdventurerSignature[`${application.id}-${role}`]" @click="requestAdventurerSignature(club, application, role)">
                                            {{ requestingAdventurerSignature[`${application.id}-${role}`] ? tr('Enviando...', 'Sending...') : tr('Enviar enlace de firma', 'Send signature link') }}
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </details>

            <details v-if="pathfinderClubs.length" class="border rounded">
                <summary class="bg-gray-100 px-4 py-2 font-semibold cursor-pointer">{{ tr('Aplicacion anual', 'Annual Application') }}</summary>
                <div class="space-y-4 p-4">
                    <div
                        v-for="club in pathfinderClubs"
                        :key="`annual-application-${club.id}`"
                        class="rounded border bg-white p-4"
                    >
                        <div class="mb-4 flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                            <div>
                                <h3 class="text-lg font-semibold text-gray-900">{{ club.club_name }}</h3>
                                <p class="text-sm text-gray-600">{{ tr('Formulario anual para clubes de Conquistadores.', 'Annual form for Pathfinder clubs.') }}</p>
                            </div>
                            <div class="flex flex-col gap-2 sm:items-end">
                                <span class="inline-flex w-fit rounded bg-gray-100 px-3 py-1 text-sm font-medium text-gray-700">
                                    {{ getAnnualApplicationDraft(club).application_year }}
                                </span>
                                <label class="inline-flex items-center gap-2 text-sm text-gray-700">
                                    <input
                                        v-model="annualApplicationJotformModeByClub[club.id]"
                                        type="checkbox"
                                        class="rounded border-gray-300 text-blue-600 focus:ring-blue-500"
                                    />
                                    {{ tr('Usar Jotform', 'Use Jotform') }}
                                </label>
                            </div>
                        </div>

                        <div v-if="annualApplicationJotformModeByClub[club.id]" class="overflow-hidden rounded border border-gray-200 bg-white">
                            <div class="border-b border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                                {{ tr('Al usar Jotform, las respuestas no se guardan en este sistema. No apareceran en el historial, no generaran el PDF interno y no usaran el flujo de firmas del portal.', 'Using Jotform means responses are not stored in this system. They will not appear in history, generate the internal PDF, or use the portal signature workflow.') }}
                            </div>
                            <iframe
                                :src="annualApplicationJotformUrl"
                                title="Pathfinder Club Yearly Application Jotform"
                                class="h-[780px] w-full border-0"
                            ></iframe>
                        </div>

                        <template v-else>
                        <div class="mb-5 border-b pb-4">
                            <div class="mb-2 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                                <div>
                                    <h4 class="text-sm font-semibold text-gray-900">{{ tr('Aplicaciones historicas', 'Historical applications') }}</h4>
                                    <p class="text-xs text-gray-500">
                                        {{ tr('El sistema mantiene una aplicacion por club y año.', 'The system keeps one application per club and year.') }}
                                    </p>
                                </div>
                                <button
                                    type="button"
                                    class="w-fit rounded bg-gray-700 px-3 py-2 text-sm text-white hover:bg-gray-800"
                                    @click="cleanAnnualApplicationForm(club)"
                                >
                                    {{ tr('Limpiar formulario actual', 'Clear current form') }}
                                </button>
                            </div>

                            <div v-if="getAnnualApplications(club).length" class="overflow-hidden rounded border border-gray-200">
                                <div class="hidden grid-cols-[1fr_1fr_1fr_auto] gap-3 bg-gray-50 px-3 py-2 text-xs font-semibold uppercase text-gray-600 md:grid">
                                    <span>{{ tr('Año', 'Year') }}</span>
                                    <span>{{ tr('Estado', 'Status') }}</span>
                                    <span>{{ tr('Ultimo envio', 'Last sent') }}</span>
                                    <span class="text-right">{{ tr('Accion', 'Action') }}</span>
                                </div>
                                <div
                                    v-for="application in getAnnualApplications(club)"
                                    :key="`annual-application-row-${application.id}`"
                                    class="grid gap-2 border-t border-gray-200 px-3 py-3 text-sm md:grid-cols-[1fr_1fr_1fr_auto] md:items-center"
                                    :class="Number(getAnnualApplicationDraft(club).id) === Number(application.id) ? 'bg-blue-50' : 'bg-white'"
                                >
                                    <div>
                                        <div class="font-semibold text-gray-900">{{ application.application_year }}</div>
                                        <div v-if="application.application_year === currentApplicationYear" class="text-xs text-blue-700">
                                            {{ tr('Año actual', 'Current year') }}
                                        </div>
                                    </div>
                                    <div>
                                        <span
                                            class="rounded px-2 py-1 text-xs font-semibold"
                                            :class="(application.signatures_complete ?? hasCompleteAnnualApplicationSignatures(application)) ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-800'"
                                        >
                                            {{ (application.signatures_complete ?? hasCompleteAnnualApplicationSignatures(application)) ? tr('Completa', 'Complete') : tr('Pendiente', 'Pending') }}
                                        </span>
                                    </div>
                                    <div class="text-gray-700">
                                        <span v-if="application.last_sent_to_email">{{ application.last_sent_to_email }}</span>
                                        <span v-else class="text-gray-500">{{ tr('No enviada', 'Not sent') }}</span>
                                        <div v-if="application.sent_at" class="text-xs text-gray-500">{{ application.sent_at }}</div>
                                    </div>
                                    <div class="flex justify-start md:justify-end">
                                        <button
                                            type="button"
                                            class="rounded border border-blue-600 px-3 py-1 text-sm font-medium text-blue-700 hover:bg-blue-50"
                                            @click="selectAnnualApplication(club, application)"
                                        >
                                            {{ Number(getAnnualApplicationDraft(club).id) === Number(application.id) ? tr('Abierta', 'Open') : tr('Abrir', 'Open') }}
                                        </button>
                                    </div>
                                </div>
                            </div>
                            <p v-else class="rounded border border-dashed border-gray-300 px-3 py-3 text-sm text-gray-500">
                                {{ tr('No hay aplicaciones anuales guardadas todavia.', 'No annual applications saved yet.') }}
                            </p>

                            <p v-if="!findAnnualApplication(club, currentApplicationYear)" class="mt-3 rounded border border-blue-200 bg-blue-50 px-3 py-2 text-sm text-blue-800">
                                {{ tr('No hay aplicacion guardada para el año actual. El formulario esta listo para crear una nueva.', 'No saved application exists for the current year. The form is ready to create a new one.') }}
                            </p>
                        </div>

                        <div class="mb-4 max-w-xs">
                            <label class="block text-sm font-medium text-gray-700">{{ tr('Año de aplicacion', 'Application year') }}</label>
                            <select
                                v-model="getAnnualApplicationDraft(club).application_year"
                                class="mt-1 w-full rounded border p-2"
                                @change="event => selectAnnualApplicationYear(club, event.target.value)"
                            >
                                <option
                                    v-for="year in annualApplicationYearOptions(club)"
                                    :key="year"
                                    :value="year"
                                >
                                    {{ year }}
                                </option>
                            </select>
                        </div>

                        <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
                            <div>
                                <label class="block text-sm font-medium text-gray-700">{{ tr('Iglesia patrocinadora', 'Sponsoring church') }}</label>
                                <input
                                    v-model="getAnnualApplicationDraft(club).sponsoring_church"
                                    type="text"
                                    class="mt-1 w-full rounded border p-2"
                                />
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700">{{ tr('Pastor', 'Pastor') }}</label>
                                <input
                                    v-model="getAnnualApplicationDraft(club).pastor"
                                    type="text"
                                    class="mt-1 w-full rounded border p-2"
                                />
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700">{{ tr('Director electo', 'Elected club director') }}</label>
                                <input
                                    v-model="getAnnualApplicationDraft(club).elected_club_director"
                                    type="text"
                                    class="mt-1 w-full rounded border p-2"
                                />
                            </div>
                            <div class="md:col-span-2">
                                <label class="block text-sm font-medium text-gray-700">{{ tr('Direccion postal', 'Mailing address') }}</label>
                                <input
                                    v-model="getAnnualApplicationDraft(club).mailing_address"
                                    type="text"
                                    class="mt-1 w-full rounded border p-2"
                                />
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700">{{ tr('Telefono del director', 'Director phone number') }}</label>
                                <input
                                    v-model="getAnnualApplicationDraft(club).director_phone_number"
                                    type="text"
                                    class="mt-1 w-full rounded border p-2"
                                />
                            </div>
                        </div>

                        <div class="mt-5">
                            <div class="mb-5 space-y-4 rounded border border-gray-200 bg-gray-50 p-4 text-sm leading-6 text-gray-800">
                                <div>
                                    <h4 class="font-semibold text-gray-900">{{ tr('The Philosophy of Pathfindering', 'The Philosophy of Pathfindering') }}</h4>
                                    <p class="mt-2">
                                        The purpose of having a Pathfinder Club is to lead its membership into a growing, redemptive relationship with Christ, and to build its membership into responsible, mature individuals and to involve its membership in active selfless service. All Pathfinder leaders are Christians, working hand in hand with parents, teachers, and pastors providing optimum opportunities for Christian development, The Pathfinder Club is an extension of the home, school and church, it is an experiential environment where growth and learning flourish. The membership involves youth in grades 5-10 who have a desire for group activities ranging from community and world mission projects to nature, out door work and camping activities
                                    </p>
                                    <p class="mt-3">
                                        AY Pathfindering class curriculum and AY Honors. Above all, Pathfindering gives youth an environment in which to actively expand their personal experience with Christ.
                                    </p>
                                </div>
                                <div>
                                    <h4 class="font-semibold text-gray-900">{{ tr('Your Commitment to Pathfindering', 'Your Commitment to Pathfindering') }}</h4>
                                    <p class="mt-2">
                                        We, the undersigned, have read, understand, and are in full agreement with the above Philosophy of Pathfindering and agree to support our club through those means with which the Lord has blessed this church, including finances, staff volunteers, securing a place to meet, transportation on outings, and other such needs as my arise in the fulfillment of this ministry, and to assist and support the work of the Pathfinder ministry in this conference and around the world.
                                    </p>
                                </div>
                            </div>
                            <h4 class="text-sm font-semibold text-gray-900">{{ tr('Firmas', 'Signatures') }}</h4>
                            <div class="mt-3">
                                <label class="block text-sm font-medium text-gray-700">{{ tr('Fecha de aprobacion de junta', 'Date of Board Approval') }}</label>
                                <input v-model="getAnnualApplicationDraft(club).board_approval_date" type="date" class="mt-1 w-full rounded border p-2 md:max-w-xs" />
                            </div>

                            <div class="mt-4 grid grid-cols-1 gap-4 xl:grid-cols-3">
                                <div class="rounded border border-gray-200 bg-white p-4">
                                    <div class="flex items-start justify-between gap-3">
                                        <div>
                                            <h5 class="font-semibold text-gray-900">{{ tr('Director del club', 'Club director') }}</h5>
                                            <p class="text-xs text-gray-500">{{ user?.email || '' }}</p>
                                        </div>
                                        <span class="rounded px-2 py-1 text-xs font-semibold" :class="signatureStatusClass(annualApplicationSignature(club, 'director'))">
                                            {{ signatureStatusLabel(annualApplicationSignature(club, 'director')) }}
                                        </span>
                                    </div>

                                    <div class="mt-3">
                                        <label class="block text-sm font-medium text-gray-700">{{ tr('Nombre del firmante', 'Signer name') }}</label>
                                        <input v-model="getAnnualApplicationDraft(club).club_director_signature" type="text" class="mt-1 w-full rounded border p-2" />
                                    </div>

                                    <div class="mt-3 inline-flex rounded border border-gray-300 bg-gray-50 p-1 text-sm">
                                        <button
                                            type="button"
                                            class="rounded px-3 py-1"
                                            :class="(annualApplicationDirectorSignatureModeByClub[club.id] || 'typed') === 'typed' ? 'bg-white font-semibold shadow-sm' : 'text-gray-600'"
                                            @click="annualApplicationDirectorSignatureModeByClub[club.id] = 'typed'"
                                        >
                                            {{ tr('Texto', 'Typed') }}
                                        </button>
                                        <button
                                            type="button"
                                            class="rounded px-3 py-1"
                                            :class="annualApplicationDirectorSignatureModeByClub[club.id] === 'drawn' ? 'bg-white font-semibold shadow-sm' : 'text-gray-600'"
                                            @click="() => { annualApplicationDirectorSignatureModeByClub[club.id] = 'drawn'; nextTick(() => configureDirectorSignatureCanvas(club.id)) }"
                                        >
                                            {{ tr('Dibujar', 'Draw') }}
                                        </button>
                                    </div>

                                    <div v-if="annualApplicationDirectorSignatureModeByClub[club.id] === 'drawn'" class="mt-3">
                                        <div class="mb-2 flex items-center justify-between">
                                            <label class="text-sm font-medium text-gray-700">{{ tr('Firma', 'Signature') }}</label>
                                            <button type="button" class="text-xs font-semibold text-gray-600 hover:text-gray-950" @click="configureDirectorSignatureCanvas(club.id)">
                                                {{ tr('Limpiar', 'Clear') }}
                                            </button>
                                        </div>
                                        <canvas
                                            :ref="element => setDirectorSignatureCanvas(club.id, element)"
                                            class="h-40 w-full touch-none rounded border border-gray-300 bg-white"
                                            @pointerdown.prevent="event => startDirectorSignature(club.id, event)"
                                            @pointermove.prevent="event => drawDirectorSignature(club.id, event)"
                                            @pointerup.prevent="() => stopDirectorSignature(club.id)"
                                            @pointercancel.prevent="() => stopDirectorSignature(club.id)"
                                            @pointerleave.prevent="() => stopDirectorSignature(club.id)"
                                        ></canvas>
                                    </div>

                                    <img
                                        v-if="annualApplicationSignature(club, 'director').signature_url"
                                        :src="annualApplicationSignature(club, 'director').signature_url"
                                        alt="Firma del director"
                                        class="mt-3 max-h-20 rounded border bg-white"
                                    />

                                    <button
                                        type="button"
                                        class="mt-3 w-full rounded bg-blue-600 px-4 py-2 text-sm text-white hover:bg-blue-700 disabled:opacity-60"
                                        :disabled="savingDirectorSignatureByClub[club.id]"
                                        @click="saveDirectorSignature(club)"
                                    >
                                        {{ savingDirectorSignatureByClub[club.id] ? tr('Guardando firma...', 'Saving signature...') : tr('Guardar firma', 'Save signature') }}
                                    </button>
                                </div>

                                <div class="rounded border border-gray-200 bg-white p-4">
                                    <div class="flex items-start justify-between gap-3">
                                        <div>
                                            <h5 class="font-semibold text-gray-900">{{ tr('Pastor de iglesia', 'Church pastor') }}</h5>
                                            <p class="text-xs text-gray-500">{{ annualApplicationSignature(club, 'pastor').signer_email || club?.church?.pastor_email || tr('Sin correo configurado', 'No email configured') }}</p>
                                        </div>
                                        <span class="rounded px-2 py-1 text-xs font-semibold" :class="signatureStatusClass(annualApplicationSignature(club, 'pastor'))">
                                            {{ signatureStatusLabel(annualApplicationSignature(club, 'pastor')) }}
                                        </span>
                                    </div>
                                    <input
                                        v-model="annualApplicationPastorEmailByClub[club.id]"
                                        type="email"
                                        class="mt-3 w-full rounded border p-2"
                                        :placeholder="tr('Correo del pastor', 'Pastor email')"
                                    />
                                    <img
                                        v-if="annualApplicationSignature(club, 'pastor').signature_url"
                                        :src="annualApplicationSignature(club, 'pastor').signature_url"
                                        alt="Firma del pastor"
                                        class="mt-3 max-h-20 rounded border bg-white"
                                    />
                                    <button
                                        type="button"
                                        class="mt-3 w-full rounded bg-amber-600 px-4 py-2 text-sm text-white hover:bg-amber-700 disabled:opacity-60"
                                        :disabled="requestingAnnualApplicationSignatureByClub[`${club.id}-pastor`] || !getAnnualApplicationDraft(club).id"
                                        @click="requestAnnualApplicationSignature(club, 'pastor')"
                                    >
                                        {{ requestingAnnualApplicationSignatureByClub[`${club.id}-pastor`] ? tr('Enviando...', 'Sending...') : tr('Enviar para firma', 'Send for signature') }}
                                    </button>
                                </div>

                                <div class="rounded border border-gray-200 bg-white p-4">
                                    <div class="flex items-start justify-between gap-3">
                                        <div>
                                            <h5 class="font-semibold text-gray-900">{{ tr('Primer anciano', 'Head elder') }}</h5>
                                            <p class="text-xs text-gray-500">{{ annualApplicationSignature(club, 'head_elder').signer_email || tr('Correo manual', 'Manual email') }}</p>
                                        </div>
                                        <span class="rounded px-2 py-1 text-xs font-semibold" :class="signatureStatusClass(annualApplicationSignature(club, 'head_elder'))">
                                            {{ signatureStatusLabel(annualApplicationSignature(club, 'head_elder')) }}
                                        </span>
                                    </div>
                                    <input
                                        v-model="annualApplicationHeadElderNameByClub[club.id]"
                                        type="text"
                                        class="mt-3 w-full rounded border p-2"
                                        :placeholder="tr('Nombre del primer anciano', 'Head elder name')"
                                    />
                                    <input
                                        v-model="annualApplicationHeadElderEmailByClub[club.id]"
                                        type="email"
                                        class="mt-2 w-full rounded border p-2"
                                        :placeholder="tr('Correo del primer anciano', 'Head elder email')"
                                    />
                                    <img
                                        v-if="annualApplicationSignature(club, 'head_elder').signature_url"
                                        :src="annualApplicationSignature(club, 'head_elder').signature_url"
                                        alt="Firma del primer anciano"
                                        class="mt-3 max-h-20 rounded border bg-white"
                                    />
                                    <button
                                        type="button"
                                        class="mt-3 w-full rounded bg-amber-600 px-4 py-2 text-sm text-white hover:bg-amber-700 disabled:opacity-60"
                                        :disabled="requestingAnnualApplicationSignatureByClub[`${club.id}-head_elder`] || !getAnnualApplicationDraft(club).id"
                                        @click="requestAnnualApplicationSignature(club, 'head_elder')"
                                    >
                                        {{ requestingAnnualApplicationSignatureByClub[`${club.id}-head_elder`] ? tr('Enviando...', 'Sending...') : tr('Enviar para firma', 'Send for signature') }}
                                    </button>
                                </div>
                            </div>
                        </div>

                        <div class="mt-5 grid gap-3 border-t pt-4 lg:grid-cols-[auto_auto_1fr_auto] lg:items-center">
                            <button
                                type="button"
                                class="rounded bg-blue-600 px-4 py-2 text-sm text-white hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-60"
                                :disabled="savingAnnualApplicationByClub[club.id]"
                                @click="saveAnnualApplication(club)"
                            >
                                {{ savingAnnualApplicationByClub[club.id] ? tr('Guardando...', 'Saving...') : tr('Guardar aplicacion', 'Save application') }}
                            </button>
                            <button
                                type="button"
                                class="rounded bg-gray-700 px-4 py-2 text-sm text-white hover:bg-gray-800 disabled:cursor-not-allowed disabled:opacity-60"
                                :disabled="!getAnnualApplicationDraft(club).id"
                                @click="downloadAnnualApplication(club)"
                            >
                                {{ tr('Descargar PDF', 'Download PDF') }}
                            </button>
                            <input
                                v-model="annualApplicationEmailByClub[club.id]"
                                type="email"
                                class="w-full rounded border p-2"
                                :placeholder="tr('Correo destino', 'Recipient email')"
                            />
                            <button
                                type="button"
                                class="rounded bg-emerald-700 px-4 py-2 text-sm text-white hover:bg-emerald-800 disabled:cursor-not-allowed disabled:opacity-60"
                                :disabled="sendingAnnualApplicationByClub[club.id] || !getAnnualApplicationDraft(club).id || !getAnnualApplicationDraft(club).signatures_complete"
                                @click="sendAnnualApplication(club)"
                            >
                                {{ sendingAnnualApplicationByClub[club.id] ? tr('Enviando...', 'Sending...') : tr('Enviar', 'Send') }}
                            </button>
                        </div>

                        <p v-if="!getAnnualApplicationDraft(club).id" class="mt-2 text-sm text-amber-700">
                            {{ tr('Primero guarda la aplicacion para habilitar envio y firmas externas.', 'Save the application first to enable sending and external signatures.') }}
                        </p>
                        <p v-else-if="!getAnnualApplicationDraft(club).signatures_complete" class="mt-2 text-sm text-amber-700">
                            {{ tr('Para enviar, primero completa las tres firmas requeridas.', 'Complete all three required signatures before sending.') }}
                        </p>

                        <p v-if="getAnnualApplicationDraft(club).last_sent_to_email" class="mt-2 text-xs text-gray-500">
                            {{ tr('Ultimo envio', 'Last sent') }}:
                            {{ getAnnualApplicationDraft(club).last_sent_to_email }}
                            <span v-if="getAnnualApplicationDraft(club).sent_at">({{ getAnnualApplicationDraft(club).sent_at }})</span>
                        </p>
                        </template>
                    </div>
                </div>
            </details>

            <details v-if="pathfinderMonthlyReportClubs.length" class="border rounded">
                <summary class="bg-gray-100 px-4 py-2 font-semibold cursor-pointer">{{ tr('Reporte mensual', 'Monthly Report') }}</summary>
                <div class="space-y-4 p-4">
                    <div
                        v-for="club in pathfinderMonthlyReportClubs"
                        :key="`monthly-report-${club.id}`"
                        class="rounded border bg-white p-4"
                    >
                        <div class="mb-4 flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                            <div>
                                <h3 class="text-lg font-semibold text-gray-900">{{ club.club_name }}</h3>
                                <p class="text-sm text-gray-600">{{ tr('Reporte mensual de actividades Pathfinder.', 'Monthly Pathfinder activity report.') }}</p>
                            </div>
                            <div class="flex flex-col gap-2 sm:items-end">
                                <span class="inline-flex w-fit rounded bg-gray-100 px-3 py-1 text-sm font-medium text-gray-700">
                                    {{ getMonthlyReportDraft(club).report_month }} {{ getMonthlyReportDraft(club).report_year }}
                                </span>
                                <label class="inline-flex items-center gap-2 text-sm text-gray-700">
                                    <input
                                        v-model="monthlyReportJotformModeByClub[club.id]"
                                        type="checkbox"
                                        class="rounded border-gray-300 text-blue-600 focus:ring-blue-500"
                                    />
                                    {{ tr('Usar Jotform', 'Use Jotform') }}
                                </label>
                            </div>
                        </div>

                        <div v-if="monthlyReportJotformModeByClub[club.id]" class="overflow-hidden rounded border border-gray-200 bg-white">
                            <div class="border-b border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                                {{ tr('Al usar Jotform, las respuestas no se guardan en este sistema. No apareceran en el historial de reportes ni tendran seguimiento interno.', 'Using Jotform means responses are not stored in this system. They will not appear in report history or have internal accountability tracking.') }}
                            </div>
                            <iframe
                                :src="monthlyReportJotformUrl"
                                title="Pathfinder Club Monthly Report Jotform"
                                class="h-[780px] w-full border-0"
                            ></iframe>
                        </div>

                        <template v-else>
                        <div class="mb-5 border-b pb-4">
                            <div class="mb-2 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                                <h4 class="text-sm font-semibold text-gray-900">{{ tr('Reportes enviados', 'Submitted reports') }}</h4>
                                <button
                                    type="button"
                                    class="w-fit rounded bg-gray-700 px-3 py-2 text-sm text-white hover:bg-gray-800"
                                    @click="startNewMonthlyReport(club)"
                                >
                                    {{ tr('Nuevo reporte', 'New report') }}
                                </button>
                            </div>

                            <div v-if="getMonthlyReports(club).length" class="overflow-hidden rounded border border-gray-200">
                                <div class="hidden grid-cols-[1fr_1fr_1fr_auto] gap-3 bg-gray-50 px-3 py-2 text-xs font-semibold uppercase text-gray-600 md:grid">
                                    <span>{{ tr('Periodo', 'Period') }}</span>
                                    <span>{{ tr('Ultimo envio', 'Last sent') }}</span>
                                    <span>{{ tr('Evidencias', 'Evidence') }}</span>
                                    <span class="text-right">{{ tr('Accion', 'Action') }}</span>
                                </div>
                                <div
                                    v-for="report in getMonthlyReports(club)"
                                    :key="`monthly-report-row-${report.id}`"
                                    class="grid gap-2 border-t border-gray-200 px-3 py-3 text-sm md:grid-cols-[1fr_1fr_1fr_auto] md:items-center"
                                    :class="Number(getMonthlyReportDraft(club).id) === Number(report.id) ? 'bg-blue-50' : 'bg-white'"
                                >
                                    <div>
                                        <div class="font-semibold text-gray-900">{{ report.report_month }} {{ report.report_year }}</div>
                                        <div class="text-xs text-gray-500 md:hidden">{{ tr('Periodo', 'Period') }}</div>
                                    </div>
                                    <div class="text-gray-700">
                                        <span v-if="report.last_sent_to_email">{{ report.last_sent_to_email }}</span>
                                        <span v-else class="text-gray-500">{{ tr('No enviado', 'Not sent') }}</span>
                                        <div v-if="report.sent_at" class="text-xs text-gray-500">{{ report.sent_at }}</div>
                                    </div>
                                    <div class="text-gray-700">
                                        {{ (report.attachments || []).length }}
                                    </div>
                                    <div class="flex justify-start md:justify-end">
                                        <button
                                            type="button"
                                            class="rounded border border-blue-600 px-3 py-1 text-sm font-medium text-blue-700 hover:bg-blue-50"
                                            @click="selectMonthlyReport(club, report)"
                                        >
                                            {{ Number(getMonthlyReportDraft(club).id) === Number(report.id) ? tr('Abierto', 'Open') : tr('Abrir', 'Open') }}
                                        </button>
                                    </div>
                                </div>
                            </div>
                            <p v-else class="rounded border border-dashed border-gray-300 px-3 py-3 text-sm text-gray-500">
                                {{ tr('No hay reportes mensuales guardados todavia.', 'No monthly reports saved yet.') }}
                            </p>
                        </div>

                        <div class="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-3">
                            <div>
                                <label class="block text-sm font-medium text-gray-700">{{ tr('Nombre completo', 'Full Name') }}</label>
                                <input v-model="getMonthlyReportDraft(club).full_name" type="text" class="mt-1 w-full rounded border p-2" />
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700">{{ tr('Correo', 'Email') }}</label>
                                <input v-model="getMonthlyReportDraft(club).email" type="email" class="mt-1 w-full rounded border p-2" />
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700">{{ tr('Area', 'Area') }}</label>
                                <select v-model="getMonthlyReportDraft(club).area" class="mt-1 w-full rounded border p-2">
                                    <option value="">{{ tr('Selecciona area', 'Select area') }}</option>
                                    <option v-for="area in monthlyReportAreas" :key="area" :value="area">{{ area }}</option>
                                </select>
                            </div>
                            <div class="md:col-span-2">
                                <label class="block text-sm font-medium text-gray-700">{{ tr('Iglesia y club', 'Church AND Club Name') }}</label>
                                <input v-model="getMonthlyReportDraft(club).church_and_club_name" type="text" class="mt-1 w-full rounded border p-2" />
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700">{{ tr('Año', 'Year') }}</label>
                                <input v-model="getMonthlyReportDraft(club).report_year" type="text" class="mt-1 w-full rounded border p-2" />
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700">{{ tr('Mes reportado', 'Month Reporting On') }}</label>
                                <select v-model="getMonthlyReportDraft(club).report_month" class="mt-1 w-full rounded border p-2">
                                    <option v-for="month in monthlyReportMonths" :key="month" :value="month">{{ month }}</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700"># {{ tr('Pathfinders', 'Pathfinders') }}</label>
                                <input v-model="getMonthlyReportDraft(club).pathfinders_count" type="number" min="0" class="mt-1 w-full rounded border p-2" />
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700"># TLT's</label>
                                <input v-model="getMonthlyReportDraft(club).tlt_count" type="number" min="0" class="mt-1 w-full rounded border p-2" />
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700"># {{ tr('Staff', 'Staff') }}</label>
                                <input v-model="getMonthlyReportDraft(club).staff_count" type="number" min="0" class="mt-1 w-full rounded border p-2" />
                            </div>
                        </div>

                        <div class="mt-5">
                            <h4 class="text-sm font-semibold text-gray-900">{{ tr('Informacion del mes', "This Month's Meeting Info") }}</h4>
                            <div class="mt-3 grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700"># {{ tr('Reuniones', 'Meetings') }}</label>
                                    <input v-model="getMonthlyReportDraft(club).meetings_count" type="number" min="0" class="mt-1 w-full rounded border p-2" />
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700"># {{ tr('Estudios biblicos', 'Bible Studies') }}</label>
                                    <input v-model="getMonthlyReportDraft(club).bible_studies_count" type="number" min="0" class="mt-1 w-full rounded border p-2" />
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700"># {{ tr('Bautismos', 'Baptisms') }}</label>
                                    <input v-model="getMonthlyReportDraft(club).baptisms_count" type="number" min="0" class="mt-1 w-full rounded border p-2" />
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700"># {{ tr('Campamentos', 'Campouts') }}</label>
                                    <input v-model="getMonthlyReportDraft(club).campouts_count" type="number" min="0" class="mt-1 w-full rounded border p-2" />
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700"># {{ tr('Salidas', 'Field Trips') }}</label>
                                    <input v-model="getMonthlyReportDraft(club).field_trips_count" type="number" min="0" class="mt-1 w-full rounded border p-2" />
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700"># {{ tr('Honores', 'Honors Completed') }}</label>
                                    <input v-model="getMonthlyReportDraft(club).honors_completed_count" type="number" min="0" class="mt-1 w-full rounded border p-2" />
                                </div>
                            </div>
                        </div>

                        <div class="mt-5 grid grid-cols-1 gap-3 lg:grid-cols-3">
                            <div>
                                <label class="block text-sm font-medium text-gray-700">{{ tr('Honores completados', 'Pathfinder Honors completed') }}</label>
                                <textarea v-model="getMonthlyReportDraft(club).honors_completed_list" rows="4" class="mt-1 w-full rounded border p-2"></textarea>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700">{{ tr('Actividades de alcance', 'Outreach activities') }}</label>
                                <textarea v-model="getMonthlyReportDraft(club).outreach_activities" rows="4" class="mt-1 w-full rounded border p-2"></textarea>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700">{{ tr('Actividades destacadas', 'Notable Pathfinder activities') }}</label>
                                <textarea v-model="getMonthlyReportDraft(club).notable_activities" rows="4" class="mt-1 w-full rounded border p-2"></textarea>
                            </div>
                        </div>

                        <div class="mt-5 grid grid-cols-1 gap-4 lg:grid-cols-2">
                            <div class="rounded border border-gray-200 bg-gray-50 p-4">
                                <label class="block text-sm font-semibold text-gray-900">{{ tr('Pruebas de voluntarios verificados', 'Verified Volunteer proof') }}</label>
                                <input
                                    type="file"
                                    multiple
                                    class="mt-2 w-full rounded border bg-white p-2 text-sm"
                                    @change="event => setMonthlyReportFiles(monthlyReportVolunteerFilesByClub, club.id, event)"
                                />
                                <p v-if="selectedMonthlyReportFileCount(monthlyReportVolunteerFilesByClub, club.id)" class="mt-2 text-xs text-blue-700">
                                    {{ selectedMonthlyReportFileCount(monthlyReportVolunteerFilesByClub, club.id) }} {{ tr('archivo(s) listos para guardar', 'file(s) ready to save') }}
                                </p>
                                <div v-if="monthlyReportAttachments(club, 'volunteer_proof').length" class="mt-3 space-y-1 text-sm">
                                    <a
                                        v-for="attachment in monthlyReportAttachments(club, 'volunteer_proof')"
                                        :key="attachment.id"
                                        :href="attachment.url"
                                        target="_blank"
                                        class="block truncate text-blue-700 hover:underline"
                                    >
                                        {{ attachment.original_name }}
                                    </a>
                                </div>
                            </div>

                            <div class="rounded border border-gray-200 bg-gray-50 p-4">
                                <label class="block text-sm font-semibold text-gray-900">{{ tr('Fotos de actividades/eventos', "This month's activities/events pictures") }}</label>
                                <input
                                    type="file"
                                    multiple
                                    class="mt-2 w-full rounded border bg-white p-2 text-sm"
                                    @change="event => setMonthlyReportFiles(monthlyReportActivityFilesByClub, club.id, event)"
                                />
                                <p v-if="selectedMonthlyReportFileCount(monthlyReportActivityFilesByClub, club.id)" class="mt-2 text-xs text-blue-700">
                                    {{ selectedMonthlyReportFileCount(monthlyReportActivityFilesByClub, club.id) }} {{ tr('archivo(s) listos para guardar', 'file(s) ready to save') }}
                                </p>
                                <div v-if="monthlyReportAttachments(club, 'activity_photo').length" class="mt-3 space-y-1 text-sm">
                                    <a
                                        v-for="attachment in monthlyReportAttachments(club, 'activity_photo')"
                                        :key="attachment.id"
                                        :href="attachment.url"
                                        target="_blank"
                                        class="block truncate text-blue-700 hover:underline"
                                    >
                                        {{ attachment.original_name }}
                                    </a>
                                </div>
                            </div>
                        </div>

                        <div class="mt-5">
                            <label class="block text-sm font-medium text-gray-700">{{ tr('Permiso para compartir fotos', 'May we share your pictures?') }}</label>
                            <select v-model="getMonthlyReportDraft(club).may_share_photos" class="mt-1 w-full rounded border p-2 md:max-w-xs">
                                <option value="">{{ tr('Selecciona', 'Select') }}</option>
                                <option value="1">{{ tr('Si', 'Yes') }}</option>
                                <option value="0">{{ tr('No', 'No') }}</option>
                            </select>
                        </div>

                        <div class="mt-5 grid gap-3 border-t pt-4 lg:grid-cols-[auto_auto_1fr_auto] lg:items-center">
                            <button
                                type="button"
                                class="rounded bg-blue-600 px-4 py-2 text-sm text-white hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-60"
                                :disabled="savingMonthlyReportByClub[club.id]"
                                @click="saveMonthlyReport(club)"
                            >
                                {{ savingMonthlyReportByClub[club.id] ? tr('Guardando...', 'Saving...') : tr('Guardar reporte', 'Save report') }}
                            </button>
                            <button
                                type="button"
                                class="rounded bg-gray-700 px-4 py-2 text-sm text-white hover:bg-gray-800 disabled:cursor-not-allowed disabled:opacity-60"
                                :disabled="!getMonthlyReportDraft(club).id"
                                @click="downloadMonthlyReport(club)"
                            >
                                {{ tr('Descargar PDF', 'Download PDF') }}
                            </button>
                            <input
                                v-model="monthlyReportEmailByClub[club.id]"
                                type="email"
                                class="w-full rounded border p-2"
                                :placeholder="tr('Correo destino', 'Recipient email')"
                            />
                            <button
                                type="button"
                                class="rounded bg-emerald-700 px-4 py-2 text-sm text-white hover:bg-emerald-800 disabled:cursor-not-allowed disabled:opacity-60"
                                :disabled="sendingMonthlyReportByClub[club.id] || !getMonthlyReportDraft(club).id"
                                @click="sendMonthlyReport(club)"
                            >
                                {{ sendingMonthlyReportByClub[club.id] ? tr('Enviando...', 'Sending...') : tr('Enviar', 'Send') }}
                            </button>
                        </div>

                        <p v-if="!getMonthlyReportDraft(club).id" class="mt-2 text-sm text-amber-700">
                            {{ tr('Primero guarda el reporte mensual para habilitar descarga y envio.', 'Save the monthly report first to enable download and sending.') }}
                        </p>
                        <p v-if="getMonthlyReportDraft(club).last_sent_to_email" class="mt-2 text-xs text-gray-500">
                            {{ tr('Ultimo envio', 'Last sent') }}:
                            {{ getMonthlyReportDraft(club).last_sent_to_email }}
                            <span v-if="getMonthlyReportDraft(club).sent_at">({{ getMonthlyReportDraft(club).sent_at }})</span>
                        </p>
                        </template>
                    </div>
                </div>
            </details>

            <details class="border rounded">
                <summary class="bg-gray-100 px-4 py-2 font-semibold cursor-pointer">{{ tr('Clases', 'Classes') }}</summary>
                <div class="p-4">
                    <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <h3 class="text-lg font-bold">{{ tr('Clases del club', 'Club Classes') }}</h3>
                        <div class="grid gap-2 sm:flex sm:items-center">
                            <button
                                type="button"
                                @click="exportClassesPdf(false)"
                                class="rounded bg-gray-700 px-3 py-2 text-sm text-white hover:bg-gray-800"
                            >
                                {{ tr('PDF clases', 'Classes PDF') }}
                            </button>
                            <button
                                type="button"
                                @click="exportClassesPdf(true)"
                                class="rounded bg-emerald-700 px-3 py-2 text-sm text-white hover:bg-emerald-800"
                            >
                                {{ tr('PDF clases + requisitos', 'Classes + requirements PDF') }}
                            </button>
                            <button
                                v-if="!filteredClubs.some(isCarpetaClub)"
                                @click="openNewClassModal"
                                class="rounded bg-blue-600 px-4 py-2 text-white hover:bg-blue-700">
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
                    <div class="overflow-x-auto rounded border bg-white">
                    <table class="min-w-[920px] w-full text-left text-sm">
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
                                            <div class="flex flex-wrap items-center gap-2">
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
                                        <td class="p-2">
                                            <div class="flex flex-wrap gap-2">
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
                                            </div>
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
                                                <div class="mb-2 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
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
                                                        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                                            <div class="min-w-0">
                                                                <p class="text-sm font-medium text-gray-900">
                                                                    {{ requirement.sort_order }}. {{ requirement.title }}
                                                                </p>
                                                                <p v-if="requirement.description" class="text-xs text-gray-600 mt-1">
                                                                    {{ requirement.description }}
                                                                </p>
                                                            </div>
                                                            <div class="flex shrink-0 items-center gap-2">
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
                                                <div v-if="showRequirementFormByClass[cls.id]" class="mt-2 flex flex-col gap-2 sm:flex-row sm:items-center">
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
                </div>
            </details>

            <details class="border rounded">
                <summary class="bg-gray-100 px-4 py-2 font-semibold cursor-pointer">{{ tr('Objetivos', 'Objectives') }}</summary>
                <div class="p-4">
                    <div class="mb-4 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <h3 class="text-lg font-bold">{{ tr('Objetivos del club', 'Club Objectives') }}</h3>
                            <p class="text-sm text-gray-600">{{ tr('Estos objetivos son locales y luego pueden usarse en el plan de trabajo aun si no se importaron desde mychurchadmin.', 'These objectives are local and can later be used in the workplan even if they were not imported from mychurchadmin.') }}</p>
                        </div>
                    </div>

                    <div class="overflow-x-auto rounded border bg-white">
                    <table class="min-w-[840px] w-full text-left text-sm">
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
                                    <div class="mb-3 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
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
                                            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                                <div class="min-w-0">
                                                    <div class="flex flex-wrap items-center gap-2">
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
                                                <div class="flex shrink-0 items-center gap-2">
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
                                        <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
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
                </div>
            </details>
        <CreateClassModal v-if="showClassModal" v-model:visible="showClassModal" :clubs="clubs"
                :staff="clubStaff" :user="user" :classToEdit="classToEdit" @created="refreshPage" />
        </div>
    </PathfinderLayout>
</template>
