<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import PathfinderLayout from '@/Layouts/PathfinderLayout.vue'
import MovementInlineEditor from '@/Components/Finance/MovementInlineEditor.vue'
import {
    ArrowPathIcon,
    ArrowUpTrayIcon,
    BanknotesIcon,
    CheckCircleIcon,
    ChevronDownIcon,
    ChevronRightIcon,
    CreditCardIcon,
    CurrencyDollarIcon,
    DocumentTextIcon,
    ExclamationTriangleIcon,
    PencilSquareIcon,
    QuestionMarkCircleIcon,
    QrCodeIcon,
    TrashIcon,
} from '@heroicons/vue/24/outline'
import {
    createFinanceEngineConcept,
    createFinanceEngineExpense,
    createFinanceEngineIncome,
    fetchFinanceEngineCashbox,
    reimburseFinanceEngineExpense,
    removeFinanceEngineExpenseReceipt,
    removeFinanceEngineReimbursementPaymentProof,
    uploadFinanceEngineExpenseReceipt,
    uploadFinanceEngineReimbursementPaymentProof,
} from '@/Services/api'
import { useGeneral } from '@/Composables/useGeneral'
import { useLocale } from '@/Composables/useLocale'

const props = defineProps({
    auth_user: { type: Object, required: true },
})

const { showToast } = useGeneral()
const { tr } = useLocale()

const loading = ref(false)
const refreshing = ref(false)
const savingIncome = ref(false)
const savingExpense = ref(false)
const loadError = ref('')
const selectedClubId = ref(null)
const currentClub = ref(null)
const clubs = ref([])
const classes = ref([])
const members = ref([])
const staff = ref([])
const concepts = ref([])
const accounts = ref([])
const expenses = ref([])
const reimbursementPayees = ref([])
const engineReport = ref(null)
const movementDomain = ref('all')
const movementSort = ref('date')
const movementSearch = ref('')
const movementPage = ref(1)
const movementPageSize = ref(10)
const balanceAccountFilter = ref('all')
const openReimbursementMovementGroups = ref({})
const openExpenseFollowUpSections = ref({
    missing: false,
    attached: false,
})
const incomeErrors = ref({})
const expenseErrors = ref({})
const conceptErrors = ref({})
const incomeCheckInput = ref(null)
const expenseReceiptInput = ref(null)
const expenseReceiptFiles = ref({})
const reimbursementPaymentProofFiles = ref({})
const reimbursementForms = ref({})
const expenseActionBusy = ref({})
const expenseActionErrors = ref({})
const expenseUploadProgress = ref({})
const showConceptModal = ref(false)
const savingConcept = ref(false)
const showReimbursementOverflowModal = ref(false)
const tutorialActive = ref(false)
const tutorialStepIndex = ref(0)
const tutorialTargetRect = ref(null)
const tutorialReturnClubId = ref(null)
const tutorialBalances = ref({})
const tutorialMovements = ref([])
const tutorialNextId = ref(9000)
const tutorialReceiptWindow = ref(null)
const CREATE_CONCEPT_OPTION = '__create_concept__'
const CUSTOM_PAYER_OPTION = '__custom_payer__'
const MOVEMENT_PAGE_SIZE_OPTIONS = [10, 15, 20]
const TUTORIAL_CLUB_ID = -9001
const TUTORIAL_MEMBER_ID = -9101
const TUTORIAL_CONCEPT_ID = -9201
const TUTORIAL_ACCOUNT = 'club_budget'
const TUTORIAL_REIMBURSEMENT_ACCOUNT = 'reimbursement_to'
const IMAGE_RECEIPT_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp']
const DOCUMENT_RECEIPT_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'pdf']

const today = () => new Date().toISOString().slice(0, 10)

const incomeForm = ref({
    mode: 'existing',
    concept_key: '',
    selected_event_concept_ids: [],
    payer_key: '',
    payer_name: '',
    concept_text: '',
    pay_to: 'club_budget',
    amount_paid: '',
    payment_date: today(),
    payment_type: 'cash',
    zelle_phone: '',
    check_image: null,
    notes: '',
})

const expenseForm = ref({
    pay_to: 'club_budget',
    funds_location: 'cash',
    amount: '',
    expense_date: today(),
    description: '',
    notes: '',
    reimbursed_to: '',
    reimbursement_target_mode: 'new',
    reimbursement_payee_id: '',
    reimbursement_payee_name: '',
    reimbursement_payee_phone: '',
    reimbursement_payee_email: '',
    receipt_image: null,
})

const conceptForm = ref({
    concept: '',
    amount: '',
    type: 'mandatory',
    reusable: false,
    pay_to: 'club_budget',
    payment_expected_by: '',
    scope_type: 'club_wide',
    class_id: '',
    member_id: '',
    staff_id: '',
})

const canSelectClub = computed(() => props.auth_user?.profile_type === 'superadmin' || clubs.value.length > 1)
const activeClubName = computed(() => currentClub.value?.club_name || clubs.value.find((club) => Number(club.id) === Number(selectedClubId.value))?.club_name || '—')
const summary = computed(() => engineReport.value?.summary || {})
const summaryAccounts = computed(() => summary.value?.accounts || [])
const allMovements = computed(() => engineReport.value?.movements || [])
const movementNumericId = (movement) => {
    const match = String(movement?.movement_id || movement?.id || '').match(/(\d+)(?!.*\d)/)

    return match ? Number(match[1]) : 0
}
const movementDateValue = (movement) => {
    const raw = movement?.occurred_at || movement?.created_at || movement?.date || ''
    const timestamp = new Date(String(raw).replace(' ', 'T')).getTime()

    return Number.isNaN(timestamp) ? 0 : timestamp
}
const movementStatusValue = (movement) => String(movement?.status || 'posted').toLowerCase()
const recentMovements = computed(() => {
    const query = movementSearch.value.trim().toLowerCase()
    const rows = movementDomain.value === 'all'
        ? allMovements.value
        : allMovements.value.filter((movement) => movement.domain === movementDomain.value)
    const filteredRows = query
        ? rows.filter((movement) => [
            movement?.movement_id,
            movement?.id,
            movement?.concept,
            movement?.display_concept,
            movement?.description,
            movement?.notes,
            movement?.reference,
        ].some((value) => String(value || '').toLowerCase().includes(query)))
        : rows

    return filteredRows.slice().sort((a, b) => {
        if (movementSort.value === 'status') {
            const statusCompare = movementStatusValue(a).localeCompare(movementStatusValue(b))
            if (statusCompare !== 0) return statusCompare
        }

        if (movementSort.value === 'id') {
            const idCompare = movementNumericId(b) - movementNumericId(a)
            if (idCompare !== 0) return idCompare
        }

        const dateCompare = movementDateValue(b) - movementDateValue(a)
        if (dateCompare !== 0) return dateCompare

        return movementNumericId(b) - movementNumericId(a)
    })
})
const recentMovementGroups = computed(() => {
    const groups = []
    const index = new Map()

    recentMovements.value.forEach((movement) => {
        const reimbursementGroup = movement.reimbursement_group || null
        const key = reimbursementGroup?.key || `movement:${movement.movement_id}`

        if (!index.has(key)) {
            const group = {
                key,
                reimbursementGroup,
                movements: [],
            }
            index.set(key, group)
            groups.push(group)
        }

        index.get(key).movements.push(movement)
    })

    return groups
})
const movementPageCount = computed(() => Math.max(Math.ceil(recentMovementGroups.value.length / movementPageSize.value), 1))
const paginatedMovementGroups = computed(() => {
    const start = (movementPage.value - 1) * movementPageSize.value

    return recentMovementGroups.value.slice(start, start + movementPageSize.value)
})
const movementPageStart = computed(() => recentMovementGroups.value.length ? ((movementPage.value - 1) * movementPageSize.value) + 1 : 0)
const movementPageEnd = computed(() => Math.min(movementPage.value * movementPageSize.value, recentMovementGroups.value.length))

const filteredConcepts = computed(() => {
    if (!selectedClubId.value) return concepts.value
    return concepts.value.filter((concept) => Number(concept.club_id) === Number(selectedClubId.value))
})
const filteredMembers = computed(() => {
    if (!selectedClubId.value) return members.value
    return members.value.filter((member) => Number(member.club_id) === Number(selectedClubId.value))
})
const filteredStaff = computed(() => {
    if (!selectedClubId.value) return staff.value
    return staff.value.filter((person) => Number(person.club_id) === Number(selectedClubId.value))
})
const filteredClasses = computed(() => {
    if (!selectedClubId.value) return classes.value
    return classes.value.filter((clubClass) => Number(clubClass.club_id) === Number(selectedClubId.value))
})

const scopeTypeOptions = computed(() => [
    { value: 'club_wide', label: tr('Todo el club', 'Whole club') },
    { value: 'class', label: tr('Clase especifica', 'Specific class') },
    { value: 'member', label: tr('Miembro especifico', 'Specific member') },
    { value: 'staff_wide', label: tr('Todo el personal', 'All staff') },
    { value: 'staff', label: tr('Personal especifico', 'Specific staff') },
])

const accountDisplayLabel = (payTo, fallback = null) => {
    if (payTo === 'reimbursement_to') return tr('Reembolsos pendientes', 'Pending reimbursements')
    if (payTo === 'club_budget' && (!fallback || fallback === 'club_budget')) return tr('Presupuesto del club', 'Club budget')

    return fallback || payTo || '—'
}

const accountOptions = computed(() => {
    const rows = new Map()
    ;(accounts.value || [])
        .filter((account) => !selectedClubId.value || Number(account.club_id) === Number(selectedClubId.value))
        .forEach((account) => {
            rows.set(account.pay_to, {
                value: account.pay_to,
                label: accountDisplayLabel(account.pay_to, account.label),
            })
        })
    summaryAccounts.value.forEach((account) => {
        if (!rows.has(account.account)) {
            rows.set(account.account, {
                value: account.account,
                label: accountDisplayLabel(account.account),
            })
        }
    })
    if (!rows.has('club_budget')) {
        rows.set('club_budget', { value: 'club_budget', label: accountDisplayLabel('club_budget') })
    }
    return Array.from(rows.values())
})
const isOperatingAccount = (payTo) => payTo !== 'reimbursement_to'
const operatingAccountOptions = computed(() => accountOptions.value.filter((account) => isOperatingAccount(account.value)))
const operatingSummaryAccounts = computed(() => summaryAccounts.value.filter((account) => isOperatingAccount(account.account)))
const reimbursementFundingOptions = computed(() => operatingAccountOptions.value)
const reimbursementPayeeOptions = computed(() => reimbursementPayees.value.map((payee) => ({
    value: payee.id,
    label: [
        payee.name,
        payee.phone,
        payee.email,
    ].filter(Boolean).join(' · '),
})))
const balanceAccountOptions = computed(() => [
    { value: 'all', label: tr('Todas las cuentas', 'All accounts') },
    ...operatingSummaryAccounts.value.map((account) => ({
        value: account.account,
        label: accountOptions.value.find((option) => option.value === account.account)?.label || account.account,
    })),
])
const selectedBalanceAccountSummary = computed(() => {
    if (balanceAccountFilter.value === 'all') return null

    return operatingSummaryAccounts.value.find((account) => account.account === balanceAccountFilter.value) || null
})
const balanceSummary = computed(() => {
    const row = selectedBalanceAccountSummary.value

    if (row) {
        return {
            cash_balance: Number(row.cash_balance || 0),
            bank_balance: Number(row.bank_balance || 0),
            total_available: Number(row.total_available ?? (Number(row.cash_balance || 0) + Number(row.bank_balance || 0))),
        }
    }

    return {
        cash_balance: operatingSummaryAccounts.value.reduce((sum, account) => sum + Number(account.cash_balance || 0), 0),
        bank_balance: operatingSummaryAccounts.value.reduce((sum, account) => sum + Number(account.bank_balance || 0), 0),
        total_available: operatingSummaryAccounts.value.reduce((sum, account) => sum + Number(account.total_available ?? (Number(account.cash_balance || 0) + Number(account.bank_balance || 0))), 0),
    }
})
const reimbursementBalanceSummary = computed(() => {
    const row = summaryAccounts.value.find((account) => account.account === 'reimbursement_to')

    return {
        cash_balance: Number(row?.cash_balance || 0),
        bank_balance: Number(row?.bank_balance || 0),
        total_available: Number(row?.total_available ?? (Number(row?.cash_balance || 0) + Number(row?.bank_balance || 0))),
    }
})
const selectedExpenseAccountSummary = computed(() =>
    summaryAccounts.value.find((account) => account.account === expenseForm.value.pay_to) || null
)
const expenseSelectedLocationBalance = computed(() => {
    const summaryRow = selectedExpenseAccountSummary.value
    const fundsLocation = expenseForm.value.funds_location || 'cash'

    return Math.max(Number(summaryRow?.[`${fundsLocation}_balance`] || 0), 0)
})
const expenseSelectedAccountTotalBalance = computed(() => {
    const summaryRow = selectedExpenseAccountSummary.value

    return Math.max(Number(summaryRow?.total_available ?? (Number(summaryRow?.cash_balance || 0) + Number(summaryRow?.bank_balance || 0))), 0)
})
const expenseAmount = computed(() => Number(expenseForm.value.amount || 0))
const expenseTransferAmount = computed(() => Math.max(
    Math.min(
        roundCurrency(expenseAmount.value - expenseSelectedLocationBalance.value),
        roundCurrency(expenseSelectedAccountTotalBalance.value - expenseSelectedLocationBalance.value),
    ),
    0,
))
const expenseNeedsInternalTransfer = computed(() => expenseAmount.value > 0 && expenseTransferAmount.value > 0)
const expenseOverflowAmount = computed(() => Math.max(expenseAmount.value - expenseSelectedAccountTotalBalance.value, 0))
const expenseHasOverflow = computed(() => expenseAmount.value > 0 && expenseOverflowAmount.value > 0)
const selectedReimbursementPayee = computed(() =>
    reimbursementPayees.value.find((payee) => Number(payee.id) === Number(expenseForm.value.reimbursement_payee_id)) || null
)
const reimbursementTargetLabel = computed(() => {
    if (expenseForm.value.reimbursement_target_mode === 'existing' && selectedReimbursementPayee.value) {
        return selectedReimbursementPayee.value.name
    }

    return expenseForm.value.reimbursement_payee_name || tr('Pendiente de registrar', 'Pending registration')
})
const regularExpenseRows = computed(() => expenses.value.filter((expense) => expense.pay_to !== 'reimbursement_to'))
const reimbursementExpenseRows = computed(() => expenses.value.filter((expense) => expense.pay_to === 'reimbursement_to'))
const isCorrectionExpense = (expense) => Boolean(
    expense?.is_cancelled
    || expense?.related_canceled_movement_id
    || expense?.canceling_id
    || expense?.reversed_expense_id
    || ['cancelled', 'cancellation'].includes(String(expense?.status || '').toLowerCase())
)
const expenseFollowUpRows = computed(() => expenses.value.filter((expense) => !isCorrectionExpense(expense)).map((expense) => ({
    key: `${expense.pay_to === 'reimbursement_to' ? 'reimbursement' : 'expense'}-${expense.id}`,
    type: expense.pay_to === 'reimbursement_to' ? 'reimbursement' : 'expense',
    expense,
})))
const hasExpenseFollowUpProof = (row) => {
    const expense = row?.expense || row

    if (expense?.pay_to === 'reimbursement_to') {
        return Boolean(
            expense?.reimbursement_payment_proof_url
            || expense?.reimbursement_receipt_signed_at
            || expense?.reimbursement_receipt_url
            || expense?.reimbursement_confirmation_url
        )
    }

    return Boolean(expense?.receipt_url || expense?.receipt_path)
}
const expenseFollowUpSections = computed(() => {
    const missing = []
    const attached = []

    expenseFollowUpRows.value.forEach((row) => {
        ;(hasExpenseFollowUpProof(row) ? attached : missing).push(row)
    })

    return [
        {
            key: 'missing',
            title: tr('Sin comprobante', 'Missing proof'),
            description: tr('Movimientos que necesitan comprobante o accion de seguimiento.', 'Movements that need proof or follow-up action.'),
            rows: missing,
        },
        {
            key: 'attached',
            title: tr('Con comprobante', 'With proof'),
            description: tr('Movimientos con comprobante ya agregado; puedes revisarlo o reemplazarlo.', 'Movements with proof already added; you can review or replace it.'),
            rows: attached,
        },
    ]
})
const isExpenseFollowUpSectionOpen = (key) => openExpenseFollowUpSections.value[key] === true
const toggleExpenseFollowUpSection = (key) => {
    openExpenseFollowUpSections.value = {
        ...openExpenseFollowUpSections.value,
        [key]: !openExpenseFollowUpSections.value[key],
    }
}
const hasExpenseFollowUp = computed(() => expenseFollowUpRows.value.length > 0)

const isEventConcept = (concept) => Boolean(concept?.event_id && concept?.event_fee_component_id)
const eventComponent = (concept) => concept?.event_fee_component || concept?.eventFeeComponent || null
const eventTitle = (concept) => concept?.event?.title || concept?.event_title || concept?.concept || 'Evento'
const conceptAmount = (concept) => Number(concept?.amount || 0)
const incomeConcepts = computed(() => filteredConcepts.value.filter((concept) => isOperatingAccount(concept.pay_to || 'club_budget')))

const eventGroups = computed(() => {
    const groups = new Map()
    incomeConcepts.value.filter(isEventConcept).forEach((concept) => {
        const key = `event:${concept.event_id}:${concept.club_id}`
        if (!groups.has(key)) {
            groups.set(key, {
                key,
                type: 'event_bundle',
                label: eventTitle(concept),
                club_id: concept.club_id,
                event_id: concept.event_id,
                pay_to: concept.pay_to || 'club_budget',
                concepts: [],
            })
        }

        groups.get(key).concepts.push(concept)
    })

    return Array.from(groups.values()).map((group) => {
        const sorted = group.concepts.slice().sort((a, b) => {
            const aOrder = Number(eventComponent(a)?.sort_order || 0)
            const bOrder = Number(eventComponent(b)?.sort_order || 0)
            return aOrder - bOrder || Number(a.id) - Number(b.id)
        })

        return {
            ...group,
            concepts: sorted,
            amount: sorted.reduce((sum, concept) => sum + conceptAmount(concept), 0),
        }
    })
})

const conceptOptions = computed(() => {
    const groupedIds = new Set(eventGroups.value.flatMap((group) => group.concepts.map((concept) => Number(concept.id))))
    const regular = incomeConcepts.value
        .filter((concept) => !groupedIds.has(Number(concept.id)))
        .map((concept) => ({
            key: `concept:${concept.id}`,
            type: 'concept',
            label: concept.concept,
            amount: conceptAmount(concept),
            pay_to: concept.pay_to || 'club_budget',
            concept,
        }))

    return [
        ...eventGroups.value.map((group) => ({
            key: group.key,
            type: 'event_bundle',
            label: `${group.label} (${tr('evento', 'event')})`,
            amount: group.amount,
            pay_to: group.pay_to,
            group,
        })),
        ...regular,
    ]
})

const selectedConceptOption = computed(() => conceptOptions.value.find((option) => option.key === incomeForm.value.concept_key) || null)
const selectedEventGroup = computed(() => selectedConceptOption.value?.group || null)
const selectedEventComponents = computed(() => {
    if (!selectedEventGroup.value) return []
    const selected = new Set(incomeForm.value.selected_event_concept_ids.map((id) => Number(id)))
    return selectedEventGroup.value.concepts.filter((concept) => selected.has(Number(concept.id)))
})
const selectedEventRequiredIds = computed(() => {
    if (!selectedEventGroup.value) return []
    return selectedEventGroup.value.concepts
        .filter((concept) => Boolean(eventComponent(concept)?.is_required ?? true))
        .map((concept) => Number(concept.id))
})
const selectedIncomeExpected = computed(() => {
    if (incomeForm.value.mode === 'manual') return null
    if (selectedEventGroup.value) {
        return selectedEventComponents.value.reduce((sum, concept) => sum + conceptAmount(concept), 0)
    }
    return selectedConceptOption.value?.amount ?? null
})
const payerOptions = computed(() => [
    ...filteredMembers.value.map((member) => ({
        value: `member:${member.id}`,
        label: `${tr('Miembro', 'Member')}: ${member.applicant_name || member.name || `#${member.id}`}`,
    })),
    ...filteredStaff.value.map((person) => ({
        value: `staff:${person.id}`,
        label: `${tr('Personal', 'Staff')}: ${person.name || person.email || `#${person.id}`}`,
    })),
])

const formatMoney = (value) => {
    const amount = Number(value || 0)

    return `${amount < 0 ? '-' : ''}$${Math.abs(amount).toFixed(2)}`
}
const roundCurrency = (value) => Math.round((Number(value || 0) + Number.EPSILON) * 100) / 100
const padDatePart = (value) => String(value).padStart(2, '0')
const dateParts = (value) => {
    if (!value) return null

    const raw = String(value)
    const normalizedMatch = raw.match(/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2}))?/)
    if (normalizedMatch) {
        return {
            year: normalizedMatch[1],
            month: normalizedMatch[2],
            day: normalizedMatch[3],
            hour: normalizedMatch[4] || null,
            minute: normalizedMatch[5] || null,
        }
    }

    const parsed = new Date(raw.replace(' ', 'T'))
    if (Number.isNaN(parsed.getTime())) return null

    return {
        year: parsed.getFullYear(),
        month: padDatePart(parsed.getMonth() + 1),
        day: padDatePart(parsed.getDate()),
        hour: padDatePart(parsed.getHours()),
        minute: padDatePart(parsed.getMinutes()),
    }
}
const formatDate = (value) => {
    const parts = dateParts(value)

    return parts ? `${parts.year}-${parts.month}-${parts.day}` : '—'
}
const formatDateTime = (value) => {
    const parts = dateParts(value)
    if (!parts) return '—'

    const date = `${parts.year}-${parts.month}-${parts.day}`

    return parts.hour && parts.minute ? `${date} ${parts.hour}:${parts.minute}` : date
}
const accountLabel = (payTo) => accountOptions.value.find((account) => account.value === payTo)?.label || payTo || '—'
const locationLabel = (location) => {
    if (location === 'cash') return tr('Efectivo', 'Cash')
    if (location === 'bank') return tr('Banco', 'Bank')
    if (location === 'staff_custody') return tr('Custodia staff', 'Staff custody')
    if (location === 'external') return tr('Externo', 'External')
    if (location === 'internal') return tr('Interno', 'Internal')
    return location || '—'
}
const expenseStatusLabel = (status) => {
    if (status === 'completed') return tr('Completado', 'Completed')
    if (status === 'working') return tr('Pendiente de comprobante', 'Proof pending')
    if (status === 'pending_reimbursement') return tr('Reembolso pendiente', 'Pending reimbursement')
    return status || tr('Registrado', 'Posted')
}
const movementTone = (movement) => {
    if (movement.domain === 'income') return 'border-emerald-200 bg-emerald-50 text-emerald-800'
    if (movement.domain === 'expense') return 'border-rose-200 bg-rose-50 text-rose-800'
    return 'border-blue-200 bg-blue-50 text-blue-800'
}
const movementAmountLabel = (movement) => {
    if (movement.domain === 'income') return `+${formatMoney(movement.amount)}`
    if (movement.domain === 'expense') return `-${formatMoney(movement.amount)}`
    return formatMoney(movement.amount)
}
const movementDisplayConcept = (movement) => movement?.display_concept || movement?.concept || movement?.reference || movement?.kind || tr('Movimiento', 'Movement')
const applyMovementConceptOverride = (movementKey, data = {}) => {
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
const handleMovementEditUpdated = ({ movementKey, data }) => applyMovementConceptOverride(movementKey, data)
const movementGroupTitle = (group) => {
    const originMovementId = group.reimbursementGroup?.origin_movement_id
    const origin = group.movements.find((movement) => movement.movement_id === originMovementId)
        || group.movements.find((movement) => movement.reimbursement_group?.role === 'origin_expense')
        || group.movements[0]

    return movementDisplayConcept(origin) || group.reimbursementGroup?.label || tr('Movimiento', 'Movement')
}
const movementGroupSummary = (group) => {
    const reimbursementGroup = group.reimbursementGroup
    if (!reimbursementGroup) return null

    return [
        reimbursementGroup.origin_expense_id ? `${tr('Gasto origen', 'Origin expense')} #${reimbursementGroup.origin_expense_id}` : null,
        reimbursementGroup.reimbursement_expense_id ? `${tr('Reembolso', 'Reimbursement')} #${reimbursementGroup.reimbursement_expense_id}` : null,
        reimbursementGroup.reimbursed_to ? `${tr('A', 'To')} ${reimbursementGroup.reimbursed_to}` : null,
    ].filter(Boolean).join(' · ')
}
const movementGroupAmountSummary = (group) => {
    const reimbursementGroup = group.reimbursementGroup
    if (!reimbursementGroup) return null

    return [
        reimbursementGroup.origin_amount !== null && reimbursementGroup.origin_amount !== undefined
            ? `${tr('Cuenta', 'Account')} ${formatMoney(reimbursementGroup.origin_amount)}`
            : null,
        reimbursementGroup.reimbursement_amount !== null && reimbursementGroup.reimbursement_amount !== undefined
            ? `${tr('Reembolso', 'Reimbursement')} ${formatMoney(reimbursementGroup.reimbursement_amount)}`
        : null,
    ].filter(Boolean).join(' · ')
}
const isReimbursementMovementGroupOpen = (group) => openReimbursementMovementGroups.value[group.key] === true
const toggleReimbursementMovementGroup = (group) => {
    openReimbursementMovementGroups.value = {
        ...openReimbursementMovementGroups.value,
        [group.key]: !isReimbursementMovementGroupOpen(group),
    }
}
const movementAccountDisplay = (movement) => {
    if (movement?.domain === 'transfer') {
        const from = movement.from_account_label || accountLabel(movement.from_account)
        const to = movement.to_account_label || accountLabel(movement.to_account)

        return `${from} → ${to}`
    }

    return movement?.account_label || accountLabel(movement?.account || movement?.from_account || movement?.to_account)
}
const movementLocationDisplay = (movement) => {
    if (movement?.domain === 'transfer') {
        return `${locationLabel(movement.from_location)} → ${locationLabel(movement.to_location)}`
    }

    return locationLabel(movement?.location || movement?.from_location || movement?.to_location)
}
const reimbursementGroupStatus = (group) => {
    const status = group.reimbursementGroup?.reimbursement_status
    const hasSettlement = group.movements.some((movement) => ['settlement_credit', 'settlement_expense'].includes(movement.reimbursement_group?.role))

    if (status === 'pending_reimbursement' && !hasSettlement) return tr('Pendiente', 'Pending')
    if (hasSettlement || status === 'completed') return tr('Liquidado', 'Settled')

    return status || tr('Registrado', 'Posted')
}
const reimbursementGroupAccounts = (group) => Array.from(new Set(
    group.movements
        .flatMap((movement) => {
            if (movement.domain === 'transfer') {
                return [
                    movement.from_account_label || accountLabel(movement.from_account),
                    movement.to_account_label || accountLabel(movement.to_account),
                ]
            }

            return [movement.account_label || accountLabel(movement.account || movement.from_account || movement.to_account)]
        })
        .filter((value) => value && value !== '—')
)).join(' · ')
const reimbursementRoleOrder = {
    origin_expense: 10,
    pending_reimbursement: 20,
    settlement_credit: 30,
    settlement_expense: 40,
}
const reimbursementRoleLabel = (role) => ({
    origin_expense: tr('Gasto original', 'Original expense'),
    pending_reimbursement: tr('Reembolso pendiente', 'Pending reimbursement'),
    settlement_credit: tr('Credito a reembolsos', 'Credit to reimbursements'),
    settlement_expense: tr('Pago desde cuenta', 'Payout from account'),
})[role] || role || tr('Movimiento', 'Movement')
const reimbursementGroupDetailRows = (group) => group.movements.slice().sort((a, b) => {
    const roleCompare = (reimbursementRoleOrder[a.reimbursement_group?.role] || 99) - (reimbursementRoleOrder[b.reimbursement_group?.role] || 99)
    if (roleCompare !== 0) return roleCompare

    const dateCompare = movementDateValue(a) - movementDateValue(b)
    if (dateCompare !== 0) return dateCompare

    return movementNumericId(a) - movementNumericId(b)
})
const reimbursementAccountingRows = (group) => {
    const detailRows = reimbursementGroupDetailRows(group)
    const byRole = new Map(detailRows.map((movement) => [movement.reimbursement_group?.role, movement]))
    const rows = [
        {
            role: 'origin_expense',
            title: tr('Salida por gasto original', 'Original expense outflow'),
            movement: byRole.get('origin_expense'),
        },
        {
            role: 'pending_reimbursement',
            title: tr('Responsabilidad de reembolso pendiente', 'Pending reimbursement liability'),
            movement: byRole.get('pending_reimbursement'),
        },
        {
            role: 'settlement_credit',
            title: tr('Credito interno a reimbursement_to', 'Internal credit into reimbursement_to'),
            movement: byRole.get('settlement_credit'),
        },
        {
            role: 'settlement_expense',
            title: tr('Pago real desde cuenta origen', 'Real payout from source account'),
            movement: byRole.get('settlement_expense'),
        },
    ]

    return rows.filter((row) => row.movement)
}
const normalizeErrors = (error) => {
    const errors = error?.response?.data?.errors || {}
    return Object.fromEntries(Object.entries(errors).map(([key, value]) => [key, Array.isArray(value) ? value[0] : value]))
}
const firstError = (errors, key) => errors?.[key] || null
const setMovementPage = (page) => {
    movementPage.value = Math.min(Math.max(Number(page) || 1, 1), movementPageCount.value)
}
const reimbursementTargetError = computed(() =>
    firstError(expenseErrors.value, 'reimbursement_payee_id')
    || firstError(expenseErrors.value, 'reimbursement_payee_name')
    || firstError(expenseErrors.value, 'reimbursement_payee_email')
    || firstError(expenseErrors.value, 'reimbursed_to')
)
const px = (value) => `${Math.round(Number(value) || 0)}px`
const viewportSize = () => {
    if (typeof window === 'undefined') {
        return { width: 1024, height: 768 }
    }

    return {
        width: window.innerWidth || 1024,
        height: window.innerHeight || 768,
    }
}
const tutorialSteps = computed(() => [
    {
        id: 'intro',
        target: '[data-tour="cashbox-header"]',
        title: tr('Caja', 'Cashbox'),
        body: tr('Modo tutorial usa datos simulados. Lo que registres aqui no toca la base de datos y se borra al salir.', 'Tutorial mode uses simulated data. What you record here does not touch the database and is cleared when you exit.'),
    },
    {
        id: 'balances',
        target: '[data-tour="cashbox-balances"]',
        title: tr('Estado de cuenta', 'Account status'),
        body: tr('Estas tarjetas muestran cuanto hay en efectivo, banco, total disponible y reembolsos pendientes para la cuenta seleccionada.', 'These cards show cash, bank, total available, and pending reimbursements for the selected account.'),
    },
    {
        id: 'account-filter',
        target: '[data-tour="cashbox-account-filter"]',
        title: tr('Filtro de cuenta', 'Account filter'),
        body: tr('Usa este selector para revisar una cuenta especifica o ver el balance combinado de todas las cuentas operativas.', 'Use this selector to review one account or the combined balance across operating accounts.'),
    },
    {
        id: 'saved-income',
        target: '[data-tour="cashbox-income-form"]',
        title: tr('Ingreso con concepto guardado', 'Income with saved concept'),
        body: tr('El formulario esta preparado con una cuota mensual de practica. Revisa concepto, pagador, monto y metodo.', 'The form is prepared with a practice monthly dues payment. Review concept, payer, amount, and method.'),
    },
    {
        id: 'income-method',
        target: '[data-tour="cashbox-income-method"]',
        title: tr('Metodo de ingreso', 'Income method'),
        body: tr('El metodo define donde queda el dinero: efectivo, banco por Zelle o transferencia, cheque, o saldo inicial.', 'The method defines where the money lands: cash, bank through Zelle or transfer, check, or initial balance.'),
    },
    {
        id: 'save-saved-income',
        target: '[data-tour="cashbox-save-income"]',
        title: tr('Guardar ingreso', 'Save income'),
        body: tr('Haz clic para simular el primer ingreso. Caja respondera como si la API lo hubiera guardado.', 'Click to simulate the first income. Cashbox will respond as if the API saved it.'),
    },
    {
        id: 'manual-income',
        target: '[data-tour="cashbox-income-form"]',
        title: tr('Ingreso manual', 'Manual income'),
        body: tr('Ahora el formulario se prepara con un concepto manual, util para donaciones o ingresos que no tienen concepto creado.', 'Now the form is prepared with a manual concept, useful for donations or income without a saved concept.'),
    },
    {
        id: 'manual-payer',
        target: '[data-tour="cashbox-payer"]',
        title: tr('Pagador', 'Payer'),
        body: tr('Selecciona un miembro o personal existente. Si no esta en la lista, usa pagador externo / otro para escribir un nombre nuevo.', 'Select an existing member or staff person. If they are not listed, use external / other payer to enter a new name.'),
    },
    {
        id: 'manual-payer-name',
        target: '[data-tour="cashbox-payer-name"]',
        title: tr('Nombre del pagador', 'Payer name'),
        body: tr('Cuando el pagador es externo u otro, escribe aqui el nombre que debe aparecer en el movimiento.', 'When the payer is external or other, enter the name that should appear on the movement.'),
    },
    {
        id: 'save-manual-income',
        target: '[data-tour="cashbox-save-income"]',
        title: tr('Guardar ingreso manual', 'Save manual income'),
        body: tr('Haz clic para simular el ingreso manual y ver como sube el balance de banco.', 'Click to simulate the manual income and see the bank balance increase.'),
    },
    {
        id: 'normal-expense',
        target: '[data-tour="cashbox-expense-form"]',
        title: tr('Gasto normal', 'Normal expense'),
        body: tr('El formulario se prepara con un gasto que la cuenta puede cubrir completo desde efectivo.', 'The form is prepared with an expense the account can fully cover from cash.'),
    },
    {
        id: 'expense-proof',
        target: '[data-tour="cashbox-expense-proof"]',
        title: tr('Comprobante de gasto', 'Expense proof'),
        body: tr('Adjunta el comprobante del gasto cuando lo tengas. Si no existe al crear el gasto, podras subirlo luego desde seguimiento.', 'Attach the expense proof when available. If it is missing when the expense is created, it can be uploaded later from follow-up.'),
    },
    {
        id: 'save-normal-expense',
        target: '[data-tour="cashbox-save-expense"]',
        title: tr('Guardar gasto', 'Save expense'),
        body: tr('Haz clic para simular el gasto normal. El movimiento aparecera abajo y bajara el efectivo.', 'Click to simulate the normal expense. The movement will appear below and cash will decrease.'),
    },
    {
        id: 'reimbursement-expense',
        target: '[data-tour="cashbox-expense-form"]',
        title: tr('Gasto con reembolso', 'Expense with reimbursement'),
        body: tr('Ahora el gasto excede lo disponible. Caja dejara el excedente como reembolso pendiente a la persona indicada.', 'Now the expense exceeds what is available. Cashbox will leave the excess as a pending reimbursement for the selected person.'),
    },
    {
        id: 'save-reimbursement-expense',
        target: '[data-tour="cashbox-save-expense"]',
        title: tr('Crear reembolso pendiente', 'Create pending reimbursement'),
        body: tr('Haz clic para simular el gasto con excedente y crear el reembolso pendiente.', 'Click to simulate the overflow expense and create the pending reimbursement.'),
    },
    {
        id: 'settle-reimbursement',
        target: '[data-tour="cashbox-follow-up"]',
        title: tr('Liquidar reembolso', 'Settle reimbursement'),
        body: tr('En seguimiento veras el reembolso pendiente. Liquida desde banco o efectivo; en tutorial la API responde sin guardar nada real.', 'In follow-up you will see the pending reimbursement. Settle it from bank or cash; in tutorial the API responds without saving anything real.'),
    },
    {
        id: 'receipt-signature',
        target: '[data-tour="cashbox-reimbursement-receipt"]',
        title: tr('Firma del recibo', 'Receipt signature'),
        body: tr('Despues de liquidar, abre el recibo en otra pestana. La persona reembolsada firma ahi y Caja recibe la confirmacion simulada.', 'After settlement, open the receipt in another tab. The reimbursed person signs there and Cashbox receives the simulated confirmation.'),
    },
    {
        id: 'movements',
        target: '[data-tour="cashbox-movements"]',
        title: tr('Movimientos', 'Movements'),
        body: tr('Esta lista es la lectura de control: ingresos, gastos, transferencias y reembolsos con fecha, cuenta, estado, recibos y comprobantes.', 'This list is the control readout: income, expenses, transfers, and reimbursements with date, account, status, receipts, and proofs.'),
    },
    {
        id: 'movement-filters',
        target: '[data-tour="cashbox-movement-filters"]',
        title: tr('Filtros de movimientos', 'Movement filters'),
        body: tr('Filtra por tipo, cambia el orden o actualiza la lectura cuando necesites confirmar lo que acaba de registrarse.', 'Filter by type, change the sort order, or refresh the readout when you need to confirm what was just recorded.'),
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
const expenseActionError = (expenseId) => expenseActionErrors.value[expenseId] || null
const isExpenseActionBusy = (expenseId) => Boolean(expenseActionBusy.value[expenseId])
const expenseUploadProgressValue = (expenseId) => Number(expenseUploadProgress.value[expenseId] || 0)
const defaultOperatingPayTo = () => operatingAccountOptions.value[0]?.value || 'club_budget'
const accountLocationBalance = (payTo, fundsLocation = 'cash') => {
    const row = summaryAccounts.value.find((account) => account.account === payTo)

    return Math.max(Number(row?.[`${fundsLocation || 'cash'}_balance`] || 0), 0)
}
const accountTotalBalance = (payTo) => {
    const row = summaryAccounts.value.find((account) => account.account === payTo)

    return Math.max(Number(row?.total_available ?? (Number(row?.cash_balance || 0) + Number(row?.bank_balance || 0))), 0)
}
const reimbursementSourceBalance = (expense) => {
    const form = reimbursementForms.value[expense.id] || {}

    return accountLocationBalance(form.pay_to, form.funds_location || 'cash')
}
const reimbursementSourceTotalBalance = (expense) => {
    const form = reimbursementForms.value[expense.id] || {}

    return accountTotalBalance(form.pay_to)
}
const reimbursementSettlementTransferAmount = (expense) => {
    const selectedBalance = reimbursementSourceBalance(expense)
    const totalBalance = reimbursementSourceTotalBalance(expense)
    const amount = Number(expense.amount || 0)

    return Math.max(Math.min(roundCurrency(amount - selectedBalance), roundCurrency(totalBalance - selectedBalance)), 0)
}
const canSettleReimbursement = (expense) =>
    tutorialActive.value || reimbursementSourceTotalBalance(expense) + 0.0001 >= Number(expense.amount || 0)

const mergeAccounts = (paymentAccounts, expenseAccounts) => {
    const rows = new Map()
    ;[...(paymentAccounts || []), ...(expenseAccounts || [])].forEach((account) => {
        if (!account?.pay_to) return
        rows.set(`${account.club_id || selectedClubId.value}:${account.pay_to}`, account)
    })
    accounts.value = Array.from(rows.values())
}

const setExpenseActionBusy = (expenseId, value) => {
    expenseActionBusy.value = {
        ...expenseActionBusy.value,
        [expenseId]: value,
    }
}

const setExpenseActionError = (expenseId, message = '') => {
    expenseActionErrors.value = {
        ...expenseActionErrors.value,
        [expenseId]: message,
    }
}

const setExpenseUploadProgress = (expenseId, value = 0) => {
    expenseUploadProgress.value = {
        ...expenseUploadProgress.value,
        [expenseId]: value,
    }
}

const fileExtension = (file) => String(file?.name || '').split('.').pop()?.toLowerCase() || ''
const allowedExtensionText = (extensions) => extensions.map((extension) => `.${extension}`).join(', ')
const invalidFileMessage = (extensions) => tr(
    `Tipo de archivo no permitido. Usa ${allowedExtensionText(extensions)}.`,
    `File type not allowed. Use ${allowedExtensionText(extensions)}.`
)
const validateReceiptFile = (file, extensions) => {
    if (!file) return true
    return extensions.includes(fileExtension(file))
}
const uploadProgressHandler = (expenseId) => (event) => {
    if (!event.total) {
        setExpenseUploadProgress(expenseId, 1)
        return
    }

    setExpenseUploadProgress(expenseId, Math.min(99, Math.round((event.loaded / event.total) * 100)))
}

const tutorialDelay = () => new Promise((resolve) => window.setTimeout(resolve, 250))
const tutorialStepIndexById = (id) => tutorialSteps.value.findIndex((step) => step.id === id)
const goToTutorialStep = (id) => {
    const index = tutorialStepIndexById(id)
    if (index >= 0) {
        tutorialStepIndex.value = index
    }
}
const tutorialAccountLabel = (payTo) => payTo === TUTORIAL_REIMBURSEMENT_ACCOUNT
    ? tr('Reembolsos pendientes', 'Pending reimbursements')
    : tr('Presupuesto del club', 'Club budget')
const tutorialPaymentLocation = (paymentType) => paymentType === 'cash' ? 'cash' : 'bank'
const tutorialEnsureBalance = (payTo) => {
    if (!tutorialBalances.value[payTo]) {
        tutorialBalances.value = {
            ...tutorialBalances.value,
            [payTo]: { cash_balance: 0, bank_balance: 0 },
        }
    }

    return tutorialBalances.value[payTo]
}
const tutorialSetBalance = (payTo, location, value) => {
    const row = tutorialEnsureBalance(payTo)
    tutorialBalances.value = {
        ...tutorialBalances.value,
        [payTo]: {
            ...row,
            [`${location}_balance`]: roundCurrency(value),
        },
    }
}
const tutorialAddBalance = (payTo, location, amount) => {
    const row = tutorialEnsureBalance(payTo)
    tutorialSetBalance(payTo, location, Number(row[`${location}_balance`] || 0) + Number(amount || 0))
}
const tutorialDeductBalance = (payTo, preferredLocation, amount) => {
    const row = tutorialEnsureBalance(payTo)
    const first = preferredLocation === 'bank' ? 'bank' : 'cash'
    const second = first === 'cash' ? 'bank' : 'cash'
    let remaining = Number(amount || 0)
    const next = { ...row }

    ;[first, second].forEach((location) => {
        const key = `${location}_balance`
        const available = Math.max(Number(next[key] || 0), 0)
        const used = Math.min(available, remaining)
        next[key] = roundCurrency(Number(next[key] || 0) - used)
        remaining = roundCurrency(remaining - used)
    })

    tutorialBalances.value = {
        ...tutorialBalances.value,
        [payTo]: next,
    }
}
const tutorialSummary = () => {
    const accountsSummary = Object.entries(tutorialBalances.value).map(([account, row]) => {
        const cash = Number(row.cash_balance || 0)
        const bank = Number(row.bank_balance || 0)

        return {
            account,
            label: tutorialAccountLabel(account),
            cash_balance: roundCurrency(cash),
            bank_balance: roundCurrency(bank),
            total_available: roundCurrency(cash + bank),
        }
    })
    const cash = accountsSummary.reduce((sum, account) => sum + Number(account.cash_balance || 0), 0)
    const bank = accountsSummary.reduce((sum, account) => sum + Number(account.bank_balance || 0), 0)

    return {
        cash_balance: roundCurrency(cash),
        bank_balance: roundCurrency(bank),
        total_available: roundCurrency(cash + bank),
        accounts: accountsSummary,
    }
}
const tutorialApplyEngineReport = () => {
    engineReport.value = {
        summary: tutorialSummary(),
        movements: tutorialMovements.value.slice(),
    }
}
const tutorialNext = () => {
    tutorialNextId.value += 1
    return tutorialNextId.value
}
const tutorialOccurredAt = () => `${today()} ${String(9 + (tutorialMovements.value.length % 8)).padStart(2, '0')}:00`
const tutorialAddMovement = (movement) => {
    tutorialMovements.value = [
        {
            status: 'posted',
            date: today(),
            occurred_at: tutorialOccurredAt(),
            created_at: tutorialOccurredAt(),
            account: TUTORIAL_ACCOUNT,
            account_label: tutorialAccountLabel(TUTORIAL_ACCOUNT),
            location: 'cash',
            ...movement,
        },
        ...tutorialMovements.value,
    ]
    tutorialApplyEngineReport()
}
const tutorialQrDataUri = (label) => {
    const svg = `<svg xmlns="http://www.w3.org/2000/svg" width="120" height="120" viewBox="0 0 120 120"><rect width="120" height="120" fill="#fff"/><rect x="10" y="10" width="28" height="28" fill="#111827"/><rect x="82" y="10" width="28" height="28" fill="#111827"/><rect x="10" y="82" width="28" height="28" fill="#111827"/><rect x="48" y="48" width="10" height="10" fill="#111827"/><rect x="64" y="48" width="10" height="10" fill="#111827"/><rect x="48" y="64" width="10" height="10" fill="#111827"/><rect x="74" y="72" width="10" height="10" fill="#111827"/><rect x="88" y="88" width="12" height="12" fill="#111827"/><text x="60" y="115" text-anchor="middle" font-size="8" fill="#111827">${label}</text></svg>`

    return `data:image/svg+xml;charset=utf-8,${encodeURIComponent(svg)}`
}
const tutorialPdfDataUri = (expense, signerName = '') => {
    const text = [
        'Recibo de reembolso tutorial',
        `Reembolso #${expense.id}`,
        `Recibe: ${signerName || expense.reimbursed_to || ''}`,
        `Monto: ${formatMoney(expense.amount)}`,
        'Documento simulado. No afecta la base de datos.',
    ].join('\n')

    return `data:text/plain;charset=utf-8,${encodeURIComponent(text)}`
}
const tutorialBuildExpenseProofUrl = (label) => `data:text/plain;charset=utf-8,${encodeURIComponent(`${label}\nComprobante simulado de tutorial.`)}`
const tutorialPrefillSavedIncome = () => {
    incomeForm.value = {
        ...incomeForm.value,
        mode: 'existing',
        concept_key: `concept:${TUTORIAL_CONCEPT_ID}`,
        selected_event_concept_ids: [],
        payer_key: `member:${TUTORIAL_MEMBER_ID}`,
        payer_name: '',
        concept_text: '',
        pay_to: TUTORIAL_ACCOUNT,
        amount_paid: '25.00',
        payment_date: today(),
        payment_type: 'cash',
        zelle_phone: '',
        check_image: null,
        notes: tr('Practica: ingreso con concepto guardado', 'Practice: saved concept income'),
    }
}
const tutorialPrefillManualIncome = () => {
    incomeForm.value = {
        ...incomeForm.value,
        mode: 'manual',
        concept_key: '',
        selected_event_concept_ids: [],
        payer_key: CUSTOM_PAYER_OPTION,
        payer_name: 'Donante Tutorial',
        concept_text: 'Donacion visitante tutorial',
        pay_to: TUTORIAL_ACCOUNT,
        amount_paid: '60.00',
        payment_date: today(),
        payment_type: 'transfer',
        zelle_phone: '',
        check_image: null,
        notes: tr('Practica: ingreso manual por transferencia', 'Practice: manual transfer income'),
    }
}
const tutorialPrefillNormalExpense = () => {
    expenseForm.value = {
        ...expenseForm.value,
        pay_to: TUTORIAL_ACCOUNT,
        funds_location: 'cash',
        amount: '20.00',
        expense_date: today(),
        description: 'Materiales de clase tutorial',
        reimbursed_to: '',
        reimbursement_target_mode: 'new',
        reimbursement_payee_id: '',
        reimbursement_payee_name: '',
        reimbursement_payee_phone: '',
        reimbursement_payee_email: '',
        receipt_image: null,
    }
    showReimbursementOverflowModal.value = false
}
const tutorialPrefillReimbursementExpense = () => {
    const total = accountTotalBalance(TUTORIAL_ACCOUNT)
    const amount = Math.max(total + 25, 125)
    expenseForm.value = {
        ...expenseForm.value,
        pay_to: TUTORIAL_ACCOUNT,
        funds_location: 'cash',
        amount: amount.toFixed(2),
        expense_date: today(),
        description: 'Compra cubierta por lider tutorial',
        reimbursed_to: 'Patrocinador Tutorial',
        reimbursement_target_mode: 'new',
        reimbursement_payee_id: '',
        reimbursement_payee_name: 'Patrocinador Tutorial',
        reimbursement_payee_phone: '555-0101',
        reimbursement_payee_email: 'tutorial@example.com',
        receipt_image: null,
    }
    showReimbursementOverflowModal.value = false
}
const tutorialApplyStepPreset = () => {
    if (!tutorialActive.value || !tutorialStep.value) return

    if (tutorialStep.value.id === 'saved-income') tutorialPrefillSavedIncome()
    if (tutorialStep.value.id === 'manual-income') tutorialPrefillManualIncome()
    if (tutorialStep.value.id === 'normal-expense') tutorialPrefillNormalExpense()
    if (tutorialStep.value.id === 'reimbursement-expense') tutorialPrefillReimbursementExpense()
}
const tutorialResetSandbox = () => {
    tutorialBalances.value = {
        [TUTORIAL_ACCOUNT]: { cash_balance: 40, bank_balance: 80 },
        [TUTORIAL_REIMBURSEMENT_ACCOUNT]: { cash_balance: 0, bank_balance: 0 },
    }
    tutorialMovements.value = []
    tutorialNextId.value = 9000
    selectedClubId.value = TUTORIAL_CLUB_ID
    currentClub.value = { id: TUTORIAL_CLUB_ID, club_name: tr('Club Tutorial', 'Tutorial Club') }
    clubs.value = [currentClub.value]
    classes.value = []
    members.value = [{
        id: TUTORIAL_MEMBER_ID,
        club_id: TUTORIAL_CLUB_ID,
        applicant_name: 'Ana Gomez',
        name: 'Ana Gomez',
    }]
    staff.value = []
    concepts.value = [{
        id: TUTORIAL_CONCEPT_ID,
        club_id: TUTORIAL_CLUB_ID,
        concept: 'Cuota mensual tutorial',
        amount: 25,
        pay_to: TUTORIAL_ACCOUNT,
        type: 'mandatory',
    }]
    accounts.value = [{
        club_id: TUTORIAL_CLUB_ID,
        pay_to: TUTORIAL_ACCOUNT,
        label: tutorialAccountLabel(TUTORIAL_ACCOUNT),
    }]
    expenses.value = []
    reimbursementPayees.value = []
    expenseActionBusy.value = {}
    expenseActionErrors.value = {}
    expenseReceiptFiles.value = {}
    reimbursementPaymentProofFiles.value = {}
    balanceAccountFilter.value = 'all'
    movementDomain.value = 'all'
    movementSort.value = 'date'
    movementPage.value = 1
    tutorialApplyEngineReport()
    resetExpenseForm()
    tutorialPrefillSavedIncome()
    ensureReimbursementForms()
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

const startCajaTutorial = () => {
    if (!tutorialActive.value) {
        tutorialReturnClubId.value = selectedClubId.value
    }
    tutorialResetSandbox()
    tutorialStepIndex.value = 0
    tutorialActive.value = true
    updateTutorialTarget(true)
}

const closeCajaTutorial = () => {
    const returnClubId = tutorialReturnClubId.value
    tutorialActive.value = false
    tutorialTargetRect.value = null
    tutorialReceiptWindow.value = null
    tutorialReturnClubId.value = null
    selectedClubId.value = returnClubId
    loadCaja(returnClubId, true)
}

const previousTutorialStep = () => {
    tutorialStepIndex.value = Math.max(tutorialStepIndex.value - 1, 0)
}

const nextTutorialStep = () => {
    if (tutorialStepIndex.value >= tutorialStepCount.value - 1) {
        closeCajaTutorial()
        return
    }

    tutorialStepIndex.value += 1
}

const handleTutorialViewportChange = () => {
    if (tutorialActive.value) {
        updateTutorialTarget(false)
    }
}

const handleTutorialKeydown = (event) => {
    if (!tutorialActive.value) return
    if (event.key === 'Escape') closeCajaTutorial()
    if (event.key === 'ArrowRight') nextTutorialStep()
    if (event.key === 'ArrowLeft') previousTutorialStep()
}

const ensureReimbursementForms = () => {
    const defaultPayTo = defaultOperatingPayTo()
    const next = { ...reimbursementForms.value }

    reimbursementExpenseRows.value.forEach((expense) => {
        if (!next[expense.id]) {
            next[expense.id] = {
                pay_to: defaultPayTo,
                funds_location: 'cash',
                reimbursement_date: today(),
            }
        }
    })

    reimbursementForms.value = next
}

const setExpenseReceiptFile = (expenseId, event) => {
    const file = event.target.files?.[0] || null
    setExpenseActionError(expenseId)

    if (file && !validateReceiptFile(file, IMAGE_RECEIPT_EXTENSIONS)) {
        event.target.value = ''
        expenseReceiptFiles.value = {
            ...expenseReceiptFiles.value,
            [expenseId]: null,
        }
        setExpenseActionError(expenseId, invalidFileMessage(IMAGE_RECEIPT_EXTENSIONS))
        return
    }

    expenseReceiptFiles.value = {
        ...expenseReceiptFiles.value,
        [expenseId]: file,
    }
}

const setReimbursementPaymentProofFile = (expenseId, event) => {
    const file = event.target.files?.[0] || null
    setExpenseActionError(expenseId)

    if (file && !validateReceiptFile(file, DOCUMENT_RECEIPT_EXTENSIONS)) {
        event.target.value = ''
        reimbursementPaymentProofFiles.value = {
            ...reimbursementPaymentProofFiles.value,
            [expenseId]: null,
        }
        setExpenseActionError(expenseId, invalidFileMessage(DOCUMENT_RECEIPT_EXTENSIONS))
        return
    }

    reimbursementPaymentProofFiles.value = {
        ...reimbursementPaymentProofFiles.value,
        [expenseId]: file,
    }
}

const loadCaja = async (clubId = null, quiet = false) => {
    if (tutorialActive.value) {
        tutorialApplyEngineReport()
        loading.value = false
        refreshing.value = false
        return
    }

    if (quiet) refreshing.value = true
    else loading.value = true
    loadError.value = ''

    try {
        const payload = await fetchFinanceEngineCashbox(clubId)
        if (tutorialActive.value) return

        const data = payload?.data || {}

        if (!selectedClubId.value) {
            selectedClubId.value = data.club?.id || data.clubs?.[0]?.id || null
        }

        currentClub.value = data.club || currentClub.value
        clubs.value = Array.isArray(data.clubs) ? data.clubs : (data.club ? [data.club] : [])
        classes.value = Array.isArray(data.classes) ? data.classes : []
        members.value = Array.isArray(data.members) ? data.members : []
        staff.value = Array.isArray(data.staff) ? data.staff : []
        concepts.value = Array.isArray(data.concepts) ? data.concepts : []
        expenses.value = Array.isArray(data.expenses) ? data.expenses : []
        reimbursementPayees.value = Array.isArray(data.reimbursement_payees) ? data.reimbursement_payees : []
        mergeAccounts(data.accounts || [], [])
        engineReport.value = data.engine_report || null
        ensureReimbursementForms()

        if (!incomeForm.value.pay_to || !operatingAccountOptions.value.some((account) => account.value === incomeForm.value.pay_to)) {
            incomeForm.value.pay_to = defaultOperatingPayTo()
        }
        if (!expenseForm.value.pay_to || !operatingAccountOptions.value.some((account) => account.value === expenseForm.value.pay_to)) {
            expenseForm.value.pay_to = defaultOperatingPayTo()
        }
    } catch (error) {
        console.error(error)
        loadError.value = error?.response?.data?.message || tr('No se pudo cargar caja.', 'Could not load cashbox.')
    } finally {
        loading.value = false
        refreshing.value = false
    }
}

const refreshCaja = () => {
    if (tutorialActive.value) {
        tutorialApplyEngineReport()
        showToast(tr('Lectura tutorial actualizada.', 'Tutorial readout refreshed.'), 'success')
        return Promise.resolve()
    }

    return loadCaja(selectedClubId.value, true)
}
const onClubChange = () => {
    if (tutorialActive.value) return

    incomeForm.value.concept_key = ''
    incomeForm.value.payer_key = ''
    incomeForm.value.payer_name = ''
    movementPage.value = 1
    loadCaja(selectedClubId.value)
}

const onConceptChange = () => {
    if (incomeForm.value.concept_key === CREATE_CONCEPT_OPTION) {
        incomeForm.value.concept_key = ''
        openConceptModal()
        return
    }

    const option = selectedConceptOption.value
    incomeErrors.value = {}

    if (!option) return

    incomeForm.value.pay_to = isOperatingAccount(option.pay_to) ? option.pay_to : defaultOperatingPayTo()
    if (option.type === 'event_bundle') {
        const required = selectedEventRequiredIds.value
        incomeForm.value.selected_event_concept_ids = required.length
            ? required
            : option.group.concepts.map((concept) => Number(concept.id))
    }

    if (selectedIncomeExpected.value !== null) {
        incomeForm.value.amount_paid = Number(selectedIncomeExpected.value || 0).toFixed(2)
    }
}

const toggleEventComponent = (concept, checked) => {
    const id = Number(concept.id)
    const required = Boolean(eventComponent(concept)?.is_required ?? true)
    if (required) return

    const selected = new Set(incomeForm.value.selected_event_concept_ids.map((item) => Number(item)))
    if (checked) selected.add(id)
    else selected.delete(id)
    selectedEventRequiredIds.value.forEach((requiredId) => selected.add(Number(requiredId)))
    incomeForm.value.selected_event_concept_ids = Array.from(selected)
    incomeForm.value.amount_paid = Number(selectedIncomeExpected.value || 0).toFixed(2)
}

const onIncomeCheckImage = (event) => {
    const file = event.target.files?.[0] || null
    if (file && !validateReceiptFile(file, IMAGE_RECEIPT_EXTENSIONS)) {
        event.target.value = ''
        incomeForm.value.check_image = null
        incomeErrors.value = {
            ...incomeErrors.value,
            check_image: invalidFileMessage(IMAGE_RECEIPT_EXTENSIONS),
        }
        return
    }

    incomeErrors.value = { ...incomeErrors.value, check_image: '' }
    incomeForm.value.check_image = file
}

const onExpenseReceipt = (event) => {
    const file = event.target.files?.[0] || null
    if (file && !validateReceiptFile(file, IMAGE_RECEIPT_EXTENSIONS)) {
        event.target.value = ''
        expenseForm.value.receipt_image = null
        expenseErrors.value = {
            ...expenseErrors.value,
            receipt_image: invalidFileMessage(IMAGE_RECEIPT_EXTENSIONS),
        }
        return
    }

    expenseErrors.value = { ...expenseErrors.value, receipt_image: '' }
    expenseForm.value.receipt_image = file
}

const resetIncomeForm = () => {
    incomeForm.value = {
        mode: incomeForm.value.mode,
        concept_key: '',
        selected_event_concept_ids: [],
        payer_key: '',
        payer_name: '',
        concept_text: '',
        pay_to: defaultOperatingPayTo(),
        amount_paid: '',
        payment_date: today(),
        payment_type: 'cash',
        zelle_phone: '',
        check_image: null,
        notes: '',
    }
    if (incomeCheckInput.value) incomeCheckInput.value.value = ''
}

const resetExpenseForm = () => {
    expenseForm.value = {
        pay_to: defaultOperatingPayTo(),
        funds_location: 'cash',
        amount: '',
        expense_date: today(),
        description: '',
        notes: '',
        reimbursed_to: '',
        reimbursement_target_mode: reimbursementPayeeOptions.value.length ? expenseForm.value.reimbursement_target_mode : 'new',
        reimbursement_payee_id: '',
        reimbursement_payee_name: '',
        reimbursement_payee_phone: '',
        reimbursement_payee_email: '',
        receipt_image: null,
    }
    if (expenseReceiptInput.value) expenseReceiptInput.value.value = ''
}

const openConceptModal = () => {
    conceptErrors.value = {}
    conceptForm.value = {
        concept: '',
        amount: '',
        type: 'mandatory',
        reusable: false,
        pay_to: incomeForm.value.pay_to || defaultOperatingPayTo(),
        payment_expected_by: '',
        scope_type: 'club_wide',
        class_id: '',
        member_id: '',
        staff_id: '',
    }
    showConceptModal.value = true
}

const closeConceptModal = () => {
    if (savingConcept.value) return
    showConceptModal.value = false
    conceptErrors.value = {}
}

const openReimbursementOverflowModal = () => {
    if (!expenseHasOverflow.value) return
    showReimbursementOverflowModal.value = true
}

const closeReimbursementOverflowModal = () => {
    showReimbursementOverflowModal.value = false
}

const onConceptReusableChange = () => {
    if (conceptForm.value.reusable) {
        conceptForm.value.payment_expected_by = ''
        conceptErrors.value = {
            ...conceptErrors.value,
            payment_expected_by: null,
        }
    }
}

const onConceptScopeChange = () => {
    conceptForm.value.class_id = ''
    conceptForm.value.member_id = ''
    conceptForm.value.staff_id = ''
    conceptErrors.value = {}
}

const conceptScopeErrorKey = computed(() => {
    if (conceptForm.value.scope_type === 'class') return 'scopes.0.class_id'
    if (conceptForm.value.scope_type === 'member') return 'scopes.0.member_id'
    if (conceptForm.value.scope_type === 'staff') return 'scopes.0.staff_id'
    return 'scopes.0.club_id'
})

const buildConceptScope = () => {
    const scope = { scope_type: conceptForm.value.scope_type }

    if (['club_wide', 'staff_wide'].includes(conceptForm.value.scope_type)) {
        scope.club_id = selectedClubId.value
    }
    if (conceptForm.value.scope_type === 'class') {
        scope.class_id = conceptForm.value.class_id
    }
    if (conceptForm.value.scope_type === 'member') {
        scope.member_id = conceptForm.value.member_id
    }
    if (conceptForm.value.scope_type === 'staff') {
        scope.staff_id = conceptForm.value.staff_id
    }

    return scope
}

const validateConceptScope = () => {
    const scope = buildConceptScope()
    const requiredKey = {
        club_wide: 'club_id',
        staff_wide: 'club_id',
        class: 'class_id',
        member: 'member_id',
        staff: 'staff_id',
    }[scope.scope_type]

    if (!scope[requiredKey]) {
        conceptErrors.value = {
            ...conceptErrors.value,
            [conceptScopeErrorKey.value]: tr('Selecciona el alcance del concepto.', 'Select the concept scope.'),
        }
        return false
    }

    return true
}

const tutorialCreateIncome = async (payload) => {
    await tutorialDelay()

    const id = tutorialNext()
    const amount = Number(payload.amount_paid || 0)
    const location = tutorialPaymentLocation(payload.payment_type)
    const concept = payload.concept_text
        || selectedConceptOption.value?.label
        || 'Ingreso tutorial'
    const payer = payload.payer_name || 'Ana Gomez'

    tutorialAddBalance(payload.pay_to || TUTORIAL_ACCOUNT, location, amount)
    tutorialAddMovement({
        movement_id: `payment:${id}`,
        domain: 'income',
        kind: 'payment',
        direction: 'in',
        account: payload.pay_to || TUTORIAL_ACCOUNT,
        account_label: tutorialAccountLabel(payload.pay_to || TUTORIAL_ACCOUNT),
        location,
        concept,
        amount,
        payer,
        receipt: {
            number: `TUT-PAY-${id}`,
            url: tutorialBuildExpenseProofUrl(`Recibo tutorial ${id}`),
        },
    })

    return { data: { id, tutorial: true } }
}

const tutorialCreateExpense = async (payload) => {
    await tutorialDelay()

    const id = tutorialNext()
    const payTo = payload.pay_to || TUTORIAL_ACCOUNT
    const amount = Number(payload.amount || 0)
    const total = accountTotalBalance(payTo)
    const overflow = Math.max(roundCurrency(amount - total), 0)
    const coveredAmount = roundCurrency(amount - overflow)
    const receiptUrl = payload.receipt_image ? tutorialBuildExpenseProofUrl(`Comprobante gasto ${id}`) : null
    const expense = {
        id,
        club_id: TUTORIAL_CLUB_ID,
        pay_to: payTo,
        funds_location: payload.funds_location || 'cash',
        amount,
        expense_date: payload.expense_date || today(),
        description: payload.description || 'Gasto tutorial',
        notes: payload.notes || '',
        status: receiptUrl ? 'completed' : 'working',
        receipt_url: receiptUrl,
        receipt_path: receiptUrl ? `tutorial/expense-${id}.txt` : null,
        generated_reimbursement_expense: null,
    }

    if (coveredAmount > 0) {
        tutorialDeductBalance(payTo, payload.funds_location || 'cash', coveredAmount)
    }

    tutorialAddMovement({
        movement_id: `expense:${id}`,
        domain: 'expense',
        kind: overflow > 0 ? 'expense_with_reimbursement' : 'expense',
        direction: 'out',
        account: payTo,
        account_label: tutorialAccountLabel(payTo),
        location: payload.funds_location || 'cash',
        concept: expense.description,
        notes: expense.notes,
        amount,
        proof: receiptUrl ? {
            name: 'Comprobante tutorial',
            url: receiptUrl,
        } : null,
    })

    if (overflow > 0) {
        const payeeId = tutorialNext()
        const reimbursementId = tutorialNext()
        const payee = {
            id: payeeId,
            name: payload.reimbursement_payee_name || payload.reimbursed_to || 'Patrocinador Tutorial',
            phone: payload.reimbursement_payee_phone || '555-0101',
            email: payload.reimbursement_payee_email || 'tutorial@example.com',
        }
        const reimbursement = {
            id: reimbursementId,
            club_id: TUTORIAL_CLUB_ID,
            pay_to: TUTORIAL_REIMBURSEMENT_ACCOUNT,
            funds_location: 'cash',
            amount: overflow,
            expense_date: payload.expense_date || today(),
            description: `Reembolso a ${payee.name}`,
            reimbursed_to: payee.name,
            reimbursement_payee_id: payee.id,
            reimbursement_payee: payee,
            reimbursement_origin_expense_id: expense.id,
            reimbursement_origin_expense: {
                id: expense.id,
                description: expense.description,
                expense_date: expense.expense_date,
                amount: expense.amount,
            },
            status: 'pending_reimbursement',
            receipt_url: null,
            reimbursement_confirmation_url: null,
            reimbursement_confirmation_qr_url: null,
            reimbursement_receipt_signed_at: null,
            reimbursement_receipt_signer_name: null,
            reimbursement_receipt_url: null,
            reimbursement_signature_url: null,
            reimbursement_payment_proof_url: null,
        }

        expense.generated_reimbursement_expense = {
            id: reimbursement.id,
            amount: reimbursement.amount,
            status: reimbursement.status,
        }
        reimbursementPayees.value = [payee, ...reimbursementPayees.value]
        expenses.value = [reimbursement, expense, ...expenses.value]
        tutorialAddBalance(TUTORIAL_REIMBURSEMENT_ACCOUNT, 'cash', -overflow)
        tutorialAddMovement({
            movement_id: `expense:${reimbursement.id}`,
            domain: 'expense',
            kind: 'reimbursement',
            direction: 'out',
            account: TUTORIAL_REIMBURSEMENT_ACCOUNT,
            account_label: tutorialAccountLabel(TUTORIAL_REIMBURSEMENT_ACCOUNT),
            location: 'cash',
            concept: reimbursement.description,
            amount: overflow,
            correction_type: 'reimbursement',
            can_reverse: true,
            reimbursement_group: {
                key: `tutorial-reimbursement:${reimbursement.id}`,
                label: reimbursement.description,
                origin_expense_id: expense.id,
                reimbursement_expense_id: reimbursement.id,
                reimbursed_to: payee.name,
                origin_amount: expense.amount,
                reimbursement_amount: reimbursement.amount,
            },
        })
        ensureReimbursementForms()

        return { data: { id: expense.id, reimbursement_expense_id: reimbursement.id, tutorial: true } }
    }

    expenses.value = [expense, ...expenses.value]
    tutorialApplyEngineReport()

    return { data: { id: expense.id, tutorial: true } }
}

const tutorialSettleReimbursement = async (expense, payload) => {
    await tutorialDelay()

    const settlementId = tutorialNext()
    const paymentProofUrl = payload.payment_proof_file
        ? tutorialBuildExpenseProofUrl(`Comprobante pago reembolso ${expense.id}`)
        : expense.reimbursement_payment_proof_url
    const confirmationUrl = `tutorial-reimbursement:${expense.id}`
    const signedAt = null

    expenses.value = expenses.value.map((row) => {
        if (Number(row.id) !== Number(expense.id)) return row

        return {
            ...row,
            status: 'completed',
            settlement_expense: {
                id: settlementId,
                pay_to: payload.pay_to || TUTORIAL_ACCOUNT,
                funds_location: payload.funds_location || 'cash',
                amount: row.amount,
            },
            reimbursement_confirmation_url: confirmationUrl,
            reimbursement_confirmation_qr_url: tutorialQrDataUri(`REIMB-${row.id}`),
            reimbursement_receipt_signed_at: signedAt,
            reimbursement_receipt_signer_name: null,
            reimbursement_receipt_url: null,
            reimbursement_signature_url: null,
            reimbursement_payment_proof_url: paymentProofUrl,
        }
    })
    tutorialAddBalance(TUTORIAL_REIMBURSEMENT_ACCOUNT, 'cash', Number(expense.amount || 0))
    tutorialAddMovement({
        movement_id: `expense:${settlementId}`,
        domain: 'expense',
        kind: 'reimbursement_settlement',
        direction: 'out',
        account: payload.pay_to || TUTORIAL_ACCOUNT,
        account_label: tutorialAccountLabel(payload.pay_to || TUTORIAL_ACCOUNT),
        location: payload.funds_location || 'cash',
        concept: `Liquidacion ${expense.description || 'reembolso tutorial'}`,
        amount: Number(expense.amount || 0),
        proof: paymentProofUrl ? {
            name: 'Comprobante de pago tutorial',
            url: paymentProofUrl,
        } : null,
    })

    return {
        data: {
            id: expense.id,
            reimbursement_confirmation_url: confirmationUrl,
            reimbursement_confirmation_qr_url: tutorialQrDataUri(`REIMB-${expense.id}`),
            tutorial: true,
        },
    }
}

const escapeTutorialHtml = (value) => String(value ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;')
const tutorialReceiptHtml = (expense) => {
    const receipt = {
        id: expense.id,
        club: currentClub.value?.club_name || 'Club Tutorial',
        amount: formatMoney(expense.amount),
        reimbursed_to: expense.reimbursed_to || 'Persona reembolsada',
        settlement: expense.settlement_expense
            ? `${accountLabel(expense.settlement_expense.pay_to)} · ${locationLabel(expense.settlement_expense.funds_location)}`
            : 'Pendiente',
        origin: expense.reimbursement_origin_expense?.description || 'Gasto relacionado',
        download: tutorialPdfDataUri(expense, expense.reimbursed_to),
    }

    return `<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Recibo tutorial #${escapeTutorialHtml(receipt.id)}</title>
    <style>
        body{margin:0;background:#f3f4f6;color:#111827;font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;padding:24px}
        main{max-width:760px;margin:0 auto;background:#fff;border:1px solid #e5e7eb;border-radius:12px;box-shadow:0 10px 28px rgba(15,23,42,.08);overflow:hidden}
        header{border-bottom:1px solid #e5e7eb;padding:22px}
        section{padding:22px;display:grid;gap:18px}
        h1{font-size:22px;margin:4px 0 0}
        .eyebrow{font-size:12px;text-transform:uppercase;letter-spacing:.08em;color:#6b7280;font-weight:700}
        .amount{font-size:30px;font-weight:800;margin:0}
        .grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}
        .box{border:1px solid #e5e7eb;background:#f9fafb;border-radius:10px;padding:14px}
        label{font-size:14px;font-weight:700;color:#374151}
        input[type=text]{width:100%;box-sizing:border-box;border:1px solid #d1d5db;border-radius:10px;padding:10px;margin-top:6px;font-size:15px}
        canvas{width:100%;height:220px;border:1px solid #d1d5db;border-radius:10px;background:#fff;touch-action:none}
        button,a.button{min-height:44px;border:0;border-radius:10px;background:#b91c1c;color:#fff;font-weight:800;padding:10px 16px;text-decoration:none;display:inline-flex;align-items:center;justify-content:center;cursor:pointer}
        button.secondary{background:#fff;color:#374151;border:1px solid #d1d5db}
        .actions{display:flex;gap:10px;flex-wrap:wrap}
        .notice{border:1px solid #a7f3d0;background:#ecfdf5;color:#065f46;border-radius:10px;padding:12px;font-size:14px;font-weight:700;display:none}
        .error{border:1px solid #fecdd3;background:#fff1f2;color:#be123c;border-radius:10px;padding:12px;font-size:14px;font-weight:700;display:none}
        @media(max-width:640px){body{padding:12px}.grid{grid-template-columns:1fr}}
    </style>
</head>
<body>
<main>
    <header>
        <div class="eyebrow">Recibo de reembolso tutorial</div>
        <div class="grid">
            <div>
                <h1>${escapeTutorialHtml(receipt.club)}</h1>
                <p>Reembolso #${escapeTutorialHtml(receipt.id)}</p>
            </div>
            <p class="amount">${escapeTutorialHtml(receipt.amount)}</p>
        </div>
    </header>
    <section>
        <div class="grid">
            <div class="box">
                <div class="eyebrow">Recibe</div>
                <strong>${escapeTutorialHtml(receipt.reimbursed_to)}</strong>
            </div>
            <div class="box">
                <div class="eyebrow">Liquidacion</div>
                <strong>${escapeTutorialHtml(receipt.settlement)}</strong>
            </div>
        </div>
        <div class="box">
            <div class="eyebrow">Gasto relacionado</div>
            <p>${escapeTutorialHtml(receipt.origin)}</p>
        </div>
        <form id="signature-form">
            <label>Nombre de quien recibe</label>
            <input id="signer-name" type="text" value="${escapeTutorialHtml(receipt.reimbursed_to)}">
            <div style="margin-top:14px">
                <label>Firma</label>
                <canvas id="signature"></canvas>
            </div>
            <label style="display:flex;gap:10px;align-items:flex-start;margin-top:14px;font-weight:500">
                <input id="ack" type="checkbox" style="margin-top:3px">
                <span>Confirmo que recibi el reembolso completo indicado en este recibo de practica.</span>
            </label>
            <p id="error" class="error"></p>
            <p id="notice" class="notice">Firma recibida por Caja en modo tutorial.</p>
            <div class="actions" style="margin-top:16px">
                <button type="submit">Confirmar recibo</button>
                <button type="button" class="secondary" id="clear">Limpiar firma</button>
                <a class="button" id="download" href="${receipt.download}" download="recibo-reembolso-tutorial-${escapeTutorialHtml(receipt.id)}.txt" style="display:none">Descargar recibo</a>
            </div>
        </form>
    </section>
</main>
<script>
(() => {
    const canvas = document.getElementById('signature');
    const context = canvas.getContext('2d');
    let drawing = false;
    let signed = false;
    const configure = () => {
        const rect = canvas.getBoundingClientRect();
        const ratio = window.devicePixelRatio || 1;
        canvas.width = Math.max(1, Math.floor(rect.width * ratio));
        canvas.height = Math.max(1, Math.floor(rect.height * ratio));
        context.fillStyle = '#fff';
        context.fillRect(0, 0, canvas.width, canvas.height);
        context.strokeStyle = '#111827';
        context.lineWidth = 2.5 * ratio;
        context.lineCap = 'round';
        context.lineJoin = 'round';
        signed = false;
    };
    const point = (event) => {
        const rect = canvas.getBoundingClientRect();
        return {
            x: (event.clientX - rect.left) * (canvas.width / rect.width),
            y: (event.clientY - rect.top) * (canvas.height / rect.height),
        };
    };
    canvas.addEventListener('pointerdown', (event) => {
        event.preventDefault();
        drawing = true;
        signed = true;
        const p = point(event);
        context.beginPath();
        context.moveTo(p.x, p.y);
    });
    canvas.addEventListener('pointermove', (event) => {
        if (!drawing) return;
        event.preventDefault();
        const p = point(event);
        context.lineTo(p.x, p.y);
        context.stroke();
    });
    ['pointerup','pointercancel','pointerleave'].forEach((name) => canvas.addEventListener(name, () => drawing = false));
    document.getElementById('clear').addEventListener('click', configure);
    document.getElementById('signature-form').addEventListener('submit', (event) => {
        event.preventDefault();
        const error = document.getElementById('error');
        const name = document.getElementById('signer-name').value.trim();
        error.style.display = 'none';
        if (!name) {
            error.textContent = 'Escribe el nombre de quien recibe.';
            error.style.display = 'block';
            return;
        }
        if (!signed) {
            error.textContent = 'Agrega una firma.';
            error.style.display = 'block';
            return;
        }
        if (!document.getElementById('ack').checked) {
            error.textContent = 'Confirma que recibiste el reembolso.';
            error.style.display = 'block';
            return;
        }
        window.opener?.postMessage({
            type: 'cashbox-tutorial-reimbursement-signed',
            expenseId: ${Number(expense.id)},
            signerName: name,
        }, '*');
        document.getElementById('notice').style.display = 'block';
        document.getElementById('download').style.display = 'inline-flex';
    });
    window.addEventListener('resize', configure);
    configure();
})();
<\/script>
</body>
</html>`
}
const tutorialSignReimbursementReceipt = (expenseId, signerName) => {
    const signedAt = formatDateTime(new Date().toISOString())

    expenses.value = expenses.value.map((row) => {
        if (Number(row.id) !== Number(expenseId)) return row

        return {
            ...row,
            reimbursement_receipt_signed_at: signedAt,
            reimbursement_receipt_signer_name: signerName,
            reimbursement_receipt_url: tutorialPdfDataUri(row, signerName),
            reimbursement_signature_url: null,
        }
    })
    showToast(tr('Firma tutorial recibida.', 'Tutorial signature received.'), 'success')
    goToTutorialStep('movements')
}
const openTutorialReimbursementReceipt = (expense) => {
    const popup = window.open('', '_blank')
    if (!popup) {
        showToast(tr('El navegador bloqueo la pestana del recibo tutorial.', 'The browser blocked the tutorial receipt tab.'), 'error')
        return
    }

    tutorialReceiptWindow.value = popup
    popup.document.open()
    popup.document.write(tutorialReceiptHtml(expense))
    popup.document.close()
}
const openReimbursementConfirmation = (expense, event) => {
    if (!tutorialActive.value || !String(expense.reimbursement_confirmation_url || '').startsWith('tutorial-reimbursement:')) {
        return
    }

    event.preventDefault()
    openTutorialReimbursementReceipt(expense)
}
const handleTutorialReceiptMessage = (event) => {
    const data = event.data || {}
    if (data.type !== 'cashbox-tutorial-reimbursement-signed') return
    if (tutorialReceiptWindow.value && event.source !== tutorialReceiptWindow.value) return

    tutorialSignReimbursementReceipt(data.expenseId, data.signerName)
}

const submitConcept = async () => {
    savingConcept.value = true
    conceptErrors.value = {}

    try {
        if (tutorialActive.value) {
            await tutorialDelay()
            showConceptModal.value = false
            showToast(tr('Concepto simulado. En tutorial no se guarda en base de datos.', 'Simulated concept. Tutorial mode does not save to the database.'), 'success')
            return
        }

        if (!selectedClubId.value) {
            conceptErrors.value = { club_id: tr('Selecciona un club.', 'Select a club.') }
            return
        }

        if (!validateConceptScope()) {
            return
        }

        const payload = {
            concept: conceptForm.value.concept,
            payment_expected_by: conceptForm.value.reusable ? null : (conceptForm.value.payment_expected_by || null),
            amount: conceptForm.value.amount,
            reusable: Boolean(conceptForm.value.reusable),
            type: conceptForm.value.type,
            pay_to: conceptForm.value.pay_to,
            status: 'active',
            club_id: selectedClubId.value,
            scopes: [buildConceptScope()],
        }

        const response = await createFinanceEngineConcept(payload)
        const conceptId = response?.data?.id
        await loadCaja(selectedClubId.value, true)

        if (conceptId) {
            incomeForm.value.mode = 'existing'
            incomeForm.value.concept_key = `concept:${conceptId}`
            incomeForm.value.pay_to = conceptForm.value.pay_to
            incomeForm.value.amount_paid = Number(conceptForm.value.amount || 0).toFixed(2)
            onConceptChange()
        }

        showConceptModal.value = false
        showToast(tr('Concepto creado.', 'Concept created.'), 'success')
    } catch (error) {
        conceptErrors.value = normalizeErrors(error)
        showToast(error?.response?.data?.message || tr('No se pudo crear el concepto.', 'Could not create the concept.'), 'error')
        console.error(error)
    } finally {
        savingConcept.value = false
    }
}

const submitIncome = async () => {
    savingIncome.value = true
    incomeErrors.value = {}

    try {
        const [payerType, payerId] = String(incomeForm.value.payer_key || '').split(':')
        const payload = {
            club_id: selectedClubId.value,
            amount_paid: incomeForm.value.amount_paid,
            payment_date: incomeForm.value.payment_date,
            payment_type: incomeForm.value.payment_type,
            zelle_phone: incomeForm.value.payment_type === 'zelle' ? incomeForm.value.zelle_phone : null,
            check_image: incomeForm.value.payment_type === 'check' ? incomeForm.value.check_image : null,
            notes: incomeForm.value.notes,
        }

        if (payerType === 'member') payload.member_id = payerId
        if (payerType === 'staff') payload.staff_id = payerId
        if (incomeForm.value.payer_key === CUSTOM_PAYER_OPTION) payload.payer_name = incomeForm.value.payer_name

        if (incomeForm.value.mode === 'manual') {
            payload.concept_text = incomeForm.value.concept_text
            payload.pay_to = incomeForm.value.pay_to
        } else if (selectedEventGroup.value) {
            payload.event_concept_ids = incomeForm.value.selected_event_concept_ids
        } else {
            const conceptId = selectedConceptOption.value?.concept?.id
            payload.payment_concept_id = conceptId
        }

        if (tutorialActive.value) {
            const wasManualIncome = incomeForm.value.mode === 'manual'
            await tutorialCreateIncome(payload)
            showToast(tr('Ingreso tutorial simulado.', 'Tutorial income simulated.'), 'success')
            resetIncomeForm()
            goToTutorialStep(wasManualIncome ? 'normal-expense' : 'manual-income')
            return
        }

        await createFinanceEngineIncome(payload)
        showToast(tr('Ingreso guardado.', 'Income saved.'), 'success')
        resetIncomeForm()
        await refreshCaja()
    } catch (error) {
        incomeErrors.value = normalizeErrors(error)
        showToast(error?.response?.data?.message || tr('No se pudo guardar el ingreso.', 'Could not save income.'), 'error')
        console.error(error)
    } finally {
        savingIncome.value = false
    }
}

const submitExpense = async () => {
    savingExpense.value = true
    expenseErrors.value = {}

    try {
        const payload = {
            club_id: selectedClubId.value,
            ...expenseForm.value,
        }

        if (!expenseHasOverflow.value) {
            payload.reimbursed_to = ''
            payload.reimbursement_payee_id = ''
            payload.reimbursement_payee_name = ''
            payload.reimbursement_payee_phone = ''
            payload.reimbursement_payee_email = ''
        }

        if (tutorialActive.value) {
            const createsReimbursement = expenseHasOverflow.value
            await tutorialCreateExpense(payload)
            showToast(createsReimbursement
                ? tr('Gasto tutorial con reembolso simulado.', 'Tutorial expense with reimbursement simulated.')
                : tr('Gasto tutorial simulado.', 'Tutorial expense simulated.'), 'success')
            resetExpenseForm()
            goToTutorialStep(createsReimbursement ? 'settle-reimbursement' : 'reimbursement-expense')
            return
        }

        await createFinanceEngineExpense(payload)
        showToast(tr('Gasto guardado.', 'Expense saved.'), 'success')
        resetExpenseForm()
        await refreshCaja()
    } catch (error) {
        expenseErrors.value = normalizeErrors(error)
        if (expenseHasOverflow.value && reimbursementTargetError.value) {
            showReimbursementOverflowModal.value = true
        }
        showToast(error?.response?.data?.message || tr('No se pudo guardar el gasto.', 'Could not save expense.'), 'error')
        console.error(error)
    } finally {
        savingExpense.value = false
    }
}

const actionErrorMessage = (error, fallback) => {
    if (error?.response?.data?.message) return error.response.data.message

    const validationMessage = Object.values(normalizeErrors(error)).find(Boolean)
    if (validationMessage) return validationMessage

    if (error?.code === 'ECONNABORTED') {
        return tr('La carga tardo demasiado. Intenta con un archivo mas pequeño o revisa la conexion.', 'The upload took too long. Try a smaller file or check the connection.')
    }

    if (error?.request && !error?.response) {
        return tr('No se recibio respuesta del servidor. Revisa la conexion e intenta de nuevo.', 'No server response was received. Check the connection and try again.')
    }

    return fallback
}

const uploadExpenseReceipt = async (expense) => {
    const file = expenseReceiptFiles.value[expense.id]
    if (!file) {
        setExpenseActionError(expense.id, tr('Selecciona un comprobante.', 'Select a proof image.'))
        return
    }

    setExpenseActionBusy(expense.id, true)
    setExpenseActionError(expense.id)
    setExpenseUploadProgress(expense.id, 0)

    try {
        if (tutorialActive.value) {
            await tutorialDelay()
            expenses.value = expenses.value.map((row) => Number(row.id) === Number(expense.id)
                ? {
                    ...row,
                    receipt_url: tutorialBuildExpenseProofUrl(`Comprobante gasto ${expense.id}`),
                    receipt_path: `tutorial/expense-${expense.id}.txt`,
                    status: 'completed',
                }
                : row)
            expenseReceiptFiles.value = { ...expenseReceiptFiles.value, [expense.id]: null }
            showToast(tr('Comprobante tutorial guardado.', 'Tutorial proof saved.'), 'success')
            return
        }

        await uploadFinanceEngineExpenseReceipt(expense.id, { receipt_image: file }, {
            onUploadProgress: uploadProgressHandler(expense.id),
        })
        setExpenseUploadProgress(expense.id, 100)
        expenseReceiptFiles.value = { ...expenseReceiptFiles.value, [expense.id]: null }
        showToast(tr('Comprobante guardado.', 'Proof saved.'), 'success')
        await refreshCaja()
    } catch (error) {
        setExpenseActionError(expense.id, actionErrorMessage(error, tr('No se pudo guardar el comprobante.', 'Could not save proof.')))
        console.error(error)
    } finally {
        setExpenseActionBusy(expense.id, false)
        window.setTimeout(() => setExpenseUploadProgress(expense.id, 0), 800)
    }
}

const removeExpenseReceipt = async (expense) => {
    setExpenseActionBusy(expense.id, true)
    setExpenseActionError(expense.id)
    setExpenseUploadProgress(expense.id, 0)

    try {
        if (tutorialActive.value) {
            await tutorialDelay()
            expenses.value = expenses.value.map((row) => Number(row.id) === Number(expense.id)
                ? {
                    ...row,
                    receipt_url: null,
                    receipt_path: null,
                    status: 'working',
                }
                : row)
            showToast(tr('Comprobante tutorial removido.', 'Tutorial proof removed.'), 'success')
            return
        }

        await removeFinanceEngineExpenseReceipt(expense.id)
        showToast(tr('Comprobante removido.', 'Proof removed.'), 'success')
        await refreshCaja()
    } catch (error) {
        setExpenseActionError(expense.id, actionErrorMessage(error, tr('No se pudo remover el comprobante.', 'Could not remove proof.')))
        console.error(error)
    } finally {
        setExpenseActionBusy(expense.id, false)
    }
}

const uploadReimbursementPaymentProof = async (expense) => {
    const file = reimbursementPaymentProofFiles.value[expense.id]
    if (!file) {
        setExpenseActionError(expense.id, tr('Selecciona un comprobante de pago.', 'Select a payment proof file.'))
        return
    }

    setExpenseActionBusy(expense.id, true)
    setExpenseActionError(expense.id)

    try {
        if (tutorialActive.value) {
            await tutorialDelay()
            expenses.value = expenses.value.map((row) => Number(row.id) === Number(expense.id)
                ? {
                    ...row,
                    reimbursement_payment_proof_url: tutorialBuildExpenseProofUrl(`Comprobante pago reembolso ${expense.id}`),
                }
                : row)
            reimbursementPaymentProofFiles.value = { ...reimbursementPaymentProofFiles.value, [expense.id]: null }
            showToast(tr('Comprobante de pago tutorial guardado.', 'Tutorial payment proof saved.'), 'success')
            return
        }

        await uploadFinanceEngineReimbursementPaymentProof(expense.id, { payment_proof_file: file }, {
            onUploadProgress: uploadProgressHandler(expense.id),
        })
        setExpenseUploadProgress(expense.id, 100)
        reimbursementPaymentProofFiles.value = { ...reimbursementPaymentProofFiles.value, [expense.id]: null }
        showToast(tr('Comprobante de pago guardado.', 'Payment proof saved.'), 'success')
        await refreshCaja()
    } catch (error) {
        setExpenseActionError(expense.id, actionErrorMessage(error, tr('No se pudo guardar el comprobante de pago.', 'Could not save payment proof.')))
        console.error(error)
    } finally {
        setExpenseActionBusy(expense.id, false)
        window.setTimeout(() => setExpenseUploadProgress(expense.id, 0), 800)
    }
}

const removeReimbursementPaymentProof = async (expense) => {
    setExpenseActionBusy(expense.id, true)
    setExpenseActionError(expense.id)

    try {
        if (tutorialActive.value) {
            await tutorialDelay()
            expenses.value = expenses.value.map((row) => Number(row.id) === Number(expense.id)
                ? {
                    ...row,
                    reimbursement_payment_proof_url: null,
                }
                : row)
            showToast(tr('Comprobante de pago tutorial removido.', 'Tutorial payment proof removed.'), 'success')
            return
        }

        await removeFinanceEngineReimbursementPaymentProof(expense.id)
        showToast(tr('Comprobante de pago removido.', 'Payment proof removed.'), 'success')
        await refreshCaja()
    } catch (error) {
        setExpenseActionError(expense.id, actionErrorMessage(error, tr('No se pudo remover el comprobante de pago.', 'Could not remove payment proof.')))
        console.error(error)
    } finally {
        setExpenseActionBusy(expense.id, false)
    }
}

const reimburseExpense = async (expense) => {
    const form = reimbursementForms.value[expense.id] || {}
    const paymentProofFile = reimbursementPaymentProofFiles.value[expense.id]

    if (!form.pay_to) {
        setExpenseActionError(expense.id, tr('Selecciona una cuenta origen.', 'Select a source account.'))
        return
    }
    if (!canSettleReimbursement(expense)) {
        setExpenseActionError(expense.id, tr('La cuenta seleccionada no tiene el monto completo para este reembolso.', 'The selected account does not have the full amount for this reimbursement.'))
        return
    }

    setExpenseActionBusy(expense.id, true)
    setExpenseActionError(expense.id)

    try {
        if (tutorialActive.value) {
            await tutorialSettleReimbursement(expense, {
                pay_to: form.pay_to,
                funds_location: form.funds_location || 'cash',
                reimbursement_date: form.reimbursement_date || today(),
                payment_proof_file: paymentProofFile || null,
            })
            reimbursementPaymentProofFiles.value = { ...reimbursementPaymentProofFiles.value, [expense.id]: null }
            showToast(tr('Reembolso tutorial liquidado.', 'Tutorial reimbursement settled.'), 'success')
            goToTutorialStep('receipt-signature')
            return
        }

        await reimburseFinanceEngineExpense(expense.id, {
            pay_to: form.pay_to,
            funds_location: form.funds_location || 'cash',
            reimbursement_date: form.reimbursement_date || today(),
            payment_proof_file: paymentProofFile || null,
        })
        reimbursementPaymentProofFiles.value = { ...reimbursementPaymentProofFiles.value, [expense.id]: null }
        showToast(tr('Reembolso liquidado.', 'Reimbursement settled.'), 'success')
        await refreshCaja()
    } catch (error) {
        setExpenseActionError(expense.id, actionErrorMessage(error, tr('No se pudo liquidar el reembolso.', 'Could not settle reimbursement.')))
        console.error(error)
    } finally {
        setExpenseActionBusy(expense.id, false)
    }
}

watch(expenseHasOverflow, (hasOverflow) => {
    if (tutorialActive.value) return
    showReimbursementOverflowModal.value = hasOverflow
})

watch(operatingSummaryAccounts, (accounts) => {
    if (balanceAccountFilter.value !== 'all' && !accounts.some((account) => account.account === balanceAccountFilter.value)) {
        balanceAccountFilter.value = 'all'
    }
})

watch([movementDomain, movementSort, movementPageSize, movementSearch], () => {
    movementPage.value = 1
})

watch(recentMovementGroups, () => {
    if (movementPage.value > movementPageCount.value) {
        setMovementPage(movementPageCount.value)
    }
})

watch([tutorialActive, tutorialStepIndex], ([active]) => {
    if (active) {
        tutorialApplyStepPreset()
        updateTutorialTarget(true)
    }
})

onMounted(() => {
    loadCaja()
    window.addEventListener('resize', handleTutorialViewportChange)
    window.addEventListener('scroll', handleTutorialViewportChange, true)
    window.addEventListener('keydown', handleTutorialKeydown)
    window.addEventListener('message', handleTutorialReceiptMessage)
})

onBeforeUnmount(() => {
    window.removeEventListener('resize', handleTutorialViewportChange)
    window.removeEventListener('scroll', handleTutorialViewportChange, true)
    window.removeEventListener('keydown', handleTutorialKeydown)
    window.removeEventListener('message', handleTutorialReceiptMessage)
})
</script>

<template>
    <PathfinderLayout>
        <template #title>{{ tr('Caja', 'Cashbox') }}</template>

        <div class="space-y-5">
            <section data-tour="cashbox-header" class="border-b border-gray-200 pb-4">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        <p class="text-sm font-medium uppercase tracking-wide text-gray-500">{{ tr('Modulo financiero', 'Finance module') }}</p>
                        <h2 class="mt-1 text-xl font-semibold text-gray-900">{{ tr('Caja de ingresos y gastos', 'Income and expense cashbox') }}</h2>
                        <p class="mt-1 text-sm text-gray-600">
                            {{ tr('Registra entradas y salidas usando el motor financiero como lectura de control.', 'Record inflows and outflows while using the finance engine as the control readout.') }}
                        </p>
                    </div>

                    <div class="flex flex-col gap-2 sm:min-w-64">
                        <label v-if="canSelectClub" class="text-xs font-semibold uppercase tracking-wide text-gray-500">
                            {{ tr('Club activo', 'Active club') }}
                        </label>
                        <select
                            v-if="canSelectClub"
                            v-model="selectedClubId"
                            :disabled="tutorialActive"
                            @change="onClubChange"
                            class="w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-red-500 focus:ring-red-500 disabled:bg-gray-100"
                        >
                            <option v-for="club in clubs" :key="club.id" :value="club.id">{{ club.club_name }}</option>
                        </select>
                        <div v-else class="rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm font-semibold text-gray-800">
                            {{ activeClubName }}
                        </div>
                        <button
                            type="button"
                            data-tour="cashbox-tutorial-toggle"
                            class="inline-flex min-h-10 items-center justify-center gap-2 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm font-semibold text-red-700 hover:bg-red-100"
                            @click="startCajaTutorial"
                        >
                            <QuestionMarkCircleIcon class="h-4 w-4" />
                            {{ tutorialActive ? tr('Reiniciar tutorial', 'Restart tutorial') : tr('Modo tutorial', 'Tutorial mode') }}
                        </button>
                    </div>
                </div>
            </section>

            <div v-if="loadError" class="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">
                {{ loadError }}
            </div>

            <div v-if="tutorialActive" class="rounded-lg border border-red-200 bg-red-50 px-4 py-3">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <p class="text-sm font-semibold text-red-900">{{ tr('Modo tutorial activo', 'Tutorial mode active') }}</p>
                        <p class="mt-1 text-sm text-red-800">
                            {{ tr('Los ingresos, gastos, reembolsos y firmas son simulados. Al salir se borra todo y se recarga la caja real.', 'Income, expenses, reimbursements, and signatures are simulated. Exiting clears everything and reloads the real cashbox.') }}
                        </p>
                    </div>
                    <button
                        type="button"
                        class="inline-flex min-h-10 items-center justify-center rounded-lg bg-red-700 px-3 py-2 text-sm font-semibold text-white hover:bg-red-800"
                        @click="closeCajaTutorial"
                    >
                        {{ tr('Salir y borrar practica', 'Exit and clear practice') }}
                    </button>
                </div>
            </div>

            <section data-tour="cashbox-balances" class="space-y-3">
                <div class="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        <h3 class="text-base font-semibold text-gray-900">{{ tr('Estado de cuenta', 'Account status') }}</h3>
                        <p class="mt-1 text-sm text-gray-500">{{ tr('Filtra los saldos por cuenta financiera.', 'Filter balances by finance account.') }}</p>
                    </div>
                    <div data-tour="cashbox-account-filter" class="sm:min-w-72">
                        <label class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ tr('Cuenta', 'Account') }}</label>
                        <select
                            v-model="balanceAccountFilter"
                            class="mt-1 w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-red-500 focus:ring-red-500"
                        >
                            <option v-for="account in balanceAccountOptions" :key="account.value" :value="account.value">{{ account.label }}</option>
                        </select>
                    </div>
                </div>

                <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    <div class="rounded-lg border border-gray-200 bg-white p-4">
                        <div class="flex items-center gap-2 text-sm font-semibold text-gray-700">
                            <BanknotesIcon class="h-5 w-5 text-emerald-600" />
                            {{ tr('Efectivo', 'Cash') }}
                        </div>
                        <p class="mt-3 text-2xl font-semibold" :class="balanceSummary.cash_balance < 0 ? 'text-rose-700' : 'text-gray-950'">{{ formatMoney(balanceSummary.cash_balance) }}</p>
                    </div>
                    <div class="rounded-lg border border-gray-200 bg-white p-4">
                        <div class="flex items-center gap-2 text-sm font-semibold text-gray-700">
                            <CreditCardIcon class="h-5 w-5 text-blue-600" />
                            {{ tr('Banco', 'Bank') }}
                        </div>
                        <p class="mt-3 text-2xl font-semibold" :class="balanceSummary.bank_balance < 0 ? 'text-rose-700' : 'text-gray-950'">{{ formatMoney(balanceSummary.bank_balance) }}</p>
                    </div>
                    <div class="rounded-lg border border-gray-200 bg-white p-4">
                        <div class="flex items-center gap-2 text-sm font-semibold text-gray-700">
                            <CheckCircleIcon class="h-5 w-5 text-gray-600" />
                            {{ tr('Disponible', 'Available') }}
                        </div>
                        <p class="mt-3 text-2xl font-semibold" :class="balanceSummary.total_available < 0 ? 'text-rose-700' : 'text-gray-950'">{{ formatMoney(balanceSummary.total_available) }}</p>
                    </div>
                    <div class="rounded-lg border border-amber-200 bg-amber-50 p-4">
                        <div class="flex items-center gap-2 text-sm font-semibold text-amber-900">
                            <ExclamationTriangleIcon class="h-5 w-5 text-amber-700" />
                            {{ tr('Reembolsos pendientes', 'Reimbursements owed') }}
                        </div>
                        <p class="mt-3 text-2xl font-semibold" :class="reimbursementBalanceSummary.total_available < 0 ? 'text-rose-700' : 'text-gray-950'">
                            {{ formatMoney(reimbursementBalanceSummary.total_available) }}
                        </p>
                    </div>
                </div>
            </section>

            <section class="grid gap-5 xl:grid-cols-2">
                <form data-tour="cashbox-income-form" class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm" @submit.prevent="submitIncome">
                    <div class="mb-4 flex items-center gap-2">
                        <CreditCardIcon class="h-5 w-5 text-emerald-600" />
                        <h3 class="text-base font-semibold text-gray-900">{{ tr('Registrar ingreso', 'Record income') }}</h3>
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="sm:col-span-2">
                            <label class="text-sm font-medium text-gray-700">{{ tr('Tipo de ingreso', 'Income type') }}</label>
                            <div class="mt-2 grid grid-cols-2 rounded-lg border border-gray-200 bg-gray-50 p-1">
                                <button
                                    type="button"
                                    class="rounded-md px-3 py-2 text-sm font-medium"
                                    :class="incomeForm.mode === 'existing' ? 'bg-white text-gray-950 shadow-sm' : 'text-gray-600'"
                                    @click="incomeForm.mode = 'existing'"
                                >
                                    {{ tr('Concepto guardado', 'Saved concept') }}
                                </button>
                                <button
                                    type="button"
                                    class="rounded-md px-3 py-2 text-sm font-medium"
                                    :class="incomeForm.mode === 'manual' ? 'bg-white text-gray-950 shadow-sm' : 'text-gray-600'"
                                    @click="incomeForm.mode = 'manual'"
                                >
                                    {{ tr('Manual', 'Manual') }}
                                </button>
                            </div>
                        </div>

                        <div v-if="incomeForm.mode === 'existing'" class="sm:col-span-2">
                            <label class="text-sm font-medium text-gray-700">{{ tr('Concepto', 'Concept') }}</label>
                            <select
                                v-model="incomeForm.concept_key"
                                @change="onConceptChange"
                                class="mt-1 w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-red-500 focus:ring-red-500"
                            >
                                <option value="">{{ tr('Selecciona concepto', 'Select concept') }}</option>
                                <option :value="CREATE_CONCEPT_OPTION">{{ tr('+ Crear concepto nuevo', '+ Create new concept') }}</option>
                                <option v-for="option in conceptOptions" :key="option.key" :value="option.key">
                                    {{ option.label }} · {{ formatMoney(option.amount) }}
                                </option>
                            </select>
                            <p v-if="firstError(incomeErrors, 'payment_concept_id')" class="mt-1 text-xs text-rose-600">{{ firstError(incomeErrors, 'payment_concept_id') }}</p>
                        </div>

                        <div v-if="selectedEventGroup" class="sm:col-span-2 rounded-lg border border-blue-100 bg-blue-50 p-3">
                            <p class="text-sm font-semibold text-blue-900">{{ tr('Desglose del evento', 'Event breakdown') }}</p>
                            <div class="mt-2 grid gap-2 sm:grid-cols-2">
                                <label
                                    v-for="componentConcept in selectedEventGroup.concepts"
                                    :key="componentConcept.id"
                                    class="flex items-start gap-2 rounded-md border border-blue-100 bg-white p-2 text-sm"
                                >
                                    <input
                                        type="checkbox"
                                        class="mt-0.5 rounded border-gray-300 text-red-600 focus:ring-red-500"
                                        :checked="incomeForm.selected_event_concept_ids.map(Number).includes(Number(componentConcept.id))"
                                        :disabled="Boolean(eventComponent(componentConcept)?.is_required ?? true)"
                                        @change="toggleEventComponent(componentConcept, $event.target.checked)"
                                    />
                                    <span>
                                        <span class="font-medium text-gray-900">{{ eventComponent(componentConcept)?.label || componentConcept.concept }}</span>
                                        <span class="block text-xs text-gray-500">
                                            {{ formatMoney(componentConcept.amount) }} ·
                                            {{ Boolean(eventComponent(componentConcept)?.is_required ?? true) ? tr('Obligatorio', 'Required') : tr('Opcional', 'Optional') }}
                                        </span>
                                    </span>
                                </label>
                            </div>
                            <p v-if="firstError(incomeErrors, 'event_concept_ids')" class="mt-2 text-xs text-rose-600">{{ firstError(incomeErrors, 'event_concept_ids') }}</p>
                        </div>

                        <div v-if="incomeForm.mode === 'manual'" class="sm:col-span-2">
                            <label class="text-sm font-medium text-gray-700">{{ tr('Concepto manual', 'Manual concept') }}</label>
                            <input
                                v-model="incomeForm.concept_text"
                                type="text"
                                class="mt-1 w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-red-500 focus:ring-red-500"
                                :placeholder="tr('Ej. Donacion especial', 'Example: Special donation')"
                            />
                            <p v-if="firstError(incomeErrors, 'concept_text')" class="mt-1 text-xs text-rose-600">{{ firstError(incomeErrors, 'concept_text') }}</p>
                        </div>

                        <div>
                            <label class="text-sm font-medium text-gray-700">{{ tr('Cuenta', 'Account') }}</label>
                            <select
                                v-model="incomeForm.pay_to"
                                :disabled="incomeForm.mode === 'existing' && Boolean(selectedConceptOption)"
                                class="mt-1 w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-red-500 focus:ring-red-500 disabled:bg-gray-100"
                            >
                                <option v-for="account in operatingAccountOptions" :key="account.value" :value="account.value">{{ account.label }}</option>
                            </select>
                        </div>

                        <div data-tour="cashbox-payer">
                            <label class="text-sm font-medium text-gray-700">{{ tr('Pagador', 'Payer') }}</label>
                            <select
                                v-model="incomeForm.payer_key"
                                class="mt-1 w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-red-500 focus:ring-red-500"
                            >
                                <option value="">{{ tr('Selecciona pagador', 'Select payer') }}</option>
                                <option :value="CUSTOM_PAYER_OPTION">{{ tr('+ Pagador externo / otro', '+ External / other payer') }}</option>
                                <option v-for="payer in payerOptions" :key="payer.value" :value="payer.value">{{ payer.label }}</option>
                            </select>
                            <p v-if="firstError(incomeErrors, 'member_id') || firstError(incomeErrors, 'staff_id') || firstError(incomeErrors, 'payer_name')" class="mt-1 text-xs text-rose-600">
                                {{ firstError(incomeErrors, 'member_id') || firstError(incomeErrors, 'staff_id') || firstError(incomeErrors, 'payer_name') }}
                            </p>
                        </div>

                        <div v-if="incomeForm.payer_key === CUSTOM_PAYER_OPTION" data-tour="cashbox-payer-name">
                            <label class="text-sm font-medium text-gray-700">{{ tr('Nombre del pagador', 'Payer name') }}</label>
                            <input
                                v-model="incomeForm.payer_name"
                                type="text"
                                class="mt-1 w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-red-500 focus:ring-red-500"
                                :placeholder="tr('Ej. Donante invitado', 'Example: Guest donor')"
                            />
                        </div>

                        <div>
                            <label class="text-sm font-medium text-gray-700">{{ tr('Monto', 'Amount') }}</label>
                            <input
                                v-model="incomeForm.amount_paid"
                                type="number"
                                min="0.01"
                                step="0.01"
                                class="mt-1 w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-red-500 focus:ring-red-500"
                            />
                            <p v-if="selectedIncomeExpected !== null" class="mt-1 text-xs text-gray-500">{{ tr('Referencia', 'Reference') }}: {{ formatMoney(selectedIncomeExpected) }}</p>
                            <p v-if="firstError(incomeErrors, 'amount_paid')" class="mt-1 text-xs text-rose-600">{{ firstError(incomeErrors, 'amount_paid') }}</p>
                        </div>

                        <div>
                            <label class="text-sm font-medium text-gray-700">{{ tr('Fecha', 'Date') }}</label>
                            <input
                                v-model="incomeForm.payment_date"
                                type="date"
                                class="mt-1 w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-red-500 focus:ring-red-500"
                            />
                        </div>

                        <div data-tour="cashbox-income-method">
                            <label class="text-sm font-medium text-gray-700">{{ tr('Metodo', 'Method') }}</label>
                            <select
                                v-model="incomeForm.payment_type"
                                class="mt-1 w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-red-500 focus:ring-red-500"
                            >
                                <option value="cash">{{ tr('Efectivo', 'Cash') }}</option>
                                <option value="zelle">Zelle</option>
                                <option value="transfer">{{ tr('Transferencia', 'Transfer') }}</option>
                                <option value="check">{{ tr('Cheque', 'Check') }}</option>
                                <option value="initial">{{ tr('Saldo inicial', 'Initial balance') }}</option>
                            </select>
                        </div>

                        <div v-if="incomeForm.payment_type === 'zelle'">
                            <label class="text-sm font-medium text-gray-700">{{ tr('Telefono emisor Zelle', 'Sender Zelle phone') }}</label>
                            <input
                                v-model="incomeForm.zelle_phone"
                                type="text"
                                class="mt-1 w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-red-500 focus:ring-red-500"
                            />
                        </div>

                        <div v-if="incomeForm.payment_type === 'check'" class="sm:col-span-2">
                            <label class="text-sm font-medium text-gray-700">{{ tr('Imagen del cheque', 'Check image') }}</label>
                            <input
                                ref="incomeCheckInput"
                                type="file"
                                accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"
                                class="mt-1 block w-full text-sm text-gray-700"
                                @change="onIncomeCheckImage"
                            />
                            <p v-if="firstError(incomeErrors, 'check_image')" class="mt-1 text-xs text-rose-600">{{ firstError(incomeErrors, 'check_image') }}</p>
                        </div>

                        <div class="sm:col-span-2">
                            <label class="text-sm font-medium text-gray-700">{{ tr('Notas', 'Notes') }}</label>
                            <textarea
                                v-model="incomeForm.notes"
                                rows="2"
                                class="mt-1 w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-red-500 focus:ring-red-500"
                            ></textarea>
                        </div>
                    </div>

                    <button
                        type="submit"
                        data-tour="cashbox-save-income"
                        :disabled="savingIncome || loading"
                        class="mt-4 inline-flex min-h-10 w-full items-center justify-center gap-2 rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800 disabled:opacity-60 sm:w-auto"
                    >
                        <ArrowPathIcon v-if="savingIncome" class="h-4 w-4 animate-spin" />
                        <BanknotesIcon v-else class="h-4 w-4" />
                        {{ savingIncome ? tr('Guardando...', 'Saving...') : tr('Guardar ingreso', 'Save income') }}
                    </button>
                </form>

                <form data-tour="cashbox-expense-form" class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm" @submit.prevent="submitExpense">
                    <div class="mb-4 flex items-center gap-2">
                        <CurrencyDollarIcon class="h-5 w-5 text-rose-600" />
                        <h3 class="text-base font-semibold text-gray-900">{{ tr('Registrar gasto', 'Record expense') }}</h3>
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label class="text-sm font-medium text-gray-700">{{ tr('Cuenta origen', 'Source account') }}</label>
                            <select
                                v-model="expenseForm.pay_to"
                                class="mt-1 w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-red-500 focus:ring-red-500"
                            >
                                <option v-for="account in operatingAccountOptions" :key="account.value" :value="account.value">{{ account.label }}</option>
                            </select>
                            <p v-if="firstError(expenseErrors, 'pay_to')" class="mt-1 text-xs text-rose-600">{{ firstError(expenseErrors, 'pay_to') }}</p>
                        </div>

                        <div>
                            <label class="text-sm font-medium text-gray-700">{{ tr('Origen del dinero', 'Money origin') }}</label>
                            <select
                                v-model="expenseForm.funds_location"
                                class="mt-1 w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-red-500 focus:ring-red-500"
                            >
                                <option value="cash">{{ tr('Efectivo', 'Cash') }}</option>
                                <option value="bank">{{ tr('Banco', 'Bank') }}</option>
                            </select>
                        </div>

                        <div>
                            <label class="text-sm font-medium text-gray-700">{{ tr('Monto', 'Amount') }}</label>
                            <input
                                v-model="expenseForm.amount"
                                type="number"
                                min="0.01"
                                step="0.01"
                                class="mt-1 w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-red-500 focus:ring-red-500"
                            />
                            <p v-if="firstError(expenseErrors, 'amount')" class="mt-1 text-xs text-rose-600">{{ firstError(expenseErrors, 'amount') }}</p>
                        </div>

                        <div>
                            <label class="text-sm font-medium text-gray-700">{{ tr('Fecha', 'Date') }}</label>
                            <input
                                v-model="expenseForm.expense_date"
                                type="date"
                                class="mt-1 w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-red-500 focus:ring-red-500"
                            />
                        </div>

                        <div v-if="expenseAmount > 0" class="sm:col-span-2 rounded-lg border border-gray-200 bg-gray-50 p-3">
                            <div class="grid gap-3 text-xs text-gray-600 sm:grid-cols-3">
                                <div>
                                    <p class="font-semibold uppercase tracking-wide text-gray-500">{{ tr('Origen seleccionado', 'Selected origin') }}</p>
                                    <p class="mt-1 text-sm font-semibold text-gray-900">{{ locationLabel(expenseForm.funds_location) }} · {{ formatMoney(expenseSelectedLocationBalance) }}</p>
                                </div>
                                <div>
                                    <p class="font-semibold uppercase tracking-wide text-gray-500">{{ tr('Balance de cuenta', 'Account balance') }}</p>
                                    <p class="mt-1 text-sm font-semibold text-gray-900">{{ formatMoney(expenseSelectedAccountTotalBalance) }}</p>
                                </div>
                                <div>
                                    <p class="font-semibold uppercase tracking-wide text-gray-500">{{ tr('Resultado', 'Result') }}</p>
                                    <p v-if="expenseHasOverflow" class="mt-1 text-sm font-semibold text-amber-800">
                                        {{ tr('Reembolso pendiente', 'Pending reimbursement') }} {{ formatMoney(expenseOverflowAmount) }}
                                    </p>
                                    <p v-else-if="expenseNeedsInternalTransfer" class="mt-1 text-sm font-semibold text-blue-700">
                                        {{ tr('Transferencia interna', 'Internal transfer') }} {{ formatMoney(expenseTransferAmount) }}
                                    </p>
                                    <p v-else class="mt-1 text-sm font-semibold text-emerald-700">
                                        {{ tr('Cubierto por la cuenta', 'Covered by account') }}
                                    </p>
                                </div>
                            </div>
                        </div>

                        <div class="sm:col-span-2">
                            <label class="text-sm font-medium text-gray-700">{{ tr('Descripcion', 'Description') }}</label>
                            <textarea
                                v-model="expenseForm.description"
                                rows="3"
                                class="mt-1 w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-red-500 focus:ring-red-500"
                            ></textarea>
                        </div>

                        <div class="sm:col-span-2">
                            <label class="text-sm font-medium text-gray-700">{{ tr('Notas', 'Notes') }}</label>
                            <textarea
                                v-model="expenseForm.notes"
                                rows="2"
                                class="mt-1 w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-red-500 focus:ring-red-500"
                            ></textarea>
                            <p v-if="firstError(expenseErrors, 'notes')" class="mt-1 text-xs text-rose-600">{{ firstError(expenseErrors, 'notes') }}</p>
                        </div>

                        <div v-if="expenseHasOverflow" class="sm:col-span-2 rounded-lg border border-amber-200 bg-amber-50 p-3">
                            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                <div>
                                    <p class="text-sm font-semibold text-amber-900">{{ tr('Excedente detectado', 'Overflow detected') }}</p>
                                    <p class="mt-1 text-xs text-amber-800">
                                        {{ tr('Balance de cuenta', 'Account balance') }}: {{ formatMoney(expenseSelectedAccountTotalBalance) }}
                                        · {{ tr('Excedente', 'Overflow') }}: {{ formatMoney(expenseOverflowAmount) }}
                                    </p>
                                    <p v-if="expenseNeedsInternalTransfer" class="mt-1 text-xs text-amber-800">
                                        {{ tr('Tambien se registrara transferencia interna hacia', 'An internal transfer will also be recorded to') }} {{ locationLabel(expenseForm.funds_location) }} {{ tr('por', 'for') }} {{ formatMoney(expenseTransferAmount) }}.
                                    </p>
                                    <p class="mt-1 text-xs text-amber-800">
                                        {{ tr('Reembolso a', 'Reimbursement to') }}: {{ reimbursementTargetLabel }}
                                    </p>
                                </div>
                                <button
                                    type="button"
                                    class="inline-flex min-h-10 items-center justify-center rounded-lg bg-amber-700 px-4 py-2 text-sm font-semibold text-white hover:bg-amber-800"
                                    @click="openReimbursementOverflowModal"
                                >
                                    {{ tr('Definir reembolso', 'Set reimbursement') }}
                                </button>
                            </div>
                            <p v-if="reimbursementTargetError" class="mt-2 text-xs text-rose-600">
                                {{ reimbursementTargetError }}
                            </p>
                        </div>

                        <div data-tour="cashbox-expense-proof" class="sm:col-span-2">
                            <label class="text-sm font-medium text-gray-700">{{ tr('Comprobante', 'Proof') }}</label>
                            <input
                                ref="expenseReceiptInput"
                                type="file"
                                accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"
                                class="mt-1 block w-full text-sm text-gray-700"
                                @change="onExpenseReceipt"
                            />
                            <p v-if="firstError(expenseErrors, 'receipt_image')" class="mt-1 text-xs text-rose-600">{{ firstError(expenseErrors, 'receipt_image') }}</p>
                        </div>
                    </div>

                    <button
                        type="submit"
                        data-tour="cashbox-save-expense"
                        :disabled="savingExpense || loading"
                        class="mt-4 inline-flex min-h-10 w-full items-center justify-center gap-2 rounded-lg bg-rose-700 px-4 py-2 text-sm font-semibold text-white hover:bg-rose-800 disabled:opacity-60 sm:w-auto"
                    >
                        <ArrowPathIcon v-if="savingExpense" class="h-4 w-4 animate-spin" />
                        <DocumentTextIcon v-else class="h-4 w-4" />
                        {{ savingExpense ? tr('Guardando...', 'Saving...') : tr('Guardar gasto', 'Save expense') }}
                    </button>
                </form>
            </section>

            <section v-if="hasExpenseFollowUp" data-tour="cashbox-follow-up" class="rounded-lg border border-gray-200 bg-white shadow-sm">
                <div class="border-b border-gray-200 p-4">
                    <h3 class="text-base font-semibold text-gray-900">{{ tr('Seguimiento de gastos', 'Expense follow-up') }}</h3>
                    <p class="mt-1 text-sm text-gray-500">
                        {{ tr('Aqui encontraras gastos registrados que necesitan comprobante y reembolsos generados por gastos con excedente, para ver a quien se debe, subir comprobantes y liquidarlos cuando la cuenta tenga el monto completo.', 'Here you will find recorded expenses that need proof and reimbursements created by overflow expenses, so you can see who is owed, upload proofs, and settle them when the account has the full amount.') }}
                    </p>
                </div>

                <div class="divide-y divide-gray-100">
                    <div v-for="section in expenseFollowUpSections" :key="section.key">
                        <button
                            type="button"
                            class="flex w-full items-center justify-between gap-3 px-4 py-3 text-left hover:bg-gray-50"
                            @click="toggleExpenseFollowUpSection(section.key)"
                        >
                            <span class="min-w-0">
                                <span class="flex items-center gap-2 text-sm font-semibold text-gray-900">
                                    <ChevronDownIcon v-if="isExpenseFollowUpSectionOpen(section.key)" class="h-4 w-4 shrink-0" />
                                    <ChevronRightIcon v-else class="h-4 w-4 shrink-0" />
                                    {{ section.title }}
                                    <span class="rounded-full bg-gray-100 px-2 py-0.5 text-xs font-semibold text-gray-600">{{ section.rows.length }}</span>
                                </span>
                                <span class="mt-0.5 block text-xs text-gray-500">{{ section.description }}</span>
                            </span>
                        </button>

                        <div v-if="isExpenseFollowUpSectionOpen(section.key)" class="divide-y divide-gray-100 border-t border-gray-100">
                            <div v-if="!section.rows.length" class="px-4 py-6 text-sm text-gray-500">
                                {{ tr('No hay movimientos en esta seccion.', 'No movements in this section.') }}
                            </div>
                    <template v-for="{ key, type, expense } in section.rows" :key="key">
                    <article v-if="type === 'expense'" class="grid gap-4 p-4 lg:grid-cols-[minmax(0,1fr)_minmax(280px,360px)]">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="rounded-full border border-rose-200 bg-rose-50 px-2 py-1 text-xs font-semibold text-rose-700">
                                    {{ tr('Gasto', 'Expense') }}
                                </span>
                                <span class="text-sm font-semibold text-gray-900">{{ expense.description || tr('Gasto sin descripcion', 'Expense without description') }}</span>
                                <span class="text-xs text-gray-500">#{{ expense.id }}</span>
                            </div>
                            <div class="mt-2 grid gap-1 text-sm text-gray-600 sm:grid-cols-2 lg:grid-cols-4">
                                <span>{{ tr('Fecha', 'Date') }}: {{ formatDate(expense.expense_date) }}</span>
                                <span>{{ tr('Cuenta', 'Account') }}: {{ accountLabel(expense.pay_to) }}</span>
                                <span>{{ tr('Ubicacion', 'Location') }}: {{ locationLabel(expense.funds_location) }}</span>
                                <span>{{ tr('Estado', 'Status') }}: {{ expenseStatusLabel(expense.status) }}</span>
                            </div>
                            <div class="mt-2 flex flex-wrap gap-3 text-sm">
                                <span class="font-semibold text-rose-700">{{ formatMoney(expense.amount) }}</span>
                                <a v-if="expense.receipt_url" :href="expense.receipt_url" target="_blank" rel="noopener" class="font-semibold text-gray-700 hover:underline">
                                    {{ tr('Ver comprobante', 'View proof') }}
                                </a>
                            </div>
                            <div v-if="expense.generated_reimbursement_expense" class="mt-3 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800">
                                {{ tr('Este gasto genero reembolso pendiente', 'This expense generated a pending reimbursement') }}
                                #{{ expense.generated_reimbursement_expense.id }}
                                {{ tr('por', 'for') }}
                                <span class="font-semibold">{{ formatMoney(expense.generated_reimbursement_expense.amount) }}</span>.
                            </div>
                        </div>

                        <div class="space-y-2">
                            <input
                                type="file"
                                accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"
                                class="block w-full text-sm text-gray-700"
                                @change="setExpenseReceiptFile(expense.id, $event)"
                            />
                            <div v-if="expenseUploadProgressValue(expense.id) > 0" class="space-y-1">
                                <div class="h-2 overflow-hidden rounded-full bg-gray-100">
                                    <div
                                        class="h-full rounded-full bg-red-600 transition-all"
                                        :style="{ width: `${expenseUploadProgressValue(expense.id)}%` }"
                                    ></div>
                                </div>
                                <p class="text-xs text-gray-500">
                                    {{ tr('Subiendo', 'Uploading') }} {{ expenseUploadProgressValue(expense.id) }}%
                                </p>
                            </div>
                            <div class="flex flex-col gap-2 sm:flex-row">
                                <button
                                    type="button"
                                    class="inline-flex min-h-10 flex-1 items-center justify-center gap-2 rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 disabled:opacity-60"
                                    :disabled="isExpenseActionBusy(expense.id)"
                                    @click="uploadExpenseReceipt(expense)"
                                >
                                    <ArrowUpTrayIcon class="h-4 w-4" />
                                    {{ expense.receipt_url ? tr('Reemplazar', 'Replace') : tr('Subir comprobante', 'Upload proof') }}
                                </button>
                                <button
                                    v-if="expense.receipt_url"
                                    type="button"
                                    class="inline-flex min-h-10 items-center justify-center gap-2 rounded-lg border border-rose-200 bg-white px-3 py-2 text-sm font-semibold text-rose-700 hover:bg-rose-50 disabled:opacity-60"
                                    :disabled="isExpenseActionBusy(expense.id)"
                                    @click="removeExpenseReceipt(expense)"
                                >
                                    <TrashIcon class="h-4 w-4" />
                                    {{ tr('Quitar', 'Remove') }}
                                </button>
                            </div>
                            <p v-if="expenseActionError(expense.id)" class="text-xs text-rose-600">{{ expenseActionError(expense.id) }}</p>
                        </div>
                    </article>

                    <article v-else class="grid gap-4 p-4 lg:grid-cols-[minmax(0,1fr)_minmax(320px,420px)]">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="rounded-full border border-amber-200 bg-amber-50 px-2 py-1 text-xs font-semibold text-amber-700">
                                    {{ tr('Reembolso', 'Reimbursement') }}
                                </span>
                                <span class="text-sm font-semibold text-gray-900">{{ expense.reimbursement_payee?.name || expense.reimbursed_to || tr('Persona pendiente', 'Pending person') }}</span>
                                <span class="text-xs text-gray-500">#{{ expense.id }}</span>
                            </div>
                            <div class="mt-2 grid gap-1 text-sm text-gray-600 sm:grid-cols-2 lg:grid-cols-4">
                                <span>{{ tr('Fecha', 'Date') }}: {{ formatDate(expense.expense_date) }}</span>
                                <span>{{ tr('Cuenta', 'Account') }}: {{ accountLabel(expense.pay_to) }}</span>
                                <span>{{ tr('Monto', 'Amount') }}: {{ formatMoney(expense.amount) }}</span>
                                <span>{{ tr('Estado', 'Status') }}: {{ expenseStatusLabel(expense.status) }}</span>
                            </div>
                            <div v-if="expense.reimbursement_payee?.phone || expense.reimbursement_payee?.email" class="mt-1 flex flex-wrap gap-3 text-xs text-gray-500">
                                <span v-if="expense.reimbursement_payee?.phone">{{ expense.reimbursement_payee.phone }}</span>
                                <span v-if="expense.reimbursement_payee?.email">{{ expense.reimbursement_payee.email }}</span>
                            </div>
                            <div v-if="expense.reimbursement_origin_expense" class="mt-3 rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 text-xs text-gray-700">
                                <p class="font-semibold text-gray-900">
                                    {{ tr('Relacionado con gasto', 'Related to expense') }} #{{ expense.reimbursement_origin_expense.id }}
                                </p>
                                <p class="mt-1">
                                    {{ expense.reimbursement_origin_expense.description || tr('Sin descripcion', 'No description') }}
                                    · {{ formatDate(expense.reimbursement_origin_expense.expense_date) }}
                                    · {{ formatMoney(expense.reimbursement_origin_expense.amount) }}
                                </p>
                            </div>
                            <div class="mt-2 flex flex-wrap gap-3 text-sm">
                                <span v-if="expense.settlement_expense" class="text-gray-500">
                                    {{ tr('Liquidado desde', 'Settled from') }} {{ accountLabel(expense.settlement_expense.pay_to) }}
                                </span>
                            </div>
                            <div v-if="expense.reimbursement_confirmation_url" data-tour="cashbox-reimbursement-receipt" class="mt-3 grid gap-3 rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-3 sm:grid-cols-[96px_minmax(0,1fr)]">
                                <img
                                    v-if="expense.reimbursement_confirmation_qr_url"
                                    :src="expense.reimbursement_confirmation_qr_url"
                                    :alt="tr('QR del recibo de reembolso', 'Reimbursement receipt QR')"
                                    class="h-24 w-24 rounded-md border border-emerald-200 bg-white p-1"
                                />
                                <div class="min-w-0 text-sm">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <QrCodeIcon class="h-4 w-4 text-emerald-700" />
                                        <p class="font-semibold text-emerald-950">
                                            {{ tr('Recibo para firma', 'Receipt for signature') }}
                                        </p>
                                        <span class="rounded-full bg-white px-2 py-0.5 text-xs font-semibold text-emerald-800 ring-1 ring-emerald-200">
                                            {{ expense.reimbursement_receipt_signed_at ? tr('Firmado', 'Signed') : tr('Pendiente de firma', 'Pending signature') }}
                                        </span>
                                    </div>
                                    <p class="mt-1 text-xs text-emerald-800">
                                        {{ tr('Comparte este QR con la persona reembolsada para que confirme el recibo con su firma.', 'Share this QR with the reimbursed person so they can confirm the receipt with their signature.') }}
                                    </p>
                                    <div class="mt-2 flex flex-wrap gap-3 text-xs font-semibold">
                                        <a :href="expense.reimbursement_confirmation_url" target="_blank" rel="noopener" class="inline-flex items-center gap-1 text-emerald-800 hover:underline" @click="openReimbursementConfirmation(expense, $event)">
                                            <PencilSquareIcon class="h-4 w-4" />
                                            {{ tr('Abrir recibo', 'Open receipt') }}
                                        </a>
                                        <a v-if="expense.reimbursement_signature_url" :href="expense.reimbursement_signature_url" target="_blank" rel="noopener" class="text-emerald-800 hover:underline">
                                            {{ tr('Ver firma', 'View signature') }}
                                        </a>
                                        <a v-if="expense.reimbursement_receipt_signed_at && expense.reimbursement_receipt_url" :href="expense.reimbursement_receipt_url" target="_blank" rel="noopener" class="text-emerald-800 hover:underline">
                                            {{ tr('Descargar PDF', 'Download PDF') }}
                                        </a>
                                    </div>
                                    <p v-if="expense.reimbursement_receipt_signed_at" class="mt-2 text-xs text-emerald-800">
                                        {{ tr('Firmado por', 'Signed by') }} {{ expense.reimbursement_receipt_signer_name || expense.reimbursed_to || tr('persona reembolsada', 'reimbursed person') }}
                                        · {{ formatDateTime(expense.reimbursement_receipt_signed_at) }}
                                    </p>
                                </div>
                            </div>
                        </div>

                        <div class="space-y-3">
                            <div v-if="expense.status === 'pending_reimbursement' && reimbursementForms[expense.id]" class="grid gap-3 sm:grid-cols-2">
                                <div>
                                    <label class="text-sm font-medium text-gray-700">{{ tr('Cuenta origen', 'Source account') }}</label>
                                    <select
                                        v-model="reimbursementForms[expense.id].pay_to"
                                        class="mt-1 w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-red-500 focus:ring-red-500"
                                    >
                                        <option v-for="account in reimbursementFundingOptions" :key="account.value" :value="account.value">{{ account.label }}</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="text-sm font-medium text-gray-700">{{ tr('Origen del dinero', 'Money origin') }}</label>
                                    <select
                                        v-model="reimbursementForms[expense.id].funds_location"
                                        class="mt-1 w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-red-500 focus:ring-red-500"
                                    >
                                        <option value="cash">{{ tr('Efectivo', 'Cash') }}</option>
                                        <option value="bank">{{ tr('Banco', 'Bank') }}</option>
                                    </select>
                                </div>
                                <div class="sm:col-span-2">
                                    <label class="text-sm font-medium text-gray-700">{{ tr('Fecha de liquidacion', 'Settlement date') }}</label>
                                    <input
                                        v-model="reimbursementForms[expense.id].reimbursement_date"
                                        type="date"
                                        class="mt-1 w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-red-500 focus:ring-red-500"
                                    />
                                </div>
                                <p class="sm:col-span-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs font-medium text-amber-800">
                                    {{ tr('Liquida el reembolso solo cuando la cuenta origen tenga el monto completo. No se registran reembolsos parciales.', 'Settle reimbursement only when the source account has the full amount. Partial reimbursements are not tracked.') }}
                                </p>
                                <p class="sm:col-span-2 rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 text-xs font-medium text-gray-700">
                                    {{ tr('Disponible en origen', 'Available in origin') }}: {{ formatMoney(reimbursementSourceBalance(expense)) }}
                                    · {{ tr('Balance de cuenta', 'Account balance') }}: {{ formatMoney(reimbursementSourceTotalBalance(expense)) }}
                                    · {{ tr('Reembolso completo', 'Full reimbursement') }}: {{ formatMoney(expense.amount) }}
                                </p>
                                <p v-if="reimbursementSettlementTransferAmount(expense) > 0" class="sm:col-span-2 rounded-lg border border-blue-200 bg-blue-50 px-3 py-2 text-xs font-medium text-blue-700">
                                    {{ tr('Se registrara transferencia interna hacia', 'Internal transfer will be recorded to') }} {{ locationLabel(reimbursementForms[expense.id].funds_location) }} {{ tr('por', 'for') }} {{ formatMoney(reimbursementSettlementTransferAmount(expense)) }}.
                                </p>
                                <p v-if="!canSettleReimbursement(expense)" class="sm:col-span-2 rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-xs font-medium text-rose-700">
                                    {{ tr('La cuenta seleccionada aun no tiene el monto completo para este reembolso.', 'The selected account does not yet have the full amount for this reimbursement.') }}
                                </p>
                            </div>

                            <div class="rounded-lg border border-gray-200 bg-gray-50 px-3 py-3">
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <label class="text-sm font-semibold text-gray-800">{{ tr('Comprobante de pago', 'Payment proof') }}</label>
                                    <a
                                        v-if="expense.reimbursement_payment_proof_url"
                                        :href="expense.reimbursement_payment_proof_url"
                                        target="_blank"
                                        rel="noopener"
                                        class="text-xs font-semibold text-gray-700 hover:underline"
                                    >
                                        {{ tr('Ver comprobante', 'View proof') }}
                                    </a>
                                </div>
                                <input
                                    type="file"
                                    accept=".jpg,.jpeg,.png,.webp,.pdf,image/jpeg,image/png,image/webp,application/pdf"
                                    class="mt-2 block w-full text-sm text-gray-700"
                                    @change="setReimbursementPaymentProofFile(expense.id, $event)"
                                />
                                <div v-if="expenseUploadProgressValue(expense.id) > 0" class="mt-2 space-y-1">
                                    <div class="h-2 overflow-hidden rounded-full bg-gray-100">
                                        <div
                                            class="h-full rounded-full bg-red-600 transition-all"
                                            :style="{ width: `${expenseUploadProgressValue(expense.id)}%` }"
                                        ></div>
                                    </div>
                                    <p class="text-xs text-gray-500">
                                        {{ tr('Subiendo', 'Uploading') }} {{ expenseUploadProgressValue(expense.id) }}%
                                    </p>
                                </div>
                                <p class="mt-2 text-xs text-gray-500">
                                    <template v-if="expense.status === 'pending_reimbursement'">
                                        {{ tr('Opcional: adjunta confirmacion de Zelle, transferencia o cheque al liquidar. En efectivo, el recibo firmado es la confirmacion principal.', 'Optional: attach Zelle, transfer, or check confirmation when settling. For cash, the signed receipt is the main confirmation.') }}
                                    </template>
                                    <template v-else>
                                        {{ tr('Usa este comprobante para evidenciar que el dinero salio por Zelle, transferencia o cheque. El recibo firmado confirma que la persona lo recibio.', 'Use this proof to show money was sent by Zelle, transfer, or check. The signed receipt confirms the person received it.') }}
                                    </template>
                                </p>
                                <div v-if="expense.status !== 'pending_reimbursement'" class="mt-3 flex flex-col gap-2 sm:flex-row">
                                    <button
                                        type="button"
                                        class="inline-flex min-h-10 flex-1 items-center justify-center gap-2 rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 disabled:opacity-60"
                                        :disabled="isExpenseActionBusy(expense.id)"
                                        @click="uploadReimbursementPaymentProof(expense)"
                                    >
                                        <ArrowUpTrayIcon class="h-4 w-4" />
                                        {{ expense.reimbursement_payment_proof_url ? tr('Reemplazar comprobante', 'Replace proof') : tr('Guardar comprobante', 'Save proof') }}
                                    </button>
                                    <button
                                        v-if="expense.reimbursement_payment_proof_url"
                                        type="button"
                                        class="inline-flex min-h-10 items-center justify-center gap-2 rounded-lg border border-rose-200 bg-white px-3 py-2 text-sm font-semibold text-rose-700 hover:bg-rose-50 disabled:opacity-60"
                                        :disabled="isExpenseActionBusy(expense.id)"
                                        @click="removeReimbursementPaymentProof(expense)"
                                    >
                                        <TrashIcon class="h-4 w-4" />
                                        {{ tr('Quitar', 'Remove') }}
                                    </button>
                                </div>
                            </div>

                            <button
                                v-if="expense.status === 'pending_reimbursement'"
                                type="button"
                                class="inline-flex min-h-10 w-full items-center justify-center gap-2 rounded-lg bg-amber-700 px-3 py-2 text-sm font-semibold text-white hover:bg-amber-800 disabled:opacity-60"
                                :disabled="isExpenseActionBusy(expense.id) || !canSettleReimbursement(expense)"
                                @click="reimburseExpense(expense)"
                            >
                                <ArrowPathIcon v-if="isExpenseActionBusy(expense.id)" class="h-4 w-4 animate-spin" />
                                <CheckCircleIcon v-else class="h-4 w-4" />
                                {{ tr('Liquidar reembolso', 'Settle reimbursement') }}
                            </button>
                            <p v-if="expenseActionError(expense.id)" class="text-xs text-rose-600">{{ expenseActionError(expense.id) }}</p>
                        </div>
                    </article>
                    </template>
                        </div>
                    </div>
                </div>
            </section>

            <section data-tour="cashbox-movements" class="rounded-lg border border-gray-200 bg-white shadow-sm">
                <div class="flex flex-col gap-3 border-b border-gray-200 p-4 lg:flex-row lg:items-center lg:justify-between">
                    <div>
                        <h3 class="text-base font-semibold text-gray-900">{{ tr('Movimientos', 'Movements') }}</h3>
                        <p class="text-sm text-gray-500">{{ tr('Aqui ingresos, gastos, transferencias, reembolsos, recibos y comprobantes', 'Here you will see income, expenses, transfers, reimbursements, receipts, and proofs.') }}</p>
                    </div>
                    <div data-tour="cashbox-movement-filters" class="flex flex-col gap-2 sm:flex-row">
                        <input
                            v-model="movementSearch"
                            type="search"
                            :placeholder="tr('Buscar ID o descripcion', 'Search ID or description')"
                            :aria-label="tr('Buscar movimientos por ID o descripcion', 'Search movements by ID or description')"
                            class="rounded-lg border-gray-300 text-sm shadow-sm focus:border-red-500 focus:ring-red-500"
                        />
                        <select
                            v-model="movementDomain"
                            :aria-label="tr('Filtrar movimientos', 'Filter movements')"
                            class="rounded-lg border-gray-300 text-sm shadow-sm focus:border-red-500 focus:ring-red-500"
                        >
                            <option value="all">{{ tr('Todos', 'All') }}</option>
                            <option value="income">{{ tr('Ingresos', 'Income') }}</option>
                            <option value="expense">{{ tr('Gastos', 'Expenses') }}</option>
                            <option value="transfer">{{ tr('Transferencias', 'Transfers') }}</option>
                        </select>
                        <select
                            v-model="movementSort"
                            :aria-label="tr('Ordenar movimientos', 'Sort movements')"
                            class="rounded-lg border-gray-300 text-sm shadow-sm focus:border-red-500 focus:ring-red-500"
                        >
                            <option value="date">{{ tr('Ordenar por fecha y hora', 'Sort by date and time') }}</option>
                            <option value="status">{{ tr('Ordenar por estado', 'Sort by status') }}</option>
                            <option value="id">{{ tr('Ordenar por ID', 'Sort by ID') }}</option>
                        </select>
                        <select
                            v-model.number="movementPageSize"
                            :aria-label="tr('Movimientos por pagina', 'Movements per page')"
                            class="rounded-lg border-gray-300 text-sm shadow-sm focus:border-red-500 focus:ring-red-500"
                        >
                            <option v-for="size in MOVEMENT_PAGE_SIZE_OPTIONS" :key="size" :value="size">
                                {{ size }} {{ tr('por pagina', 'per page') }}
                            </option>
                        </select>
                    </div>
                </div>

                <div v-if="loading" class="p-6 text-sm text-gray-500">{{ tr('Cargando caja...', 'Loading cashbox...') }}</div>

                <div v-else-if="recentMovements.length === 0" class="flex items-start gap-3 p-6 text-sm text-gray-500">
                    <ExclamationTriangleIcon class="h-5 w-5 shrink-0 text-amber-500" />
                    <span>{{ tr('No hay movimientos para mostrar.', 'There are no movements to show.') }}</span>
                </div>

                <div v-else class="divide-y divide-gray-100">
                    <article v-for="group in paginatedMovementGroups" :key="group.key" class="p-4">
                        <template v-if="group.reimbursementGroup">
                            <button
                                type="button"
                                class="flex w-full items-start justify-between gap-3 rounded-lg border border-amber-200 bg-amber-50 px-3 py-3 text-left hover:bg-amber-100"
                                @click="toggleReimbursementMovementGroup(group)"
                            >
                                <span class="min-w-0">
                                    <span class="flex flex-wrap items-center gap-2">
                                        <ChevronDownIcon v-if="isReimbursementMovementGroupOpen(group)" class="h-4 w-4 shrink-0 text-amber-800" />
                                        <ChevronRightIcon v-else class="h-4 w-4 shrink-0 text-amber-800" />
                                        <span class="text-sm font-semibold text-amber-950">{{ movementGroupTitle(group) }}</span>
                                        <span class="rounded-full bg-white px-2 py-0.5 text-xs font-semibold text-amber-800 ring-1 ring-amber-200">{{ reimbursementGroupStatus(group) }}</span>
                                    </span>
                                    <span v-if="movementGroupSummary(group)" class="mt-1 block text-xs text-amber-800">{{ movementGroupSummary(group) }}</span>
                                    <span v-if="reimbursementGroupAccounts(group)" class="mt-1 block text-xs text-amber-700">
                                        {{ tr('Cuentas', 'Accounts') }}: {{ reimbursementGroupAccounts(group) }}
                                    </span>
                                </span>
                                <span class="shrink-0 text-right text-xs font-semibold text-amber-900">
                                    {{ movementGroupAmountSummary(group) }}
                                </span>
                            </button>

                            <div v-if="isReimbursementMovementGroupOpen(group)" class="mt-3 space-y-3">
                                <div class="rounded-lg border border-gray-200 bg-gray-50 px-3 py-3">
                                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ tr('Vista contable', 'Accounting view') }}</p>
                                    <div class="mt-2 grid gap-2 lg:grid-cols-2">
                                        <div
                                            v-for="row in reimbursementAccountingRows(group)"
                                            :key="`${group.key}-${row.role}`"
                                            class="rounded-md border border-gray-200 bg-white px-3 py-2"
                                        >
                                            <div class="flex items-start justify-between gap-3">
                                                <div class="min-w-0">
                                                    <div class="flex flex-wrap items-center gap-2">
                                                        <p class="text-sm font-semibold text-gray-900">{{ row.title }}</p>
                                                        <MovementInlineEditor
                                                            v-if="!tutorialActive"
                                                            :movement="row.movement"
                                                            :club-id="selectedClubId"
                                                            compact
                                                            input-focus-class="focus:border-blue-500 focus:ring-blue-500"
                                                            panel-class="basis-full"
                                                            @updated="handleMovementEditUpdated"
                                                        />
                                                    </div>
                                                    <p class="mt-1 text-xs font-medium text-gray-700">{{ movementDisplayConcept(row.movement) }}</p>
                                                    <p v-if="row.movement.concept_override" class="mt-1 text-xs text-gray-500">
                                                        {{ tr('Original', 'Original') }}: {{ row.movement.original_concept || row.movement.concept }}
                                                    </p>
                                                    <p v-if="row.movement.notes" class="mt-1 text-xs text-gray-600">
                                                        {{ tr('Notas', 'Notes') }}: {{ row.movement.notes }}
                                                    </p>
                                                    <p class="mt-1 text-xs text-gray-500">
                                                        {{ row.movement.movement_id }} · {{ formatDate(row.movement.date) }} · {{ movementLocationDisplay(row.movement) }}
                                                    </p>
                                                    <p class="mt-1 text-xs text-gray-600">{{ movementAccountDisplay(row.movement) }}</p>
                                                </div>
                                                <p class="shrink-0 text-sm font-semibold" :class="row.movement.domain === 'expense' ? 'text-rose-700' : row.movement.domain === 'income' ? 'text-emerald-700' : 'text-blue-700'">
                                                    {{ movementAmountLabel(row.movement) }}
                                                </p>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="overflow-hidden rounded-lg border border-gray-200">
                                    <div class="grid gap-2 bg-gray-50 px-3 py-2 text-xs font-semibold uppercase tracking-wide text-gray-500 lg:grid-cols-[1fr_6rem_9rem_8rem_7rem_7rem_8rem]">
                                        <span>{{ tr('Movimiento', 'Movement') }}</span>
                                        <span>{{ tr('Rol', 'Role') }}</span>
                                        <span>{{ tr('Fecha', 'Date') }}</span>
                                        <span>{{ tr('Cuenta', 'Account') }}</span>
                                        <span>{{ tr('Ubicacion', 'Location') }}</span>
                                        <span>{{ tr('Monto', 'Amount') }}</span>
                                        <span>{{ tr('Monto firmado', 'Signed amount') }}</span>
                                    </div>
                                    <div
                                        v-for="movement in reimbursementGroupDetailRows(group)"
                                        :key="`${group.key}-${movement.movement_id}`"
                                        class="grid gap-2 border-t border-gray-100 px-3 py-2 text-sm lg:grid-cols-[1fr_6rem_9rem_8rem_7rem_7rem_8rem]"
                                    >
                                        <div class="min-w-0">
                                            <p class="font-semibold text-gray-900">{{ movement.movement_id }}</p>
                                            <p class="truncate text-xs text-gray-500">{{ movementDisplayConcept(movement) }}</p>
                                        </div>
                                        <div class="text-xs font-semibold text-gray-700">{{ reimbursementRoleLabel(movement.reimbursement_group?.role) }}</div>
                                        <div class="text-gray-600">{{ formatDateTime(movement.occurred_at || movement.created_at || movement.date) }}</div>
                                        <div class="break-words text-gray-600">{{ movementAccountDisplay(movement) }}</div>
                                        <div class="text-gray-600">{{ movementLocationDisplay(movement) }}</div>
                                        <div class="font-semibold" :class="movement.domain === 'expense' ? 'text-rose-700' : movement.domain === 'income' ? 'text-emerald-700' : 'text-blue-700'">
                                            {{ movementAmountLabel(movement) }}
                                        </div>
                                        <div class="text-gray-600">{{ formatMoney(movement.signed_amount || 0) }}</div>
                                    </div>
                                </div>
                            </div>
                        </template>

                        <template v-else>
                            <div class="space-y-3">
                                <div
                                    v-for="movement in group.movements"
                                    :key="movement.movement_id"
                                    class="grid gap-3 lg:grid-cols-[1fr_auto] lg:items-center"
                                >
                                    <div class="min-w-0">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <span class="rounded-full border px-2 py-1 text-xs font-semibold" :class="movementTone(movement)">
                                                {{ movement.domain }}
                                            </span>
                                            <span class="text-sm font-semibold text-gray-900">{{ movementDisplayConcept(movement) }}</span>
                                            <span class="text-xs text-gray-500">#{{ movement.movement_id }}</span>
                                            <MovementInlineEditor
                                                v-if="!tutorialActive"
                                                :movement="movement"
                                                :club-id="selectedClubId"
                                                compact
                                                input-focus-class="focus:border-blue-500 focus:ring-blue-500"
                                                panel-class="basis-full"
                                                @updated="handleMovementEditUpdated"
                                            />
                                        </div>
                                        <p v-if="movement.concept_override" class="mt-1 text-xs text-gray-500">
                                            {{ tr('Original', 'Original') }}: {{ movement.original_concept || movement.concept }}
                                        </p>
                                        <p v-if="movement.notes" class="mt-1 text-sm text-gray-600">
                                            {{ tr('Notas', 'Notes') }}: {{ movement.notes }}
                                        </p>
                                        <div class="mt-2 grid gap-1 text-sm text-gray-600 sm:grid-cols-2 lg:grid-cols-4">
                                            <span>{{ tr('Fecha y hora', 'Date and time') }}: {{ formatDateTime(movement.occurred_at || movement.created_at || movement.date) }}</span>
                                            <span>{{ tr('Cuenta', 'Account') }}: {{ movement.account_label || accountLabel(movement.account) }}</span>
                                            <span>{{ tr('Ubicacion', 'Location') }}: {{ locationLabel(movement.location) }}</span>
                                            <span>{{ tr('Estado', 'Status') }}: {{ movement.status || 'posted' }}</span>
                                        </div>
                                        <div v-if="movement.receipt || movement.proof" class="mt-2 flex flex-wrap gap-2 text-xs">
                                            <a
                                                v-if="movement.receipt?.url"
                                                :href="movement.receipt.url"
                                                target="_blank"
                                                class="font-semibold text-red-700 hover:underline"
                                            >
                                                {{ movement.receipt.number || tr('Recibo', 'Receipt') }}
                                            </a>
                                            <a
                                                v-if="movement.proof?.url"
                                                :href="movement.proof.url"
                                                target="_blank"
                                                class="font-semibold text-gray-700 hover:underline"
                                            >
                                                {{ movement.proof.name || tr('Comprobante', 'Proof') }}
                                            </a>
                                        </div>
                                    </div>
                                    <div class="text-left lg:text-right">
                                        <p
                                            class="text-lg font-semibold"
                                            :class="movement.domain === 'expense' ? 'text-rose-700' : movement.domain === 'income' ? 'text-emerald-700' : 'text-blue-700'"
                                        >
                                            {{ movementAmountLabel(movement) }}
                                        </p>
                                        <p class="text-xs text-gray-500">{{ movement.kind }}</p>
                                    </div>
                                </div>
                            </div>
                        </template>
                    </article>
                </div>

                <div
                    v-if="recentMovementGroups.length > movementPageSize"
                    class="flex flex-col gap-3 border-t border-gray-100 p-4 sm:flex-row sm:items-center sm:justify-between"
                >
                    <p class="text-sm text-gray-600">
                        {{ tr('Mostrando', 'Showing') }}
                        <span class="font-semibold text-gray-900">{{ movementPageStart }}-{{ movementPageEnd }}</span>
                        {{ tr('de', 'of') }}
                        <span class="font-semibold text-gray-900">{{ recentMovementGroups.length }}</span>
                        {{ tr('movimientos', 'movements') }}
                    </p>
                    <div class="flex items-center gap-2">
                        <button
                            type="button"
                            class="inline-flex min-h-10 items-center justify-center rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-50"
                            :disabled="movementPage <= 1"
                            @click="setMovementPage(movementPage - 1)"
                        >
                            {{ tr('Anterior', 'Previous') }}
                        </button>
                        <span class="rounded-lg bg-gray-50 px-3 py-2 text-sm font-semibold text-gray-700">
                            {{ tr('Pagina', 'Page') }} {{ movementPage }} / {{ movementPageCount }}
                        </span>
                        <button
                            type="button"
                            class="inline-flex min-h-10 items-center justify-center rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-50"
                            :disabled="movementPage >= movementPageCount"
                            @click="setMovementPage(movementPage + 1)"
                        >
                            {{ tr('Siguiente', 'Next') }}
                        </button>
                    </div>
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
                :aria-label="tr('Tutorial de caja', 'Cashbox tutorial')"
            >
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wide text-red-600">
                            {{ tr('Tutorial de caja', 'Cashbox tutorial') }} · {{ tutorialProgressLabel }}
                        </p>
                        <h3 class="mt-1 text-base font-semibold text-gray-950">{{ tutorialStep.title }}</h3>
                    </div>
                    <button
                        type="button"
                        class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg border border-gray-200 text-xl leading-none text-gray-500 hover:bg-gray-50"
                        :aria-label="tr('Salir del tutorial', 'Exit tutorial')"
                        @click="closeCajaTutorial"
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
                            @click="closeCajaTutorial"
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

        <div
            v-if="showReimbursementOverflowModal && expenseHasOverflow"
            class="fixed inset-0 z-50 flex items-end justify-center bg-black/40 p-0 sm:items-center sm:p-4"
            @click.self="closeReimbursementOverflowModal"
        >
            <form
                class="max-h-[92vh] w-full overflow-y-auto rounded-t-xl bg-white p-4 shadow-xl sm:max-w-2xl sm:rounded-xl sm:p-6"
                @submit.prevent="closeReimbursementOverflowModal"
            >
                <div class="flex items-start justify-between gap-4 border-b border-gray-200 pb-4">
                    <div>
                        <h3 class="text-lg font-semibold text-gray-900">{{ tr('Reembolsar excedente', 'Reimburse overflow') }}</h3>
                        <p class="mt-1 text-sm text-gray-500">
                            {{ tr('El gasto excede el balance total de la cuenta. Registra quien cubrio el excedente para dejar el reembolso pendiente.', 'This expense exceeds the account total balance. Register who covered the overflow so the reimbursement remains pending.') }}
                        </p>
                    </div>
                    <button
                        type="button"
                        class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-lg border border-gray-200 text-xl leading-none text-gray-500 hover:bg-gray-50"
                        @click="closeReimbursementOverflowModal"
                    >
                        ×
                    </button>
                </div>

                <div class="mt-4 grid gap-3 sm:grid-cols-3">
                    <div class="rounded-lg border border-gray-200 bg-gray-50 p-3">
                        <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ tr('Cuenta', 'Account') }}</p>
                        <p class="mt-1 text-sm font-semibold text-gray-900">{{ accountLabel(expenseForm.pay_to) }}</p>
                        <p class="mt-1 text-xs text-gray-500">{{ locationLabel(expenseForm.funds_location) }} · {{ formatMoney(expenseSelectedLocationBalance) }}</p>
                    </div>
                    <div class="rounded-lg border border-gray-200 bg-gray-50 p-3">
                        <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ tr('Balance de cuenta', 'Account balance') }}</p>
                        <p class="mt-1 text-sm font-semibold text-gray-900">{{ formatMoney(expenseSelectedAccountTotalBalance) }}</p>
                    </div>
                    <div class="rounded-lg border border-amber-200 bg-amber-50 p-3">
                        <p class="text-xs font-semibold uppercase tracking-wide text-amber-700">{{ tr('Excedente', 'Overflow') }}</p>
                        <p class="mt-1 text-sm font-semibold text-amber-900">{{ formatMoney(expenseOverflowAmount) }}</p>
                    </div>
                </div>

                <div class="mt-5">
                    <label class="text-sm font-medium text-gray-700">{{ tr('Reembolsar excedente a', 'Reimburse overflow to') }}</label>
                    <div class="mt-2 grid grid-cols-2 rounded-lg border border-gray-200 bg-gray-50 p-1">
                        <button
                            type="button"
                            class="rounded-md px-3 py-2 text-sm font-medium"
                            :class="expenseForm.reimbursement_target_mode === 'new' ? 'bg-white text-gray-950 shadow-sm' : 'text-gray-600'"
                            @click="expenseForm.reimbursement_target_mode = 'new'"
                        >
                            {{ tr('Registrar persona', 'Register person') }}
                        </button>
                        <button
                            type="button"
                            class="rounded-md px-3 py-2 text-sm font-medium disabled:text-gray-400"
                            :class="expenseForm.reimbursement_target_mode === 'existing' ? 'bg-white text-gray-950 shadow-sm' : 'text-gray-600'"
                            :disabled="!reimbursementPayeeOptions.length"
                            @click="expenseForm.reimbursement_target_mode = 'existing'"
                        >
                            {{ tr('Persona guardada', 'Saved person') }}
                        </button>
                    </div>

                    <select
                        v-if="expenseForm.reimbursement_target_mode === 'existing'"
                        v-model="expenseForm.reimbursement_payee_id"
                        class="mt-3 w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-red-500 focus:ring-red-500"
                    >
                        <option value="">{{ tr('Selecciona persona', 'Select person') }}</option>
                        <option v-for="payee in reimbursementPayeeOptions" :key="payee.value" :value="payee.value">{{ payee.label }}</option>
                    </select>

                    <div v-else class="mt-3 grid gap-3 sm:grid-cols-3">
                        <div>
                            <label class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ tr('Nombre', 'Name') }}</label>
                            <input
                                v-model="expenseForm.reimbursement_payee_name"
                                type="text"
                                class="mt-1 w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-red-500 focus:ring-red-500"
                                :placeholder="tr('Nombre', 'Name')"
                            />
                        </div>
                        <div>
                            <label class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ tr('Telefono', 'Phone') }}</label>
                            <input
                                v-model="expenseForm.reimbursement_payee_phone"
                                type="tel"
                                class="mt-1 w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-red-500 focus:ring-red-500"
                                :placeholder="tr('Telefono', 'Phone')"
                            />
                        </div>
                        <div>
                            <label class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ tr('Correo', 'Email') }}</label>
                            <input
                                v-model="expenseForm.reimbursement_payee_email"
                                type="email"
                                class="mt-1 w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-red-500 focus:ring-red-500"
                                placeholder="email@example.com"
                            />
                        </div>
                    </div>

                    <p v-if="reimbursementTargetError" class="mt-2 text-xs text-rose-600">
                        {{ reimbursementTargetError }}
                    </p>
                </div>

                <div class="mt-5 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                    <button
                        type="button"
                        class="inline-flex min-h-10 items-center justify-center rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50"
                        @click="closeReimbursementOverflowModal"
                    >
                        {{ tr('Cerrar', 'Close') }}
                    </button>
                    <button
                        type="submit"
                        class="inline-flex min-h-10 items-center justify-center rounded-lg bg-amber-700 px-4 py-2 text-sm font-semibold text-white hover:bg-amber-800"
                    >
                        {{ tr('Guardar receptor', 'Save recipient') }}
                    </button>
                </div>
            </form>
        </div>

        <div
            v-if="showConceptModal"
            class="fixed inset-0 z-50 flex items-end justify-center bg-black/40 p-0 sm:items-center sm:p-4"
            @click.self="closeConceptModal"
        >
            <form
                class="max-h-[92vh] w-full overflow-y-auto rounded-t-xl bg-white p-4 shadow-xl sm:max-w-2xl sm:rounded-xl sm:p-6"
                @submit.prevent="submitConcept"
            >
                <div class="flex items-start justify-between gap-4 border-b border-gray-200 pb-4">
                    <div>
                        <h3 class="text-lg font-semibold text-gray-900">{{ tr('Crear concepto nuevo', 'Create new concept') }}</h3>
                        <p class="mt-1 text-sm text-gray-500">
                            {{ tr('El concepto se crea activo y disponible para todo el club seleccionado.', 'The concept is created active and available to the selected whole club.') }}
                        </p>
                    </div>
                    <button
                        type="button"
                        class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-lg border border-gray-200 text-xl leading-none text-gray-500 hover:bg-gray-50"
                        @click="closeConceptModal"
                    >
                        ×
                    </button>
                </div>

                <div class="mt-4 grid gap-4 sm:grid-cols-2">
                    <div v-if="firstError(conceptErrors, 'club_id')" class="sm:col-span-2 rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700">
                        {{ firstError(conceptErrors, 'club_id') }}
                    </div>

                    <div class="sm:col-span-2">
                        <label class="text-sm font-medium text-gray-700">{{ tr('Nombre del concepto', 'Concept name') }}</label>
                        <input
                            v-model="conceptForm.concept"
                            type="text"
                            class="mt-1 w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-red-500 focus:ring-red-500"
                            :placeholder="tr('Ej. Cuota mensual', 'Example: Monthly dues')"
                        />
                        <p v-if="firstError(conceptErrors, 'concept')" class="mt-1 text-xs text-rose-600">{{ firstError(conceptErrors, 'concept') }}</p>
                    </div>

                    <div>
                        <label class="text-sm font-medium text-gray-700">{{ tr('Monto esperado', 'Expected amount') }}</label>
                        <input
                            v-model="conceptForm.amount"
                            type="number"
                            min="0"
                            step="0.01"
                            class="mt-1 w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-red-500 focus:ring-red-500"
                        />
                        <p v-if="firstError(conceptErrors, 'amount')" class="mt-1 text-xs text-rose-600">{{ firstError(conceptErrors, 'amount') }}</p>
                    </div>

                    <div v-if="!conceptForm.reusable">
                        <label class="text-sm font-medium text-gray-700">{{ tr('Fecha limite', 'Due date') }}</label>
                        <input
                            v-model="conceptForm.payment_expected_by"
                            type="date"
                            class="mt-1 w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-red-500 focus:ring-red-500"
                        />
                        <p v-if="firstError(conceptErrors, 'payment_expected_by')" class="mt-1 text-xs text-rose-600">{{ firstError(conceptErrors, 'payment_expected_by') }}</p>
                    </div>

                    <div>
                        <label class="text-sm font-medium text-gray-700">{{ tr('Tipo', 'Type') }}</label>
                        <select
                            v-model="conceptForm.type"
                            class="mt-1 w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-red-500 focus:ring-red-500"
                        >
                            <option value="mandatory">{{ tr('Obligatorio', 'Mandatory') }}</option>
                            <option value="optional">{{ tr('Opcional', 'Optional') }}</option>
                        </select>
                        <p v-if="firstError(conceptErrors, 'type')" class="mt-1 text-xs text-rose-600">{{ firstError(conceptErrors, 'type') }}</p>
                    </div>

                    <div>
                        <label class="text-sm font-medium text-gray-700">{{ tr('Cuenta destino', 'Destination account') }}</label>
                        <select
                            v-model="conceptForm.pay_to"
                            class="mt-1 w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-red-500 focus:ring-red-500"
                        >
                            <option v-for="account in operatingAccountOptions" :key="account.value" :value="account.value">{{ account.label }}</option>
                        </select>
                        <p v-if="firstError(conceptErrors, 'pay_to')" class="mt-1 text-xs text-rose-600">{{ firstError(conceptErrors, 'pay_to') }}</p>
                    </div>

                    <div>
                        <label class="text-sm font-medium text-gray-700">{{ tr('Alcance', 'Scope') }}</label>
                        <select
                            v-model="conceptForm.scope_type"
                            class="mt-1 w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-red-500 focus:ring-red-500"
                            @change="onConceptScopeChange"
                        >
                            <option v-for="scope in scopeTypeOptions" :key="scope.value" :value="scope.value">{{ scope.label }}</option>
                        </select>
                        <p v-if="firstError(conceptErrors, 'scopes.0.scope_type')" class="mt-1 text-xs text-rose-600">{{ firstError(conceptErrors, 'scopes.0.scope_type') }}</p>
                    </div>

                    <div v-if="conceptForm.scope_type === 'club_wide' || conceptForm.scope_type === 'staff_wide'">
                        <label class="text-sm font-medium text-gray-700">{{ tr('Club', 'Club') }}</label>
                        <div class="mt-1 rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 text-sm font-medium text-gray-700">
                            {{ activeClubName }}
                        </div>
                        <p v-if="firstError(conceptErrors, 'scopes.0.club_id')" class="mt-1 text-xs text-rose-600">{{ firstError(conceptErrors, 'scopes.0.club_id') }}</p>
                    </div>

                    <div v-if="conceptForm.scope_type === 'class'">
                        <label class="text-sm font-medium text-gray-700">{{ tr('Clase', 'Class') }}</label>
                        <select
                            v-model="conceptForm.class_id"
                            class="mt-1 w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-red-500 focus:ring-red-500"
                        >
                            <option value="">{{ tr('Seleccionar clase', 'Select class') }}</option>
                            <option v-for="clubClass in filteredClasses" :key="clubClass.id" :value="clubClass.id">
                                {{ clubClass.class_name }}
                            </option>
                        </select>
                        <p v-if="firstError(conceptErrors, 'scopes.0.class_id')" class="mt-1 text-xs text-rose-600">{{ firstError(conceptErrors, 'scopes.0.class_id') }}</p>
                        <p v-if="filteredClasses.length === 0" class="mt-1 text-xs text-amber-700">{{ tr('No hay clases disponibles para este club.', 'There are no classes available for this club.') }}</p>
                    </div>

                    <div v-if="conceptForm.scope_type === 'member'">
                        <label class="text-sm font-medium text-gray-700">{{ tr('Miembro', 'Member') }}</label>
                        <select
                            v-model="conceptForm.member_id"
                            class="mt-1 w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-red-500 focus:ring-red-500"
                        >
                            <option value="">{{ tr('Seleccionar miembro', 'Select member') }}</option>
                            <option v-for="member in filteredMembers" :key="member.id" :value="member.id">
                                {{ member.applicant_name || member.name || `#${member.id}` }}
                            </option>
                        </select>
                        <p v-if="firstError(conceptErrors, 'scopes.0.member_id')" class="mt-1 text-xs text-rose-600">{{ firstError(conceptErrors, 'scopes.0.member_id') }}</p>
                        <p v-if="filteredMembers.length === 0" class="mt-1 text-xs text-amber-700">{{ tr('No hay miembros disponibles para este club.', 'There are no members available for this club.') }}</p>
                    </div>

                    <div v-if="conceptForm.scope_type === 'staff'">
                        <label class="text-sm font-medium text-gray-700">{{ tr('Personal', 'Staff') }}</label>
                        <select
                            v-model="conceptForm.staff_id"
                            class="mt-1 w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-red-500 focus:ring-red-500"
                        >
                            <option value="">{{ tr('Seleccionar personal', 'Select staff') }}</option>
                            <option v-for="person in filteredStaff" :key="person.id" :value="person.id">
                                {{ person.name || person.email || `#${person.id}` }}
                            </option>
                        </select>
                        <p v-if="firstError(conceptErrors, 'scopes.0.staff_id')" class="mt-1 text-xs text-rose-600">{{ firstError(conceptErrors, 'scopes.0.staff_id') }}</p>
                        <p v-if="filteredStaff.length === 0" class="mt-1 text-xs text-amber-700">{{ tr('No hay personal disponible para este club.', 'There are no staff members available for this club.') }}</p>
                    </div>

                    <label class="sm:col-span-2 flex items-start gap-3 rounded-lg border border-gray-200 bg-gray-50 p-3 text-sm">
                        <input
                            v-model="conceptForm.reusable"
                            type="checkbox"
                            class="mt-0.5 rounded border-gray-300 text-red-600 focus:ring-red-500"
                            @change="onConceptReusableChange"
                        />
                        <span>
                            <span class="font-semibold text-gray-900">{{ tr('Concepto reutilizable', 'Reusable concept') }}</span>
                            <span class="block text-gray-500">
                                {{ tr('Usalo para cobros repetibles del mismo monto, como cuotas semanales o mensuales.', 'Use it for repeatable charges with the same amount, such as weekly or monthly dues.') }}
                            </span>
                        </span>
                    </label>
                </div>

                <div class="mt-5 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                    <button
                        type="button"
                        class="inline-flex min-h-10 items-center justify-center rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50"
                        @click="closeConceptModal"
                    >
                        {{ tr('Cancelar', 'Cancel') }}
                    </button>
                    <button
                        type="submit"
                        :disabled="savingConcept"
                        class="inline-flex min-h-10 items-center justify-center gap-2 rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800 disabled:opacity-60"
                    >
                        <ArrowPathIcon v-if="savingConcept" class="h-4 w-4 animate-spin" />
                        <CheckCircleIcon v-else class="h-4 w-4" />
                        {{ savingConcept ? tr('Creando...', 'Creating...') : tr('Crear concepto', 'Create concept') }}
                    </button>
                </div>
            </form>
        </div>
    </PathfinderLayout>
</template>
