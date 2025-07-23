<script setup>
import PathfinderLayout from "@/Layouts/PathfinderLayout.vue";
import { ref, onMounted, computed, watch } from "vue";
import { usePage } from "@inertiajs/vue3";
import { useGeneral } from "@/Composables/useGeneral";
import { fetchClubsByChurch, fetchStaffRecord } from "@/Services/api";
import { useAuth } from '@/Composables/useAuth'
import { reactive } from 'vue'
import { fetchAssignedMembersByStaff } from '@/Services/api'



const { showError } = useGeneral()
const assignedMembers = ref([])
const assignedClass = ref(null)
const page = usePage();
const { showToast } = useGeneral();
const clubs = ref([]);
const { user, userClubIds } = useAuth()
const userId = computed(() => user.value?.id || null)
const staff = computed(() => page.props.staff || null)

const today = new Date()

const form = reactive({
    unit_name: assignedClass.value?.name || '',
    counselor: user.value?.name || '',
    captain: '',
    month: today.toLocaleString('default', { month: 'long' }),
    year: today.getFullYear(),
    church: user.value?.church_name || '',
    district: user.value?.conference_name || '',
    date: new Date().toISOString().split('T')[0],
    members: Array.from({ length: 10 }, (_, i) => ({
        name: '',
        merits: {
            asistencia: 0,
            puntualidad: 0,
            uniforme: 0,
            conductor: 0,
            cuota: 0,
            extras: 0,
        },
        demerits: {
            tarea: 0,
            conducta: 0,
            otros: 0,
        },
    })),
})

const attendanceData = ref([])

watch(assignedMembers, (newMembers) => {
    attendanceData.value = newMembers.map(member => ({
        member_id: member.id,
        scores: Array(6).fill(null),
        lastThree: Array(3).fill(null)
    }))
})
watch(assignedClass, (newVal) => {
    if (newVal && newVal.name) {
        form.unit_name = newVal.name
    }
})

const fetchClubs = async () => {
    try {
        const data = await fetchClubsByChurch(user.value.church_name);
        clubs.value = data;
    } catch (error) {
        showToast("Error loading clubs", "error");
        console.error("Failed to fetch clubs:", error);
    }
};



const loadAssignedMembers = async (staffId) => {
    try {
        const data = await fetchAssignedMembersByStaff(staffId)
        assignedMembers.value = data.members
        assignedClass.value = data.class
    } catch (err) {
        console.error('Failed to load assigned members', err)
        showError(err)
    }
}

onMounted(() => {
    fetchClubs();
    if (userId.value) {
        loadAssignedMembers(staff.value.id)
    }
});

</script>
<template>
    <PathfinderLayout>
        <template #title>Assistance Report</template>
        <div class="max-w-6xl mx-auto bg-white p-6 shadow rounded text-sm space-y-4">

            <!-- Header Section -->
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="font-semibold">Nombre de la Clase:</label>
                    <input v-model="form.unit_name" type="text" class="w-full border rounded p-1" />
                </div>
                <div>
                    <label class="font-semibold">Consejero:</label>
                    <input v-model="form.counselor" type="text" class="w-full border rounded p-1" />
                </div>
                <div class="flex gap-2">
                    <div class="flex-1">
                        <label class="font-semibold">Mes:</label>
                        <input v-model="form.month" type="text" class="w-full border rounded p-1" />
                    </div>
                    <div class="flex-1">
                        <label class="font-semibold">Año:</label>
                        <input v-model="form.year" type="text" class="w-full border rounded p-1" />
                    </div>
                </div>
                <div>
                    <label class="font-semibold">Iglesia:</label>
                    <input v-model="form.church" type="text" class="w-full border rounded p-1" />
                </div>
                <div>
                    <label class="font-semibold">Distrito:</label>
                    <input v-model="form.district" type="text" class="w-full border rounded p-1" />
                </div>
                <div>
                    <label class="font-semibold">Fecha:</label>
                    <input v-model="form.date" type="date" class="w-full border rounded p-1" />
                </div>
            </div>

            <!-- Table Section -->
            <div class="overflow-x-auto">
                <table class="min-w-full table-auto text-center border border-gray-300">
                    <thead class="bg-blue-100 text-xs">
                        <tr>
                            <th class="border p-1 bg-blue-300 text-white" rowspan="2">#</th>
                            <th class="border p-1 bg-blue-300 text-white" rowspan="2">Integrantes</th>
                            <th class="border p-1 bg-blue-300" colspan="6">Méritos</th>
                            <th class="border p-1 bg-purple-300" rowspan="2">Sub Total</th>
                            <th class="border p-1 bg-red-200" colspan="3">Demeritos</th>
                            <th class="border p-1 bg-gray-200" rowspan="2">Gran Total</th>
                        </tr>
                        <tr class="text-[10px]">
                            <th class="border p-1">Asistencia</th>
                            <th class="border p-1">Puntualidad</th>
                            <th class="border p-1">Uniforme</th>
                            <th class="border p-1">Conductor</th>
                            <th class="border p-1">Cuota</th>
                            <th class="border p-1">Méritos Extras</th>
                            <th class="border p-1">Tarea</th>
                            <th class="border p-1">Conducta</th>
                            <th class="border p-1">Otros</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(member, i) in assignedMembers" :key="member.id || i" class="text-xs">
                            <td class="border p-1">{{ i + 1 }}</td>
                            <td class="border p-1">
                                <input type="text" :value="member.applicant_name" disabled
                                    class="w-full text-xs p-1 border rounded bg-gray-100 text-gray-700" />
                            </td>
                            <td class="border p-1" v-for="(field, j) in 6" :key="j">
                                <input type="number" class="w-full text-xs p-1 border rounded"
                                    v-model.number="attendanceData[i].scores[j]" />
                            </td>
                            <td class="border p-1 bg-purple-100 text-center font-semibold">
                                {{
                                    attendanceData[i].scores.reduce(
                                        (sum, val) => sum + (parseFloat(val) || 0),
                                        0
                                    )
                                }}
                            </td>
                            <td class="border p-1" v-for="(field, k) in 3" :key="'last-' + k">
                                <input type="number" class="w-full text-xs p-1 border rounded"
                                    v-model.number="attendanceData[i].lastThree[k]" />
                            </td>
                            <td class="border p-1 bg-gray-100 text-center font-semibold">
                                {{
                                    attendanceData[i].scores.reduce((sum, val) => sum + (parseFloat(val) || 0), 0)
                                    -
                                    attendanceData[i].lastThree.reduce((sum, val) => sum + (parseFloat(val) || 0), 0)
                                }}
                            </td>
                        </tr>

                    </tbody>
                </table>
            </div>
        </div>
    </PathfinderLayout>
</template>