<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
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
                <span class="text-sm text-emerald-700">{{ enrollmentRefreshing ? tr('Actualizando...', 'Refreshing...') : tr('En vivo · cada 5 segundos', 'Live · every 5 seconds') }}</span>
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
        </div>
    </PathfinderLayout>
</template>
