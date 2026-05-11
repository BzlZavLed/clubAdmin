<script setup>
import { ref, onMounted, computed } from 'vue'
import PathfinderLayout from '@/Layouts/PathfinderLayout.vue'
import WorkplanCalendar from '@/Components/WorkplanCalendar.vue'
import { fetchParentWorkplan, fetchParentReceipts } from '@/Services/api'
import { useGeneral } from '@/Composables/useGeneral'
import UpdatePasswordModal from "@/Components/ChangePassword.vue";
import { useLocale } from '@/Composables/useLocale'
import axios from 'axios'

const props = defineProps({
    auth_user: Object,
    parent_setup: {
        type: Object,
        default: null,
    },
    is_superadmin_parent_preview: {
        type: Boolean,
        default: false,
    },
})

const { showToast } = useGeneral()
const { tr } = useLocale()

const clubs = ref([])
const selectedClubId = ref(null)
const workplan = ref(null)
const events = ref([])
const memberships = ref([])
const selectedEvent = ref(null)
const eventModalOpen = ref(false)
const showPasswordModal = ref(false)
const changePasswordUserId = ref(null)
const parentSetup = ref(props.parent_setup)
const creatingParentAccount = ref(false)
const parentAccountResult = ref(null)
const parentAccountError = ref('')
const needsParentAccountSetup = computed(() => Boolean(parentSetup.value?.needs_account))
const workplanPdfHref = computed(() => selectedClubId.value ? route('parent.workplan.pdf', { club_id: selectedClubId.value }) : '#')
const workplanIcsHref = computed(() => selectedClubId.value ? route('parent.workplan.ics', { club_id: selectedClubId.value }) : '#')
const receipts = ref([])

const cleanDate = (val) => {
    if (!val) return '—'
    const str = String(val)
    if (str.includes('T')) return str.slice(0, 10)
    return str
}

const cleanTime = (val) => {
    if (!val) return ''
    const str = String(val)
    const parts = str.split(':')
    if (parts.length >= 2) return `${parts[0].padStart(2, '0')}:${parts[1].padStart(2, '0')}`
    return str
}

const load = async (clubId = null) => {
    try {
        const { clubs: c, selected_club_id, workplan: wp, memberships: m } = await fetchParentWorkplan(clubId)
        clubs.value = c || []
        selectedClubId.value = selected_club_id || null
        workplan.value = wp
        events.value = (wp?.events || []).map(ev => {
            const sourceType = ev.source_type || ''
            const isInherited = sourceType.includes('AssociationWorkplanEvent') || sourceType.includes('DistrictWorkplanEvent')
            return {
                ...ev,
                classPlans: ev.classPlans || ev.class_plans || [],
                _inherited: isInherited,
                _source_level: sourceType.includes('District') ? 'district' : (sourceType.includes('Association') ? 'association' : null),
            }
        })
        memberships.value = m || []
    } catch (e) {
        console.error(e)
        showToast(tr('No se pudo cargar el plan de trabajo', 'Could not load the workplan'), 'error')
    }
}

const loadReceipts = async () => {
    try {
        const payload = await fetchParentReceipts()
        receipts.value = payload.data || []
    } catch (e) {
        console.error(e)
        showToast(tr('No se pudieron cargar los recibos', 'Could not load receipts'), 'error')
    }
}

const changeClub = async () => {
    if (!selectedClubId.value) return
    await load(selectedClubId.value)
}

const openEvent = (ev) => {
    selectedEvent.value = ev
    eventModalOpen.value = true
}

const closeEvent = () => {
    eventModalOpen.value = false
    selectedEvent.value = null
}

const openPasswordModal = () => {
    if (!props.auth_user?.id) return
    changePasswordUserId.value = props.auth_user.id
    showPasswordModal.value = true
}

const createParentAccount = async () => {
    if (!parentSetup.value?.member_id) return

    creatingParentAccount.value = true
    parentAccountError.value = ''
    parentAccountResult.value = null

    try {
        const { data } = await axios.post(route('superadmin.members.parent-account.store', { member: parentSetup.value.member_id }))
        parentAccountResult.value = data
        showToast(data.message || tr('Cuenta creada correctamente', 'Account created successfully'))
    } catch (error) {
        console.error(error)
        parentAccountError.value = error?.response?.data?.message || tr('No se pudo crear la cuenta de padre', 'Could not create the parent account')
    } finally {
        creatingParentAccount.value = false
    }
}

onMounted(() => {
    if (needsParentAccountSetup.value) return

    load()
    loadReceipts()
    if (props.auth_user?.must_change_password && !props.is_superadmin_parent_preview) {
        openPasswordModal()
    }
})
</script>

<template>
    <PathfinderLayout>
        <template #title>{{ tr('Panel de padres', 'Parent Dashboard') }}</template>

        <div v-if="needsParentAccountSetup" class="space-y-4">
            <div class="rounded border border-blue-200 bg-white p-5 shadow-sm">
                <div class="space-y-2">
                    <p class="text-xs font-semibold uppercase tracking-wide text-blue-700">
                        {{ tr('Portal de padre sin cuenta vinculada', 'Parent portal without a linked account') }}
                    </p>
                    <h2 class="text-2xl font-semibold text-gray-900">
                        {{ parentSetup?.parent_name || tr('Padre/Madre registrado', 'Registered parent') }}
                    </h2>
                    <p class="text-sm text-gray-600">
                        {{ tr('Este miembro tiene datos de padre/madre, pero todavia no existe una cuenta de padre vinculada. Crea la cuenta para activar el portal y vincular automaticamente al miembro.', 'This member has parent data, but there is no linked parent account yet. Create the account to activate the portal and automatically link the child.') }}
                    </p>
                </div>

                <div class="mt-5 grid gap-3 text-sm md:grid-cols-2">
                    <div class="rounded bg-gray-50 p-3">
                        <div class="text-xs font-medium text-gray-500">{{ tr('Miembro', 'Member') }}</div>
                        <div class="font-semibold text-gray-900">{{ parentSetup?.member_name || '—' }}</div>
                    </div>
                    <div class="rounded bg-gray-50 p-3">
                        <div class="text-xs font-medium text-gray-500">{{ tr('Club', 'Club') }}</div>
                        <div class="font-semibold text-gray-900">{{ parentSetup?.club_name || '—' }}</div>
                    </div>
                    <div class="rounded bg-gray-50 p-3">
                        <div class="text-xs font-medium text-gray-500">{{ tr('Correo del padre', 'Parent email') }}</div>
                        <div class="font-semibold text-gray-900">{{ parentSetup?.parent_email || '—' }}</div>
                    </div>
                    <div class="rounded bg-gray-50 p-3">
                        <div class="text-xs font-medium text-gray-500">{{ tr('Telefono del padre', 'Parent phone') }}</div>
                        <div class="font-semibold text-gray-900">{{ parentSetup?.parent_phone || '—' }}</div>
                    </div>
                </div>

                <div v-if="!parentSetup?.can_create" class="mt-5 rounded border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800">
                    {{ tr('Para crear la cuenta falta el correo del padre/madre. Actualiza el registro del miembro y vuelve a abrir este portal.', 'The parent email is required before creating the account. Update the member record and open this portal again.') }}
                </div>

                <div v-if="parentAccountError" class="mt-5 rounded border border-red-200 bg-red-50 p-4 text-sm text-red-700">
                    {{ parentAccountError }}
                </div>

                <div v-if="parentAccountResult" class="mt-5 rounded border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-900">
                    <div class="font-semibold">{{ tr('Cuenta vinculada correctamente', 'Account linked successfully') }}</div>
                    <div class="mt-1">{{ tr('Correo:', 'Email:') }} {{ parentAccountResult.email }}</div>
                    <div v-if="parentAccountResult.temporary_password" class="mt-2 rounded bg-white p-3">
                        <div class="text-xs font-medium text-gray-500">{{ tr('Codigo temporal para iniciar sesion', 'Temporary login code') }}</div>
                        <div class="mt-1 font-mono text-lg font-semibold text-gray-900">{{ parentAccountResult.temporary_password }}</div>
                        <div class="mt-1 text-xs text-gray-600">
                            {{ tr('El padre debe iniciar sesion con este codigo como contrasena. En el primer ingreso el sistema pedira crear una nueva contrasena.', 'The parent should log in with this code as the password. On first login the system will ask for a new password.') }}
                        </div>
                    </div>
                    <a
                        :href="parentAccountResult.portal_url"
                        target="_blank"
                        rel="noopener"
                        class="mt-3 inline-flex rounded bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800"
                    >
                        {{ tr('Abrir portal del padre', 'Open parent portal') }}
                    </a>
                </div>

                <button
                    v-if="!parentAccountResult"
                    type="button"
                    class="mt-6 w-full rounded bg-blue-700 px-5 py-4 text-base font-semibold text-white hover:bg-blue-800 disabled:cursor-not-allowed disabled:opacity-60 md:w-auto"
                    :disabled="creatingParentAccount || !parentSetup?.can_create"
                    @click="createParentAccount"
                >
                    {{ creatingParentAccount ? tr('Creando cuenta...', 'Creating account...') : tr('Crear cuenta de padre', 'Create parent account') }}
                </button>
            </div>
        </div>

        <div v-else class="space-y-4">
            <div class="bg-white border rounded shadow-sm p-4">
                <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
                    <div>
                        <h2 class="text-xl font-semibold text-gray-800">{{ tr('Bienvenido,', 'Welcome,') }} {{ props.auth_user?.name }}</h2>
                        <p class="text-gray-600 text-sm mt-1">{{ tr('Consulta los planes de trabajo de los clubes de tus hijos.', 'Review the workplans for your children’s clubs.') }}</p>
                    </div>
                    <button
                        class="px-3 py-2 text-sm bg-blue-600 text-white rounded hover:bg-blue-700"
                        @click="openPasswordModal"
                    >
                        {{ tr('Actualizar contrasena', 'Update password') }}
                    </button>
                </div>
            </div>

            <div class="bg-white border rounded shadow-sm p-4 space-y-3">
                <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
                    <div class="space-y-1">
                        <h3 class="text-lg font-semibold text-gray-800">{{ tr('Plan de trabajo del club', 'Club workplan') }}</h3>
                        <p class="text-sm text-gray-600">{{ tr('Selecciona un club para ver su calendario.', 'Select a club to view its calendar.') }}</p>
                    </div>
                    <div class="flex items-center gap-2">
                        <label class="text-sm text-gray-700">{{ tr('Club', 'Club') }}</label>
                        <select v-model="selectedClubId" class="border rounded px-3 py-1 text-sm" @change="changeClub">
                            <option value="">{{ tr('Selecciona un club', 'Select a club') }}</option>
                            <option v-for="club in clubs" :key="club.id" :value="club.id">{{ club.club_name }}</option>
                        </select>
                    </div>
                </div>

                <div v-if="workplan">
                    <WorkplanCalendar
                        :events="events"
                        :is-read-only="true"
                        :can-add="false"
                        :initial-date="new Date().toISOString().slice(0,10)"
                        :pdf-href="workplanPdfHref"
                        :ics-href="workplanIcsHref"
                        @edit="openEvent"
                    />
                </div>
                <div v-else class="text-sm text-gray-600">{{ tr('No se encontró plan de trabajo para tus clubes.', 'No workplan was found for your clubs.') }}</div>
            </div>

            <div class="bg-white border rounded shadow-sm p-4 space-y-3">
                <div class="space-y-1">
                    <h3 class="text-lg font-semibold text-gray-800">{{ tr('Mis recibos', 'My receipts') }}</h3>
                    <p class="text-sm text-gray-600">{{ tr('Recibos emitidos por pagos de tus hijos.', 'Receipts issued for your children’s payments.') }}</p>
                </div>
                <div v-if="!receipts.length" class="text-sm text-gray-600">{{ tr('Aun no hay recibos disponibles.', 'There are no receipts available yet.') }}</div>
                <div v-else class="space-y-2">
                    <div v-for="receipt in receipts" :key="receipt.id" class="flex flex-col gap-2 rounded border border-gray-200 p-3 md:flex-row md:items-center md:justify-between">
                        <div class="text-sm">
                            <div class="font-semibold text-gray-900">{{ receipt.receipt_number }}</div>
                            <div class="text-gray-600">
                                {{ receipt.member_name || receipt.staff_name || '—' }} • {{ receipt.concept_name || '—' }}
                            </div>
                            <div class="text-xs text-gray-500">
                                {{ receipt.payment_date || '—' }} • ${{ Number(receipt.amount_paid || 0).toFixed(2) }} • {{ receipt.club_name || '—' }}
                            </div>
                        </div>
                        <a :href="receipt.download_url" target="_blank" rel="noopener" class="text-sm font-medium text-blue-600 hover:underline">
                            {{ tr('Descargar recibo', 'Download receipt') }}
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <div v-if="eventModalOpen && selectedEvent" class="fixed inset-0 bg-black/40 flex items-center justify-center z-50">
            <div class="bg-white rounded-lg shadow-lg w-full max-w-3xl p-6 space-y-4 overflow-y-auto max-h-[90vh]">
                <div class="flex items-center justify-between">
                    <div>
                        <h4 class="text-lg font-semibold text-gray-900">{{ selectedEvent.title }}</h4>
                        <p class="text-sm text-gray-600">
                            {{ selectedEvent.meeting_type }} • {{ cleanDate(selectedEvent.date) }}
                            <span v-if="selectedEvent.start_time || selectedEvent.end_time" class="ml-2 text-xs text-gray-500">
                                {{ cleanTime(selectedEvent.start_time) }}
                                <template v-if="selectedEvent.end_time"> - {{ cleanTime(selectedEvent.end_time) }}</template>
                            </span>
                        </p>
                    </div>
                    <button class="text-gray-500" @click="closeEvent">✕</button>
                </div>
                <div class="space-y-2 text-sm text-gray-700">
                    <div><span class="font-semibold">{{ tr('Descripción:', 'Description:') }}</span> {{ selectedEvent.description || '—' }}</div>
                    <div><span class="font-semibold">{{ tr('Ubicación:', 'Location:') }}</span> {{ selectedEvent.location || '—' }}</div>
                </div>
                <div v-if="selectedEvent.classPlans?.length" class="border-t pt-3">
                    <h5 class="font-semibold text-gray-800 text-sm mb-2">{{ tr('Planes de clase para tus hijos', 'Class plans for your children') }}</h5>
                    <div class="space-y-2">
                        <div v-for="plan in selectedEvent.classPlans" :key="plan.id" class="border rounded p-3 bg-gray-50">
                            <div class="flex items-center justify-between text-sm">
                                <div class="font-semibold">{{ plan.title || tr('Plan de clase', 'Class plan') }}</div>
                                <span class="text-xs capitalize text-gray-600">{{ plan.type || tr('plan', 'plan') }}</span>
                            </div>
                            <div class="text-xs text-gray-700 mt-1">{{ plan.description || '—' }}</div>
                            <div class="text-[11px] text-gray-600 mt-2">
                                {{ tr('Clase:', 'Class:') }} {{ plan.class?.class_name || '—' }} • {{ tr('Personal:', 'Staff:') }} {{ plan.staff?.user?.name || plan.staff?.name || '—' }}
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <UpdatePasswordModal
            v-if="showPasswordModal && changePasswordUserId"
            :show="showPasswordModal"
            :user-id="changePasswordUserId"
            :force="Boolean(props.auth_user?.must_change_password && !props.is_superadmin_parent_preview)"
            @close="showPasswordModal = false"
            @updated="showToast(tr('Contrasena actualizada correctamente', 'Password updated successfully'))"
        />
    </PathfinderLayout>
</template>
