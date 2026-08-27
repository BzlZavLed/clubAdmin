<script setup>
import { ref } from 'vue'
import axios from 'axios'
import PathfinderLayout from '@/Layouts/PathfinderLayout.vue'
import { fetchInviteCode, regenerateInviteCode } from '@/Services/api'
import { useGeneral } from '@/Composables/useGeneral'
import { useLocale } from '@/Composables/useLocale'

const { showToast } = useGeneral()
const { tr } = useLocale()

const props = defineProps({
  club_hierarchy: {
    type: Object,
    default: null,
  },
  enrollment_confirmation_requests: {
    type: Object,
    default: () => ({ total: 0, parents: [], director_activated_parents: [] }),
  },
})

const inviteModalOpen = ref(false)
const inviteCode = ref(null)
const inviteLoading = ref(false)
const confirmationRequests = ref(props.enrollment_confirmation_requests)
const confirmingRequest = ref(null)
const activationCandidate = ref(null)
const childLinkDecision = ref(null)
const childLinkDecisionNote = ref('')

function labelOrMissing(value) {
  return value || tr('No registrado', 'Not registered')
}

async function openInviteModal() {
  inviteModalOpen.value = true
  inviteLoading.value = true
  try {
    const data = await fetchInviteCode()
    inviteCode.value = data.code
  } catch (e) {
    console.error(e)
    showToast(tr('No se pudo cargar el código de invitación', 'Could not load the invitation code'), 'error')
  } finally {
    inviteLoading.value = false
  }
}

async function regenerateCode() {
  inviteLoading.value = true
  try {
    const data = await regenerateInviteCode()
    inviteCode.value = data.code
    showToast(tr('Código de invitación regenerado', 'Invitation code regenerated'))
  } catch (e) {
    console.error(e)
    showToast(tr('No se pudo regenerar el código', 'Could not regenerate the code'), 'error')
  } finally {
    inviteLoading.value = false
  }
}

function requestDirectorActivation(parent) {
  activationCandidate.value = parent
}

async function confirmSecureEnrollment() {
  const parent = activationCandidate.value
  if (!parent) return
  const key = `parent-${parent.id}`
  confirmingRequest.value = key
  try {
    const { data } = await axios.post(route('club.enrollment-confirmations.parents.confirm', { user: parent.id }))
    confirmationRequests.value = data.data
    activationCandidate.value = null
    showToast(tr('Cuenta de padre activada por el director', 'Parent account activated by the director'))
  } catch (error) {
    showToast(error?.response?.data?.message || tr('No se pudo confirmar la solicitud', 'Could not confirm the request'), 'error')
  } finally {
    confirmingRequest.value = null
  }
}

async function decideChildLinkRequest(decision) {
  const linkRequest = childLinkDecision.value
  if (!linkRequest) return
  const key = `child-link-${linkRequest.id}`
  confirmingRequest.value = key
  try {
    const routeName = decision === 'approved'
      ? 'club.child-link-requests.approve'
      : 'club.child-link-requests.reject'
    const { data } = await axios.post(route(routeName, { linkRequest: linkRequest.id }), {
      decision_note: decision === 'rejected' ? childLinkDecisionNote.value : null,
    })
    confirmationRequests.value = data.data
    childLinkDecision.value = null
    childLinkDecisionNote.value = ''
    showToast(decision === 'approved'
      ? tr('Hijo vinculado a la cuenta del padre', 'Child linked to the parent account')
      : tr('Solicitud de vínculo rechazada', 'Linking request rejected'))
  } catch (error) {
    showToast(error?.response?.data?.message || tr('No se pudo procesar la solicitud', 'Could not process the request'), 'error')
  } finally {
    confirmingRequest.value = null
  }
}

</script>

<template>
  <PathfinderLayout>
    <template #title>{{ tr('Panel del Director de Club', 'Club Director Dashboard') }}</template>

    <div class="space-y-6 text-gray-800">
      <section v-if="confirmationRequests?.total" class="overflow-hidden rounded-xl border border-amber-300 bg-white shadow-sm">
        <div class="flex flex-col gap-2 border-b border-amber-200 bg-amber-50 p-5 sm:flex-row sm:items-start sm:justify-between">
          <div>
            <p class="text-xs font-semibold uppercase tracking-wide text-amber-700">{{ tr('Inscripciones por enlace seguro', 'Secure-link enrollments') }}</p>
            <h2 class="mt-1 text-xl font-semibold text-gray-950">{{ tr('Confirmaciones pendientes', 'Pending confirmations') }}</h2>
            <p class="mt-1 text-sm text-gray-700">{{ tr('Estas cuentas esperan que el padre confirme su correo. Usa la activación del director solamente cuando el padre no pueda recibir el mensaje.', 'These accounts are waiting for the parent to confirm their email. Use director activation only when the parent cannot receive the message.') }}</p>
          </div>
          <span class="inline-flex w-fit rounded-full bg-amber-600 px-3 py-1 text-sm font-bold text-white">{{ confirmationRequests.total }}</span>
        </div>

        <div class="p-5">
          <div v-if="confirmationRequests.parents?.length">
            <h3 class="font-semibold text-gray-900">{{ tr('Cuentas de padres', 'Parent accounts') }}</h3>
            <div class="mt-3 space-y-3">
              <article v-for="parent in confirmationRequests.parents" :key="parent.id" class="flex flex-col gap-3 rounded-lg border p-4 sm:flex-row sm:items-center sm:justify-between">
                <div class="min-w-0">
                  <p class="font-semibold text-gray-900">{{ parent.name }}</p>
                  <p class="break-all text-sm text-gray-600">{{ parent.email }}</p>
                  <p class="mt-1 text-xs font-medium text-emerald-700">{{ parent.club_name }}</p>
                  <p class="mt-2 inline-flex rounded-full bg-amber-100 px-2.5 py-1 text-xs font-semibold text-amber-800">
                    {{ tr('Esperando confirmación de correo', 'Waiting for email confirmation') }}
                  </p>
                </div>
                <button type="button" class="shrink-0 rounded bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800 disabled:opacity-60" :disabled="confirmingRequest === `parent-${parent.id}`" @click="requestDirectorActivation(parent)">
                  {{ confirmingRequest === `parent-${parent.id}` ? tr('Activando...', 'Activating...') : tr('Activar como director', 'Activate as director') }}
                </button>
              </article>
            </div>
          </div>
        </div>
      </section>

      <section v-if="confirmationRequests?.child_link_requests?.length" class="overflow-hidden rounded-xl border border-blue-300 bg-white shadow-sm">
        <div class="flex items-start justify-between gap-3 border-b border-blue-200 bg-blue-50 p-5">
          <div>
            <p class="text-xs font-semibold uppercase tracking-wide text-blue-700">{{ tr('Vínculos de padres e hijos', 'Parent-child links') }}</p>
            <h2 class="mt-1 text-xl font-semibold text-gray-950">{{ tr('Solicitudes para confirmar', 'Requests to confirm') }}</h2>
            <p class="mt-1 text-sm text-gray-700">{{ tr('Dos de los tres factores de identidad coinciden. Revisa los datos antes de aprobar el vínculo.', 'Two of the three identity factors match. Review the information before approving the link.') }}</p>
          </div>
          <span class="inline-flex rounded-full bg-blue-700 px-3 py-1 text-sm font-bold text-white">{{ confirmationRequests.child_link_requests.length }}</span>
        </div>
        <div class="space-y-3 p-5">
          <article v-for="linkRequest in confirmationRequests.child_link_requests" :key="linkRequest.id" class="rounded-lg border border-gray-200 p-4">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
              <div class="min-w-0">
                <p class="font-semibold text-gray-950">{{ linkRequest.child_name }}</p>
                <p class="text-sm text-gray-700">{{ tr('Padre/madre', 'Parent') }}: {{ linkRequest.parent_name }}</p>
                <p class="break-all text-sm text-gray-600">{{ linkRequest.parent_email }}</p>
                <p class="mt-1 text-xs font-medium text-blue-700">{{ linkRequest.club_name }}</p>
                <div class="mt-3 flex flex-wrap gap-2 text-xs font-semibold">
                  <span class="rounded-full px-2.5 py-1" :class="linkRequest.match_factors?.last_name ? 'bg-emerald-100 text-emerald-800' : 'bg-gray-100 text-gray-500'">{{ tr('Apellido', 'Last name') }} {{ linkRequest.match_factors?.last_name ? '✓' : '—' }}</span>
                  <span class="rounded-full px-2.5 py-1" :class="linkRequest.match_factors?.parent_name ? 'bg-emerald-100 text-emerald-800' : 'bg-gray-100 text-gray-500'">{{ tr('Nombre del padre', 'Parent name') }} {{ linkRequest.match_factors?.parent_name ? '✓' : '—' }}</span>
                  <span class="rounded-full px-2.5 py-1" :class="linkRequest.match_factors?.email ? 'bg-emerald-100 text-emerald-800' : 'bg-gray-100 text-gray-500'">{{ tr('Correo verificado', 'Verified email') }} {{ linkRequest.match_factors?.email ? '✓' : '—' }}</span>
                </div>
              </div>
              <div class="flex shrink-0 gap-2">
                <button type="button" class="rounded border border-red-300 px-3 py-2 text-sm font-semibold text-red-700 hover:bg-red-50" @click="childLinkDecision = linkRequest; childLinkDecisionNote = ''">
                  {{ tr('Revisar', 'Review') }}
                </button>
              </div>
            </div>
          </article>
        </div>
      </section>

      <div class="bg-white border rounded-lg shadow-sm p-5">
        <div class="flex flex-col gap-2 md:flex-row md:items-start md:justify-between">
          <div>
            <p class="text-lg font-semibold">{{ tr('Jerarquía del club', 'Club hierarchy') }}</p>
            <p class="text-sm text-gray-600">
              {{ tr('Ubicación administrativa del club actualmente seleccionado.', 'Administrative location for the currently selected club.') }}
            </p>
          </div>
          <span
            v-if="props.club_hierarchy?.club?.status"
            class="inline-flex w-fit rounded-full px-3 py-1 text-xs font-semibold"
            :class="props.club_hierarchy.club.status === 'active' ? 'bg-green-100 text-green-700' : 'bg-amber-100 text-amber-700'"
          >
            {{ props.club_hierarchy.club.status }}
          </span>
        </div>

        <div v-if="props.club_hierarchy" class="mt-5 grid gap-3 md:grid-cols-5">
          <div class="rounded-lg border border-blue-100 bg-blue-50 p-3">
            <p class="text-xs font-semibold uppercase tracking-wide text-blue-700">{{ tr('Unión', 'Union') }}</p>
            <p class="mt-1 font-semibold text-gray-900">{{ labelOrMissing(props.club_hierarchy.union?.name) }}</p>
            <p class="mt-1 text-xs text-gray-600">ID: {{ props.club_hierarchy.union?.id || '—' }}</p>
            <p v-if="props.club_hierarchy.union?.evaluation_system" class="text-xs text-gray-600">
              {{ tr('Sistema', 'System') }}: {{ props.club_hierarchy.union.evaluation_system }}
            </p>
          </div>

          <div class="rounded-lg border border-slate-200 bg-slate-50 p-3">
            <p class="text-xs font-semibold uppercase tracking-wide text-slate-600">{{ tr('Asociación / Conferencia', 'Association / Conference') }}</p>
            <p class="mt-1 font-semibold text-gray-900">{{ labelOrMissing(props.club_hierarchy.association?.name) }}</p>
            <p class="mt-1 text-xs text-gray-600">ID: {{ props.club_hierarchy.association?.id || '—' }}</p>
          </div>

          <div class="rounded-lg border border-slate-200 bg-slate-50 p-3">
            <p class="text-xs font-semibold uppercase tracking-wide text-slate-600">{{ tr('Distrito', 'District') }}</p>
            <p class="mt-1 font-semibold text-gray-900">{{ labelOrMissing(props.club_hierarchy.district?.name) }}</p>
            <p class="mt-1 text-xs text-gray-600">ID: {{ props.club_hierarchy.district?.id || '—' }}</p>
          </div>

          <div class="rounded-lg border border-slate-200 bg-slate-50 p-3">
            <p class="text-xs font-semibold uppercase tracking-wide text-slate-600">{{ tr('Iglesia', 'Church') }}</p>
            <p class="mt-1 font-semibold text-gray-900">{{ labelOrMissing(props.club_hierarchy.church?.name) }}</p>
            <p class="mt-1 text-xs text-gray-600">ID: {{ props.club_hierarchy.church?.id || '—' }}</p>
          </div>

          <div class="rounded-lg border border-emerald-100 bg-emerald-50 p-3">
            <p class="text-xs font-semibold uppercase tracking-wide text-emerald-700">{{ tr('Club', 'Club') }}</p>
            <p class="mt-1 font-semibold text-gray-900">{{ labelOrMissing(props.club_hierarchy.club?.name) }}</p>
            <p class="mt-1 text-xs text-gray-600">ID: {{ props.club_hierarchy.club?.id || '—' }}</p>
            <p class="text-xs text-gray-600">{{ tr('Tipo', 'Type') }}: {{ labelOrMissing(props.club_hierarchy.club?.type) }}</p>
          </div>
        </div>

        <div v-else class="mt-5 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800">
          {{ tr('No hay un club activo o seleccionado para este usuario.', 'There is no active or selected club for this user.') }}
        </div>
      </div>

      <div class="bg-white border rounded-lg shadow-sm p-4 flex items-center justify-between">
        <div>
          <p class="text-lg font-semibold">{{ tr('Código de invitación de la iglesia', 'Church invitation code') }}</p>
          <p class="text-sm text-gray-600">{{ tr('Compártelo con usuarios autorizados para que puedan registrarse.', 'Share it with authorized users so they can register.') }}</p>
        </div>
        <button
          class="px-4 py-2 bg-blue-600 text-white rounded text-sm"
          type="button"
          @click="openInviteModal"
        >
          {{ tr('Ver / Regenerar', 'View / Regenerate') }}
        </button>
      </div>
    </div>

    <div v-if="activationCandidate" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
      <div class="w-full max-w-lg rounded-xl bg-white p-6 shadow-xl">
        <h3 class="text-xl font-semibold text-gray-950">{{ tr('Activar cuenta sin confirmar correo', 'Activate account without email confirmation') }}</h3>
        <p class="mt-3 text-sm text-gray-700">
          {{ tr('Esta acción permitirá que el padre use el portal, pero marcará la cuenta como activada por el director. El padre no podrá recuperar ni cambiar la contraseña por sí mismo y deberá solicitar ayuda al director.', 'This action will allow the parent to use the portal, but will mark the account as director-activated. The parent will not be able to recover or change the password independently and must ask the director for help.') }}
        </p>
        <div class="mt-4 rounded-lg bg-amber-50 p-3 text-sm text-amber-900">
          <p class="font-semibold">{{ activationCandidate.name }}</p>
          <p class="break-all">{{ activationCandidate.email }}</p>
        </div>
        <div class="mt-6 flex justify-end gap-3">
          <button type="button" class="rounded border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50" :disabled="Boolean(confirmingRequest)" @click="activationCandidate = null">
            {{ tr('Cancelar', 'Cancel') }}
          </button>
          <button type="button" class="rounded bg-amber-700 px-4 py-2 text-sm font-semibold text-white hover:bg-amber-800 disabled:opacity-60" :disabled="Boolean(confirmingRequest)" @click="confirmSecureEnrollment">
            {{ confirmingRequest ? tr('Activando...', 'Activating...') : tr('Entiendo, activar cuenta', 'I understand, activate account') }}
          </button>
        </div>
      </div>
    </div>

    <div v-if="childLinkDecision" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
      <div class="w-full max-w-lg rounded-xl bg-white p-6 shadow-xl">
        <h3 class="text-xl font-semibold text-gray-950">{{ tr('Confirmar vínculo familiar', 'Confirm family link') }}</h3>
        <p class="mt-2 text-sm text-gray-700">{{ tr('La solicitud coincide en dos factores. Confirma solamente si reconoces que esta cuenta pertenece al padre, madre o tutor del menor.', 'The request matches two factors. Approve only if you recognize this account as belonging to the child’s parent or guardian.') }}</p>
        <div class="mt-4 rounded-lg bg-blue-50 p-3 text-sm text-blue-950">
          <p class="font-semibold">{{ childLinkDecision.child_name }}</p>
          <p>{{ childLinkDecision.parent_name }} · {{ childLinkDecision.parent_email }}</p>
          <p>{{ childLinkDecision.club_name }}</p>
        </div>
        <label for="child-link-note" class="mt-4 block text-sm font-medium text-gray-800">{{ tr('Motivo del rechazo (opcional)', 'Rejection reason (optional)') }}</label>
        <textarea id="child-link-note" v-model="childLinkDecisionNote" rows="3" class="mt-1 w-full rounded border-gray-300 text-sm" maxlength="1000"></textarea>
        <div class="mt-6 flex flex-wrap justify-end gap-3">
          <button type="button" class="rounded border border-gray-300 px-4 py-2 text-sm" :disabled="Boolean(confirmingRequest)" @click="childLinkDecision = null">{{ tr('Cancelar', 'Cancel') }}</button>
          <button type="button" class="rounded bg-red-700 px-4 py-2 text-sm font-semibold text-white disabled:opacity-60" :disabled="Boolean(confirmingRequest)" @click="decideChildLinkRequest('rejected')">{{ tr('Rechazar', 'Reject') }}</button>
          <button type="button" class="rounded bg-emerald-700 px-4 py-2 text-sm font-semibold text-white disabled:opacity-60" :disabled="Boolean(confirmingRequest)" @click="decideChildLinkRequest('approved')">{{ tr('Aprobar vínculo', 'Approve link') }}</button>
        </div>
      </div>
    </div>

    <!-- Invite code modal -->
    <div v-if="inviteModalOpen" class="fixed inset-0 bg-black/40 flex items-center justify-center z-50">
      <div class="bg-white rounded-lg shadow-lg max-w-md w-full p-5 space-y-3">
        <div class="flex items-start justify-between">
          <h4 class="text-lg font-semibold">{{ tr('Código de invitación de la iglesia', 'Church invitation code') }}</h4>
          <button class="text-gray-500" @click="inviteModalOpen = false">✕</button>
        </div>
        <div class="text-sm text-gray-700">
          {{ tr('Comparte este código con usuarios autorizados para que puedan registrarse.', 'Share this code with authorized users so they can register.') }}
        </div>
        <div class="p-3 border rounded bg-gray-50 text-center text-xl font-mono tracking-wide">
          <span v-if="inviteLoading" class="text-sm text-gray-500">{{ tr('Cargando…', 'Loading…') }}</span>
          <span v-else>{{ inviteCode || '—' }}</span>
        </div>
        <div class="flex justify-end gap-2">
          <button class="px-3 py-2 border rounded" @click="inviteModalOpen = false">{{ tr('Cerrar', 'Close') }}</button>
          <button class="px-3 py-2 bg-blue-600 text-white rounded" :disabled="inviteLoading" @click="regenerateCode">{{ tr('Regenerar', 'Regenerate') }}</button>
        </div>
      </div>
    </div>
  </PathfinderLayout>
</template>
