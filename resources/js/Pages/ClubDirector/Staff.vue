<script setup>
import { ref, computed, onMounted, watch, watchEffect } from 'vue'
import PathfinderLayout from '@/Layouts/PathfinderLayout.vue'
import CreateStaffModal from '@/Components/CreateStaffModal.vue'
import {
    PlusIcon,
    MinusIcon,
    UserPlusIcon,
    DocumentArrowDownIcon,
    TrashIcon,
    ArrowPathIcon,
    PencilIcon,
} from '@heroicons/vue/24/solid'
import { useAuth } from '@/Composables/useAuth'
import { useGeneral } from '@/Composables/useGeneral'
import UpdatePasswordModal from "@/Components/ChangePassword.vue";
import {
    fetchClubsByUserId,
    fetchStaffByClubId,
    createStaffUser,
    approveStaff,
    rejectStaff,
    updateStaffStatus,
    updateUserStatus,
    downloadStaffZip,
    fetchClubClasses,
    updateStaffAssignedClass,
    linkStaffToClubUser,
    fetchTempStaffPathfinder,
    createTempStaffPathfinder
} from '@/Services/api'
import axios from 'axios'
import { usePage } from '@inertiajs/vue3';

const page = usePage();
const subRoles = page.props.sub_roles;


// ✅ Auth & general utilities
const { user } = useAuth()
const churchId = computed(() => user.value?.church_id || null)
const userId = computed(() => user.value?.id || null)
const changePasswordUserId = ref(null)
const club_name = computed(() => user.value?.clubs[0]?.club_name || '')
const { toast, showToast } = useGeneral()
const showPasswordModal = ref(false)
// ✅ State
const selectedClub = ref(null)
const clubs = ref([])
const staff = ref([])
const pendingStaff = ref([])
const tempStaff = ref([])
const assignedClassChanges = ref({})
const isUpdatingClass = ref({})
const tempStaffForm = ref({
    club_id: '',
    staff_name: '',
    staff_dob: '',
    staff_age: '',
    staff_email: '',
    staff_phone: '',
})
const clubClasses = ref([])
const sub_roles = ref([])
const pendingUsers = ref([])
const createStaffModalVisible = ref(false)
const staffToEdit = ref(null)
const selectedUserForStaff = ref(null)
const expandedRows = ref(new Set())
const selectAll = ref(false)
const selectedStaffIds = ref(new Set())
const activeTab = ref('active')
const activeStaffTab = ref('active')
const clubUserIds = ref(new Set())

// ✅ Create staff eligibility map
const createStaffMap = computed(() => {
    const map = {}
    sub_roles.value.forEach(user => {
        map[user.id] = user.create_staff === true
    })
    return map
})

// ✅ Filtered lists
const userClubId = computed(() => user.value?.club_id || null)

const filteredUsers = computed(() =>
    sub_roles.value.filter(user => {
        if (activeTab.value === 'pending') return false
        const targetStatus = activeTab.value === 'active' ? 'active' : 'deleted'
        return user.status === targetStatus && (!userClubId.value || String(user.club_id) === String(userClubId.value))
    })
)

const filteredPendingUsers = computed(() =>
    pendingUsers.value.filter(u => !userClubId.value || String(u.club_id) === String(userClubId.value))
)
const filteredPendingStaff = computed(() =>
    pendingStaff.value.filter(u => !userClubId.value || String(u.club_id) === String(userClubId.value))
)

const filteredStaff = computed(() =>
    staff.value.filter(person => person.status === activeStaffTab.value)
)
const availableClasses = computed(() => {
    if (!selectedClub.value) return []
    const cls = clubClasses.value.filter(c => c.club_id === selectedClub.value.id || !c.club_id)
    return cls
})

const classDisplay = (person) => {
    if (person.class_names?.length) return person.class_names.join(', ')
    if (person.assigned_class) {
        const match = clubClasses.value.find(c => String(c.id) === String(person.assigned_class))
        if (match) return match.class_name
    }
    return '—'
}


const openEditStaffModal = (staff) => {
    selectedUserForStaff.value = null
    staffToEdit.value = staff
    createStaffModalVisible.value = true
}

watch(sub_roles, (newVal) => {
    if (!newVal.some(user => user.status === 'deleted')) {
        activeTab.value = 'active'
    }
}, { immediate: true })

watch(staff, (newVal) => {
    if (!newVal.some(person => person.status === 'deleted')) {
        activeStaffTab.value = 'active'
    }
}, { immediate: true })

// ✅ Fetch club(s)
const fetchClubs = async () => {
    try {
        const data = await fetchClubsByUserId(user.value.id)
        clubs.value = Array.isArray(data) ? data : []
        showToast('Clubs loaded')
    } catch (error) {
        console.error('Failed to fetch clubs:', error)
        showToast('Error loading clubs', 'error')
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
// ✅ Fetch staff list
const fetchStaff = async (clubId, churchId = null) => {
    try {
        const data = await fetchStaffByClubId(clubId, churchId)
        staff.value = data.staff
        sub_roles.value = data.sub_role_users
        pendingUsers.value = data.pending_users || []
        pendingStaff.value = data.pending_staff || []
        clubUserIds.value = new Set(data.club_user_ids || [])
        await fetchClasses(clubId)
        // hydrate current class selections
        staff.value.forEach(person => {
            if (person.assigned_class) {
                assignedClassChanges.value[person.id] = person.assigned_class
            } else if (person.class_names?.length === 1) {
                const match = clubClasses.value.find(c => c.class_name === person.class_names[0])
                if (match) assignedClassChanges.value[person.id] = match.id
            }
        })
        if (selectedClub.value?.club_type === 'pathfinders') {
            await loadTempStaff(clubId)
        } else {
            tempStaff.value = []
        }
        showToast('Staff loaded')
    } catch (error) {
        console.error('Failed to fetch staff:', error)
        showToast('Error loading staff', 'error')
    }
}
const saveAssignedClass = async (person) => {
    const newClassId = assignedClassChanges.value[person.id]
    if (!newClassId) return
    try {
        isUpdatingClass.value[person.id] = true
        await updateStaffAssignedClass(person.id, newClassId)
        showToast('Class updated!')
        await fetchStaff(person.club_id)
    } catch (err) {
        console.error('Failed to update class', err)
        showToast('Error updating class', 'error')
    } finally {
        isUpdatingClass.value[person.id] = false
    }
}

// ✅ Modals
const openStaffForm = (user) => {
    selectedUserForStaff.value = user
    selectedUserForStaff.value.club_name = club_name.value
    createStaffModalVisible.value = true
}

// ✅ Account management
const updateStaffAccount = async (staff, status_code) => {
    const action = status_code === 301 ? 'deactivate' : 'reactivate'
    if (!confirm(`Are you sure you want to ${action} the staff member ${staff.name}?`)) return

    try {
        await updateStaffStatus(staff.id, status_code)
        showToast(`Staff ${action}d`)
        fetchStaff(staff.club_id)
    } catch (error) {
        console.error('Failed to update staff status:', error)
        showToast(`Failed to ${action} staff`, 'error')
    }
}

const updateStaffUserAccount = async (user, status_code) => {
    const action = status_code === 301 ? 'deactivate' : 'reactivate'
    if (!confirm(`Are you sure you want to ${action} the user account for ${user.name}?`)) return

    try {
        await updateUserStatus(user.id, status_code)
        showToast(`User ${action}d`)
        fetchStaff(user.club_id)
    } catch (error) {
        console.error('Failed to update user status:', error)
        showToast(`Failed to ${action} user`, 'error')
    }
}

const createUser = async (person) => {
    try {
        await createStaffUser({
            name: person.name,
            email: person.email,
            church_name: person.church_name,
            church_id: person.church_id,
            club_id: person.club_id
        })
        showToast('User created successfully')
        person.create_user = false
        fetchStaff(person.club_id,churchId.value)
    } catch (err) {
        console.error('Create user error:', err)
        showToast('Failed to create user', 'error')
    }
}

const linkToClubUsers = async (person) => {
    if (!person.user_id) {
        showToast('No user linked to this staff (by email).', 'error')
        return
    }
    try {
        await linkStaffToClubUser(person.id)
        clubUserIds.value.add(person.user_id)
        showToast('Staff linked to club access')
    } catch (err) {
        console.error('Failed to link staff to club users', err)
        showToast('Failed to link staff', 'error')
    }
}

// ✅ Bulk actions
const handleBulkAction = async (action) => {
    if (selectedStaffIds.value.size === 0) {
        showToast('No staff selected.')
        return
    }

    const ids = Array.from(selectedStaffIds.value)
    const isReactivate = action === 'reactivate'
    const statusCode = isReactivate ? 423 : 301
    const confirmText = isReactivate
        ? 'Are you sure you want to reactivate the selected staff?'
        : 'Are you sure you want to deactivate the selected staff?'

    if (!confirm(confirmText)) return

    try {
        for (const id of ids) {
            await updateStaffStatus(id, statusCode)
        }
        showToast(`Staff ${isReactivate ? 'reactivated' : 'deactivated'}`)
        await fetchStaff(selectedClub.value.id)
        selectedStaffIds.value.clear()
        selectAll.value = false
    } catch (error) {
        console.error('Bulk action failed:', error)
        toast.error('Bulk update failed')
    }
}

// ✅ Download
const downloadWord = (staffId) => {
    window.open(`/staff/${staffId}/export-word`, '_blank')
}

const approvePending = async (userId) => {
    try {
        await axios.post(route('club.users.approve', userId))
        showToast('User approved')
        fetchStaff(selectedClub.value.id, churchId.value)
    } catch (err) {
        console.error('Approve failed', err)
        showToast('Failed to approve user', 'error')
    }
}

const rejectPending = async (userId) => {
    try {
        await updateUserStatus(userId, 301)
        showToast('User rejected')
        fetchStaff(selectedClub.value.id, churchId.value)
    } catch (err) {
        console.error('Reject failed', err)
        showToast('Failed to reject user', 'error')
    }
}



// ✅ Selection helpers
const toggleSelectStaff = (id) => {
    selectedStaffIds.value.has(id)
        ? selectedStaffIds.value.delete(id)
        : selectedStaffIds.value.add(id)
}

const toggleSelectAll = () => {
    selectAll.value
        ? selectedStaffIds.value = new Set(staff.value.map(m => m.id))
        : selectedStaffIds.value.clear()
}

const toggleExpanded = (id) => {
    expandedRows.value.has(id) ? expandedRows.value.delete(id) : expandedRows.value.add(id)
}

const changePassword = (user) => {
    showPasswordModal.value = true
    changePasswordUserId.value = user.id
}
const closeModal = () => {
    createStaffModalVisible.value = false
    staffToEdit.value = null
}
const approvePendingStaff = async (staffRow) => {
    try {
        await approveStaff(staffRow.id)
        showToast('Staff approved')
        fetchStaff(selectedClub.value.id, churchId.value)
    } catch (err) {
        console.error('Approve staff failed', err)
        showToast('Failed to approve staff', 'error')
    }
}

const rejectPendingStaff = async (staffRow) => {
    try {
        await rejectStaff(staffRow.id)
        showToast('Staff rejected')
        fetchStaff(selectedClub.value.id, churchId.value)
    } catch (err) {
        console.error('Reject staff failed', err)
        showToast('Failed to reject staff', 'error')
    }
}

const loadTempStaff = async (clubId) => {
    try {
        tempStaff.value = await fetchTempStaffPathfinder(clubId)
        console.log('Loaded temp staff:', tempStaff.value)
    } catch (err) {
        console.error('Failed to load temp staff', err)
        tempStaff.value = []
    }
}

const saveTempStaff = async () => {
    try {
        tempStaffForm.value.club_id = selectedClub.value?.id || ''
        if (!tempStaffForm.value.club_id) {
            showToast('Select a club first', 'error')
            return
        }
        await createTempStaffPathfinder(tempStaffForm.value)
        showToast('Temp staff saved', 'success')
        await loadTempStaff(tempStaffForm.value.club_id)
        tempStaffForm.value = {
            club_id: selectedClub.value?.id || '',
            staff_name: '',
            staff_dob: '',
            staff_age: '',
            staff_email: '',
            staff_phone: '',
        }
        // Refresh entire staff view to reflect new temp staff everywhere
        await fetchStaff(selectedClub.value.id, churchId.value)
    } catch (err) {
        console.error('Failed to save temp staff', err)
        showToast('Failed to save temp staff', 'error')
    }
}

// Auto-fill age from DOB
watch(() => tempStaffForm.value.staff_dob, (dob) => {
    if (!dob) return
    const birth = new Date(dob)
    if (isNaN(birth.getTime())) return
    const today = new Date()
    let age = today.getFullYear() - birth.getFullYear()
    const m = today.getMonth() - birth.getMonth()
    if (m < 0 || (m === 0 && today.getDate() < birth.getDate())) {
        age--
    }
    tempStaffForm.value.staff_age = age
})

onMounted(fetchClubs)
</script>


<template>
    <PathfinderLayout>
        <div class="p-8">
            <h1 class="text-xl font-bold mb-4">Staff</h1>
            <div class="max-w-xl mb-6">
                <label class="block mb-1 font-medium text-gray-700">Select a club</label>
                <select v-model="selectedClub"
                    @change="() => { if (selectedClub) { fetchStaff(selectedClub.id, churchId) } }"
                    class="w-full p-2 border rounded">
                    <option disabled value="">-- Choose a club --</option>
                    <option v-for="club in clubs" :key="club.id" :value="club">
                        {{ club.club_name }} ({{ club.club_type }})
                    </option>
                </select><br><br>
                <button v-if="selectedClub && selectedClub.club_type === 'adventurers'"
                    class="bg-green-600 text-white px-4 py-2 rounded hover:bg-green-700" @click="openStaffForm(user)">
                    Create Staff</button>
                <p v-else-if="selectedClub" class="text-sm text-gray-600">
                    Staff module is currently available for Adventurers clubs only.
                </p>

            </div>

            <div v-if="selectedClub" class="max-w-5xl mx-auto">
                <div v-if="selectedClub.club_type === 'pathfinders'" class="mb-6 border rounded p-4 bg-amber-50">
                    <h2 class="font-semibold text-amber-800 mb-2">Temporary Pathfinder Staff</h2>
                    <div class="grid md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Name</label>
                            <input v-model="tempStaffForm.staff_name" type="text" class="w-full border rounded p-2" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">DOB</label>
                            <input v-model="tempStaffForm.staff_dob" type="date" class="w-full border rounded p-2" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Age</label>
                            <input v-model="tempStaffForm.staff_age" type="number" min="0" class="w-full border rounded p-2" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Email</label>
                            <input v-model="tempStaffForm.staff_email" type="email" class="w-full border rounded p-2" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Phone</label>
                            <input v-model="tempStaffForm.staff_phone" type="text" class="w-full border rounded p-2" />
                        </div>
                    </div>
                    <div class="mt-3">
                        <button @click="saveTempStaff" class="px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700">Save temp staff</button>
                    </div>

                    <div class="mt-4 overflow-x-auto">
                        <table class="min-w-full text-sm border">
                            <thead class="bg-amber-100">
                                <tr>
                                    <th class="p-2 text-left">Name</th>
                                    <th class="p-2 text-left">DOB</th>
                                    <th class="p-2 text-left">Age</th>
                                    <th class="p-2 text-left">Email</th>
                                    <th class="p-2 text-left">Phone</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-if="!tempStaff.length">
                                    <td colspan="5" class="p-3 text-center text-gray-500">No temp staff</td>
                                </tr>
                                <tr v-for="ts in tempStaff" :key="ts.id" class="border-t">
                                    <td class="p-2">{{ ts.staff_name }}</td>
                                    <td class="p-2">{{ ts.staff_dob || '—' }}</td>
                                    <td class="p-2">{{ ts.staff_age || '—' }}</td>
                                    <td class="p-2">{{ ts.staff_email || '—' }}</td>
                                    <td class="p-2">{{ ts.staff_phone || '—' }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div v-if="filteredPendingStaff.length" class="mb-6 border rounded p-4 bg-amber-50">
                    <h2 class="font-semibold text-amber-800 mb-2">Pending staff approvals</h2>
                    <div class="space-y-2">
                        <div v-for="person in filteredPendingStaff" :key="person.id" class="flex items-center justify-between bg-white border rounded px-3 py-2">
                            <div>
                                <div class="font-medium text-gray-900">{{ person.name || 'Unnamed' }}</div>
                                <div class="text-sm text-gray-600">{{ person.email || 'No email' }}</div>
                            </div>
                            <div class="flex gap-2">
                                <button @click="approvePendingStaff(person)" class="px-3 py-1 text-sm rounded bg-green-600 text-white hover:bg-green-700">Approve</button>
                                <button @click="rejectPendingStaff(person)" class="px-3 py-1 text-sm rounded bg-red-600 text-white hover:bg-red-700">Reject</button>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="mb-4 flex space-x-4 border-b pb-2">
                    <button @click="activeStaffTab = 'active'"
                        :class="activeStaffTab === 'active' ? 'font-bold border-b-2 border-blue-600' : 'text-gray-500'">
                        Active Staff
                    </button>
                    <button
                        v-if="staff.some(person => person.status === 'deleted') && user.profile_type === 'club_director'"
                        @click="activeStaffTab = 'deleted'"
                        :class="activeStaffTab === 'deleted' ? 'font-bold border-b-2 border-red-600' : 'text-gray-500'">
                        Inactive Staff
                    </button>
                </div>
                <div class="flex items-center justify-between mb-4">
                    <div class="flex items-center gap-4">
                        <label class="inline-flex items-center">
                            <input type="checkbox" v-model="selectAll" @change="toggleSelectAll" class="mr-2" />
                            <span>Select All</span>
                        </label>
                        <select v-if="selectedStaffIds.size > 0"
                            @change="e => handleBulkAction(e.target.value, 'staff')"
                            class="border p-2 px-4 rounded w-60 text-sm">
                            <option value="" disabled selected>Bulk Actions</option>

                            <option :value="activeStaffTab === 'deleted' ? 'reactivate' : 'delete'">
                                {{ activeStaffTab === 'deleted' ? 'Reactivate Selected' : 'Deactivate Selected' }}
                            </option>

                            <option value="download">Download Forms</option>
                        </select>
                    </div>
                    <span class="text-sm text-gray-600">{{ selectedStaffIds.size }} selected</span>
                </div>

                <table class="w-full border rounded overflow-hidden text-sm">
                    <thead class="bg-gray-200">
                        <tr>
                            <th class="p-2 text-left"></th>
                            <th class="p-2 text-left">Name</th>
                            <th class="p-2 text-left">DOB</th>
                            <th class="p-2 text-left">Address</th>
                            <!-- <th class="p-2 text-left">Class</th> -->
                            <th class="p-2 text-left">Cell</th>
                            <th class="p-2 text-left w-16">Email</th>
                            <th class="p-2 text-left">Status</th>
                            <th class="p-2 text-left">Actions</th>
                            <th class="p-2 text-left">Assigned Classes</th>

                        </tr>
                    </thead>
                    <tbody>
                        <template v-for="person in filteredStaff" :key="person.id">
                            <tr class="border-t">
                                <td class="p-2 text-xs">
                                    <input type="checkbox" :value="person.id" :checked="selectedStaffIds.has(person.id)"
                                        @change="() => toggleSelectStaff(person.id)" />
                                </td>
                                <td class="p-2 text-xs">{{ person.name }}</td>
                                <td class="p-2 text-xs">{{ person.dob ? person.dob.slice(0, 10) : '—' }}</td>
                                <td class="p-2 text-xs">{{ person.address }}</td>
                                <!-- <td class="p-2">{{ person.assigned_classes?.[0]?.class_name ?? '—' }}</td> -->
                                <td class="p-2 text-xs">{{ person.cell_phone }}</td>
                                <td class="p-2 text-xs w-16 truncate">
                                    <a :href="`mailto:${person.email}`" class="text-blue-600 hover:underline block">
                                        {{ person.email }}
                                    </a>
                                </td>
                                <td class="p-2 text-xs">{{ person.status }}</td>
                                <td class="p-2 space-x-1 text-xs">
                                    <!-- Toggle Details -->
                                    <button @click="toggleExpanded(person.id)" class="text-green-600"
                                        title="Toggle details">
                                        <component :is="expandedRows.has(person.id) ? MinusIcon : PlusIcon"
                                            class="w-4 h-4 inline" />
                                    </button>

                                    <!-- Create User -->
                                <button v-if="person.create_user" @click="createUser(person)"
                                    class="text-orange-600" title="Create user">
                                    <UserPlusIcon class="w-4 h-4 inline" />
                                </button>
                                <button
                                    v-else-if="person.user_id && !clubUserIds.has(person.user_id)"
                                    @click="linkToClubUsers(person)"
                                    class="text-amber-600"
                                    title="Link to club access"
                                >
                                    <UserPlusIcon class="w-4 h-4 inline" />
                                </button>

                                <!-- Download Word Form -->
                                <button
                                    v-if="person.type !== 'temp_pathfinder'"
                                    @click="downloadWord(person.id)"
                                    class="text-blue-600"
                                    title="Download Word form">
                                        <DocumentArrowDownIcon class="w-4 h-4 inline" />
                                    </button>

                                    <!-- Delete or Reactivate -->
                                    <button v-if="person.status === 'active'" @click="updateStaffAccount(person, 301)"
                                        class="text-red-600" title="Delete staff">
                                        <TrashIcon class="w-4 h-4 inline" />
                                    </button>
                                    <button v-else @click="() => updateStaffAccount(person, 423)" class="text-gray-600"
                                        title="Reactivate staff">
                                        <ArrowPathIcon class="w-4 h-4 inline" />
                                    </button>
                                    <button
                                        v-if="person.type !== 'temp_pathfinder'"
                                        class="text-indigo-600 hover:underline"
                                        @click="openEditStaffModal(person)">
                                        <PencilIcon class="w-4 h-4 inline" />
                                    </button>
                                </td>
                                <td class="p-2 text-xs">
                                    {{ classDisplay(person) }}
                                    <div class="mt-1 flex items-center gap-2">
                                        <select
                                            v-model="assignedClassChanges[person.id]"
                                            class="border p-1 rounded text-xs"
                                        >
                                            <option disabled value="">Select class</option>
                                            <option v-for="cls in availableClasses" :key="cls.id" :value="cls.id">
                                                {{ cls.class_name }}
                                            </option>
                                        </select>
                                        <button
                                            @click="() => saveAssignedClass(person)"
                                            :disabled="!assignedClassChanges[person.id] || isUpdatingClass[person.id]"
                                            class="px-2 py-1 bg-blue-600 text-white rounded text-xs hover:bg-blue-700"
                                        >
                                            {{ isUpdatingClass[person.id] ? 'Saving...' : 'Save' }}
                                        </button>
                                    </div>
                                </td>
                            </tr>

                            <tr v-if="expandedRows.has(person.id)" class="bg-gray-50 border-t">
                                <td colspan="10" class="p-4 text-gray-700">
                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                        <div><strong>City/State/ZIP:</strong> {{ person.city }}, {{ person.state }} {{
                                            person.zip }}</div>
                                        <div><strong>Club Name:</strong> {{ person.club_name }}</div>
                                        <div><strong>Church Name:</strong> {{ person.church_name }}</div>

                                        <div><strong>Health Limitation:</strong> {{ person.has_health_limitation ? 'Yes'
                                            : 'No' }}</div>
                                        <div v-if="person.has_health_limitation"><strong>Limitation Details:</strong> {{
                                            person.health_limitation_description }}</div>

                                        <!-- Experience -->
                                        <div>
                                            <strong>Experience:</strong>
                                            <ul class="list-disc list-inside ml-4" v-if="person.experiences?.length">
                                                <li v-for="(exp, idx) in person.experiences" :key="idx">
                                                    {{ exp.position }} at {{ exp.organization }} ({{ exp.date }})
                                                </li>
                                            </ul>
                                            <div v-else>No experience listed.</div>
                                        </div>

                                        <!-- Awards -->
                                        <div>
                                            <strong>Awards/Instruction:</strong>
                                            <ul class="list-disc list-inside ml-4"
                                                v-if="person.award_instruction_abilities?.length">
                                                <li v-for="(award, index) in person.award_instruction_abilities"
                                                    :key="index">
                                                    {{ award.name }} —
                                                    <span v-if="award.level === 'T'">Capable of Teaching</span>
                                                    <span v-else-if="award.level === 'A'">Able to Assist</span>
                                                    <span v-else-if="award.level === 'I'">Interested in Learning</span>
                                                    <span v-else>{{ award.level }}</span>
                                                </li>
                                            </ul>
                                            <div v-else>No awards listed.</div>
                                        </div>

                                        <!-- Unlawful Conduct -->
                                        <div><strong>Unlawful Conduct:</strong> {{ person.unlawful_sexual_conduct ===
                                            'yes' ? 'Yes' : 'No' }}</div>
                                        <div v-if="person.unlawful_sexual_conduct === 'yes'">
                                            <strong>Conduct Records:</strong>
                                            <ul class="list-disc list-inside ml-4"
                                                v-if="person.unlawful_sexual_conduct_records?.length">
                                                <li v-for="(record, idx) in person.unlawful_sexual_conduct_records"
                                                    :key="idx">
                                                    {{ record.type || 'N/A' }} — {{ record.date_place || 'Unknown Date/Place' }}<br />
                                                    <span class="text-gray-600">Reference: {{ record.reference || 'N/A'
                                                        }}</span>
                                                </li>
                                            </ul>
                                        </div>

                                        <div><strong>Sterling Volunteer Completed:</strong> {{
                                            person.sterling_volunteer_completed ? 'Yes' : 'No' }}
                                        </div>

                                        <!-- References -->
                                        <div>
                                            <strong>References:</strong>
                                            <ul class="list-disc pl-5">
                                                <li v-if="person.reference_pastor">Pastor: {{ person.reference_pastor }}
                                                </li>
                                                <li v-if="person.reference_elder">Elder: {{ person.reference_elder }}
                                                </li>
                                                <li v-if="person.reference_other">Other: {{ person.reference_other }}
                                                </li>
                                                <li
                                                    v-if="!person.reference_pastor && !person.reference_elder && !person.reference_other">
                                                    No
                                                    references provided.</li>
                                            </ul>
                                        </div>

                                        <div><strong>Signed:</strong> {{ person.applicant_signature }} on {{
                                            person.application_signed_date.slice(0,
                                                10) }}</div>
                                    </div>
                                </td>
                            </tr>

                        </template>
                    </tbody>
                </table>
                <div class="mt-12 max-w-5xl mx-auto">
                    <div class="mb-4 flex space-x-4 border-b pb-2">
                        <button @click="activeTab = 'active'"
                            :class="activeTab === 'active' ? 'font-bold border-b-2 border-blue-600' : 'text-gray-500'">
                            Active Accounts
                        </button>
                        <button
                            v-if="sub_roles.some(user => user.status === 'deleted') && user.profile_type === 'club_director'"
                            @click="activeTab = 'deleted'"
                            :class="activeTab === 'deleted' ? 'font-bold border-b-2 border-red-600' : 'text-gray-500'">
                            Inactive Accounts
                        </button>
                        <button v-if="filteredPendingUsers.length"
                            @click="activeTab = 'pending'"
                            :class="activeTab === 'pending' ? 'font-bold border-b-2 border-amber-600' : 'text-gray-500'">
                            Pending Requests
                        </button>
                    </div>

                    <template v-if="activeTab !== 'pending'">
                        <table class="w-full text-sm border rounded overflow-hidden">
                            <thead class="bg-gray-100">
                                <tr>
                                    <th class="p-2 text-left">Name</th>
                                    <th class="p-2 text-left">Email</th>
                                    <th class="p-2 text-left">Role</th>
                                    <th class="p-2 text-left">Sub Role</th>
                                    <th class="p-2 text-left">Church</th>
                                    <th class="p-2 text-left">Status</th>
                                    <th class="p-2 text-left">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="user in filteredUsers" :key="user.id" class="border-t">
                                    <td class="p-2 text-xs">{{ user.name }}</td>
                                    <td class="p-2 text-xs">{{ user.email }}</td>
                                    <td class="p-2 text-xs">{{ user.profile_type }}</td>


                                    <td class="p-2 capitalize text-xs">
                                        <select id="sub_role" class="border p-1 rounded text-xs" v-model="user.sub_role">
                                            <option value="">-- Select Sub Role --</option>
                                            <option v-for="role in subRoles" :key="role.id" :value="role.key">
                                                {{ role.label }}
                                            </option>
                                        </select>
                                    </td>


                                    <!-- <td class="p-2 capitalize text-xs">{{ user.sub_role }}</td> -->
                                    <td class="p-2 text-xs">{{ user.church_name }}</td>
                                    <td class="p-2 text-xs">{{ user.status }}</td>
                                    <td class="p-2 text-xs">
                                        <template v-if="user.status === 'active'">
                                            <div class="flex items-center space-x-2">
                                                <button @click="changePassword(user)"class="px-2 py-1 bg-blue-600 text-white rounded text-xs hover:bg-blue-700">
                                                    Change Password
                                                </button>

                                                <button @click="updateStaffUserAccount(user, 301)"
                                                    class="text-red-600 hover:underline" title="Delete record">
                                                    <TrashIcon class="w-4 h-4 inline" />
                                                </button>

                                            <button v-if="createStaffMap[user.id] && selectedClub?.club_type === 'adventurers'"
                                                class="text-green-600 hover:underline" @click="openStaffForm(user)"
                                                title="Click to add user as staff">
                                                <UserPlusIcon class="w-5 h-5 text-green-600" />
                                            </button>
                                            </div>
                                        </template>
                                        <template v-else-if="user.status !== 'active'">
                                            <button @click="updateStaffUserAccount(user, 423)"
                                                class="text-blue-600 hover:underline">
                                                Reactivate Account
                                            </button>
                                        </template>
                                        <template v-else>
                                            <span class="text-gray-400 italic">No actions</span>
                                        </template>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </template>
                    <template v-else>
                        <table class="w-full text-sm border rounded overflow-hidden">
                            <thead class="bg-gray-100">
                                <tr>
                                    <th class="p-2 text-left">Name</th>
                                    <th class="p-2 text-left">Email</th>
                                    <th class="p-2 text-left">Role</th>
                                    <th class="p-2 text-left">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="u in filteredPendingUsers" :key="u.id" class="border-t">
                                    <td class="p-2 text-xs">{{ u.name }}</td>
                                    <td class="p-2 text-xs">{{ u.email }}</td>
                                    <td class="p-2 text-xs capitalize">{{ u.profile_type.replace('_',' ') }}</td>
                                    <td class="p-2 text-xs space-x-2">
                                        <button class="text-green-700" @click="approvePending(u.id)">Approve</button>
                                        <button class="text-red-600" @click="rejectPending(u.id)">Reject</button>
                                    </td>
                                </tr>
                                <tr v-if="filteredPendingUsers.length === 0">
                                    <td colspan="4" class="p-3 text-center text-gray-500">No pending requests.</td>
                                </tr>
                            </tbody>
                        </table>
                    </template>
                </div>

            </div>
        </div>
        <UpdatePasswordModal
            v-if="showPasswordModal && changePasswordUserId"
            :show="showPasswordModal"
            :user-id="changePasswordUserId"
            @close="showPasswordModal = false"
            @updated="showToast('Password updated successfully')"
        />

        <CreateStaffModal :show="createStaffModalVisible" :user="selectedUserForStaff" :club="selectedClub"
            :club-classes="clubClasses" :editing-staff="staffToEdit" @close="closeModal"
            @submitted="fetchStaff(selectedClub.id)" />

    </PathfinderLayout>
</template>
