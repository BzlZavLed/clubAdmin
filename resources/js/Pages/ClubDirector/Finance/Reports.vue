<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import PathfinderLayout from '@/Layouts/PathfinderLayout.vue'
import {
    ArrowDownTrayIcon,
    ArrowPathIcon,
    BanknotesIcon,
    BuildingLibraryIcon,
    ChevronDownIcon,
    ChevronRightIcon,
    DocumentChartBarIcon,
    FunnelIcon,
} from '@heroicons/vue/24/outline'
import {
    fetchFinanceEngineAccounting,
    fetchFinanceEngineMovements,
} from '@/Services/api'
import { useGeneral } from '@/Composables/useGeneral'
import { useLocale } from '@/Composables/useLocale'

const props = defineProps({
    auth_user: { type: Object, required: true },
})

const { showToast } = useGeneral()
const { tr } = useLocale()

const LEDGER_PAGE_SIZE = 30

const loading = ref(false)
const ledgerLoading = ref(false)
const loadError = ref('')
const selectedClubId = ref(null)
const clubs = ref([])
const treasury = ref({
    club: null,
    accounts: [],
    summary: {},
})
const engineReport = ref(null)
const ledgerPage = ref(1)
const openLedgerAccountSections = ref({})
const ledgerDateMode = ref('dates')
const ledgerFilters = ref({
    account: 'all',
    date_from: '',
    date_to: '',
})
const ledgerMonthRange = ref({
    from: '',
    to: '',
})
const includeLedgerAnnexes = ref(false)
const includeIncomeReceiptAnnexes = ref(false)

const ledgerIsAllAccounts = computed(() => ledgerFilters.value.account === 'all')
const canSelectClub = computed(() => props.auth_user?.profile_type === 'superadmin' || clubs.value.length > 1)
const currentClub = computed(() => treasury.value.club || clubs.value.find((club) => Number(club.id) === Number(selectedClubId.value)) || null)
const summary = computed(() => treasury.value.summary || {})
const summaryTotals = computed(() => ({
    cash_balance: Number(summary.value.cash_balance || 0),
    bank_balance: Number(summary.value.bank_balance || 0),
    total_available: Number(summary.value.total_available ?? (Number(summary.value.cash_balance || 0) + Number(summary.value.bank_balance || 0))),
}))

const accountOptions = computed(() => {
    const rows = new Map()

    ;(treasury.value.accounts || []).forEach((account) => {
        rows.set(account.value, {
            value: account.value,
            label: account.label || account.value,
        })
    })

    ;(summary.value.accounts || []).forEach((account) => {
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

    return Array.from(rows.values()).sort((a, b) => String(a.label).localeCompare(String(b.label)))
})

const accountLabels = computed(() => Object.fromEntries(accountOptions.value.map((account) => [account.value, account.label])))
const accountBalanceRows = computed(() => (summary.value.accounts || []).map((row) => ({
    ...row,
    account: row.account,
    label: row.label || accountLabels.value[row.account] || row.account,
    cash_balance: Number(row.cash_balance || 0),
    bank_balance: Number(row.bank_balance || 0),
    total_available: Number(row.total_available ?? (Number(row.cash_balance || 0) + Number(row.bank_balance || 0))),
})).sort((a, b) => String(a.label).localeCompare(String(b.label))))

const ledgerMovements = computed(() => engineReport.value?.movements || [])
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

const ledgerIncomeAmount = (movement) => {
    if (movement?.domain !== 'income') return null

    return Number(movement.signed_amount ?? movement.amount ?? 0)
}
const ledgerExpenseAmount = (movement) => {
    if (movement?.domain !== 'expense') return null

    return -Number(movement.signed_amount ?? (-1 * Number(movement.amount || 0)))
}
const ledgerTransferAmount = (movement) => movement?.domain === 'transfer' ? Number(movement.amount || 0) : null

const ledgerTotals = computed(() => ledgerMovements.value.reduce((totals, movement) => {
    const income = ledgerIncomeAmount(movement)
    const expense = ledgerExpenseAmount(movement)
    const transfer = ledgerTransferAmount(movement)
    const isCorrection = ['cancelled', 'cancellation'].includes(movement.status) || movement.canceling_id || movement.is_cancelled

    return {
        income: totals.income + (income ?? 0),
        expenses: totals.expenses + (expense ?? 0),
        transfers: totals.transfers + (transfer ?? 0),
        corrections: totals.corrections + (isCorrection ? 1 : 0),
    }
}, { income: 0, expenses: 0, transfers: 0, corrections: 0 }))
const ledgerNet = computed(() => ledgerTotals.value.income - ledgerTotals.value.expenses)
const currentYear = new Date().getFullYear()
const monthOptions = computed(() => [
    { value: 1, label: tr('Enero', 'January') },
    { value: 2, label: tr('Febrero', 'February') },
    { value: 3, label: tr('Marzo', 'March') },
    { value: 4, label: tr('Abril', 'April') },
    { value: 5, label: tr('Mayo', 'May') },
    { value: 6, label: tr('Junio', 'June') },
    { value: 7, label: tr('Julio', 'July') },
    { value: 8, label: tr('Agosto', 'August') },
    { value: 9, label: tr('Septiembre', 'September') },
    { value: 10, label: tr('Octubre', 'October') },
    { value: 11, label: tr('Noviembre', 'November') },
    { value: 12, label: tr('Diciembre', 'December') },
])

const ledgerPdfUrl = computed(() => {
    const params = { limit: 5000 }
    if (selectedClubId.value) params.club_id = selectedClubId.value
    if (ledgerFilters.value.account !== 'all') params.account = ledgerFilters.value.account
    if (ledgerFilters.value.date_from) params.date_from = ledgerFilters.value.date_from
    if (ledgerFilters.value.date_to) params.date_to = ledgerFilters.value.date_to
    if (includeLedgerAnnexes.value) params.include_annexes = 1
    if (includeLedgerAnnexes.value && includeIncomeReceiptAnnexes.value) params.include_income_receipt_annexes = 1

    return route('club.finance-engine.movements.pdf', params)
})

watch(includeLedgerAnnexes, (includeAnnexes) => {
    if (!includeAnnexes) {
        includeIncomeReceiptAnnexes.value = false
    }
})

const ledgerHasDateLimit = computed(() => Boolean(ledgerFilters.value.date_from || ledgerFilters.value.date_to))

const downloadingLedgerPdf = ref(false)
const exportConfirmationModal = ref({
    show: false,
    title: '',
    message: '',
    confirmLabel: '',
    cancelLabel: '',
    confirmClass: '',
    files: [],
})
const generatedLedgerFilesModal = ref({
    show: false,
    files: [],
})
let resolveExportConfirmation = null

const closeExportConfirmationModal = (confirmed = false) => {
    exportConfirmationModal.value.show = false

    if (resolveExportConfirmation) {
        resolveExportConfirmation(confirmed)
        resolveExportConfirmation = null
    }
}

const confirmExportAction = ({ title, message, confirmLabel, cancelLabel, confirmClass = 'bg-red-600 text-white hover:bg-red-700', files = [] }) => new Promise((resolve) => {
    resolveExportConfirmation = resolve
    exportConfirmationModal.value = {
        show: true,
        title,
        message,
        confirmLabel,
        cancelLabel,
        confirmClass,
        files,
    }
})

const closeGeneratedLedgerFilesModal = () => {
    generatedLedgerFilesModal.value = {
        show: false,
        files: [],
    }
}

const formatFileSize = (size) => {
    const bytes = Number(size || 0)
    if (!bytes) return ''
    if (bytes < 1024 * 1024) return `${Math.max(1, Math.round(bytes / 1024))} KB`

    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`
}

const downloadLedgerPdf = async () => {
    if (!ledgerHasDateLimit.value) {
        const shouldContinue = await confirmExportAction({
            title: tr('Exportar sin rango', 'Export without range'),
            message: tr(
                'No seleccionaste un rango de fechas o meses. Exportar sin limite puede generar un archivo muy grande y tardar bastante en descargar.',
                'No date or month range is selected. Exporting without a limit can generate a very large file and take a while to download.'
            ),
            confirmLabel: tr('Continuar exportacion', 'Continue export'),
            cancelLabel: tr('Cancelar', 'Cancel'),
        })

        if (!shouldContinue) return
    }

    if (includeLedgerAnnexes.value) {
        const shouldContinue = await confirmExportAction({
            title: tr('Recibos en PDF separado', 'Receipts in separate PDF'),
            message: tr(
                'Los recibos se generaran en un PDF separado del libro contable. Esto hace la descarga mas rapida y confiable.',
                'Receipts will be generated in a separate PDF from the ledger. This makes the download faster and more reliable.'
            ),
            confirmLabel: tr('Generar archivos', 'Generate files'),
            cancelLabel: tr('Cancelar', 'Cancel'),
            confirmClass: 'bg-emerald-600 text-white hover:bg-emerald-700',
            files: [
                {
                    label: tr('Libro contable', 'Ledger'),
                    name: 'finance-ledger.pdf',
                },
                {
                    label: tr('Recibos y comprobantes', 'Receipts and proofs'),
                    name: 'finance-ledger-receipts.pdf',
                },
            ],
        })

        if (!shouldContinue) return
    }

    downloadingLedgerPdf.value = true

    try {
        const response = await fetch(ledgerPdfUrl.value, {
            method: 'GET',
            headers: {
                Accept: 'application/json',
            },
            credentials: 'same-origin',
        })

        if (!response.ok) {
            throw new Error('Could not generate ledger PDF.')
        }

        const data = await response.json()

        if (!data.url) {
            throw new Error('No PDF URL returned.')
        }

        generatedLedgerFilesModal.value = {
            show: true,
            files: Array.isArray(data.files) && data.files.length
                ? data.files
                : [{ label: tr('Libro contable', 'Ledger'), file_name: data.file_name || 'finance-ledger.pdf', url: data.url, size: data.size }],
        }
       
    } catch (error) {
        console.error(error)
        showToast(tr('No se pudo descargar el PDF del libro contable. Intenta de nuevo.', 'Could not download the ledger PDF. Please try again.'), 'error')
    } finally {
        downloadingLedgerPdf.value = false
    }
}






const formatMoney = (value) => {
    const amount = Number(value || 0)
    const sign = amount < 0 ? '-' : ''

    return `${sign}$${Math.abs(amount).toLocaleString('en-US', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    })}`
}
const formatDate = (value) => value ? String(value).slice(0, 10) : '—'
const isoDate = (year, month, day) => `${year}-${String(month).padStart(2, '0')}-${String(day).padStart(2, '0')}`
const lastDayOfMonth = (year, month) => new Date(year, month, 0).getDate()
const accountLabel = (value) => accountLabels.value[value] || value || '—'
const locationLabel = (value) => ({
    bank: tr('Banco', 'Bank'),
    cash: tr('Efectivo', 'Cash'),
    staff_custody: tr('Custodia staff', 'Staff custody'),
    internal: tr('Interno', 'Internal'),
    pending: tr('Pendiente', 'Pending'),
})[value] || value || '—'
const paymentTypeLabel = (value) => ({
    cash: tr('Efectivo', 'Cash'),
    zelle: 'Zelle',
    check: tr('Cheque', 'Check'),
    transfer: tr('Transferencia', 'Transfer'),
    internal: tr('Interno', 'Internal'),
    initial: tr('Inicial', 'Initial'),
})[value] || value || '—'
const typeLabel = (movement) => {
    if (movement?.kind === 'income_reversal' || (movement?.domain === 'income' && Number(movement.signed_amount || 0) < 0)) {
        return tr('Correccion ingreso', 'Income correction')
    }
    if (movement?.kind === 'expense_reversal' || (movement?.domain === 'expense' && Number(movement.signed_amount || 0) > 0)) {
        return tr('Correccion gasto', 'Expense correction')
    }

    return ({
        income: tr('Ingreso', 'Income'),
        expense: tr('Gasto', 'Expense'),
        transfer: tr('Movimiento', 'Movement'),
    })[movement?.domain] || movement?.kind || '—'
}
const typeBadgeClass = (movement) => {
    if (movement?.status === 'cancelled') return 'border-amber-200 bg-amber-50 text-amber-800'
    if (movement?.status === 'cancellation' || movement?.canceling_id) return 'border-purple-200 bg-purple-50 text-purple-800'

    return ({
        income: 'border-emerald-200 bg-emerald-50 text-emerald-800',
        expense: 'border-rose-200 bg-rose-50 text-rose-800',
        transfer: 'border-sky-200 bg-sky-50 text-sky-800',
    })[movement?.domain] || 'border-gray-200 bg-gray-50 text-gray-700'
}
const statusLabel = (movement) => ({
    posted: tr('Registrado', 'Posted'),
    completed: tr('Completado', 'Completed'),
    pending_reimbursement: tr('Reembolso pendiente', 'Pending reimbursement'),
    cancelled: tr('Corregido', 'Corrected'),
    cancellation: tr('Correccion', 'Correction'),
})[movement?.status] || movement?.status || '—'

const movementAccountText = (movement) => {
    if (movement?.domain === 'transfer') {
        return [
            movement.from_account_label || accountLabel(movement.from_account),
            `(${locationLabel(movement.from_location)})`,
            '->',
            movement.to_account_label || accountLabel(movement.to_account),
            `(${locationLabel(movement.to_location)})`,
        ].join(' ')
    }

    return [
        movement?.account_label || accountLabel(movement?.account),
        locationLabel(movement?.location),
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
const ledgerAccountGroups = computed(() => {
    const groups = new Map()

    ledgerMovements.value.forEach((movement) => {
        movementAccountKeys(movement).forEach((account) => {
            if (!groups.has(account)) {
                groups.set(account, {
                    key: account,
                    account,
                    label: accountLabels.value[account] || account,
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
            group.totals.income += ledgerIncomeAmount(movement) ?? 0
            group.totals.expenses += ledgerExpenseAmount(movement) ?? 0
            group.totals.transfers += ledgerTransferAmount(movement) ?? 0
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
const receiptLinks = (movement) => {
    const links = []

    if (movement?.receipt?.url) {
        links.push({
            url: movement.receipt.url,
            label: movement.receipt.number || tr('Recibo', 'Receipt'),
        })
    }

    if (movement?.proof?.url) {
        links.push({
            url: movement.proof.url,
            label: movement.proof.name || (movement.domain === 'expense' ? tr('Comprobante gasto', 'Expense receipt') : tr('Comprobante', 'Proof')),
        })
    }

    return links
}
const receiptFallback = (movement) => movement?.receipt?.number || null
const correctionText = (movement) => {
    const cancellation = movement?.cancellation || {}
    if (movement?.status === 'cancelled' && (movement.related_canceled_movement_key || cancellation.related_canceled_movement_key)) {
        return tr('Tiene correccion registrada', 'Has a correction recorded')
    }
    if (movement?.status === 'cancellation' || movement?.canceling_id || cancellation.canceling_id) {
        return tr('Movimiento de correccion', 'Correction movement')
    }

    return null
}

const setLedgerPage = (page) => {
    ledgerPage.value = Math.min(Math.max(Number(page) || 1, 1), ledgerPageCount.value)
}
const normalizeClubs = (payload) => {
    if (Array.isArray(payload?.clubs) && payload.clubs.length) {
        clubs.value = payload.clubs
    }
}

const loadBalances = async () => {
    const payload = await fetchFinanceEngineAccounting(selectedClubId.value)
    const data = payload?.data || {}
    const treasuryData = data.treasury || {}

    treasury.value = {
        club: treasuryData.club,
        accounts: treasuryData.accounts || [],
        summary: treasuryData.summary || {},
    }
    selectedClubId.value = treasuryData.club?.id ?? selectedClubId.value
    normalizeClubs(data)
}

const loadLedger = async () => {
    ledgerLoading.value = true

    try {
        ledgerPage.value = 1
        const params = {
            club_id: selectedClubId.value || undefined,
            limit: 5000,
        }
        if (ledgerFilters.value.account !== 'all') params.account = ledgerFilters.value.account
        if (ledgerFilters.value.date_from) params.date_from = ledgerFilters.value.date_from
        if (ledgerFilters.value.date_to) params.date_to = ledgerFilters.value.date_to

        const payload = await fetchFinanceEngineMovements(params)
        engineReport.value = payload?.data || null
        openLedgerAccountSections.value = {}
    } catch (error) {
        console.error(error)
        showToast(error?.response?.data?.message || tr('No se pudo cargar el libro contable.', 'Could not load the ledger.'), 'error')
    } finally {
        ledgerLoading.value = false
    }
}

const loadData = async () => {
    loading.value = true
    loadError.value = ''

    try {
        await loadBalances()
        await loadLedger()
    } catch (error) {
        console.error(error)
        loadError.value = error?.response?.data?.message || tr('No se pudieron cargar los reportes.', 'Could not load the reports.')
    } finally {
        loading.value = false
    }
}

const applyLedgerFilters = () => loadLedger()
const applyLedgerDateFilters = async () => {
    ledgerDateMode.value = 'dates'
    ledgerMonthRange.value = {
        from: '',
        to: '',
    }
    await loadLedger()
}
const applyLedgerMonthRange = async () => {
    ledgerDateMode.value = 'months'
    const selectedFrom = Number(ledgerMonthRange.value.from || ledgerMonthRange.value.to || 0)
    const selectedTo = Number(ledgerMonthRange.value.to || ledgerMonthRange.value.from || 0)

    if (!selectedFrom || !selectedTo) {
        showToast(tr('Selecciona al menos un mes.', 'Select at least one month.'), 'error')
        return
    }

    const fromMonth = Math.min(selectedFrom, selectedTo)
    const toMonth = Math.max(selectedFrom, selectedTo)

    ledgerFilters.value.date_from = isoDate(currentYear, fromMonth, 1)
    ledgerFilters.value.date_to = isoDate(currentYear, toMonth, lastDayOfMonth(currentYear, toMonth))

    await loadLedger()
}
const clearLedgerFilters = async () => {
    ledgerDateMode.value = 'dates'
    ledgerFilters.value = {
        account: 'all',
        date_from: '',
        date_to: '',
    }
    ledgerMonthRange.value = {
        from: '',
        to: '',
    }
    await loadLedger()
}
const onClubChange = () => loadData()

watch(ledgerMovements, () => {
    if (ledgerPage.value > ledgerPageCount.value) {
        setLedgerPage(ledgerPageCount.value)
    }
})

onMounted(loadData)
</script>

<template>
    <PathfinderLayout>
        <template #title>{{ tr('Reportes financieros', 'Financial reports') }}</template>

        <div class="space-y-6">
            <section class="overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm">
                <div class="border-b border-gray-100 bg-gray-50 px-4 py-4 sm:px-5">
                    <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                        <div class="min-w-0">
                            <div class="flex items-center gap-3">
                                <span class="flex h-10 w-10 items-center justify-center rounded-lg border border-red-100 bg-white text-red-700">
                                    <DocumentChartBarIcon class="h-5 w-5" />
                                </span>
                                <div class="min-w-0">
                                    <h1 class="text-xl font-semibold text-gray-900">{{ tr('Reportes financieros', 'Financial reports') }}</h1>
                                    <p class="truncate text-sm text-gray-500">{{ currentClub?.club_name || tr('Club actual', 'Current club') }}</p>
                                </div>
                            </div>
                        </div>

                        <div class="flex flex-col gap-2 sm:flex-row sm:items-end">
                            <div v-if="canSelectClub && clubs.length" class="w-full sm:w-72">
                                <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-gray-500">{{ tr('Club', 'Club') }}</label>
                                <select
                                    v-model="selectedClubId"
                                    class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm focus:border-red-400 focus:outline-none focus:ring-2 focus:ring-red-100"
                                    @change="onClubChange"
                                >
                                    <option v-for="club in clubs" :key="club.id" :value="club.id">{{ club.club_name }}</option>
                                </select>
                            </div>
                            <button
                                type="button"
                                class="inline-flex min-h-10 items-center justify-center gap-2 rounded-lg border border-gray-200 bg-white px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 disabled:opacity-60"
                                :disabled="loading || ledgerLoading"
                                @click="loadData"
                            >
                                <ArrowPathIcon class="h-4 w-4" :class="{ 'animate-spin': loading || ledgerLoading }" />
                                <span>{{ loading || ledgerLoading ? tr('Cargando...', 'Loading...') : tr('Recargar', 'Reload') }}</span>
                            </button>
                        </div>
                    </div>
                </div>
            </section>

            <div v-if="loadError" class="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-700">
                {{ loadError }}
            </div>

            <section class="grid gap-4 md:grid-cols-3">
                <article class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
                    <div class="flex items-center gap-2 text-sm font-semibold text-gray-700">
                        <BanknotesIcon class="h-5 w-5 text-emerald-600" />
                        {{ tr('Efectivo total', 'Total cash') }}
                    </div>
                    <p class="mt-3 text-2xl font-semibold text-gray-950">{{ formatMoney(summaryTotals.cash_balance) }}</p>
                </article>
                <article class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
                    <div class="flex items-center gap-2 text-sm font-semibold text-gray-700">
                        <BuildingLibraryIcon class="h-5 w-5 text-blue-600" />
                        {{ tr('Banco total', 'Total bank') }}
                    </div>
                    <p class="mt-3 text-2xl font-semibold text-gray-950">{{ formatMoney(summaryTotals.bank_balance) }}</p>
                </article>
                <article class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
                    <div class="flex items-center gap-2 text-sm font-semibold text-gray-700">
                        <DocumentChartBarIcon class="h-5 w-5 text-gray-600" />
                        {{ tr('Balance general', 'General balance') }}
                    </div>
                    <p class="mt-3 text-2xl font-semibold text-gray-950">{{ formatMoney(summaryTotals.total_available) }}</p>
                </article>
            </section>

            <section class="rounded-lg border border-gray-200 bg-white shadow-sm">
                <div class="border-b border-gray-100 p-4">
                    <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                        <div>
                            <h2 class="text-lg font-semibold text-gray-950">{{ tr('Libro contable general', 'General accounting ledger') }}</h2>
                            <p class="mt-1 text-sm text-gray-500">
                                {{ tr('Filtra por cuenta o rango de fechas. Las correcciones y movimientos cancelados se muestran junto al movimiento original.', 'Filter by account or date range. Corrections and cancelled movements are shown with the original movement.') }}
                            </p>
                        </div>
                        <!-- <a
                            :href="ledgerPdfUrl"
                            class="inline-flex items-center justify-center gap-2 rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50"
                        >
                            <ArrowDownTrayIcon class="h-4 w-4" />
                            {{ tr('PDF libro', 'Ledger PDF') }}
                        </a> -->

                        <div class="flex flex-col gap-2 sm:items-end">
                            <label class="inline-flex items-start gap-2 text-sm font-medium text-gray-700">
                                <input
                                    v-model="includeLedgerAnnexes"
                                    type="checkbox"
                                    class="mt-0.5 rounded border-gray-300 text-red-600 shadow-sm focus:ring-red-500"
                                >
                                <span class="leading-5">
                                    {{ tr('Incluir anexos de recibos', 'Include receipt appendices') }}
                                    <span class="block text-xs font-normal text-gray-500">
                                        {{ tr('Se generan como PDF separado para evitar archivos pesados.', 'Generated as a separate PDF to avoid oversized files.') }}
                                    </span>
                                </span>
                            </label>

                            <label
                                v-if="includeLedgerAnnexes"
                                class="inline-flex items-start gap-2 text-sm font-medium text-gray-700"
                            >
                                <input
                                    v-model="includeIncomeReceiptAnnexes"
                                    type="checkbox"
                                    class="mt-0.5 rounded border-gray-300 text-red-600 shadow-sm focus:ring-red-500"
                                >
                                <span class="leading-5">
                                    {{ tr('Incluir recibos de ingresos', 'Include income receipts') }}
                                    <span class="block text-xs font-normal text-gray-500">
                                        {{ tr('Los comprobantes de gastos y pagos de reembolso se incluyen por defecto.', 'Expense receipts and reimbursement payment proofs are included by default.') }}
                                    </span>
                                </span>
                            </label>

                            <button
                                type="button"
                                @click="downloadLedgerPdf"
                                :disabled="downloadingLedgerPdf"
                                class="inline-flex items-center justify-center gap-2 rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 disabled:opacity-50"
                            >
                                <ArrowDownTrayIcon class="h-4 w-4" />

                                <span v-if="downloadingLedgerPdf">
                                    {{ tr('Generando...', 'Generating...') }}
                                </span>

                                <span v-else>
                                    {{ tr('PDF libro', 'Ledger PDF') }}
                                </span>
                            </button>
                        </div>

                    </div>

                    <div class="mt-4 grid gap-3 lg:grid-cols-[minmax(180px,1fr)_minmax(150px,0.75fr)_minmax(150px,0.75fr)_auto_auto] lg:items-end">
                        <div>
                            <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-gray-500">{{ tr('Cuenta', 'Account') }}</label>
                            <select v-model="ledgerFilters.account" class="w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-red-500 focus:ring-red-500">
                                <option value="all">{{ tr('Todas las cuentas', 'All accounts') }}</option>
                                <option v-for="account in accountOptions" :key="account.value" :value="account.value">
                                    {{ account.label }}
                                </option>
                            </select>
                        </div>
                        <div class="lg:col-span-4">
                            <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-gray-500">{{ tr('Rango', 'Range') }}</label>
                            <div class="inline-flex rounded-lg border border-gray-200 bg-gray-50 p-1">
                                <button
                                    type="button"
                                    class="rounded-md px-3 py-1.5 text-sm font-semibold"
                                    :class="ledgerDateMode === 'dates' ? 'bg-white text-gray-950 shadow-sm' : 'text-gray-600 hover:text-gray-900'"
                                    @click="ledgerDateMode = 'dates'"
                                >
                                    {{ tr('Fechas', 'Dates') }}
                                </button>
                                <button
                                    type="button"
                                    class="rounded-md px-3 py-1.5 text-sm font-semibold"
                                    :class="ledgerDateMode === 'months' ? 'bg-white text-gray-950 shadow-sm' : 'text-gray-600 hover:text-gray-900'"
                                    @click="ledgerDateMode = 'months'"
                                >
                                    {{ tr('Meses', 'Months') }}
                                </button>
                            </div>
                        </div>
                    </div>

                    <div v-if="ledgerDateMode === 'dates'" class="mt-3 grid gap-3 lg:grid-cols-[minmax(150px,0.75fr)_minmax(150px,0.75fr)_auto_auto_minmax(0,1fr)] lg:items-end">
                        <div>
                            <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-gray-500">{{ tr('Desde', 'From') }}</label>
                            <input v-model="ledgerFilters.date_from" type="date" class="w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-red-500 focus:ring-red-500">
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-gray-500">{{ tr('Hasta', 'To') }}</label>
                            <input v-model="ledgerFilters.date_to" type="date" class="w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-red-500 focus:ring-red-500">
                        </div>
                        <button
                            type="button"
                            class="inline-flex min-h-10 items-center justify-center gap-2 rounded-lg bg-red-600 px-4 py-2 text-sm font-semibold text-white hover:bg-red-700 disabled:opacity-60"
                            :disabled="ledgerLoading"
                            @click="applyLedgerDateFilters"
                        >
                            <FunnelIcon class="h-4 w-4" />
                            {{ tr('Aplicar', 'Apply') }}
                        </button>
                        <button
                            type="button"
                            class="inline-flex min-h-10 items-center justify-center rounded-lg border border-gray-200 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 disabled:opacity-60"
                            :disabled="ledgerLoading"
                            @click="clearLedgerFilters"
                        >
                            {{ tr('Limpiar', 'Clear') }}
                        </button>
                    </div>
                    <div v-else class="mt-3 grid gap-3 lg:grid-cols-[minmax(150px,0.75fr)_minmax(150px,0.75fr)_auto_auto_minmax(0,1fr)] lg:items-end">
                        <div>
                            <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-gray-500">{{ tr('Mes inicial', 'Start month') }}</label>
                            <select v-model="ledgerMonthRange.from" class="w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-red-500 focus:ring-red-500">
                                <option value="">{{ tr('Seleccionar mes', 'Select month') }}</option>
                                <option v-for="month in monthOptions" :key="`from-${month.value}`" :value="month.value">
                                    {{ month.label }}
                                </option>
                            </select>
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-gray-500">{{ tr('Mes final', 'End month') }}</label>
                            <select v-model="ledgerMonthRange.to" class="w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-red-500 focus:ring-red-500">
                                <option value="">{{ tr('Seleccionar mes', 'Select month') }}</option>
                                <option v-for="month in monthOptions" :key="`to-${month.value}`" :value="month.value">
                                    {{ month.label }}
                                </option>
                            </select>
                        </div>
                        <button
                            type="button"
                            class="inline-flex min-h-10 items-center justify-center gap-2 rounded-lg border border-red-200 bg-red-50 px-4 py-2 text-sm font-semibold text-red-700 hover:bg-red-100 disabled:opacity-60"
                            :disabled="ledgerLoading"
                            @click="applyLedgerMonthRange"
                        >
                            <FunnelIcon class="h-4 w-4" />
                            {{ tr('Aplicar meses', 'Apply months') }}
                        </button>
                        <button
                            type="button"
                            class="inline-flex min-h-10 items-center justify-center rounded-lg border border-gray-200 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 disabled:opacity-60"
                            :disabled="ledgerLoading"
                            @click="clearLedgerFilters"
                        >
                            {{ tr('Limpiar', 'Clear') }}
                        </button>
                        <p class="text-xs text-gray-500">
                            {{ tr('Usa el ano actual', 'Uses current year') }} {{ currentYear }}. {{ tr('El mes final usa su ultimo dia real.', 'The end month uses its real last day.') }}
                        </p>
                    </div>
                </div>

                <div class="grid gap-2 border-b border-gray-100 p-3 text-sm sm:grid-cols-2 lg:grid-cols-4">
                    <div class="rounded-lg bg-emerald-50 px-3 py-2">
                        <p class="text-xs font-semibold uppercase tracking-wide text-emerald-700">{{ tr('Ingresos', 'Income') }}</p>
                        <p class="mt-0.5 text-base font-semibold text-emerald-950">{{ formatMoney(ledgerTotals.income) }}</p>
                    </div>
                    <div class="rounded-lg bg-rose-50 px-3 py-2">
                        <p class="text-xs font-semibold uppercase tracking-wide text-rose-700">{{ tr('Gastos', 'Expenses') }}</p>
                        <p class="mt-0.5 text-base font-semibold text-rose-950">{{ formatMoney(ledgerTotals.expenses) }}</p>
                    </div>
                    <div class="rounded-lg bg-gray-50 px-3 py-2">
                        <p class="text-xs font-semibold uppercase tracking-wide text-gray-600">{{ tr('Neto mostrado', 'Shown net') }}</p>
                        <p class="mt-0.5 text-base font-semibold" :class="ledgerNet < 0 ? 'text-rose-700' : 'text-emerald-700'">{{ formatMoney(ledgerNet) }}</p>
                    </div>
                    <div class="rounded-lg bg-purple-50 px-3 py-2">
                        <p class="text-xs font-semibold uppercase tracking-wide text-purple-700">{{ tr('Correcciones', 'Corrections') }}</p>
                        <p class="mt-0.5 text-base font-semibold text-purple-950">{{ ledgerTotals.corrections }}</p>
                    </div>
                </div>

                <div class="divide-y divide-gray-100">
                    <div v-if="ledgerIsAllAccounts && ledgerAccountGroups.length" class="flex flex-col gap-2 border-b border-gray-100 bg-white px-3 py-2 text-xs text-gray-600 sm:flex-row sm:items-center sm:justify-between">
                        <span>
                            {{ tr('Las cuentas estan colapsadas para facilitar la revision.', 'Accounts are collapsed to make review easier.') }}
                        </span>
                        <div class="flex gap-2">
                            <button
                                type="button"
                                class="rounded-lg border border-gray-200 px-3 py-1.5 font-semibold text-gray-700 hover:bg-gray-50"
                                @click="expandAllLedgerSections"
                            >
                                {{ tr('Abrir todas', 'Open all') }}
                            </button>
                            <button
                                type="button"
                                class="rounded-lg border border-gray-200 px-3 py-1.5 font-semibold text-gray-700 hover:bg-gray-50"
                                @click="collapseAllLedgerSections"
                            >
                                {{ tr('Cerrar todas', 'Close all') }}
                            </button>
                        </div>
                    </div>

                    <div class="hidden border-b border-gray-100 bg-gray-50 px-3 py-2 text-[11px] font-semibold uppercase tracking-wide text-gray-500 lg:grid lg:grid-cols-[5.25rem_minmax(0,1.15fr)_minmax(0,0.9fr)_minmax(0,0.65fr)_minmax(4.6rem,0.42fr)_minmax(4.6rem,0.42fr)_minmax(4.6rem,0.42fr)_minmax(5.6rem,0.5fr)] lg:gap-2">
                        <span>{{ tr('Fecha', 'Date') }}</span>
                        <span>{{ tr('Concepto', 'Concept') }}</span>
                        <span>{{ tr('Cuenta / ubicacion', 'Account / location') }}</span>
                        <span>{{ tr('Quien pago', 'Payer') }}</span>
                        <span class="text-right">{{ tr('Ingresos', 'Income') }}</span>
                        <span class="text-right">{{ tr('Gastos', 'Expenses') }}</span>
                        <span class="text-right">{{ tr('Mov.', 'Mov.') }}</span>
                        <span class="text-right">{{ tr('Balance', 'Balance') }}</span>
                    </div>

                    <template v-for="section in ledgerDisplaySections" :key="section.key">
                        <button
                            v-if="section.label"
                            type="button"
                            class="flex w-full items-center justify-between gap-3 bg-gray-100 px-3 py-2 text-left text-xs font-semibold uppercase tracking-wide text-gray-700 hover:bg-gray-200"
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
                            <span class="hidden shrink-0 gap-3 text-[11px] normal-case tracking-normal text-gray-600 sm:flex">
                                <span class="text-emerald-700">{{ formatMoney(section.totals.income) }}</span>
                                <span class="text-rose-700">-{{ formatMoney(section.totals.expenses) }}</span>
                                <span class="text-sky-700">{{ formatMoney(section.totals.transfers) }}</span>
                            </span>
                        </button>

                        <template v-if="isLedgerSectionOpen(section)">
                            <article
                                v-for="movement in section.rows"
                                :key="`${section.key}-${movement.movement_id}`"
                                class="grid min-w-0 gap-2 px-3 py-2.5 text-xs sm:grid-cols-[5.25rem_minmax(0,1fr)] lg:grid-cols-[5.25rem_minmax(0,1.15fr)_minmax(0,0.9fr)_minmax(0,0.65fr)_minmax(4.6rem,0.42fr)_minmax(4.6rem,0.42fr)_minmax(4.6rem,0.42fr)_minmax(5.6rem,0.5fr)] lg:items-start"
                            >
                            <div class="min-w-0">
                                <p class="font-semibold text-gray-900">{{ formatDate(movement.date) }}</p>
                                <div class="mt-1 flex flex-wrap gap-1">
                                    <span class="inline-flex rounded-full border px-1.5 py-0.5 text-[11px] font-semibold leading-4" :class="typeBadgeClass(movement)">
                                        {{ typeLabel(movement) }}
                                    </span>
                                    <span class="rounded-full bg-gray-100 px-1.5 py-0.5 text-[11px] font-semibold leading-4 text-gray-700">
                                        {{ statusLabel(movement) }}
                                    </span>
                                </div>
                            </div>

                            <div class="min-w-0">
                                <p class="break-words text-sm font-semibold leading-5 text-gray-950">
                                    {{ movement.display_concept || movement.concept || movement.reference || '—' }}
                                </p>
                                <p v-if="correctionText(movement)" class="mt-1 text-xs font-medium text-purple-700">{{ correctionText(movement) }}</p>
                                <div v-if="receiptLinks(movement).length" class="mt-1 flex min-w-0 flex-wrap gap-x-2 gap-y-0.5">
                                    <a
                                        v-for="link in receiptLinks(movement)"
                                        :key="`${movement.movement_id}-${link.url}`"
                                        :href="link.url"
                                        target="_blank"
                                        rel="noopener"
                                        class="break-words text-[11px] font-semibold text-red-700 hover:text-red-800"
                                    >
                                        {{ link.label }}
                                    </a>
                                </div>
                                <span v-else-if="receiptFallback(movement)" class="mt-1 inline-flex text-[11px] font-medium text-gray-700">{{ receiptFallback(movement) }}</span>
                            </div>

                            <div class="min-w-0">
                                <p class="text-[11px] font-semibold uppercase tracking-wide text-gray-500 lg:hidden">{{ tr('Cuenta / ubicacion', 'Account / location') }}</p>
                                <p class="break-words leading-5 text-gray-700">{{ movementAccountText(movement) }}</p>
                            </div>

                            <div class="min-w-0">
                                <p class="text-[11px] font-semibold uppercase tracking-wide text-gray-500 lg:hidden">{{ tr('Quien pago / tercero', 'Payer / counterparty') }}</p>
                                <p class="break-words leading-5 text-gray-700">{{ movement.counterparty || '—' }}</p>
                                <p class="text-[11px] text-gray-500">{{ paymentTypeLabel(movement.payment_type) }}</p>
                            </div>

                            <div class="grid min-w-0 grid-cols-2 gap-1 sm:col-span-2 sm:grid-cols-4 lg:contents">
                                <div class="rounded-md bg-emerald-50 px-2 py-1 lg:bg-transparent lg:p-0">
                                    <p class="text-[11px] font-semibold uppercase tracking-wide text-emerald-700 lg:hidden">{{ tr('Ingresos', 'Income') }}</p>
                                    <p class="text-right font-semibold leading-5 text-emerald-700">
                                        {{ ledgerIncomeAmount(movement) === null ? '—' : formatMoney(ledgerIncomeAmount(movement)) }}
                                    </p>
                                </div>
                                <div class="rounded-md bg-rose-50 px-2 py-1 lg:bg-transparent lg:p-0">
                                    <p class="text-[11px] font-semibold uppercase tracking-wide text-rose-700 lg:hidden">{{ tr('Gastos', 'Expenses') }}</p>
                                    <p class="text-right font-semibold leading-5 text-rose-700">
                                        {{ ledgerExpenseAmount(movement) === null ? '—' : formatMoney(ledgerExpenseAmount(movement)) }}
                                    </p>
                                </div>
                                <div class="rounded-md bg-sky-50 px-2 py-1 lg:bg-transparent lg:p-0">
                                    <p class="text-[11px] font-semibold uppercase tracking-wide text-sky-700 lg:hidden">{{ tr('Mov.', 'Mov.') }}</p>
                                    <p class="text-right font-semibold leading-5 text-sky-700">
                                        {{ ledgerTransferAmount(movement) === null ? '—' : formatMoney(ledgerTransferAmount(movement)) }}
                                    </p>
                                </div>
                                <div class="rounded-md bg-gray-50 px-2 py-1 lg:bg-transparent lg:p-0">
                                    <p class="text-[11px] font-semibold uppercase tracking-wide text-gray-600 lg:hidden">{{ tr('Balance', 'Balance') }}</p>
                                    <p class="text-right font-semibold leading-5 text-gray-900">
                                        {{ movementBalanceTextForAccount(movement, section.account) }}
                                    </p>
                                </div>
                            </div>
                            </article>
                        </template>
                    </template>

                    <div v-if="!ledgerLoading && ledgerMovements.length === 0" class="px-3 py-8 text-center text-sm text-gray-500">
                        {{ tr('No hay movimientos para estos filtros.', 'No movements match these filters.') }}
                    </div>
                    <div v-if="ledgerLoading" class="px-3 py-8 text-center text-sm text-gray-500">
                        {{ tr('Cargando libro contable...', 'Loading ledger...') }}
                    </div>
                </div>

                <div class="flex flex-col gap-3 border-t border-gray-100 px-4 py-3 text-sm text-gray-600 sm:flex-row sm:items-center sm:justify-between">
                    <span v-if="ledgerIsAllAccounts">
                        {{ ledgerMovements.length }} {{ tr('movimientos agrupados en', 'movements grouped into') }} {{ ledgerAccountGroups.length }} {{ tr('cuentas', 'accounts') }}
                    </span>
                    <span v-else>
                        {{ tr('Mostrando', 'Showing') }} {{ ledgerPageStart }}-{{ ledgerPageEnd }} {{ tr('de', 'of') }} {{ ledgerMovements.length }}
                    </span>
                    <div v-if="!ledgerIsAllAccounts" class="flex items-center gap-2">
                        <button
                            type="button"
                            class="rounded-lg border border-gray-200 px-3 py-1.5 font-semibold text-gray-700 hover:bg-gray-50 disabled:opacity-50"
                            :disabled="ledgerPage <= 1"
                            @click="setLedgerPage(ledgerPage - 1)"
                        >
                            {{ tr('Anterior', 'Previous') }}
                        </button>
                        <span class="font-medium">{{ ledgerPage }} / {{ ledgerPageCount }}</span>
                        <button
                            type="button"
                            class="rounded-lg border border-gray-200 px-3 py-1.5 font-semibold text-gray-700 hover:bg-gray-50 disabled:opacity-50"
                            :disabled="ledgerPage >= ledgerPageCount"
                            @click="setLedgerPage(ledgerPage + 1)"
                        >
                            {{ tr('Siguiente', 'Next') }}
                        </button>
                    </div>
                </div>
            </section>

            <section class="rounded-lg border border-gray-200 bg-white shadow-sm">
                <div class="border-b border-gray-100 p-4">
                    <div>
                        <h2 class="text-lg font-semibold text-gray-950">{{ tr('Saldos por cuenta', 'Balances by account') }}</h2>
                        <p class="mt-1 text-sm text-gray-500">
                            {{ tr('Cada cuenta muestra donde esta el dinero: efectivo, banco y balance total disponible.', 'Each account shows where the money is: cash, bank, and total available balance.') }}
                        </p>
                    </div>
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                            <tr>
                                <th class="px-3 py-3">{{ tr('Cuenta', 'Account') }}</th>
                                <th class="px-3 py-3 text-right">{{ tr('Efectivo', 'Cash') }}</th>
                                <th class="px-3 py-3 text-right">{{ tr('Banco', 'Bank') }}</th>
                                <th class="px-3 py-3 text-right">{{ tr('Balance', 'Balance') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 bg-white">
                            <tr v-for="account in accountBalanceRows" :key="account.account">
                                <td class="px-3 py-3">
                                    <p class="font-semibold text-gray-950">{{ account.label }}</p>
                                    <p class="text-xs text-gray-500">{{ account.account }}</p>
                                </td>
                                <td class="whitespace-nowrap px-3 py-3 text-right font-semibold text-emerald-700">{{ formatMoney(account.cash_balance) }}</td>
                                <td class="whitespace-nowrap px-3 py-3 text-right font-semibold text-blue-700">{{ formatMoney(account.bank_balance) }}</td>
                                <td class="whitespace-nowrap px-3 py-3 text-right font-semibold text-gray-950">{{ formatMoney(account.total_available) }}</td>
                            </tr>
                            <tr v-if="accountBalanceRows.length === 0">
                                <td colspan="4" class="px-3 py-8 text-center text-gray-500">{{ tr('No hay cuentas para mostrar.', 'No accounts to show.') }}</td>
                            </tr>
                        </tbody>
                        <tfoot class="border-t border-gray-200 bg-gray-50">
                            <tr>
                                <td class="px-3 py-3 font-semibold text-gray-950">{{ tr('Resumen general', 'General summary') }}</td>
                                <td class="whitespace-nowrap px-3 py-3 text-right font-semibold text-emerald-800">{{ formatMoney(summaryTotals.cash_balance) }}</td>
                                <td class="whitespace-nowrap px-3 py-3 text-right font-semibold text-blue-800">{{ formatMoney(summaryTotals.bank_balance) }}</td>
                                <td class="whitespace-nowrap px-3 py-3 text-right font-semibold text-gray-950">{{ formatMoney(summaryTotals.total_available) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </section>
        </div>

        <div
            v-if="exportConfirmationModal.show"
            class="fixed inset-0 z-50 flex items-center justify-center bg-gray-950/50 p-4"
            @click.self="closeExportConfirmationModal(false)"
        >
            <div class="w-full max-w-md rounded-lg bg-white shadow-xl">
                <div class="border-b border-gray-100 p-4">
                    <div class="flex items-start gap-3">
                        <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-amber-50 text-amber-700">
                            <DocumentChartBarIcon class="h-5 w-5" />
                        </div>
                        <div>
                            <h2 class="text-base font-semibold text-gray-950">{{ exportConfirmationModal.title }}</h2>
                            <p class="mt-1 text-sm leading-6 text-gray-600">{{ exportConfirmationModal.message }}</p>
                            <div v-if="exportConfirmationModal.files.length" class="mt-3 rounded-lg border border-gray-200 bg-gray-50 p-3">
                                <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">
                                    {{ tr('Archivos a generar', 'Files to generate') }}
                                </p>
                                <ul class="mt-2 space-y-2">
                                    <li
                                        v-for="file in exportConfirmationModal.files"
                                        :key="file.name"
                                        class="flex items-center justify-between gap-3 text-sm"
                                    >
                                        <span class="text-gray-600">{{ file.label }}</span>
                                        <span class="rounded-md bg-white px-2 py-1 font-mono text-xs font-semibold text-gray-900 shadow-sm">
                                            {{ file.name }}
                                        </span>
                                    </li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="flex flex-col-reverse gap-2 p-4 sm:flex-row sm:justify-end">
                    <button
                        type="button"
                        class="inline-flex min-h-10 items-center justify-center rounded-lg border border-gray-200 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50"
                        @click="closeExportConfirmationModal(false)"
                    >
                        {{ exportConfirmationModal.cancelLabel }}
                    </button>
                    <button
                        type="button"
                        class="inline-flex min-h-10 items-center justify-center rounded-lg px-4 py-2 text-sm font-semibold"
                        :class="exportConfirmationModal.confirmClass"
                        @click="closeExportConfirmationModal(true)"
                    >
                        {{ exportConfirmationModal.confirmLabel }}
                    </button>
                </div>
            </div>
        </div>

        <div
            v-if="generatedLedgerFilesModal.show"
            class="fixed inset-0 z-50 flex items-center justify-center bg-gray-950/50 p-4"
            @click.self="closeGeneratedLedgerFilesModal"
        >
            <div class="w-full max-w-lg rounded-lg bg-white shadow-xl">
                <div class="border-b border-gray-100 p-4">
                    <div class="flex items-start gap-3">
                        <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-emerald-50 text-emerald-700">
                            <ArrowDownTrayIcon class="h-5 w-5" />
                        </div>
                        <div>
                            <h2 class="text-base font-semibold text-gray-950">
                                {{ tr('Archivos generados', 'Generated files') }}
                            </h2>
                            <p class="mt-1 text-sm leading-6 text-gray-600">
                                {{ tr('Abre o descarga cada archivo generado para este reporte.', 'Open or download each file generated for this report.') }}
                            </p>
                        </div>
                    </div>
                </div>
                <div class="space-y-2 p-4">
                    <a
                        v-for="file in generatedLedgerFilesModal.files"
                        :key="file.url"
                        :href="file.url"
                        target="_blank"
                        rel="noopener"
                        class="flex items-center justify-between gap-3 rounded-lg border border-gray-200 bg-white px-3 py-3 text-sm hover:bg-gray-50"
                    >
                        <span>
                            <span class="block font-semibold text-gray-950">{{ file.label }}</span>
                            <span class="block font-mono text-xs text-gray-500">{{ file.file_name }}</span>
                        </span>
                        <span class="shrink-0 text-xs font-semibold text-gray-500">
                            {{ formatFileSize(file.size) }}
                        </span>
                    </a>
                </div>
                <div class="flex justify-end border-t border-gray-100 p-4">
                    <button
                        type="button"
                        class="inline-flex min-h-10 items-center justify-center rounded-lg border border-gray-200 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50"
                        @click="closeGeneratedLedgerFilesModal"
                    >
                        {{ tr('Cerrar', 'Close') }}
                    </button>
                </div>
            </div>
        </div>
    </PathfinderLayout>
</template>
