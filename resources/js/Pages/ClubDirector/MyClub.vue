<script setup>
import { ref, computed, onMounted } from 'vue'
import { useForm } from '@inertiajs/vue3'
import CreateClassModal from '@/Components/CreateClassModal.vue'
import PathfinderLayout from '@/Layouts/PathfinderLayout.vue'
import { useAuth } from '@/Composables/useAuth'
import { useGeneral } from '@/Composables/useGeneral'
import { refreshPage } from '@/Helpers/general'

import {
    fetchClubsByChurchId,
    deleteClubById,
    selectUserClub,
    createClub,
    updateClub as updateClubApi
} from '@/Services/api'

// 🧠 Auth state
const { user } = useAuth()

const { showToast } = useGeneral()
// 🧠 Derived data
const church_name = user.value.church_name || 'Unknown Church'
const clubId = user.value.club_id || null

const clubClasses = computed(() => {
    const firstClub = clubs.value[0] ?? {}
    return [...(firstClub.club_classes || [])].sort((a, b) => a.class_order - b.class_order)
})

const clubStaff = computed(() => {
    return clubs.value[0]?.staff_adventurers ?? []
})

// 🧠 UI & state
const isEditing = ref(false)
const addClub = ref(false)
const editingClubId = ref(null)
const clubs = ref([])
const showClassModal = ref(false)
const classToEdit = ref(null)
const hasClub = ref(false)

// 🧠 Club form
const clubForm = useForm({
    church_id: user.value.church_id,
    club_name: '',
    church_name: user.value.church_name,
    director_name: user.value.name,
    creation_date: '',
    pastor_name: '',
    conference_name: '',
    conference_region: '',
    club_type: ''
})

// 🧠 Load clubs on mount
const fetchClubs = async () => {
    try {
        // Always get from backend regardless of user.clubs
        const data = await fetchClubsByChurchId(user.value.church_id)
        clubs.value = [...data]
        hasClub.value = data.length > 0
        console.log('Clubs fetched:', clubs.value)
        showToast('Clubs fetched successfully!')
    } catch (error) {
        console.error('Failed to fetch clubs:', error)
        showToast('Error loading clubs')
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
        showToast('Failed to create club')
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
        showToast('Error deleting club')
    }
}

const deleteCls = async (clubId) => {
    if (!confirm('Are you sure you want to delete this class?')) return
    try {
        await deleteClubById(clubId) // Optional: separate class delete endpoint
        showToast('Class deleted successfully!')
        fetchClubs()
    } catch (error) {
        console.error('Failed to delete class:', error)
        toast.error('Error deleting class')
    }
}

// 🧠 Select club (director choosing one)
const selectClub = async (clubId) => {
    try {
        await selectUserClub(clubId, user.value.id)
        toast.success('Club selected successfully!')
        await router.reload({ only: ['auth'] })
        refreshPage()
    } catch (error) {
        console.error('Failed to select club:', error)
        refreshPage()
    }
}

// 🧠 Get assigned staff name
const getStaffName = (id) => {
    const staff = clubStaff.value.find(s => s.id === id)
    return staff ? staff.name : '—'
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
        creation_date: '',
        pastor_name: '',
        conference_name: '',
        conference_region: '',
        club_type: ''
    })
}

onMounted(fetchClubs)
</script>


<template>
    <PathfinderLayout>
        <template #title>My Club</template>

        <div v-if="(clubId == null && clubs.length == 0) || isEditing || addClub" class="space-y-6">
            <p class="text-gray-700">
                {{ isEditing ? 'Edit your club below:' : 'Create your club below.' }}
            </p>

            <form class="space-y-4" @submit.prevent="isEditing ? updateClub() : submitClub()">
                <div v-for="field in [
                    { key: 'club_name', label: 'Club Name' },
                    { key: 'church_name', label: 'Church Name' },
                    { key: 'director_name', label: 'Director Name', readonly: true },
                    { key: 'creation_date', label: 'Date of Creation', type: 'date' },
                    { key: 'pastor_name', label: 'Pastor Name' },
                    { key: 'conference_name', label: 'Conference Name' },
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
        <div v-else-if="clubId == null && clubs.length > 0" class="space-y-6">
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

                    <table class="min-w-full border rounded text-left border-collapse">
                        <thead class="bg-gray-100">
                            <tr>
                                <th class="border-b px-4 py-2">Order</th>
                                <th class="border-b px-4 py-2">Name</th>
                                <th class="border-b px-4 py-2">Assigned Staff</th>
                                <th class="border-b px-4 py-2">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="clubClasses.length === 0">
                                <td colspan="4" class="text-center py-4 text-gray-500">No classes yet.</td>
                            </tr>
                            <tr v-for="cls in clubClasses" :key="cls.id">
                                <td class="px-4 py-2">{{ cls.class_order }}</td>
                                <td class="px-4 py-2">{{ cls.class_name }}</td>
                                <td class="px-4 py-2">{{ getStaffName(cls.assigned_staff_id) }}</td>
                                <td class="p-2 space-x-2">
                                    <button @click="editCls(cls)" class="text-blue-600 hover:underline">Edit</button>
                                    <button @click="deleteCls(cls)" class="text-red-600 hover:underline">Delete</button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </details>

            <CreateClassModal v-if="showClassModal" v-model:visible="showClassModal" :clubs="user.clubs"
                :staff="clubStaff" :user="user" :classToEdit="classToEdit" @created="refreshPage" />
        </div>
    </PathfinderLayout>
</template>
