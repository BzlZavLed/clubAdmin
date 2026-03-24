<script setup>
import PathfinderLayout from '@/Layouts/PathfinderLayout.vue'
import { useForm } from '@inertiajs/vue3'
import { useLocale } from '@/Composables/useLocale'
import { computed } from 'vue'
import { useAuth } from '@/Composables/useAuth'

const props = defineProps({
    clubs: Array,
    selectedClubId: {
        type: [Number, String, null],
        default: null,
    },
    lockClubSelection: {
        type: Boolean,
        default: false,
    },
})

const { activeClub } = useAuth()

const selectedClub = computed(() =>
    (props.clubs || []).find((club) => String(club.id) === String(props.selectedClubId ?? ''))
    || activeClub.value
    || null
)

const form = useForm({
    club_id: props.selectedClubId || props.clubs?.[0]?.id || '',
    title: '',
    description: '',
    event_type: '',
    start_at: '',
    end_at: '',
    timezone: 'America/New_York',
    location_name: '',
    location_address: '',
    status: 'draft',
    budget_estimated_total: '',
    budget_actual_total: '',
    requires_approval: false,
    is_payable: false,
    payment_amount: '',
    risk_level: '',
})
const { tr } = useLocale()

const submit = () => {
    form.post(route('events.store'))
}
</script>

<template>
    <PathfinderLayout>
        <template #title>{{ tr('Crear evento', 'Create Event') }}</template>

        <div class="bg-white rounded-lg border p-6 space-y-4">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="text-sm text-gray-600">{{ tr('Club', 'Club') }}</label>
                    <template v-if="lockClubSelection">
                        <input
                            :value="selectedClub ? `${selectedClub.club_name}${selectedClub.club_type ? ` (${selectedClub.club_type})` : ''}` : tr('Selecciona un club desde el selector global', 'Select a club from the global selector')"
                            class="w-full border rounded px-3 py-2 text-sm bg-gray-100 text-gray-700"
                            readonly
                        />
                    </template>
                    <template v-else>
                        <select v-model="form.club_id" class="w-full border rounded px-3 py-2 text-sm">
                            <option value="">{{ tr('Selecciona un club', 'Select a club') }}</option>
                            <option v-for="club in clubs" :key="club.id" :value="club.id">{{ club.club_name }}</option>
                        </select>
                    </template>
                </div>
                <div>
                    <label class="text-sm text-gray-600">{{ tr('Título', 'Title') }}</label>
                    <input v-model="form.title" class="w-full border rounded px-3 py-2 text-sm" />
                </div>
                <div>
                    <label class="text-sm text-gray-600">{{ tr('Tipo de evento', 'Event Type') }}</label>
                    <input v-model="form.event_type" class="w-full border rounded px-3 py-2 text-sm" :placeholder="tr('campamento, recaudación...', 'camp, fundraiser...')" />
                </div>
                <div class="md:col-span-2">
                    <label class="text-sm text-gray-600">{{ tr('Descripción del evento', 'Event Description') }}</label>
                    <textarea
                        v-model="form.description"
                        rows="4"
                        class="w-full border rounded px-3 py-2 text-sm"
                        :placeholder="tr('Describe el propósito, formato y contexto del evento para mejorar las tareas sugeridas por IA.', 'Describe the purpose, format, and context of the event to improve AI task suggestions.')"
                    ></textarea>
                </div>
                <div>
                    <label class="text-sm text-gray-600">{{ tr('Inicio', 'Start') }}</label>
                    <input v-model="form.start_at" type="datetime-local" class="w-full border rounded px-3 py-2 text-sm" />
                </div>
                <div>
                    <label class="text-sm text-gray-600">{{ tr('Fin', 'End') }}</label>
                    <input v-model="form.end_at" type="datetime-local" class="w-full border rounded px-3 py-2 text-sm" />
                </div>
                <div>
                    <label class="text-sm text-gray-600">{{ tr('Zona horaria', 'Timezone') }}</label>
                    <input v-model="form.timezone" class="w-full border rounded px-3 py-2 text-sm" />
                </div>
                <div>
                    <label class="text-sm text-gray-600">{{ tr('Nombre del lugar (opcional)', 'Location Name (optional)') }}</label>
                    <input v-model="form.location_name" class="w-full border rounded px-3 py-2 text-sm" :placeholder="tr('Opcional', 'Optional')" />
                </div>
                <div>
                    <label class="text-sm text-gray-600">{{ tr('Dirección del lugar (opcional)', 'Location Address (optional)') }}</label>
                    <input v-model="form.location_address" class="w-full border rounded px-3 py-2 text-sm" :placeholder="tr('Opcional', 'Optional')" />
                </div>
            </div>
            <div class="flex items-center gap-6">
                <label class="text-sm text-gray-600 flex items-center gap-2">
                    <input type="checkbox" v-model="form.requires_approval" />
                    {{ tr('Requiere aprobación', 'Requires approval') }}
                </label>
            </div>
            <div class="flex items-center gap-6">
                <label class="text-sm text-gray-600 flex items-center gap-2">
                    <input type="checkbox" v-model="form.is_payable" />
                    {{ tr('Evento con pago', 'Payable event') }}
                </label>
                <div class="flex items-center gap-2">
                    <label class="text-sm text-gray-600">{{ tr('Monto', 'Fee amount') }}</label>
                    <input
                        v-model="form.payment_amount"
                        type="number"
                        step="0.01"
                        min="0"
                        :disabled="!form.is_payable"
                        class="w-32 border rounded px-3 py-2 text-sm disabled:bg-gray-100"
                        placeholder="0.00"
                    />
                </div>
            </div>
            <button @click="submit" class="px-4 py-2 bg-blue-600 text-white rounded text-sm" :disabled="form.processing">
                {{ form.processing ? tr('Guardando...', 'Saving...') : tr('Crear evento', 'Create Event') }}
            </button>
        </div>
    </PathfinderLayout>
</template>
