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
    fetchClubsByIds,
    filterAssistanceReports,
} from "@/Services/api";
import { ref, onMounted, computed,nextTick } from "vue";
import html2pdf from 'html2pdf.js'
import { useAuth } from "@/Composables/useAuth";
import { useGeneral } from "@/Composables/useGeneral";

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
const reports = ref([]);

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
            showToast("Miembros cargados", "success");
        } else {
            members.value = [];
            alert("No se encontraron miembros para este club.");
        }
    } catch (error) {
        console.error("Failed to fetch members:", error);
        showToast("Error al obtener miembros", "error");
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
        clubs.value = await fetchClubsByIds(
            user.value.clubs.map((club) => club.id)
        );
    } catch (error) {
        console.error("Failed to fetch clubs:", error);
        showToast("Error al cargar clubes", "error");
    }
};

const submitReport = async () => {
    if (!selectedClub.value) {
        showToast("Selecciona un club primero", "error");
        return;
    }

    const payload = {
        club_id: selectedClub.value.id,
        report_type: reportType.value,
    };

    switch (reportType.value) {
        case "date":
            if (!selectedDate.value) {
                showToast("Selecciona una fecha.", "error");
                return;
            }
            payload.date = selectedDate.value;
            break;

        case "range":
            if (!startDate.value || !endDate.value) {
                showToast("Selecciona fecha inicial y final.", "error");
                return;
            }
            payload.start_date = startDate.value;
            payload.end_date = endDate.value;
            break;

        case "class":
            if (!selectedClassId.value) {
                showToast("Selecciona una clase.", "error");
                return;
            }
            payload.class_id = selectedClassId.value;
            break;

        case "member":
            if (!selectedMemberId.value) {
                showToast("Selecciona un miembro.", "error");
                return;
            }
            payload.member_id = selectedMemberId.value;
            break;

        default:
            showToast("Selecciona un tipo de reporte.", "error");
            return;
    }

    try {
        const response = await filterAssistanceReports(payload);
        reports.value = response.data.reports;
        console.log(response);
        if (reports.value.length === 0) {
            showToast("No se encontraron reportes para el criterio seleccionado.", "info");
            return;
        }
        showToast("Reporte generado correctamente", "success");
    } catch (error) {
        console.error("Error fetching report:", error);
        showToast("No se pudo generar el reporte", "error");
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
        case 'date': return 'Reporte de asistencia por fecha';
        case 'range': return 'Reporte de asistencia por rango de fechas';
        case 'class': return 'Reporte de asistencia por clase';
        case 'member': return 'Reporte de asistencia por miembro';
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
    const merged = mergeReports.value ? ' (Combinado)' : '';
    // Example: "NAD Eagles — Reporte de asistencia por clase (Rangers) (Combinado)"
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
            Para mejor experiencia, ve este reporte en escritorio o en modo
            horizontal.
        </div>
        <div class="px-4 sm:px-6 lg:px-8 py-6">
            <!-- Heading -->
            <h1
                class="text-lg sm:text-xl font-semibold text-gray-800 mb-6 text-center sm:text-left"
            >
                Reporte de asistencia
            </h1>

            <!-- Form Container -->
            <div class="grid gap-6 sm:grid-cols-2 md:grid-cols-3">
                <!-- Select Club -->
                <div class="col-span-full sm:col-span-1">
                    <label class="block text-sm font-medium text-gray-700 mb-1"
                        >Selecciona un club</label
                    >
                    <select
                        v-model="selectedClub"
                        @change="onClubChange"
                        class="w-full border border-gray-300 rounded-md p-2 text-sm focus:ring-yellow-400 focus:border-yellow-400"
                    >
                        <option disabled value="">-- Elige un club --</option>
                        <option
                            v-for="club in clubs"
                            :key="club.id"
                            :value="club"
                        >
                            {{ club.club_name }} ({{ club.club_type }})
                        </option>
                    </select>
                </div>

                <!-- Select Report Type -->
                <div class="col-span-full sm:col-span-1" v-if="selectedClub">
                    <label
                        for="reportType"
                        class="block text-sm font-medium text-gray-700 mb-1"
                        >Tipo de reporte</label
                    >
                    <select
                        id="reportType"
                        v-model="reportType"
                        class="w-full border border-gray-300 rounded-md p-2 text-sm focus:ring-yellow-400 focus:border-yellow-400"
                    >
                        <option value="" disabled>Selecciona un tipo de reporte</option>
                        <option value="date">Por fecha</option>
                        <option value="range">Por rango de fechas</option>
                        <option value="class">Por clase</option>
                        <option value="member">Por miembro</option>
                    </select>
                </div>

                <!-- By Date -->
                <div
                    class="col-span-full sm:col-span-1"
                    v-if="reportType === 'date'"
                >
                    <label class="block text-sm font-medium text-gray-700 mb-1"
                        >Selecciona fecha</label
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
                            >Fecha inicio</label
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
                            >Fecha fin</label
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
                        >Selecciona clase</label
                    >
                    <select
                        v-model="selectedClassId"
                        class="w-full border border-gray-300 rounded-md p-2 text-sm"
                    >
                        <option disabled value="">Elige una clase</option>
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
                        >Selecciona miembro</label
                    >
                    <select
                        v-model="selectedMemberId"
                        class="w-full border border-gray-300 rounded-md p-2 text-sm"
                    >
                        <option disabled value="">Elige un miembro</option>
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
                        Generar reporte
                    </button>
                    &nbsp;
                    <button
                        @click="exportPdf"
                        class="px-2 py-1 bg-emerald-600 text-white rounded text-xs hover:bg-emerald-700"
                    >
                        Exportar PDF
                    </button>
                    <label class="inline-flex items-center">
                        <input
                            type="checkbox"
                            v-model="mergeReports"
                            class="form-checkbox"
                        />
                        <span class="ml-2 text-sm"
                            >Combinar todos los reportes en una tabla</span
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
                            Generado el {{ new Date().toLocaleString() }}
                        </p>
                </div>
                <div v-if="mergeReports" class="overflow-x-auto">
                    <table class="w-full min-w-max border text-sm">
                        <thead>
                            <tr class="bg-gray-100">
                                <th class="border p-1">Fecha</th>
                                <th class="border p-1">Mes</th>
                                <th class="border p-1">Ano</th>
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
                                Reporte: {{ report.date }} —
                                {{ report.class_name }} | {{ report.staff_name }} ({{ report.month }}
                                {{ report.year }} )
                            </summary>
                            <div class="overflow-x-auto">
                                <table class="w-full text-sm border mt-2">
                                    <thead class="bg-gray-50">
                                        <tr>
                                            <th class="border p-1">Miembro</th>
                                            <th class="border p-1">
                                                Asistencia
                                            </th>
                                            <th class="border p-1">
                                                Puntualidad
                                            </th>
                                            <th class="border p-1">Uniforme</th>
                                            <th class="border p-1">
                                                Conductor
                                            </th>
                                            <th class="border p-1">Cuota</th>
                                            <th class="border p-1">Monto</th>
                                            <th class="border p-1">Total</th>
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
