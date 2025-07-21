<script setup>
import PathfinderLayout from "@/Layouts/PathfinderLayout.vue";
import { ref, onMounted, computed } from "vue";
import { usePage } from "@inertiajs/vue3";
import CreateStaffModal from "@/Components/CreateStaffModal.vue";
import UpdatePasswordModal from "@/Components/ChangePassword.vue";
import { useGeneral } from "@/Composables/useGeneral";
import { fetchClubsByChurch, fetchStaffRecord } from "@/Services/api";

const page = usePage();
const { showToast } = useGeneral();

const createStaffModalVisible = ref(false);
const selectedUserForStaff = ref(null);
const selectedClub = ref(null);
const hasStaffRecord = ref(false);
const staffRecord = ref(null);
const user = ref(null);
const userId = computed(() => user.value?.id || null)

const clubs = ref([]);
const showPasswordModal = ref(false);

const openStaffForm = (usr) => {
    selectedUserForStaff.value = usr;
    createStaffModalVisible.value = true;
};

const fetchClubs = async () => {
    try {
        const data = await fetchClubsByChurch(user.value.church_name);
        clubs.value = data;
    } catch (error) {
        showToast("Error loading clubs", "error");
        console.error("Failed to fetch clubs:", error);
    }
};

onMounted(async () => {
    try {
        const data = await fetchStaffRecord();
        hasStaffRecord.value = data.hasStaffRecord;
        staffRecord.value = data.staffRecord;
        user.value = data.user;
        fetchClubs();
    } catch (error) {
        console.error("Failed to fetch staff record:", error);
    }
});

</script>

<template>
    <PathfinderLayout>
        <template #title>Club Staff Dashboard</template>

        <div class="space-y-4 text-gray-800">
            <p class="text-lg">Welcome to the Pathfinder Club Admin Panel.</p>

            <div v-if="!hasStaffRecord">
                <label class="block mb-1 font-medium text-gray-700">Select a club</label>
                <select v-model="selectedClub" class="w-full p-2 border rounded">
                    <option disabled value="">-- Choose a club --</option>
                    <option v-for="club in clubs" :key="club.id" :value="club">
                        {{ club.club_name }} ({{ club.club_type }})
                    </option>
                </select>
                <button class="text-green-600 hover:underline mt-2" @click="openStaffForm(user)">
                    Create myself as Staff
                </button>
            </div>

            <div v-if="user" class="max-w-md bg-white shadow rounded p-6">
                <h2 class="text-xl font-bold mb-4">User Profile</h2>
                <dl class="space-y-2 text-sm">
                    <div><dt class="font-semibold">ID</dt><dd>{{ user.id }}</dd></div>
                    <div><dt class="font-semibold">Name</dt><dd>{{ user.name }}</dd></div>
                    <div><dt class="font-semibold">Email</dt><dd>{{ user.email }}</dd></div>
                    <div><dt class="font-semibold">Email Verified</dt><dd>{{ user.email_verified_at ?? "Not verified" }}</dd></div>
                    <div><dt class="font-semibold">Created At</dt><dd>{{ user.created_at?.slice(0, 10) }}</dd></div>
                    <div><dt class="font-semibold">Updated At</dt><dd>{{ user.updated_at?.slice(0, 10) }}</dd></div>
                    <div><dt class="font-semibold">Profile Type</dt><dd>{{ user.profile_type }}</dd></div>
                    <div><dt class="font-semibold">Sub Role</dt><dd>{{ user.sub_role }}</dd></div>
                    <div><dt class="font-semibold">Church Name</dt><dd>{{ user.church_name }}</dd></div>

                    <div class="mt-4">
                        <button @click="showPasswordModal = true"
                            class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700 text-sm">
                            Change Password
                        </button>
                    </div>
                </dl>
            </div>
        </div>

        <!-- Modals -->
        <UpdatePasswordModal
            v-if="userId"
            :show="showPasswordModal"
            :user-id="userId"
            @close="showPasswordModal = false"
            @updated="showToast('Password updated successfully')"
        />

        <CreateStaffModal
            :show="createStaffModalVisible"
            :user="selectedUserForStaff"
            :club="selectedClub"
            @close="createStaffModalVisible = false"
            @submitted="showToast('Staff profile created')"
        />
    </PathfinderLayout>
</template>
