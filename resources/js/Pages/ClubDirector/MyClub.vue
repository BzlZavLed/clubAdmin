<script setup>
import { useForm } from '@inertiajs/vue3'
import CreateClassModal from '@/Components/CreateClassModal.vue'
import PathfinderLayout from '@/Layouts/PathfinderLayout.vue'
import { useAuth } from '@/Composables/useAuth'
import { useGeneral } from '@/Composables/useGeneral'
import { refreshPage } from '@/Helpers/general'
import { computed, ref, watch, onMounted } from 'vue'

import {
    fetchClubsByUserId,
    deleteClubById,
    selectUserClub,
    createClub,
    updateClub as updateClubApi,
    deleteClassById,
    fetchMembersByClub
} from '@/Services/api'

// 🧠 Auth state
const { user } = useAuth()

const { showToast } = useGeneral()
const today = new Date().toISOString().split("T")[0]

// 🧠 UI & state
const isEditing = ref(false)
const addClub = ref(false)
const editingClubId = ref(null)
const clubs = ref([])
const showClassModal = ref(false)
const classToEdit = ref(null)
const hasClub = ref(false)

// 🧠 Derived data
const church_name = user.value.church_name || 'Unknown Church'
const clubId = ref(user.value.club_id || null)

const clubStaff = computed(() => {
    return clubs.value[0]?.staff_adventurers ?? []
})
if (!user.value.pastor_name) {
    showToast('Create church first', 'error')
}

// 🧠 Club form
const clubForm = useForm({
    church_id: user.value.church_id,
    club_name: '',
    church_name: user.value.church_name,
    director_name: user.value.name,
    creation_date: today,
    pastor_name: user.value.pastor_name || 'No church created',
    conference_name: user.value.conference_name || 'No church created',
    conference_region: '',
    club_type: ''
})

const selectedClubId = ref('')

const filteredClubs = computed(() => {
    return selectedClubId.value
        ? clubs.value.filter(club => club.id === selectedClubId.value)
        : clubs.value
})

// 🧠 Load clubs on mount
const fetchClubs = async () => {
    try {
        const data = await fetchClubsByUserId(user.value.id)
        clubs.value = Array.isArray(data) ? data : []
        hasClub.value = clubs.value.length > 0
        if (!clubId.value && clubs.value.length) {
            clubId.value = clubs.value[0].id
        }
        if (!selectedClubId.value && clubId.value) {
            selectedClubId.value = clubId.value
        }
        showToast('Clubs fetched successfully!')
    } catch (error) {
        console.error('Failed to fetch clubs:', error)
        showToast('Error loading clubs', 'error')
    }
}

// 🧠 Submit & update club
const submitClub = async () => {
    try {
        await createClub(clubForm)
        showToast('Club created successfully!')
        addClub.value = false
        fetchClubs()
    } catch (error) {
        console.error(error)
        showToast('Failed to create club', 'error')
    }
}

const updateClub = async () => {
    try {
        await updateClubApi(clubForm)
        showToast('Club updated successfully!')
        isEditing.value = false
        editingClubId.value = null
        fetchClubs()
    } catch (error) {
        console.error(error)
        showToast('Failed to update club', 'error')
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
    if (!confirm('Are you sure you want to delete this club?')) return
    try {
        await deleteClubById(clubId)
        showToast('Club deleted successfully!')
        fetchClubs()
    } catch (error) {
        console.error('Failed to delete club:', error)
        showToast('Error deleting club', 'error')
    }
}

const deleteCls = async (classID) => {
    if (!confirm('Are you sure you want to delete this class?')) return
    try {
        await deleteClassById(classID)
        showToast('Class deleted successfully!')
        fetchClubs()
    } catch (error) {
        console.error('Failed to delete class:', error)
        showToast('Error deleting class', 'error')
    }
}

// 🧠 Select club (director choosing one)
const selectClub = async (clubId) => {
    try {
        await selectUserClub(clubId, user.value.id)
        showToast('Club selected successfully!')
        await router.reload({ only: ['auth'] })
        refreshPage()
    } catch (error) {
        console.error('Failed to select club:', error)
        refreshPage()
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

// 🧠 Start new form
const startCreatingClub = () => {
    addClub.value = true
    clubForm.reset()
    Object.assign(clubForm, {
        church_id: user.value.church_id,
        club_name: '',
        church_name: user.value.church_name,
        director_name: user.value.name,
        creation_date: today,
        pastor_name: user.value.pastor_name,
        conference_name: user.value.conference_name || '',
        conference_region: '',
        club_type: ''
    })
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
    { value: 'club_wide', label: 'Club wide' },
    { value: 'class', label: 'Specific class' },
    { value: 'member', label: 'Specific member' },
    { value: 'staff_wide', label: 'Staff wide' },
    { value: 'staff', label: 'Specific staff' }
]

const payToOptions = [
    { value: 'church_budget', label: 'Church budget' },
    { value: 'club_budget', label: 'Club budget' },
    { value: 'conference', label: 'Conference' },
    { value: 'reimbursement_to', label: 'Reimbursement to…' }
]

const typeOptions = [
    { value: 'mandatory', label: 'Mandatory' },
    { value: 'optional', label: 'Optional' }
]

const statusOptions = [
    { value: 'active', label: 'Active' },
    { value: 'inactive', label: 'Inactive' }
]

// derive current club name (for sanity)
const conceptClubName = computed(() => {
    const club = clubs.value.find(c => c.id === conceptClubId.value)
    return club?.club_name ?? ''
})

// scope builder actions
function addScope() {
    if (!conceptClubId.value) {
        showToast('Select a club for this concept first', 'error')
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
        showToast('Please choose the concept’s club', 'error')
        return
    }
    if (pcForm.scopes.length === 0) {
        showToast('Please add at least one scope', 'error')
        return
    }

    // If pay_to != reimbursement_to, clear payee*
    if (pcForm.pay_to !== 'reimbursement_to') {
        pcForm.payee_type = null
        pcForm.payee_id = null
    }

    try {
        // await createPaymentConcept(pcForm) // when backend ready
        showToast('Payment concept saved (stub)', 'success')
        pcForm.reset()
        pcForm.type = 'mandatory'
        pcForm.pay_to = 'club_budget'
        pcForm.status = 'active'
        pcForm.club_id = conceptClubId.value || null
        pcForm.scopes = []
        await loadPaymentConcepts()
    } catch (e) {
        showToast('Failed to save concept', 'error')
    }
}

async function deleteConcept(id) {
    try {
        // await deletePaymentConcept(id)
        showToast('Concept deleted (stub)', 'success')
        await loadPaymentConcepts()
    } catch (e) {
        showToast('Failed to delete concept', 'error')
    }
}

const staffList = ref([])

const fetchStaff = async (clubId) => {
    try {
        const response = await axios.get(`/clubs/${clubId}/staff`)
        staffList.value = response.data.staff
        if(staffList.value.length === 0) {
            showToast('Create staff first, none found','error')
            return
        }
        showToast('Staff loaded','success');
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
            showToast('Members loaded', 'success')
        } else {
            members.value = []
            alert('No members found for this club.')
        }
    } catch (error) {
        console.error('Failed to fetch members:', error)
        showToast('Error fetching members', 'error')
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
        <template #title>My Club</template>

        <div v-if="isEditing || addClub || (clubs.length === 0 && !clubId)" class="space-y-6">
            <p class="text-gray-700">
                {{ isEditing ? 'Edit your club below:' : 'Create your club below.' }}
            </p>

            <form class="space-y-4" @submit.prevent="isEditing ? updateClub() : submitClub()">
                <div v-for="field in [
                    { key: 'club_name', label: 'Club Name' },
                    { key: 'church_name', label: 'Church Name' , readonly: true },
                    { key: 'director_name', label: 'Director Name', readonly: true },
                    { key: 'creation_date', label: 'Date of Creation', type: 'date' },
                    { key: 'pastor_name', label: 'Pastor Name', readonly: true },
                    { key: 'conference_name', label: 'Conference Name', readonly: true },
                    { key: 'conference_region', label: 'Conference Region' }
                ]" :key="field.key">
                    <label class="block text-sm font-medium text-gray-700">{{ field.label }}</label>
                    <input v-model="clubForm[field.key]" :type="field.type || 'text'" :readonly="field.readonly"
                        class="w-full mt-1 p-2 border rounded" />
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700">Club Type</label>
                    <select v-model="clubForm.club_type" class="w-full mt-1 p-2 border rounded">
                        <option value="">Select Type</option>
                        <option value="adventurers">Adventurers</option>
                        <option value="pathfinders">Pathfinders</option>
                        <option value="master_guide">Master Guide</option>
                    </select>
                </div>

                <div class="flex items-center space-x-4">
                    <button type="submit" class="bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded">
                        {{ isEditing ? 'Update Club' : 'Save Club' }}
                    </button>
                    <button v-if="isEditing || addClub" type="button" @click="() => {
                        isEditing = false;
                        addClub = false;
                        editingClubId = null
                    }" class="text-sm text-gray-600 hover:underline">
                        Cancel Edit
                    </button>
                </div>
            </form>
        </div>
        <div v-else-if="!clubId && clubs.length > 0" class="space-y-6">
            <p class="text-gray-700">Select an existing club from your church: {{ church_name || 'Unknown Church' }}</p>
            <table class="min-w-full border rounded text-sm">
                <thead class="bg-gray-100">
                    <tr>
                        <th class="p-2 text-left">Name</th>
                        <th class="p-2 text-left">Type</th>
                        <th class="p-2 text-left">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="club in clubs" :key="club.id" class="border-t">
                        <td class="p-2">{{ club.club_name }}</td>
                        <td class="p-2 capitalize">{{ club.club_type }}</td>
                        <td class="p-2 space-x-2">
                            <button @click="selectClub(club.id)" class="text-blue-600 hover:underline">Select</button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <div v-else class="space-y-4">
            <details open class="border rounded">
                <summary class="bg-gray-100 px-4 py-2 font-semibold cursor-pointer">Club Information</summary>
                <div class="p-4">
                    <table class="min-w-full border rounded text-sm">
                        <thead class="bg-gray-100">
                            <tr>
                                <th class="p-2 text-left">Name</th>
                                <th class="p-2 text-left">Church</th>
                                <th class="p-2 text-left">Type</th>
                                <th class="p-2 text-left">Created</th>
                                <th class="p-2 text-left">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="club in clubs" :key="club.id" class="border-t">
                                <td class="p-2">{{ club.club_name }}</td>
                                <td class="p-2">{{ club.church_name }}</td>
                                <td class="p-2 capitalize">{{ club.club_type }}</td>
                                <td class="p-2">{{ club.creation_date }}</td>
                                <td class="p-2 space-x-2">
                                    <button @click="editClub(club)" class="text-blue-600 hover:underline">Edit</button>
                                    <button @click="deleteClub(club.id)"
                                        class="text-red-600 hover:underline">Delete</button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <div class="mt-4">
                        <button @click="startCreatingClub"
                            class="px-4 py-2 bg-green-600 text-white rounded hover:bg-green-700">
                            + Create Club
                        </button>
                    </div>
                </div>
            </details>

            <details class="border rounded">
                <summary class="bg-gray-100 px-4 py-2 font-semibold cursor-pointer">Classes</summary>
                <div class="p-4">
                    <div class="flex justify-between items-center mb-4">
                        <h3 class="text-lg font-bold">Club Classes</h3>
                        <button @click="openNewClassModal"
                            class="px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700">
                            + Add Class
                        </button>
                    </div>
                    <select v-model="selectedClubId" class="border rounded mb-6">
                        <option value="">All Clubs</option>
                        <option v-for="club in clubs" :key="club.id" :value="club.id">
                            {{ club.club_name }}
                        </option>
                    </select>

                    <table class="min-w-full border rounded text-left border-collapse">
                        <thead class="bg-gray-100">
                            <tr>
                                <th class="border-b px-4 py-2">Club</th>
                                <th class="border-b px-4 py-2">Order</th>
                                <th class="border-b px-4 py-2">Name</th>
                                <th class="border-b px-4 py-2">Assigned Staff</th>
                                <th class="border-b px-4 py-2">Actions</th>
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
                                        <td class="px-4 py-2">{{ getStaffName(cls) }}</td>
                                        <td class="p-2 space-x-2">
                                            <button @click="editCls(cls)"
                                                class="text-blue-600 hover:underline">Edit</button>
                                            <button @click="deleteCls(cls)"
                                                class="text-red-600 hover:underline">Delete</button>
                                        </td>
                                    </tr>
                                </template>
                            </template>
                        </tbody>
                    </table>
                </div>
            </details>
        <CreateClassModal v-if="showClassModal" v-model:visible="showClassModal" :clubs="clubs"
                :staff="clubStaff" :user="user" :classToEdit="classToEdit" @created="refreshPage" />
        </div>
    </PathfinderLayout>
</template>
