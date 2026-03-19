<script setup>
import PathfinderLayout from "@/Layouts/PathfinderLayout.vue";
import { ref, onMounted, computed, watch } from "vue";
import { usePage } from "@inertiajs/vue3";
import { useLocale } from "@/Composables/useLocale";
import CreateStaffModal from "@/Components/CreateStaffModal.vue";
import UpdatePasswordModal from "@/Components/ChangePassword.vue";
import AssistanceReportPdf from "@/Components/Reports/AssistanceReport.vue";
import { useGeneral } from "@/Composables/useGeneral";
import {
    fetchStaffRecord,
    fetchClubClasses,
    fetchReportsByStaffId,
    fetchReportByIdAndDate,
    fetchPersonalWorkplan,
    createTempStaffPathfinder
} from "@/Services/api";
import { ArrowDownTrayIcon, CalendarDaysIcon } from "@heroicons/vue/24/outline";
import { ArrowTurnLeftUpIcon } from "@heroicons/vue/24/solid";
import WorkplanCalendar from "@/Components/WorkplanCalendar.vue";
import { createClassPlan } from "@/Services/api";

const page = usePage();
const { showToast } = useGeneral();
const { locale, tr } = useLocale();
const formatDate = (date) => new Date(date).toLocaleDateString()
const createStaffModalVisible = ref(false);
const tempStaffModalVisible = ref(false);
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
const workplan = ref(null)
const workplanClubs = ref([])
const selectedWorkplanClubId = ref(null)
const workplanEvents = ref([])
const planModalOpen = ref(false)
const planForm = ref({
    workplan_event_id: null,
    class_id: null,
    type: 'plan',
    title: '',
    description: '',
    requested_date: '',
    location_override: ''
})
const selectedEvent = ref(null)
const planLocationSuggestions = ref([])
const planLocationLoading = ref(false)
let planLocationTimer = null
const canSelectWorkplanClub = computed(() => page.props.auth?.user?.profile_type === 'superadmin')
const tempStaffForm = ref({
    staff_name: '',
    staff_dob: '',
    staff_age: '',
    staff_email: '',
    staff_phone: '',
    club_id: ''
})

const fetchClasses = async (clubId) => {
    try {
        clubClasses.value = await fetchClubClasses(clubId.id)
    } catch (error) {
        console.error('Failed to fetch club classes:', error)
    }
}
const computeAge = (dob) => {
    if (!dob) return ''
    const birth = new Date(dob)
    const today = new Date()
    let age = today.getFullYear() - birth.getFullYear()
    const m = today.getMonth() - birth.getMonth()
    if (m < 0 || (m === 0 && today.getDate() < birth.getDate())) {
        age--
    }
    return age
}

const openStaffForm = (usr) => {
    if (!selectedClub.value) {
        showToast(tr("Selecciona un club primero", "Please select a club first"), "error");
        return;
    }
    selectedUserForStaff.value = usr;
    if (['pathfinders', 'temp_pathfinder'].includes(selectedClub.value?.club_type)) {
        tempStaffForm.value.club_id = selectedClub.value.id
        tempStaffForm.value.staff_email = usr?.email || ''
        tempStaffForm.value.staff_name = usr?.name || ''
        if (usr?.dob) {
            tempStaffForm.value.staff_dob = usr.dob.slice(0, 10)
            tempStaffForm.value.staff_age = computeAge(tempStaffForm.value.staff_dob)
        }
        tempStaffModalVisible.value = true
    } else {
        createStaffModalVisible.value = true;
    }
};

const userClub = computed(() => {
    if (!user.value) return null
    if (Array.isArray(user.value.clubs) && user.value.clubs.length) {
        const found = user.value.clubs.find(c => String(c.id) === String(user.value.club_id))
        return found || user.value.clubs[0]
    }
    if (user.value.club_id && user.value.club_name) {
        return { id: user.value.club_id, club_name: user.value.club_name }
    }
    return null
})
const fetchStaffRecordMethod = async () => {
    try {
        const data = await fetchStaffRecord();
        hasStaffRecord.value = data.hasStaffRecord;
        staff.value = data.staffRecord;
        user.value = data.user;
        if (userClub.value) {
            clubs.value = [userClub.value]
            selectedClub.value = userClub.value
            await fetchClasses(userClub.value)
        } else {
            clubs.value = []
            selectedClub.value = null
        }
        if (staff.value?.id) {
            await loadStaffReports(staff.value.id);
        }
        await loadWorkplan();
    } catch (error) {
        console.error("Failed to fetch staff record:", error);
    }
};



const loadStaffReports = async (staffId) => {
    try {
        reports.value = await fetchReportsByStaffId(staffId);
    } catch (error) {
        console.error("Failed to load staff reports", error);
        showToast(tr('Error cargando reportes', 'Error loading reports'), 'error');
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

watch(tempStaffModalVisible, (visible) => {
    if (!visible) {
        tempStaffForm.value = {
            staff_name: user.value?.name || '',
            staff_dob: user.value?.dob ? user.value.dob.slice(0, 10) : '',
            staff_age: user.value?.dob ? computeAge(user.value.dob.slice(0, 10)) : '',
            staff_email: user.value?.email || '',
            staff_phone: '',
            club_id: selectedClub.value?.id || ''
        }
        fetchStaffRecordMethod();
    }
})

watch(() => tempStaffForm.value.staff_dob, (dob) => {
    if (!dob) {
        tempStaffForm.value.staff_age = ''
        return
    }
    tempStaffForm.value.staff_age = computeAge(dob)
})

const submitTempStaff = async () => {
    try {
        if (!tempStaffForm.value.club_id) {
            tempStaffForm.value.club_id = selectedClub.value?.id || ''
        }
        await createTempStaffPathfinder(tempStaffForm.value)
        showToast(tr('Perfil de staff creado', 'Staff profile created'))
        tempStaffModalVisible.value = false
        // Refresh page to reflect new staff record immediately
        window.location.reload()
    } catch (error) {
        console.error('Failed to create temp staff', error)
        showToast(tr('Error creando staff', 'Error creating staff'), 'error')
    }
}


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
        alert(tr('No se pudo cargar el reporte para expandir', 'Failed to load report for expansion'));
    }
}


async function generatePDF(id, date) {
    inlineShow.value = false
    pdfShow.value = true
    try {
        pdfReport.value = await fetchReportByIdAndDateWrapper(id, date);
    } catch (err) {
        alert(tr('La generación del PDF falló', 'PDF generation failed'))
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

const normalizeDate = (val) => {
    if (!val) return ''
    const raw = String(val)
    return raw.includes('T') ? raw.slice(0, 10) : raw
}

const loadWorkplan = async (clubId = null) => {
    try {
        const { workplan: wp, clubs: wpClubs, selected_club_id } = await fetchPersonalWorkplan(clubId)
        workplan.value = wp
        workplanClubs.value = wpClubs || []
        selectedWorkplanClubId.value = selected_club_id || null
        workplanEvents.value = wp?.events || []
    } catch (e) {
        console.error('Failed to load workplan', e)
    }
}

const workplanPdfHref = computed(() => {
    if (!selectedWorkplanClubId.value) return '#'
    return route('club.personal.workplan.pdf', { club_id: selectedWorkplanClubId.value })
})

const workplanIcsHref = computed(() => {
    if (!selectedWorkplanClubId.value) return '#'
    return route('club.personal.workplan.ics', { club_id: selectedWorkplanClubId.value })
})

const showIcsHelp = ref(false)

const monthLabel = computed(() => {
    const date = new Date(monthCursor.value)
    return date.toLocaleDateString(undefined, { month: 'long', year: 'numeric' })
})

const periodLabel = computed(() => isMobile.value ? weekLabel.value : monthLabel.value)

const handleWorkplanClubChange = async () => {
    if (!selectedWorkplanClubId.value) return
    await loadWorkplan(selectedWorkplanClubId.value)
}

const openPlanModal = (event) => {
    selectedEvent.value = event
    planForm.value.workplan_event_id = event.id
    planForm.value.class_id = staff.value?.class_id || null
    planForm.value.type = 'plan'
    planForm.value.title = ''
    planForm.value.description = ''
    planForm.value.requested_date = event.date ? String(event.date).slice(0, 10) : ''
    planForm.value.location_override = ''
    planModalOpen.value = true
}

const savePlan = async () => {
    try {
        await createClassPlan(planForm.value)
        showToast('Class plan submitted', 'success')
        planModalOpen.value = false
        await loadWorkplan(selectedWorkplanClubId.value)
    } catch (e) {
        console.error(e)
        showToast(tr('No se pudo guardar el plan', 'Failed to save plan'), 'error')
    }
}

const searchPlanLocation = (query) => {
    if (planLocationTimer) clearTimeout(planLocationTimer)
    if (!query || query.length < 3) {
        planLocationSuggestions.value = []
        return
    }
    planLocationTimer = setTimeout(async () => {
        planLocationLoading.value = true
        try {
            const resp = await fetch(`https://nominatim.openstreetmap.org/search?format=json&q=${encodeURIComponent(query)}&limit=6`, {
                headers: { 'Accept-Language': locale.value === 'en' ? 'en' : 'es', 'User-Agent': 'club-portal/1.0' }
            })
            const data = await resp.json()
            planLocationSuggestions.value = (data || []).map(item => ({
                label: item.display_name,
                value: item.display_name,
            }))
        } catch (err) {
            console.error('Location search failed', err)
            planLocationSuggestions.value = []
        } finally {
            planLocationLoading.value = false
        }
    }, 400)
}

const applyPlanLocation = (item) => {
    planForm.value.location_override = item.value
    planLocationSuggestions.value = []
}

</script>

<template>
    <PathfinderLayout>
        <template #title>{{ tr('Panel de personal del club', 'Club Staff Dashboard') }}</template>

        <div class="space-y-4 text-gray-800">
            <p class="text-lg">
                {{ tr('Bienvenido', 'Welcome') }} {{ page.props.auth_user.name }} |
                {{ tr('Clase', 'Class') }} : {{ page.props.auth_user.assigned_class_name || page.props.auth.user.assigned_classes?.[0] || '—' }}
            </p>

            <div class="bg-white border rounded shadow-sm p-4">
                <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3 mb-3">
                    <div class="space-y-1">
                        <h2 class="text-xl font-semibold text-gray-800">{{ tr('Plan de trabajo del club', 'Club Workplan') }}</h2>
                        <p class="text-sm text-gray-600">{{ tr('Calendario de solo lectura de reuniones y eventos del club.', 'Read-only calendar of club-wide meetings and events.') }}</p>
                        <div class="flex items-center gap-2">
                            <label class="text-sm text-gray-700">{{ tr('Club', 'Club') }}</label>
                            <select v-if="canSelectWorkplanClub" v-model="selectedWorkplanClubId" class="border rounded px-3 py-1 text-sm" @change="handleWorkplanClubChange">
                                <option value="">{{ tr('Selecciona un club', 'Select a club') }}</option>
                                <option v-for="club in workplanClubs" :key="club.id" :value="club.id">{{ club.club_name }}</option>
                            </select>
                            <span v-else class="text-sm font-semibold text-gray-800">
                                {{ workplanClubs.find(club => String(club.id) === String(selectedWorkplanClubId))?.club_name || '—' }}
                            </span>
                        </div>
                    </div>
                    <div class="flex flex-col items-start gap-2">
                        <div class="flex gap-2 flex-wrap">
                            <a :href="workplanPdfHref" target="_blank" class="p-2 rounded-md bg-white border text-sm text-gray-800 hover:bg-gray-50 inline-flex items-center gap-1" :class="!selectedWorkplanClubId && 'opacity-50 pointer-events-none'">
                                <ArrowDownTrayIcon class="w-4 h-4" />
                                <span class="sr-only">{{ tr('Descargar PDF', 'Download PDF') }}</span>
                            </a>
                            <a :href="workplanIcsHref" target="_blank" class="p-2 rounded-md bg-white border text-sm text-gray-800 hover:bg-gray-50 inline-flex items-center gap-1" :class="!selectedWorkplanClubId && 'opacity-50 pointer-events-none'">
                                <CalendarDaysIcon class="w-4 h-4" />
                                <span class="sr-only">{{ tr('Descargar ICS', 'Download ICS') }}</span>
                            </a>
                        </div>
                        <button class="text-sm text-blue-600 hover:underline" @click="showIcsHelp = true" type="button">{{ tr('¿Cómo agregarlo?', 'How to add?') }}</button>
                    </div>
                </div>

                <div v-if="workplan">
                    <WorkplanCalendar
                        :events="workplanEvents"
                        :is-read-only="true"
                        :can-add="false"
                        :initial-date="workplan?.start_date || new Date().toISOString().slice(0,10)"
                        :pdf-href="workplanPdfHref"
                        :ics-href="workplanIcsHref"
                    />
                    
                </div>
                <div v-else class="text-sm text-gray-600">{{ tr('No hay un club activo para ver su plan de trabajo.', 'There is no active club available to view its workplan.') }}</div>
            </div>

            

            <!-- ICS Help Modal -->
            <div v-if="showIcsHelp" class="fixed inset-0 bg-black/40 flex items-center justify-center z-50">
                <div class="bg-white rounded-lg shadow-lg w-full max-w-lg p-5 space-y-4">
                    <h4 class="text-lg font-semibold">{{ tr('Agregar ICS a tu calendario', 'Add ICS to your calendar') }}</h4>
                    <div class="space-y-2 text-sm text-gray-700">
                        <p class="font-medium text-gray-800">iOS (iPhone/iPad)</p>
                        <ol class="list-decimal list-inside space-y-1">
                            <li>Tap the “Download ICS” link.</li>
                            <li>Choose “Open in Calendar” (or share to Calendar).</li>
                            <li>Tap “Add All” or select events to import.</li>
                        </ol>
                        <p class="font-medium text-gray-800 pt-2">Google Calendar (web)</p>
                        <ol class="list-decimal list-inside space-y-1">
                            <li>Download the .ics file.</li>
                            <li>Go to calendar.google.com → “Other calendars” → “Import”.</li>
                            <li>Upload the .ics file and pick the calendar to add to.</li>
                        </ol>
                    </div>
                    <div class="flex justify-end gap-2">
                        <button class="px-4 py-2 border rounded" @click="showIcsHelp = false">{{ tr('Cerrar', 'Close') }}</button>
                    </div>
                </div>
            </div>

            <div v-if="!hasStaffRecord && selectedClub" class="mb-4">
                <div class="text-sm text-gray-700 mb-2">
                    {{ tr('Club', 'Club') }}: <strong>{{ selectedClub.club_name }}</strong>
                </div>
                <button class="text-green-600 hover:underline mt-2" @click="openStaffForm(user)">
                    {{ tr('Crear mi perfil como staff', 'Create myself as Staff') }}
                </button>
            </div>
            <div class="space-y-6">
                <div class="w-full bg-white shadow rounded p-4 text-sm">
                    <h2 class="text-xl font-bold mb-4">{{ tr('Historial de reportes de asistencia', 'Assistance Reports - History') }}</h2>

                    <div class="hidden md:block overflow-x-auto">
                        <table class="min-w-full table-auto border border-gray-200 text-sm">
                            <thead class="bg-gray-100 text-left">
                                <tr>
                                    <th class="p-2 border">{{ tr('Mes', 'Month') }}</th>
                                    <th class="p-2 border">{{ tr('Año', 'Year') }}</th>
                                    <th class="p-2 border">{{ tr('Fecha', 'Date') }}</th>

                                    <th class="p-2 border">{{ tr('Acciones', 'Actions') }}</th>
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
                                                    <span v-if="expandedReports.has(report.id)">{{ tr('Contraer', 'Collapse') }}</span>
                                                    <span v-else>{{ tr('Expandir', 'Expand') }}</span>
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
                                <p><strong>{{ tr('Fecha', 'Date') }}:</strong> {{ formatDate(report.date) }}</p>
                                <p><strong>Staff:</strong> {{ report.staff_name }}</p>
                                <p><strong>{{ tr('Iglesia', 'Church') }}:</strong> {{ report.church }}</p>
                                <p><strong>{{ tr('Distrito', 'District') }}:</strong> {{ report.district }}</p>
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
                        <h2 class="text-xl font-bold mb-4">{{ tr('Perfil de usuario', 'User Profile') }}</h2>
                        <div class="mt-4">
                            <button @click="showPasswordModal = true"
                                class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700 text-sm">
                                {{ tr('Cambiar contraseña', 'Change Password') }}
                            </button>
                        </div>
                        <dl class="space-y-2 text-sm">
                            <div>
                                <dt class="font-semibold">ID</dt>
                                <dd>{{ user.id }}</dd>
                            </div>
                            <div>
                                <dt class="font-semibold">{{ tr('Nombre', 'Name') }}</dt>
                                <dd>{{ user.name }}</dd>
                            </div>
                            <div>
                                <dt class="font-semibold">Email</dt>
                                <dd>{{ user.email }}</dd>
                            </div>
                            <div>
                                <dt class="font-semibold">{{ tr('Correo verificado', 'Email Verified') }}</dt>
                                <dd>{{ user.email_verified_at ?? tr("No verificado", "Not verified") }}</dd>
                            </div>
                            <div>
                                <dt class="font-semibold">{{ tr('Creado el', 'Created At') }}</dt>
                                <dd>{{ user.created_at?.slice(0, 10) }}</dd>
                            </div>
                            <div>
                                <dt class="font-semibold">{{ tr('Actualizado el', 'Updated At') }}</dt>
                                <dd>{{ user.updated_at?.slice(0, 10) }}</dd>
                            </div>
                            <div>
                                <dt class="font-semibold">{{ tr('Tipo de perfil', 'Profile Type') }}</dt>
                                <dd>{{ user.profile_type }}</dd>
                            </div>
                            <div>
                                <dt class="font-semibold">{{ tr('Subrol', 'Sub Role') }}</dt>
                                <dd>{{ user.sub_role }}</dd>
                            </div>
                            <div>
                                <dt class="font-semibold">{{ tr('Nombre de iglesia', 'Church Name') }}</dt>
                                <dd>{{ user.church_name }}</dd>
                            </div>


                        </dl>
                    </div>

                    <div v-if="staff"
                        class="w-full md:w-1/3 bg-white shadow rounded p-4 text-sm h-[450px] overflow-y-auto">
                        <h2 class="text-xl font-bold mb-4">{{ tr('Perfil de staff', 'Staff Profile') }}</h2>
                        <dl class="space-y-2">
                            <div>
                                <dt class="font-semibold">ID</dt>
                                <dd>{{ staff.id }}</dd>
                            </div>
                            <div>
                                <dt class="font-semibold">{{ tr('Nombre', 'Name') }}</dt>
                                <dd>{{ staff.name }}</dd>
                            </div>
                            <div>
                                <dt class="font-semibold">{{ tr('Fecha de nacimiento', 'Date of Birth') }}</dt>
                                <dd>{{ staff.dob?.slice(0, 10) }}</dd>
                            </div>
                            <div>
                                <dt class="font-semibold">Email</dt>
                                <dd>{{ staff.email }}</dd>
                            </div>
                            <div>
                                <dt class="font-semibold">{{ tr('Teléfono celular', 'Cell Phone') }}</dt>
                                <dd>{{ staff.cell_phone }}</dd>
                            </div>
                            <div>
                                <dt class="font-semibold">{{ tr('Dirección', 'Address') }}</dt>
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
                                <dt class="font-semibold">{{ tr('Clase asignada', 'Assigned Class') }}</dt>
                                <dd>{{ staff.assigned_class }}</dd>
                            </div>
                            <div>
                                <dt class="font-semibold">{{ tr('Limitación de salud', 'Health Limitation') }}</dt>
                                <dd>{{ staff.has_health_limitation ? tr('Sí', 'Yes') : tr('No', 'No') }}</dd>
                            </div>
                            <div v-if="staff.has_health_limitation && staff.health_limitation_description">
                                <dt class="font-semibold">{{ tr('Descripción de limitación', 'Limitation Description') }}</dt>
                                <dd>{{ staff.health_limitation_description }}</dd>
                            </div>
                            <div>
                                <dt class="font-semibold">{{ tr('Sterling Volunteer completado', 'Sterling Volunteer Completed') }}</dt>
                                <dd>{{ staff.sterling_volunteer_completed ? tr('Sí', 'Yes') : tr('No', 'No') }}</dd>
                            </div>

                            <div>
                                <dt class="font-semibold">{{ tr('Fecha de firma', 'Application Signed Date') }}</dt>
                                <dd>{{ staff.application_signed_date?.slice(0, 10) }}</dd>
                            </div>
                            <div>
                                <dt class="font-semibold">{{ tr('Firma del solicitante', 'Applicant Signature') }}</dt>
                                <dd>{{ staff.applicant_signature }}</dd>
                            </div>
                            <div>
                                <dt class="font-semibold">Status</dt>
                                <dd>{{ staff.status }}</dd>
                            </div>

                            <div class="mt-4">
                                <h3 class="font-semibold">{{ tr('Referencias', 'References') }}</h3>
                                <ul class="list-disc pl-5">
                                    <li>{{ tr('Pastor', 'Pastor') }}: {{ staff.reference_pastor }}</li>
                                    <li>{{ tr('Anciano', 'Elder') }}: {{ staff.reference_elder }}</li>
                                    <li>{{ tr('Otro', 'Other') }}: {{ staff.reference_other }}</li>
                                </ul>
                            </div>

                            <div v-if="staff.experiences?.length" class="mt-4">
                                <h3 class="font-semibold">{{ tr('Experiencias', 'Experiences') }}</h3>
                                <ul class="list-disc pl-5">
                                    <li v-for="(exp, i) in staff.experiences" :key="i">
                                        {{ exp.position }} at {{ exp.organization }} ({{ exp.date }})
                                    </li>
                                </ul>
                            </div>

                            <div v-if="staff.award_instruction_abilities?.length" class="mt-4">
                                <h3 class="font-semibold">{{ tr('Habilidades de instrucción de insignias', 'Award Instruction Abilities') }}</h3>
                                <ul class="list-disc pl-5">
                                    <li v-for="(ability, i) in staff.award_instruction_abilities" :key="i">
                                        {{ ability.name }} - {{ tr('Nivel', 'Level') }} {{ ability.level }}
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
            @close="showPasswordModal = false" @updated="showToast(tr('Contraseña actualizada correctamente', 'Password updated successfully'))" />

        <CreateStaffModal :show="createStaffModalVisible" :user="selectedUserForStaff" :club="selectedClub"
            :club-classes="clubClasses" @close="createStaffModalVisible = false"
            @submitted="showToast(tr('Perfil de staff creado', 'Staff profile created'))" />

        <!-- Temp staff modal for pathfinder clubs -->
        <div v-if="tempStaffModalVisible" class="fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-50">
            <div class="bg-white rounded-lg shadow-xl w-full max-w-lg p-6">
                <div class="flex justify-between items-center mb-4">
                    <h2 class="text-lg font-bold">{{ tr('Crear staff (Pathfinder)', 'Create Staff (Pathfinder)') }}</h2>
                    <button @click="tempStaffModalVisible = false" class="text-red-500 text-lg font-bold">&times;</button>
                </div>
                <div class="space-y-3">
                    <div>
                        <label class="block text-sm font-medium">{{ tr('Nombre', 'Name') }}</label>
                        <input v-model="tempStaffForm.staff_name" type="text" class="w-full border rounded p-2" />
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div>
                            <label class="block text-sm font-medium">DOB</label>
                            <input v-model="tempStaffForm.staff_dob" type="date" class="w-full border rounded p-2" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium">Age</label>
                            <input v-model="tempStaffForm.staff_age" type="number" min="0" class="w-full border rounded p-2" />
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium">Email</label>
                        <input v-model="tempStaffForm.staff_email" type="email" class="w-full border rounded p-2" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium">Phone</label>
                        <input v-model="tempStaffForm.staff_phone" type="text" class="w-full border rounded p-2" />
                    </div>
                </div>
                <div class="flex justify-end gap-2 mt-5">
                    <button class="px-4 py-2 border rounded" @click="tempStaffModalVisible = false">{{ tr('Cancelar', 'Cancel') }}</button>
                    <button class="px-4 py-2 bg-blue-600 text-white rounded" @click="submitTempStaff">{{ tr('Guardar', 'Save') }}</button>
                </div>
            </div>
        </div>

        <AssistanceReportPdf v-if="!inlineShow && pdfShow && pdfReport" :report="pdfReport" ref="pdfComponent"
            @pdf-done="pdfReport = null" :disableAutoDownload="inlineShow" />

    </PathfinderLayout>
</template>
