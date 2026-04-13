<script setup>
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import axios from 'axios'
import PathfinderLayout from '@/Layouts/PathfinderLayout.vue'
import { useLocale } from '@/Composables/useLocale'

const props = defineProps({
    clubs: { type: Array, default: () => [] },
    context: { type: Object, default: () => ({ club_id: null }) },
})

const selectedClubId = ref(props.context?.club_id ? String(props.context.club_id) : '')
const saving = ref(false)
const message = ref('')
const error = ref('')
const { tr } = useLocale()

const saveContext = async () => {
    saving.value = true
    error.value = ''
    message.value = ''
    try {
        await axios.post(route('superadmin.context.set'), {
            club_id: selectedClubId.value ? Number(selectedClubId.value) : null,
        })
        message.value = tr('Contexto guardado para esta sesion.', 'Context saved for this session.')
        router.reload({ only: ['auth'] })
    } catch (err) {
        error.value = err?.response?.data?.message || tr('No se pudo guardar el contexto.', 'Could not save the context.')
    } finally {
        saving.value = false
    }
}
</script>

<template>
  <PathfinderLayout>
    <template #title>{{ tr('Panel de Superadministrador', 'Superadmin Dashboard') }}</template>

    <div class="space-y-4 text-gray-800">
      <div class="bg-white border rounded-lg shadow-sm p-4">
        <p class="text-lg font-semibold">{{ tr('Bienvenido, Superadministrador', 'Welcome, Superadmin') }}</p>
        <p class="text-sm text-gray-600">{{ tr('Define el club activo para operar en vistas de director y personal. La iglesia se resuelve automaticamente desde ese club.', 'Set the active club to work in director and staff views. The church is resolved automatically from that club.') }}</p>
      </div>

      <div class="bg-white border rounded-lg shadow-sm p-4 space-y-3">
        <p class="text-sm font-semibold">{{ tr('Contexto de sesion', 'Session context') }}</p>

        <div>
          <label class="block text-xs text-gray-600 mb-1">{{ tr('Club', 'Club') }}</label>
          <select v-model="selectedClubId" class="w-full border rounded px-3 py-2 text-sm">
            <option value="">{{ tr('Todos los clubes', 'All clubs') }}</option>
            <option v-for="club in props.clubs" :key="club.id" :value="String(club.id)">
              {{ club.club_name }}
            </option>
          </select>
        </div>

        <div class="flex items-center gap-3">
          <button
            type="button"
            class="px-3 py-2 rounded bg-blue-600 text-white text-sm disabled:opacity-60"
            :disabled="saving"
            @click="saveContext"
          >
            {{ saving ? tr('Guardando...', 'Saving...') : tr('Guardar contexto', 'Save context') }}
          </button>
          <a :href="route('superadmin.clubs.manage')" class="text-sm text-blue-600 hover:underline">{{ tr('Crear/gestionar clubes', 'Create/manage clubs') }}</a>
        </div>

        <p v-if="message" class="text-xs text-green-700">{{ message }}</p>
        <p v-if="error" class="text-xs text-red-600">{{ error }}</p>
      </div>
    </div>
  </PathfinderLayout>
</template>
