<script setup>
import PathfinderLayout from '@/Layouts/PathfinderLayout.vue'
import { ref, computed, onMounted, watch } from 'vue'
import { useForm, usePage } from '@inertiajs/vue3'
import CreateStaffModal from '@/Components/CreateStaffModal.vue'
import { useToast } from 'vue-toastification'

const page = usePage()
const auth_user = page.props?.auth.user
const selectedClub = ref(null)
const clubs = ref([]);
const staff = ref([])
const sub_roles = ref([])
const expandedRows = ref(new Set())

const createStaffModalVisible = ref(false)
const selectedUserForStaff = ref(null)
const selectAll = ref(false)
const selectedStaffIds = ref(new Set())
const toast = useToast()
const createStaffMap = computed(() => {
    const map = {}
    sub_roles.value.forEach(user => {
        map[user.id] = user.create_staff === true
    })
    return map
})

const openStaffForm = (user) => {
    selectedUserForStaff.value = user
    createStaffModalVisible.value = true
}

const fetchStaff = async (clubId) => {
    try {
        const response = await axios.get(`/clubs/${clubId}/staff`)

        staff.value = response.data.staff
        sub_roles.value = response.data.sub_role_users
        toast.success('Staff loaded')
    } catch (error) {
        console.error('Failed to fetch staff:', error)
    }
}
const updateStaffAccount = async (staff, new_status) => {
    const action = new_status === 301 ? 'delete' : 'reactivate';
    const confirmed = confirm(`Are you sure you want to ${action} the user account for ${staff.name}?`);
    if (!confirmed) return;

    const staff_id = staff.id;
    const club_id = staff.club_id;

    try {
        const response = await axios.post('/staff/update-staff-account', {
            staff_id: staff_id,
            status_code: new_status
        });

        if (response.data.success) {
            toast.success(response.data.message)
            fetchStaff(club_id);
        } else {
            console.warn('Unexpected status:', response.data);
        }
    } catch (error) {
        console.error('Failed to delete staff account:', error);
    }
}
const updateStaffUserAccount = async (user, new_status) => {
    const action = new_status === 301 ? 'delete' : 'reactivate';
    const confirmed = confirm(`Are you sure you want to ${action} the user account for ${user.name}?`);
    if (!confirmed) return;

    const user_id = user.id;
    const club_id = user.club_id;

    try {
        const response = await axios.post('/staff/update-user-account', {
            user_id: user_id,
            status_code: new_status
        });

        if (response.data.success) {
            toast.success(response.data.message)
            fetchStaff(club_id);
        } else {
            console.warn('Unexpected status:', response.data);
        }
    } catch (error) {
        console.error('Failed to delete user account:', error);
    }
};

const toggleExpanded = (id) => {
    if (expandedRows.value.has(id)) {
        expandedRows.value.delete(id)
    } else {
        expandedRows.value.add(id)
    }
}
const createUser = async (person) => {
    try {
        const response = await axios.post('/staff/create-user', {
            name: person.name,
            email: person.email,
            church_name: person.church_name,
            church_id: person.church_id,
            club_id: person.club_id,
        });
        toast.success('User created successfully!')
        person.create_user = false; // Hide button
        fetchStaff(person.club_id);
    } catch (err) {
        console.error(err);
        alert(err);
    }
};

const downloadWord = (staffId) => {
    window.open(`/staff/${staffId}/export-word`, '_blank');
}

const toggleSelectStaff = (id) => {
    if (selectedStaffIds.value.has(id)) {
        selectedStaffIds.value.delete(id)
    } else {
        selectedStaffIds.value.add(id)
    }
}
const toggleSelectAll = () => {
    if (selectAll.value) {
        selectedStaffIds.value = new Set(staff.value.map(m => m.id))
    } else {
        selectedStaffIds.value.clear()
    }
}
const handleBulkAction = async (action, type_request = null) => {
    if (selectedStaffIds.value.size === 0) {
        toast.error('No staff selected.');
        return
    }

    const ids = Array.from(selectedStaffIds.value)

    if (action === 'delete') {
        const confirmed = window.confirm('Are you sure you want to deactivate the selected staff? This will also prevent staff for accessing the system.');

        if (!confirmed) return;
        try {
            for (const id of selectedStaffIds.value) {

                await axios.post('/staff/update-staff-account', {
                    staff_id: id,
                    status_code: 301
                });
            }

            await fetchStaff(selectedClub.value.id);

            toast.success('Selected staff deleted')

            selectedStaffIds.value.clear();
            selectAll.value = false;
        } catch (error) {
            console.error('Bulk deletion failed:', error);
            toast.error('Error deleting selected members.')
        }
    }
    if (action === 'reactivate') {
        const confirmed = window.confirm('Are you sure you want to delete the selected staff? This action cannot be undone.');

        if (!confirmed) return;
        try {
            for (const id of selectedStaffIds.value) {

                await axios.post('/staff/update-staff-account', {
                    staff_id: id,
                    status_code: 423
                });
            }

            await fetchStaff(selectedClub.value.id);

            toast.success('Selected staff deleted')

            selectedStaffIds.value.clear();
            selectAll.value = false;
        } catch (error) {
            console.error('Bulk deletion failed:', error);
            toast.error('Error deleting selected members.')
        }
    }
    if (action === 'download') {
        try {
            const response = await axios.post(`/export/${type_request}/zip`, {
                [type_request === 'staff' ? 'staff_adventurer_ids' : 'member_ids']: Array.from(selectedStaffIds.value),
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
const activeTab = ref('active');

const filteredUsers = computed(() =>
    sub_roles.value.filter(user => user.status === (activeTab.value === 'active' ? 'active' : 'deleted'))
);
watch(sub_roles, (newVal) => {
    if (Array.isArray(newVal)) {
        if (!newVal.some(user => user.status === 'deleted')) {
            activeTab.value = 'active';
        }
    }
}, { immediate: true });

const fetchClubs = async () => {
    try {
        const response = await axios.get('/clubs/by-ids', {
            params: { ids: parseInt(auth_user.club_id) }
        })
        clubs.value = response.data

    } catch (error) {
        toast.error(error)
        console.error('Failed to fetch clubs:', error)
    }

}
onMounted(fetchClubs)
const activeStaffTab = ref('active');

const filteredStaff = computed(() => {
    return staff.value.filter(person => person.status === activeStaffTab.value);
});
watch(staff, (newVal) => {
    if (Array.isArray(newVal)) {
        if (!newVal.some(user => staff.status === 'deleted')) {
            activeStaffTab.value = 'active';
        }
    }
}, { immediate: true });
</script>
<template>
<PathfinderLayout>
    <div class="p-8">
        <h1 class="text-xl font-bold mb-4">Staff</h1>
        <div class="max-w-xl mb-6">
            <label class="block mb-1 font-medium text-gray-700">Select a club</label>
            <select v-model="selectedClub" @change="() => { if (selectedClub) { fetchStaff(selectedClub.id) } }" class="w-full p-2 border rounded">
                <option disabled value="">-- Choose a club --</option>
                <option v-for="club in clubs" :key="club.id" :value="club">
                    {{ club.club_name }} ({{ club.club_type }})
                </option>
            </select><br><br>
            <button v-if="selectedClub && selectedClub.club_type === 'adventurers'" class="bg-green-600 text-white px-4 py-2 rounded hover:bg-green-700" @click="openStaffForm(user)"> Create Staff</button>

        </div>

        <div v-if="selectedClub && selectedClub.club_type === 'adventurers'" class="max-w-5xl mx-auto">

            <div class="mb-4 flex space-x-4 border-b pb-2">
                <button @click="activeStaffTab = 'active'" :class="activeStaffTab === 'active' ? 'font-bold border-b-2 border-blue-600' : 'text-gray-500'">
                    Active Staff
                </button>
                <button v-if="staff.some(person => person.status === 'deleted') && auth_user.profile_type === 'club_director'" @click="activeStaffTab = 'deleted'" :class="activeStaffTab === 'deleted' ? 'font-bold border-b-2 border-red-600' : 'text-gray-500'">
                    Inactive Staff
                </button>
            </div>
            <div class="flex items-center justify-between mb-4">
                <div class="flex items-center gap-4">
                    <label class="inline-flex items-center">
                        <input type="checkbox" v-model="selectAll" @change="toggleSelectAll" class="mr-2" />
                        <span>Select All</span>
                    </label>
                    <select v-if="selectedStaffIds.size > 0" @change="e => handleBulkAction(e.target.value, 'staff')" class="border p-2 px-4 rounded w-60 text-sm">
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
                                <input type="checkbox" :value="person.id" :checked="selectedStaffIds.has(person.id)" @change="() => toggleSelectStaff(person.id)" />
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
                                <button class="text-orange-600 hover:underline" @click="createUser(person)" v-if="person.create_user">
                                    Create user
                                </button>
                                &nbsp;&nbsp;
                                <button class="text-blue-600 hover:underline" @click="downloadWord(person.id)">Download form</button>
                                &nbsp;&nbsp;
                                <template v-if="person.status === 'active'">
                                    <button @click="updateStaffAccount(person, 301)" class="text-red-600 hover:underline">
                                        Delete staff
                                    </button>
                                </template>
                                <template v-else>
                                    <button @click="() => updateStaffAccount(person, 423)" class="text-gray-600 hover:underline">
                                        Reactivate
                                    </button>
                                </template>
                            </td>
                        </tr>

                        <tr v-if="expandedRows.has(person.id)" class="bg-gray-50 border-t">
                            <td colspan="7" class="p-4 text-gray-700">
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                    <div><strong>City/State/ZIP:</strong> {{ person.city }}, {{ person.state }} {{ person.zip }}</div>
                                    <div><strong>Club Name:</strong> {{ person.club_name }}</div>
                                    <div><strong>Church Name:</strong> {{ person.church_name }}</div>

                                    <div><strong>Health Limitation:</strong> {{ person.has_health_limitation ? 'Yes' : 'No' }}</div>
                                    <div v-if="person.has_health_limitation"><strong>Limitation Details:</strong> {{ person.health_limitation_description }}</div>

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
                                        <ul class="list-disc list-inside ml-4" v-if="person.award_instruction_abilities?.length">
                                            <li v-for="(award, index) in person.award_instruction_abilities" :key="index">
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
                                    <div><strong>Unlawful Conduct:</strong> {{ person.unlawful_sexual_conduct === 'yes' ? 'Yes' : 'No' }}</div>
                                    <div v-if="person.unlawful_sexual_conduct === 'yes'">
                                        <strong>Conduct Records:</strong>
                                        <ul class="list-disc list-inside ml-4" v-if="person.unlawful_sexual_conduct_records?.length">
                                            <li v-for="(record, idx) in person.unlawful_sexual_conduct_records" :key="idx">
                                                {{ record.type || 'N/A' }} — {{ record.date_place || 'Unknown Date/Place' }}<br />
                                                <span class="text-gray-600">Reference: {{ record.reference || 'N/A' }}</span>
                                            </li>
                                        </ul>
                                    </div>

                                    <div><strong>Sterling Volunteer Completed:</strong> {{ person.sterling_volunteer_completed ? 'Yes' : 'No' }}</div>

                                    <!-- References -->
                                    <div>
                                        <strong>References:</strong>
                                        <ul class="list-disc pl-5">
                                            <li v-if="person.reference_pastor">Pastor: {{ person.reference_pastor }}</li>
                                            <li v-if="person.reference_elder">Elder: {{ person.reference_elder }}</li>
                                            <li v-if="person.reference_other">Other: {{ person.reference_other }}</li>
                                            <li v-if="!person.reference_pastor && !person.reference_elder && !person.reference_other">No references provided.</li>
                                        </ul>
                                    </div>

                                    <div><strong>Signed:</strong> {{ person.applicant_signature }} on {{ person.application_signed_date.slice(0, 10) }}</div>
                                </div>
                            </td>
                        </tr>

                    </template>
                </tbody>
            </table>
            <div class="mt-12 max-w-5xl mx-auto">
                <div class="mb-4 flex space-x-4 border-b pb-2">
                    <button @click="activeTab = 'active'" :class="activeTab === 'active' ? 'font-bold border-b-2 border-blue-600' : 'text-gray-500'">
                        Active Accounts
                    </button>
                    <button v-if="sub_roles.some(user => user.status === 'deleted') && auth_user.profile_type === 'club_director'" @click="activeTab = 'deleted'" :class="activeTab === 'deleted' ? 'font-bold border-b-2 border-red-600' : 'text-gray-500'">
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
                                    <button @click="updateStaffUserAccount(user, 301)" class="text-red-600 hover:underline">
                                        Delete Account
                                    </button>
                                    &nbsp;&nbsp;
                                    <button v-if="createStaffMap[user.id]" class="text-green-600 hover:underline" @click="openStaffForm(user)">
                                        Create Staff
                                    </button>
                                </template>
                                <template v-else-if="user.status !== 'active'">
                                    <button @click="updateStaffUserAccount(user, 423)" class="text-blue-600 hover:underline">
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
    <CreateStaffModal :show="createStaffModalVisible" :user="selectedUserForStaff" :club="selectedClub" @close="createStaffModalVisible = false" @submitted="fetchStaff(selectedClub.id)" />
</PathfinderLayout>
</template>
