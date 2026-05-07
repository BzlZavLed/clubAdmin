<style scoped>
/* Repeat table headers/footers across pages in PDF */
table { border-collapse: collapse; }
thead { display: table-header-group; }
tfoot { display: table-footer-group; }

/* Avoid breaking a row across pages */
tr, td, th { page-break-inside: avoid; }

/* Optional helpers you can add to elements to force page breaks */
.page-break-before { page-break-before: always; }
.page-break-after  { page-break-after: always; }
</style>

<script setup>
import PathfinderLayout from "@/Layouts/PathfinderLayout.vue";
import {
    fetchMembersByClub,
    fetchClubClasses,
    filterAssistanceReports,
} from "@/Services/api";
import { ref, onMounted, computed,nextTick } from "vue";
import html2pdf from 'html2pdf.js'
import { useAuth } from "@/Composables/useAuth";
import { useGeneral } from "@/Composables/useGeneral";
import { useLocale } from "@/Composables/useLocale";
import { usePage } from "@inertiajs/vue3";

const selectedClub = ref(null);
const members = ref([]);
const clubClasses = ref([]);
const clubs = ref([]);
const mergeReports = ref(false);
const results = ref([]);
//report variables
const reportType = ref("");
const selectedDate = ref("");
const startDate = ref("");
const endDate = ref("");
const selectedClassId = ref("");
const selectedMemberId = ref("");
const reportRef = ref(null)

const { user, userClubIds } = useAuth();
const { toast, showToast } = useGeneral();
const { tr } = useLocale();
const reports = ref([]);
const page = usePage();
const superadminContext = computed(() => page.props.auth?.superadmin_context ?? null);
const canSelectClub = computed(() => (clubs.value?.length ?? 0) > 1);

const onClubChange = async () => {
    if (selectedClub.value) {
        await fetchMembers(selectedClub.value.id);
        await fetchClasses(selectedClub.value.id);
    }
};

// Fetch members
const fetchMembers = async (clubId) => {
    try {
        const data = await fetchMembersByClub(clubId);
        if (Array.isArray(data) && data.length > 0) {
            members.value = data;
            showToast(tr("Miembros cargados", "Members loaded"), "success");
        } else {
            members.value = [];
            showToast(tr("No se encontraron miembros para este club.", "No members were found for this club."), "info");
        }
    } catch (error) {
        console.error("Failed to fetch members:", error);
        showToast(tr("Error al obtener miembros", "Could not load members"), "error");
    }
};

// Fetch club classes
const fetchClasses = async (clubId) => {
    try {
        clubClasses.value = await fetchClubClasses(clubId);
    } catch (error) {
        console.error("Failed to fetch club classes:", error);
    }
};

// Fetch clubs
const fetchClubs = async () => {
    try {
        clubs.value = Array.isArray(user.value?.clubs) ? user.value.clubs : [];

        if (!clubs.value.length) {
            selectedClub.value = null;
            members.value = [];
            clubClasses.value = [];
            return;
        }

        const contextClubId = superadminContext.value?.club_id;
        const preferredClub = contextClubId
            ? clubs.value.find((club) => String(club.id) === String(contextClubId))
            : null;

        selectedClub.value = preferredClub || selectedClub.value || clubs.value[0];

        if (selectedClub.value?.id) {
            await onClubChange();
        }
    } catch (error) {
        console.error("Failed to fetch clubs:", error);
        showToast(tr("Error al cargar clubes", "Could not load clubs"), "error");
    }
};

const submitReport = async () => {
    if (!selectedClub.value) {
        showToast(tr("Selecciona un club primero", "Select a club first"), "error");
        return;
    }

    const payload = {
        club_id: selectedClub.value.id,
        report_type: reportType.value,
    };

    switch (reportType.value) {
        case "date":
            if (!selectedDate.value) {
                showToast(tr("Selecciona una fecha.", "Select a date."), "error");
                return;
            }
            payload.date = selectedDate.value;
            break;

        case "range":
            if (!startDate.value || !endDate.value) {
                showToast(tr("Selecciona fecha inicial y final.", "Select a start and end date."), "error");
                return;
            }
            payload.start_date = startDate.value;
            payload.end_date = endDate.value;
            break;

        case "class":
            if (!selectedClassId.value) {
                showToast(tr("Selecciona una clase.", "Select a class."), "error");
                return;
            }
            payload.class_id = selectedClassId.value;
            break;

        case "member":
            if (!selectedMemberId.value) {
                showToast(tr("Selecciona un miembro.", "Select a member."), "error");
                return;
            }
            payload.member_id = selectedMemberId.value;
            break;

        default:
            showToast(tr("Selecciona un tipo de reporte.", "Select a report type."), "error");
            return;
    }

    try {
        const response = await filterAssistanceReports(payload);
        reports.value = response.data.reports;
        console.log(response);
        if (reports.value.length === 0) {
            showToast(tr("No se encontraron reportes para el criterio seleccionado.", "No reports were found for the selected criteria."), "info");
            return;
        }
        showToast(tr("Reporte generado correctamente", "Report generated successfully"), "success");
    } catch (error) {
        console.error("Error fetching report:", error);
        showToast(tr("No se pudo generar el reporte", "Could not generate the report"), "error");
    }
};

const mergedMerits = computed(() =>
    reports.value.flatMap((report) =>
        report.merits.map((m) => ({
            ...m,
            report_date: report.date,
            month: report.month,
            year: report.year,
        }))
    )
);



function expandAllDetails() {
    // expand all details so they render in the PDF
    if (!reportRef.value) return
    const details = reportRef.value.querySelectorAll('details')
    details.forEach(d => d.open = true)
}

async function exportPdf() {
    // Ensure everything visible/expanded before capture
    if (!mergeReports.value) {
        expandAllDetails()
    }

    await nextTick()
    // Give the layout a tick to paint fonts & widths
    await new Promise(r => requestAnimationFrame(() => r()))

    const el = reportRef.value
    if (!el) return

    // Filename helper
    const today = new Date()
    const yyyy = today.getFullYear()
    const mm = String(today.getMonth() + 1).padStart(2, '0')
    const dd = String(today.getDate()).padStart(2, '0')
    const mode = mergeReports.value ? 'merged' : 'unmerged'

    const safe = (s) => (s || '').replace(/[^\w\s.-]+/g, '').replace(/\s+/g, '_');
    const filename = `assistance_${safe(reportType.value)}_${safe(reportFilterDetail.value)}_${yyyy}-${mm}-${dd}_${mode}.pdf`;

    // Decide orientation (merged tables are usually wide)
    const orientation = mergeReports.value ? 'landscape' : 'portrait'

    const opt = {
        margin: [10, 10, 10, 10], // pts
        filename,
        image: { type: 'jpeg', quality: 0.98 },
        html2canvas: { scale: 2, useCORS: true }, // sharper text
        jsPDF: { unit: 'pt', format: 'letter', orientation },
        pagebreak: { mode: ['css', 'legacy'] } // honor CSS page-break rules
    }

    // Clean up any focus/hover states before capture (optional)
    document.activeElement && document.activeElement.blur?.()

    // Generate
    await html2pdf().from(el).set(opt).save()
}

//REPORT HEADER

const formatDate = (iso) => {
    if (!iso) return '';
    const d = new Date(iso);
    // keep the same format you display in tables; tweak as you wish
    return d.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: '2-digit' });
};

const selectedClassName = computed(() => {
    if (!selectedClassId.value) return '';
    const c = clubClasses.value.find(x => x.id === selectedClassId.value);
    return c ? c.class_name : '';
});

const selectedMemberName = computed(() => {
    if (!selectedMemberId.value) return '';
    const m = members.value.find(x => x.id === selectedMemberId.value);
    return m ? m.applicant_name : '';
});

const reportKindLabel = computed(() => {
    switch (reportType.value) {
        case 'date': return tr('Reporte de asistencia por fecha', 'Attendance report by date');
        case 'range': return tr('Reporte de asistencia por rango de fechas', 'Attendance report by date range');
        case 'class': return tr('Reporte de asistencia por clase', 'Attendance report by class');
        case 'member': return tr('Reporte de asistencia por miembro', 'Attendance report by member');
        default: return '';
    }
});

const reportFilterDetail = computed(() => {
    switch (reportType.value) {
        case 'date':
            return selectedDate.value ? formatDate(selectedDate.value) : '';
        case 'range':
            if (!startDate.value || !endDate.value) return '';
            return `${formatDate(startDate.value)} – ${formatDate(endDate.value)}`;
        case 'class':
            return selectedClassName.value || '';
        case 'member':
            return selectedMemberName.value || '';
        default:
            return '';
    }
});

const reportHeader = computed(() => {
    const club = selectedClub.value ? selectedClub.value.club_name : '';
    const kind = reportKindLabel.value;
    const filter = reportFilterDetail.value;
    const merged = mergeReports.value ? tr(' (Combinado)', ' (Merged)') : '';
    return [club, '—', kind, filter ? `(${filter})` : '', merged].filter(Boolean).join(' ');
});

onMounted(() => {
    fetchClubs();
});
</script>

<template>
    <PathfinderLayout>
        <div
            class="bg-yellow-100 text-yellow-800 text-sm px-4 py-2 text-center font-medium shadow-md lg:hidden"
        >
            {{
                tr(
                    "Para mejor experiencia, ve este reporte en escritorio o en modo horizontal.",
                    "For the best experience, view this report on desktop or in landscape mode."
                )
            }}
        </div>
        <div class="px-4 sm:px-6 lg:px-8 py-6">
            <!-- Heading -->
            <h1
                class="text-lg sm:text-xl font-semibold text-gray-800 mb-6 text-center sm:text-left"
            >
                {{ tr("Reporte de asistencia", "Attendance Report") }}
            </h1>

            <!-- Form Container -->
            <div class="grid gap-6 sm:grid-cols-2 md:grid-cols-3">
                <!-- Select Club -->
                <div v-if="canSelectClub" class="col-span-full sm:col-span-1">
                    <label class="block text-sm font-medium text-gray-700 mb-1"
                        >{{ tr("Selecciona un club", "Select a club") }}</label
                    >
                    <select
                        v-model="selectedClub"
                        @change="onClubChange"
                        class="w-full border border-gray-300 rounded-md p-2 text-sm focus:ring-yellow-400 focus:border-yellow-400"
                    >
                        <option disabled value="">-- {{ tr("Elige un club", "Choose a club") }} --</option>
                        <option
                            v-for="club in clubs"
                            :key="club.id"
                            :value="club"
                        >
                            {{ club.club_name }} ({{ club.club_type }})
                        </option>
                    </select>
                </div>
                <div v-else-if="selectedClub" class="col-span-full sm:col-span-1 rounded border bg-white px-3 py-2 text-sm text-gray-700">
                    {{ tr("Club activo", "Active club") }}: <strong>{{ selectedClub.club_name }}</strong>
                </div>

                <!-- Select Report Type -->
                <div class="col-span-full sm:col-span-1" v-if="selectedClub">
                    <label
                        for="reportType"
                        class="block text-sm font-medium text-gray-700 mb-1"
                        >{{ tr("Tipo de reporte", "Report type") }}</label
                    >
                    <select
                        id="reportType"
                        v-model="reportType"
                        class="w-full border border-gray-300 rounded-md p-2 text-sm focus:ring-yellow-400 focus:border-yellow-400"
                    >
                        <option value="" disabled>{{ tr("Selecciona un tipo de reporte", "Select a report type") }}</option>
                        <option value="date">{{ tr("Por fecha", "By date") }}</option>
                        <option value="range">{{ tr("Por rango de fechas", "By date range") }}</option>
                        <option value="class">{{ tr("Por clase", "By class") }}</option>
                        <option value="member">{{ tr("Por miembro", "By member") }}</option>
                    </select>
                </div>

                <!-- By Date -->
                <div
                    class="col-span-full sm:col-span-1"
                    v-if="reportType === 'date'"
                >
                    <label class="block text-sm font-medium text-gray-700 mb-1"
                        >{{ tr("Selecciona fecha", "Select date") }}</label
                    >
                    <input
                        type="date"
                        v-model="selectedDate"
                        class="w-full border border-gray-300 rounded-md p-2 text-sm"
                    />
                </div>

                <!-- By Date Range -->
                <div
                    v-if="reportType === 'range'"
                    class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-4 col-span-full"
                >
                    <div>
                        <label
                            class="block text-sm font-medium text-gray-700 mb-1"
                            >{{ tr("Fecha inicio", "Start date") }}</label
                        >
                        <input
                            type="date"
                            v-model="startDate"
                            class="w-full border border-gray-300 rounded-md p-2 text-sm"
                        />
                    </div>
                    <div>
                        <label
                            class="block text-sm font-medium text-gray-700 mb-1"
                            >{{ tr("Fecha fin", "End date") }}</label
                        >
                        <input
                            type="date"
                            v-model="endDate"
                            class="w-full border border-gray-300 rounded-md p-2 text-sm"
                        />
                    </div>
                </div>

                <!-- By Class -->
                <div
                    class="col-span-full sm:col-span-1"
                    v-if="reportType === 'class'"
                >
                    <label class="block text-sm font-medium text-gray-700 mb-1"
                        >{{ tr("Selecciona clase", "Select class") }}</label
                    >
                    <select
                        v-model="selectedClassId"
                        class="w-full border border-gray-300 rounded-md p-2 text-sm"
                    >
                        <option disabled value="">{{ tr("Elige una clase", "Choose a class") }}</option>
                        <option
                            v-for="c in clubClasses"
                            :key="c.id"
                            :value="c.id"
                        >
                            {{ c.class_name }}
                        </option>
                    </select>
                </div>

                <!-- By Member -->
                <div
                    class="col-span-full sm:col-span-1"
                    v-if="reportType === 'member'"
                >
                    <label class="block text-sm font-medium text-gray-700 mb-1"
                        >{{ tr("Selecciona miembro", "Select member") }}</label
                    >
                    <select
                        v-model="selectedMemberId"
                        class="w-full border border-gray-300 rounded-md p-2 text-sm"
                    >
                        <option disabled value="">{{ tr("Elige un miembro", "Choose a member") }}</option>
                        <option v-for="m in members" :key="m.id" :value="m.id">
                            {{ m.applicant_name }}
                        </option>
                    </select>
                </div>
            </div>
            <div class="grid gap-6 sm:grid-cols-2 md:grid-cols-3">
                <div class="col-span-full sm:col-span-1 mt-6">
                    <button
                        @click="submitReport"
                        class="px-2 py-1 bg-blue-600 text-white rounded text-xs hover:bg-blue-700"
                    >
                        {{ tr("Generar reporte", "Generate report") }}
                    </button>
                    &nbsp;
                    <button
                        @click="exportPdf"
                        class="px-2 py-1 bg-emerald-600 text-white rounded text-xs hover:bg-emerald-700"
                    >
                        {{ tr("Exportar PDF", "Export PDF") }}
                    </button>
                    <label class="inline-flex items-center">
                        <input
                            type="checkbox"
                            v-model="mergeReports"
                            class="form-checkbox"
                        />
                        <span class="ml-2 text-sm"
                            >{{ tr("Combinar todos los reportes en una tabla", "Merge all reports into one table") }}</span
                        >
                    </label>
                </div>
            </div>
            <div ref="reportRef" class="mt-6">
                <div class="mt-8 mb-2">
                        <h2 class="text-base sm:text-lg font-semibold text-gray-900">
                            {{ reportHeader }}
                        </h2>
                        <p class="text-xs text-gray-500">
                            {{ tr("Generado el", "Generated on") }} {{ new Date().toLocaleString() }}
                        </p>
                </div>
                <div v-if="mergeReports" class="overflow-x-auto">
                    <table class="w-full min-w-max border text-sm">
                        <thead>
                            <tr class="bg-gray-100">
                                <th class="border p-1">{{ tr("Fecha", "Date") }}</th>
                                <th class="border p-1">{{ tr("Mes", "Month") }}</th>
                                <th class="border p-1">{{ tr("Ano", "Year") }}</th>
                                <th class="border p-1">{{ tr("Miembro", "Member") }}</th>
                                <th class="border p-1">{{ tr("Asistencia", "Attendance") }}</th>
                                <th class="border p-1">{{ tr("Puntualidad", "Punctuality") }}</th>
                                <th class="border p-1">{{ tr("Uniforme", "Uniform") }}</th>
                                <th class="border p-1">{{ tr("Conductor", "Driver") }}</th>
                                <th class="border p-1">{{ tr("Cuota", "Dues") }}</th>
                                <th class="border p-1">{{ tr("Monto", "Amount") }}</th>
                                <th class="border p-1">{{ tr("Total", "Total") }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="(m, i) in mergedMerits" :key="i">
                                <td class="border p-1">{{ m.report_date }}</td>
                                <td class="border p-1">{{ m.month }}</td>
                                <td class="border p-1">{{ m.year }}</td>
                                <td class="border p-1">{{ m.mem_adv_name }}</td>
                                <td class="border p-1">
                                    {{ m.asistencia ? "✓" : "" }}
                                </td>
                                <td class="border p-1">
                                    {{ m.puntualidad ? "✓" : "" }}
                                </td>
                                <td class="border p-1">
                                    {{ m.uniforme ? "✓" : "" }}
                                </td>
                                <td class="border p-1">
                                    {{ m.conductor ? "✓" : "" }}
                                </td>
                                <td class="border p-1">
                                    {{ m.cuota ? "✓" : "" }}
                                </td>
                                <td class="border p-1">
                                    {{
                                        new Intl.NumberFormat("en-US", {
                                            style: "currency",
                                            currency: "USD",
                                        }).format(m.cuota_amount || 0)
                                    }}
                                </td>
                                <td class="border p-1">{{ m.total }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div v-else>
                    <div
                        v-for="report in reports"
                        :key="report.id"
                        class="mb-4 border rounded"
                    >
                        <details>
                            <summary
                                class="cursor-pointer p-2 bg-gray-100 font-medium"
                            >
                                {{ tr("Reporte", "Report") }}: {{ report.date }} —
                                {{ report.class_name }} | {{ report.staff_name }} ({{ report.month }}
                                {{ report.year }} )
                            </summary>
                            <div class="overflow-x-auto">
                                <table class="w-full text-sm border mt-2">
                                    <thead class="bg-gray-50">
                                        <tr>
                                            <th class="border p-1">{{ tr("Miembro", "Member") }}</th>
                                            <th class="border p-1">
                                                {{ tr("Asistencia", "Attendance") }}
                                            </th>
                                            <th class="border p-1">
                                                {{ tr("Puntualidad", "Punctuality") }}
                                            </th>
                                            <th class="border p-1">{{ tr("Uniforme", "Uniform") }}</th>
                                            <th class="border p-1">
                                                {{ tr("Conductor", "Driver") }}
                                            </th>
                                            <th class="border p-1">{{ tr("Cuota", "Dues") }}</th>
                                            <th class="border p-1">{{ tr("Monto", "Amount") }}</th>
                                            <th class="border p-1">{{ tr("Total", "Total") }}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr
                                            v-for="(m, i) in report.merits"
                                            :key="`merit-${report.id}-${i}`"
                                        >
                                            <td class="border p-1">
                                                {{ m.mem_adv_name }}
                                            </td>
                                            <td class="border p-1">
                                                {{ m.asistencia ? "✓" : "" }}
                                            </td>
                                            <td class="border p-1">
                                                {{ m.puntualidad ? "✓" : "" }}
                                            </td>
                                            <td class="border p-1">
                                                {{ m.uniforme ? "✓" : "" }}
                                            </td>
                                            <td class="border p-1">
                                                {{ m.conductor ? "✓" : "" }}
                                            </td>
                                            <td class="border p-1">
                                                {{ m.cuota ? "✓" : "" }}
                                            </td>
                                            <td class="border p-1">
                                                {{
                                                    new Intl.NumberFormat(
                                                        "en-US",
                                                        {
                                                            style: "currency",
                                                            currency: "USD",
                                                        }
                                                    ).format(
                                                        m.cuota_amount || 0
                                                    )
                                                }}
                                            </td>
                                            <td class="border p-1">
                                                {{ m.total }}
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </details>
                    </div>
                </div>
            </div>
        </div>
    </PathfinderLayout>
</template>
