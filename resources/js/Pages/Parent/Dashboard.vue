<script setup>
import { ref, onMounted, computed } from 'vue'
import PathfinderLayout from '@/Layouts/PathfinderLayout.vue'
import WorkplanCalendar from '@/Components/WorkplanCalendar.vue'
import { fetchParentWorkplan, fetchParentReceipts } from '@/Services/api'
import { useGeneral } from '@/Composables/useGeneral'
import UpdatePasswordModal from "@/Components/ChangePassword.vue";
import { useLocale } from '@/Composables/useLocale'

const props = defineProps({
    auth_user: Object
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

onMounted(() => {
    load()
    loadReceipts()
})
</script>

<template>
    <PathfinderLayout>
        <template #title>{{ tr('Panel de padres', 'Parent Dashboard') }}</template>

        <div class="space-y-4">
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
            @close="showPasswordModal = false"
            @updated="showToast(tr('Contrasena actualizada correctamente', 'Password updated successfully'))"
        />
    </PathfinderLayout>
</template>
