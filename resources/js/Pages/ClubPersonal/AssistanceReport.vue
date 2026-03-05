<script setup>
import PathfinderLayout from "@/Layouts/PathfinderLayout.vue";
import { computed, reactive, ref, onMounted, watch } from 'vue';
import {
    createAssistanceReport,
    updateAssistanceReport,
    getAssistanceReport,
    checkAssistanceReportToday,
    fetchAssignedMembersByStaff,
    fetchAssistanceRequirementActivities
} from '@/Services/api';
import { useAuth } from '@/Composables/useAuth';
import { usePage } from '@inertiajs/vue3';
import { useGeneral } from '@/Composables/useGeneral'
import { useLocale } from '@/Composables/useLocale'

const { user } = useAuth();
const userId = computed(() => user.value?.id || null);
const assignedMembers = ref([]);
const assignedClass = ref(null);
const attendanceData = ref([]);
const page = usePage();
const staff = computed(() => page.props.staff || null);
const { toast, showToast } = useGeneral()
const { tr } = useLocale()
const isEditing = ref(false);

const showForm = ref(true)
const submittedReport = ref(null)
const submittedMerits = ref([])
const plannedRequirementActivities = ref([])

const meritsLabels = ['asistencia', 'puntualidad', 'uniforme', 'cuota'];
const requirementActivityLabelMap = computed(() => {
    const map = {}
    plannedRequirementActivities.value.forEach((activity) => {
        const key = String(activity.id)
        const req = activity.requirement_title || 'Requisito'
        const act = activity.title || activity.event_title || 'Actividad'
        map[key] = `${req} — ${act}`
    })
    return map
})

const hasAnyRequirementChecks = computed(() => {
    return submittedMerits.value.some((entry) => {
        const checks = entry.requirement_checks_json || {}
        return Object.keys(checks).length > 0
    })
})

const formatRequirementChecks = (entry) => {
    const checks = entry?.requirement_checks_json || {}
    const keys = Object.keys(checks)
    if (!keys.length) return []
    return keys.map((key) => ({
        label: requirementActivityLabelMap.value[key] || `Actividad ${key}`,
        done: Boolean(checks[key]),
    }))
}

const buildDefaultRequirementChecks = () => {
    const checks = {}
    plannedRequirementActivities.value.forEach((activity) => {
        checks[String(activity.id)] = false
    })
    return checks
}

const syncRequirementChecksToAttendanceRows = () => {
    attendanceData.value = attendanceData.value.map((row) => {
        const current = { ...(row.requirement_checks || {}) }
        const next = {}
        plannedRequirementActivities.value.forEach((activity) => {
            const key = String(activity.id)
            next[key] = Boolean(current[key])
        })
        return {
            ...row,
            requirement_checks: next
        }
    })
}

const canMarkSecondaryMerits = (row) => Number(row?.scores?.[0] || 0) === 1

const clearSecondaryMerits = (row) => {
    if (!row) return
    row.scores[1] = 0
    row.scores[2] = 0
    row.scores[3] = 0
    row.cuota_amount = 0
    Object.keys(row.requirement_checks || {}).forEach((key) => {
        row.requirement_checks[key] = false
    })
}

const enforceAssistancePrecedence = (row) => {
    if (!canMarkSecondaryMerits(row)) clearSecondaryMerits(row)
}

const showAssistanceRequiredAlert = () => {
    showToast('Debes marcar asistencia antes de registrar los demás indicadores.', 'warning')
}

const handleSecondaryMeritToggle = (row, meritIndex) => {
    if (canMarkSecondaryMerits(row)) return
    if (Number(row?.scores?.[meritIndex] || 0) === 1) {
        row.scores[meritIndex] = 0
        if (meritIndex === 3) row.cuota_amount = 0
        showAssistanceRequiredAlert()
    }
}

const handleRequirementToggle = (row, key) => {
    if (canMarkSecondaryMerits(row)) return
    if (row?.requirement_checks?.[key]) {
        row.requirement_checks[key] = false
        showAssistanceRequiredAlert()
    }
}

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
            conductor: false,
            cuota: scores[3] === 1,
            total: scores.filter(score => score === 1).length,
            cuota_amount: attendanceData.value[i].cuota_amount || 0, // ADD THIS
            requirement_checks_json: attendanceData.value[i].requirement_checks || {},

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
        showToast(tr('Reporte guardado correctamente.', 'Report saved successfully!'), 'success');
        showForm.value = false;
        isEditing.value = true;
    } catch (error) {
        console.error('Error saving report:', error);
        showToast(tr('No se pudo guardar el reporte.', 'Failed to save report'), 'error');
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
            conductor: false,
            cuota: scores[3] === 1,
            total: scores.filter(score => score === 1).length,
            cuota_amount: attendanceData.value[i].cuota_amount || 0, // ADD THIS
            requirement_checks_json: attendanceData.value[i].requirement_checks || {},

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
        showToast(tr('Reporte actualizado correctamente.', 'Report updated successfully!'), 'success');
        showForm.value = false;
    } catch (error) {
        console.error('Error updating report:', error);
        showToast(tr('No se pudo actualizar el reporte.', 'Failed to update report'), 'error');
    }
};

watch(assignedMembers, (members) => {
    attendanceData.value = members.map(m => ({
        member_id: m.id,
        scores: Array(4).fill(0),
        lastThree: Array(3).fill(null),
        cuota_amount: 0,
        requirement_checks: buildDefaultRequirementChecks(),
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
const resetAttendanceRows = () => {
    attendanceData.value = assignedMembers.value.map((m) => ({
        member_id: m.id,
        scores: Array(4).fill(0),
        lastThree: Array(3).fill(null),
        cuota_amount: 0,
        requirement_checks: buildDefaultRequirementChecks(),
    }))
}

const checkIfReportExistsForDate = async (staffId, targetDate) => {
    try {
        const res = await checkAssistanceReportToday(staffId, targetDate);
        if (res.exists) {
            submittedReport.value = res.report;
            submittedMerits.value = res.merits;
            preloadReport(res.report, res.merits);
            showForm.value = false;
            isEditing.value = true;
            showToast('Ya existe un reporte para la fecha seleccionada. Cargado en la vista guardada.', 'info');
        } else {
            showForm.value = true;
            isEditing.value = false;
            submittedReport.value = null;
            submittedMerits.value = [];
            resetAttendanceRows();
        }
    } catch (error) {
        console.error('Error checking report existence:', error);
        showToast('Error verificando reporte por fecha.', 'error');
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

    const byMemberId = new Map(reportMerits.map((entry) => [String(entry.mem_adv_id), entry]))
    attendanceData.value = assignedMembers.value.map((member) => {
        const entry = byMemberId.get(String(member.id))
        return {
            member_id: member.id,
            scores: entry
                ? meritsLabels.map(label => entry[label.toLowerCase()] ? 1 : 0)
                : Array(4).fill(0),
            cuota_amount: entry?.cuota_amount || 0,
            requirement_checks: entry?.requirement_checks_json || buildDefaultRequirementChecks(),
        };
    });
    attendanceData.value.forEach(enforceAssistancePrecedence)
};

const loadRequirementActivities = async (date) => {
    try {
        const data = await fetchAssistanceRequirementActivities(date)
        plannedRequirementActivities.value = Array.isArray(data.activities) ? data.activities : []
        syncRequirementChecksToAttendanceRows()
    } catch (error) {
        console.error('Failed to load requirement activities:', error)
        plannedRequirementActivities.value = []
        syncRequirementChecksToAttendanceRows()
    }
}
const totalCuotaAmount = computed(() => {
    return submittedMerits.value.reduce((sum, m) => {
        return sum + (parseFloat(m.cuota_amount) || 0);
    }, 0);
});
onMounted(async () => {
    if (userId.value && staff.value?.id) {
        // Prefer server-provided assigned members/class (derived from members table)
        if (Array.isArray(page.props.assigned_members) && page.props.assigned_members.length) {
            assignedMembers.value = page.props.assigned_members;
            assignedClass.value = page.props.assigned_class || null;
        } else {
            await loadAssignedMembers(staff.value.id);
        }
        if (Array.isArray(page.props.planned_requirement_activities)) {
            plannedRequirementActivities.value = page.props.planned_requirement_activities
        } else {
            await loadRequirementActivities(form.date)
        }
        syncRequirementChecksToAttendanceRows()
        await checkIfReportExistsForDate(staff.value.id, form.date);
    }
});

watch(() => form.date, async (newDate, oldDate) => {
    if (!newDate || newDate === oldDate || !staff.value?.id) return
    await loadRequirementActivities(newDate)
    await checkIfReportExistsForDate(staff.value.id, newDate)
})

watch(submittedReport, async (report) => {
    if (!report?.date) return
    await loadRequirementActivities(report.date)
})
</script>
<template>
    <PathfinderLayout>
        <template #title>{{ tr('Reporte de asistencia', 'Assistance Report') }}</template>
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

            <div v-if="false && isEditing" class="overflow-x-auto border rounded">
                <table class="w-full text-xs border-collapse">
                    <thead class="bg-gray-100">
                        <tr>
                            <th class="border p-2">#</th>
                            <th class="border p-2 text-left">Miembro</th>
                            <th class="border p-2">Asistencia</th>
                            <th class="border p-2">Puntualidad</th>
                            <th class="border p-2">Uniforme</th>
                            <th class="border p-2">Cuota</th>
                            <th class="border p-2">Monto</th>
                            <th
                                v-for="activity in plannedRequirementActivities"
                                :key="`activity-col-${activity.id}`"
                                class="border p-2 min-w-[180px]"
                            >
                                {{ activity.requirement_sort_order ? `${activity.requirement_sort_order}. ` : '' }}{{ activity.requirement_title || 'Requisito' }}
                            </th>
                            <th class="border p-2">Subtotal</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(member, i) in assignedMembers" :key="`table-member-${member.id || i}`">
                            <td class="border p-2 text-center">{{ i + 1 }}</td>
                            <td class="border p-2">{{ member.applicant_name }}</td>
                            <td class="border p-2 text-center">
                                <input type="checkbox" v-model="attendanceData[i].scores[0]" :true-value="1" :false-value="0" class="form-checkbox" @change="enforceAssistancePrecedence(attendanceData[i])" />
                            </td>
                            <td class="border p-2 text-center">
                                <input type="checkbox" v-model="attendanceData[i].scores[1]" :true-value="1" :false-value="0" class="form-checkbox" @change="handleSecondaryMeritToggle(attendanceData[i], 1)" />
                            </td>
                            <td class="border p-2 text-center">
                                <input type="checkbox" v-model="attendanceData[i].scores[2]" :true-value="1" :false-value="0" class="form-checkbox" @change="handleSecondaryMeritToggle(attendanceData[i], 2)" />
                            </td>
                            <td class="border p-2 text-center">
                                <input type="checkbox" v-model="attendanceData[i].scores[3]" :true-value="1" :false-value="0" class="form-checkbox" @change="handleSecondaryMeritToggle(attendanceData[i], 3)" />
                            </td>
                            <td class="border p-2">
                                <input
                                    v-if="attendanceData[i].scores[3] === 1"
                                    type="number"
                                    min="0"
                                    step="0.01"
                                    v-model.number="attendanceData[i].cuota_amount"
                                    class="border rounded px-2 py-1 w-24"
                                    placeholder="0.00"
                                />
                            </td>
                            <td
                                v-for="activity in plannedRequirementActivities"
                                :key="`table-member-${member.id}-activity-${activity.id}`"
                                class="border p-2 text-center"
                            >
                                <input
                                    type="checkbox"
                                    v-model="attendanceData[i].requirement_checks[String(activity.id)]"
                                    class="form-checkbox"
                                    @change="handleRequirementToggle(attendanceData[i], String(activity.id))"
                                />
                            </td>
                            <td class="border p-2 text-center font-semibold">{{ total(attendanceData[i].scores) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Accordion for members (new report mode) -->
            <div v-else class="space-y-2">
                <details v-for="(member, i) in assignedMembers" :key="member.id || i"
                    class="border rounded overflow-hidden">
                    <summary
                        class="bg-gray-100 px-4 py-2 cursor-pointer text-sm font-semibold flex items-center justify-between">
                        <span>{{ i + 1 }}. {{ member.applicant_name }}</span>
                        <label class="inline-flex items-center gap-1 text-xs font-normal">
                            <input type="checkbox" :id="`merit-${i}-0`" v-model="attendanceData[i].scores[0]"
                                :true-value="1" :false-value="0" class="form-checkbox" @change="enforceAssistancePrecedence(attendanceData[i])" />
                            Asistencia
                        </label>
                    </summary>

                    <div class="p-4 space-y-2 text-xs">
                        <div v-if="plannedRequirementActivities.length" class="border rounded p-2 bg-blue-50">
                            <p class="font-semibold text-[12px] mb-2">Requisitos planificados para esta fecha</p>
                            <div class="space-y-1">
                                <label
                                    v-for="activity in plannedRequirementActivities"
                                    :key="`member-${member.id}-activity-${activity.id}`"
                                    class="flex items-start gap-2 text-[12px]"
                                >
                                    <input
                                        type="checkbox"
                                        v-model="attendanceData[i].requirement_checks[String(activity.id)]"
                                        class="form-checkbox mt-0.5"
                                        @change="handleRequirementToggle(attendanceData[i], String(activity.id))"
                                    />
                                    <span>
                                        <strong>{{ activity.requirement_sort_order ? `${activity.requirement_sort_order}. ` : '' }}{{ activity.requirement_title || 'Requisito' }}</strong>
                                        <span class="text-gray-600"> — {{ activity.title || activity.event_title || 'Actividad' }}</span>
                                    </span>
                                </label>
                            </div>
                        </div>
                        <div class="grid grid-cols-2 gap-2">
                            <div v-for="(label, index) in meritsLabels.slice(1)" :key="index + 1" class="mb-2">
                                <div class="flex items-center gap-2">
                                    <input type="checkbox" :id="`merit-${i}-${index + 1}`"
                                        v-model="attendanceData[i].scores[index + 1]" :true-value="1" :false-value="0"
                                        class="form-checkbox" @change="handleSecondaryMeritToggle(attendanceData[i], index + 1)" />
                                    <label :for="`merit-${i}-${index + 1}`" class="text-sm capitalize">{{ label
                                        }}</label>
                                </div>

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
                    {{ isEditing ? tr('Actualizar reporte', 'Update Report') : tr('Guardar reporte completo', 'Save Full Report') }}
                </button>
            </div>
        </div>

        <div v-else class="max-w-4xl mx-auto bg-white p-4 shadow rounded text-sm">
            <h2 class="text-lg font-bold mb-4">{{ tr('Reporte guardado', 'Saved Report') }}</h2>

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
                            <th class="border p-1">Cuota</th>
                            <th class="border p-1">Monto</th>
                            <th v-if="hasAnyRequirementChecks" class="border p-1">Investidura</th>
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
                            <td class="border p-1">{{ m.cuota ? '✓' : '' }}</td>
                            <td class="border p-1">{{ new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' }).format(m.cuota_amount) }}</td>
                            <td v-if="hasAnyRequirementChecks" class="border p-1">
                                <div v-if="formatRequirementChecks(m).length" class="space-y-1">
                                    <div v-for="(item, idx) in formatRequirementChecks(m)" :key="`req-${i}-${idx}`">
                                        <span>{{ item.done ? '✓' : '✗' }}</span> {{ item.label }}
                                    </div>
                                </div>
                                <span v-else>—</span>
                            </td>
                            <td class="border p-1">{{ m.total }}</td>
                        </tr>
                    </tbody>
                    <tfoot class="bg-gray-100 font-semibold">
                        <tr>
                            <td class="border p-1 text-right" colspan="6">Total Cuota</td>
                            <td class="border p-1">
                                {{ new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' }).format(totalCuotaAmount) }}
                            </td>
                            <td class="border p-1" :colspan="hasAnyRequirementChecks ? 2 : 1"></td>
                        </tr>
                    </tfoot>
                </table>
            </details>

            <div class="mt-4 text-right">
                <button class="px-3 py-1 bg-blue-600 text-white rounded hover:bg-blue-700" @click="handleEditReport">
                    {{ tr('Editar reporte', 'Edit report') }}
                </button>
            </div>
        </div>

    </PathfinderLayout>
</template>
