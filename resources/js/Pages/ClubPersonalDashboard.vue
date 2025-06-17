<script setup>
import PathfinderLayout from '@/Layouts/PathfinderLayout.vue'
import axios from 'axios';
import { ref, onMounted, computed } from 'vue'
import { useForm, usePage } from '@inertiajs/vue3'
import CreateStaffModal from '@/Components/CreateStaffModal.vue' // Your modal component

const page = usePage()

const createStaffModalVisible = ref(false)
const selectedUserForStaff = ref(null)
const selectedClub = ref(null)

const hasStaffRecord = ref(false)
const staffRecord = ref(null)

const user = ref(null)
const clubs = ref([])

const toast = ref({ show: false, message: '', type: 'success' })

const showToast = (message, type = 'success') => {
    toast.value = { show: true, message, type }
    setTimeout(() => (toast.value.show = false), 3500)
}
const openStaffForm = (user) => {
    selectedUserForStaff.value = user
    createStaffModalVisible.value = true
}
const fetchClubs = async () => {
    console.log(user.value.church_name);
    try {
        const response = await axios.get('/clubs/by-church-name', {
            params: { church_name: user.value.church_name }
        })
        console.log(response);
        clubs.value = response.data

    } catch (error) {
        showToast('Error loading club', 'success')

        console.error('Failed to fetch clubs:', error)
    }

}
onMounted(async () => {
    try {
        const response = await axios.get('/staff/staff-record')
        console.log(response);
        hasStaffRecord.value = response.data.hasStaffRecord
        staffRecord.value = response.data.staffRecord
        user.value = response.data.user
        fetchClubs();
    } catch (error) {
        console.error('Failed to fetch staff record:', error)
    }
})
</script>

<template>
<PathfinderLayout>
    <template #title>Club Staff Dashboard</template>

    <div class="space-y-4 text-gray-800">
        <p class="text-lg">Welcome to the Pathfinder Club Admin Panel.</p>
        <div v-if=!hasStaffRecord>
            <label class="block mb-1 font-medium text-gray-700">Select a club</label>
            <select v-model="selectedClub" class="w-full p-2 border rounded">
                <option disabled value="">-- Choose a club --</option>
                <option v-for="club in clubs" :key="club.id" :value="club">
                    {{ club.club_name }} ({{ club.club_type }})
                </option>
            </select>
            <button class="text-green-600 hover:underline" @click="openStaffForm(user)">
                Create myself as Staff
            </button>
        </div>
        <div v-if="user" class="max-w-md bg-white shadow rounded p-6">
            <h2 class="text-xl font-bold mb-4">User Profile</h2>
            <dl class="space-y-2 text-sm">
                <div>
                    <dt class="font-semibold">ID</dt>
                    <dd>{{ user.id }}</dd>
                </div>
                <div>
                    <dt class="font-semibold">Name</dt>
                    <dd>{{ user.name }}</dd>
                </div>
                <div>
                    <dt class="font-semibold">Email</dt>
                    <dd>{{ user.email }}</dd>
                </div>
                <div>
                    <dt class="font-semibold">Email Verified</dt>
                    <dd>{{ user.email_verified_at ?? 'Not verified' }}</dd>
                </div>
                <div>
                    <dt class="font-semibold">Created At</dt>
                    <dd>{{ user.created_at }}</dd>
                </div>
                <div>
                    <dt class="font-semibold">Updated At</dt>
                    <dd>{{ user.updated_at }}</dd>
                </div>
                <div>
                    <dt class="font-semibold">Profile Type</dt>
                    <dd>{{ user.profile_type }}</dd>
                </div>
                <div>
                    <dt class="font-semibold">Sub Role</dt>
                    <dd>{{ user.sub_role }}</dd>
                </div>
                <div>
                    <dt class="font-semibold">Church Name</dt>
                    <dd>{{ user.church_name }}</dd>
                </div>
            </dl>
        </div>
    </div>
    <CreateStaffModal :show="createStaffModalVisible" :user="selectedUserForStaff" :club="selectedClub" @close="createStaffModalVisible = false" />

</PathfinderLayout>
</template>
