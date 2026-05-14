<script setup>
import { computed, onMounted, ref } from 'vue'
import PathfinderLayout from '@/Layouts/PathfinderLayout.vue'
import {
    ArrowPathIcon,
    BanknotesIcon,
    CheckCircleIcon,
    CreditCardIcon,
    CurrencyDollarIcon,
    DocumentTextIcon,
    ExclamationTriangleIcon,
} from '@heroicons/vue/24/outline'
import {
    createFinanceEngineConcept,
    createFinanceEngineExpense,
    createFinanceEngineIncome,
    fetchFinanceEngineCashbox,
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
const engineReport = ref(null)
const movementDomain = ref('all')
const incomeErrors = ref({})
const expenseErrors = ref({})
const conceptErrors = ref({})
const incomeCheckInput = ref(null)
const expenseReceiptInput = ref(null)
const showConceptModal = ref(false)
const savingConcept = ref(false)
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

const accountOptions = computed(() => {
    const rows = new Map()
    ;(accounts.value || [])
        .filter((account) => !selectedClubId.value || Number(account.club_id) === Number(selectedClubId.value))
        .forEach((account) => {
            rows.set(account.pay_to, {
                value: account.pay_to,
                label: account.label || account.pay_to,
            })
        })
    summaryAccounts.value.forEach((account) => {
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

const isEventConcept = (concept) => Boolean(concept?.event_id && concept?.event_fee_component_id)
const eventComponent = (concept) => concept?.event_fee_component || concept?.eventFeeComponent || null
const eventTitle = (concept) => concept?.event?.title || concept?.event_title || concept?.concept || 'Evento'
const conceptAmount = (concept) => Number(concept?.amount || 0)

const eventGroups = computed(() => {
    const groups = new Map()
    filteredConcepts.value.filter(isEventConcept).forEach((concept) => {
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
    const regular = filteredConcepts.value
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

const formatMoney = (value) => `$${Number(value || 0).toFixed(2)}`
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

const mergeAccounts = (paymentAccounts, expenseAccounts) => {
    const rows = new Map()
    ;[...(paymentAccounts || []), ...(expenseAccounts || [])].forEach((account) => {
        if (!account?.pay_to) return
        rows.set(`${account.club_id || selectedClubId.value}:${account.pay_to}`, account)
    })
    accounts.value = Array.from(rows.values())
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
        mergeAccounts(data.accounts || [], [])
        engineReport.value = data.engine_report || null

        if (!incomeForm.value.pay_to && accountOptions.value.length) {
            incomeForm.value.pay_to = accountOptions.value[0].value
        }
        if (!expenseForm.value.pay_to && accountOptions.value.length) {
            expenseForm.value.pay_to = accountOptions.value[0].value
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

    incomeForm.value.pay_to = option.pay_to || 'club_budget'
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
        pay_to: accountOptions.value[0]?.value || 'club_budget',
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
        pay_to: accountOptions.value[0]?.value || 'club_budget',
        funds_location: 'cash',
        amount: '',
        expense_date: today(),
        description: '',
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
        pay_to: incomeForm.value.pay_to || accountOptions.value[0]?.value || 'club_budget',
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
        await createFinanceEngineExpense({
            club_id: selectedClubId.value,
            ...expenseForm.value,
        })
        showToast(tr('Gasto guardado.', 'Expense saved.'), 'success')
        resetExpenseForm()
        await refreshCaja()
    } catch (error) {
        expenseErrors.value = normalizeErrors(error)
        showToast(error?.response?.data?.message || tr('No se pudo guardar el gasto.', 'Could not save expense.'), 'error')
        console.error(error)
    } finally {
        savingExpense.value = false
    }
}

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

            <section class="grid gap-3 sm:grid-cols-3">
                <div class="rounded-lg border border-gray-200 bg-white p-4">
                    <div class="flex items-center gap-2 text-sm font-semibold text-gray-700">
                        <BanknotesIcon class="h-5 w-5 text-emerald-600" />
                        {{ tr('Efectivo', 'Cash') }}
                    </div>
                    <p class="mt-3 text-2xl font-semibold text-gray-950">{{ formatMoney(summary.cash_balance) }}</p>
                </div>
                <div class="rounded-lg border border-gray-200 bg-white p-4">
                    <div class="flex items-center gap-2 text-sm font-semibold text-gray-700">
                        <CreditCardIcon class="h-5 w-5 text-blue-600" />
                        {{ tr('Banco', 'Bank') }}
                    </div>
                    <p class="mt-3 text-2xl font-semibold text-gray-950">{{ formatMoney(summary.bank_balance) }}</p>
                </div>
                <div class="rounded-lg border border-gray-200 bg-white p-4">
                    <div class="flex items-center gap-2 text-sm font-semibold text-gray-700">
                        <CheckCircleIcon class="h-5 w-5 text-gray-600" />
                        {{ tr('Disponible', 'Available') }}
                    </div>
                    <p class="mt-3 text-2xl font-semibold text-gray-950">{{ formatMoney(summary.total_available) }}</p>
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
                                <option v-for="account in accountOptions" :key="account.value" :value="account.value">{{ account.label }}</option>
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
                                <option v-for="account in accountOptions" :key="account.value" :value="account.value">{{ account.label }}</option>
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
                            <option v-for="account in accountOptions" :key="account.value" :value="account.value">{{ account.label }}</option>
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
