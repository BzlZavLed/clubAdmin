<script setup>
import { ref } from 'vue'
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
})

const inviteModalOpen = ref(false)
const inviteCode = ref(null)
const inviteLoading = ref(false)

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
</script>

<template>
  <PathfinderLayout>
    <template #title>{{ tr('Panel del Director de Club', 'Club Director Dashboard') }}</template>

    <div class="space-y-6 text-gray-800">
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
