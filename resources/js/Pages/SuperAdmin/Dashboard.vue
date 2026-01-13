<script setup>
import { ref } from 'vue'
import PathfinderLayout from '@/Layouts/PathfinderLayout.vue'
import { fetchInviteCode, regenerateInviteCode } from '@/Services/api'
import { useGeneral } from '@/Composables/useGeneral'

const { showToast } = useGeneral()

const inviteModalOpen = ref(false)
const inviteCode = ref(null)
const inviteLoading = ref(false)

async function openInviteModal() {
  inviteModalOpen.value = true
  inviteLoading.value = true
  try {
    const data = await fetchInviteCode()
    inviteCode.value = data.code
  } catch (e) {
    console.error(e)
    showToast('Failed to load invite code', 'error')
  } finally {
    inviteLoading.value = false
  }
}

async function regenerateCode() {
  inviteLoading.value = true
  try {
    const data = await regenerateInviteCode()
    inviteCode.value = data.code
    showToast('Invite code regenerated')
  } catch (e) {
    console.error(e)
    showToast('Failed to regenerate code', 'error')
  } finally {
    inviteLoading.value = false
  }
}
</script>

<template>
  <PathfinderLayout>
    <template #title>Super Admin Dashboard</template>

    <div class="space-y-6 text-gray-800">
      <div class="bg-white border rounded-lg shadow-sm p-4 flex items-center justify-between">
        <div>
          <p class="text-lg font-semibold">Church invite code</p>
          <p class="text-sm text-gray-600">Share with allowed users so they can register.</p>
        </div>
        <button
          class="px-4 py-2 bg-blue-600 text-white rounded text-sm"
          type="button"
          @click="openInviteModal"
        >
          View / Regenerate
        </button>
      </div>
    </div>

    <!-- Invite code modal -->
    <div v-if="inviteModalOpen" class="fixed inset-0 bg-black/40 flex items-center justify-center z-50">
      <div class="bg-white rounded-lg shadow-lg max-w-md w-full p-5 space-y-3">
        <div class="flex items-start justify-between">
          <h4 class="text-lg font-semibold">Church invite code</h4>
          <button class="text-gray-500" @click="inviteModalOpen = false">✕</button>
        </div>
        <div class="text-sm text-gray-700">
          Share this code with allowed users so they can register.
        </div>
        <div class="p-3 border rounded bg-gray-50 text-center text-xl font-mono tracking-wide">
          <span v-if="inviteLoading" class="text-sm text-gray-500">Loading…</span>
          <span v-else>{{ inviteCode || '—' }}</span>
        </div>
        <div class="flex justify-end gap-2">
          <button class="px-3 py-2 border rounded" @click="inviteModalOpen = false">Close</button>
          <button class="px-3 py-2 bg-blue-600 text-white rounded" :disabled="inviteLoading" @click="regenerateCode">Regenerate</button>
        </div>
      </div>
    </div>
  </PathfinderLayout>
</template>
