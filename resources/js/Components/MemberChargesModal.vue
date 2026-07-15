<script setup>
import { computed, ref, watch } from 'vue'
import { useLocale } from '@/Composables/useLocale'

const props = defineProps({
    show: Boolean,
    member: { type: Object, default: null },
})
const emit = defineEmits(['close', 'updated'])
const { tr } = useLocale()

const loading = ref(false)
const saving = ref(false)
const data = ref(null)
const error = ref('')
const editing = ref(null)
const payment = ref(null)

const money = (value) => `$${Number(value || 0).toFixed(2)}`
const memberId = computed(() => props.member?.member_id || props.member?.id)
const charges = computed(() => data.value?.charges || [])
const summary = computed(() => data.value?.summary || { expected: 0, paid: 0, remaining: 0 })

const load = async () => {
    if (!memberId.value) return
    loading.value = true
    error.value = ''
    try {
        const response = await window.axios.get(route('members.charges.index', memberId.value))
        data.value = response.data.data
    } catch (err) {
        error.value = err.response?.data?.message || tr('No se pudieron cargar los cargos.', 'Could not load charges.')
    } finally {
        loading.value = false
    }
}

watch(() => props.show, (show) => {
    if (show) {
        editing.value = null
        payment.value = null
        load()
    }
})

const beginEdit = (charge) => {
    editing.value = {
        ...charge,
        payment_expected_by: charge.due_date || '',
    }
}

const saveEdit = async () => {
    saving.value = true
    error.value = ''
    try {
        await window.axios.put(route('members.charges.update', { member: memberId.value, paymentConcept: editing.value.id }), {
            concept: editing.value.concept,
            amount: editing.value.amount,
            payment_expected_by: editing.value.payment_expected_by || null,
            type: editing.value.type,
        })
        editing.value = null
        await load()
        emit('updated')
    } catch (err) {
        error.value = err.response?.data?.message || tr('No se pudo actualizar el cargo.', 'Could not update the charge.')
    } finally {
        saving.value = false
    }
}

const remove = async (charge) => {
    if (!window.confirm(tr('¿Eliminar este cargo solo para este miembro?', 'Remove this charge only for this member?'))) return

    saving.value = true
    error.value = ''
    try {
        await window.axios.delete(route('members.charges.destroy', { member: memberId.value, paymentConcept: charge.id }))
        await load()
        emit('updated')
    } catch (err) {
        error.value = err.response?.data?.message || tr('No se pudo eliminar el cargo.', 'Could not remove the charge.')
    } finally {
        saving.value = false
    }
}

const openPayment = (charge) => {
    const eventCharges = charge.event_id
        ? charges.value.filter((row) => row.event_id === charge.event_id && row.remaining_amount > 0)
        : [charge]
    const required = eventCharges.filter((row) => row.event_required)
    const selected = charge.event_id
        ? [...new Map([...required, charge].map((row) => [row.id, row])).values()]
        : [charge]
    payment.value = {
        charge,
        conceptIds: selected.map((row) => row.id),
        amount: selected.reduce((sum, row) => sum + Number(row.remaining_amount || 0), 0).toFixed(2),
        payment_type: 'cash',
        payment_date: new Date().toISOString().slice(0, 10),
        notes: '',
    }
}

const receivePayment = async () => {
    saving.value = true
    error.value = ''
    try {
        const selected = payment.value.conceptIds
        const payload = {
            member_id: memberId.value,
            amount_paid: payment.value.amount,
            payment_type: payment.value.payment_type,
            payment_date: payment.value.payment_date,
            notes: payment.value.notes || null,
        }
        if (payment.value.charge.event_id) payload.event_concept_ids = selected
        else payload.payment_concept_id = payment.value.charge.id
        await window.axios.post(route('club.finance-engine.income.store'), payload)
        payment.value = null
        await load()
        emit('updated')
    } catch (err) {
        error.value = err.response?.data?.message || tr('No se pudo registrar el pago.', 'Could not record the payment.')
    } finally {
        saving.value = false
    }
}
</script>

<template>
    <div v-if="show" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" @click.self="emit('close')">
        <section class="max-h-[90vh] w-full max-w-4xl overflow-y-auto rounded-xl bg-white shadow-xl">
            <header class="sticky top-0 z-10 flex items-start justify-between border-b bg-white px-5 py-4">
                <div>
                    <h2 class="text-lg font-bold text-gray-900">{{ tr('Cargos del miembro', 'Member charges') }}</h2>
                    <p class="text-sm text-gray-600">{{ data?.member?.name || member?.applicant_name }}</p>
                </div>
                <button class="text-2xl leading-none text-gray-500 hover:text-gray-900" @click="emit('close')">×</button>
            </header>

            <div class="p-5">
                <p v-if="error" class="mb-4 rounded border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-800">{{ error }}</p>
                <div v-if="loading" class="py-10 text-center text-sm text-gray-500">{{ tr('Cargando cargos...', 'Loading charges...') }}</div>
                <template v-else>
                    <div class="mb-4 grid grid-cols-3 gap-3 text-sm">
                        <div class="rounded bg-gray-50 p-3"><span class="block text-gray-500">{{ tr('Esperado', 'Expected') }}</span><strong>{{ money(summary.expected) }}</strong></div>
                        <div class="rounded bg-emerald-50 p-3"><span class="block text-emerald-700">{{ tr('Pagado', 'Paid') }}</span><strong>{{ money(summary.paid) }}</strong></div>
                        <div class="rounded bg-amber-50 p-3"><span class="block text-amber-700">{{ tr('Pendiente', 'Due') }}</span><strong>{{ money(summary.remaining) }}</strong></div>
                    </div>
                    <p class="mb-3 text-xs text-gray-500">{{ tr('Los cargos de eventos se administran en el Planificador de eventos; los demás se pueden editar o eliminar aquí.', 'Event charges are managed in Event Planner; other charges can be edited or removed here.') }}</p>
                    <div v-if="!charges.length" class="rounded border border-dashed p-8 text-center text-sm text-gray-500">{{ tr('No hay cargos aplicables para este miembro.', 'There are no applicable charges for this member.') }}</div>
                    <div v-else class="space-y-3">
                        <article v-for="charge in charges" :key="charge.id" class="rounded-lg border p-4">
                            <template v-if="editing?.id === charge.id">
                                <div class="grid gap-3 sm:grid-cols-2">
                                    <input v-model="editing.concept" class="rounded border px-3 py-2" :aria-label="tr('Concepto', 'Concept')" />
                                    <input v-model.number="editing.amount" type="number" min="0" step="0.01" class="rounded border px-3 py-2" :aria-label="tr('Monto', 'Amount')" />
                                    <input v-model="editing.payment_expected_by" type="date" class="rounded border px-3 py-2" />
                                    <select v-model="editing.type" class="rounded border px-3 py-2"><option value="mandatory">{{ tr('Obligatorio', 'Mandatory') }}</option><option value="optional">{{ tr('Opcional', 'Optional') }}</option></select>
                                </div>
                                <div class="mt-3 flex gap-2"><button class="rounded bg-blue-700 px-3 py-2 text-sm font-semibold text-white disabled:opacity-50" :disabled="saving" @click="saveEdit">{{ tr('Guardar', 'Save') }}</button><button class="rounded border px-3 py-2 text-sm" @click="editing = null">{{ tr('Cancelar', 'Cancel') }}</button></div>
                            </template>
                            <template v-else>
                                <div class="flex flex-col justify-between gap-3 sm:flex-row">
                                    <div>
                                        <div class="font-semibold text-gray-900">{{ charge.concept }}</div>
                                        <div class="mt-1 text-xs text-gray-500">{{ charge.event_title ? `${charge.event_title}${charge.event_component_label ? ` · ${charge.event_component_label}` : ''}` : charge.scope_label }}<span v-if="charge.due_date"> · {{ tr('Vence', 'Due') }}: {{ charge.due_date }}</span></div>
                                    </div>
                                    <div class="text-left sm:text-right"><div class="font-semibold">{{ money(charge.remaining_amount) }} <span class="font-normal text-gray-500">{{ tr('pendiente', 'due') }}</span></div><div class="text-xs text-gray-500">{{ tr('Pagado', 'Paid') }} {{ money(charge.paid_amount) }} / {{ money(charge.amount) }}</div></div>
                                </div>
                                <div class="mt-3 flex flex-wrap gap-2"><button v-if="charge.remaining_amount > 0" class="rounded bg-emerald-700 px-3 py-1.5 text-sm font-semibold text-white" @click="openPayment(charge)">{{ tr('Recibir pago', 'Receive payment') }}</button><button v-if="charge.can_manage" class="rounded border px-3 py-1.5 text-sm text-blue-700" @click="beginEdit(charge)">{{ tr('Editar', 'Edit') }}</button><button v-if="charge.can_manage" class="rounded border border-rose-200 px-3 py-1.5 text-sm text-rose-700" @click="remove(charge)">{{ tr('Eliminar', 'Remove') }}</button></div>
                            </template>
                        </article>
                    </div>
                </template>
            </div>
        </section>
    </div>

    <div v-if="payment" class="fixed inset-0 z-[60] flex items-center justify-center bg-black/50 p-4" @click.self="payment = null">
        <section class="w-full max-w-md rounded-xl bg-white p-5 shadow-xl">
            <h3 class="text-lg font-bold">{{ tr('Recibir pago', 'Receive payment') }}</h3>
            <p class="mt-1 text-sm text-gray-600">{{ payment.charge.concept }}</p>
            <div class="mt-4 space-y-3"><input v-model.number="payment.amount" type="number" min="0.01" step="0.01" class="w-full rounded border px-3 py-2" /><select v-model="payment.payment_type" class="w-full rounded border px-3 py-2"><option value="cash">{{ tr('Efectivo', 'Cash') }}</option><option value="check">{{ tr('Cheque', 'Check') }}</option><option value="transfer">{{ tr('Transferencia', 'Transfer') }}</option><option value="zelle">Zelle</option></select><input v-model="payment.payment_date" type="date" class="w-full rounded border px-3 py-2" /><textarea v-model="payment.notes" class="w-full rounded border px-3 py-2" :placeholder="tr('Nota opcional', 'Optional note')" /></div>
            <div class="mt-4 flex justify-end gap-2"><button class="rounded border px-3 py-2 text-sm" @click="payment = null">{{ tr('Cancelar', 'Cancel') }}</button><button class="rounded bg-emerald-700 px-3 py-2 text-sm font-semibold text-white disabled:opacity-50" :disabled="saving" @click="receivePayment">{{ saving ? tr('Guardando...', 'Saving...') : tr('Confirmar pago', 'Confirm payment') }}</button></div>
        </section>
    </div>
</template>
