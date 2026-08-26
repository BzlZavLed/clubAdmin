<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import PathfinderLayout from '@/Layouts/PathfinderLayout.vue'
import MovementInlineEditor from '@/Components/Finance/MovementInlineEditor.vue'
import MovementSummary from '@/Components/Finance/MovementSummary.vue'
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
    ChevronDownIcon,
    ChevronRightIcon,
    ExclamationTriangleIcon,
    QuestionMarkCircleIcon,
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
const selectedNoteMovement = ref(null)
const correctionError = ref('')
const correctionForm = ref({
    correction_date: today(),
    reason: '',
})
const selectedSettlement = ref(null)
const settlementSaving = ref(false)
const settlementError = ref('')
const ledgerPage = ref(1)
const ledgerSearch = ref('')
const ledgerFiltersOpen = ref(false)
const openLedgerAccountSections = ref({})
const ledgerSectionPages = ref({})
const LEDGER_PAGE_SIZE = 25
const LEDGER_NOTE_MAX_LENGTH = 120
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
const tutorialActive = ref(false)
const tutorialStepIndex = ref(0)
const tutorialTargetRect = ref(null)
const tutorialReturnClubId = ref(null)
const tutorialNextId = ref(7000)
const TUTORIAL_CLUB_ID = -9701
const TUTORIAL_ACCOUNT = 'club_budget'
const TUTORIAL_EVENT_ACCOUNT = 'camporee_event'
const ledgerIsAllAccounts = computed(() => ledgerFilters.value.account === 'all')

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
const rawLedgerMovements = computed(() => engineReport.value?.movements || [])
const movementDisplayConcept = (movement) => movement?.display_concept || movement?.concept || movement?.reference || movementTypeLabel(movement?.kind)
const movementNote = (movement) => String(movement?.notes || '').trim()
const movementNoteIsLong = (movement) => movementNote(movement).length > LEDGER_NOTE_MAX_LENGTH
const movementNotePreview = (movement) => {
    const note = movementNote(movement)
    if (note.length <= LEDGER_NOTE_MAX_LENGTH) return note

    return `${note.slice(0, LEDGER_NOTE_MAX_LENGTH).trimEnd()}…`
}
const openMovementNoteModal = (movement) => {
    selectedNoteMovement.value = movement
}
const closeMovementNoteModal = () => {
    selectedNoteMovement.value = null
}
const ledgerMovements = computed(() => {
    const query = ledgerSearch.value.trim().toLowerCase()
    if (!query) return rawLedgerMovements.value

    return rawLedgerMovements.value.filter((movement) => [
        movement?.movement_id,
        movement?.id,
        movementDisplayConcept(movement),
        movement?.concept,
        movement?.original_concept,
        movement?.reference,
        movement?.notes,
        rowCounterparty(movement),
        movementDescription(movement),
        movement?.account,
        movement?.account_label,
        movement?.from_account,
        movement?.from_account_label,
        movement?.to_account,
        movement?.to_account_label,
    ].some((value) => String(value || '').toLowerCase().includes(query)))
})
const ledgerPageCount = computed(() => ledgerIsAllAccounts.value ? 1 : Math.max(Math.ceil(ledgerMovements.value.length / LEDGER_PAGE_SIZE), 1))
const paginatedLedgerMovements = computed(() => {
    if (ledgerIsAllAccounts.value) return ledgerMovements.value

    const start = (ledgerPage.value - 1) * LEDGER_PAGE_SIZE

    return ledgerMovements.value.slice(start, start + LEDGER_PAGE_SIZE)
})
const ledgerPageStart = computed(() => {
    if (!ledgerMovements.value.length) return 0
    if (ledgerIsAllAccounts.value) return 1

    return ((ledgerPage.value - 1) * LEDGER_PAGE_SIZE) + 1
})
const ledgerPageEnd = computed(() => ledgerIsAllAccounts.value
    ? ledgerMovements.value.length
    : Math.min(ledgerPage.value * LEDGER_PAGE_SIZE, ledgerMovements.value.length))
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
const px = (value) => `${Math.round(Number(value) || 0)}px`
const viewportSize = () => {
    if (typeof window === 'undefined') return { width: 1024, height: 768 }

    return {
        width: window.innerWidth || 1024,
        height: window.innerHeight || 768,
    }
}
const tutorialSteps = computed(() => [
    {
        id: 'intro',
        target: '[data-tour="accounting-header"]',
        title: tr('Contabilidad', 'Accounting'),
        body: tr('Modo tutorial usa datos simulados. Puedes practicar filtros, transferencias y lectura del libro sin guardar nada real.', 'Tutorial mode uses simulated data. You can practice filters, transfers, and ledger review without saving anything real.'),
    },
    {
        id: 'module-nav',
        target: '[data-tour="accounting-module-nav"]',
        title: tr('Secciones del modulo', 'Module sections'),
        body: tr('Estos accesos te llevan a transferencias, saldos, libro contable, eventos y entregas de staff.', 'These shortcuts take you to transfers, balances, ledger, events, and staff remittances.'),
    },
    {
        id: 'summary',
        target: '[data-tour="accounting-summary"]',
        title: tr('Resumen general', 'General summary'),
        body: tr('El resumen separa efectivo, banco, total disponible y reembolsos pendientes para revisar la salud de caja.', 'The summary separates cash, bank, total available, and pending reimbursements so you can review cash health.'),
    },
    {
        id: 'transfer-form',
        target: '[data-tour="accounting-transfer-form"]',
        title: tr('Transferencias', 'Transfers'),
        body: tr('Aqui registras movimientos internos como depositar efectivo al banco, retirar efectivo o mover dinero entre cuentas.', 'Here you record internal movements such as depositing cash to bank, withdrawing cash, or moving money between accounts.'),
    },
    {
        id: 'transfer-type',
        target: '[data-tour="accounting-transfer-type"]',
        title: tr('Tipo de movimiento', 'Movement type'),
        body: tr('El tipo define si el dinero cambia de ubicacion dentro de la misma cuenta o si pasa entre cuentas internas.', 'The type defines whether money changes location inside the same account or moves between internal accounts.'),
    },
    {
        id: 'transfer-amount',
        target: '[data-tour="accounting-transfer-amount"]',
        title: tr('Monto y referencia', 'Amount and reference'),
        body: tr('Registra el monto y una referencia de banco, Zelle, deposito o nota interna para dejar trazabilidad.', 'Record the amount and a bank, Zelle, deposit, or internal reference for traceability.'),
    },
    {
        id: 'save-transfer',
        target: '[data-tour="accounting-save-transfer"]',
        title: tr('Guardar movimiento', 'Save movement'),
        body: tr('Haz clic para simular la transferencia. En tutorial se actualizan saldos y libro contable sin llamar la API real.', 'Click to simulate the transfer. In tutorial, balances and the ledger update without calling the real API.'),
    },
    {
        id: 'balances',
        target: '[data-tour="accounting-balances"]',
        title: tr('Saldos por cuenta', 'Account balances'),
        body: tr('Esta lectura muestra cuanto tiene cada cuenta en efectivo y banco despues de cada movimiento.', 'This readout shows how much each account has in cash and bank after each movement.'),
    },
    {
        id: 'ledger-filters',
        target: '[data-tour="accounting-ledger-filters"]',
        title: tr('Filtros del libro', 'Ledger filters'),
        body: tr('Filtra por dominio, cuenta y fechas para auditar una parte especifica del libro contable.', 'Filter by domain, account, and dates to audit a specific part of the ledger.'),
    },
    {
        id: 'ledger',
        target: '[data-tour="accounting-ledger"]',
        title: tr('Libro contable', 'Accounting ledger'),
        body: tr('El libro combina ingresos, gastos, transferencias, soportes, estados y balances despues de cada movimiento.', 'The ledger combines income, expenses, transfers, files, statuses, and balances after each movement.'),
    },
    {
        id: 'events',
        target: '[data-tour="accounting-events"]',
        title: tr('Transferencias de eventos', 'Event transfers'),
        body: tr('Cuando un evento pertenece a otro nivel, aqui veras lo pendiente por depositar con su desglose y cuenta destino.', 'When an event belongs to another level, this shows what remains to deposit with its breakdown and destination account.'),
    },
    {
        id: 'staff',
        target: '[data-tour="accounting-staff"]',
        title: tr('Entregas de staff', 'Staff remittances'),
        body: tr('Valida entregas que personal marco como recibidas para que entren oficialmente al control financiero.', 'Validate remittances staff marked as delivered so they officially enter financial control.'),
    },
    {
        id: 'recent',
        target: '[data-tour="accounting-recent-transfers"]',
        title: tr('Auditoria rapida', 'Quick audit'),
        body: tr('Esta lista deja a la vista las transferencias internas mas recientes registradas desde contabilidad.', 'This list keeps the latest internal transfers recorded from accounting visible.'),
    },
])
const tutorialStep = computed(() => tutorialSteps.value[tutorialStepIndex.value] || tutorialSteps.value[0] || null)
const tutorialStepCount = computed(() => tutorialSteps.value.length)
const tutorialProgressLabel = computed(() => `${tutorialStepIndex.value + 1}/${tutorialStepCount.value}`)
const tutorialCutout = computed(() => {
    if (!tutorialTargetRect.value) return null

    const { width, height } = viewportSize()
    const margin = 8
    const top = Math.max(tutorialTargetRect.value.top - margin, 0)
    const left = Math.max(tutorialTargetRect.value.left - margin, 0)
    const right = Math.min(tutorialTargetRect.value.right + margin, width)
    const bottom = Math.min(tutorialTargetRect.value.bottom + margin, height)

    return {
        top,
        left,
        right,
        bottom,
        width: Math.max(right - left, 0),
        height: Math.max(bottom - top, 0),
    }
})
const tutorialMaskStyles = computed(() => {
    const cutout = tutorialCutout.value
    if (!cutout) return []

    return [
        { top: '0px', left: '0px', width: '100vw', height: px(cutout.top) },
        { top: px(cutout.bottom), left: '0px', width: '100vw', height: `calc(100vh - ${px(cutout.bottom)})` },
        { top: px(cutout.top), left: '0px', width: px(cutout.left), height: px(cutout.height) },
        { top: px(cutout.top), left: px(cutout.right), width: `calc(100vw - ${px(cutout.right)})`, height: px(cutout.height) },
    ]
})
const tutorialHighlightStyle = computed(() => {
    const cutout = tutorialCutout.value
    if (!cutout) return {}

    return {
        top: px(cutout.top),
        left: px(cutout.left),
        width: px(cutout.width),
        height: px(cutout.height),
    }
})
const tutorialPanelStyle = computed(() => {
    const { width, height } = viewportSize()
    const cutout = tutorialCutout.value

    if (width < 640) {
        return {
            left: '1rem',
            right: '1rem',
            bottom: '1rem',
        }
    }

    if (!cutout) {
        return {
            left: '50%',
            top: '50%',
            width: 'min(24rem, calc(100vw - 2rem))',
            transform: 'translate(-50%, -50%)',
        }
    }

    const panelWidth = Math.min(384, Math.max(280, width - 32))
    const estimatedHeight = 236
    const left = Math.min(Math.max(cutout.left, 16), width - panelWidth - 16)
    let top = cutout.bottom + 12

    if (top + estimatedHeight > height && cutout.top > estimatedHeight + 24) {
        top = cutout.top - estimatedHeight - 12
    }

    return {
        left: px(left),
        top: px(Math.max(16, Math.min(top, height - estimatedHeight - 16))),
        width: px(panelWidth),
    }
})
const linkedMovementKey = (movement, type) => {
    const cancellation = movement?.cancellation || {}
    return type === 'correction'
        ? (movement?.related_canceled_movement_key || cancellation.related_canceled_movement_key)
        : (movement?.canceling_movement_key || cancellation.canceling_movement_key || cancellation.reversed_movement_key)
}
const normalizeErrors = (error) => {
    const errors = error?.response?.data?.errors || {}
    return Object.fromEntries(Object.entries(errors).map(([key, value]) => [key, Array.isArray(value) ? value[0] : value]))
}
const setLedgerPage = (page) => {
    ledgerPage.value = Math.min(Math.max(Number(page) || 1, 1), ledgerPageCount.value)
}
const applyMovementEdit = (movementKey, data) => {
    if (!engineReport.value?.movements) return

    engineReport.value = {
        ...engineReport.value,
        movements: engineReport.value.movements.map((movement) => {
            if (movement.movement_id !== movementKey) return movement

            return {
                ...movement,
                original_concept: movement.original_concept || data.original_concept || movement.concept || null,
                display_concept: data.display_concept || movement.concept || null,
                concept_override: data.display_concept ? data : null,
                notes: data.notes || null,
            }
        }),
    }
}
const handleMovementEditUpdated = ({ movementKey, data }) => applyMovementEdit(movementKey, data)
const scrollToLedgerMovement = async (movementKey) => {
    const index = ledgerMovements.value.findIndex((movement) => movement.movement_id === movementKey)
    if (index >= 0) {
        if (ledgerIsAllAccounts.value) {
            const movement = ledgerMovements.value[index]
            const sectionKey = movementAccountKeys(movement)[0]
            if (sectionKey) {
                const section = ledgerAccountGroups.value.find((candidate) => candidate.key === sectionKey)
                openLedgerAccountSections.value = {
                    ...openLedgerAccountSections.value,
                    [sectionKey]: true,
                }
                if (section) {
                    const sectionIndex = section.rows.findIndex((row) => row.movement_id === movementKey)
                    setLedgerSectionPage(section, Math.floor(sectionIndex / LEDGER_PAGE_SIZE) + 1)
                }
            }
        } else {
            setLedgerPage(Math.floor(index / LEDGER_PAGE_SIZE) + 1)
        }
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
const movementBalanceText = (movement) => {
    const balance = movement?.balance_after
    if (!balance) return '—'

    if (movement?.domain === 'transfer') {
        const from = balance.from
        const to = balance.to
        if (!from && !to) return '—'

        if (from?.account && from.account === to?.account) {
            return formatMoney(from.account_balance)
        }

        return [
            `${tr('Origen', 'From')} ${formatMoney(from?.account_balance)}`,
            `${tr('Destino', 'To')} ${formatMoney(to?.account_balance)}`,
        ].join(' · ')
    }

    return balance.account_balance === null || balance.account_balance === undefined
        ? '—'
        : formatMoney(balance.account_balance)
}
const movementAccountKeys = (movement) => {
    if (!movement) return []

    if (movement.domain === 'transfer') {
        return Array.from(new Set([
            movement.from_account || movement.account,
            movement.to_account || movement.account,
        ].filter(Boolean)))
    }

    return [movement.account || movement.from_account || movement.to_account].filter(Boolean)
}
const movementBalanceTextForAccount = (movement, account = null) => {
    if (!account || account === 'all') return movementBalanceText(movement)

    const balance = movement?.balance_after
    if (!balance) return '—'

    if (movement?.domain === 'transfer') {
        if (balance.from?.account === account) return formatMoney(balance.from.account_balance)
        if (balance.to?.account === account) return formatMoney(balance.to.account_balance)

        return '—'
    }

    const movementAccount = movement.account || movement.from_account || movement.to_account
    if (movementAccount && movementAccount !== account) return '—'

    return balance.account_balance === null || balance.account_balance === undefined
        ? '—'
        : formatMoney(balance.account_balance)
}
const ledgerMovementIncomeAmount = (movement) => movement?.domain === 'income' ? Number(movement.amount || 0) : 0
const ledgerMovementExpenseAmount = (movement) => movement?.domain === 'expense' ? Number(movement.amount || 0) : 0
const ledgerMovementTransferAmount = (movement) => movement?.domain === 'transfer' ? Number(movement.amount || 0) : 0
const ledgerAccountGroups = computed(() => {
    const groups = new Map()

    ledgerMovements.value.forEach((movement) => {
        movementAccountKeys(movement).forEach((account) => {
            if (!groups.has(account)) {
                groups.set(account, {
                    key: account,
                    account,
                    label: accountLabel(account),
                    rows: [],
                    totals: {
                        income: 0,
                        expenses: 0,
                        transfers: 0,
                    },
                })
            }

            const group = groups.get(account)
            group.rows.push(movement)
            group.totals.income += ledgerMovementIncomeAmount(movement)
            group.totals.expenses += ledgerMovementExpenseAmount(movement)
            group.totals.transfers += ledgerMovementTransferAmount(movement)
        })
    })

    return Array.from(groups.values())
        .filter((group) => group.rows.length)
        .sort((a, b) => String(a.label).localeCompare(String(b.label)))
})
const ledgerDisplaySections = computed(() => ledgerIsAllAccounts.value
    ? ledgerAccountGroups.value
    : [{
        key: ledgerFilters.value.account,
        account: ledgerFilters.value.account,
        label: null,
        rows: paginatedLedgerMovements.value,
    }])
const ledgerSectionPageCount = (section) => ledgerIsAllAccounts.value
    ? Math.max(Math.ceil(section.rows.length / LEDGER_PAGE_SIZE), 1)
    : 1
const ledgerSectionPage = (section) => Math.min(
    Math.max(Number(ledgerSectionPages.value[section.key]) || 1, 1),
    ledgerSectionPageCount(section),
)
const ledgerSectionRows = (section) => {
    if (!ledgerIsAllAccounts.value) return section.rows

    const start = (ledgerSectionPage(section) - 1) * LEDGER_PAGE_SIZE
    return section.rows.slice(start, start + LEDGER_PAGE_SIZE)
}
const ledgerSectionPageStart = (section) => section.rows.length
    ? ((ledgerSectionPage(section) - 1) * LEDGER_PAGE_SIZE) + 1
    : 0
const ledgerSectionPageEnd = (section) => Math.min(ledgerSectionPage(section) * LEDGER_PAGE_SIZE, section.rows.length)
const setLedgerSectionPage = (section, page) => {
    ledgerSectionPages.value = {
        ...ledgerSectionPages.value,
        [section.key]: Math.min(Math.max(Number(page) || 1, 1), ledgerSectionPageCount(section)),
    }
}
const isLedgerSectionOpen = (section) => !ledgerIsAllAccounts.value || openLedgerAccountSections.value[section.key] === true
const toggleLedgerAccountSection = (section) => {
    openLedgerAccountSections.value = {
        ...openLedgerAccountSections.value,
        [section.key]: !isLedgerSectionOpen(section),
    }
}
const expandAllLedgerSections = () => {
    openLedgerAccountSections.value = Object.fromEntries(ledgerAccountGroups.value.map((section) => [section.key, true]))
}
const collapseAllLedgerSections = () => {
    openLedgerAccountSections.value = {}
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

const tutorialNext = () => {
    tutorialNextId.value += 1
    return tutorialNextId.value
}
const tutorialAccountLabel = (account) => ({
    [TUTORIAL_ACCOUNT]: tr('Presupuesto del club', 'Club budget'),
    [TUTORIAL_EVENT_ACCOUNT]: tr('Camporee', 'Camporee'),
    reimbursement_to: tr('Reembolsos pendientes', 'Pending reimbursements'),
})[account] || account
const tutorialSummaryFromAccounts = (accounts) => {
    const rows = accounts.map((account) => ({
        account: account.account,
        label: account.label,
        cash_balance: Number(account.cash_balance || 0),
        bank_balance: Number(account.bank_balance || 0),
        total_available: Number(account.cash_balance || 0) + Number(account.bank_balance || 0),
    }))

    return {
        cash_balance: rows.reduce((sum, account) => sum + Number(account.cash_balance || 0), 0),
        bank_balance: rows.reduce((sum, account) => sum + Number(account.bank_balance || 0), 0),
        total_available: rows.reduce((sum, account) => sum + Number(account.total_available || 0), 0),
        accounts: rows,
    }
}
const tutorialLedgerMovement = (overrides) => ({
    id: tutorialNext(),
    movement_id: `tutorial-${tutorialNextId.value}`,
    domain: 'transfer',
    kind: 'transfer',
    date: today(),
    status: 'posted',
    amount: 0,
    signed_amount: 0,
    reference: '',
    concept: tr('Transferencia tutorial', 'Tutorial transfer'),
    account: TUTORIAL_ACCOUNT,
    account_label: tutorialAccountLabel(TUTORIAL_ACCOUNT),
    location: 'cash',
    balance_after: null,
    can_reverse: false,
    correction_type: null,
    ...overrides,
})
const tutorialApplyTreasury = ({ accounts, movements, ledger, eventRows, staffRows }) => {
    treasury.value = {
        club: { id: TUTORIAL_CLUB_ID, club_name: tr('Club Tutorial', 'Tutorial Club') },
        bank_info: {
            bank_name: 'Banco Tutorial',
            account_holder: tr('Club Tutorial', 'Tutorial Club'),
            account_type: tr('Corriente', 'Checking'),
            account_number: '****1234',
            zelle_email: 'tutorial@example.com',
        },
        accounts: accounts.map((account) => ({
            value: account.account,
            label: account.label,
        })),
        summary: tutorialSummaryFromAccounts(accounts),
        pending_staff_remittances: staffRows,
        movements,
    }
    accountReport.value = { accounts }
    engineReport.value = { movements: ledger }
    eventSettlementRows.value = eventRows
    openLedgerAccountSections.value = {}
    ledgerSectionPages.value = {}
}
const tutorialResetSandbox = () => {
    const accounts = [
        { account: TUTORIAL_ACCOUNT, label: tutorialAccountLabel(TUTORIAL_ACCOUNT), cash_balance: 180, bank_balance: 420 },
        { account: TUTORIAL_EVENT_ACCOUNT, label: tutorialAccountLabel(TUTORIAL_EVENT_ACCOUNT), cash_balance: 35, bank_balance: 125 },
        { account: 'reimbursement_to', label: tutorialAccountLabel('reimbursement_to'), cash_balance: -40, bank_balance: 0 },
    ]
    const ledger = [
        tutorialLedgerMovement({
            movement_id: 'tutorial-income-7001',
            domain: 'income',
            kind: 'income',
            concept: tr('Cuota mensual tutorial', 'Tutorial monthly dues'),
            account: TUTORIAL_ACCOUNT,
            account_label: tutorialAccountLabel(TUTORIAL_ACCOUNT),
            location: 'cash',
            amount: 25,
            signed_amount: 25,
            balance_after: { account: TUTORIAL_ACCOUNT, account_balance: 600 },
            rowCounterparty: 'Ana Gomez',
        }),
        tutorialLedgerMovement({
            movement_id: 'tutorial-expense-7002',
            domain: 'expense',
            kind: 'expense',
            concept: tr('Materiales de clase tutorial', 'Tutorial class supplies'),
            account: TUTORIAL_ACCOUNT,
            account_label: tutorialAccountLabel(TUTORIAL_ACCOUNT),
            location: 'cash',
            amount: 45,
            signed_amount: -45,
            balance_after: { account: TUTORIAL_ACCOUNT, account_balance: 575 },
        }),
    ]
    const movements = [
        {
            id: 7003,
            movement_type: 'cash_deposit',
            pay_to: TUTORIAL_ACCOUNT,
            from_location: 'cash',
            to_location: 'bank',
            amount: 75,
            movement_date: today(),
            reference: 'DEP-TUTORIAL-01',
        },
    ]
    const eventRows = [{
        event_id: 701,
        club_id: TUTORIAL_CLUB_ID,
        event_title: tr('Camporee tutorial', 'Tutorial camporee'),
        organizer_label: tr('Asociacion Tutorial', 'Tutorial Association'),
        pending_settlement_amount: 95,
        pending_settlement_breakdown: [
            { label: tr('Inscripcion', 'Registration'), amount: 75 },
            { label: tr('Actividad opcional', 'Optional activity'), amount: 20 },
        ],
        paid_members_count: 3,
        paid_members_total: 95,
        paid_members: [
            { member_id: 1, name: 'Ana Gomez', total_paid: 35, payments_count: 1, last_payment_date: today(), payment_types: ['cash'] },
            { member_id: 2, name: 'Luis Perez', total_paid: 30, payments_count: 1, last_payment_date: today(), payment_types: ['zelle'] },
        ],
        organizer_bank_info: {
            label: tr('Cuenta de asociacion', 'Association account'),
            bank_name: 'Banco Tutorial',
            account_holder: tr('Asociacion Tutorial', 'Tutorial Association'),
            account_number: '****9876',
            zelle_email: 'association@example.com',
        },
        settlement_receipts: [],
    }]
    const staffRows = [{
        batch_id: 'tutorial-staff-1',
        staff_name: 'Instructor Tutorial',
        amount: 30,
        count: 2,
        remittance_method: 'cash',
        remittance_reference: 'STAFF-TUTORIAL',
        remitted_at: today(),
    }]

    selectedClubId.value = TUTORIAL_CLUB_ID
    clubs.value = [{ id: TUTORIAL_CLUB_ID, club_name: tr('Club Tutorial', 'Tutorial Club') }]
    ledgerFilters.value = { domain: 'all', account: 'all', date_from: '', date_to: '' }
    ledgerPage.value = 1
    tutorialNextId.value = 7003
    tutorialApplyTreasury({ accounts, movements, ledger, eventRows, staffRows })
    resetMovementForm()
    movementForm.value = {
        ...movementForm.value,
        movement_type: 'cash_deposit',
        pay_to: TUTORIAL_ACCOUNT,
        from_pay_to: TUTORIAL_ACCOUNT,
        to_pay_to: '',
        from_location: 'cash',
        to_location: 'bank',
        amount: '75.00',
        movement_date: today(),
        reference: 'DEP-TUTORIAL-02',
        notes: tr('Practica: deposito de efectivo a banco', 'Practice: cash deposit to bank'),
    }
}
const updateTutorialTarget = (scrollIntoView = false) => {
    if (typeof window === 'undefined' || !tutorialActive.value || !tutorialStep.value) return

    nextTick(() => {
        const target = document.querySelector(tutorialStep.value.target)
        if (!target) {
            tutorialTargetRect.value = null
            return
        }

        if (scrollIntoView) {
            target.scrollIntoView({ behavior: 'smooth', block: 'center', inline: 'nearest' })
        }

        window.setTimeout(() => {
            const rect = target.getBoundingClientRect()
            if (!rect.width && !rect.height) {
                tutorialTargetRect.value = null
                return
            }

            tutorialTargetRect.value = {
                top: rect.top,
                left: rect.left,
                right: rect.right,
                bottom: rect.bottom,
                width: rect.width,
                height: rect.height,
            }
        }, scrollIntoView ? 260 : 0)
    })
}
const startAccountingTutorial = () => {
    if (!tutorialActive.value) {
        tutorialReturnClubId.value = selectedClubId.value
    }
    tutorialResetSandbox()
    tutorialStepIndex.value = 0
    tutorialActive.value = true
    updateTutorialTarget(true)
}
const closeAccountingTutorial = () => {
    const returnClubId = tutorialReturnClubId.value
    tutorialActive.value = false
    tutorialTargetRect.value = null
    tutorialReturnClubId.value = null
    selectedClubId.value = returnClubId
    loadData()
}
const previousTutorialStep = () => {
    tutorialStepIndex.value = Math.max(tutorialStepIndex.value - 1, 0)
}
const goToTutorialStep = (id) => {
    const index = tutorialSteps.value.findIndex((step) => step.id === id)
    if (index >= 0) tutorialStepIndex.value = index
}
const nextTutorialStep = () => {
    if (tutorialStepIndex.value >= tutorialStepCount.value - 1) {
        closeAccountingTutorial()
        return
    }

    tutorialStepIndex.value += 1
}
const handleTutorialViewportChange = () => {
    if (tutorialActive.value) updateTutorialTarget(false)
}
const handleTutorialKeydown = (event) => {
    if (event.key === 'Escape' && selectedNoteMovement.value) {
        closeMovementNoteModal()
        return
    }
    if (!tutorialActive.value) return
    if (event.key === 'Escape') closeAccountingTutorial()
    if (event.key === 'ArrowRight') nextTutorialStep()
    if (event.key === 'ArrowLeft') previousTutorialStep()
}
const tutorialCreateTransfer = async () => {
    await new Promise((resolve) => window.setTimeout(resolve, 250))
    const amount = Number(movementForm.value.amount || 0)
    const payTo = movementForm.value.pay_to || TUTORIAL_ACCOUNT
    const accounts = summaryAccounts.value.map((account) => ({ ...account }))
    const account = accounts.find((row) => row.account === payTo)

    if (account && movementForm.value.movement_type === 'cash_deposit') {
        account.cash_balance = Number(account.cash_balance || 0) - amount
        account.bank_balance = Number(account.bank_balance || 0) + amount
        account.total_available = Number(account.cash_balance || 0) + Number(account.bank_balance || 0)
    }

    const id = tutorialNext()
    const transfer = {
        id,
        movement_type: movementForm.value.movement_type,
        pay_to: payTo,
        from_pay_to: movementForm.value.from_pay_to,
        to_pay_to: movementForm.value.to_pay_to,
        from_location: movementForm.value.from_location,
        to_location: movementForm.value.to_location,
        amount,
        movement_date: movementForm.value.movement_date,
        reference: movementForm.value.reference,
    }
    const ledger = [
        tutorialLedgerMovement({
            id,
            movement_id: `tutorial-transfer-${id}`,
            concept: movementTypeLabel(movementForm.value.movement_type),
            movement_type: movementForm.value.movement_type,
            amount,
            signed_amount: 0,
            reference: movementForm.value.reference,
            from_account: payTo,
            to_account: payTo,
            from_account_label: tutorialAccountLabel(payTo),
            to_account_label: tutorialAccountLabel(payTo),
            from_location: movementForm.value.from_location,
            to_location: movementForm.value.to_location,
            balance_after: {
                from: { account: payTo, account_balance: account?.total_available ?? 0 },
                to: { account: payTo, account_balance: account?.total_available ?? 0 },
            },
        }),
        ...ledgerMovements.value,
    ]

    tutorialApplyTreasury({
        accounts,
        movements: [transfer, ...recentTreasuryMovements.value],
        ledger,
        eventRows: eventSettlementRows.value,
        staffRows: pendingStaffRemittances.value,
    })
}

async function loadLedger() {
    if (tutorialActive.value) {
        showToast(tr('Filtros tutorial aplicados.', 'Tutorial filters applied.'), 'success')
        return
    }

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
        openLedgerAccountSections.value = {}
        ledgerSectionPages.value = {}
    } catch (error) {
        console.error(error)
        showToast(error?.response?.data?.message || tr('No se pudo cargar el libro contable.', 'Could not load the ledger.'), 'error')
    } finally {
        ledgerLoading.value = false
    }
}

async function loadData() {
    if (tutorialActive.value) {
        tutorialResetSandbox()
        return
    }

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
        openLedgerAccountSections.value = {}
        ledgerSectionPages.value = {}
        ledgerPage.value = 1
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
        if (tutorialActive.value) {
            await tutorialCreateTransfer()
            showToast(tr('Movimiento tutorial registrado.', 'Tutorial movement recorded.'), 'success')
            resetMovementForm()
            goToTutorialStep('balances')
            return
        }

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
        if (tutorialActive.value) {
            await new Promise((resolve) => window.setTimeout(resolve, 250))
            tutorialApplyTreasury({
                accounts: summaryAccounts.value,
                movements: recentTreasuryMovements.value,
                ledger: ledgerMovements.value,
                eventRows: eventSettlementRows.value,
                staffRows: pendingStaffRemittances.value.filter((row) => row.batch_id !== batch.batch_id),
            })
            showToast(tr('Entrega tutorial validada.', 'Tutorial remittance validated.'), 'success')
            return
        }

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
        if (tutorialActive.value) {
            await new Promise((resolve) => window.setTimeout(resolve, 250))
            tutorialApplyTreasury({
                accounts: summaryAccounts.value,
                movements: recentTreasuryMovements.value,
                ledger: ledgerMovements.value,
                eventRows: eventSettlementRows.value.map((row) => row.event_id === selectedSettlement.value.event_id
                    ? {
                        ...row,
                        pending_settlement_amount: 0,
                        settlement_receipts: [
                            ...(row.settlement_receipts || []),
                            { id: tutorialNext(), receipt_number: 'TUTORIAL-EVENT-001', receipt_url: 'data:text/plain;charset=utf-8,Recibo%20tutorial' },
                        ],
                    }
                    : row),
                staffRows: pendingStaffRemittances.value,
            })
            showToast(tr('Transferencia de evento tutorial registrada.', 'Tutorial event transfer recorded.'), 'success')
            closeSettlementModal()
            return
        }

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

watch(ledgerSearch, () => {
    ledgerPage.value = 1
    ledgerSectionPages.value = {}
})

watch(ledgerMovements, () => {
    if (ledgerPage.value > ledgerPageCount.value) {
        setLedgerPage(ledgerPageCount.value)
    }
})

watch([tutorialActive, tutorialStepIndex], ([active]) => {
    if (active) updateTutorialTarget(true)
})

onMounted(() => {
    loadData()
    window.addEventListener('resize', handleTutorialViewportChange)
    window.addEventListener('scroll', handleTutorialViewportChange, true)
    window.addEventListener('keydown', handleTutorialKeydown)
})

onBeforeUnmount(() => {
    window.removeEventListener('resize', handleTutorialViewportChange)
    window.removeEventListener('scroll', handleTutorialViewportChange, true)
    window.removeEventListener('keydown', handleTutorialKeydown)
})
</script>

<template>
    <PathfinderLayout>
        <template #title>{{ tr('Contabilidad', 'Accounting') }}</template>

        <div class="space-y-6">
            <section data-tour="accounting-header" class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm">
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
                                    :disabled="tutorialActive"
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
                            <button
                                type="button"
                                class="inline-flex min-h-10 items-center justify-center gap-2 rounded-xl border border-red-200 bg-red-50 px-4 py-2 text-sm font-semibold text-red-700 hover:bg-red-100"
                                @click="startAccountingTutorial"
                            >
                                <QuestionMarkCircleIcon class="h-4 w-4" />
                                <span>{{ tutorialActive ? tr('Reiniciar tutorial', 'Restart tutorial') : tr('Modo tutorial', 'Tutorial mode') }}</span>
                            </button>
                        </div>
                    </div>
                </div>

                <div data-tour="accounting-module-nav" class="grid gap-3 p-4 sm:grid-cols-2 md:grid-cols-3 xl:grid-cols-5">
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

            <div v-if="tutorialActive" class="rounded-2xl border border-red-200 bg-red-50 px-4 py-3">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <p class="text-sm font-semibold text-red-900">{{ tr('Modo tutorial activo', 'Tutorial mode active') }}</p>
                        <p class="mt-1 text-sm text-red-800">
                            {{ tr('Transferencias, eventos y validaciones son simuladas. Al salir se borra la practica y se recarga contabilidad real.', 'Transfers, events, and validations are simulated. Exiting clears practice data and reloads real accounting.') }}
                        </p>
                    </div>
                    <button
                        type="button"
                        class="inline-flex min-h-10 items-center justify-center rounded-xl bg-red-700 px-3 py-2 text-sm font-semibold text-white hover:bg-red-800"
                        @click="closeAccountingTutorial"
                    >
                        {{ tr('Salir y borrar practica', 'Exit and clear practice') }}
                    </button>
                </div>
            </div>

            <section data-tour="accounting-summary" class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
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
                <article data-tour="accounting-transfer-form" class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm">
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
                        <div data-tour="accounting-transfer-type">
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

                        <div data-tour="accounting-transfer-amount" class="grid gap-3 sm:grid-cols-2">
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
                            data-tour="accounting-save-transfer"
                            class="inline-flex min-h-11 w-full items-center justify-center rounded-xl bg-red-600 px-4 py-2 text-sm font-semibold text-white hover:bg-red-700 disabled:opacity-60"
                            :disabled="savingMovement"
                        >
                            {{ savingMovement ? tr('Guardando...', 'Saving...') : tr('Guardar movimiento', 'Save movement') }}
                        </button>
                    </form>
                </article>

                <article id="accounting-balances" data-tour="accounting-balances" class="scroll-mt-4 rounded-2xl border border-gray-200 bg-white p-4 shadow-sm">
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

                </article>
            </section>

            <section id="accounting-ledger" data-tour="accounting-ledger" class="min-w-0 max-w-full scroll-mt-4 overflow-hidden rounded-2xl border border-gray-200 bg-white p-4 shadow-sm">
                <div class="mb-4 flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
                    <div>
                        <h2 class="text-lg font-semibold text-gray-900">{{ tr('Libro contable normalizado', 'Normalized Accounting Ledger') }}</h2>
                        <p class="text-sm text-gray-600">
                            {{ tr('Vista unificada de ingresos, gastos, transferencias, recibos, comprobantes y correcciones vinculadas.', 'Unified view of income, expenses, transfers, receipts, proofs, and linked corrections.') }}
                        </p>
                    </div>
                    <button
                        type="button"
                        class="inline-flex min-h-10 items-center justify-center gap-2 rounded-xl border border-gray-200 px-3 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 lg:hidden"
                        @click="ledgerFiltersOpen = !ledgerFiltersOpen"
                    >
                        <ChevronDownIcon v-if="ledgerFiltersOpen" class="h-4 w-4" />
                        <ChevronRightIcon v-else class="h-4 w-4" />
                        {{ tr('Filtros y busqueda', 'Filters and search') }}
                    </button>
                    <div
                        data-tour="accounting-ledger-filters"
                        class="grid gap-2 sm:grid-cols-2 lg:grid lg:grid-cols-6"
                        :class="ledgerFiltersOpen ? 'grid' : 'hidden'"
                    >
                        <input
                            v-model="ledgerSearch"
                            type="search"
                            class="rounded-xl border border-gray-300 px-3 py-2 text-sm focus:border-red-400 focus:outline-none focus:ring-2 focus:ring-red-100 lg:col-span-2"
                            :placeholder="tr('Buscar ID, concepto, nota o tercero', 'Search ID, concept, note, or counterparty')"
                        />
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

                <div v-if="ledgerMovements.length && ledgerIsAllAccounts && ledgerAccountGroups.length" class="mb-3 flex flex-col gap-2 rounded-xl border border-gray-200 bg-gray-50 px-3 py-2 text-xs text-gray-600 sm:flex-row sm:items-center sm:justify-between">
                    <span>
                        {{ tr('Las cuentas estan colapsadas para facilitar la revision.', 'Accounts are collapsed to make review easier.') }}
                    </span>
                    <div class="flex gap-2">
                        <button
                            type="button"
                            class="rounded-lg border border-gray-200 bg-white px-3 py-1.5 font-semibold text-gray-700 hover:bg-gray-50"
                            @click="expandAllLedgerSections"
                        >
                            {{ tr('Abrir todas', 'Open all') }}
                        </button>
                        <button
                            type="button"
                            class="rounded-lg border border-gray-200 bg-white px-3 py-1.5 font-semibold text-gray-700 hover:bg-gray-50"
                            @click="collapseAllLedgerSections"
                        >
                            {{ tr('Cerrar todas', 'Close all') }}
                        </button>
                    </div>
                </div>

                <div v-if="ledgerMovements.length" class="min-w-0 space-y-3 xl:hidden">
                    <template v-for="section in ledgerDisplaySections" :key="section.key">
                        <button
                            v-if="section.label"
                            type="button"
                            class="flex w-full items-center justify-between gap-3 rounded-xl bg-gray-100 px-3 py-2 text-left text-xs font-semibold uppercase tracking-wide text-gray-700 hover:bg-gray-200"
                            @click="toggleLedgerAccountSection(section)"
                        >
                            <span class="flex min-w-0 items-center gap-2">
                                <ChevronDownIcon v-if="isLedgerSectionOpen(section)" class="h-4 w-4 shrink-0" />
                                <ChevronRightIcon v-else class="h-4 w-4 shrink-0" />
                                <span class="truncate">{{ section.label }}</span>
                                <span class="shrink-0 rounded-full bg-white px-2 py-0.5 text-[11px] normal-case tracking-normal text-gray-600">
                                    {{ section.rows.length }} {{ tr('movimientos', 'movements') }}
                                </span>
                            </span>
                        </button>
                        <template v-if="isLedgerSectionOpen(section)">
                            <article
                                v-for="movement in ledgerSectionRows(section)"
                                :key="`${section.key}-${movement.movement_id}`"
                                :data-ledger-movement="movement.movement_id"
                                class="rounded-xl border border-gray-200 p-3 scroll-mt-24"
                            >
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <MovementSummary
                                            :movement="movement"
                                            :show-reference="false"
                                            :show-notes="false"
                                            notes-class="mt-1 text-sm text-gray-600"
                                        />
                                        <MovementInlineEditor
                                            v-if="!tutorialActive"
                                            :movement="movement"
                                            :club-id="selectedClubId"
                                            compact
                                            panel-class="basis-full"
                                            @updated="handleMovementEditUpdated"
                                        />
                                    </div>
                                    <p v-if="movementNote(movement)" class="mt-1 break-words text-sm text-gray-600">
                                        <span class="font-medium">{{ tr('Notas', 'Notes') }}:</span>
                                        <button
                                            v-if="movementNoteIsLong(movement)"
                                            type="button"
                                            class="text-left text-red-700 underline decoration-red-300 underline-offset-2 hover:text-red-800"
                                            @click="openMovementNoteModal(movement)"
                                        >
                                            {{ movementNotePreview(movement) }}
                                            <span class="font-semibold">{{ tr('Ver nota completa', 'View full note') }}</span>
                                        </button>
                                        <span v-else>{{ movementNote(movement) }}</span>
                                    </p>
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
                            <p class="mt-1 text-sm font-semibold text-gray-800">
                                {{ tr('Balance', 'Balance') }}: {{ movementBalanceTextForAccount(movement, section.account) }}
                            </p>
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
                            <div
                                v-if="ledgerIsAllAccounts && ledgerSectionPageCount(section) > 1"
                                class="flex flex-col gap-2 rounded-xl border border-gray-200 bg-gray-50 p-3 sm:flex-row sm:items-center sm:justify-between"
                            >
                                <p class="text-xs text-gray-600">
                                    {{ tr('Mostrando', 'Showing') }}
                                    <span class="font-semibold text-gray-900">{{ ledgerSectionPageStart(section) }}-{{ ledgerSectionPageEnd(section) }}</span>
                                    {{ tr('de', 'of') }}
                                    <span class="font-semibold text-gray-900">{{ section.rows.length }}</span>
                                </p>
                                <div class="flex items-center gap-2">
                                    <button
                                        type="button"
                                        class="rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-xs font-semibold text-gray-700 disabled:opacity-50"
                                        :disabled="ledgerSectionPage(section) <= 1"
                                        @click="setLedgerSectionPage(section, ledgerSectionPage(section) - 1)"
                                    >
                                        {{ tr('Anterior', 'Previous') }}
                                    </button>
                                    <span class="text-xs font-semibold text-gray-700">
                                        {{ ledgerSectionPage(section) }} / {{ ledgerSectionPageCount(section) }}
                                    </span>
                                    <button
                                        type="button"
                                        class="rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-xs font-semibold text-gray-700 disabled:opacity-50"
                                        :disabled="ledgerSectionPage(section) >= ledgerSectionPageCount(section)"
                                        @click="setLedgerSectionPage(section, ledgerSectionPage(section) + 1)"
                                    >
                                        {{ tr('Siguiente', 'Next') }}
                                    </button>
                                </div>
                            </div>
                        </template>
                    </template>
                </div>

                <div v-if="ledgerMovements.length" class="hidden min-w-0 max-w-full overflow-hidden xl:block">
                    <table class="w-full table-fixed text-xs text-gray-700 2xl:text-sm">
                        <colgroup>
                            <col class="w-[8%]" />
                            <col class="w-[8%]" />
                            <col class="w-[18%]" />
                            <col class="w-[14%]" />
                            <col class="w-[11%]" />
                            <col class="w-[9%]" />
                            <col class="w-[10%]" />
                            <col class="w-[9%]" />
                            <col class="w-[7%]" />
                            <col class="w-[6%]" />
                        </colgroup>
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="break-words px-2 py-2 text-left font-semibold">{{ tr('Fecha', 'Date') }}</th>
                                <th class="break-words px-2 py-2 text-left font-semibold">{{ tr('Tipo', 'Type') }}</th>
                                <th class="break-words px-2 py-2 text-left font-semibold">{{ tr('Concepto', 'Concept') }}</th>
                                <th class="break-words px-2 py-2 text-left font-semibold">{{ tr('Cuenta / ubicacion', 'Account / location') }}</th>
                                <th class="break-words px-2 py-2 text-left font-semibold">{{ tr('Tercero', 'Counterparty') }}</th>
                                <th class="break-words px-2 py-2 text-right font-semibold">{{ tr('Monto', 'Amount') }}</th>
                                <th class="break-words px-2 py-2 text-right font-semibold">{{ tr('Balance', 'Balance') }}</th>
                                <th class="break-words px-2 py-2 text-left font-semibold">{{ tr('Estatus', 'Status') }}</th>
                                <th class="break-words px-2 py-2 text-left font-semibold">{{ tr('Soportes', 'Files') }}</th>
                                <th class="break-words px-2 py-2 text-right font-semibold">{{ tr('Accion', 'Action') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            <template v-for="section in ledgerDisplaySections" :key="section.key">
                                <tr v-if="section.label" class="bg-gray-100">
                                    <td colspan="10" class="px-3 py-2 text-xs font-semibold uppercase tracking-wide text-gray-700">
                                        <button
                                            type="button"
                                            class="flex w-full flex-wrap items-center justify-between gap-2 text-left hover:text-gray-950"
                                            @click="toggleLedgerAccountSection(section)"
                                        >
                                            <span class="flex min-w-0 items-center gap-2">
                                                <ChevronDownIcon v-if="isLedgerSectionOpen(section)" class="h-4 w-4 shrink-0" />
                                                <ChevronRightIcon v-else class="h-4 w-4 shrink-0" />
                                                <span class="truncate">{{ section.label }}</span>
                                                <span class="shrink-0 rounded-full bg-white px-2 py-0.5 text-[11px] normal-case tracking-normal text-gray-600">
                                                    {{ section.rows.length }} {{ tr('movimientos', 'movements') }}
                                                </span>
                                            </span>
                                            <span class="ml-auto flex flex-wrap justify-end gap-x-3 gap-y-1 text-[11px] normal-case tracking-normal text-gray-600">
                                                <span class="text-emerald-700">{{ formatMoney(section.totals.income) }}</span>
                                                <span class="text-red-700">-{{ formatMoney(section.totals.expenses) }}</span>
                                                <span class="text-sky-700">{{ formatMoney(section.totals.transfers) }}</span>
                                            </span>
                                        </button>
                                    </td>
                                </tr>
                                <template v-if="isLedgerSectionOpen(section)">
                                    <tr
                                        v-for="movement in ledgerSectionRows(section)"
                                        :key="`${section.key}-${movement.movement_id}`"
                                        :data-ledger-movement="movement.movement_id"
                                        class="scroll-mt-24"
                                    >
                                    <td class="break-words px-2 py-2 align-top">{{ formatDate(movement.date) }}</td>
                                    <td class="break-words px-2 py-2 align-top">{{ domainLabel(movement.domain) }}</td>
                                    <td class="min-w-0 break-words px-2 py-2 align-top">
                                        <div class="flex items-start gap-2">
                                            <div class="min-w-0">
                                                <MovementSummary
                                                    :movement="movement"
                                                    :show-notes="false"
                                                    title-class="font-medium text-gray-900"
                                                />
                                                <p v-if="movementNote(movement)" class="mt-1 break-words text-xs text-gray-500">
                                                    <span class="font-medium">{{ tr('Notas', 'Notes') }}:</span>
                                                    <button
                                                        v-if="movementNoteIsLong(movement)"
                                                        type="button"
                                                        class="text-left text-red-700 underline decoration-red-300 underline-offset-2 hover:text-red-800"
                                                        @click="openMovementNoteModal(movement)"
                                                    >
                                                        {{ movementNotePreview(movement) }}
                                                        <span class="font-semibold">{{ tr('Ver nota completa', 'View full note') }}</span>
                                                    </button>
                                                    <span v-else>{{ movementNote(movement) }}</span>
                                                </p>
                                            </div>
                                            <MovementInlineEditor
                                                v-if="!tutorialActive"
                                                :movement="movement"
                                                :club-id="selectedClubId"
                                                compact
                                                panel-class="basis-full"
                                                @updated="handleMovementEditUpdated"
                                            />
                                        </div>
                                    </td>
                                    <td class="break-words px-2 py-2 align-top">{{ movementDescription(movement) }}</td>
                                    <td class="break-words px-2 py-2 align-top">{{ rowCounterparty(movement) }}</td>
                                    <td
                                        class="break-words px-2 py-2 text-right align-top font-semibold"
                                        :class="Number(movement.signed_amount) < 0 ? 'text-red-700' : Number(movement.signed_amount) > 0 ? 'text-emerald-700' : 'text-gray-900'"
                                    >
                                        {{ formatMoney(movement.amount) }}
                                    </td>
                                    <td class="break-words px-2 py-2 text-right align-top font-semibold text-gray-900">
                                        {{ movementBalanceTextForAccount(movement, section.account) }}
                                    </td>
                                    <td class="min-w-0 break-words px-2 py-2 align-top">
                                        <div>
                                            <span
                                                class="inline-flex max-w-full break-words rounded-full px-2 py-0.5 text-[11px] font-semibold"
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
                                    <td class="min-w-0 break-words px-2 py-2 align-top">
                                        <div class="flex min-w-0 flex-col gap-1">
                                            <a v-if="movement.receipt?.url" :href="movement.receipt.url" target="_blank" rel="noopener" class="break-all font-semibold text-red-700">
                                                {{ tr('Recibo', 'Receipt') }}
                                            </a>
                                            <a v-if="movement.proof?.url" :href="movement.proof.url" target="_blank" rel="noopener" class="break-all font-semibold text-red-700">
                                                {{ tr('Comprobante', 'Proof') }}
                                            </a>
                                        </div>
                                    </td>
                                    <td class="px-1 py-2 text-right align-top">
                                        <button
                                            v-if="canCorrectMovement(movement)"
                                            type="button"
                                            class="inline-flex min-h-8 max-w-full items-center justify-center break-words rounded-lg border border-red-200 px-2 py-1 text-[11px] font-semibold text-red-700 hover:bg-red-50"
                                            @click="openCorrectionModal(movement)"
                                        >
                                            {{ tr('Corregir', 'Correct') }}
                                        </button>
                                        <span v-else class="text-xs text-gray-400">—</span>
                                    </td>
                                    </tr>
                                    <tr v-if="ledgerIsAllAccounts && ledgerSectionPageCount(section) > 1" class="bg-gray-50">
                                        <td colspan="10" class="px-3 py-2">
                                            <div class="flex items-center justify-between gap-3 text-xs text-gray-600">
                                                <span>
                                                    {{ tr('Mostrando', 'Showing') }}
                                                    <strong class="text-gray-900">{{ ledgerSectionPageStart(section) }}-{{ ledgerSectionPageEnd(section) }}</strong>
                                                    {{ tr('de', 'of') }}
                                                    <strong class="text-gray-900">{{ section.rows.length }}</strong>
                                                </span>
                                                <div class="flex items-center gap-2">
                                                    <button
                                                        type="button"
                                                        class="rounded-lg border border-gray-300 bg-white px-2 py-1 font-semibold text-gray-700 disabled:opacity-50"
                                                        :disabled="ledgerSectionPage(section) <= 1"
                                                        @click="setLedgerSectionPage(section, ledgerSectionPage(section) - 1)"
                                                    >
                                                        {{ tr('Anterior', 'Previous') }}
                                                    </button>
                                                    <span class="font-semibold text-gray-700">{{ ledgerSectionPage(section) }} / {{ ledgerSectionPageCount(section) }}</span>
                                                    <button
                                                        type="button"
                                                        class="rounded-lg border border-gray-300 bg-white px-2 py-1 font-semibold text-gray-700 disabled:opacity-50"
                                                        :disabled="ledgerSectionPage(section) >= ledgerSectionPageCount(section)"
                                                        @click="setLedgerSectionPage(section, ledgerSectionPage(section) + 1)"
                                                    >
                                                        {{ tr('Siguiente', 'Next') }}
                                                    </button>
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                </template>
                            </template>
                        </tbody>
                    </table>
                </div>

                <div
                    v-if="ledgerMovements.length && (ledgerIsAllAccounts || ledgerMovements.length > LEDGER_PAGE_SIZE)"
                    class="mt-4 flex flex-col gap-3 border-t border-gray-100 pt-4 sm:flex-row sm:items-center sm:justify-between"
                >
                    <p v-if="ledgerIsAllAccounts" class="text-sm text-gray-600">
                        <span class="font-semibold text-gray-900">{{ ledgerMovements.length }}</span>
                        {{ tr('movimientos agrupados en', 'movements grouped into') }}
                        <span class="font-semibold text-gray-900">{{ ledgerAccountGroups.length }}</span>
                        {{ tr('cuentas', 'accounts') }}
                    </p>
                    <p v-else class="text-sm text-gray-600">
                        {{ tr('Mostrando', 'Showing') }}
                        <span class="font-semibold text-gray-900">{{ ledgerPageStart }}-{{ ledgerPageEnd }}</span>
                        {{ tr('de', 'of') }}
                        <span class="font-semibold text-gray-900">{{ ledgerMovements.length }}</span>
                        {{ tr('movimientos', 'movements') }}
                    </p>
                    <div v-if="!ledgerIsAllAccounts" class="flex items-center gap-2">
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

            <section id="accounting-events" data-tour="accounting-events" class="scroll-mt-4 rounded-2xl border border-gray-200 bg-white p-4 shadow-sm">
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

            <section id="accounting-staff" data-tour="accounting-staff" class="scroll-mt-4 rounded-2xl border border-gray-200 bg-white p-4 shadow-sm">
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

            <section data-tour="accounting-recent-transfers" class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm">
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

        <div v-if="tutorialActive && tutorialStep" class="pointer-events-none fixed inset-0 z-[70]">
            <template v-if="tutorialCutout">
                <div
                    v-for="(style, index) in tutorialMaskStyles"
                    :key="index"
                    class="pointer-events-auto fixed bg-gray-950/70"
                    :style="style"
                ></div>
                <div
                    class="pointer-events-none fixed rounded-xl border-2 border-red-500 shadow-[0_0_0_4px_rgba(248,113,113,0.35),0_18px_45px_rgba(15,23,42,0.35)]"
                    :style="tutorialHighlightStyle"
                ></div>
            </template>
            <div v-else class="pointer-events-auto fixed inset-0 bg-gray-950/70"></div>

            <aside
                class="pointer-events-auto fixed rounded-xl border border-gray-200 bg-white p-4 shadow-2xl"
                :style="tutorialPanelStyle"
                role="dialog"
                :aria-label="tr('Tutorial de contabilidad', 'Accounting tutorial')"
            >
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wide text-red-600">
                            {{ tr('Tutorial de contabilidad', 'Accounting tutorial') }} · {{ tutorialProgressLabel }}
                        </p>
                        <h3 class="mt-1 text-base font-semibold text-gray-950">{{ tutorialStep.title }}</h3>
                    </div>
                    <button
                        type="button"
                        class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg border border-gray-200 text-xl leading-none text-gray-500 hover:bg-gray-50"
                        :aria-label="tr('Salir del tutorial', 'Exit tutorial')"
                        @click="closeAccountingTutorial"
                    >
                        ×
                    </button>
                </div>
                <p class="mt-3 text-sm leading-6 text-gray-600">{{ tutorialStep.body }}</p>
                <p v-if="!tutorialCutout" class="mt-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs font-medium text-amber-800">
                    {{ tr('Esta parte no esta visible ahora; aparecera cuando existan datos relacionados.', 'This part is not visible right now; it will appear when related data exists.') }}
                </p>
                <div class="mt-4 flex flex-col-reverse gap-2 sm:flex-row sm:items-center sm:justify-between">
                    <button
                        type="button"
                        class="inline-flex min-h-10 items-center justify-center rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-50"
                        :disabled="tutorialStepIndex === 0"
                        @click="previousTutorialStep"
                    >
                        {{ tr('Anterior', 'Previous') }}
                    </button>
                    <div class="flex gap-2">
                        <button
                            type="button"
                            class="inline-flex min-h-10 items-center justify-center rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50"
                            @click="closeAccountingTutorial"
                        >
                            {{ tr('Salir', 'Exit') }}
                        </button>
                        <button
                            type="button"
                            class="inline-flex min-h-10 items-center justify-center rounded-lg bg-red-700 px-3 py-2 text-sm font-semibold text-white hover:bg-red-800"
                            @click="nextTutorialStep"
                        >
                            {{ tutorialStepIndex >= tutorialStepCount - 1 ? tr('Terminar', 'Finish') : tr('Siguiente', 'Next') }}
                        </button>
                    </div>
                </div>
            </aside>
        </div>

        <div class="fixed inset-x-0 bottom-0 z-40 border-t border-gray-200 bg-white/95 px-3 py-2 shadow-lg backdrop-blur lg:hidden">
            <div class="grid grid-cols-4 gap-2 text-xs">
                <div>
                    <p class="font-semibold uppercase tracking-wide text-gray-500">{{ tr('Efectivo', 'Cash') }}</p>
                    <p class="font-semibold text-gray-950">{{ formatMoney(summaryTotals.cash_balance) }}</p>
                </div>
                <div>
                    <p class="font-semibold uppercase tracking-wide text-gray-500">{{ tr('Banco', 'Bank') }}</p>
                    <p class="font-semibold text-gray-950">{{ formatMoney(summaryTotals.bank_balance) }}</p>
                </div>
                <div>
                    <p class="font-semibold uppercase tracking-wide text-gray-500">{{ tr('Total', 'Total') }}</p>
                    <p class="font-semibold text-gray-950">{{ formatMoney(summaryTotals.total_balance) }}</p>
                </div>
                <div>
                    <p class="font-semibold uppercase tracking-wide text-amber-700">{{ tr('Reemb.', 'Reimb.') }}</p>
                    <p class="font-semibold text-amber-800">{{ formatMoney(reimbursementBalanceSummary.total_available) }}</p>
                </div>
            </div>
        </div>

        <div
            v-if="selectedNoteMovement"
            class="fixed inset-0 z-[60] flex items-center justify-center bg-black/40 p-4"
            role="dialog"
            aria-modal="true"
            :aria-label="tr('Nota del movimiento', 'Movement note')"
            @click.self="closeMovementNoteModal"
        >
            <div class="flex max-h-[88vh] w-full max-w-2xl flex-col overflow-hidden rounded-2xl border bg-white shadow-xl">
                <div class="flex items-start justify-between gap-4 border-b px-5 py-4">
                    <div class="min-w-0">
                        <h2 class="text-lg font-semibold text-gray-900">{{ tr('Nota del movimiento', 'Movement note') }}</h2>
                        <p class="mt-1 break-words text-sm font-medium text-gray-700">{{ movementDisplayConcept(selectedNoteMovement) }}</p>
                        <p class="mt-1 text-xs text-gray-500">
                            {{ selectedNoteMovement.movement_id }} · {{ formatDate(selectedNoteMovement.date) }}
                        </p>
                    </div>
                    <button
                        type="button"
                        class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-lg text-2xl leading-none text-gray-500 hover:bg-gray-100 hover:text-gray-700"
                        :aria-label="tr('Cerrar', 'Close')"
                        @click="closeMovementNoteModal"
                    >
                        ×
                    </button>
                </div>
                <div class="overflow-y-auto p-5">
                    <p class="whitespace-pre-wrap break-words text-sm leading-6 text-gray-700">{{ movementNote(selectedNoteMovement) }}</p>
                </div>
                <div class="flex justify-end border-t px-5 py-4">
                    <button
                        type="button"
                        class="rounded-xl border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50"
                        @click="closeMovementNoteModal"
                    >
                        {{ tr('Cerrar', 'Close') }}
                    </button>
                </div>
            </div>
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
