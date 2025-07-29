<script setup>
import PathfinderLayout from "@/Layouts/PathfinderLayout.vue";
import { computed, reactive, ref, onMounted, watch } from 'vue';
import {
    createAssistanceReport,
    updateAssistanceReport,
    getAssistanceReport,
    checkAssistanceReportToday,
    fetchAssignedMembersByStaff
} from '@/Services/api';
import { useAuth } from '@/Composables/useAuth';
import { usePage } from '@inertiajs/vue3';
import { useGeneral } from '@/Composables/useGeneral'

const { user } = useAuth();
const userId = computed(() => user.value?.id || null);
const assignedMembers = ref([]);
const assignedClass = ref(null);
const attendanceData = ref([]);
const page = usePage();
const staff = computed(() => page.props.staff || null);
const { toast, showToast } = useGeneral()
const isEditing = ref(false);

const showForm = ref(true)
const submittedReport = ref(null)
const submittedMerits = ref([])

const meritsLabels = ['asistencia', 'puntualidad', 'uniforme', 'conductor', 'cuota'];

const form = reactive({
    unit_name: '',
    unit_id: '',
    staff_name: user.value?.name || '',
    captain: '',
    month: new Date().toLocaleString('default', { month: 'long' }),
    year: new Date().getFullYear(),
    church: user.value?.church_name || '',
    district: user.value?.conference_name || '',
    date: new Date().toISOString().split('T')[0],
});

const total = (scores) => scores.reduce((sum, v) => sum + (parseFloat(v) || 0), 0);

const saveReport = async () => {
    const meritsArray = assignedMembers.value.map((member, i) => {
        const scores = attendanceData.value[i].scores;
        return {
            mem_adv_name: member.applicant_name,
            mem_adv_id: member.id,
            asistencia: scores[0] === 1,
            puntualidad: scores[1] === 1,
            uniforme: scores[2] === 1,
            conductor: scores[3] === 1,
            cuota: scores[4] === 1,
            total: scores.filter(score => score === 1).length,
            cuota_amount: attendanceData.value[i].cuota_amount || 0, // ADD THIS

        };
    });

    const payload = {
        month: form.month,
        year: form.year.toString(),
        date: form.date,
        class_name: form.unit_name,
        class_id: form.unit_id,
        staff_name: form.staff_name,
        staff_id: staff.value.id,
        church: form.church,
        church_id: user.value?.church_id,
        club_id: user.value?.club_id,
        district: form.district,
        merits: meritsArray
    };
    console.log('Payload to save report:', payload);

    try {
        const res = await createAssistanceReport(payload);
        const reportData = await getAssistanceReport(res.id);
        submittedReport.value = reportData.report;
        submittedMerits.value = reportData.merits;
        showToast('Report saved successfully!', 'success');
        showForm.value = false;
        isEditing.value = true;
    } catch (error) {
        console.error('Error saving report:', error);
        showToast('Failed to save report', 'error');
    } 
};

const updateReport = async () => {
    const meritsArray = assignedMembers.value.map((member, i) => {
        const scores = attendanceData.value[i].scores;
        return {
            mem_adv_name: member.applicant_name,
            mem_adv_id: member.id,
            asistencia: scores[0] === 1,
            puntualidad: scores[1] === 1,
            uniforme: scores[2] === 1,
            conductor: scores[3] === 1,
            cuota: scores[4] === 1,
            total: scores.filter(score => score === 1).length,
            cuota_amount: attendanceData.value[i].cuota_amount || 0, // ADD THIS

        };
    });

    const payload = {
        month: form.month,
        year: form.year.toString(),
        date: form.date,
        class_name: form.unit_name,
        class_id: form.unit_id,
        staff_name: form.staff_name,
        staff_id: staff.value.id,
        church: form.church,
        church_id: user.value?.church_id,
        club_id: user.value?.club_id,
        district: form.district,
        merits: meritsArray
    };

    try {
        const reportId = submittedReport.value.id;
        await updateAssistanceReport(reportId, payload);
        const reportData = await getAssistanceReport(reportId);
        submittedReport.value = reportData.report;
        submittedMerits.value = reportData.merits;
        showToast('Report updated successfully!', 'success');
        showForm.value = false;
    } catch (error) {
        console.error('Error updating report:', error);
        showToast('Failed to update report', 'error');
    }
};

watch(assignedMembers, (members) => {
    attendanceData.value = members.map(m => ({
        member_id: m.id,
        scores: Array(6).fill(null),
        lastThree: Array(3).fill(null),
        cuota_amount: 0
    }));
});

watch(assignedClass, (val) => {
    if (val && val.name) form.unit_name = val.name;
    if (val && val.id) form.unit_id = val.id;
});

const loadAssignedMembers = async (staffId) => {
    const res = await fetchAssignedMembersByStaff(staffId);
    assignedMembers.value = res.members;
    assignedClass.value = res.class;
};
const checkIfReportExistsToday = async (staffId) => {
    try {
        const today = new Date().toISOString().split('T')[0];
        const res = await checkAssistanceReportToday(staffId, today);
        if (res.exists) {
            showForm.value = false;
            submittedReport.value = res.report;
            submittedMerits.value = res.merits;
            isEditing.value = true;
            showToast('Un reporte para hoy ya existe.', 'info');
        } else {
            showForm.value = true;
            isEditing.value = false;
        }
    } catch (error) {
        console.error('Error checking report existence:', error);
        showToast('Error verificando reporte del día.', 'error');
    }
};

const handleEditReport = () => {
    preloadReport(submittedReport.value, submittedMerits.value);
    showForm.value = true;
};
const preloadReport = (report, reportMerits) => {
    form.month = report.month;
    form.year = report.year;
    form.date = report.date;
    form.unit_name = report.class_name;
    form.staff_name = report.staff_name;
    form.church = report.church;
    form.district = report.district;

    attendanceData.value = reportMerits.map(entry => {
        return {
            member_id: entry.mem_adv_id,
            scores: meritsLabels.map(label => entry[label.toLowerCase()] ? 1 : 0),
        };
    });
};
const totalCuotaAmount = computed(() => {
    return submittedMerits.value.reduce((sum, m) => {
        return sum + (parseFloat(m.cuota_amount) || 0);
    }, 0);
});
onMounted(async () => {
    if (userId.value && staff.value?.id) {
        await loadAssignedMembers(staff.value.id);
        await checkIfReportExistsToday(staff.value.id);
    }
});
</script>
<template>
    <PathfinderLayout>
        <template #title>Assistance Report</template>
        <div v-if="showForm" class="max-w-4xl mx-auto bg-white p-4 shadow rounded text-sm">
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-4">
                <div>
                    <label class="font-semibold">Nombre de la Clase:</label>
                    <input v-model="form.unit_name" type="text" class="w-full border rounded p-1" />
                </div>
                <div>
                    <label class="font-semibold">Consejero:</label>
                    <input v-model="form.staff_name" type="text" class="w-full border rounded p-1" />
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

            <!-- Accordion for members -->
            <div class="space-y-2">
                <details v-for="(member, i) in assignedMembers" :key="member.id || i"
                    class="border rounded overflow-hidden">
                    <summary
                        class="bg-gray-100 px-4 py-2 cursor-pointer text-sm font-semibold flex items-center justify-between">
                        <span>{{ i + 1 }}. {{ member.applicant_name }}</span>
                        <!-- Assistance Checkbox (Index 0) -->
                        <label class="inline-flex items-center gap-1 text-xs font-normal">
                            <input type="checkbox" :id="`merit-${i}-0`" v-model="attendanceData[i].scores[0]"
                                :true-value="1" :false-value="0" class="form-checkbox" />
                            Asistencia
                        </label>
                    </summary>

                    <div class="p-4 space-y-2 text-xs">
                        <div class="grid grid-cols-2 gap-2">
                            <div v-for="(label, index) in meritsLabels.slice(1)" :key="index + 1" class="mb-2">
                                <div class="flex items-center gap-2">
                                    <input type="checkbox" :id="`merit-${i}-${index + 1}`"
                                        v-model="attendanceData[i].scores[index + 1]" :true-value="1" :false-value="0"
                                        class="form-checkbox" />
                                    <label :for="`merit-${i}-${index + 1}`" class="text-sm capitalize">{{ label
                                        }}</label>
                                </div>

                                <!-- Show input only if 'cuota' is checked -->
                                <div v-if="label === 'cuota' && attendanceData[i].scores[index + 1] === 1"
                                    class="pl-6 mt-1">
                                    <label for="">Amount</label> &nbsp;
                                    <input type="number" min="0" step="0.01"
                                        v-model.number="attendanceData[i].cuota_amount"
                                        class="border rounded px-2 py-1 w-32 text-sm" placeholder="Amount paid" />
                                </div>
                            </div>
                            <div class="col-span-2 font-semibold text-right flex justify-between items-center">
                                <span>Subtotal: {{ total(attendanceData[i].scores) }}</span>
                            </div>
                        </div>
                        <hr />
                    </div>
                </details>
            </div>
            <div class="mt-6 text-right">
                <button @click="isEditing ? updateReport() : saveReport()"
                    class="px-4 py-2 bg-green-600 text-white rounded hover:bg-green-700">
                    {{ isEditing ? 'Update Report' : 'Save Full Report' }}
                </button>
            </div>
        </div>

        <div v-else class="max-w-4xl mx-auto bg-white p-4 shadow rounded text-sm">
            <h2 class="text-lg font-bold mb-4">Reporte Guardado</h2>

            <div class="mb-4 grid grid-cols-2 gap-2 text-sm">
                <p><strong>Clase:</strong> {{ submittedReport.class_name }}</p>
                <p><strong>Consejero:</strong> {{ submittedReport.staff_name }}</p>
                <p><strong>Fecha:</strong> {{ submittedReport.date }}</p>
                <p><strong>Iglesia:</strong> {{ submittedReport.church }}</p>
                <p><strong>Distrito:</strong> {{ submittedReport.district }}</p>
                <p><strong>Mes/Año:</strong> {{ submittedReport.month }} {{ submittedReport.year }}</p>
            </div>

            <details class="mt-4 border rounded">
                <summary class="cursor-pointer bg-gray-100 px-4 py-2 font-semibold">Ver Detalles - Report ID {{
                    submittedReport.id }}</summary>
                <table class="w-full mt-2 text-xs border border-gray-300">
                    <thead class="bg-gray-200">
                        <tr>
                            <th class="border p-1">#</th>
                            <th class="border p-1">Miembro</th>
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
                        <tr v-for="(m, i) in submittedMerits" :key="i">
                            <td class="border p-1">{{ i + 1 }}</td>
                            <td class="border p-1">{{ m.mem_adv_name }}</td>
                            <td class="border p-1">{{ m.asistencia ? '✓' : '' }}</td>
                            <td class="border p-1">{{ m.puntualidad ? '✓' : '' }}</td>
                            <td class="border p-1">{{ m.uniforme ? '✓' : '' }}</td>
                            <td class="border p-1">{{ m.conductor ? '✓' : '' }}</td>
                            <td class="border p-1">{{ m.cuota ? '✓' : '' }}</td>
                            <td class="border p-1">{{ new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' }).format(m.cuota_amount) }}</td>
                            <td class="border p-1">{{ m.total }}</td>
                        </tr>
                    </tbody>
                    <tfoot class="bg-gray-100 font-semibold">
                        <tr>
                            <td class="border p-1 text-right" colspan="7">Total Cuota</td>
                            <td class="border p-1">
                                {{ new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' }).format(totalCuotaAmount) }}
                            </td>
                            <td class="border p-1"></td>
                        </tr>
                    </tfoot>
                </table>
            </details>

            <div class="mt-4 text-right">
                <button class="px-3 py-1 bg-blue-600 text-white rounded hover:bg-blue-700" @click="handleEditReport">
                    Editar Reporte
                </button>
            </div>
        </div>

    </PathfinderLayout>
</template>
