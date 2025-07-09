<script setup>
import { ref, computed, onMounted, watch } from 'vue'
import PathfinderLayout from '@/Layouts/PathfinderLayout.vue'
import CreateStaffModal from '@/Components/CreateStaffModal.vue'

import { useAuth } from '@/Composables/useAuth'
import { useGeneral } from '@/Composables/useGeneral'

import {
    fetchClubsByIds,
    fetchStaffByClubId,
    createStaffUser,
    updateStaffStatus,
    updateUserStatus,
    downloadStaffZip
} from '@/Services/api'

// ✅ Auth & general utilities
const { user } = useAuth()
const { toast } = useGeneral()

// ✅ State
const selectedClub = ref(null)
const clubs = ref([])
const staff = ref([])
const sub_roles = ref([])
const createStaffModalVisible = ref(false)
const selectedUserForStaff = ref(null)
const expandedRows = ref(new Set())
const selectAll = ref(false)
const selectedStaffIds = ref(new Set())
const activeTab = ref('active')
const activeStaffTab = ref('active')

// ✅ Create staff eligibility map
const createStaffMap = computed(() => {
    const map = {}
    sub_roles.value.forEach(user => {
        map[user.id] = user.create_staff === true
    })
    return map
})

// ✅ Filtered lists
const filteredUsers = computed(() =>
    sub_roles.value.filter(user => user.status === (activeTab.value === 'active' ? 'active' : 'deleted'))
)

const filteredStaff = computed(() =>
    staff.value.filter(person => person.status === activeStaffTab.value)
)

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
        const response = await fetchClubsByIds([user.value.club_id])
        clubs.value = response
    } catch (error) {
        console.error('Failed to fetch clubs:', error)
        toast.error('Error loading clubs')
    }
}

// ✅ Fetch staff list
const fetchStaff = async (clubId) => {
    try {
        const data = await fetchStaffByClubId(clubId)
        staff.value = data.staff
        sub_roles.value = data.sub_role_users
        toast.success('Staff loaded')
    } catch (error) {
        console.error('Failed to fetch staff:', error)
        toast.error('Error loading staff')
    }
}

// ✅ Modals
const openStaffForm = (user) => {
    selectedUserForStaff.value = user
    createStaffModalVisible.value = true
}

// ✅ Account management
const updateStaffAccount = async (staff, status_code) => {
    const action = status_code === 301 ? 'deactivate' : 'reactivate'
    if (!confirm(`Are you sure you want to ${action} the staff member ${staff.name}?`)) return

    try {
        await updateStaffStatus(staff.id, status_code)
        toast.success(`Staff ${action}d`)
        fetchStaff(staff.club_id)
    } catch (error) {
        console.error('Failed to update staff status:', error)
        toast.error(`Failed to ${action} staff`)
    }
}

const updateStaffUserAccount = async (user, status_code) => {
    const action = status_code === 301 ? 'deactivate' : 'reactivate'
    if (!confirm(`Are you sure you want to ${action} the user account for ${user.name}?`)) return

    try {
        await updateUserStatus(user.id, status_code)
        toast.success(`User ${action}d`)
        fetchStaff(user.club_id)
    } catch (error) {
        console.error('Failed to update user status:', error)
        toast.error(`Failed to ${action} user`)
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
        toast.success('User created successfully')
        person.create_user = false
        fetchStaff(person.club_id)
    } catch (err) {
        console.error('Create user error:', err)
        toast.error('Failed to create user')
    }
}

// ✅ Bulk actions
const handleBulkAction = async (action) => {
    if (selectedStaffIds.value.size === 0) {
        toast.error('No staff selected.')
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
        toast.success(`Staff ${isReactivate ? 'reactivated' : 'deactivated'}`)
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

const downloadSelectedZip = async () => {
    try {
        await downloadStaffZip(Array.from(selectedStaffIds.value))
    } catch (error) {
        console.error('Download failed:', error)
        toast.error('Failed to download ZIP')
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

onMounted(fetchClubs)
</script>


<template>
    <PathfinderLayout>
        <div class="p-8">
            <h1 class="text-xl font-bold mb-4">Staff</h1>
            <div class="max-w-xl mb-6">
                <label class="block mb-1 font-medium text-gray-700">Select a club</label>
                <select v-model="selectedClub" @change="() => { if (selectedClub) { fetchStaff(selectedClub.id) } }"
                    class="w-full p-2 border rounded">
                    <option disabled value="">-- Choose a club --</option>
                    <option v-for="club in clubs" :key="club.id" :value="club">
                        {{ club.club_name }} ({{ club.club_type }})
                    </option>
                </select><br><br>
                <button v-if="selectedClub && selectedClub.club_type === 'adventurers'"
                    class="bg-green-600 text-white px-4 py-2 rounded hover:bg-green-700" @click="openStaffForm(user)">
                    Create Staff</button>

            </div>

            <div v-if="selectedClub && selectedClub.club_type === 'adventurers'" class="max-w-5xl mx-auto">

                <div class="mb-4 flex space-x-4 border-b pb-2">
                    <button @click="activeStaffTab = 'active'"
                        :class="activeStaffTab === 'active' ? 'font-bold border-b-2 border-blue-600' : 'text-gray-500'">
                        Active Staff
                    </button>
                    <button
                        v-if="staff.some(person => person.status === 'deleted') && auth_user.profile_type === 'club_director'"
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

                <table class="w-full text-sm border rounded overflow-hidden">
                    <thead class="bg-gray-200">
                        <tr>
                            <th class="p-2 text-left"></th>
                            <th class="p-2 text-left">Name</th>
                            <th class="p-2 text-left">DOB</th>
                            <th class="p-2 text-left">Address</th>
                            <th class="p-2 text-left">Class</th>
                            <th class="p-2 text-left">Cell</th>
                            <th class="p-2 text-left">Email</th>
                            <th class="p-2 text-left">Status</th>
                            <th class="p-2 text-left">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template v-for="person in filteredStaff" :key="person.id">
                            <tr class="border-t">
                                <td class="p-2">
                                    <input type="checkbox" :value="person.id" :checked="selectedStaffIds.has(person.id)"
                                        @change="() => toggleSelectStaff(person.id)" />
                                </td>
                                <td class="p-2">{{ person.name }}</td>
                                <td class="p-2">{{ person.dob.slice(0, 10) }}</td>
                                <td class="p-2">{{ person.address }}</td>
                                <td class="p-2">{{ person.assigned_class }}</td>
                                <td class="p-2">{{ person.cell_phone }}</td>
                                <td class="p-2">{{ person.email }}</td>
                                <td class="p-2">{{ person.status }}</td>
                                <td class="p-2">
                                    <button class="text-green-600 hover:underline" @click="toggleExpanded(person.id)">
                                        {{ expandedRows.has(person.id) ? 'Hide' : 'Details' }}
                                    </button>&nbsp;&nbsp;
                                    <button class="text-orange-600 hover:underline" @click="createUser(person)"
                                        v-if="person.create_user">
                                        Create user
                                    </button>
                                    &nbsp;&nbsp;
                                    <button class="text-blue-600 hover:underline"
                                        @click="downloadWord(person.id)">Download form</button>
                                    &nbsp;&nbsp;
                                    <template v-if="person.status === 'active'">
                                        <button @click="updateStaffAccount(person, 301)"
                                            class="text-red-600 hover:underline">
                                            Delete staff
                                        </button>
                                    </template>
                                    <template v-else>
                                        <button @click="() => updateStaffAccount(person, 423)"
                                            class="text-gray-600 hover:underline">
                                            Reactivate
                                        </button>
                                    </template>
                                </td>
                            </tr>

                            <tr v-if="expandedRows.has(person.id)" class="bg-gray-50 border-t">
                                <td colspan="7" class="p-4 text-gray-700">
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
                            v-if="sub_roles.some(user => user.status === 'deleted') && auth_user.profile_type === 'club_director'"
                            @click="activeTab = 'deleted'"
                            :class="activeTab === 'deleted' ? 'font-bold border-b-2 border-red-600' : 'text-gray-500'">
                            Inactive Accounts
                        </button>
                    </div>

                    <table class="w-full text-sm border rounded overflow-hidden">
                        <thead class="bg-gray-100">
                            <tr>
                                <th class="p-2 text-left">Name</th>
                                <th class="p-2 text-left">Email</th>
                                <th class="p-2 text-left">Sub Role</th>
                                <th class="p-2 text-left">Church</th>
                                <th class="p-2 text-left">Status</th>
                                <th class="p-2 text-left">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="user in filteredUsers" :key="user.id" class="border-t">
                                <td class="p-2">{{ user.name }}</td>
                                <td class="p-2">{{ user.email }}</td>
                                <td class="p-2 capitalize">{{ user.sub_role }}</td>
                                <td class="p-2">{{ user.church_name }}</td>
                                <td class="p-2">{{ user.status }}</td>
                                <td class="p-2">
                                    <template v-if="user.status === 'active'">
                                        <button @click="updateStaffUserAccount(user, 301)"
                                            class="text-red-600 hover:underline">
                                            Delete Account
                                        </button>
                                        &nbsp;&nbsp;
                                        <button v-if="createStaffMap[user.id]" class="text-green-600 hover:underline"
                                            @click="openStaffForm(user)">
                                            Create Staff
                                        </button>
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
                </div>
            </div>
        </div>
        <CreateStaffModal :show="createStaffModalVisible" :user="selectedUserForStaff" :club="selectedClub"
            @close="createStaffModalVisible = false" @submitted="fetchStaff(selectedClub.id)" />
    </PathfinderLayout>
</template>
