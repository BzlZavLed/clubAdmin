<script setup>
import { computed, nextTick, onMounted, ref, watch } from 'vue'
import PathfinderLayout from '@/Layouts/PathfinderLayout.vue'
import { useGeneral } from '@/Composables/useGeneral'
import { useLocale } from '@/Composables/useLocale'
import {
    fetchFinanceEngineAccounting,
    fetchFinanceEngineMovements,
    createFinanceEngineEventSettlement,
    createFinanceEngineTransfer,
    reverseFinanceEngineExpense,
    reverseFinanceEnginePayment,
    reverseFinanceEngineReimbursement,
    validateFinanceEngineStaffRemittance,
} from '@/Services/api'
import {
    ArrowPathIcon,
    BanknotesIcon,
    BuildingLibraryIcon,
    ChartBarIcon,
    ExclamationTriangleIcon,
} from '@heroicons/vue/24/outline'

const props = defineProps({
    auth_user: { type: Object, required: true },
})

const { showToast } = useGeneral()
const { tr } = useLocale()

const today = () => new Date().toISOString().slice(0, 10)

const defaultMovementForm = () => ({
    movement_type: 'cash_deposit',
    pay_to: 'club_budget',
    from_pay_to: 'club_budget',
    to_pay_to: '',
    location: 'cash',
    from_location: 'cash',
    to_location: 'bank',
    amount: '',
    movement_date: today(),
    reference: '',
    notes: '',
    proof: null,
})

const loading = ref(false)
const ledgerLoading = ref(false)
const savingMovement = ref(false)
const loadError = ref('')
const selectedClubId = ref(null)
const clubs = ref([])
const treasury = ref({
    club: null,
    bank_info: null,
    accounts: [],
    summary: {},
    pending_staff_remittances: [],
    movements: [],
})
const accountReport = ref(null)
const engineReport = ref(null)
const eventSettlementRows = ref([])
const movementForm = ref(defaultMovementForm())
const movementProofInput = ref(null)
const validatingBatchId = ref('')
const reversingKey = ref('')
const selectedCorrectionMovement = ref(null)
const correctionError = ref('')
const correctionForm = ref({
    correction_date: today(),
    reason: '',
})
const selectedSettlement = ref(null)
const settlementSaving = ref(false)
const settlementError = ref('')
const ledgerPage = ref(1)
const LEDGER_PAGE_SIZE = 25
const settlementForm = ref({
    deposited_at: new Date().toISOString().slice(0, 16),
    reference: '',
    notes: '',
    deposit_proof: null,
})
const ledgerFilters = ref({
    domain: 'all',
    account: 'all',
    date_from: '',
    date_to: '',
})

const canSelectClub = computed(() => props.auth_user?.profile_type === 'superadmin' || clubs.value.length > 1)
const currentClub = computed(() => treasury.value.club || clubs.value.find((club) => Number(club.id) === Number(selectedClubId.value)) || null)
const summary = computed(() => treasury.value.summary || {})
const summaryAccounts = computed(() => summary.value.accounts || [])
const isOperatingAccount = (account) => account !== 'reimbursement_to'
const operatingSummaryAccounts = computed(() => summaryAccounts.value.filter((account) => isOperatingAccount(account.account)))
const summaryTotals = computed(() => ({
    cash_balance: operatingSummaryAccounts.value.reduce((sum, account) => sum + Number(account.cash_balance || 0), 0),
    bank_balance: operatingSummaryAccounts.value.reduce((sum, account) => sum + Number(account.bank_balance || 0), 0),
    total_balance: operatingSummaryAccounts.value.reduce((sum, account) => sum + Number(account.total_available ?? (Number(account.cash_balance || 0) + Number(account.bank_balance || 0))), 0),
}))
const reimbursementBalanceSummary = computed(() => {
    const row = summaryAccounts.value.find((account) => account.account === 'reimbursement_to')

    return {
        cash_balance: Number(row?.cash_balance || 0),
        bank_balance: Number(row?.bank_balance || 0),
        total_available: Number(row?.total_available ?? (Number(row?.cash_balance || 0) + Number(row?.bank_balance || 0))),
    }
})
const pendingStaffRemittances = computed(() => treasury.value.pending_staff_remittances || [])
const recentTreasuryMovements = computed(() => treasury.value.movements || [])
const ledgerMovements = computed(() => engineReport.value?.movements || [])
const ledgerPageCount = computed(() => Math.max(Math.ceil(ledgerMovements.value.length / LEDGER_PAGE_SIZE), 1))
const paginatedLedgerMovements = computed(() => {
    const start = (ledgerPage.value - 1) * LEDGER_PAGE_SIZE

    return ledgerMovements.value.slice(start, start + LEDGER_PAGE_SIZE)
})
const ledgerPageStart = computed(() => ledgerMovements.value.length ? ((ledgerPage.value - 1) * LEDGER_PAGE_SIZE) + 1 : 0)
const ledgerPageEnd = computed(() => Math.min(ledgerPage.value * LEDGER_PAGE_SIZE, ledgerMovements.value.length))
const moduleNavItems = computed(() => [
    { href: '#accounting-transfers', label: tr('Transferencias', 'Transfers'), meta: recentTreasuryMovements.value.length },
    { href: '#accounting-balances', label: tr('Saldos', 'Balances'), meta: accountBalanceRows.value.length },
    { href: '#accounting-ledger', label: tr('Libro contable', 'Ledger'), meta: ledgerMovements.value.length },
    { href: '#accounting-events', label: tr('Eventos', 'Events'), meta: eventSettlementRows.value.length },
    { href: '#accounting-staff', label: tr('Staff', 'Staff'), meta: pendingStaffRemittances.value.length },
])

const accountOptions = computed(() => {
    const rows = new Map()
    ;(treasury.value.accounts || []).forEach((account) => {
        rows.set(account.value, {
            value: account.value,
            label: account.label || account.value,
        })
    })
    ;(summaryAccounts.value || []).forEach((account) => {
        if (!rows.has(account.account)) {
            rows.set(account.account, {
                value: account.account,
                label: account.account,
            })
        }
    })
    if (!rows.has('club_budget')) {
        rows.set('club_budget', { value: 'club_budget', label: tr('Presupuesto del club', 'Club budget') })
    }
    return Array.from(rows.values())
})

const accountBalanceRows = computed(() => {
    const labels = Object.fromEntries(accountOptions.value.map((account) => [account.value, account.label]))
    const source = summaryAccounts.value.length
        ? summaryAccounts.value
        : (accountReport.value?.accounts || [])

    return (source || []).map((row) => ({
        ...row,
        account: row.account,
        label: row.label || labels[row.account] || row.account,
        cash_balance: Number(row.cash_balance || 0),
        bank_balance: Number(row.bank_balance || 0),
        balance: Number(row.balance ?? row.total_available ?? (Number(row.cash_balance || 0) + Number(row.bank_balance || 0))),
    }))
})

const formatMoney = (value) => `$${Number(value || 0).toLocaleString('en-US', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
})}`
const formatDate = (value) => value ? String(value).slice(0, 10) : '—'
const accountLabel = (value) => accountOptions.value.find((account) => account.value === value)?.label || value || '—'
const locationLabel = (value) => ({
    bank: tr('Banco', 'Bank'),
    cash: tr('Efectivo', 'Cash'),
    staff_custody: tr('Custodia de staff', 'Staff custody'),
    pending: tr('Pendiente', 'Pending'),
})[value] || value || '—'
const movementTypeLabel = (value) => ({
    cash_deposit: tr('Depositar efectivo a banco', 'Deposit cash to bank'),
    cash_withdrawal: tr('Retirar efectivo de banco', 'Withdraw cash from bank'),
    account_transfer: tr('Transferir entre cuentas', 'Transfer between accounts'),
    event_settlement: tr('Transferencia de evento', 'Event transfer'),
})[value] || value || '—'
const domainLabel = (value) => ({
    income: tr('Ingreso', 'Income'),
    expense: tr('Gasto', 'Expense'),
    transfer: tr('Transferencia', 'Transfer'),
})[value] || value || '—'
const remittanceMethodLabel = (value) => ({
    cash: tr('Efectivo', 'Cash'),
    zelle: 'Zelle',
    transfer: tr('Transferencia', 'Transfer'),
})[value] || value || '—'
const paymentTypesLabel = (types) => (types || []).filter(Boolean).join(', ')

const bankInfoLines = (info) => {
    if (!info) return []
    return [
        info.bank_name ? `${tr('Banco', 'Bank')}: ${info.bank_name}` : null,
        info.account_holder ? `${tr('Titular', 'Account holder')}: ${info.account_holder}` : null,
        info.account_type ? `${tr('Tipo', 'Type')}: ${info.account_type}` : null,
        info.account_number ? `${tr('Cuenta', 'Account')}: ${info.account_number}` : null,
        info.routing_number ? `Routing: ${info.routing_number}` : null,
        info.zelle_email ? `Zelle: ${info.zelle_email}` : null,
        info.zelle_phone ? `${tr('Zelle tel', 'Zelle phone')}: ${info.zelle_phone}` : null,
    ].filter(Boolean)
}

const rowCounterparty = (row) =>
    row?.payer_name ||
    row?.member_display_name ||
    row?.staff_display_name ||
    row?.received_by_name ||
    row?.reimbursed_to ||
    row?.counterparty ||
    row?.created_by_name ||
    '—'

const cancellationSummary = (movement) => {
    const cancellation = movement?.cancellation || movement || {}
    if (cancellation.is_cancelled && cancellation.related_canceled_movement_id) {
        return `${tr('Cancelado por', 'Cancelled by')} #${cancellation.related_canceled_movement_id}`
    }
    if (cancellation.canceling_id) {
        return `${tr('Cancela', 'Cancels')} #${cancellation.canceling_id}`
    }
    if (cancellation.reversed_payment_id) {
        return `${tr('Revierte ingreso', 'Reverses income')} #${cancellation.reversed_payment_id}`
    }
    if (cancellation.reversed_expense_id) {
        return `${tr('Revierte gasto', 'Reverses expense')} #${cancellation.reversed_expense_id}`
    }
    return null
}
const correctionStatusLabel = (movement) => {
    if (movement?.canceling_id || movement?.cancellation?.canceling_id || movement?.status === 'cancellation') {
        return tr('Correccion', 'Correction')
    }

    if (movement?.is_cancelled || movement?.cancellation?.is_cancelled || movement?.status === 'cancelled') {
        return tr('Corregido', 'Corrected')
    }

    return movement?.status || 'posted'
}
const canCorrectMovement = (movement) => Boolean(movement?.can_reverse && movement?.correction_type)
const linkedMovementKey = (movement, type) => {
    const cancellation = movement?.cancellation || {}
    return type === 'correction'
        ? (movement?.related_canceled_movement_key || cancellation.related_canceled_movement_key)
        : (movement?.canceling_movement_key || cancellation.canceling_movement_key || cancellation.reversed_movement_key)
}
const setLedgerPage = (page) => {
    ledgerPage.value = Math.min(Math.max(Number(page) || 1, 1), ledgerPageCount.value)
}
const scrollToLedgerMovement = async (movementKey) => {
    const index = ledgerMovements.value.findIndex((movement) => movement.movement_id === movementKey)
    if (index >= 0) {
        setLedgerPage(Math.floor(index / LEDGER_PAGE_SIZE) + 1)
        await nextTick()
    }

    const target = Array.from(document.querySelectorAll('[data-ledger-movement]'))
        .find((element) => element.dataset.ledgerMovement === movementKey && element.offsetParent !== null)

    if (!target) {
        showToast(tr('Ese movimiento no esta en los filtros actuales del libro.', 'That movement is not in the current ledger filters.'), 'error')
        return
    }

    target.scrollIntoView({ behavior: 'smooth', block: 'center' })
}

const movementDescription = (movement) => {
    if (movement?.domain === 'transfer') {
        return [
            `${movement.from_account_label || accountLabel(movement.from_account)}`,
            `(${locationLabel(movement.from_location)})`,
            '→',
            `${movement.to_account_label || accountLabel(movement.to_account)}`,
            `(${locationLabel(movement.to_location)})`,
        ].join(' ')
    }

    if (movement?.movement_type === 'account_transfer') {
        return [
            `${movement.from_account_label || accountLabel(movement.from_pay_to)}`,
            `(${locationLabel(movement.from_location)})`,
            '→',
            `${movement.to_account_label || accountLabel(movement.to_pay_to)}`,
            `(${locationLabel(movement.to_location)})`,
        ].join(' ')
    }

    if (movement?.movement_type) {
        return [
            movement.account_label || accountLabel(movement.pay_to),
            `${locationLabel(movement.from_location)} → ${locationLabel(movement.to_location)}`,
        ].filter(Boolean).join(' · ')
    }

    return [
        movement.account_label || accountLabel(movement.account),
        locationLabel(movement.location),
    ].filter(Boolean).join(' · ')
}

const normalizeClubs = (payload) => {
    const rows = payload?.clubs || []
    if (Array.isArray(rows) && rows.length) clubs.value = rows
}

const syncMovementDefaults = () => {
    const available = accountOptions.value.map((account) => account.value)
    const fallback = available[0] || 'club_budget'
    if (!available.includes(movementForm.value.pay_to)) movementForm.value.pay_to = fallback
    if (!available.includes(movementForm.value.from_pay_to)) movementForm.value.from_pay_to = fallback
    if (movementForm.value.to_pay_to && !available.includes(movementForm.value.to_pay_to)) movementForm.value.to_pay_to = ''
}

async function loadLedger() {
    ledgerLoading.value = true
    try {
        ledgerPage.value = 1
        const params = {
            club_id: selectedClubId.value || undefined,
            limit: 200,
        }
        if (ledgerFilters.value.domain !== 'all') params.domain = ledgerFilters.value.domain
        if (ledgerFilters.value.account !== 'all') params.account = ledgerFilters.value.account
        if (ledgerFilters.value.date_from) params.date_from = ledgerFilters.value.date_from
        if (ledgerFilters.value.date_to) params.date_to = ledgerFilters.value.date_to

        const payload = await fetchFinanceEngineMovements(params)
        engineReport.value = payload?.data || null
    } catch (error) {
        console.error(error)
        showToast(error?.response?.data?.message || tr('No se pudo cargar el libro contable.', 'Could not load the ledger.'), 'error')
    } finally {
        ledgerLoading.value = false
    }
}

async function loadData() {
    loading.value = true
    loadError.value = ''
    try {
        const payload = await fetchFinanceEngineAccounting(selectedClubId.value)
        const data = payload?.data || {}
        const treasuryData = data.treasury || {}

        treasury.value = {
            club: treasuryData.club,
            bank_info: treasuryData.bank_info,
            accounts: treasuryData.accounts || [],
            summary: treasuryData.summary || {},
            pending_staff_remittances: treasuryData.pending_staff_remittances || [],
            movements: treasuryData.movements || [],
        }
        selectedClubId.value = treasuryData.club?.id ?? selectedClubId.value
        syncMovementDefaults()

        normalizeClubs(data)
        accountReport.value = data.account_report || null
        normalizeClubs(accountReport.value)
        engineReport.value = data.engine_report || null
        eventSettlementRows.value = Array.isArray(data.event_settlements) ? data.event_settlements : []
    } catch (error) {
        console.error(error)
        loadError.value = error?.response?.data?.message || tr('No se pudo cargar contabilidad.', 'Could not load accounting.')
    } finally {
        loading.value = false
    }
}

function onProofSelected(event) {
    movementForm.value.proof = event.target.files?.[0] || null
}

function resetMovementForm() {
    movementForm.value = defaultMovementForm()
    movementForm.value.pay_to = accountOptions.value[0]?.value || 'club_budget'
    movementForm.value.from_pay_to = movementForm.value.pay_to
    if (movementProofInput.value) movementProofInput.value.value = ''
}

async function saveMovement() {
    savingMovement.value = true
    try {
        await createFinanceEngineTransfer({
            ...movementForm.value,
            club_id: selectedClubId.value,
        })
        showToast(tr('Movimiento contable registrado.', 'Accounting movement recorded.'), 'success')
        resetMovementForm()
        await loadData()
    } catch (error) {
        console.error(error)
        showToast(error?.response?.data?.message || tr('No se pudo registrar el movimiento.', 'Could not record the movement.'), 'error')
    } finally {
        savingMovement.value = false
    }
}

async function validateRemittance(batch) {
    validatingBatchId.value = batch.batch_id
    try {
        await validateFinanceEngineStaffRemittance(batch.batch_id, selectedClubId.value)
        showToast(tr('Entrega de staff validada.', 'Staff remittance validated.'), 'success')
        await loadData()
    } catch (error) {
        console.error(error)
        showToast(error?.response?.data?.message || tr('No se pudo validar la entrega.', 'Could not validate the remittance.'), 'error')
    } finally {
        validatingBatchId.value = ''
    }
}

function openSettlementModal(row) {
    selectedSettlement.value = row
    settlementError.value = ''
    settlementForm.value = {
        deposited_at: new Date().toISOString().slice(0, 16),
        reference: '',
        notes: '',
        deposit_proof: null,
    }
}

function closeSettlementModal() {
    selectedSettlement.value = null
    settlementError.value = ''
}

function onSettlementProofSelected(event) {
    settlementForm.value.deposit_proof = event.target.files?.[0] || null
}

async function saveEventSettlement() {
    if (!selectedSettlement.value) return

    settlementSaving.value = true
    settlementError.value = ''
    try {
        await createFinanceEngineEventSettlement(selectedSettlement.value.event_id, {
            club_id: selectedSettlement.value.club_id,
            deposited_at: settlementForm.value.deposited_at,
            reference: settlementForm.value.reference,
            notes: settlementForm.value.notes,
            deposit_proof: settlementForm.value.deposit_proof,
        })
        showToast(tr('Transferencia de evento registrada.', 'Event transfer recorded.'), 'success')
        closeSettlementModal()
        await loadData()
    } catch (error) {
        console.error(error)
        const errors = error?.response?.data?.errors
        const firstError = errors ? Object.values(errors)[0] : null
        settlementError.value = Array.isArray(firstError)
            ? firstError[0]
            : (firstError || error?.response?.data?.message || tr('No se pudo registrar la transferencia.', 'Could not record the transfer.'))
        showToast(settlementError.value, 'error')
    } finally {
        settlementSaving.value = false
    }
}

function openCorrectionModal(movement) {
    if (!canCorrectMovement(movement)) return

    selectedCorrectionMovement.value = movement
    correctionError.value = ''
    correctionForm.value = {
        correction_date: today(),
        reason: '',
    }
}

function closeCorrectionModal() {
    selectedCorrectionMovement.value = null
    correctionError.value = ''
}

async function reverseSelectedMovement() {
    const movement = selectedCorrectionMovement.value
    if (!movement) return

    const reason = correctionForm.value.reason.trim()
    const correctionDate = correctionForm.value.correction_date || today()

    if (!reason) {
        showToast(tr('Escribe el motivo de la correccion.', 'Enter the correction reason.'), 'error')
        return
    }

    reversingKey.value = movement.movement_id
    correctionError.value = ''
    try {
        if (movement.correction_type === 'income') {
            await reverseFinanceEnginePayment(movement.id, { reason, correction_date: correctionDate })
        } else if (movement.correction_type === 'expense') {
            await reverseFinanceEngineExpense(movement.id, { reason, correction_date: correctionDate })
        } else if (movement.correction_type === 'reimbursement') {
            await reverseFinanceEngineReimbursement(movement.id, { reason, correction_date: correctionDate })
        } else {
            throw new Error(tr('Este movimiento no tiene flujo de correccion configurado.', 'This movement has no correction flow configured.'))
        }

        showToast(tr('Correccion registrada.', 'Correction recorded.'), 'success')
        closeCorrectionModal()
        await loadData()
    } catch (error) {
        console.error(error)
        correctionError.value = error?.response?.data?.message || error?.message || tr('No se pudo registrar la correccion.', 'Could not record the correction.')
        showToast(correctionError.value, 'error')
    } finally {
        reversingKey.value = ''
    }
}

watch(() => movementForm.value.movement_type, (type) => {
    if (type === 'cash_deposit') {
        movementForm.value.from_location = 'cash'
        movementForm.value.to_location = 'bank'
        movementForm.value.to_pay_to = ''
    } else if (type === 'cash_withdrawal') {
        movementForm.value.from_location = 'bank'
        movementForm.value.to_location = 'cash'
        movementForm.value.to_pay_to = ''
    } else {
        movementForm.value.from_location = 'cash'
        movementForm.value.to_location = 'cash'
    }
})

watch(() => movementForm.value.from_pay_to, (fromPayTo) => {
    if (movementForm.value.to_pay_to === fromPayTo) movementForm.value.to_pay_to = ''
})

watch(ledgerMovements, () => {
    if (ledgerPage.value > ledgerPageCount.value) {
        setLedgerPage(ledgerPageCount.value)
    }
})

onMounted(loadData)
</script>

<template>
    <PathfinderLayout>
        <template #title>{{ tr('Contabilidad', 'Accounting') }}</template>

        <div class="space-y-6">
            <section class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm">
                <div class="border-b border-gray-100 bg-gray-50 px-4 py-4 sm:px-5">
                    <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                        <div class="min-w-0">
                            <div class="flex items-center gap-3">
                                <span class="flex h-10 w-10 items-center justify-center rounded-xl border border-red-100 bg-white text-red-700">
                                    <BuildingLibraryIcon class="h-5 w-5" />
                                </span>
                                <div class="min-w-0">
                                    <h1 class="text-xl font-semibold text-gray-900">{{ tr('Contabilidad', 'Accounting') }}</h1>
                                    <p class="truncate text-sm text-gray-500">{{ currentClub?.club_name || tr('Club actual', 'Current club') }}</p>
                                </div>
                            </div>
                        </div>

                        <div class="flex flex-col gap-2 sm:flex-row sm:items-end">
                            <div v-if="canSelectClub && clubs.length" class="w-full sm:w-72">
                                <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-gray-500">{{ tr('Club', 'Club') }}</label>
                                <select
                                    v-model="selectedClubId"
                                    class="w-full rounded-xl border border-gray-300 bg-white px-3 py-2 text-sm focus:border-red-400 focus:outline-none focus:ring-2 focus:ring-red-100"
                                    @change="loadData"
                                >
                                    <option v-for="club in clubs" :key="club.id" :value="club.id">{{ club.club_name }}</option>
                                </select>
                            </div>
                            <button
                                type="button"
                                class="inline-flex min-h-10 items-center justify-center gap-2 rounded-xl border border-gray-200 bg-white px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 disabled:opacity-60"
                                :disabled="loading"
                                @click="loadData"
                            >
                                <ArrowPathIcon class="h-4 w-4" :class="{ 'animate-spin': loading }" />
                                <span>{{ loading ? tr('Cargando...', 'Loading...') : tr('Recargar', 'Reload') }}</span>
                            </button>
                        </div>
                    </div>
                </div>

                <div class="grid gap-3 p-4 sm:grid-cols-2 md:grid-cols-3 xl:grid-cols-5">
                    <a
                        v-for="item in moduleNavItems"
                        :key="item.href"
                        :href="item.href"
                        class="group flex min-h-14 items-center justify-between gap-3 rounded-xl border border-gray-200 bg-white px-3 py-2 text-sm font-semibold text-gray-700 hover:border-red-200 hover:bg-red-50 hover:text-red-700"
                    >
                        <span>{{ item.label }}</span>
                        <span class="rounded-full bg-gray-100 px-2 py-0.5 text-xs text-gray-600 group-hover:bg-white group-hover:text-red-700">
                            {{ item.meta }}
                        </span>
                    </a>
                </div>
            </section>

            <div v-if="loadError" class="rounded-2xl border border-red-200 bg-red-50 p-4 text-sm text-red-700">
                {{ loadError }}
            </div>

            <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <article class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm">
                    <div class="flex items-center justify-between gap-3">
                        <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ tr('Efectivo', 'Cash') }}</p>
                        <span class="rounded-full bg-emerald-50 px-2 py-1 text-xs font-semibold text-emerald-700">{{ tr('Disponible', 'Available') }}</span>
                    </div>
                    <p class="mt-2 text-2xl font-bold" :class="summaryTotals.cash_balance < 0 ? 'text-rose-700' : 'text-gray-900'">{{ formatMoney(summaryTotals.cash_balance) }}</p>
                </article>
                <article class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm">
                    <div class="flex items-center justify-between gap-3">
                        <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ tr('Banco', 'Bank') }}</p>
                        <span class="rounded-full bg-blue-50 px-2 py-1 text-xs font-semibold text-blue-700">{{ tr('Electronico', 'Electronic') }}</span>
                    </div>
                    <p class="mt-2 text-2xl font-bold" :class="summaryTotals.bank_balance < 0 ? 'text-rose-700' : 'text-gray-900'">{{ formatMoney(summaryTotals.bank_balance) }}</p>
                </article>
                <article class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm">
                    <div class="flex items-center justify-between gap-3">
                        <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ tr('Disponible', 'Available') }}</p>
                        <span class="rounded-full bg-gray-100 px-2 py-1 text-xs font-semibold text-gray-700">{{ operatingSummaryAccounts.length }} {{ tr('cuentas', 'accounts') }}</span>
                    </div>
                    <p class="mt-2 text-2xl font-bold" :class="summaryTotals.total_balance < 0 ? 'text-rose-700' : 'text-gray-900'">{{ formatMoney(summaryTotals.total_balance) }}</p>
                </article>
                <article class="rounded-2xl border border-amber-200 bg-amber-50 p-4 shadow-sm">
                    <div class="flex items-center justify-between gap-3">
                        <p class="text-xs font-semibold uppercase tracking-wide text-amber-900">{{ tr('Reembolsos pendientes', 'Reimbursements owed') }}</p>
                        <ExclamationTriangleIcon class="h-5 w-5 text-amber-700" />
                    </div>
                    <p class="mt-2 text-2xl font-bold" :class="reimbursementBalanceSummary.total_available < 0 ? 'text-rose-700' : 'text-gray-900'">
                        {{ formatMoney(reimbursementBalanceSummary.total_available) }}
                    </p>
                </article>
            </section>

            <section id="accounting-transfers" class="scroll-mt-4 grid gap-6 xl:grid-cols-[minmax(0,0.95fr)_minmax(0,1.05fr)]">
                <article class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm">
                    <div class="mb-4 flex items-start gap-3">
                        <BanknotesIcon class="mt-1 h-5 w-5 text-red-600" />
                        <div>
                            <h2 class="text-lg font-semibold text-gray-900">{{ tr('Transferencias y ubicacion de fondos', 'Transfers and Fund Location') }}</h2>
                            <p class="text-sm text-gray-600">
                                {{ tr('Mueve dinero entre efectivo y banco, o entre cuentas internas del club.', 'Move money between cash and bank, or between the club internal accounts.') }}
                            </p>
                        </div>
                    </div>

                    <form class="space-y-4" @submit.prevent="saveMovement">
                        <div>
                            <label class="mb-1 block text-sm font-medium text-gray-700">{{ tr('Tipo de movimiento', 'Movement type') }}</label>
                            <select
                                v-model="movementForm.movement_type"
                                class="w-full rounded-xl border border-gray-300 px-3 py-2 text-sm focus:border-red-400 focus:outline-none focus:ring-2 focus:ring-red-100"
                            >
                                <option value="cash_deposit">{{ tr('Depositar efectivo a banco', 'Deposit cash to bank') }}</option>
                                <option value="cash_withdrawal">{{ tr('Retirar efectivo de banco', 'Withdraw cash from bank') }}</option>
                                <option value="account_transfer">{{ tr('Transferir entre cuentas', 'Transfer between accounts') }}</option>
                            </select>
                        </div>

                        <div v-if="movementForm.movement_type !== 'account_transfer'" class="grid gap-3 sm:grid-cols-2">
                            <div>
                                <label class="mb-1 block text-sm font-medium text-gray-700">{{ tr('Cuenta', 'Account') }}</label>
                                <select
                                    v-model="movementForm.pay_to"
                                    class="w-full rounded-xl border border-gray-300 px-3 py-2 text-sm focus:border-red-400 focus:outline-none focus:ring-2 focus:ring-red-100"
                                >
                                    <option v-for="account in accountOptions" :key="account.value" :value="account.value">
                                        {{ account.label }}
                                    </option>
                                </select>
                            </div>
                            <div>
                                <label class="mb-1 block text-sm font-medium text-gray-700">{{ tr('Fecha', 'Date') }}</label>
                                <input
                                    v-model="movementForm.movement_date"
                                    type="date"
                                    class="w-full rounded-xl border border-gray-300 px-3 py-2 text-sm focus:border-red-400 focus:outline-none focus:ring-2 focus:ring-red-100"
                                />
                            </div>
                        </div>

                        <div v-else class="space-y-3">
                            <div class="grid gap-3 sm:grid-cols-2">
                                <div>
                                    <label class="mb-1 block text-sm font-medium text-gray-700">{{ tr('Cuenta origen', 'Source account') }}</label>
                                    <select
                                        v-model="movementForm.from_pay_to"
                                        class="w-full rounded-xl border border-gray-300 px-3 py-2 text-sm focus:border-red-400 focus:outline-none focus:ring-2 focus:ring-red-100"
                                    >
                                        <option v-for="account in accountOptions" :key="account.value" :value="account.value">
                                            {{ account.label }}
                                        </option>
                                    </select>
                                </div>
                                <div>
                                    <label class="mb-1 block text-sm font-medium text-gray-700">{{ tr('Cuenta destino', 'Destination account') }}</label>
                                    <select
                                        v-model="movementForm.to_pay_to"
                                        class="w-full rounded-xl border border-gray-300 px-3 py-2 text-sm focus:border-red-400 focus:outline-none focus:ring-2 focus:ring-red-100"
                                    >
                                        <option value="">{{ tr('Seleccionar cuenta', 'Select account') }}</option>
                                        <option
                                            v-for="account in accountOptions.filter((item) => item.value !== movementForm.from_pay_to)"
                                            :key="account.value"
                                            :value="account.value"
                                        >
                                            {{ account.label }}
                                        </option>
                                    </select>
                                </div>
                            </div>
                            <div class="grid gap-3 sm:grid-cols-3">
                                <div>
                                    <label class="mb-1 block text-sm font-medium text-gray-700">{{ tr('Origen', 'Source') }}</label>
                                    <select
                                        v-model="movementForm.from_location"
                                        class="w-full rounded-xl border border-gray-300 px-3 py-2 text-sm focus:border-red-400 focus:outline-none focus:ring-2 focus:ring-red-100"
                                    >
                                        <option value="cash">{{ tr('Efectivo', 'Cash') }}</option>
                                        <option value="bank">{{ tr('Banco', 'Bank') }}</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="mb-1 block text-sm font-medium text-gray-700">{{ tr('Destino', 'Destination') }}</label>
                                    <select
                                        v-model="movementForm.to_location"
                                        class="w-full rounded-xl border border-gray-300 px-3 py-2 text-sm focus:border-red-400 focus:outline-none focus:ring-2 focus:ring-red-100"
                                    >
                                        <option value="cash">{{ tr('Efectivo', 'Cash') }}</option>
                                        <option value="bank">{{ tr('Banco', 'Bank') }}</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="mb-1 block text-sm font-medium text-gray-700">{{ tr('Fecha', 'Date') }}</label>
                                    <input
                                        v-model="movementForm.movement_date"
                                        type="date"
                                        class="w-full rounded-xl border border-gray-300 px-3 py-2 text-sm focus:border-red-400 focus:outline-none focus:ring-2 focus:ring-red-100"
                                    />
                                </div>
                            </div>
                        </div>

                        <div class="grid gap-3 sm:grid-cols-2">
                            <div>
                                <label class="mb-1 block text-sm font-medium text-gray-700">{{ tr('Monto', 'Amount') }}</label>
                                <input
                                    v-model="movementForm.amount"
                                    type="number"
                                    min="0.01"
                                    step="0.01"
                                    class="w-full rounded-xl border border-gray-300 px-3 py-2 text-sm focus:border-red-400 focus:outline-none focus:ring-2 focus:ring-red-100"
                                    required
                                />
                            </div>
                            <div>
                                <label class="mb-1 block text-sm font-medium text-gray-700">{{ tr('Referencia', 'Reference') }}</label>
                                <input
                                    v-model="movementForm.reference"
                                    type="text"
                                    class="w-full rounded-xl border border-gray-300 px-3 py-2 text-sm focus:border-red-400 focus:outline-none focus:ring-2 focus:ring-red-100"
                                    :placeholder="tr('Deposito, Zelle, nota interna', 'Deposit, Zelle, internal note')"
                                />
                            </div>
                        </div>

                        <div>
                            <label class="mb-1 block text-sm font-medium text-gray-700">{{ tr('Comprobante', 'Proof') }}</label>
                            <input
                                ref="movementProofInput"
                                type="file"
                                accept="image/*,.pdf"
                                class="w-full rounded-xl border border-gray-300 px-3 py-2 text-sm file:mr-3 file:rounded-lg file:border-0 file:bg-gray-100 file:px-3 file:py-1.5 file:text-sm file:font-semibold file:text-gray-700 focus:border-red-400 focus:outline-none focus:ring-2 focus:ring-red-100"
                                @change="onProofSelected"
                            />
                        </div>

                        <div>
                            <label class="mb-1 block text-sm font-medium text-gray-700">{{ tr('Notas', 'Notes') }}</label>
                            <textarea
                                v-model="movementForm.notes"
                                rows="3"
                                class="w-full rounded-xl border border-gray-300 px-3 py-2 text-sm focus:border-red-400 focus:outline-none focus:ring-2 focus:ring-red-100"
                            />
                        </div>

                        <button
                            type="submit"
                            class="inline-flex min-h-11 w-full items-center justify-center rounded-xl bg-red-600 px-4 py-2 text-sm font-semibold text-white hover:bg-red-700 disabled:opacity-60"
                            :disabled="savingMovement"
                        >
                            {{ savingMovement ? tr('Guardando...', 'Saving...') : tr('Guardar movimiento', 'Save movement') }}
                        </button>
                    </form>
                </article>

                <article id="accounting-balances" class="scroll-mt-4 rounded-2xl border border-gray-200 bg-white p-4 shadow-sm">
                    <div class="mb-4 flex items-start gap-3">
                        <ChartBarIcon class="mt-1 h-5 w-5 text-red-600" />
                        <div>
                            <h2 class="text-lg font-semibold text-gray-900">{{ tr('Saldos por cuenta', 'Account Balances') }}</h2>
                            <p class="text-sm text-gray-600">
                                {{ tr('Lectura rapida por cuenta y ubicacion del dinero.', 'Quick view by account and money location.') }}
                            </p>
                        </div>
                    </div>

                    <div v-if="!accountBalanceRows.length" class="rounded-xl border border-dashed border-gray-300 p-4 text-sm text-gray-500">
                        {{ tr('Aun no hay saldos disponibles.', 'No balances are available yet.') }}
                    </div>
                    <div v-else class="space-y-3">
                        <article v-for="account in accountBalanceRows" :key="account.account" class="rounded-xl border border-gray-200 p-3">
                            <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                                <div>
                                    <h3 class="font-semibold text-gray-900">{{ account.label }}</h3>
                                    <p class="text-xs text-gray-500">{{ account.account }}</p>
                                </div>
                                <div class="text-lg font-semibold text-gray-900">{{ formatMoney(account.balance) }}</div>
                            </div>
                            <div class="mt-3 grid grid-cols-2 gap-2 text-sm">
                                <div class="rounded-lg bg-gray-50 p-2">
                                    <p class="text-xs text-gray-500">{{ tr('Efectivo', 'Cash') }}</p>
                                    <p class="font-semibold text-gray-900">{{ formatMoney(account.cash_balance) }}</p>
                                </div>
                                <div class="rounded-lg bg-gray-50 p-2">
                                    <p class="text-xs text-gray-500">{{ tr('Banco', 'Bank') }}</p>
                                    <p class="font-semibold text-gray-900">{{ formatMoney(account.bank_balance) }}</p>
                                </div>
                            </div>
                        </article>
                    </div>

                    <div class="mt-4 flex flex-wrap gap-2">
                        <a
                            v-if="selectedClubId"
                            :href="route('club.finance-engine.accounting.pdf', { club_id: selectedClubId })"
                            target="_blank"
                            rel="noopener"
                            class="inline-flex items-center justify-center rounded-xl border border-gray-200 px-3 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50"
                        >
                            {{ tr('PDF de saldos', 'Balances PDF') }}
                        </a>
                    </div>
                </article>
            </section>

            <section id="accounting-ledger" class="scroll-mt-4 rounded-2xl border border-gray-200 bg-white p-4 shadow-sm">
                <div class="mb-4 flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
                    <div>
                        <h2 class="text-lg font-semibold text-gray-900">{{ tr('Libro contable normalizado', 'Normalized Accounting Ledger') }}</h2>
                        <p class="text-sm text-gray-600">
                            {{ tr('Vista unificada de ingresos, gastos, transferencias, recibos, comprobantes y correcciones vinculadas.', 'Unified view of income, expenses, transfers, receipts, proofs, and linked corrections.') }}
                        </p>
                    </div>
                    <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-5">
                        <select
                            v-model="ledgerFilters.domain"
                            class="rounded-xl border border-gray-300 px-3 py-2 text-sm focus:border-red-400 focus:outline-none focus:ring-2 focus:ring-red-100"
                        >
                            <option value="all">{{ tr('Todos', 'All') }}</option>
                            <option value="income">{{ tr('Ingresos', 'Income') }}</option>
                            <option value="expense">{{ tr('Gastos', 'Expenses') }}</option>
                            <option value="transfer">{{ tr('Transferencias', 'Transfers') }}</option>
                        </select>
                        <select
                            v-model="ledgerFilters.account"
                            class="rounded-xl border border-gray-300 px-3 py-2 text-sm focus:border-red-400 focus:outline-none focus:ring-2 focus:ring-red-100"
                        >
                            <option value="all">{{ tr('Todas las cuentas', 'All accounts') }}</option>
                            <option v-for="account in accountOptions" :key="account.value" :value="account.value">
                                {{ account.label }}
                            </option>
                        </select>
                        <input
                            v-model="ledgerFilters.date_from"
                            type="date"
                            class="rounded-xl border border-gray-300 px-3 py-2 text-sm focus:border-red-400 focus:outline-none focus:ring-2 focus:ring-red-100"
                        />
                        <input
                            v-model="ledgerFilters.date_to"
                            type="date"
                            class="rounded-xl border border-gray-300 px-3 py-2 text-sm focus:border-red-400 focus:outline-none focus:ring-2 focus:ring-red-100"
                        />
                        <button
                            type="button"
                            class="inline-flex min-h-10 items-center justify-center gap-2 rounded-xl border border-gray-200 px-3 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 disabled:opacity-60"
                            :disabled="ledgerLoading"
                            @click="loadLedger"
                        >
                            <ArrowPathIcon class="h-4 w-4" :class="{ 'animate-spin': ledgerLoading }" />
                            <span>{{ tr('Filtrar', 'Filter') }}</span>
                        </button>
                    </div>
                </div>

                <div v-if="!ledgerMovements.length" class="rounded-xl border border-dashed border-gray-300 p-4 text-sm text-gray-500">
                    {{ tr('No hay movimientos para los filtros seleccionados.', 'There are no movements for the selected filters.') }}
                </div>

                <div v-else class="space-y-3 lg:hidden">
                    <article
                        v-for="movement in paginatedLedgerMovements"
                        :key="movement.movement_id"
                        :data-ledger-movement="movement.movement_id"
                        class="rounded-xl border border-gray-200 p-3 scroll-mt-24"
                    >
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="font-semibold text-gray-900">{{ movement.concept || movementTypeLabel(movement.kind) }}</p>
                                <p class="text-sm text-gray-600">{{ formatDate(movement.date) }} · {{ domainLabel(movement.domain) }}</p>
                            </div>
                            <p
                                class="shrink-0 font-semibold"
                                :class="Number(movement.signed_amount) < 0 ? 'text-red-700' : Number(movement.signed_amount) > 0 ? 'text-emerald-700' : 'text-gray-900'"
                            >
                                {{ formatMoney(movement.amount) }}
                            </p>
                        </div>
                        <p class="mt-2 text-sm text-gray-600">{{ movementDescription(movement) }}</p>
                        <div class="mt-2 flex flex-wrap items-center gap-2 text-xs">
                            <span class="text-gray-500">{{ rowCounterparty(movement) }}</span>
                            <span
                                class="rounded-full px-2 py-0.5 font-semibold"
                                :class="movement.status === 'cancelled' ? 'bg-amber-50 text-amber-700' : movement.status === 'cancellation' ? 'bg-red-50 text-red-700' : 'bg-gray-100 text-gray-700'"
                            >
                                {{ correctionStatusLabel(movement) }}
                            </span>
                        </div>
                        <div v-if="cancellationSummary(movement)" class="mt-2 flex flex-wrap items-center gap-2 text-xs">
                            <span class="font-semibold text-amber-700">{{ cancellationSummary(movement) }}</span>
                            <button
                                v-if="linkedMovementKey(movement, 'correction')"
                                type="button"
                                class="font-semibold text-red-700 hover:text-red-800"
                                @click="scrollToLedgerMovement(linkedMovementKey(movement, 'correction'))"
                            >
                                {{ tr('Ver correccion', 'View correction') }}
                            </button>
                            <button
                                v-if="linkedMovementKey(movement, 'original')"
                                type="button"
                                class="font-semibold text-red-700 hover:text-red-800"
                                @click="scrollToLedgerMovement(linkedMovementKey(movement, 'original'))"
                            >
                                {{ tr('Ver original', 'View original') }}
                            </button>
                        </div>
                        <div class="mt-2 flex flex-wrap gap-2 text-xs">
                            <a v-if="movement.receipt?.url" :href="movement.receipt.url" target="_blank" rel="noopener" class="font-semibold text-red-700">
                                {{ tr('Recibo', 'Receipt') }} {{ movement.receipt.number ? `#${movement.receipt.number}` : '' }}
                            </a>
                            <a v-if="movement.proof?.url" :href="movement.proof.url" target="_blank" rel="noopener" class="font-semibold text-red-700">
                                {{ tr('Comprobante', 'Proof') }}
                            </a>
                        </div>
                        <div class="mt-3">
                            <button
                                v-if="canCorrectMovement(movement)"
                                type="button"
                                class="inline-flex min-h-9 items-center justify-center rounded-xl border border-red-200 px-3 py-1.5 text-sm font-semibold text-red-700 hover:bg-red-50"
                                @click="openCorrectionModal(movement)"
                            >
                                {{ tr('Corregir', 'Correct') }}
                            </button>
                        </div>
                    </article>
                </div>

                <div v-if="ledgerMovements.length" class="hidden overflow-x-auto lg:block">
                    <table class="min-w-full text-sm text-gray-700">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-3 py-2 text-left font-semibold">{{ tr('Fecha', 'Date') }}</th>
                                <th class="px-3 py-2 text-left font-semibold">{{ tr('Tipo', 'Type') }}</th>
                                <th class="px-3 py-2 text-left font-semibold">{{ tr('Concepto', 'Concept') }}</th>
                                <th class="px-3 py-2 text-left font-semibold">{{ tr('Cuenta / ubicacion', 'Account / location') }}</th>
                                <th class="px-3 py-2 text-left font-semibold">{{ tr('Tercero', 'Counterparty') }}</th>
                                <th class="px-3 py-2 text-right font-semibold">{{ tr('Monto', 'Amount') }}</th>
                                <th class="px-3 py-2 text-left font-semibold">{{ tr('Estatus', 'Status') }}</th>
                                <th class="px-3 py-2 text-left font-semibold">{{ tr('Soportes', 'Files') }}</th>
                                <th class="px-3 py-2 text-right font-semibold">{{ tr('Accion', 'Action') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            <tr
                                v-for="movement in paginatedLedgerMovements"
                                :key="movement.movement_id"
                                :data-ledger-movement="movement.movement_id"
                                class="scroll-mt-24"
                            >
                                <td class="whitespace-nowrap px-3 py-2">{{ formatDate(movement.date) }}</td>
                                <td class="px-3 py-2">{{ domainLabel(movement.domain) }}</td>
                                <td class="max-w-xs px-3 py-2">
                                    <div class="font-medium text-gray-900">{{ movement.concept || movementTypeLabel(movement.kind) }}</div>
                                    <div v-if="movement.reference" class="text-xs text-gray-500">{{ movement.reference }}</div>
                                </td>
                                <td class="px-3 py-2">{{ movementDescription(movement) }}</td>
                                <td class="px-3 py-2">{{ rowCounterparty(movement) }}</td>
                                <td
                                    class="whitespace-nowrap px-3 py-2 text-right font-semibold"
                                    :class="Number(movement.signed_amount) < 0 ? 'text-red-700' : Number(movement.signed_amount) > 0 ? 'text-emerald-700' : 'text-gray-900'"
                                >
                                    {{ formatMoney(movement.amount) }}
                                </td>
                                <td class="px-3 py-2">
                                    <div>
                                        <span
                                            class="rounded-full px-2 py-0.5 text-xs font-semibold"
                                            :class="movement.status === 'cancelled' ? 'bg-amber-50 text-amber-700' : movement.status === 'cancellation' ? 'bg-red-50 text-red-700' : 'bg-gray-100 text-gray-700'"
                                        >
                                            {{ correctionStatusLabel(movement) }}
                                        </span>
                                    </div>
                                    <div v-if="cancellationSummary(movement)" class="mt-1 space-y-1 text-xs">
                                        <div class="font-semibold text-amber-700">{{ cancellationSummary(movement) }}</div>
                                        <button
                                            v-if="linkedMovementKey(movement, 'correction')"
                                            type="button"
                                            class="font-semibold text-red-700 hover:text-red-800"
                                            @click="scrollToLedgerMovement(linkedMovementKey(movement, 'correction'))"
                                        >
                                            {{ tr('Ver correccion', 'View correction') }}
                                        </button>
                                        <button
                                            v-if="linkedMovementKey(movement, 'original')"
                                            type="button"
                                            class="font-semibold text-red-700 hover:text-red-800"
                                            @click="scrollToLedgerMovement(linkedMovementKey(movement, 'original'))"
                                        >
                                            {{ tr('Ver original', 'View original') }}
                                        </button>
                                    </div>
                                </td>
                                <td class="px-3 py-2">
                                    <div class="flex flex-wrap gap-2">
                                        <a v-if="movement.receipt?.url" :href="movement.receipt.url" target="_blank" rel="noopener" class="font-semibold text-red-700">
                                            {{ tr('Recibo', 'Receipt') }}
                                        </a>
                                        <a v-if="movement.proof?.url" :href="movement.proof.url" target="_blank" rel="noopener" class="font-semibold text-red-700">
                                            {{ tr('Comprobante', 'Proof') }}
                                        </a>
                                    </div>
                                </td>
                                <td class="whitespace-nowrap px-3 py-2 text-right">
                                    <button
                                        v-if="canCorrectMovement(movement)"
                                        type="button"
                                        class="inline-flex min-h-9 items-center justify-center rounded-xl border border-red-200 px-3 py-1.5 text-sm font-semibold text-red-700 hover:bg-red-50"
                                        @click="openCorrectionModal(movement)"
                                    >
                                        {{ tr('Corregir', 'Correct') }}
                                    </button>
                                    <span v-else class="text-xs text-gray-400">—</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div
                    v-if="ledgerMovements.length > LEDGER_PAGE_SIZE"
                    class="mt-4 flex flex-col gap-3 border-t border-gray-100 pt-4 sm:flex-row sm:items-center sm:justify-between"
                >
                    <p class="text-sm text-gray-600">
                        {{ tr('Mostrando', 'Showing') }}
                        <span class="font-semibold text-gray-900">{{ ledgerPageStart }}-{{ ledgerPageEnd }}</span>
                        {{ tr('de', 'of') }}
                        <span class="font-semibold text-gray-900">{{ ledgerMovements.length }}</span>
                        {{ tr('movimientos', 'movements') }}
                    </p>
                    <div class="flex items-center gap-2">
                        <button
                            type="button"
                            class="inline-flex min-h-10 items-center justify-center rounded-xl border border-gray-200 px-3 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-50"
                            :disabled="ledgerPage <= 1"
                            @click="setLedgerPage(ledgerPage - 1)"
                        >
                            {{ tr('Anterior', 'Previous') }}
                        </button>
                        <span class="rounded-xl bg-gray-50 px-3 py-2 text-sm font-semibold text-gray-700">
                            {{ tr('Pagina', 'Page') }} {{ ledgerPage }} / {{ ledgerPageCount }}
                        </span>
                        <button
                            type="button"
                            class="inline-flex min-h-10 items-center justify-center rounded-xl border border-gray-200 px-3 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-50"
                            :disabled="ledgerPage >= ledgerPageCount"
                            @click="setLedgerPage(ledgerPage + 1)"
                        >
                            {{ tr('Siguiente', 'Next') }}
                        </button>
                    </div>
                </div>
            </section>

            <section id="accounting-events" class="scroll-mt-4 rounded-2xl border border-gray-200 bg-white p-4 shadow-sm">
                <div class="mb-4 flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <h2 class="text-lg font-semibold text-gray-900">{{ tr('Transferencias de eventos hacia arriba', 'Upstream Event Transfers') }}</h2>
                        <p class="text-sm text-gray-600">
                            {{ tr('Depositos desde este club hacia la organizacion dueña del evento, con comprobante y recibo.', 'Deposits from this club to the event owning organization, with proof and receipt.') }}
                        </p>
                    </div>
                    <span class="rounded-full bg-gray-100 px-3 py-1 text-sm font-semibold text-gray-700">
                        {{ eventSettlementRows.length }} {{ tr('eventos', 'events') }}
                    </span>
                </div>

                <div v-if="!eventSettlementRows.length" class="rounded-xl border border-dashed border-gray-300 p-4 text-sm text-gray-500">
                    {{ tr('No hay transferencias de eventos pendientes.', 'There are no pending event transfers.') }}
                </div>
                <div v-else class="space-y-3">
                    <article v-for="row in eventSettlementRows" :key="row.event_id" class="rounded-xl border border-gray-200 p-3">
                        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                            <div class="min-w-0 space-y-3">
                                <div>
                                    <h3 class="font-semibold text-gray-900">{{ row.event_title }}</h3>
                                    <p class="text-xs text-gray-500">{{ row.organizer_label }}</p>
                                </div>
                                <div class="rounded-xl border border-gray-200 bg-gray-50 p-3 text-sm">
                                    <div
                                        v-for="item in row.pending_settlement_breakdown || []"
                                        :key="`${row.event_id}-${item.component_id || item.label}`"
                                        class="flex items-center justify-between gap-3"
                                    >
                                        <span>{{ item.label }}</span>
                                        <span class="font-semibold text-gray-900">{{ formatMoney(item.amount) }}</span>
                                    </div>
                                </div>
                                <details v-if="row.paid_members?.length" class="rounded-xl border border-gray-200 bg-white p-3 text-sm">
                                    <summary class="cursor-pointer list-none">
                                        <div class="flex items-center justify-between gap-3">
                                            <span class="font-semibold text-gray-900">{{ tr('Miembros con pagos', 'Members with payments') }}</span>
                                            <span class="text-xs text-gray-600">
                                                {{ row.paid_members_count }} {{ tr('miembros', 'members') }} · {{ formatMoney(row.paid_members_total) }}
                                            </span>
                                        </div>
                                    </summary>
                                    <div class="mt-3 divide-y divide-gray-100">
                                        <div v-for="member in row.paid_members" :key="`${row.event_id}-${member.member_id || member.name}`" class="py-2 first:pt-0 last:pb-0">
                                            <div class="flex items-center justify-between gap-3">
                                                <span class="font-medium text-gray-900">{{ member.name }}</span>
                                                <span class="font-semibold text-gray-900">{{ formatMoney(member.total_paid) }}</span>
                                            </div>
                                            <p class="mt-0.5 text-xs text-gray-500">
                                                {{ member.payments_count }} {{ member.payments_count === 1 ? tr('pago', 'payment') : tr('pagos', 'payments') }}
                                                <span v-if="member.last_payment_date"> · {{ tr('Ultimo', 'Last') }}: {{ formatDate(member.last_payment_date) }}</span>
                                                <span v-if="paymentTypesLabel(member.payment_types)"> · {{ paymentTypesLabel(member.payment_types) }}</span>
                                            </p>
                                        </div>
                                    </div>
                                </details>
                                <div v-if="row.organizer_bank_info" class="rounded-xl border border-blue-100 bg-blue-50 p-3 text-sm text-blue-900">
                                    <p class="font-semibold">{{ row.organizer_bank_info.label || tr('Cuenta de destino', 'Destination account') }}</p>
                                    <div class="mt-1 grid gap-1 md:grid-cols-2">
                                        <div v-for="line in bankInfoLines(row.organizer_bank_info)" :key="`${row.event_id}-${line}`">{{ line }}</div>
                                    </div>
                                </div>
                            </div>
                            <div class="w-full space-y-3 lg:max-w-xs">
                                <div class="rounded-xl bg-gray-50 px-3 py-2">
                                    <p class="text-xs text-gray-500">{{ tr('Pendiente', 'Pending') }}</p>
                                    <p class="text-xl font-semibold text-gray-900">{{ formatMoney(row.pending_settlement_amount) }}</p>
                                </div>
                                <button
                                    v-if="Number(row.pending_settlement_amount || 0) > 0"
                                    type="button"
                                    class="w-full rounded-xl bg-red-600 px-3 py-2 text-sm font-semibold text-white hover:bg-red-700"
                                    @click="openSettlementModal(row)"
                                >
                                    {{ tr('Registrar transferencia', 'Record transfer') }}
                                </button>
                                <div v-if="row.settlement_receipts?.length" class="space-y-1 text-sm">
                                    <a
                                        v-for="receipt in row.settlement_receipts"
                                        :key="receipt.id"
                                        :href="receipt.receipt_url"
                                        target="_blank"
                                        rel="noopener"
                                        class="block font-semibold text-red-700"
                                    >
                                        {{ receipt.receipt_number }}
                                    </a>
                                </div>
                            </div>
                        </div>
                    </article>
                </div>
            </section>

            <section id="accounting-staff" class="scroll-mt-4 rounded-2xl border border-gray-200 bg-white p-4 shadow-sm">
                <div class="mb-4 flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <h2 class="text-lg font-semibold text-gray-900">{{ tr('Entregas de dinero de staff', 'Staff Money Remittances') }}</h2>
                        <p class="text-sm text-gray-600">
                            {{ tr('Valida el dinero que staff ya marco como entregado y que todavia espera confirmacion del director.', 'Validate money that staff marked as remitted and still awaits director confirmation.') }}
                        </p>
                    </div>
                    <span class="rounded-full bg-gray-100 px-3 py-1 text-sm font-semibold text-gray-700">
                        {{ pendingStaffRemittances.length }} {{ tr('pendientes', 'pending') }}
                    </span>
                </div>

                <div v-if="!pendingStaffRemittances.length" class="rounded-xl border border-dashed border-gray-300 p-4 text-sm text-gray-500">
                    {{ tr('No hay entregas pendientes.', 'There are no pending remittances.') }}
                </div>
                <div v-else class="space-y-3">
                    <article v-for="batch in pendingStaffRemittances" :key="batch.batch_id" class="rounded-xl border border-amber-200 bg-amber-50 p-3">
                        <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                            <div class="min-w-0">
                                <h3 class="font-semibold text-gray-900">{{ batch.staff_name }}</h3>
                                <p class="text-sm text-gray-700">
                                    {{ formatMoney(batch.amount) }} · {{ batch.count }} {{ tr('pagos', 'payments') }} · {{ remittanceMethodLabel(batch.remittance_method) }}
                                </p>
                                <p class="text-xs text-gray-500">
                                    {{ tr('Referencia', 'Reference') }}: {{ batch.remittance_reference || '—' }} · {{ tr('Fecha', 'Date') }}: {{ formatDate(batch.remitted_at) }}
                                </p>
                            </div>
                            <button
                                type="button"
                                class="inline-flex min-h-10 items-center justify-center rounded-xl bg-amber-700 px-4 py-2 text-sm font-semibold text-white hover:bg-amber-800 disabled:opacity-60"
                                :disabled="validatingBatchId === batch.batch_id"
                                @click="validateRemittance(batch)"
                            >
                                {{ validatingBatchId === batch.batch_id ? tr('Validando...', 'Validating...') : tr('Validar entrega', 'Validate remittance') }}
                            </button>
                        </div>
                    </article>
                </div>
            </section>

            <section class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm">
                <div class="mb-4 flex items-start gap-3">
                    <ArrowPathIcon class="mt-1 h-5 w-5 text-red-600" />
                    <div>
                        <h2 class="text-lg font-semibold text-gray-900">{{ tr('Ultimas transferencias internas', 'Recent Internal Transfers') }}</h2>
                        <p class="text-sm text-gray-600">
                            {{ tr('Auditoria rapida de los movimientos hechos desde este modulo o tesoreria.', 'Quick audit of movements made from this module or treasury.') }}
                        </p>
                    </div>
                </div>

                <div v-if="!recentTreasuryMovements.length" class="rounded-xl border border-dashed border-gray-300 p-4 text-sm text-gray-500">
                    {{ tr('No hay transferencias registradas.', 'No transfers have been recorded.') }}
                </div>
                <div v-else class="space-y-3">
                    <article v-for="movement in recentTreasuryMovements.slice(0, 8)" :key="movement.id" class="rounded-xl border border-gray-200 p-3">
                        <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                            <div>
                                <p class="font-semibold text-gray-900">{{ movementTypeLabel(movement.movement_type) }}</p>
                                <p class="text-sm text-gray-600">{{ movementDescription(movement) }}</p>
                                <p class="text-xs text-gray-500">{{ formatDate(movement.movement_date) }} · {{ movement.reference || '—' }}</p>
                            </div>
                            <div class="font-semibold text-gray-900">{{ formatMoney(movement.amount) }}</div>
                        </div>
                    </article>
                </div>
            </section>
        </div>

        <div v-if="selectedCorrectionMovement" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
            <div class="flex max-h-[92vh] w-full max-w-xl flex-col overflow-hidden rounded-2xl border bg-white shadow-xl">
                <div class="flex items-start justify-between gap-4 border-b px-5 py-4">
                    <div>
                        <h2 class="text-lg font-semibold text-gray-900">{{ tr('Corregir movimiento contable', 'Correct Accounting Movement') }}</h2>
                        <p class="mt-1 text-sm text-gray-600">{{ selectedCorrectionMovement.movement_id }}</p>
                    </div>
                    <button type="button" class="text-2xl leading-none text-gray-500 hover:text-gray-700" @click="closeCorrectionModal">×</button>
                </div>

                <div class="space-y-4 overflow-y-auto p-5">
                    <div class="rounded-xl border border-gray-200 bg-gray-50 p-3">
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                            <div class="min-w-0">
                                <p class="font-semibold text-gray-900">{{ selectedCorrectionMovement.concept || movementTypeLabel(selectedCorrectionMovement.kind) }}</p>
                                <p class="mt-1 text-sm text-gray-600">{{ movementDescription(selectedCorrectionMovement) }}</p>
                                <p class="mt-1 text-xs text-gray-500">
                                    {{ formatDate(selectedCorrectionMovement.date) }} · {{ domainLabel(selectedCorrectionMovement.domain) }} · {{ rowCounterparty(selectedCorrectionMovement) }}
                                </p>
                            </div>
                            <div class="shrink-0 text-right">
                                <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ tr('Monto', 'Amount') }}</p>
                                <p class="text-xl font-semibold text-gray-900">{{ formatMoney(selectedCorrectionMovement.amount) }}</p>
                            </div>
                        </div>
                        <div class="mt-3 flex flex-wrap gap-2 text-xs">
                            <a v-if="selectedCorrectionMovement.receipt?.url" :href="selectedCorrectionMovement.receipt.url" target="_blank" rel="noopener" class="font-semibold text-red-700">
                                {{ tr('Recibo', 'Receipt') }} {{ selectedCorrectionMovement.receipt.number ? `#${selectedCorrectionMovement.receipt.number}` : '' }}
                            </a>
                            <a v-if="selectedCorrectionMovement.proof?.url" :href="selectedCorrectionMovement.proof.url" target="_blank" rel="noopener" class="font-semibold text-red-700">
                                {{ tr('Comprobante', 'Proof') }}
                            </a>
                        </div>
                    </div>

                    <div class="rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
                        {{ tr('La correccion no borra el registro original. Se creara un movimiento opuesto y ambos quedaran vinculados en el libro contable.', 'The correction does not delete the original record. An opposite movement will be created and both rows will remain linked in the ledger.') }}
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700">{{ tr('Fecha de correccion', 'Correction date') }}</label>
                        <input
                            v-model="correctionForm.correction_date"
                            type="date"
                            class="mt-1 w-full rounded-xl border border-gray-300 px-3 py-2 text-sm focus:border-red-400 focus:outline-none focus:ring-2 focus:ring-red-100"
                        />
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700">{{ tr('Motivo', 'Reason') }}</label>
                        <textarea
                            v-model="correctionForm.reason"
                            rows="3"
                            class="mt-1 w-full rounded-xl border border-gray-300 px-3 py-2 text-sm focus:border-red-400 focus:outline-none focus:ring-2 focus:ring-red-100"
                            :placeholder="tr('Describe el error que se esta corrigiendo.', 'Describe the error being corrected.')"
                        />
                    </div>

                    <div v-if="correctionError" class="rounded-xl border border-red-200 bg-red-50 p-3 text-sm text-red-700">
                        {{ correctionError }}
                    </div>
                </div>

                <div class="flex flex-col-reverse gap-3 border-t px-5 py-4 sm:flex-row sm:justify-end">
                    <button
                        type="button"
                        class="rounded-xl border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50"
                        @click="closeCorrectionModal"
                    >
                        {{ tr('Cancelar', 'Cancel') }}
                    </button>
                    <button
                        type="button"
                        class="rounded-xl bg-red-600 px-4 py-2 text-sm font-semibold text-white hover:bg-red-700 disabled:opacity-60"
                        :disabled="reversingKey === selectedCorrectionMovement.movement_id"
                        @click="reverseSelectedMovement"
                    >
                        {{ reversingKey === selectedCorrectionMovement.movement_id ? tr('Corrigiendo...', 'Correcting...') : tr('Registrar correccion', 'Record correction') }}
                    </button>
                </div>
            </div>
        </div>

        <div v-if="selectedSettlement" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
            <div class="flex max-h-[92vh] w-full max-w-xl flex-col overflow-hidden rounded-2xl border bg-white shadow-xl">
                <div class="flex items-start justify-between gap-4 border-b px-5 py-4">
                    <div>
                        <h2 class="text-lg font-semibold text-gray-900">{{ tr('Registrar transferencia de evento', 'Record Event Transfer') }}</h2>
                        <p class="mt-1 text-sm text-gray-600">{{ selectedSettlement.event_title }}</p>
                    </div>
                    <button type="button" class="text-2xl leading-none text-gray-500 hover:text-gray-700" @click="closeSettlementModal">×</button>
                </div>
                <div class="space-y-4 overflow-y-auto p-5">
                    <div class="rounded-xl border border-gray-200 bg-gray-50 p-3">
                        <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ tr('Monto maximo depositable', 'Maximum depositable amount') }}</p>
                        <p class="mt-1 text-2xl font-semibold text-gray-900">{{ formatMoney(selectedSettlement.pending_settlement_amount) }}</p>
                    </div>
                    <div class="grid gap-4 md:grid-cols-2">
                        <div>
                            <label class="block text-sm font-medium text-gray-700">{{ tr('Fecha', 'Date') }}</label>
                            <input
                                v-model="settlementForm.deposited_at"
                                type="datetime-local"
                                class="mt-1 w-full rounded-xl border border-gray-300 px-3 py-2 text-sm focus:border-red-400 focus:outline-none focus:ring-2 focus:ring-red-100"
                            />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">{{ tr('Referencia', 'Reference') }}</label>
                            <input
                                v-model="settlementForm.reference"
                                type="text"
                                class="mt-1 w-full rounded-xl border border-gray-300 px-3 py-2 text-sm focus:border-red-400 focus:outline-none focus:ring-2 focus:ring-red-100"
                            />
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700">{{ tr('Comprobante', 'Proof') }}</label>
                        <input
                            type="file"
                            accept="image/*,application/pdf"
                            class="mt-1 w-full rounded-xl border border-gray-300 px-3 py-2 text-sm file:mr-3 file:rounded-lg file:border-0 file:bg-gray-100 file:px-3 file:py-1.5 file:text-sm file:font-semibold file:text-gray-700 focus:border-red-400 focus:outline-none focus:ring-2 focus:ring-red-100"
                            @change="onSettlementProofSelected"
                        />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700">{{ tr('Notas', 'Notes') }}</label>
                        <textarea
                            v-model="settlementForm.notes"
                            rows="3"
                            class="mt-1 w-full rounded-xl border border-gray-300 px-3 py-2 text-sm focus:border-red-400 focus:outline-none focus:ring-2 focus:ring-red-100"
                        />
                    </div>
                    <div v-if="settlementError" class="rounded-xl border border-red-200 bg-red-50 p-3 text-sm text-red-700">
                        {{ settlementError }}
                    </div>
                </div>
                <div class="flex flex-col-reverse gap-3 border-t px-5 py-4 sm:flex-row sm:justify-end">
                    <button
                        type="button"
                        class="rounded-xl border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50"
                        @click="closeSettlementModal"
                    >
                        {{ tr('Cancelar', 'Cancel') }}
                    </button>
                    <button
                        type="button"
                        class="rounded-xl bg-red-600 px-4 py-2 text-sm font-semibold text-white hover:bg-red-700 disabled:opacity-60"
                        :disabled="settlementSaving"
                        @click="saveEventSettlement"
                    >
                        {{ settlementSaving ? tr('Guardando...', 'Saving...') : tr('Registrar transferencia', 'Record transfer') }}
                    </button>
                </div>
            </div>
        </div>
    </PathfinderLayout>
</template>
