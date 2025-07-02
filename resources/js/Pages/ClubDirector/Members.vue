<script setup>
import PathfinderLayout from '@/Layouts/PathfinderLayout.vue'
import { ref, computed, onMounted, nextTick } from 'vue'
import { useForm, usePage } from '@inertiajs/vue3'
import MemberRegistrationModal from '@/Components/MemberRegistrationModal.vue'
import DeleteMemberModal from '@/Components/DeleteMemberModal.vue'

const page = usePage()

const auth_user = page.props?.auth.user
const clubs = ref([]);
const selectedClub = ref(null)
const members = ref([])
const expandedRows = ref(new Set())
const showRegistrationForm = ref(false)
const registrationFormSection = ref(null)
const showDeleteModal = ref(false)
const deletingMember = ref(null)
const toast = ref({ show: false, message: '', type: 'success' })
const selectedMemberIds = ref(new Set())
const selectAll = ref(false)
const selectedTab = ref('members')

const clubClasses = ref([])
const clubIds = computed(() => page.props?.auth?.user_club_ids ?? [])
const activeTabClass = 'border-b-2 border-blue-600 text-blue-600 font-semibold pb-2'
const inactiveTabClass = 'text-gray-500 hover:text-gray-700 pb-2'
const showToast = (message, type = 'success') => {
    toast.value = { show: true, message, type }
    setTimeout(() => (toast.value.show = false), 3500)
}
const memberForm = useForm({
    club_id: '',
    club_name: '',
    director_name: '',
    church_name: '',

    applicant_name: '',
    birthdate: '',
    age: '',
    grade: '',
    mailing_address: '',
    cell_number: '',
    emergency_contact: '',

    investiture_classes: [],

    allergies: '',
    physical_restrictions: '',
    health_history: '',

    parent_name: '',
    parent_cell: '',
    home_address: '',
    email_address: '',
    signature: '',
})
const downloadWord = (memberId) => {
    window.open(`/members/${memberId}/export-word`, '_blank')
}

const deleteMember = (member) => {
    deletingMember.value = member
    showDeleteModal.value = true
}
const handleMemberDelete = async ({ id, notes }) => {
    try {
        await axios.post(`/members/${id}`, {
            notes_deleted: notes,
            _method: 'DELETE'
        })
        showDeleteModal.value = false
        deletingMember.value = null
        await fetchMembers(selectedClub.value.id)
        showToast('Member deleted successfully.', 'success')

    } catch (err) {
        console.error('Failed to delete:', err)
        showToast('Error deleting member.', 'error')

    }
}
const toggleRegistrationForm = async () => {
    showRegistrationForm.value = !showRegistrationForm.value

    if (showRegistrationForm.value) {
        await nextTick()
        registrationFormSection.value?.scrollIntoView({ behavior: 'smooth', block: 'start' })
    }
}
const toggleExpanded = (id) => {
    if (expandedRows.value.has(id)) {
        expandedRows.value.delete(id)
    } else {
        expandedRows.value.add(id)
    }
}

const formatDate = (isoString) => {
    return new Date(isoString).toLocaleDateString()
}

const fetchClubs = async () => {
    console.log(auth_user)
    const clubIds = auth_user.clubs.map(club => club.id)

    try {
        const response = await axios.get('/clubs/by-ids', {
            params: { ids: clubIds }
        })
        clubs.value = response.data

    } catch (error) {
        showToast('Error loading club', 'success')

        console.error('Failed to fetch clubs:', error)
    }

}
const fetchMembers = async (clubId) => {
    try {
        const response = await axios.get(`/clubs/${clubId}/members`)
        const data = response.data.members
        console.log('Fetched members:', data)
        if (Array.isArray(data) && data.length > 0) {
            members.value = data
            showToast('Members loaded', 'success')
        } else{
            members.value = []
            alert('No members found for this club.')
        }
    } catch (error) {
        console.error('Failed to fetch members:', error)
        showToast('Error fetching members', 'error')
    }
}



const toggleSelectAll = () => {
    if (selectAll.value) {
        selectedMemberIds.value = new Set(members.value.map(m => m.id))
    } else {
        selectedMemberIds.value.clear()
    }
}

const toggleSelectMember = (id) => {
    if (selectedMemberIds.value.has(id)) {
        selectedMemberIds.value.delete(id)
    } else {
        selectedMemberIds.value.add(id)
    }
}

const handleBulkAction = async (action,type_request=null) => {
    if (selectedMemberIds.value.size === 0) {
        alert('No members selected.')
        return
    }

    const ids = Array.from(selectedMemberIds.value)

    if (action === 'delete') {
        const confirmed = window.confirm('Are you sure you want to delete the selected members? This action cannot be undone.');

        if (!confirmed) return;
        try {
            for (const id of selectedMemberIds.value) {
                await axios.delete(`/members/${id}`, {
                    data: { notes_deleted: 'Bulk deleted' }
                });
            }

            await fetchMembers(selectedClub.value.id);

            toast.value = {
                show: true,
                message: 'Selected members deleted.',
                type: 'success'
            };

            selectedMemberIds.value.clear();
            selectAll.value = false;
        } catch (error) {
            console.error('Bulk deletion failed:', error);
            toast.value = {
                show: true,
                message: 'Error deleting selected members.',
                type: 'error'
            };
        }
    }

    if (action === 'download') {
        try {
            const response = await axios.post(`/export/${type_request}/zip`, {
                [type_request === 'staff' ? 'staff_adventurer_ids' : 'member_ids']: Array.from(selectedMemberIds.value),
            }, {
                responseType: 'blob'
            });

            const blob = new Blob([response.data], { type: 'application/zip' });
            const link = document.createElement('a');
            link.href = URL.createObjectURL(blob);
            link.download = `${type_request}_export.zip`;
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            URL.revokeObjectURL(link.href);
        } catch (err) {
            console.error(`Failed to download ${type_request} ZIP:`, err);
        }
    }
}
//WIP
const onClubChange = async () => {
    if (selectedClub.value) {
        memberForm.club_id = selectedClub.value.id
        memberForm.club_name = selectedClub.value.club_name
        memberForm.director_name = selectedClub.value.director_name
        memberForm.church_name = selectedClub.value.church_name

        await fetchMembers(selectedClub.value.id)
        await fetchClubClasses(selectedClub.value.id)
    }
}
const fetchClubClasses = async (clubId) => {
    try {
        const response = await axios.get(`/clubs/${clubId}/classes`)
        clubClasses.value = response.data
        console.log('Fetched club classes:', clubClasses.value)
    } catch (error) {
        console.error('Failed to fetch club classes:', error)
    }
}
const getStaffName = (id) => {
    const staff = selectedClub.value?.staff_adventurers?.find(s => s.id === id)
    return staff ? staff.name : '—'
}
const moveMemberToClass = async (memberId, newClassName) => {
    try {
        await axios.post(`/members/${memberId}/move-class`, {
            new_class_name: newClassName
        })
        await fetchMembers(selectedClub.value.id)
        showToast('Member moved successfully.', 'success')
    } catch (error) {
        console.error('Failed to move member:', error)
        showToast('Failed to move member.', 'error')
    }
}


onMounted(fetchClubs)
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
            <h1 class="text-xl font-bold mb-4">Members</h1>

            <!-- Tabs -->
            <div class="mb-4 border-b">
                <nav class="-mb-px flex space-x-6">
                    <button
                        :class="selectedTab === 'members' ? activeTabClass : inactiveTabClass"
                        @click="selectedTab = 'members'"
                    >
                        Members
                    </button>
                    <button
                        :class="selectedTab === 'classes' ? activeTabClass : inactiveTabClass"
                        @click="selectedTab = 'classes'"
                    >
                        Classes Overview
                    </button>
                </nav>
            </div>

            <!-- Club Selector -->
            <div class="max-w-xl mb-6">
                <label class="block mb-1 font-medium text-gray-700">Select a club</label>
                <select v-model="selectedClub" @change="onClubChange" class="w-full p-2 border rounded">
                    <option disabled value="">-- Choose a club --</option>
                    <option v-for="club in clubs" :key="club.id" :value="club">
                        {{ club.club_name }} ({{ club.club_type }})
                    </option>
                </select>
            </div>

            <!-- Tab 1: Members Table -->
            <div v-if="selectedTab === 'members' && selectedClub && selectedClub.club_type === 'adventurers'">
                <div class="flex items-center justify-between mb-4">
                <div class="flex items-center gap-4">
                    <label class="inline-flex items-center">
                        <input type="checkbox" v-model="selectAll" @change="toggleSelectAll" class="mr-2" />
                        <span>Select All</span>
                    </label>
                    <select v-if="selectedMemberIds.size > 0" @change="e => handleBulkAction(e.target.value,'member')" class="border p-2 px-4 rounded w-60 text-sm">
                        <option value="" disabled selected>Bulk Actions</option>
                        <option value="delete">Delete Selected</option>
                        <option value="download">Download Forms</option>
                    </select>
                </div>
                <span class="text-sm text-gray-600">{{ selectedMemberIds.size }} selected</span>
            </div>
            <table class="w-full text-sm border rounded overflow-hidden">
                <thead class="bg-gray-200">
                    <tr>
                        <th class="p-2 text-left"></th>
                        <th class="p-2 text-left">Name</th>
                        <th class="p-2 text-left">Home Address</th>
                        <th class="p-2 text-left">Last completed</th>
                        <th class="p-2 text-left">Parent Cell</th>
                        <th class="p-2 text-left">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <template v-for="member in members" :key="member.id">
                        <!-- Main Row -->
                        <tr class="border-t">
                            <td class="p-2">
                                <input type="checkbox" :value="member.id" :checked="selectedMemberIds.has(member.id)" @change="() => toggleSelectMember(member.id)" />
                            </td>
                            <td class="p-2 font-semibold">{{ member.applicant_name }}</td>
                            <td class="p-2">{{ member.home_address }}</td>
                            <td class="p-2">
                                <span v-if="Array.isArray(member.investiture_classes)">
                                    {{ member.investiture_classes.join(', ') }}
                                </span>
                            </td>
                            <td class="p-2">{{ member.parent_cell }}</td>
                            <td class="p-2">
                                <button class="text-green-600 hover:underline" @click="toggleExpanded(member.id)">
                                    {{ expandedRows.has(member.id) ? 'Hide' : 'Details' }}
                                </button> &nbsp;&nbsp;
                                <button class="text-red-600 hover:underline" @click="deleteMember(member)">Delete</button>
                                &nbsp;&nbsp;
                                <button class="text-blue-600 hover:underline" @click="downloadWord(member.id)">Download form</button>
                            </td>
                        </tr>

                        <!-- Expandable Child Row -->
                        <tr v-if="expandedRows.has(member.id)" class="bg-gray-50 border-t">
                            <td colspan="6" class="p-4">
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-gray-700">
                                    <div><strong>Birthdate:</strong> {{ formatDate(member.birthdate) }}</div>
                                    <div><strong>Age:</strong> {{ member.age }}</div>
                                    <div><strong>Grade:</strong> {{ member.grade }}</div>
                                    <div><strong>Mailing Address:</strong> {{ member.mailing_address }}</div>
                                    <div><strong>Cell Number:</strong> {{ member.cell_number }}</div>
                                    <div><strong>Emergency Contact:</strong> {{ member.emergency_contact }}</div>
                                    <div><strong>Allergies:</strong> {{ member.allergies }}</div>
                                    <div><strong>Physical Restrictions:</strong> {{ member.physical_restrictions }}</div>
                                    <div><strong>Health History:</strong> {{ member.health_history }}</div>
                                    <div><strong>Parent Name:</strong> {{ member.parent_name }}</div>
                                    <div><strong>Email Address:</strong> {{ member.email_address }}</div>
                                    <div><strong>Signature:</strong> {{ member.signature }}</div>
                                </div>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
            <div class="mt-6 text-center">
                <button @click="toggleRegistrationForm" class="bg-green-600 text-white px-4 py-2 rounded hover:bg-green-700">
                    {{ showRegistrationForm ? 'Hide Form' : 'Register New Member' }}
                </button>
            </div>
            </div>

            <!-- Tab 2: Class Overview -->
            <div v-if="selectedTab === 'classes' && selectedClub">
                <h2 class="text-lg font-semibold mb-4">Class Overview</h2>
                <div v-if="clubClasses.length === 0" class="text-gray-600">
                    No classes found for this club.
                </div>
                <div v-else class="space-y-6">
                    <div v-for="clubClass in clubClasses" :key="clubClass.id" class="border rounded p-4 bg-gray-50">
                        <h3 class="text-md font-bold">
                            {{ clubClass.class_name }} (Order: {{ clubClass.class_order }})
                        </h3>
                        <p class="text-sm text-gray-700 mb-2">
                            Assigned Staff: {{ clubClass.assigned_staff.name }}
                        </p>
                        <table class="w-full border text-sm">
                            <thead class="bg-gray-100">
                                <tr>
                                    <th class="p-2">Name</th>
                                    <th class="p-2">Age</th>
                                    <th class="p-2">Move to Class</th>
                                </tr>
                            </thead>
                            <tbody>
                            NEED TO ADD ASSIGNED CLASS TO members_adventurers table
                                <tr
                                    v-for="member in members"
                                    :key="member.id"
                                >
                                    <td class="p-2">{{ member.applicant_name }}</td>
                                    <td class="p-2">{{ member.age }}</td>
                                    <td class="p-2">
                                        <select
                                            v-model="member.assigned_class"
                                            @change="e => moveMemberToClass(member.id, e.target.value)"
                                            class="border p-1 rounded"
                                        >
                                            <option
                                                v-for="targetClass in clubClasses"
                                                :key="targetClass.id"
                                                :value="targetClass.class_name"
                                            >
                                                {{ targetClass.class_name }}
                                            </option>
                                        </select>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- TOAST -->
            <transition name="fade">
                <div
                    v-if="toast.show"
                    :class="[
                        'fixed top-6 right-6 z-50 px-4 py-2 rounded shadow text-white',
                        toast.type === 'success' ? 'bg-green-600' : 'bg-red-600'
                    ]"
                >
                    {{ toast.message }}
                </div>
            </transition>

            <!-- MODALS -->
            <MemberRegistrationModal
                :show="showRegistrationForm"
                :clubs="clubs"
                :selectedClub="selectedClub"
                @close="showRegistrationForm = false"
                @submitted="fetchMembers(selectedClub.id)"
            />
            <DeleteMemberModal
                :show="showDeleteModal"
                :memberId="deletingMember?.id"
                :memberName="deletingMember?.applicant_name"
                @cancel="showDeleteModal = false"
                @confirm="handleMemberDelete"
            />
        </div>
    </PathfinderLayout>
</template>

