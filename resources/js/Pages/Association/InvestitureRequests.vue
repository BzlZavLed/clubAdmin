<script setup>
import PathfinderLayout from '@/Layouts/PathfinderLayout.vue'
import Modal from '@/Components/Modal.vue'
import { router } from '@inertiajs/vue3'
import { computed, ref } from 'vue'
import { useGeneral } from '@/Composables/useGeneral'
import { useLocale } from '@/Composables/useLocale'

const props = defineProps({
    association: { type: Object, required: true },
    union: { type: Object, default: null },
    activeRequests: { type: Array, default: () => [] },
    historyRequests: { type: Array, default: () => [] },
    historyPagination: { type: Object, default: () => ({}) },
})

const { showToast } = useGeneral()
const { tr } = useLocale()
const selectedRequest = ref(null)
const authorizationRequest = ref(null)
const dateChangeRequest = ref(null)
const assigning = ref(false)
const authorizing = ref(false)
const requestingNewDate = ref(false)
const authorizationForm = ref({
    authorization_person_name: '',
    ceremony_representative_name: '',
    ceremony_representative_email: '',
    ceremony_representative_phone: '',
})
const dateChangeReason = ref('')

const pendingRequests = computed(() => props.activeRequests.filter((request) => request.status === 'submitted' || request.status === 'returned'))
const activeWorkflowRequests = computed(() => props.activeRequests.filter((request) => [
    'assigned',
    'in_review',
    'completed',
    'date_change_requested',
    'authorized',
].includes(request.status)))

const statusLabel = (status) => ({
    submitted: tr('Pendiente de asignación', 'Pending assignment'),
    assigned: tr('Asignada', 'Assigned'),
    in_review: tr('En revisión', 'In review'),
    completed: tr('Completada', 'Completed'),
    authorized: tr('Autorizada', 'Authorized'),
    date_change_requested: tr('Nueva fecha solicitada', 'New date requested'),
    returned: tr('Devuelta', 'Returned'),
}[status] || status)

const statusClass = (status) => ({
    submitted: 'bg-amber-50 text-amber-800 ring-amber-200',
    assigned: 'bg-blue-50 text-blue-800 ring-blue-200',
    in_review: 'bg-indigo-50 text-indigo-800 ring-indigo-200',
    completed: 'bg-emerald-50 text-emerald-800 ring-emerald-200',
    authorized: 'bg-green-100 text-green-900 ring-green-200',
    date_change_requested: 'bg-amber-50 text-amber-800 ring-amber-200',
    returned: 'bg-rose-50 text-rose-800 ring-rose-200',
}[status] || 'bg-gray-50 text-gray-700 ring-gray-200')

const progressText = (request) => {
    if (!request.requirements_count) return tr('Sin requisitos registrados', 'No requirements registered')
    return `${request.completed_requirements_count || 0}/${request.requirements_count} ${tr('requisitos cargados', 'requirements loaded')}`
}

const goToHistoryPage = (page) => {
    if (!page || page === props.historyPagination?.current_page) return

    router.get(route('association.investiture-requests'), {
        history_page: page,
    }, {
        preserveScroll: true,
        preserveState: true,
        replace: true,
    })
}

const openAssignModal = (request) => {
    selectedRequest.value = request
}

const closeAssignModal = () => {
    if (assigning.value) return
    selectedRequest.value = null
}

const assignDistrictPastor = () => {
    if (!selectedRequest.value) return

    assigning.value = true
    router.post(route('association.investiture-requests.assign-district-pastor', selectedRequest.value.id), {}, {
        preserveScroll: true,
        onSuccess: () => {
            showToast(tr('Solicitud asignada al pastor distrital.', 'Request assigned to the district pastor.'), 'success')
            selectedRequest.value = null
        },
        onError: (errors) => {
            showToast(Object.values(errors || {})[0] || tr('No se pudo asignar la solicitud.', 'Could not assign the request.'), 'error')
        },
        onFinish: () => {
            assigning.value = false
        },
    })
}

const openAuthorizeModal = (request) => {
    authorizationRequest.value = request
    authorizationForm.value = {
        authorization_person_name: '',
        ceremony_representative_name: '',
        ceremony_representative_email: '',
        ceremony_representative_phone: '',
    }
}

const closeAuthorizeModal = () => {
    if (authorizing.value) return
    authorizationRequest.value = null
}

const authorizeRequest = () => {
    if (!authorizationRequest.value) return

    authorizing.value = true
    router.post(route('association.investiture-requests.authorize', authorizationRequest.value.id), authorizationForm.value, {
        preserveScroll: true,
        onSuccess: () => {
            showToast(tr('Investidura autorizada por la asociación.', 'Investiture authorized by the association.'), 'success')
            authorizationRequest.value = null
        },
        onError: (errors) => {
            showToast(Object.values(errors || {})[0] || tr('No se pudo autorizar la investidura.', 'Could not authorize the investiture.'), 'error')
        },
        onFinish: () => {
            authorizing.value = false
        },
    })
}

const openDateChangeModal = (request) => {
    dateChangeRequest.value = request
    dateChangeReason.value = ''
}

const closeDateChangeModal = () => {
    if (requestingNewDate.value) return
    dateChangeRequest.value = null
}

const requestNewDate = () => {
    if (!dateChangeRequest.value) return

    requestingNewDate.value = true
    router.post(route('association.investiture-requests.request-new-date', dateChangeRequest.value.id), {
        date_change_reason: dateChangeReason.value,
    }, {
        preserveScroll: true,
        onSuccess: () => {
            showToast(tr('Se solicitó al club proponer una nueva fecha.', 'The club was asked to propose a new date.'), 'success')
            dateChangeRequest.value = null
        },
        onError: (errors) => {
            showToast(Object.values(errors || {})[0] || tr('No se pudo solicitar nueva fecha.', 'Could not request a new date.'), 'error')
        },
        onFinish: () => {
            requestingNewDate.value = false
        },
    })
}
</script>

<template>
    <PathfinderLayout>
        <template #title>{{ tr('Solicitudes de investidura', 'Investiture requests') }}</template>

        <div class="space-y-6">
            <section class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
                <div class="flex flex-col gap-4 md:flex-row md:items-start md:justify-between">
                    <div>
                        <p class="text-sm font-semibold uppercase tracking-wide text-gray-400">{{ tr('Asociación', 'Association') }}</p>
                        <h1 class="mt-1 text-2xl font-semibold text-gray-900">{{ association.name }}</h1>
                        <p class="mt-2 text-sm text-gray-600">
                            {{ tr('Recibe solicitudes de clubes y asigna formalmente el pastor distrital para que el distrito pueda revisarlas.', 'Receive club requests and formally assign the district pastor so the district can review them.') }}
                        </p>
                    </div>
                    <div class="rounded-xl border border-gray-200 bg-gray-50 px-4 py-3 text-sm text-gray-700">
                        <p class="font-semibold text-gray-900">{{ union?.name || tr('Unión', 'Union') }}</p>
                        <p>{{ tr('Sistema', 'System') }}: {{ union?.evaluation_system || 'honors' }}</p>
                    </div>
                </div>
            </section>

            <section class="rounded-2xl border border-amber-200 bg-amber-50 p-5 shadow-sm">
                <div class="flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h2 class="text-lg font-semibold text-amber-950">{{ tr('Pendientes de asignación', 'Pending assignment') }}</h2>
                        <p class="text-sm text-amber-800">{{ tr('Estas solicitudes todavía no son visibles para el distrito.', 'These requests are not visible to the district yet.') }}</p>
                    </div>
                    <span class="text-sm font-semibold text-amber-900">{{ pendingRequests.length }} {{ tr('pendientes', 'pending') }}</span>
                </div>

                <div class="mt-4 grid gap-4 xl:grid-cols-2">
                    <article
                        v-for="request in pendingRequests"
                        :key="request.id"
                        class="rounded-2xl border border-amber-200 bg-white p-5 shadow-sm"
                    >
                        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                            <div>
                                <div class="flex flex-wrap items-center gap-2">
                                    <h3 class="text-base font-semibold text-gray-900">{{ tr('Solicitud', 'Request') }} #{{ request.id }}</h3>
                                    <span class="rounded-full px-2.5 py-1 text-xs font-semibold ring-1" :class="statusClass(request.status)">
                                        {{ statusLabel(request.status) }}
                                    </span>
                                </div>
                                <p class="mt-1 text-sm text-gray-600">{{ request.club?.club_name || tr('Club', 'Club') }} · {{ request.club?.church_name || tr('Iglesia', 'Church') }}</p>
                                <p class="mt-1 text-sm text-gray-600">{{ tr('Distrito', 'District') }}: {{ request.district?.name || '—' }}</p>
                                <p class="mt-2 text-xs uppercase tracking-wide text-gray-400">
                                    {{ tr('Año', 'Year') }} {{ request.carpeta_year }} · {{ request.club_type }} · {{ request.members_count }} {{ tr('miembros', 'members') }}
                                </p>
                            </div>
                            <button
                                type="button"
                                class="rounded-lg bg-gray-900 px-4 py-2 text-sm font-semibold text-white hover:bg-gray-800 disabled:cursor-not-allowed disabled:bg-gray-300"
                                :disabled="!request.district?.pastor_name && !request.district?.pastor_email"
                                @click="openAssignModal(request)"
                            >
                                {{ tr('Asignar pastor', 'Assign pastor') }}
                            </button>
                        </div>
                        <div class="mt-4 rounded-xl border border-gray-100 bg-gray-50 p-3 text-sm text-gray-700">
                            <p>{{ progressText(request) }}</p>
                            <p v-if="request.director_notes" class="mt-2 text-gray-600">{{ tr('Notas del director', 'Director notes') }}: {{ request.director_notes }}</p>
                            <p v-if="!request.district?.pastor_name && !request.district?.pastor_email" class="mt-2 text-rose-700">
                                {{ tr('Este distrito no tiene pastor configurado.', 'This district has no pastor configured.') }}
                            </p>
                        </div>
                    </article>

                    <p v-if="!pendingRequests.length" class="rounded-xl border border-dashed border-amber-300 bg-white px-4 py-6 text-sm text-amber-800">
                        {{ tr('No hay solicitudes pendientes de asignación.', 'There are no requests pending assignment.') }}
                    </p>
                </div>
            </section>

            <section class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
                <div class="flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h2 class="text-lg font-semibold text-gray-900">{{ tr('Solicitudes en proceso', 'Requests in process') }}</h2>
                        <p class="text-sm text-gray-500">{{ tr('Solicitudes ya asignadas que siguen en revisión o pendientes de autorización.', 'Assigned requests that are still in review or pending authorization.') }}</p>
                    </div>
                    <span class="text-sm font-semibold text-gray-700">{{ activeWorkflowRequests.length }} {{ tr('activas', 'active') }}</span>
                </div>
                <div class="mt-4 space-y-3">
                    <article
                        v-for="request in activeWorkflowRequests"
                        :key="request.id"
                        class="rounded-xl border border-gray-200 bg-gray-50 p-4"
                    >
                        <div class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
                            <div>
                                <div class="flex flex-wrap items-center gap-2">
                                    <h3 class="font-semibold text-gray-900">#{{ request.id }} · {{ request.club?.club_name || tr('Club', 'Club') }}</h3>
                                    <span class="rounded-full px-2.5 py-1 text-xs font-semibold ring-1" :class="statusClass(request.status)">
                                        {{ statusLabel(request.status) }}
                                    </span>
                                </div>
                                <p class="mt-1 text-sm text-gray-600">
                                    {{ tr('Distrito', 'District') }}: {{ request.district?.name || '—' }} · {{ tr('Evaluador', 'Evaluator') }}:
                                    {{ request.assigned_evaluator_name || request.assigned_evaluator_email || '—' }}
                                </p>
                                <p class="mt-1 text-sm text-gray-600">
                                    {{ tr('Fecha tentativa', 'Tentative date') }}: {{ request.tentative_investiture_date || '—' }}
                                    <template v-if="request.approved_investiture_date">
                                        · {{ tr('Fecha autorizada', 'Authorized date') }}: {{ request.approved_investiture_date }}
                                    </template>
                                </p>
                                <p v-if="request.date_change_reason" class="mt-2 rounded border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800">
                                    {{ tr('Nueva fecha solicitada', 'New date requested') }}: {{ request.date_change_reason }}
                                </p>
                            </div>
                            <div class="flex flex-col gap-2 md:items-end">
                                <p class="text-sm text-gray-500">{{ tr('Asignada', 'Assigned') }}: {{ request.assigned_at || '—' }}</p>
                                <p v-if="request.completed_at" class="text-sm text-gray-500">{{ tr('Evaluada', 'Evaluated') }}: {{ request.completed_at }}</p>
                                <p v-if="request.authorized_at" class="text-sm font-semibold text-green-700">{{ tr('Autorizada', 'Authorized') }}: {{ request.authorized_at }}</p>
                                <p v-if="request.ceremony_representative_name" class="text-sm text-gray-600">
                                    {{ tr('Representante', 'Representative') }}: {{ request.ceremony_representative_name }}
                                    <template v-if="request.ceremony_representative_email"> · {{ request.ceremony_representative_email }}</template>
                                    <template v-if="request.ceremony_representative_phone"> · {{ request.ceremony_representative_phone }}</template>
                                </p>
                                <button
                                    v-if="request.status === 'completed'"
                                    type="button"
                                    class="rounded-lg bg-green-700 px-4 py-2 text-sm font-semibold text-white hover:bg-green-800"
                                    @click="openAuthorizeModal(request)"
                                >
                                    {{ tr('Autorizar investidura', 'Authorize investiture') }}
                                </button>
                                <button
                                    v-if="request.status === 'completed'"
                                    type="button"
                                    class="rounded-lg border border-amber-300 bg-amber-50 px-4 py-2 text-sm font-semibold text-amber-800 hover:bg-amber-100"
                                    @click="openDateChangeModal(request)"
                                >
                                    {{ tr('Solicitar nueva fecha', 'Request new date') }}
                                </button>
                            </div>
                        </div>
                    </article>

                    <p v-if="!activeWorkflowRequests.length" class="rounded-xl border border-dashed border-gray-300 px-4 py-6 text-sm text-gray-500">
                        {{ tr('No hay solicitudes activas en este momento.', 'There are no active requests right now.') }}
                    </p>
                </div>
            </section>

            <section class="rounded-2xl border border-emerald-200 bg-emerald-50 p-5 shadow-sm">
                <div class="flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h2 class="text-lg font-semibold text-emerald-950">{{ tr('Historial de investiduras completadas', 'Completed investiture history') }}</h2>
                        <p class="text-sm text-emerald-800">{{ tr('Solicitudes ya autorizadas cuya ceremonia fue marcada como completada por el director del club.', 'Authorized requests whose ceremony was marked completed by the club director.') }}</p>
                    </div>
                    <span class="text-sm font-semibold text-emerald-900">{{ historyPagination?.total || 0 }} {{ tr('en historial', 'in history') }}</span>
                </div>

                <div class="mt-4 overflow-hidden rounded-xl border border-emerald-200 bg-white">
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-emerald-100 text-sm">
                            <thead class="bg-emerald-50 text-left text-xs uppercase tracking-wide text-emerald-900">
                                <tr>
                                    <th class="px-4 py-3 font-semibold">{{ tr('Solicitud', 'Request') }}</th>
                                    <th class="px-4 py-3 font-semibold">{{ tr('Club', 'Club') }}</th>
                                    <th class="px-4 py-3 font-semibold">{{ tr('Distrito', 'District') }}</th>
                                    <th class="px-4 py-3 font-semibold">{{ tr('Fecha de investidura', 'Investiture date') }}</th>
                                    <th class="px-4 py-3 font-semibold">{{ tr('Representante', 'Representative') }}</th>
                                    <th class="px-4 py-3 font-semibold">{{ tr('Completada', 'Completed') }}</th>
                                    <th class="px-4 py-3 font-semibold">{{ tr('Progreso', 'Progress') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                <tr v-for="request in historyRequests" :key="`history-${request.id}`" class="text-gray-700">
                                    <td class="px-4 py-3">
                                        <div class="font-semibold text-gray-900">#{{ request.id }}</div>
                                        <div class="mt-1 text-xs text-gray-500">{{ tr('Año', 'Year') }} {{ request.carpeta_year }} · {{ request.club_type }}</div>
                                    </td>
                                    <td class="px-4 py-3">
                                        <div class="font-medium text-gray-900">{{ request.club?.club_name || '—' }}</div>
                                        <div class="mt-1 text-xs text-gray-500">{{ request.club?.church_name || '—' }}</div>
                                    </td>
                                    <td class="px-4 py-3">{{ request.district?.name || '—' }}</td>
                                    <td class="px-4 py-3">{{ request.approved_investiture_date || request.tentative_investiture_date || '—' }}</td>
                                    <td class="px-4 py-3">
                                        <div class="font-medium text-gray-900">{{ request.ceremony_representative_name || '—' }}</div>
                                        <div v-if="request.ceremony_representative_email" class="mt-1 text-xs text-gray-500">{{ request.ceremony_representative_email }}</div>
                                    </td>
                                    <td class="px-4 py-3">
                                        <div class="font-medium text-emerald-700">{{ request.ceremony_completed_at || '—' }}</div>
                                        <div v-if="request.authorized_at" class="mt-1 text-xs text-gray-500">{{ tr('Autorizada', 'Authorized') }}: {{ request.authorized_at }}</div>
                                    </td>
                                    <td class="px-4 py-3">{{ progressText(request) }}</td>
                                </tr>
                                <tr v-if="!historyRequests.length">
                                    <td colspan="7" class="px-4 py-6 text-sm text-emerald-900">
                                        {{ tr('Todavía no hay solicitudes completadas en el historial.', 'There are no completed requests in the history yet.') }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div v-if="historyPagination?.last_page > 1" class="flex flex-col gap-3 border-t border-emerald-100 bg-emerald-50 px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
                        <p class="text-sm text-emerald-900">
                            {{ tr('Mostrando', 'Showing') }} {{ historyPagination.from || 0 }}-{{ historyPagination.to || 0 }} {{ tr('de', 'of') }} {{ historyPagination.total || 0 }}
                        </p>
                        <div class="flex items-center gap-2">
                            <button
                                type="button"
                                class="rounded-lg border border-emerald-300 bg-white px-3 py-2 text-sm font-semibold text-emerald-900 hover:bg-emerald-100 disabled:cursor-not-allowed disabled:opacity-50"
                                :disabled="historyPagination.current_page <= 1"
                                @click="goToHistoryPage(historyPagination.current_page - 1)"
                            >
                                {{ tr('Anterior', 'Previous') }}
                            </button>
                            <span class="text-sm font-medium text-emerald-900">
                                {{ tr('Página', 'Page') }} {{ historyPagination.current_page }} {{ tr('de', 'of') }} {{ historyPagination.last_page }}
                            </span>
                            <button
                                type="button"
                                class="rounded-lg border border-emerald-300 bg-white px-3 py-2 text-sm font-semibold text-emerald-900 hover:bg-emerald-100 disabled:cursor-not-allowed disabled:opacity-50"
                                :disabled="historyPagination.current_page >= historyPagination.last_page"
                                @click="goToHistoryPage(historyPagination.current_page + 1)"
                            >
                                {{ tr('Siguiente', 'Next') }}
                            </button>
                        </div>
                    </div>
                </div>
            </section>
        </div>

        <Modal :show="!!selectedRequest" max-width="lg" @close="closeAssignModal">
            <div class="p-6">
                <h2 class="text-lg font-semibold text-gray-900">{{ tr('Asignar solicitud al pastor distrital', 'Assign request to district pastor') }}</h2>
                <p class="mt-2 text-sm text-gray-600">
                    {{ tr('Al confirmar, el distrito podrá ver y evaluar la solicitud', 'When confirmed, the district will be able to view and evaluate request') }} #{{ selectedRequest?.id }}.
                </p>

                <div class="mt-4 rounded-xl border border-gray-200 bg-gray-50 p-4 text-sm text-gray-700">
                    <p><span class="font-semibold">{{ tr('Club:', 'Club:') }}</span> {{ selectedRequest?.club?.club_name || '—' }}</p>
                    <p><span class="font-semibold">{{ tr('Distrito:', 'District:') }}</span> {{ selectedRequest?.district?.name || '—' }}</p>
                    <p><span class="font-semibold">{{ tr('Pastor:', 'Pastor:') }}</span> {{ selectedRequest?.district?.pastor_name || selectedRequest?.district?.pastor_email || '—' }}</p>
                </div>

                <div class="mt-6 flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                    <button type="button" class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50" @click="closeAssignModal">
                        {{ tr('Cancelar', 'Cancel') }}
                    </button>
                    <button
                        type="button"
                        class="rounded-lg bg-gray-900 px-4 py-2 text-sm font-semibold text-white hover:bg-gray-800 disabled:cursor-not-allowed disabled:bg-gray-300"
                        :disabled="assigning"
                        @click="assignDistrictPastor"
                    >
                        {{ assigning ? tr('Asignando...', 'Assigning...') : tr('Confirmar asignación', 'Confirm assignment') }}
                    </button>
                </div>
            </div>
        </Modal>

        <Modal :show="!!authorizationRequest" max-width="lg" @close="closeAuthorizeModal">
            <div class="p-6">
                <h2 class="text-lg font-semibold text-gray-900">{{ tr('Autorizar investidura', 'Authorize investiture') }}</h2>
                <p class="mt-2 text-sm text-gray-600">
                    {{ tr('Confirme que la asociación autoriza la solicitud', 'Confirm that the association authorizes request') }} #{{ authorizationRequest?.id }} {{ tr('después de la evaluación completada por el distrito.', 'after the evaluation completed by the district.') }}
                </p>

                <div class="mt-4 rounded-xl border border-gray-200 bg-gray-50 p-4 text-sm text-gray-700">
                    <p><span class="font-semibold">{{ tr('Club:', 'Club:') }}</span> {{ authorizationRequest?.club?.club_name || '—' }}</p>
                    <p><span class="font-semibold">{{ tr('Iglesia:', 'Church:') }}</span> {{ authorizationRequest?.club?.church_name || '—' }}</p>
                    <p><span class="font-semibold">{{ tr('Distrito:', 'District:') }}</span> {{ authorizationRequest?.district?.name || '—' }}</p>
                    <p><span class="font-semibold">{{ tr('Evaluador:', 'Evaluator:') }}</span> {{ authorizationRequest?.assigned_evaluator_name || authorizationRequest?.assigned_evaluator_email || '—' }}</p>
                    <p><span class="font-semibold">{{ tr('Fecha propuesta:', 'Proposed date:') }}</span> {{ authorizationRequest?.tentative_investiture_date || '—' }}</p>
                </div>

                <div class="mt-4 space-y-3">
                    <label class="block text-sm font-medium text-gray-700">
                        {{ tr('Nombre de quien autoriza', 'Name of authorizing person') }}
                        <input v-model="authorizationForm.authorization_person_name" type="text" class="mt-1 w-full rounded-md border-gray-300 text-sm">
                    </label>
                    <label class="block text-sm font-medium text-gray-700">
                        {{ tr('Representante que estará presente en la ceremonia', 'Representative who will be present at the ceremony') }}
                        <input v-model="authorizationForm.ceremony_representative_name" type="text" class="mt-1 w-full rounded-md border-gray-300 text-sm">
                    </label>
                    <label class="block text-sm font-medium text-gray-700">
                        {{ tr('Correo del representante', 'Representative email') }} <span class="font-normal text-gray-500">{{ tr('(opcional)', '(optional)') }}</span>
                        <input v-model="authorizationForm.ceremony_representative_email" type="email" class="mt-1 w-full rounded-md border-gray-300 text-sm">
                    </label>
                    <label class="block text-sm font-medium text-gray-700">
                        {{ tr('Teléfono del representante', 'Representative phone') }} <span class="font-normal text-gray-500">{{ tr('(opcional)', '(optional)') }}</span>
                        <input v-model="authorizationForm.ceremony_representative_phone" type="text" class="mt-1 w-full rounded-md border-gray-300 text-sm">
                    </label>
                </div>

                <div class="mt-6 flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                    <button type="button" class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50" @click="closeAuthorizeModal">
                        {{ tr('Cancelar', 'Cancel') }}
                    </button>
                    <button
                        type="button"
                        class="rounded-lg bg-green-700 px-4 py-2 text-sm font-semibold text-white hover:bg-green-800 disabled:cursor-not-allowed disabled:bg-gray-300"
                        :disabled="authorizing"
                        @click="authorizeRequest"
                    >
                        {{ authorizing ? tr('Autorizando...', 'Authorizing...') : tr('Confirmar autorización', 'Confirm authorization') }}
                    </button>
                </div>
            </div>
        </Modal>

        <Modal :show="!!dateChangeRequest" max-width="lg" @close="closeDateChangeModal">
            <div class="p-6">
                <h2 class="text-lg font-semibold text-gray-900">{{ tr('Solicitar nueva fecha', 'Request new date') }}</h2>
                <p class="mt-2 text-sm text-gray-600">
                    {{ tr('Use esta opción si la fecha tentativa no funciona para la asociación o para el representante que debe asistir.', 'Use this option if the tentative date does not work for the association or the representative who must attend.') }}
                </p>

                <div class="mt-4 rounded-xl border border-gray-200 bg-gray-50 p-4 text-sm text-gray-700">
                    <p><span class="font-semibold">{{ tr('Club:', 'Club:') }}</span> {{ dateChangeRequest?.club?.club_name || '—' }}</p>
                    <p><span class="font-semibold">{{ tr('Fecha tentativa actual:', 'Current tentative date:') }}</span> {{ dateChangeRequest?.tentative_investiture_date || '—' }}</p>
                </div>

                <label class="mt-4 block text-sm font-medium text-gray-700">
                    {{ tr('Motivo o instrucciones para el club', 'Reason or instructions for the club') }}
                    <textarea v-model="dateChangeReason" rows="4" class="mt-1 w-full rounded-md border-gray-300 text-sm" />
                </label>

                <div class="mt-6 flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                    <button type="button" class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50" @click="closeDateChangeModal">
                        {{ tr('Cancelar', 'Cancel') }}
                    </button>
                    <button
                        type="button"
                        class="rounded-lg bg-amber-700 px-4 py-2 text-sm font-semibold text-white hover:bg-amber-800 disabled:cursor-not-allowed disabled:bg-gray-300"
                        :disabled="requestingNewDate"
                        @click="requestNewDate"
                    >
                        {{ requestingNewDate ? tr('Enviando...', 'Sending...') : tr('Solicitar nueva fecha', 'Request new date') }}
                    </button>
                </div>
            </div>
        </Modal>
    </PathfinderLayout>
</template>
