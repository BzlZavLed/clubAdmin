<script setup>
import { computed, ref, watch } from 'vue'
import axios from 'axios'
import PathfinderLayout from '@/Layouts/PathfinderLayout.vue'
import { useLocale } from '@/Composables/useLocale'

const props = defineProps({
    unions: { type: Array, default: () => [] },
    associations: { type: Array, default: () => [] },
    districts: { type: Array, default: () => [] },
    churches: { type: Array, default: () => [] },
    clubs: { type: Array, default: () => [] },
    context: { type: Object, default: () => ({}) },
})

const { tr } = useLocale()
const saving = ref(false)
const message = ref('')
const error = ref('')

const selectedUnionId = ref(props.context?.union_id ? String(props.context.union_id) : '')
const selectedAssociationId = ref(props.context?.association_id ? String(props.context.association_id) : '')
const selectedDistrictId = ref(props.context?.district_id ? String(props.context.district_id) : '')
const selectedChurchId = ref(props.context?.church_id ? String(props.context.church_id) : '')
const selectedClubId = ref(props.context?.club_id ? String(props.context.club_id) : '')

const filteredAssociations = computed(() => {
    if (!selectedUnionId.value) return []
    return props.associations.filter((association) => String(association.union_id) === String(selectedUnionId.value))
})

const filteredDistricts = computed(() => {
    if (!selectedAssociationId.value) return []
    return props.districts.filter((district) => String(district.association_id) === String(selectedAssociationId.value))
})

const filteredChurches = computed(() => {
    if (!selectedDistrictId.value) return []
    return props.churches.filter((church) => String(church.district_id) === String(selectedDistrictId.value))
})

const filteredClubs = computed(() => {
    if (!selectedChurchId.value) return []
    return props.clubs.filter((club) => String(club.church_id) === String(selectedChurchId.value))
})

const inferredRole = computed(() => {
    if (selectedClubId.value) return tr('Director de club', 'Club director')
    if (selectedDistrictId.value) return tr('Rol distrital', 'District role')
    if (selectedAssociationId.value) return tr('Dir. de jóvenes asociación', 'Association youth director')
    if (selectedUnionId.value) return tr('Dir. de jóvenes unión', 'Union youth director')
    return 'Superadmin'
})

watch(selectedUnionId, () => {
    const exists = filteredAssociations.value.some((association) => String(association.id) === String(selectedAssociationId.value))
    if (!exists) {
        selectedAssociationId.value = ''
        selectedDistrictId.value = ''
        selectedChurchId.value = ''
        selectedClubId.value = ''
    }
})

watch(selectedAssociationId, () => {
    const exists = filteredDistricts.value.some((district) => String(district.id) === String(selectedDistrictId.value))
    if (!exists) {
        selectedDistrictId.value = ''
        selectedChurchId.value = ''
        selectedClubId.value = ''
    }
})

watch(selectedDistrictId, () => {
    const exists = filteredChurches.value.some((church) => String(church.id) === String(selectedChurchId.value))
    if (!exists) {
        selectedChurchId.value = ''
        selectedClubId.value = ''
    }
})

watch(selectedChurchId, () => {
    const exists = filteredClubs.value.some((club) => String(club.id) === String(selectedClubId.value))
    if (!exists) {
        selectedClubId.value = ''
    }
})

const saveContext = async () => {
    saving.value = true
    error.value = ''
    message.value = ''

    try {
        const { data } = await axios.post(route('superadmin.context.set'), {
            union_id: selectedUnionId.value ? Number(selectedUnionId.value) : null,
            association_id: selectedAssociationId.value ? Number(selectedAssociationId.value) : null,
            district_id: selectedDistrictId.value ? Number(selectedDistrictId.value) : null,
            church_id: selectedChurchId.value ? Number(selectedChurchId.value) : null,
            club_id: selectedClubId.value ? Number(selectedClubId.value) : null,
        })

        message.value = tr('Contexto guardado para esta sesión.', 'Context saved for this session.')
        const dashboardUrl = data?.context?.dashboard_url || route('superadmin.dashboard')
        window.location.href = dashboardUrl
    } catch (err) {
        error.value = err?.response?.data?.message || tr('No se pudo guardar el contexto.', 'Could not save the context.')
    } finally {
        saving.value = false
    }
}

const clearContext = async () => {
    selectedUnionId.value = ''
    selectedAssociationId.value = ''
    selectedDistrictId.value = ''
    selectedChurchId.value = ''
    selectedClubId.value = ''
    await saveContext()
}
</script>

<template>
  <PathfinderLayout>
    <template #title>{{ tr('Panel de Superadministrador', 'Superadmin Dashboard') }}</template>

    <div class="space-y-4 text-gray-800">
      <div class="bg-white border rounded-lg shadow-sm p-4">
        <p class="text-lg font-semibold">{{ tr('Contexto operativo', 'Operating context') }}</p>
        <p class="text-sm text-gray-600">
          {{ tr('Selecciona la jerarquía desde unión hasta club. El nivel más profundo seleccionado define qué dashboard y menú cargará esta sesión.', 'Select the hierarchy from union down to club. The deepest selected level defines which dashboard and menu this session will load.') }}
        </p>
      </div>

      <div class="bg-white border rounded-lg shadow-sm p-4 space-y-4">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div>
            <label class="block text-xs text-gray-600 mb-1">{{ tr('Unión', 'Union') }}</label>
            <select v-model="selectedUnionId" class="w-full border rounded px-3 py-2 text-sm">
              <option value="">{{ tr('Sin unión', 'No union') }}</option>
              <option v-for="union in props.unions" :key="union.id" :value="String(union.id)">
                {{ union.name }}
              </option>
            </select>
          </div>

          <div>
            <label class="block text-xs text-gray-600 mb-1">{{ tr('Conferencia / Asociación', 'Conference / Association') }}</label>
            <select v-model="selectedAssociationId" class="w-full border rounded px-3 py-2 text-sm" :disabled="!selectedUnionId">
              <option value="">{{ tr('Sin asociación', 'No association') }}</option>
              <option v-for="association in filteredAssociations" :key="association.id" :value="String(association.id)">
                {{ association.name }}
              </option>
            </select>
          </div>

          <div>
            <label class="block text-xs text-gray-600 mb-1">{{ tr('Distrito', 'District') }}</label>
            <select v-model="selectedDistrictId" class="w-full border rounded px-3 py-2 text-sm" :disabled="!selectedAssociationId">
              <option value="">{{ tr('Sin distrito', 'No district') }}</option>
              <option v-for="district in filteredDistricts" :key="district.id" :value="String(district.id)">
                {{ district.name }}
              </option>
            </select>
          </div>

          <div>
            <label class="block text-xs text-gray-600 mb-1">{{ tr('Iglesia', 'Church') }}</label>
            <select v-model="selectedChurchId" class="w-full border rounded px-3 py-2 text-sm" :disabled="!selectedDistrictId">
              <option value="">{{ tr('Sin iglesia', 'No church') }}</option>
              <option v-for="church in filteredChurches" :key="church.id" :value="String(church.id)">
                {{ church.church_name }}
              </option>
            </select>
          </div>

          <div class="md:col-span-2">
            <label class="block text-xs text-gray-600 mb-1">{{ tr('Club', 'Club') }}</label>
            <select v-model="selectedClubId" class="w-full border rounded px-3 py-2 text-sm" :disabled="!selectedChurchId">
              <option value="">{{ tr('Sin club', 'No club') }}</option>
              <option v-for="club in filteredClubs" :key="club.id" :value="String(club.id)">
                {{ club.club_name }}
              </option>
            </select>
          </div>
        </div>

        <div class="rounded-lg border border-blue-200 bg-blue-50 px-4 py-3">
          <p class="text-sm font-semibold text-blue-900">{{ tr('Rol efectivo', 'Effective role') }}</p>
          <p class="text-sm text-blue-800">{{ inferredRole }}</p>
        </div>

        <div class="flex items-center gap-3">
          <button
            type="button"
            class="px-3 py-2 rounded bg-blue-600 text-white text-sm disabled:opacity-60"
            :disabled="saving"
            @click="saveContext"
          >
            {{ saving ? tr('Guardando...', 'Saving...') : tr('Guardar y entrar', 'Save and enter') }}
          </button>
          <button
            type="button"
            class="px-3 py-2 rounded border border-gray-300 text-sm text-gray-700"
            :disabled="saving"
            @click="clearContext"
          >
            {{ tr('Limpiar contexto', 'Clear context') }}
          </button>
        </div>

        <p v-if="message" class="text-xs text-green-700">{{ message }}</p>
        <p v-if="error" class="text-xs text-red-600">{{ error }}</p>
      </div>
    </div>
  </PathfinderLayout>
</template>
