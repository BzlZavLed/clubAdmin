<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import axios from 'axios'
import PathfinderLayout from '@/Layouts/PathfinderLayout.vue'
import { useGeneral } from '@/Composables/useGeneral'
import { useLocale } from '@/Composables/useLocale'

const props = defineProps({
    auth_user: Object,
    clubs: { type: Array, default: () => [] },
    selected_club_id: { type: [String, Number, null], default: null },
    enrollment_session: { type: Object, default: null },
})

const { showToast } = useGeneral()
const { tr } = useLocale()
const canSelectClub = computed(() => props.auth_user?.profile_type === 'superadmin')
const selectedClubId = ref(props.selected_club_id || props.clubs[0]?.id || '')
const enrollmentSession = ref(props.enrollment_session || null)
const enrollmentRefreshing = ref(false)
const enrollmentActionId = ref(null)
const staffAssignments = ref({})
const staffTreasurers = ref({})
const childClassAssignmentId = ref(null)
const showAssistedEnrollment = ref(false)
const assistedStep = ref(1)
const assistedSubmitting = ref(false)
const assistedEnrollment = reactive({
    enrollment_type: 'parent_and_member',
    parent: { name: '', email: '', phone: '', password: '', password_confirmation: '' },
    member: {
        applicant_name: '', birthdate: '', age: '', grade: '', mailing_address: '', home_address: '',
        cell_number: '', emergency_contact: '', parent_name: '', parent_cell: '', email_address: '',
        signature: '', allergies: '', physical_restrictions: '', health_history: '', investiture_classes: [],
        program_year: 1, father_guardian_name: '', father_guardian_phone: '', mother_guardian_name: '', mother_guardian_phone: '',
    },
})
const assistedTotalSteps = computed(() => 4)
let pollingTimer = null

watch(selectedClubId, (clubId) => {
    if (!clubId) return
    router.get(route('club.settings.enrollment.display'), { club_id: clubId }, { replace: true })
})

watch(() => props.enrollment_session, (value) => {
    enrollmentSession.value = value || null
})

async function refreshEnrollmentSession() {
    if (!selectedClubId.value) return
    enrollmentRefreshing.value = true
    try {
        const { data } = await axios.get(route('club.settings.enrollment-session'), { params: { club_id: selectedClubId.value } })
        enrollmentSession.value = data.data
    } catch (error) {
        console.error('Could not refresh enrollment session', error)
    } finally {
        enrollmentRefreshing.value = false
    }
}

async function updateEnrollmentParent(parent, action) {
    enrollmentActionId.value = parent.id
    try {
        const routeName = action === 'approve'
            ? 'club.settings.enrollment.parents.approve'
            : 'club.settings.enrollment.parents.reject'
        const { data } = await axios.post(route(routeName, parent.id), { club_id: selectedClubId.value })
        enrollmentSession.value = data.data
        showToast(action === 'approve' ? tr('Solicitud aprobada', 'Request approved') : tr('Solicitud rechazada', 'Request rejected'))
    } catch (error) {
        showToast(error?.response?.data?.message || tr('No se pudo actualizar la solicitud', 'Could not update the request'), 'error')
    } finally {
        enrollmentActionId.value = null
    }
}

async function updateEnrollmentStaff(staff, action) {
    enrollmentActionId.value = `staff-${staff.id}`
    try {
        const routeName = action === 'approve'
            ? 'club.settings.enrollment.staff.approve'
            : 'club.settings.enrollment.staff.reject'
        const payload = { club_id: selectedClubId.value }
        if (action === 'approve') {
            payload.assigned_class = staffAssignments.value[staff.id] || null
            payload.make_treasurer = Boolean(staffTreasurers.value[staff.id])
        }
        const { data } = await axios.post(route(routeName, staff.id), payload)
        enrollmentSession.value = data.data
        delete staffAssignments.value[staff.id]
        delete staffTreasurers.value[staff.id]
        showToast(action === 'approve' ? tr('Personal aprobado', 'Staff approved') : tr('Solicitud rechazada', 'Request rejected'))
    } catch (error) {
        showToast(error?.response?.data?.message || tr('No se pudo actualizar la solicitud', 'Could not update the request'), 'error')
    } finally {
        enrollmentActionId.value = null
    }
}

async function assignChildClass(child, event) {
    const classId = event.target.value
    if (!classId) return

    childClassAssignmentId.value = child.id
    try {
        await axios.post(route('members.assign'), {
            member_id: child.id,
            club_class_id: classId,
            role: 'student',
            assigned_at: new Date().toISOString().slice(0, 10),
        })
        child.class_name = enrollmentSession.value.classes.find((clubClass) => String(clubClass.id) === String(classId))?.name || null
        showToast(tr('Clase asignada', 'Class assigned'))
    } catch (error) {
        event.target.value = ''
        showToast(error?.response?.data?.message || tr('No se pudo asignar la clase', 'Could not assign the class'), 'error')
    } finally {
        childClassAssignmentId.value = null
    }
}

function openAssistedEnrollment() {
    assistedStep.value = 1
    showAssistedEnrollment.value = true
}

function nextAssistedStep() {
    assistedStep.value = Math.min(assistedStep.value + 1, assistedTotalSteps.value)
}

function previousAssistedStep() {
    assistedStep.value = Math.max(1, assistedStep.value - 1)
}

async function submitAssistedEnrollment() {
    assistedSubmitting.value = true
    try {
        const { data } = await axios.post(route('club.settings.enrollment.assisted.store'), {
            club_id: selectedClubId.value,
            enrollment_type: assistedEnrollment.enrollment_type,
            parent: assistedEnrollment.parent,
            member: assistedEnrollment.member,
        })
        enrollmentSession.value = data.data
        showAssistedEnrollment.value = false
        showToast(tr('Inscripción creada y aprobada', 'Enrollment created and approved'))
    } catch (error) {
        showToast(error?.response?.data?.message || tr('Revisa los datos requeridos antes de continuar', 'Review the required information before continuing'), 'error')
    } finally {
        assistedSubmitting.value = false
    }
}

async function copyEnrollmentUrl() {
    try {
        await navigator.clipboard.writeText(enrollmentSession.value.registration_url)
        showToast(tr('Enlace copiado', 'Link copied'))
    } catch {
        showToast(tr('No se pudo copiar el enlace', 'Could not copy the link'), 'error')
    }
}

async function copyStaffEnrollmentUrl() {
    try {
        await navigator.clipboard.writeText(enrollmentSession.value.staff_registration_url)
        showToast(tr('Enlace copiado', 'Link copied'))
    } catch {
        showToast(tr('No se pudo copiar el enlace', 'Could not copy the link'), 'error')
    }
}

onMounted(() => {
    pollingTimer = window.setInterval(refreshEnrollmentSession, 5000)
})

onBeforeUnmount(() => {
    if (pollingTimer) window.clearInterval(pollingTimer)
})
</script>

<template>
    <PathfinderLayout>
        <template #title>{{ tr('Sesión de inscripciones', 'Enrollment session') }}</template>

        <div class="mx-auto max-w-5xl space-y-6">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <Link :href="route('club.settings', { club_id: selectedClubId })" class="text-sm text-blue-700 hover:underline">← {{ tr('Volver a configuración', 'Back to settings') }}</Link>
                <select v-if="canSelectClub" v-model="selectedClubId" class="rounded border px-3 py-2 text-sm">
                    <option v-for="club in clubs" :key="club.id" :value="club.id">{{ club.club_name }}</option>
                </select>
                <div class="flex items-center gap-3"><button type="button" class="rounded bg-blue-600 px-3 py-2 text-sm font-semibold text-white hover:bg-blue-700" @click="openAssistedEnrollment">{{ tr('Inscripción asistida', 'Assisted enrollment') }}</button><span class="text-sm text-emerald-700">{{ enrollmentRefreshing ? tr('Actualizando...', 'Refreshing...') : tr('En vivo · cada 5 segundos', 'Live · every 5 seconds') }}</span></div>
            </div>

            <div v-if="!enrollmentSession" class="rounded border border-dashed p-6 text-center text-gray-500">{{ tr('No hay un club seleccionado.', 'No club is selected.') }}</div>
            <template v-else>
                <section class="bg-slate-950 p-5 text-white shadow-sm sm:p-8">
                    <div class="grid gap-6 lg:grid-cols-2">
                    <div class="grid items-center gap-6 rounded-xl bg-white p-5 text-slate-900 md:grid-cols-[minmax(0,1fr)_220px] md:p-7">
                        <div>
                            <p class="text-sm font-medium uppercase tracking-wide text-slate-500">{{ enrollmentSession.club?.club_name }}</p>
                            <h2 class="mt-2 text-2xl font-bold">{{ tr('Escanea para registrar a un padre o madre', 'Scan to register a parent') }}</h2>
                            <p class="mt-3 break-all text-sm text-slate-600">{{ enrollmentSession.registration_url }}</p>
                            <button type="button" class="mt-4 rounded bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700" @click="copyEnrollmentUrl">{{ tr('Copiar enlace', 'Copy link') }}</button>
                        </div>
                        <img :src="enrollmentSession.qr_url" :alt="tr('Código QR de inscripción', 'Enrollment QR code')" class="mx-auto w-full max-w-[280px] rounded bg-white p-2" />
                    </div>
                    <div class="grid items-center gap-6 rounded-xl bg-sky-50 p-5 text-slate-900 md:grid-cols-[minmax(0,1fr)_220px] md:p-7">
                        <div>
                            <p class="text-sm font-medium uppercase tracking-wide text-sky-700">{{ enrollmentSession.club?.club_name }}</p>
                            <h2 class="mt-2 text-2xl font-bold">{{ tr('Escanea para registrar personal', 'Scan to register staff') }}</h2>
                            <p class="mt-3 break-all text-sm text-slate-600">{{ enrollmentSession.staff_registration_url }}</p>
                            <button type="button" class="mt-4 rounded bg-sky-700 px-4 py-2 text-sm font-semibold text-white hover:bg-sky-800" @click="copyStaffEnrollmentUrl">{{ tr('Copiar enlace', 'Copy link') }}</button>
                        </div>
                        <img :src="enrollmentSession.staff_qr_url" :alt="tr('Código QR de personal', 'Staff QR code')" class="mx-auto w-full max-w-[220px] rounded bg-white p-2" />
                    </div>
                    </div>

                    <div class="mt-6 rounded-xl border border-amber-300 bg-amber-50 p-5 text-center text-slate-900">
                        <p class="text-sm font-semibold uppercase tracking-wide text-amber-800">{{ tr('Código de invitación de la iglesia', 'Church invitation code') }}</p>
                        <p class="mt-2 break-all font-mono text-3xl font-bold tracking-[0.2em] sm:text-5xl">{{ enrollmentSession.church_invite_code }}</p>
                    </div>
                </section>

                <section class="rounded-xl bg-white p-5 shadow-sm md:p-6">
                    <div class="flex items-center justify-between gap-3">
                        <div><h2 class="text-lg font-bold">{{ tr('Solicitudes de padres', 'Parent requests') }}</h2><p class="text-sm text-slate-500">{{ tr('Aprueba o rechaza cada cuenta al llegar.', 'Approve or reject each account as it arrives.') }}</p></div>
                        <span class="rounded-full bg-amber-100 px-3 py-1 text-sm font-semibold text-amber-800">{{ enrollmentSession.pending_parents?.length || 0 }}</span>
                    </div>
                    <div class="mt-4 overflow-x-auto"><table class="min-w-full text-sm"><thead class="border-b text-left text-slate-500"><tr><th class="px-2 py-2">{{ tr('Padre/Madre', 'Parent') }}</th><th class="px-2 py-2">{{ tr('Correo', 'Email') }}</th><th class="px-2 py-2 text-right">{{ tr('Acciones', 'Actions') }}</th></tr></thead><tbody><tr v-for="parent in enrollmentSession.pending_parents" :key="parent.id" class="border-b last:border-0"><td class="px-2 py-3 font-medium">{{ parent.name }}</td><td class="px-2 py-3 text-slate-600">{{ parent.email }}</td><td class="px-2 py-3 text-right whitespace-nowrap"><button type="button" class="mr-2 rounded bg-emerald-600 px-3 py-1.5 font-medium text-white disabled:opacity-60" :disabled="enrollmentActionId === parent.id" @click="updateEnrollmentParent(parent, 'approve')">{{ tr('Aprobar', 'Approve') }}</button><button type="button" class="rounded border border-red-300 px-3 py-1.5 font-medium text-red-700 disabled:opacity-60" :disabled="enrollmentActionId === parent.id" @click="updateEnrollmentParent(parent, 'reject')">{{ tr('Rechazar', 'Reject') }}</button></td></tr><tr v-if="!enrollmentSession.pending_parents?.length"><td colspan="3" class="px-2 py-5 text-center text-slate-500">{{ tr('Aún no hay solicitudes pendientes.', 'There are no pending requests yet.') }}</td></tr></tbody></table></div>
                </section>

                <section class="rounded-xl bg-white p-5 shadow-sm md:p-6">
                    <div class="flex items-center justify-between gap-3"><div><h2 class="text-lg font-bold">{{ tr('Solicitudes de personal', 'Staff requests') }}</h2><p class="text-sm text-slate-500">{{ tr('Aprueba al personal, asígnale una clase o conviértelo en tesorero.', 'Approve staff, assign a class, or make them treasurer.') }}</p></div><span class="rounded-full bg-sky-100 px-3 py-1 text-sm font-semibold text-sky-800">{{ enrollmentSession.pending_staff?.length || 0 }}</span></div>
                    <div class="mt-4 overflow-x-auto"><table class="min-w-full text-sm"><thead class="border-b text-left text-slate-500"><tr><th class="px-2 py-2">{{ tr('Personal', 'Staff') }}</th><th class="px-2 py-2">{{ tr('Clase', 'Class') }}</th><th class="px-2 py-2">{{ tr('Tesorería', 'Treasury') }}</th><th class="px-2 py-2 text-right">{{ tr('Acciones', 'Actions') }}</th></tr></thead><tbody><tr v-for="staff in enrollmentSession.pending_staff" :key="staff.id" class="border-b last:border-0"><td class="px-2 py-3"><div class="font-medium">{{ staff.name }}</div><div class="text-slate-600">{{ staff.email }}</div></td><td class="px-2 py-3"><select v-model="staffAssignments[staff.id]" class="min-w-40 rounded border px-2 py-1.5"><option value="">{{ tr('Sin clase', 'No class') }}</option><option v-for="clubClass in enrollmentSession.classes" :key="clubClass.id" :value="clubClass.id">{{ clubClass.name }}</option></select></td><td class="px-2 py-3"><label class="inline-flex items-center gap-2"><input v-model="staffTreasurers[staff.id]" type="checkbox" class="rounded border-slate-300 text-sky-700"><span>{{ tr('Hacer tesorero', 'Make treasurer') }}</span></label></td><td class="px-2 py-3 text-right whitespace-nowrap"><button type="button" class="mr-2 rounded bg-emerald-600 px-3 py-1.5 font-medium text-white disabled:opacity-60" :disabled="enrollmentActionId === `staff-${staff.id}`" @click="updateEnrollmentStaff(staff, 'approve')">{{ tr('Aprobar', 'Approve') }}</button><button type="button" class="rounded border border-red-300 px-3 py-1.5 font-medium text-red-700 disabled:opacity-60" :disabled="enrollmentActionId === `staff-${staff.id}`" @click="updateEnrollmentStaff(staff, 'reject')">{{ tr('Rechazar', 'Reject') }}</button></td></tr><tr v-if="!enrollmentSession.pending_staff?.length"><td colspan="4" class="px-2 py-5 text-center text-slate-500">{{ tr('Aún no hay solicitudes pendientes.', 'There are no pending requests yet.') }}</td></tr></tbody></table></div>
                </section>

                <section class="rounded-xl bg-white p-5 shadow-sm md:p-6">
                    <h2 class="text-lg font-bold">{{ tr('Inscripciones completadas', 'Completed enrollments') }}</h2>
                    <p class="text-sm text-slate-500">{{ tr('Cada padre aprobado aparece con los hijos registrados en este club.', 'Each approved parent appears with children registered in this club.') }}</p>
                    <div v-if="!enrollmentSession.enrolled_parents?.length" class="mt-4 rounded border border-dashed p-4 text-sm text-slate-500">{{ tr('Aún no hay hijos registrados.', 'There are no registered children yet.') }}</div>
                    <div v-else class="mt-4 space-y-3"><div v-for="parent in enrollmentSession.enrolled_parents" :key="parent.id" class="rounded border border-slate-200 p-4"><div class="font-semibold">{{ parent.name }} <span class="ml-2 font-normal text-slate-500">{{ parent.email }}</span></div><div class="mt-3 space-y-2 border-l-2 border-blue-200 pl-4"><div v-for="child in parent.children" :key="child.id" class="flex flex-wrap items-center gap-2 text-sm"><span class="font-medium">{{ child.name }}</span><span class="text-slate-500"> · {{ child.age ? `${child.age} ${tr('años', 'years')}` : tr('Edad no registrada', 'Age not recorded') }} · {{ child.club_name || enrollmentSession.club?.club_name }} · {{ child.class_name || tr('Sin clase asignada', 'No class assigned') }}</span><select v-if="!child.class_name && child.can_assign_class" :disabled="childClassAssignmentId === child.id" class="rounded border border-blue-300 bg-white px-2 py-1 text-sm text-blue-800 disabled:opacity-60" @change="assignChildClass(child, $event)"><option value="">{{ tr('Asignar clase…', 'Assign class…') }}</option><option v-for="clubClass in enrollmentSession.classes" :key="clubClass.id" :value="clubClass.id">{{ clubClass.name }}</option></select></div></div></div></div>
                </section>
            </template>

            <div v-if="showAssistedEnrollment" class="fixed inset-0 z-50 overflow-y-auto bg-slate-950/60 p-4">
                <div class="mx-auto my-6 w-full max-w-2xl rounded-xl bg-white p-5 shadow-xl sm:p-7">
                    <div class="flex items-start justify-between gap-4 border-b pb-4"><div><p class="text-sm font-semibold text-blue-700">{{ tr('Paso', 'Step') }} {{ assistedStep }} {{ tr('de', 'of') }} {{ assistedTotalSteps }}</p><h2 class="text-xl font-bold">{{ tr('Inscripción asistida', 'Assisted enrollment') }}</h2></div><button type="button" class="text-2xl text-slate-500" @click="showAssistedEnrollment = false">×</button></div>

                    <div v-if="assistedStep === 1" class="mt-6 space-y-4"><p class="text-lg font-medium">{{ tr('¿Qué necesitas registrar?', 'What do you need to register?') }}</p><label class="flex cursor-pointer items-center gap-3 rounded border p-4"><input v-model="assistedEnrollment.enrollment_type" type="radio" value="parent_and_member"><span><strong>{{ tr('Padre/madre y miembro', 'Parent and member') }}</strong><small class="block text-slate-500">{{ tr('La cuenta se crea y aprueba inmediatamente.', 'The account is created and approved immediately.') }}</small></span></label><label class="flex cursor-pointer items-center gap-3 rounded border p-4"><input v-model="assistedEnrollment.enrollment_type" type="radio" value="member_only"><span><strong>{{ tr('Solo miembro', 'Member only') }}</strong><small class="block text-slate-500">{{ tr('Registra al menor sin crear una cuenta de padre.', 'Register the child without creating a parent account.') }}</small></span></label></div>

                    <div v-if="assistedStep === 2" class="mt-6 grid gap-4 sm:grid-cols-2"><template v-if="assistedEnrollment.enrollment_type === 'parent_and_member'"><div class="sm:col-span-2"><h3 class="text-lg font-semibold">{{ tr('Cuenta del padre o madre', 'Parent account') }}</h3></div><label>{{ tr('Nombre', 'Name') }}<input v-model="assistedEnrollment.parent.name" class="mt-1 w-full rounded border p-3" /></label><label>{{ tr('Correo', 'Email') }}<input v-model="assistedEnrollment.parent.email" type="email" class="mt-1 w-full rounded border p-3" /></label><label>{{ tr('Teléfono', 'Phone') }}<input v-model="assistedEnrollment.parent.phone" class="mt-1 w-full rounded border p-3" /></label><label>{{ tr('Contraseña temporal', 'Temporary password') }}<input v-model="assistedEnrollment.parent.password" type="password" class="mt-1 w-full rounded border p-3" /></label><label class="sm:col-span-2">{{ tr('Confirmar contraseña', 'Confirm password') }}<input v-model="assistedEnrollment.parent.password_confirmation" type="password" class="mt-1 w-full rounded border p-3" /></label></template><template v-else><div class="sm:col-span-2"><h3 class="text-lg font-semibold">{{ tr('Contacto del padre o madre', 'Parent contact') }}</h3></div><label>{{ tr('Nombre', 'Name') }}<input v-model="assistedEnrollment.member.parent_name" class="mt-1 w-full rounded border p-3" /></label><label>{{ tr('Teléfono', 'Phone') }}<input v-model="assistedEnrollment.member.parent_cell" class="mt-1 w-full rounded border p-3" /></label><label class="sm:col-span-2">{{ tr('Correo', 'Email') }}<input v-model="assistedEnrollment.member.email_address" type="email" class="mt-1 w-full rounded border p-3" /></label></template></div>

                    <div v-if="assistedStep === 3" class="mt-6 grid gap-4 sm:grid-cols-2"><div class="sm:col-span-2"><h3 class="text-lg font-semibold">{{ tr('Datos del menor', 'Child details') }}</h3></div><label class="sm:col-span-2">{{ tr('Nombre', 'Name') }}<input v-model="assistedEnrollment.member.applicant_name" class="mt-1 w-full rounded border p-3" /></label><label>{{ tr('Fecha de nacimiento', 'Birthdate') }}<input v-model="assistedEnrollment.member.birthdate" type="date" class="mt-1 w-full rounded border p-3" /></label><label>{{ tr('Edad', 'Age') }}<input v-model="assistedEnrollment.member.age" type="number" class="mt-1 w-full rounded border p-3" /></label><label>{{ tr('Grado', 'Grade') }}<input v-model="assistedEnrollment.member.grade" class="mt-1 w-full rounded border p-3" /></label><label>{{ tr('Teléfono', 'Phone') }}<input v-model="assistedEnrollment.member.cell_number" class="mt-1 w-full rounded border p-3" /></label><label class="sm:col-span-2">{{ tr('Dirección', 'Address') }}<input v-model="assistedEnrollment.member.mailing_address" class="mt-1 w-full rounded border p-3" /></label><label class="sm:col-span-2">{{ tr('Contacto de emergencia', 'Emergency contact') }}<input v-model="assistedEnrollment.member.emergency_contact" class="mt-1 w-full rounded border p-3" /></label></div>

                    <div v-if="assistedStep === 4" class="mt-6 space-y-4"><h3 class="text-lg font-semibold">{{ tr('Información final', 'Final information') }}</h3><label class="block">{{ tr('Dirección residencial', 'Home address') }}<input v-model="assistedEnrollment.member.home_address" class="mt-1 w-full rounded border p-3" /></label><label class="block">{{ tr('Alergias', 'Allergies') }}<textarea v-model="assistedEnrollment.member.allergies" class="mt-1 w-full rounded border p-3"></textarea></label><label class="block">{{ tr('Restricciones físicas', 'Physical restrictions') }}<textarea v-model="assistedEnrollment.member.physical_restrictions" class="mt-1 w-full rounded border p-3"></textarea></label><label class="block">{{ tr('Historial médico', 'Health history') }}<textarea v-model="assistedEnrollment.member.health_history" class="mt-1 w-full rounded border p-3"></textarea></label><label class="block">{{ tr('Firma del padre/madre', 'Parent signature') }}<input v-model="assistedEnrollment.member.signature" class="mt-1 w-full rounded border p-3" /></label></div>

                    <div class="mt-7 flex justify-between border-t pt-4"><button v-if="assistedStep > 1" type="button" class="rounded border px-4 py-2" @click="previousAssistedStep">{{ tr('Anterior', 'Back') }}</button><span v-else></span><button v-if="assistedStep < assistedTotalSteps" type="button" class="rounded bg-blue-600 px-4 py-2 font-semibold text-white" @click="nextAssistedStep">{{ tr('Continuar', 'Continue') }}</button><button v-else type="button" class="rounded bg-emerald-600 px-4 py-2 font-semibold text-white disabled:opacity-60" :disabled="assistedSubmitting" @click="submitAssistedEnrollment">{{ assistedSubmitting ? tr('Guardando...', 'Saving...') : tr('Crear inscripción', 'Create enrollment') }}</button></div>
                </div>
            </div>
        </div>
    </PathfinderLayout>
</template>
