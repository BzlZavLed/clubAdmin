<script setup>
import PathfinderLayout from "@/Layouts/PathfinderLayout.vue";
import { ref, onMounted, computed, watch } from "vue";
import { usePage } from "@inertiajs/vue3";
import CreateStaffModal from "@/Components/CreateStaffModal.vue";
import UpdatePasswordModal from "@/Components/ChangePassword.vue";
import AssistanceReportPdf from "@/Components/Reports/AssistanceReport.vue";
import { useGeneral } from "@/Composables/useGeneral";
import { fetchClubsByChurch, fetchStaffRecord, fetchClubClasses, fetchReportsByStaffId, fetchReportByIdAndDate } from "@/Services/api";
import { ArrowTurnLeftUpIcon } from "@heroicons/vue/24/solid";

const page = usePage();
const { showToast } = useGeneral();
const formatDate = (date) => new Date(date).toLocaleDateString()
const createStaffModalVisible = ref(false);
const selectedUserForStaff = ref(null);
const selectedClub = ref(null);
const hasStaffRecord = ref(false);
const staff = ref(null);
const user = ref(null);
const userId = computed(() => user.value?.id || null)
const clubClasses = ref([])
const inlineShow = ref(false)
const pdfShow = ref(false)
const reports = ref([])
const clubs = ref([]);
const showPasswordModal = ref(false);

const fetchClasses = async (clubId) => {
    try {
        clubClasses.value = await fetchClubClasses(clubId.id)
    } catch (error) {
        console.error('Failed to fetch club classes:', error)
    }
}
const openStaffForm = (usr) => {
    if (!selectedClub.value) {
        showToast("Please select a club first", "error");
        return;
    }
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
const fetchStaffRecordMethod = async () => {
    try {
        const data = await fetchStaffRecord();
        hasStaffRecord.value = data.hasStaffRecord;
        staff.value = data.staffRecord;
        user.value = data.user;
        if (staff.value?.id) {
            await loadStaffReports(staff.value.id);
        }
        fetchClubs();
    } catch (error) {
        console.error("Failed to fetch staff record:", error);
    }
};



const loadStaffReports = async (staffId) => {
    try {
        reports.value = await fetchReportsByStaffId(staffId);
    } catch (error) {
        console.error("Failed to load staff reports", error);
        showToast('Error loading reports', 'error');
    }
}
onMounted(async () => {
    fetchStaffRecordMethod();

});
watch(createStaffModalVisible, (visible) => {
    if (!visible) {
        fetchStaffRecordMethod();
    }
})


const pdfReport = ref(null);
const expandedReports = ref(new Set())

async function toggleExpand(id, date) {
    inlineShow.value = true
    pdfShow.value = false
    if (expandedReports.value.has(id)) {
        expandedReports.value.delete(id);
        return;
    }

    try {
        pdfReport.value = await fetchReportByIdAndDateWrapper(id, date);
        expandedReports.value.add(id);
    } catch {
        alert('Failed to load report for expansion');
    }
}


async function generatePDF(id, date) {
    inlineShow.value = false
    pdfShow.value = true
    try {
        pdfReport.value = await fetchReportByIdAndDateWrapper(id, date);
    } catch (err) {
        alert('PDF generation failed')
    }
}

async function fetchReportByIdAndDateWrapper(id, date) {
    try {
        return await fetchReportByIdAndDate(id, date);
    } catch (error) {
        console.error('Failed to fetch report:', error);
        throw error;
    }
}

</script>

<template>
    <PathfinderLayout>
        <template #title>Club Staff Dashboard</template>

        <div class="space-y-4 text-gray-800">
            <p class="text-lg">Welcome {{ page.props.auth_user.name }} | Class : {{ page.props.auth.user.assigned_classes[0] }}</p>

            <div v-if="!hasStaffRecord">
                <label class="block mb-1 font-medium text-gray-700">Select a club</label>
                <select v-model="selectedClub" class="w-full p-2 border rounded" @change="fetchClasses(selectedClub)">
                    <option disabled value="">-- Choose a club --</option>
                    <option v-for="club in clubs" :key="club.id" :value="club">
                        {{ club.club_name }} ({{ club.club_type }})
                    </option>
                </select>
                <button class="text-green-600 hover:underline mt-2" @click="openStaffForm(user)">
                    Create myself as Staff
                </button>
            </div>
            <div class="space-y-6">
                <div class="w-full bg-white shadow rounded p-4 text-sm">
                    <h2 class="text-xl font-bold mb-4">Assistance Reports - History</h2>

                    <div class="hidden md:block overflow-x-auto">
                        <table class="min-w-full table-auto border border-gray-200 text-sm">
                            <thead class="bg-gray-100 text-left">
                                <tr>
                                    <th class="p-2 border">Month</th>
                                    <th class="p-2 border">Year</th>
                                    <th class="p-2 border">Date</th>

                                    <th class="p-2 border">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <template v-for="report in reports" :key="report.id">
                                    <!-- Main Row -->
                                    <tr class="hover:bg-gray-50">
                                        <td class="p-2 border">{{ report.month }}</td>
                                        <td class="p-2 border">{{ report.year }}</td>
                                        <td class="p-2 border">{{ formatDate(report.date) }}</td>

                                        <td class="p-2 border whitespace-nowrap">
                                            <div class="flex gap-2">
                                                <button @click="generatePDF(report.id, report.date)"
                                                    class="text-blue-600 hover:underline">PDF</button>
                                                <button @click="toggleExpand(report.id, report.date)"
                                                    class="text-green-600 hover:underline">
                                                    <span v-if="expandedReports.has(report.id)">Collapse</span>
                                                    <span v-else>Expand</span>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>

                                    <!-- Child Row -->
                                    <tr v-if="expandedReports.has(report.id)">
                                        <td colspan="8" class="border bg-gray-50">
                                            <div class="p-4">
                                                <AssistanceReportPdf v-if="inlineShow && !pdfShow && pdfReport"
                                                    :report="pdfReport" ref="pdfComponent" @pdf-done="pdfReport = null"
                                                    :disableAutoDownload="inlineShow" />

                                            </div>
                                        </td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>

                    <!-- MOBILE ACCORDION -->
                    <div class="md:hidden space-y-4">
                        <div v-for="report in reports" :key="report.id" class="border rounded shadow bg-white">
                            <button @click="toggleExpand(report.id, report.date)"
                                class="w-full text-left px-4 py-3 bg-gray-100 flex justify-between items-center">
                                <span>{{ report.class_name }} - {{ report.month }} {{ report.year }}</span>
                                <svg :class="{ 'rotate-180': expandedReports.has(report.id) }"
                                    class="w-4 h-4 transform transition-transform" fill="none" stroke="currentColor"
                                    viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M19 9l-7 7-7-7" />
                                </svg>
                            </button>

                            <div v-show="expandedReports.has(report.id)" class="px-4 py-2 space-y-1">
                                <p><strong>Date:</strong> {{ formatDate(report.date) }}</p>
                                <p><strong>Staff:</strong> {{ report.staff_name }}</p>
                                <p><strong>Church:</strong> {{ report.church }}</p>
                                <p><strong>District:</strong> {{ report.district }}</p>
                                <div class="flex gap-3">
                                    <button @click="generatePDF(report.id, report.date)"
                                        class="text-blue-600 hover:underline">PDF</button>

                                </div>

                            </div>
                        </div>
                    </div>
                </div>

                <div class="flex flex-col md:flex-row gap-4">
                    <div v-if="user"
                        class="w-full md:w-1/3 bg-white shadow rounded p-4 text-sm h-[450px] overflow-y-auto">
                        <h2 class="text-xl font-bold mb-4">User Profile</h2>
                        <div class="mt-4">
                            <button @click="showPasswordModal = true"
                                class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700 text-sm">
                                Change Password
                            </button>
                        </div>
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
                                <dd>{{ user.email_verified_at ?? "Not verified" }}</dd>
                            </div>
                            <div>
                                <dt class="font-semibold">Created At</dt>
                                <dd>{{ user.created_at?.slice(0, 10) }}</dd>
                            </div>
                            <div>
                                <dt class="font-semibold">Updated At</dt>
                                <dd>{{ user.updated_at?.slice(0, 10) }}</dd>
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

                    <div v-if="staff"
                        class="w-full md:w-1/3 bg-white shadow rounded p-4 text-sm h-[450px] overflow-y-auto">
                        <h2 class="text-xl font-bold mb-4">Staff Profile</h2>
                        <dl class="space-y-2">
                            <div>
                                <dt class="font-semibold">ID</dt>
                                <dd>{{ staff.id }}</dd>
                            </div>
                            <div>
                                <dt class="font-semibold">Name</dt>
                                <dd>{{ staff.name }}</dd>
                            </div>
                            <div>
                                <dt class="font-semibold">Date of Birth</dt>
                                <dd>{{ staff.dob?.slice(0, 10) }}</dd>
                            </div>
                            <div>
                                <dt class="font-semibold">Email</dt>
                                <dd>{{ staff.email }}</dd>
                            </div>
                            <div>
                                <dt class="font-semibold">Cell Phone</dt>
                                <dd>{{ staff.cell_phone }}</dd>
                            </div>
                            <div>
                                <dt class="font-semibold">Address</dt>
                                <dd>{{ staff.address }}, {{ staff.city }}, {{ staff.state }} {{ staff.zip }}</dd>
                            </div>
                            <div>
                                <dt class="font-semibold">Church</dt>
                                <dd>{{ staff.church_name }}</dd>
                            </div>
                            <div>
                                <dt class="font-semibold">Club</dt>
                                <dd>{{ staff.club_name }}</dd>
                            </div>
                            <div>
                                <dt class="font-semibold">Assigned Class</dt>
                                <dd>{{ staff.assigned_class }}</dd>
                            </div>
                            <div>
                                <dt class="font-semibold">Health Limitation</dt>
                                <dd>{{ staff.has_health_limitation ? 'Yes' : 'No' }}</dd>
                            </div>
                            <div v-if="staff.has_health_limitation && staff.health_limitation_description">
                                <dt class="font-semibold">Limitation Description</dt>
                                <dd>{{ staff.health_limitation_description }}</dd>
                            </div>
                            <div>
                                <dt class="font-semibold">Sterling Volunteer Completed</dt>
                                <dd>{{ staff.sterling_volunteer_completed ? 'Yes' : 'No' }}</dd>
                            </div>

                            <div>
                                <dt class="font-semibold">Application Signed Date</dt>
                                <dd>{{ staff.application_signed_date?.slice(0, 10) }}</dd>
                            </div>
                            <div>
                                <dt class="font-semibold">Applicant Signature</dt>
                                <dd>{{ staff.applicant_signature }}</dd>
                            </div>
                            <div>
                                <dt class="font-semibold">Status</dt>
                                <dd>{{ staff.status }}</dd>
                            </div>

                            <div class="mt-4">
                                <h3 class="font-semibold">References</h3>
                                <ul class="list-disc pl-5">
                                    <li>Pastor: {{ staff.reference_pastor }}</li>
                                    <li>Elder: {{ staff.reference_elder }}</li>
                                    <li>Other: {{ staff.reference_other }}</li>
                                </ul>
                            </div>

                            <div v-if="staff.experiences?.length" class="mt-4">
                                <h3 class="font-semibold">Experiences</h3>
                                <ul class="list-disc pl-5">
                                    <li v-for="(exp, i) in staff.experiences" :key="i">
                                        {{ exp.position }} at {{ exp.organization }} ({{ exp.date }})
                                    </li>
                                </ul>
                            </div>

                            <div v-if="staff.award_instruction_abilities?.length" class="mt-4">
                                <h3 class="font-semibold">Award Instruction Abilities</h3>
                                <ul class="list-disc pl-5">
                                    <li v-for="(ability, i) in staff.award_instruction_abilities" :key="i">
                                        {{ ability.name }} - Level {{ ability.level }}
                                    </li>
                                </ul>
                            </div>
                        </dl>
                    </div>
                </div>
            </div>

        </div>

        <!-- Modals -->
        <UpdatePasswordModal v-if="userId" :show="showPasswordModal" :user-id="userId"
            @close="showPasswordModal = false" @updated="showToast('Password updated successfully')" />

        <CreateStaffModal :show="createStaffModalVisible" :user="selectedUserForStaff" :club="selectedClub"
            :club-classes="clubClasses" @close="createStaffModalVisible = false"
            @submitted="showToast('Staff profile created')" />

        <AssistanceReportPdf v-if="!inlineShow && pdfShow && pdfReport" :report="pdfReport" ref="pdfComponent"
            @pdf-done="pdfReport = null" :disableAutoDownload="inlineShow" />

    </PathfinderLayout>
</template>
