<script setup>
import { computed, onMounted, ref } from 'vue'
import PathfinderLayout from '@/Layouts/PathfinderLayout.vue'
import { useGeneral } from '@/Composables/useGeneral'
import { createEventClubSettlement, createTreasuryMovement, fetchClubEventSettlements, fetchClubTreasury } from '@/Services/api'
import { ArrowPathIcon, BanknotesIcon, BuildingLibraryIcon, WalletIcon } from '@heroicons/vue/24/outline'

const { showToast } = useGeneral()

const props = defineProps({
    auth_user: Object,
})

const loading = ref(false)
const savingMovement = ref(false)
const treasury = ref({
    club: null,
    bank_info: null,
    accounts: [],
    summary: {},
    income_rows: [],
    movements: [],
})
const eventSettlementRows = ref([])
const incomeLocationFilter = ref('all')
const movementForm = ref({
    movement_type: 'cash_deposit',
    pay_to: 'club_budget',
    amount: '',
    movement_date: new Date().toISOString().slice(0, 10),
    reference: '',
    notes: '',
    proof: null,
})
const selectedSettlement = ref(null)
const settlementSaving = ref(false)
const settlementError = ref('')
const settlementForm = ref({
    deposited_at: new Date().toISOString().slice(0, 16),
    reference: '',
    notes: '',
    deposit_proof: null,
})

const summary = computed(() => treasury.value.summary || {})
const bankInfo = computed(() => treasury.value.bank_info || null)
const filteredIncomeRows = computed(() => {
    if (incomeLocationFilter.value === 'all') return treasury.value.income_rows || []
    return (treasury.value.income_rows || []).filter(row => row.location === incomeLocationFilter.value)
})

const formatMoney = (value) => Number(value || 0).toFixed(2)
const formatDate = (value) => value ? String(value).slice(0, 10) : '—'
const locationLabel = (value) => value === 'bank' ? 'Banco' : value === 'external' ? 'Externo' : value === 'internal' ? 'Interno' : 'Efectivo'
const accountLabel = (row) => row?.account_label || treasury.value.accounts.find(account => account.value === row?.pay_to)?.label || row?.pay_to || '—'
const paymentTypesLabel = (types) => (types || []).filter(Boolean).join(', ')
const movementLabel = (value) => ({
    cash_deposit: 'Depósito a banco',
    cash_withdrawal: 'Retiro de banco',
    event_settlement: 'Transferencia de evento',
})[value] || value

const bankInfoLines = (info) => {
    if (!info) return []
    return [
        info.bank_name ? `Banco: ${info.bank_name}` : null,
        info.account_holder ? `Titular: ${info.account_holder}` : null,
        info.account_type ? `Tipo: ${info.account_type}` : null,
        info.account_number ? `Cuenta: ${info.account_number}` : null,
        info.routing_number ? `Routing: ${info.routing_number}` : null,
        info.zelle_email ? `Zelle: ${info.zelle_email}` : null,
        info.zelle_phone ? `Zelle tel: ${info.zelle_phone}` : null,
    ].filter(Boolean)
}

async function loadData() {
    loading.value = true
    try {
        const [treasuryData, settlementsData] = await Promise.all([
            fetchClubTreasury(),
            fetchClubEventSettlements(),
        ])
        treasury.value = {
            club: treasuryData.club,
            bank_info: treasuryData.bank_info,
            accounts: treasuryData.accounts || [],
            summary: treasuryData.summary || {},
            income_rows: treasuryData.income_rows || [],
            movements: treasuryData.movements || [],
        }
        eventSettlementRows.value = Array.isArray(settlementsData?.data) ? settlementsData.data : []
    } catch (error) {
        console.error(error)
        showToast(error?.response?.data?.message || 'No se pudo cargar tesorería', 'error')
    } finally {
        loading.value = false
    }
}

const onMovementProofSelected = (event) => {
    movementForm.value.proof = event.target.files?.[0] || null
}

async function saveMovement() {
    savingMovement.value = true
    try {
        await createTreasuryMovement(movementForm.value)
        showToast('Movimiento registrado', 'success')
        movementForm.value = {
            movement_type: 'cash_deposit',
            pay_to: 'club_budget',
            amount: '',
            movement_date: new Date().toISOString().slice(0, 10),
            reference: '',
            notes: '',
            proof: null,
        }
        await loadData()
    } catch (error) {
        console.error(error)
        showToast(error?.response?.data?.message || 'No se pudo registrar el movimiento', 'error')
    } finally {
        savingMovement.value = false
    }
}

const openSettlementModal = (row) => {
    selectedSettlement.value = row
    settlementError.value = ''
    settlementForm.value = {
        deposited_at: new Date().toISOString().slice(0, 16),
        reference: '',
        notes: '',
        deposit_proof: null,
    }
}

const closeSettlementModal = () => {
    selectedSettlement.value = null
    settlementError.value = ''
}

const onSettlementProofSelected = (event) => {
    settlementForm.value.deposit_proof = event.target.files?.[0] || null
}

async function saveEventSettlement() {
    if (!selectedSettlement.value) return
    settlementSaving.value = true
    settlementError.value = ''
    try {
        await createEventClubSettlement(selectedSettlement.value.event_id, {
            club_id: selectedSettlement.value.club_id,
            deposited_at: settlementForm.value.deposited_at,
            reference: settlementForm.value.reference,
            notes: settlementForm.value.notes,
            deposit_proof: settlementForm.value.deposit_proof,
        })
        showToast('Transferencia de evento registrada', 'success')
        closeSettlementModal()
        await loadData()
    } catch (error) {
        console.error(error)
        const errors = error?.response?.data?.errors
        const firstError = errors ? Object.values(errors)[0] : null
        settlementError.value = Array.isArray(firstError)
            ? firstError[0]
            : (firstError || error?.response?.data?.message || 'No se pudo registrar la transferencia')
        showToast(settlementError.value, 'error')
    } finally {
        settlementSaving.value = false
    }
}

onMounted(loadData)
</script>

<template>
    <PathfinderLayout>
        <template #title>Tesorería</template>

        <div class="space-y-6">
            <section class="rounded-lg border bg-white p-5 shadow-sm">
                <div class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
                    <div>
                        <h1 class="text-xl font-semibold text-gray-900">Tesorería del club</h1>
                        <p class="mt-1 text-sm text-gray-600">
                            Control de efectivo, banco y transferencias externas de eventos.
                        </p>
                    </div>
                    <button
                        type="button"
                        class="inline-flex items-center gap-2 rounded border border-gray-300 px-3 py-2 text-sm text-gray-700 hover:bg-gray-50 disabled:opacity-60"
                        :disabled="loading"
                        @click="loadData"
                    >
                        <ArrowPathIcon class="h-4 w-4" />
                        {{ loading ? 'Cargando...' : 'Actualizar' }}
                    </button>
                </div>
            </section>

            <section class="grid gap-4 md:grid-cols-3">
                <div class="rounded-lg border bg-white p-4 shadow-sm">
                    <div class="flex items-center gap-2 text-sm text-gray-500">
                        <WalletIcon class="h-5 w-5" />
                        Efectivo disponible
                    </div>
                    <div class="mt-2 text-2xl font-semibold text-gray-900">${{ formatMoney(summary.cash_balance) }}</div>
                </div>
                <div class="rounded-lg border bg-white p-4 shadow-sm">
                    <div class="flex items-center gap-2 text-sm text-gray-500">
                        <BuildingLibraryIcon class="h-5 w-5" />
                        Banco disponible
                    </div>
                    <div class="mt-2 text-2xl font-semibold text-gray-900">${{ formatMoney(summary.bank_balance) }}</div>
                </div>
                <div class="rounded-lg border bg-white p-4 shadow-sm">
                    <div class="flex items-center gap-2 text-sm text-gray-500">
                        <BanknotesIcon class="h-5 w-5" />
                        Total disponible
                    </div>
                    <div class="mt-2 text-2xl font-semibold text-gray-900">${{ formatMoney(summary.total_available) }}</div>
                </div>
            </section>

            <section class="rounded-lg border bg-white p-5 shadow-sm">
                <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                    <div>
                        <h2 class="text-lg font-semibold text-gray-900">Cuenta bancaria del club</h2>
                        <p class="mt-1 text-sm text-gray-600">Cuenta física usada para pagos electrónicos y depósitos del club.</p>
                    </div>
                    <a :href="route('club.settings')" class="text-sm font-medium text-blue-700 hover:underline">
                        Configurar cuenta
                    </a>
                </div>
                <div v-if="bankInfo" class="mt-4 rounded-lg border border-blue-100 bg-blue-50 p-3 text-sm text-blue-900">
                    <div class="font-semibold">{{ bankInfo.label || 'Cuenta bancaria del club' }}</div>
                    <div class="mt-2 grid gap-1 md:grid-cols-2">
                        <div v-for="line in bankInfoLines(bankInfo)" :key="line">{{ line }}</div>
                    </div>
                    <div v-if="bankInfo.deposit_instructions" class="mt-2 text-xs text-blue-800">
                        {{ bankInfo.deposit_instructions }}
                    </div>
                </div>
                <div v-else class="mt-4 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800">
                    El club no tiene cuenta bancaria registrada. No se deben recibir pagos electrónicos hasta configurarla.
                </div>
            </section>

            <section class="rounded-lg border bg-white p-5 shadow-sm">
                <h2 class="text-lg font-semibold text-gray-900">Depósitos y retiros</h2>
                <form class="mt-4 grid gap-4 md:grid-cols-2" @submit.prevent="saveMovement">
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Tipo</label>
                        <select v-model="movementForm.movement_type" class="mt-1 w-full rounded border px-3 py-2 text-sm">
                            <option value="cash_deposit">Depositar efectivo a banco</option>
                            <option value="cash_withdrawal">Retirar efectivo del banco</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Cuenta</label>
                        <select v-model="movementForm.pay_to" class="mt-1 w-full rounded border px-3 py-2 text-sm">
                            <option v-for="account in treasury.accounts" :key="account.value" :value="account.value">{{ account.label }}</option>
                            <option v-if="!treasury.accounts.length" value="club_budget">Presupuesto del club</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Monto</label>
                        <input v-model="movementForm.amount" type="number" min="0.01" step="0.01" class="mt-1 w-full rounded border px-3 py-2 text-sm" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Fecha</label>
                        <input v-model="movementForm.movement_date" type="date" class="mt-1 w-full rounded border px-3 py-2 text-sm" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Referencia</label>
                        <input v-model="movementForm.reference" type="text" class="mt-1 w-full rounded border px-3 py-2 text-sm" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Comprobante</label>
                        <input type="file" accept="image/*,application/pdf" class="mt-1 block w-full text-sm" @change="onMovementProofSelected" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Notas</label>
                        <textarea v-model="movementForm.notes" rows="2" class="mt-1 w-full rounded border px-3 py-2 text-sm"></textarea>
                    </div>
                    <div class="md:col-span-2">
                        <button type="submit" class="rounded bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-60" :disabled="savingMovement">
                            {{ savingMovement ? 'Guardando...' : 'Registrar movimiento' }}
                        </button>
                    </div>
                </form>
            </section>

            <section class="rounded-lg border bg-white p-5 shadow-sm">
                <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                    <div>
                        <h2 class="text-lg font-semibold text-gray-900">Ingresos por ubicación</h2>
                        <p class="mt-1 text-sm text-gray-600">Los ingresos se clasifican por tipo de pago: efectivo o banco.</p>
                    </div>
                    <select v-model="incomeLocationFilter" class="rounded border px-3 py-2 text-sm">
                        <option value="all">Todos</option>
                        <option value="cash">Efectivo</option>
                        <option value="bank">Banco</option>
                    </select>
                </div>
                <div class="mt-4 overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-gray-50 text-left text-gray-600">
                            <tr>
                                <th class="px-3 py-2">Fecha</th>
                                <th class="px-3 py-2">Concepto</th>
                                <th class="px-3 py-2">Cuenta</th>
                                <th class="px-3 py-2">Pagador</th>
                                <th class="px-3 py-2">Tipo</th>
                                <th class="px-3 py-2">Ubicación</th>
                                <th class="px-3 py-2 text-right">Monto</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in filteredIncomeRows" :key="row.id" class="border-t">
                                <td class="px-3 py-2">{{ formatDate(row.payment_date) }}</td>
                                <td class="px-3 py-2">
                                    <div>{{ row.concept_name || '—' }}</div>
                                    <div v-if="row.event_title" class="text-xs text-gray-500">{{ row.event_title }}</div>
                                </td>
                                <td class="px-3 py-2">
                                    <div>{{ accountLabel(row) }}</div>
                                    <div v-if="row.pay_to && row.pay_to !== accountLabel(row)" class="text-xs text-gray-500">{{ row.pay_to }}</div>
                                </td>
                                <td class="px-3 py-2">{{ row.payer_name }}</td>
                                <td class="px-3 py-2 capitalize">{{ row.payment_type }}</td>
                                <td class="px-3 py-2">{{ locationLabel(row.location) }}</td>
                                <td class="px-3 py-2 text-right font-medium">${{ formatMoney(row.amount_paid) }}</td>
                            </tr>
                            <tr v-if="!filteredIncomeRows.length">
                                <td colspan="7" class="px-3 py-6 text-center text-gray-500">No hay ingresos para este filtro.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="rounded-lg border bg-white p-5 shadow-sm">
                <h2 class="text-lg font-semibold text-gray-900">Transferencias de eventos</h2>
                <div v-if="!eventSettlementRows.length" class="mt-4 rounded border border-dashed border-gray-200 bg-gray-50 p-4 text-sm text-gray-500">
                    No hay transferencias de eventos pendientes.
                </div>
                <div v-else class="mt-4 space-y-4">
                    <article v-for="row in eventSettlementRows" :key="row.event_id" class="rounded-lg border border-gray-200 p-4">
                        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                            <div class="space-y-3">
                                <div>
                                    <h3 class="font-semibold text-gray-900">{{ row.event_title }}</h3>
                                    <div class="text-xs text-gray-500">{{ row.organizer_label }}</div>
                                </div>
                                <div class="rounded border border-gray-200 bg-gray-50 p-3 text-sm">
                                    <div v-for="item in row.pending_settlement_breakdown || []" :key="`${row.event_id}-${item.component_id || item.label}`" class="flex items-center justify-between gap-3">
                                        <span>{{ item.label }}</span>
                                        <span class="font-medium">${{ formatMoney(item.amount) }}</span>
                                    </div>
                                </div>
                                <details v-if="row.paid_members?.length" class="rounded border border-gray-200 bg-white p-3 text-sm">
                                    <summary class="cursor-pointer list-none">
                                        <div class="flex items-center justify-between gap-3">
                                            <span class="font-medium text-gray-900">Miembros con pagos</span>
                                            <span class="text-xs text-gray-600">
                                                {{ row.paid_members_count }} miembros · ${{ formatMoney(row.paid_members_total) }}
                                            </span>
                                        </div>
                                    </summary>
                                    <div class="mt-3 divide-y divide-gray-100">
                                        <div v-for="member in row.paid_members" :key="`${row.event_id}-${member.member_id || member.name}`" class="py-2 first:pt-0 last:pb-0">
                                            <div class="flex items-center justify-between gap-3">
                                                <div class="font-medium text-gray-900">{{ member.name }}</div>
                                                <div class="font-semibold text-gray-900">${{ formatMoney(member.total_paid) }}</div>
                                            </div>
                                            <div class="mt-0.5 text-xs text-gray-500">
                                                {{ member.payments_count }} pago{{ member.payments_count === 1 ? '' : 's' }}
                                                <span v-if="member.last_payment_date"> · Último: {{ formatDate(member.last_payment_date) }}</span>
                                                <span v-if="paymentTypesLabel(member.payment_types)"> · {{ paymentTypesLabel(member.payment_types) }}</span>
                                            </div>
                                            <div v-if="member.breakdown?.length" class="mt-2 flex flex-wrap gap-2 text-xs text-gray-600">
                                                <span v-for="item in member.breakdown" :key="`${member.member_id || member.name}-${item.label}`" class="rounded bg-gray-100 px-2 py-1">
                                                    {{ item.label }}: ${{ formatMoney(item.amount) }}
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                </details>
                                <div v-if="row.organizer_bank_info" class="rounded border border-blue-100 bg-blue-50 p-3 text-sm text-blue-900">
                                    <div class="font-semibold">{{ row.organizer_bank_info.label || 'Cuenta de destino' }}</div>
                                    <div class="mt-1 grid gap-1 md:grid-cols-2">
                                        <div v-for="line in bankInfoLines(row.organizer_bank_info)" :key="`${row.event_id}-${line}`">{{ line }}</div>
                                    </div>
                                </div>
                            </div>
                            <div class="w-full space-y-3 lg:max-w-xs">
                                <div class="rounded bg-gray-50 px-3 py-2">
                                    <div class="text-xs text-gray-500">Pendiente</div>
                                    <div class="text-xl font-semibold text-gray-900">${{ formatMoney(row.pending_settlement_amount) }}</div>
                                </div>
                                <button
                                    v-if="Number(row.pending_settlement_amount || 0) > 0"
                                    type="button"
                                    class="w-full rounded bg-blue-600 px-3 py-2 text-sm font-medium text-white hover:bg-blue-700"
                                    @click="openSettlementModal(row)"
                                >
                                    Transferir a organización
                                </button>
                                <div v-if="row.settlement_receipts?.length" class="space-y-1 text-sm">
                                    <a v-for="receipt in row.settlement_receipts" :key="receipt.id" :href="receipt.receipt_url" class="block text-blue-700 hover:underline">
                                        {{ receipt.receipt_number }}
                                    </a>
                                </div>
                            </div>
                        </div>
                    </article>
                </div>
            </section>

            <section class="rounded-lg border bg-white p-5 shadow-sm">
                <h2 class="text-lg font-semibold text-gray-900">Movimientos registrados</h2>
                <div class="mt-4 overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-gray-50 text-left text-gray-600">
                            <tr>
                                <th class="px-3 py-2">Fecha</th>
                                <th class="px-3 py-2">Tipo</th>
                                <th class="px-3 py-2">Movimiento</th>
                                <th class="px-3 py-2">Referencia</th>
                                <th class="px-3 py-2 text-right">Monto</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in treasury.movements" :key="row.id" class="border-t">
                                <td class="px-3 py-2">{{ formatDate(row.movement_date) }}</td>
                                <td class="px-3 py-2">{{ movementLabel(row.movement_type) }}</td>
                                <td class="px-3 py-2">{{ locationLabel(row.from_location) }} → {{ locationLabel(row.to_location) }}</td>
                                <td class="px-3 py-2">
                                    <div>{{ row.reference || row.receipt_number || '—' }}</div>
                                    <a v-if="row.proof_url" :href="row.proof_url" target="_blank" rel="noopener" class="text-xs text-blue-700 hover:underline">Ver comprobante</a>
                                </td>
                                <td class="px-3 py-2 text-right font-medium">${{ formatMoney(row.amount) }}</td>
                            </tr>
                            <tr v-if="!treasury.movements.length">
                                <td colspan="5" class="px-3 py-6 text-center text-gray-500">No hay movimientos registrados.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>

        <div v-if="selectedSettlement" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
            <div class="w-full max-w-xl rounded-lg border bg-white shadow-xl">
                <div class="flex items-start justify-between gap-4 border-b px-5 py-4">
                    <div>
                        <h2 class="text-lg font-semibold text-gray-900">Transferir a organización</h2>
                        <p class="mt-1 text-sm text-gray-600">{{ selectedSettlement.event_title }}</p>
                    </div>
                    <button type="button" class="text-gray-500 hover:text-gray-700" @click="closeSettlementModal">×</button>
                </div>
                <div class="p-5 space-y-4">
                    <div class="rounded border border-gray-200 bg-gray-50 p-3">
                        <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">Monto máximo depositable</div>
                        <div class="mt-1 text-2xl font-semibold text-gray-900">${{ formatMoney(selectedSettlement.pending_settlement_amount) }}</div>
                    </div>
                    <div class="grid gap-4 md:grid-cols-2">
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Fecha</label>
                            <input v-model="settlementForm.deposited_at" type="datetime-local" class="mt-1 w-full rounded border px-3 py-2 text-sm" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Referencia</label>
                            <input v-model="settlementForm.reference" type="text" class="mt-1 w-full rounded border px-3 py-2 text-sm" />
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Comprobante</label>
                        <input type="file" accept="image/*,application/pdf" class="mt-1 block w-full text-sm" @change="onSettlementProofSelected" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Notas</label>
                        <textarea v-model="settlementForm.notes" rows="3" class="mt-1 w-full rounded border px-3 py-2 text-sm"></textarea>
                    </div>
                    <div v-if="settlementError" class="text-sm text-red-600">{{ settlementError }}</div>
                </div>
                <div class="flex justify-end gap-3 border-t px-5 py-4">
                    <button type="button" class="rounded border border-gray-300 px-4 py-2 text-sm text-gray-700 hover:bg-gray-50" @click="closeSettlementModal">
                        Cancelar
                    </button>
                    <button type="button" class="rounded bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-60" :disabled="settlementSaving" @click="saveEventSettlement">
                        {{ settlementSaving ? 'Guardando...' : 'Registrar transferencia' }}
                    </button>
                </div>
            </div>
        </div>
    </PathfinderLayout>
</template>
