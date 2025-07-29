<script setup>
import PathfinderLayout from '@/Layouts/PathfinderLayout.vue'
import {
    fetchMembersByClub,
    fetchClubClasses,
    fetchClubsByIds,
    filterAssistanceReports
} from '@/Services/api'
import { ref, onMounted, computed } from 'vue'
import { useAuth } from '@/Composables/useAuth'
import { useGeneral } from '@/Composables/useGeneral'


const selectedClub = ref(null)
const members = ref([])
const clubClasses = ref([])
const clubs = ref([])
const mergeReports = ref(false)
const results = ref([])
//report variables
const reportType = ref('');
const selectedDate = ref('');
const startDate = ref('');
const endDate = ref('');
const selectedClassId = ref('');
const selectedMemberId = ref('');



const { user, userClubIds } = useAuth()
const { toast, showToast } = useGeneral()
const reports = ref([]);

const onClubChange = async () => {
    if (selectedClub.value) {
        await fetchMembers(selectedClub.value.id)
        await fetchClasses(selectedClub.value.id)
    }
}

// Fetch members
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
}

// Fetch club classes
const fetchClasses = async (clubId) => {
    try {
        clubClasses.value = await fetchClubClasses(clubId)
    } catch (error) {
        console.error('Failed to fetch club classes:', error)
    }
}

// Fetch clubs
const fetchClubs = async () => {
    try {
        clubs.value = await fetchClubsByIds(user.value.clubs.map(club => club.id))
    } catch (error) {
        console.error('Failed to fetch clubs:', error)
        showToast('Error loading clubs', 'error')
    }
}


const submitReport = async () => {
    if (!selectedClub.value) {
        showToast('Please select a club first', 'error');
        return;
    }

    const payload = {
        club_id: selectedClub.value.id,
        report_type: reportType.value,
    };

    switch (reportType.value) {
        case 'date':
            if (!selectedDate.value) {
                showToast('Please select a date.', 'error');
                return;
            }
            payload.date = selectedDate.value;
            break;

        case 'range':
            if (!startDate.value || !endDate.value) {
                showToast('Please select both start and end dates.', 'error');
                return;
            }
            payload.start_date = startDate.value;
            payload.end_date = endDate.value;
            break;

        case 'class':
            if (!selectedClassId.value) {
                showToast('Please select a class.', 'error');
                return;
            }
            payload.class_id = selectedClassId.value;
            break;

        case 'member':
            if (!selectedMemberId.value) {
                showToast('Please select a member.', 'error');
                return;
            }
            payload.member_id = selectedMemberId.value;
            break;

        default:
            showToast('Please choose a report type.', 'error');
            return;
    }


    try {
        const response = await filterAssistanceReports(payload);
        reports.value = response.data.reports;
        showToast('Report generated successfully', 'success');
    } catch (error) {
        console.error('Error fetching report:', error);
        showToast('Failed to generate report', 'error');
    }
};

const mergedMerits = computed(() =>
    reports.value.flatMap(report =>
        report.merits.map(m => ({
        ...m,
        report_date: report.date,
        month: report.month,
        year: report.year
        }))
    )
)

onMounted(() => {
    fetchClubs()
})

</script>

<template>
    <PathfinderLayout>
        <div class="px-4 sm:px-6 lg:px-8 py-6">
            <!-- Heading -->
            <h1 class="text-lg sm:text-xl font-semibold text-gray-800 mb-6 text-center sm:text-left">
                Assistance Report
            </h1>

            <!-- Form Container -->
            <div class="grid gap-6 sm:grid-cols-2 md:grid-cols-3">
                <!-- Select Club -->
                <div class="col-span-full sm:col-span-1">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Select a Club</label>
                    <select v-model="selectedClub" @change="onClubChange"
                        class="w-full border border-gray-300 rounded-md p-2 text-sm focus:ring-yellow-400 focus:border-yellow-400">
                        <option disabled value="">-- Choose a club --</option>
                        <option v-for="club in clubs" :key="club.id" :value="club">
                            {{ club.club_name }} ({{ club.club_type }})
                        </option>
                    </select>
                </div>

                <!-- Select Report Type -->
                <div class="col-span-full sm:col-span-1" v-if="selectedClub">
                    <label for="reportType" class="block text-sm font-medium text-gray-700 mb-1">Report Type</label>
                    <select id="reportType" v-model="reportType"
                        class="w-full border border-gray-300 rounded-md p-2 text-sm focus:ring-yellow-400 focus:border-yellow-400">
                        <option value="" disabled>Select a report type</option>
                        <option value="date">By Date</option>
                        <option value="range">By Date Range</option>
                        <option value="class">By Class</option>
                        <option value="member">By Member</option>
                    </select>
                </div>

                <!-- By Date -->
                <div class="col-span-full sm:col-span-1" v-if="reportType === 'date'">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Select Date</label>
                    <input type="date" v-model="selectedDate"
                        class="w-full border border-gray-300 rounded-md p-2 text-sm" />
                </div>

                <!-- By Date Range -->
                <div v-if="reportType === 'range'"
                    class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-4 col-span-full">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Start Date</label>
                        <input type="date" v-model="startDate"
                            class="w-full border border-gray-300 rounded-md p-2 text-sm" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">End Date</label>
                        <input type="date" v-model="endDate"
                            class="w-full border border-gray-300 rounded-md p-2 text-sm" />
                    </div>
                </div>


                <!-- By Class -->
                <div class="col-span-full sm:col-span-1" v-if="reportType === 'class'">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Select Class</label>
                    <select v-model="selectedClassId" class="w-full border border-gray-300 rounded-md p-2 text-sm">
                        <option disabled value="">Choose a class</option>
                        <option v-for="c in clubClasses" :key="c.id" :value="c.id">
                            {{ c.class_name }}
                        </option>
                    </select>
                </div>

                <!-- By Member -->
                <div class="col-span-full sm:col-span-1" v-if="reportType === 'member'">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Select Member</label>
                    <select v-model="selectedMemberId" class="w-full border border-gray-300 rounded-md p-2 text-sm">
                        <option disabled value="">Choose a member</option>
                        <option v-for="m in members" :key="m.id" :value="m.id">
                            {{ m.applicant_name }}
                        </option>
                    </select>
                </div>
            </div>
            <div class="grid gap-6 sm:grid-cols-2 md:grid-cols-3">
                <div class="col-span-full sm:col-span-1 mt-6">
                    <button @click="submitReport"
                        class="px-2 py-1 bg-blue-600 text-white rounded text-xs hover:bg-blue-700">
                        Generate Report
                    </button><br><br>
                    <label class="inline-flex items-center">
                        <input type="checkbox" v-model="mergeReports" class="form-checkbox">
                        <span class="ml-2 text-sm">Merge all reports into one table</span>
                    </label>
                </div>
            </div>
            <div v-if="mergeReports">
                <table class="w-full border text-sm">
                    <thead>
                        <tr class="bg-gray-100">
                            <th class="border p-1">Date</th>
                            <th class="border p-1">Month</th>
                            <th class="border p-1">Year</th>
                            <th class="border p-1">Member</th>
                            <th class="border p-1">Asistencia</th>
                            <th class="border p-1">Puntualidad</th>
                            <th class="border p-1">Uniforme</th>
                            <th class="border p-1">Conductor</th>
                            <th class="border p-1">Cuota</th>
                            <th class="border p-1">Monto</th>
                            <th class="border p-1">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(m, i) in mergedMerits" :key="i">
                            <td class="border p-1">{{ m.report_date }}</td>
                            <td class="border p-1">{{ m.month }}</td>
                            <td class="border p-1">{{ m.year }}</td>
                            <td class="border p-1">{{ m.mem_adv_name }}</td>
                            <td class="border p-1">{{ m.asistencia ? '✓' : '' }}</td>
                            <td class="border p-1">{{ m.puntualidad ? '✓' : '' }}</td>
                            <td class="border p-1">{{ m.uniforme ? '✓' : '' }}</td>
                            <td class="border p-1">{{ m.conductor ? '✓' : '' }}</td>
                            <td class="border p-1">{{ m.cuota ? '✓' : '' }}</td>
                            <td class="border p-1">
                            {{
                                new Intl.NumberFormat('en-US', {
                                style: 'currency',
                                currency: 'USD',
                                }).format(m.cuota_amount || 0)
                            }}
                            </td>
                            <td class="border p-1">{{ m.total }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            
            <div v-else>
                <div v-for="report in reports" :key="report.id" class="mb-4 border rounded">
                    <details>
                        <summary class="cursor-pointer p-2 bg-gray-100 font-medium">
                            Report: {{ report.date }} — {{ report.class_name }} ({{ report.month }} {{ report.year }})
                        </summary>
                        <table class="w-full text-sm border mt-2">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="border p-1">Member</th>
                                    <th class="border p-1">Asistencia</th>
                                    <th class="border p-1">Puntualidad</th>
                                    <th class="border p-1">Uniforme</th>
                                    <th class="border p-1">Conductor</th>
                                    <th class="border p-1">Cuota</th>
                                    <th class="border p-1">Monto</th>
                                    <th class="border p-1">Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="(m, i) in report.merits" :key="`merit-${report.id}-${i}`">
                                    <td class="border p-1">{{ m.mem_adv_name }}</td>
                                    <td class="border p-1">{{ m.asistencia ? '✓' : '' }}</td>
                                    <td class="border p-1">{{ m.puntualidad ? '✓' : '' }}</td>
                                    <td class="border p-1">{{ m.uniforme ? '✓' : '' }}</td>
                                    <td class="border p-1">{{ m.conductor ? '✓' : '' }}</td>
                                    <td class="border p-1">{{ m.cuota ? '✓' : '' }}</td>
                                    <td class="border p-1">
                                    {{ new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' }).format(m.cuota_amount || 0) }}
                                    </td>
                                    <td class="border p-1">{{ m.total }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </details>
                </div>
            </div>
        </div>
    </PathfinderLayout>
</template>