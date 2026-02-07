<script setup>
import { ref, onMounted, computed } from 'vue'
import PathfinderLayout from '@/Layouts/PathfinderLayout.vue'
import WorkplanCalendar from '@/Components/WorkplanCalendar.vue'
import { fetchParentWorkplan } from '@/Services/api'
import { useGeneral } from '@/Composables/useGeneral'
import UpdatePasswordModal from "@/Components/ChangePassword.vue";

const props = defineProps({
    auth_user: Object
})

const { showToast } = useGeneral()

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
        events.value = wp?.events || []
        memberships.value = m || []
    } catch (e) {
        console.error(e)
        showToast('No se pudo cargar el plan de trabajo', 'error')
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
})
</script>

<template>
    <PathfinderLayout>
        <template #title>Panel de padres</template>

        <div class="space-y-4">
            <div class="bg-white border rounded shadow-sm p-4">
                <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
                    <div>
                        <h2 class="text-xl font-semibold text-gray-800">Bienvenido, {{ props.auth_user?.name }}</h2>
                        <p class="text-gray-600 text-sm mt-1">Consulta los planes de trabajo de los clubes de tus hijos.</p>
                    </div>
                    <button
                        class="px-3 py-2 text-sm bg-blue-600 text-white rounded hover:bg-blue-700"
                        @click="openPasswordModal"
                    >
                        Actualizar contrasena
                    </button>
                </div>
            </div>

            <div class="bg-white border rounded shadow-sm p-4 space-y-3">
                <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
                    <div class="space-y-1">
                        <h3 class="text-lg font-semibold text-gray-800">Plan de trabajo del club</h3>
                        <p class="text-sm text-gray-600">Selecciona un club para ver su calendario.</p>
                    </div>
                    <div class="flex items-center gap-2">
                        <label class="text-sm text-gray-700">Club</label>
                        <select v-model="selectedClubId" class="border rounded px-3 py-1 text-sm" @change="changeClub">
                            <option value="">Selecciona un club</option>
                            <option v-for="club in clubs" :key="club.id" :value="club.id">{{ club.club_name }}</option>
                        </select>
                    </div>
                </div>

                <div v-if="workplan">
                    <WorkplanCalendar
                        :events="events"
                        :is-read-only="true"
                        :can-add="false"
                        :initial-date="workplan?.start_date || new Date().toISOString().slice(0,10)"
                        :pdf-href="workplanPdfHref"
                        :ics-href="workplanIcsHref"
                        @edit="openEvent"
                    />
                </div>
                <div v-else class="text-sm text-gray-600">No se encontró plan de trabajo para tus clubes.</div>
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
                    <div><span class="font-semibold">Descripción:</span> {{ selectedEvent.description || '—' }}</div>
                    <div><span class="font-semibold">Ubicación:</span> {{ selectedEvent.location || '—' }}</div>
                </div>
                <div v-if="selectedEvent.classPlans?.length" class="border-t pt-3">
                    <h5 class="font-semibold text-gray-800 text-sm mb-2">Planes de clase para tus hijos</h5>
                    <div class="space-y-2">
                        <div v-for="plan in selectedEvent.classPlans" :key="plan.id" class="border rounded p-3 bg-gray-50">
                            <div class="flex items-center justify-between text-sm">
                                <div class="font-semibold">{{ plan.title || 'Plan de clase' }}</div>
                                <span class="text-xs capitalize text-gray-600">{{ plan.type || 'plan' }}</span>
                            </div>
                            <div class="text-xs text-gray-700 mt-1">{{ plan.description || '—' }}</div>
                            <div class="text-[11px] text-gray-600 mt-2">
                                Clase: {{ plan.class?.class_name || '—' }} • Personal: {{ plan.staff?.user?.name || plan.staff?.name || '—' }}
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
            @updated="showToast('Contrasena actualizada correctamente')"
        />
    </PathfinderLayout>
</template>
