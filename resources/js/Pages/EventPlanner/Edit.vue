<script setup>
import PathfinderLayout from '@/Layouts/PathfinderLayout.vue'
import { useForm } from '@inertiajs/vue3'
import { useLocale } from '@/Composables/useLocale'

const props = defineProps({
    event: Object,
})

const form = useForm({
    title: props.event.title,
    event_type: props.event.event_type,
    start_at: props.event.start_at?.slice(0, 16),
    end_at: props.event.end_at ? props.event.end_at.slice(0, 16) : '',
    timezone: props.event.timezone,
    location_name: props.event.location_name,
    location_address: props.event.location_address,
    status: props.event.status,
    budget_estimated_total: props.event.budget_estimated_total,
    budget_actual_total: props.event.budget_actual_total,
    requires_approval: props.event.requires_approval,
    is_payable: props.event.is_payable,
    payment_amount: props.event.payment_amount,
    risk_level: props.event.risk_level,
})
const { tr } = useLocale()

const submit = () => {
    form.put(route('events.update', props.event.id))
}
</script>

<template>
    <PathfinderLayout>
        <template #title>{{ tr('Editar evento', 'Edit Event') }}</template>

        <div class="space-y-4 rounded-lg border bg-white p-4 sm:p-6">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="text-sm text-gray-600">{{ tr('Título', 'Title') }}</label>
                    <input v-model="form.title" class="w-full border rounded px-3 py-2 text-sm" />
                </div>
                <div>
                    <label class="text-sm text-gray-600">{{ tr('Tipo de evento', 'Event Type') }}</label>
                    <input v-model="form.event_type" class="w-full border rounded px-3 py-2 text-sm" />
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
                    <label class="text-sm text-gray-600">{{ tr('Nombre del lugar', 'Location Name') }}</label>
                    <input v-model="form.location_name" class="w-full border rounded px-3 py-2 text-sm" />
                </div>
                <div>
                    <label class="text-sm text-gray-600">{{ tr('Dirección del lugar', 'Location Address') }}</label>
                    <input v-model="form.location_address" class="w-full border rounded px-3 py-2 text-sm" />
                </div>
                <div>
                    <label class="text-sm text-gray-600">{{ tr('Estado', 'Status') }}</label>
                    <input v-model="form.status" class="w-full border rounded px-3 py-2 text-sm" />
                </div>
            </div>
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:gap-6">
                <label class="flex items-center gap-2 text-sm text-gray-600">
                    <input type="checkbox" v-model="form.is_payable" />
                    {{ tr('Evento con pago', 'Payable event') }}
                </label>
                <div class="flex flex-col gap-1 sm:flex-row sm:items-center sm:gap-2">
                    <label class="text-sm text-gray-600">{{ tr('Monto', 'Fee amount') }}</label>
                    <input
                        v-model="form.payment_amount"
                        type="number"
                        step="0.01"
                        min="0"
                        :disabled="!form.is_payable"
                        class="w-full rounded border px-3 py-2 text-sm disabled:bg-gray-100 sm:w-32"
                        placeholder="0.00"
                    />
                </div>
            </div>
            <button @click="submit" class="w-full rounded bg-blue-600 px-4 py-2 text-sm text-white sm:w-auto" :disabled="form.processing">
                {{ form.processing ? tr('Guardando...', 'Saving...') : tr('Guardar cambios', 'Save Changes') }}
            </button>
        </div>
    </PathfinderLayout>
</template>
