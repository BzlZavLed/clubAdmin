<script setup>
import { computed, onMounted, ref } from 'vue'
import PathfinderLayout from '@/Layouts/PathfinderLayout.vue'
import { useGeneral } from '@/Composables/useGeneral'
import { useLocale } from '@/Composables/useLocale'
import { fetchStaffMoneyCustody, remitStaffMoneyCustody } from '@/Services/api'

const props = defineProps({
    auth_user: Object,
})

const { showToast } = useGeneral()
const { tr } = useLocale()

const loading = ref(false)
const saving = ref(false)
const custody = ref({
    club: null,
    held_total: 0,
    held_payments: [],
    pending_remittances: [],
})
const form = ref({
    remittance_method: 'cash',
    remittance_date: new Date().toISOString().slice(0, 10),
    remittance_reference: '',
    remittance_notes: '',
})

const heldPayments = computed(() => custody.value.held_payments || [])
const pendingRemittances = computed(() => custody.value.pending_remittances || [])
const heldTotal = computed(() => Number(custody.value.held_total || 0))
const canRemit = computed(() => heldPayments.value.length > 0 && Boolean(form.value.remittance_date) && !saving.value)

const formatMoney = (value) => Number(value || 0).toFixed(2)
const formatDate = (value) => value ? String(value).slice(0, 10) : '—'
const methodLabel = (value) => ({
    cash: tr('Efectivo', 'Cash'),
    zelle: 'Zelle',
    transfer: tr('Transferencia', 'Transfer'),
})[value] || value

async function loadData() {
    loading.value = true
    try {
        custody.value = await fetchStaffMoneyCustody()
    } catch (error) {
        console.error(error)
        showToast(error?.response?.data?.message || tr('No se pudo cargar el dinero en custodia.', 'Could not load money in custody.'), 'error')
    } finally {
        loading.value = false
    }
}

async function remitAll() {
    if (!canRemit.value) return
    saving.value = true
    try {
        await remitStaffMoneyCustody({
            payment_ids: heldPayments.value.map(payment => payment.id),
            remittance_method: form.value.remittance_method,
            remittance_date: form.value.remittance_date,
            remittance_reference: form.value.remittance_reference,
            remittance_notes: form.value.remittance_notes,
        })
        showToast(tr('Entrega marcada como pendiente de validación.', 'Remittance marked as pending validation.'), 'success')
        form.value = {
            remittance_method: 'cash',
            remittance_date: new Date().toISOString().slice(0, 10),
            remittance_reference: '',
            remittance_notes: '',
        }
        await loadData()
    } catch (error) {
        console.error(error)
        showToast(error?.response?.data?.message || tr('No se pudo marcar la entrega.', 'Could not mark the remittance.'), 'error')
    } finally {
        saving.value = false
    }
}

onMounted(loadData)
</script>

<template>
    <PathfinderLayout :auth_user="props.auth_user">
        <template #title>{{ tr('Dinero en custodia', 'Money in Custody') }}</template>

        <div class="mx-auto max-w-6xl space-y-6 p-4 sm:p-6">
            <section class="rounded-lg border bg-white p-4 shadow-sm sm:p-5">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <h1 class="text-xl font-semibold text-gray-900">{{ tr('Dinero en custodia', 'Money in Custody') }}</h1>
                        <p class="mt-1 text-sm text-gray-600">
                            {{ tr('Cuotas generadas desde asistencia que todavia estan bajo tu responsabilidad hasta que las entregues y el director las valide.', 'Dues generated from attendance that remain under your responsibility until you remit them and the director validates them.') }}
                        </p>
                    </div>
                    <button
                        type="button"
                        class="rounded border px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
                        :disabled="loading"
                        @click="loadData"
                    >
                        {{ loading ? tr('Cargando...', 'Loading...') : tr('Actualizar', 'Refresh') }}
                    </button>
                </div>
            </section>

            <section class="grid gap-4 md:grid-cols-3">
                <article class="rounded-lg border bg-white p-4 shadow-sm md:col-span-1">
                    <p class="text-sm font-medium text-gray-600">{{ tr('Total en tus manos', 'Total Held by You') }}</p>
                    <div class="mt-2 text-3xl font-semibold text-gray-900">${{ formatMoney(heldTotal) }}</div>
                    <p class="mt-1 text-xs text-gray-500">{{ heldPayments.length }} {{ tr('cuotas pendientes de entrega', 'dues pending remittance') }}</p>
                </article>

                <article class="rounded-lg border bg-white p-4 shadow-sm md:col-span-2">
                    <h2 class="text-base font-semibold text-gray-900">{{ tr('Marcar entrega', 'Mark Remittance') }}</h2>
                    <div class="mt-4 grid gap-3 sm:grid-cols-3 sm:items-end">
                        <label class="block text-sm">
                            <span class="block min-h-5 font-medium text-gray-700">{{ tr('Metodo', 'Method') }}</span>
                            <select v-model="form.remittance_method" class="mt-1 h-10 w-full rounded border-gray-300 text-sm">
                                <option value="cash">{{ tr('Efectivo', 'Cash') }}</option>
                                <option value="zelle">Zelle</option>
                                <option value="transfer">{{ tr('Transferencia', 'Transfer') }}</option>
                            </select>
                        </label>
                        <label class="block text-sm">
                            <span class="block min-h-5 font-medium text-gray-700">{{ tr('Fecha de transferencia', 'Transfer Date') }}</span>
                            <input v-model="form.remittance_date" type="date" class="mt-1 h-10 w-full rounded border-gray-300 text-sm" />
                        </label>
                        <label class="block text-sm">
                            <span class="block min-h-5 font-medium text-gray-700">{{ tr('Referencia', 'Reference') }}</span>
                            <input v-model="form.remittance_reference" type="text" class="mt-1 h-10 w-full rounded border-gray-300 text-sm" :placeholder="tr('Opcional', 'Optional')" />
                        </label>
                        <label class="block text-sm sm:col-span-3">
                            <span class="block min-h-5 font-medium text-gray-700">{{ tr('Notas', 'Notes') }}</span>
                            <textarea v-model="form.remittance_notes" rows="2" class="mt-1 w-full rounded border-gray-300 text-sm" :placeholder="tr('Detalle corto de la entrega...', 'Short remittance detail...')"></textarea>
                        </label>
                    </div>
                    <div class="mt-4 flex justify-end">
                        <button
                            type="button"
                            class="rounded bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700 disabled:cursor-not-allowed disabled:opacity-50"
                            :disabled="!canRemit"
                            @click="remitAll"
                        >
                            {{ saving ? tr('Guardando...', 'Saving...') : tr('Marcar todo como entregado', 'Mark All as Remitted') }}
                        </button>
                    </div>
                </article>
            </section>

            <section class="rounded-lg border bg-white p-4 shadow-sm sm:p-5">
                <h2 class="text-base font-semibold text-gray-900">{{ tr('Cuotas en tus manos', 'Dues Currently Held') }}</h2>
                <div class="mt-4 space-y-3 sm:hidden">
                    <article v-for="payment in heldPayments" :key="payment.id" class="rounded border p-3">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <div class="font-medium text-gray-900">{{ payment.payer_name }}</div>
                                <div class="mt-1 text-xs text-gray-500">{{ formatDate(payment.payment_date) }}</div>
                            </div>
                            <div class="font-semibold text-gray-900">${{ formatMoney(payment.amount_paid) }}</div>
                        </div>
                        <a v-if="payment.receipt_url" :href="payment.receipt_url" target="_blank" rel="noopener" class="mt-2 inline-block text-xs text-blue-700 hover:underline">
                            {{ payment.receipt_number }}
                        </a>
                    </article>
                    <div v-if="!heldPayments.length" class="rounded border border-dashed p-4 text-center text-sm text-gray-500">
                        {{ tr('No tienes cuotas en custodia.', 'You are not holding any dues.') }}
                    </div>
                </div>
                <div class="mt-4 hidden overflow-x-auto sm:block">
                    <table class="min-w-full text-sm">
                        <thead class="bg-gray-50 text-left text-gray-600">
                            <tr>
                                <th class="px-3 py-2">{{ tr('Fecha', 'Date') }}</th>
                                <th class="px-3 py-2">{{ tr('Miembro', 'Member') }}</th>
                                <th class="px-3 py-2">{{ tr('Recibo', 'Receipt') }}</th>
                                <th class="px-3 py-2 text-right">{{ tr('Monto', 'Amount') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="payment in heldPayments" :key="payment.id" class="border-t">
                                <td class="px-3 py-2">{{ formatDate(payment.payment_date) }}</td>
                                <td class="px-3 py-2">{{ payment.payer_name }}</td>
                                <td class="px-3 py-2">
                                    <a v-if="payment.receipt_url" :href="payment.receipt_url" target="_blank" rel="noopener" class="text-blue-700 hover:underline">
                                        {{ payment.receipt_number }}
                                    </a>
                                    <span v-else>—</span>
                                </td>
                                <td class="px-3 py-2 text-right font-medium">${{ formatMoney(payment.amount_paid) }}</td>
                            </tr>
                            <tr v-if="!heldPayments.length">
                                <td colspan="4" class="px-3 py-6 text-center text-gray-500">{{ tr('No tienes cuotas en custodia.', 'You are not holding any dues.') }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="rounded-lg border bg-white p-4 shadow-sm sm:p-5">
                <h2 class="text-base font-semibold text-gray-900">{{ tr('Entregas esperando validacion', 'Remittances Awaiting Validation') }}</h2>
                <div class="mt-4 space-y-3">
                    <article v-for="batch in pendingRemittances" :key="batch.batch_id" class="rounded border bg-amber-50 p-3">
                        <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                            <div>
                                <div class="font-semibold text-gray-900">${{ formatMoney(batch.amount) }} · {{ methodLabel(batch.remittance_method) }}</div>
                                <div class="mt-1 text-xs text-gray-600">{{ formatDate(batch.remitted_at) }} · {{ batch.count }} {{ tr('cuotas', 'dues') }}</div>
                                <div v-if="batch.remittance_reference" class="mt-1 text-xs text-gray-600">{{ tr('Referencia', 'Reference') }}: {{ batch.remittance_reference }}</div>
                            </div>
                            <span class="rounded-full bg-amber-100 px-2 py-1 text-xs font-medium text-amber-800">{{ tr('Pendiente de director', 'Director pending') }}</span>
                        </div>
                    </article>
                    <div v-if="!pendingRemittances.length" class="rounded border border-dashed p-4 text-center text-sm text-gray-500">
                        {{ tr('No hay entregas pendientes de validacion.', 'No remittances are awaiting validation.') }}
                    </div>
                </div>
            </section>
        </div>
    </PathfinderLayout>
</template>
