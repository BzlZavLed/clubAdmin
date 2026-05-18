<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import PathfinderLayout from '@/Layouts/PathfinderLayout.vue'
import {
    ArrowPathIcon,
    ArrowUpTrayIcon,
    BanknotesIcon,
    CheckCircleIcon,
    CreditCardIcon,
    CurrencyDollarIcon,
    DocumentTextIcon,
    ExclamationTriangleIcon,
    TrashIcon,
} from '@heroicons/vue/24/outline'
import {
    createFinanceEngineConcept,
    createFinanceEngineExpense,
    createFinanceEngineIncome,
    fetchFinanceEngineCashbox,
    reimburseFinanceEngineExpense,
    removeFinanceEngineExpenseReceipt,
    removeFinanceEngineReimbursementReceipt,
    uploadFinanceEngineExpenseReceipt,
    uploadFinanceEngineReimbursementReceipt,
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
const balanceAccountFilter = ref('all')
const incomeErrors = ref({})
const expenseErrors = ref({})
const conceptErrors = ref({})
const incomeCheckInput = ref(null)
const expenseReceiptInput = ref(null)
const expenseReceiptFiles = ref({})
const reimbursementReceiptFiles = ref({})
const reimbursementForms = ref({})
const expenseActionBusy = ref({})
const expenseActionErrors = ref({})
const showConceptModal = ref(false)
const savingConcept = ref(false)
const showReimbursementOverflowModal = ref(false)
const CREATE_CONCEPT_OPTION = '__create_concept__'
const CUSTOM_PAYER_OPTION = '__custom_payer__'

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
const recentMovements = computed(() => {
    if (movementDomain.value === 'all') return allMovements.value
    return allMovements.value.filter((movement) => movement.domain === movementDomain.value)
})

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
    ...summaryAccounts.value.map((account) => ({
        value: account.account,
        label: accountOptions.value.find((option) => option.value === account.account)?.label || account.account,
    })),
])
const selectedBalanceAccountSummary = computed(() => {
    if (balanceAccountFilter.value === 'all') return null

    return summaryAccounts.value.find((account) => account.account === balanceAccountFilter.value) || null
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
        cash_balance: Number(summary.value.cash_balance || 0),
        bank_balance: Number(summary.value.bank_balance || 0),
        total_available: Number(summary.value.total_available ?? (Number(summary.value.cash_balance || 0) + Number(summary.value.bank_balance || 0))),
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
const expenseAmount = computed(() => Number(expenseForm.value.amount || 0))
const expenseOverflowAmount = computed(() => Math.max(expenseAmount.value - expenseSelectedLocationBalance.value, 0))
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
const hasExpenseFollowUp = computed(() => regularExpenseRows.value.length > 0 || reimbursementExpenseRows.value.length > 0)

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
const formatDate = (value) => value ? String(value).slice(0, 10) : '—'
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
const normalizeErrors = (error) => {
    const errors = error?.response?.data?.errors || {}
    return Object.fromEntries(Object.entries(errors).map(([key, value]) => [key, Array.isArray(value) ? value[0] : value]))
}
const firstError = (errors, key) => errors?.[key] || null
const reimbursementTargetError = computed(() =>
    firstError(expenseErrors.value, 'reimbursement_payee_id')
    || firstError(expenseErrors.value, 'reimbursement_payee_name')
    || firstError(expenseErrors.value, 'reimbursement_payee_email')
    || firstError(expenseErrors.value, 'reimbursed_to')
)
const expenseActionError = (expenseId) => expenseActionErrors.value[expenseId] || null
const isExpenseActionBusy = (expenseId) => Boolean(expenseActionBusy.value[expenseId])
const defaultOperatingPayTo = () => operatingAccountOptions.value[0]?.value || 'club_budget'
const accountLocationBalance = (payTo, fundsLocation = 'cash') => {
    const row = summaryAccounts.value.find((account) => account.account === payTo)

    return Math.max(Number(row?.[`${fundsLocation || 'cash'}_balance`] || 0), 0)
}
const reimbursementSourceBalance = (expense) => {
    const form = reimbursementForms.value[expense.id] || {}

    return accountLocationBalance(form.pay_to, form.funds_location || 'cash')
}
const canSettleReimbursement = (expense) =>
    reimbursementSourceBalance(expense) + 0.0001 >= Number(expense.amount || 0)

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
    expenseReceiptFiles.value = {
        ...expenseReceiptFiles.value,
        [expenseId]: event.target.files?.[0] || null,
    }
}

const setReimbursementReceiptFile = (expenseId, event) => {
    reimbursementReceiptFiles.value = {
        ...reimbursementReceiptFiles.value,
        [expenseId]: event.target.files?.[0] || null,
    }
}

const loadCaja = async (clubId = null, quiet = false) => {
    if (quiet) refreshing.value = true
    else loading.value = true
    loadError.value = ''

    try {
        const payload = await fetchFinanceEngineCashbox(clubId)
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

const refreshCaja = () => loadCaja(selectedClubId.value, true)
const onClubChange = () => {
    incomeForm.value.concept_key = ''
    incomeForm.value.payer_key = ''
    incomeForm.value.payer_name = ''
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
    incomeForm.value.check_image = event.target.files?.[0] || null
}

const onExpenseReceipt = (event) => {
    expenseForm.value.receipt_image = event.target.files?.[0] || null
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

const submitConcept = async () => {
    savingConcept.value = true
    conceptErrors.value = {}

    try {
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

const actionErrorMessage = (error, fallback) =>
    error?.response?.data?.message || Object.values(normalizeErrors(error)).find(Boolean) || fallback

const uploadExpenseReceipt = async (expense) => {
    const file = expenseReceiptFiles.value[expense.id]
    if (!file) {
        setExpenseActionError(expense.id, tr('Selecciona un comprobante.', 'Select a proof image.'))
        return
    }

    setExpenseActionBusy(expense.id, true)
    setExpenseActionError(expense.id)

    try {
        await uploadFinanceEngineExpenseReceipt(expense.id, { receipt_image: file })
        expenseReceiptFiles.value = { ...expenseReceiptFiles.value, [expense.id]: null }
        showToast(tr('Comprobante guardado.', 'Proof saved.'), 'success')
        await refreshCaja()
    } catch (error) {
        setExpenseActionError(expense.id, actionErrorMessage(error, tr('No se pudo guardar el comprobante.', 'Could not save proof.')))
        console.error(error)
    } finally {
        setExpenseActionBusy(expense.id, false)
    }
}

const removeExpenseReceipt = async (expense) => {
    setExpenseActionBusy(expense.id, true)
    setExpenseActionError(expense.id)

    try {
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

const uploadReimbursementReceipt = async (expense) => {
    const file = reimbursementReceiptFiles.value[expense.id]
    if (!file) {
        setExpenseActionError(expense.id, tr('Selecciona un comprobante de reembolso.', 'Select a reimbursement proof image.'))
        return
    }

    setExpenseActionBusy(expense.id, true)
    setExpenseActionError(expense.id)

    try {
        await uploadFinanceEngineReimbursementReceipt(expense.id, { receipt_image: file })
        reimbursementReceiptFiles.value = { ...reimbursementReceiptFiles.value, [expense.id]: null }
        showToast(tr('Comprobante de reembolso guardado.', 'Reimbursement proof saved.'), 'success')
        await refreshCaja()
    } catch (error) {
        setExpenseActionError(expense.id, actionErrorMessage(error, tr('No se pudo guardar el comprobante de reembolso.', 'Could not save reimbursement proof.')))
        console.error(error)
    } finally {
        setExpenseActionBusy(expense.id, false)
    }
}

const removeReimbursementReceipt = async (expense) => {
    setExpenseActionBusy(expense.id, true)
    setExpenseActionError(expense.id)

    try {
        await removeFinanceEngineReimbursementReceipt(expense.id)
        showToast(tr('Comprobante de reembolso removido.', 'Reimbursement proof removed.'), 'success')
        await refreshCaja()
    } catch (error) {
        setExpenseActionError(expense.id, actionErrorMessage(error, tr('No se pudo remover el comprobante de reembolso.', 'Could not remove reimbursement proof.')))
        console.error(error)
    } finally {
        setExpenseActionBusy(expense.id, false)
    }
}

const reimburseExpense = async (expense) => {
    const form = reimbursementForms.value[expense.id] || {}
    const file = reimbursementReceiptFiles.value[expense.id]

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
        await reimburseFinanceEngineExpense(expense.id, {
            pay_to: form.pay_to,
            funds_location: form.funds_location || 'cash',
            reimbursement_date: form.reimbursement_date || today(),
            receipt_image: file || null,
        })
        reimbursementReceiptFiles.value = { ...reimbursementReceiptFiles.value, [expense.id]: null }
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
    showReimbursementOverflowModal.value = hasOverflow
})

watch(summaryAccounts, (accounts) => {
    if (balanceAccountFilter.value !== 'all' && !accounts.some((account) => account.account === balanceAccountFilter.value)) {
        balanceAccountFilter.value = 'all'
    }
})

onMounted(() => loadCaja())
</script>

<template>
    <PathfinderLayout>
        <template #title>{{ tr('Caja', 'Cashbox') }}</template>

        <div class="space-y-5">
            <section class="border-b border-gray-200 pb-4">
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
                            @change="onClubChange"
                            class="w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-red-500 focus:ring-red-500"
                        >
                            <option v-for="club in clubs" :key="club.id" :value="club.id">{{ club.club_name }}</option>
                        </select>
                        <div v-else class="rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm font-semibold text-gray-800">
                            {{ activeClubName }}
                        </div>
                    </div>
                </div>
            </section>

            <div v-if="loadError" class="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">
                {{ loadError }}
            </div>

            <section class="space-y-3">
                <div class="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        <h3 class="text-base font-semibold text-gray-900">{{ tr('Estado de cuenta', 'Account status') }}</h3>
                        <p class="mt-1 text-sm text-gray-500">{{ tr('Filtra los saldos por cuenta financiera.', 'Filter balances by finance account.') }}</p>
                    </div>
                    <div class="sm:min-w-72">
                        <label class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ tr('Cuenta', 'Account') }}</label>
                        <select
                            v-model="balanceAccountFilter"
                            class="mt-1 w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-red-500 focus:ring-red-500"
                        >
                            <option v-for="account in balanceAccountOptions" :key="account.value" :value="account.value">{{ account.label }}</option>
                        </select>
                    </div>
                </div>

                <div class="grid gap-3 sm:grid-cols-3">
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
                </div>
            </section>

            <section class="grid gap-5 xl:grid-cols-2">
                <form class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm" @submit.prevent="submitIncome">
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

                        <div>
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

                        <div v-if="incomeForm.payer_key === CUSTOM_PAYER_OPTION">
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

                        <div>
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
                                accept="image/*"
                                class="mt-1 block w-full text-sm text-gray-700"
                                @change="onIncomeCheckImage"
                            />
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
                        :disabled="savingIncome || loading"
                        class="mt-4 inline-flex min-h-10 w-full items-center justify-center gap-2 rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800 disabled:opacity-60 sm:w-auto"
                    >
                        <ArrowPathIcon v-if="savingIncome" class="h-4 w-4 animate-spin" />
                        <BanknotesIcon v-else class="h-4 w-4" />
                        {{ savingIncome ? tr('Guardando...', 'Saving...') : tr('Guardar ingreso', 'Save income') }}
                    </button>
                </form>

                <form class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm" @submit.prevent="submitExpense">
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

                        <div class="sm:col-span-2">
                            <label class="text-sm font-medium text-gray-700">{{ tr('Descripcion', 'Description') }}</label>
                            <textarea
                                v-model="expenseForm.description"
                                rows="3"
                                class="mt-1 w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-red-500 focus:ring-red-500"
                            ></textarea>
                        </div>

                        <div v-if="expenseHasOverflow" class="sm:col-span-2 rounded-lg border border-amber-200 bg-amber-50 p-3">
                            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                <div>
                                    <p class="text-sm font-semibold text-amber-900">{{ tr('Excedente detectado', 'Overflow detected') }}</p>
                                    <p class="mt-1 text-xs text-amber-800">
                                        {{ tr('Disponible', 'Available') }}: {{ formatMoney(expenseSelectedLocationBalance) }}
                                        · {{ tr('Excedente', 'Overflow') }}: {{ formatMoney(expenseOverflowAmount) }}
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

                        <div class="sm:col-span-2">
                            <label class="text-sm font-medium text-gray-700">{{ tr('Comprobante', 'Proof') }}</label>
                            <input
                                ref="expenseReceiptInput"
                                type="file"
                                accept="image/*"
                                class="mt-1 block w-full text-sm text-gray-700"
                                @change="onExpenseReceipt"
                            />
                        </div>
                    </div>

                    <button
                        type="submit"
                        :disabled="savingExpense || loading"
                        class="mt-4 inline-flex min-h-10 w-full items-center justify-center gap-2 rounded-lg bg-rose-700 px-4 py-2 text-sm font-semibold text-white hover:bg-rose-800 disabled:opacity-60 sm:w-auto"
                    >
                        <ArrowPathIcon v-if="savingExpense" class="h-4 w-4 animate-spin" />
                        <DocumentTextIcon v-else class="h-4 w-4" />
                        {{ savingExpense ? tr('Guardando...', 'Saving...') : tr('Guardar gasto', 'Save expense') }}
                    </button>
                </form>
            </section>

            <section v-if="hasExpenseFollowUp" class="rounded-lg border border-gray-200 bg-white shadow-sm">
                <div class="border-b border-gray-200 p-4">
                    <h3 class="text-base font-semibold text-gray-900">{{ tr('Seguimiento de gastos', 'Expense follow-up') }}</h3>
                    <p class="mt-1 text-sm text-gray-500">
                        {{ tr('Completa comprobantes pendientes y liquida reembolsos desde Caja.', 'Complete pending proofs and settle reimbursements from Cashbox.') }}
                    </p>
                </div>

                <div class="divide-y divide-gray-100">
                    <article v-for="expense in regularExpenseRows" :key="`expense-${expense.id}`" class="grid gap-4 p-4 lg:grid-cols-[minmax(0,1fr)_minmax(280px,360px)]">
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
                        </div>

                        <div class="space-y-2">
                            <input
                                type="file"
                                accept="image/*"
                                class="block w-full text-sm text-gray-700"
                                @change="setExpenseReceiptFile(expense.id, $event)"
                            />
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

                    <article v-for="expense in reimbursementExpenseRows" :key="`reimbursement-${expense.id}`" class="grid gap-4 p-4 lg:grid-cols-[minmax(0,1fr)_minmax(320px,420px)]">
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
                            <div class="mt-2 flex flex-wrap gap-3 text-sm">
                                <a v-if="expense.reimbursement_receipt_url" :href="expense.reimbursement_receipt_url" target="_blank" rel="noopener" class="font-semibold text-gray-700 hover:underline">
                                    {{ tr('Ver comprobante de reembolso', 'View reimbursement proof') }}
                                </a>
                                <span v-if="expense.settlement_expense" class="text-gray-500">
                                    {{ tr('Liquidado desde', 'Settled from') }} {{ accountLabel(expense.settlement_expense.pay_to) }}
                                </span>
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
                                    {{ tr('Disponible en cuenta origen', 'Available in source account') }}: {{ formatMoney(reimbursementSourceBalance(expense)) }}
                                    · {{ tr('Reembolso completo', 'Full reimbursement') }}: {{ formatMoney(expense.amount) }}
                                </p>
                                <p v-if="!canSettleReimbursement(expense)" class="sm:col-span-2 rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-xs font-medium text-rose-700">
                                    {{ tr('La cuenta seleccionada aun no tiene el monto completo para este reembolso.', 'The selected account does not yet have the full amount for this reimbursement.') }}
                                </p>
                            </div>

                            <input
                                type="file"
                                accept="image/*"
                                class="block w-full text-sm text-gray-700"
                                @change="setReimbursementReceiptFile(expense.id, $event)"
                            />
                            <p class="text-xs text-gray-500">
                                {{ tr('Comprobante opcional; puedes agregarlo luego.', 'Proof is optional; you can add it later.') }}
                            </p>
                            <div class="flex flex-col gap-2 sm:flex-row">
                                <button
                                    type="button"
                                    class="inline-flex min-h-10 flex-1 items-center justify-center gap-2 rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 disabled:opacity-60"
                                    :disabled="isExpenseActionBusy(expense.id)"
                                    @click="uploadReimbursementReceipt(expense)"
                                >
                                    <ArrowUpTrayIcon class="h-4 w-4" />
                                    {{ expense.reimbursement_receipt_url ? tr('Reemplazar comprobante', 'Replace proof') : tr('Guardar comprobante', 'Save proof') }}
                                </button>
                                <button
                                    v-if="expense.reimbursement_receipt_url"
                                    type="button"
                                    class="inline-flex min-h-10 items-center justify-center gap-2 rounded-lg border border-rose-200 bg-white px-3 py-2 text-sm font-semibold text-rose-700 hover:bg-rose-50 disabled:opacity-60"
                                    :disabled="isExpenseActionBusy(expense.id)"
                                    @click="removeReimbursementReceipt(expense)"
                                >
                                    <TrashIcon class="h-4 w-4" />
                                    {{ tr('Quitar', 'Remove') }}
                                </button>
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
                </div>
            </section>

            <section class="rounded-lg border border-gray-200 bg-white shadow-sm">
                <div class="flex flex-col gap-3 border-b border-gray-200 p-4 lg:flex-row lg:items-center lg:justify-between">
                    <div>
                        <h3 class="text-base font-semibold text-gray-900">{{ tr('Movimientos del motor financiero', 'Finance engine movements') }}</h3>
                        <p class="text-sm text-gray-500">{{ tr('Lectura normalizada de ingresos, gastos y transferencias.', 'Normalized readout of income, expenses, and transfers.') }}</p>
                    </div>
                    <div class="flex flex-col gap-2 sm:flex-row">
                        <select
                            v-model="movementDomain"
                            class="rounded-lg border-gray-300 text-sm shadow-sm focus:border-red-500 focus:ring-red-500"
                        >
                            <option value="all">{{ tr('Todos', 'All') }}</option>
                            <option value="income">{{ tr('Ingresos', 'Income') }}</option>
                            <option value="expense">{{ tr('Gastos', 'Expenses') }}</option>
                            <option value="transfer">{{ tr('Transferencias', 'Transfers') }}</option>
                        </select>
                        <button
                            type="button"
                            class="inline-flex min-h-10 items-center justify-center gap-2 rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50"
                            @click="refreshCaja"
                        >
                            <ArrowPathIcon class="h-4 w-4" :class="{ 'animate-spin': refreshing }" />
                            {{ tr('Actualizar', 'Refresh') }}
                        </button>
                    </div>
                </div>

                <div v-if="loading" class="p-6 text-sm text-gray-500">{{ tr('Cargando caja...', 'Loading cashbox...') }}</div>

                <div v-else-if="recentMovements.length === 0" class="flex items-start gap-3 p-6 text-sm text-gray-500">
                    <ExclamationTriangleIcon class="h-5 w-5 shrink-0 text-amber-500" />
                    <span>{{ tr('No hay movimientos para mostrar.', 'There are no movements to show.') }}</span>
                </div>

                <div v-else class="divide-y divide-gray-100">
                    <article v-for="movement in recentMovements" :key="movement.movement_id" class="grid gap-3 p-4 lg:grid-cols-[1fr_auto] lg:items-center">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="rounded-full border px-2 py-1 text-xs font-semibold" :class="movementTone(movement)">
                                    {{ movement.domain }}
                                </span>
                                <span class="text-sm font-semibold text-gray-900">{{ movement.concept || movement.kind }}</span>
                                <span class="text-xs text-gray-500">#{{ movement.movement_id }}</span>
                            </div>
                            <div class="mt-2 grid gap-1 text-sm text-gray-600 sm:grid-cols-2 lg:grid-cols-4">
                                <span>{{ tr('Fecha', 'Date') }}: {{ formatDate(movement.date) }}</span>
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
                            <p class="text-lg font-semibold" :class="movement.domain === 'expense' ? 'text-rose-700' : movement.domain === 'income' ? 'text-emerald-700' : 'text-blue-700'">
                                {{ movementAmountLabel(movement) }}
                            </p>
                            <p class="text-xs text-gray-500">{{ movement.kind }}</p>
                        </div>
                    </article>
                </div>
            </section>

            <section class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
                <h3 class="text-base font-semibold text-gray-900">{{ tr('Balances por cuenta', 'Balances by account') }}</h3>
                <div class="mt-3 grid gap-3 md:grid-cols-2">
                    <div v-for="account in summaryAccounts" :key="account.account" class="rounded-lg border border-gray-200 p-3">
                        <p class="font-semibold text-gray-900">{{ accountLabel(account.account) }}</p>
                        <div class="mt-2 grid grid-cols-3 gap-2 text-sm">
                            <div>
                                <p class="text-xs text-gray-500">{{ tr('Efectivo', 'Cash') }}</p>
                                <p class="font-semibold text-gray-800">{{ formatMoney(account.cash_balance) }}</p>
                            </div>
                            <div>
                                <p class="text-xs text-gray-500">{{ tr('Banco', 'Bank') }}</p>
                                <p class="font-semibold text-gray-800">{{ formatMoney(account.bank_balance) }}</p>
                            </div>
                            <div>
                                <p class="text-xs text-gray-500">{{ tr('Total', 'Total') }}</p>
                                <p class="font-semibold text-gray-800">{{ formatMoney(account.total_available) }}</p>
                            </div>
                        </div>
                    </div>
                </div>
            </section>
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
                            {{ tr('El gasto excede el saldo seleccionado. Registra quien cubrio el excedente para dejar el reembolso pendiente.', 'This expense exceeds the selected balance. Register who covered the overflow so the reimbursement remains pending.') }}
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
                        <p class="mt-1 text-xs text-gray-500">{{ locationLabel(expenseForm.funds_location) }}</p>
                    </div>
                    <div class="rounded-lg border border-gray-200 bg-gray-50 p-3">
                        <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ tr('Disponible', 'Available') }}</p>
                        <p class="mt-1 text-sm font-semibold text-gray-900">{{ formatMoney(expenseSelectedLocationBalance) }}</p>
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
