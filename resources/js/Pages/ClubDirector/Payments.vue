<script setup>
import PathfinderLayout from "@/Layouts/PathfinderLayout.vue";
import { ref, computed, watch, nextTick } from 'vue'
import { useForm, router, usePage } from '@inertiajs/vue3'
import {
    CreditCardIcon,
    UserIcon,
    CalendarDaysIcon,
    CurrencyDollarIcon,
    PhotoIcon,
    ArrowPathIcon,
    UserGroupIcon
} from '@heroicons/vue/24/outline'
import { createClubPayment, updateClubPayment, downloadBulkReceipts } from '@/Services/api'
import { useGeneral } from '@/Composables/useGeneral'
import { useLocale } from '@/Composables/useLocale'

const props = defineProps({
    auth_user: { type: Object, required: true },
    user: Object,
    club: { type: Object, required: true },
    clubs: { type: Array, default: () => [] },
    members: { type: Array, required: true },
    staff: { type: Array, required: true },
    concepts: { type: Array, required: true },
    accounts: { type: Array, default: () => [] },
    payments: { type: Array, required: true },
    pending_receipts: { type: Array, default: () => [] },
    pending_parent_transfers: { type: Array, default: () => [] },
    completed_payment_targets: { type: Array, default: () => [] },
    payment_totals: { type: Object, default: () => ({}) },
    payment_types: { type: Array, required: true },
    prefill: { type: Object, default: () => ({}) },
})
const inertiaPage = usePage()
const { showToast } = useGeneral()
const { tr } = useLocale()
const parentTransferError = computed(() => inertiaPage.props.errors?.parent_transfer || null)
const canSelectClub = computed(() => props.auth_user?.profile_type === 'superadmin')
const canEditPayments = computed(() => ['club_director', 'superadmin'].includes(props.auth_user?.profile_type))
const currentClubName = computed(() =>
    allowedClubs.value.find(c => Number(c.id) === Number(form.club_id))?.club_name
    || props.club?.club_name
    || '—'
)
const allowedClubs = computed(() => {
    const userClubId = props.auth_user?.club_id
        ? Number(props.auth_user.club_id)
        : (props.user?.club_id ? Number(props.user.club_id) : null)

    // Build base list from props.clubs; if empty, fall back to single club prop
    const baseClubs = Array.isArray(props.clubs) && props.clubs.length
        ? props.clubs
        : (props.club ? [props.club] : [])

    if (!userClubId) return baseClubs
    const filtered = baseClubs.filter(c => Number(c.id) === userClubId)
    return filtered.length ? filtered : baseClubs
})
const scopeLabel = (sc) => {
    if (!sc) return tr('Sin alcance', 'No scope')
    switch (sc.scope_type) {
        case 'club_wide': return `${tr('Todo el club', 'Whole club')} (${sc.club?.club_name ?? sc.club_id ?? '—'})`
        case 'class': return `${tr('Clase', 'Class')}: ${sc.class?.class_name ?? sc.class_id ?? '—'}`
        case 'staff_wide': return `${tr('Todo el personal', 'All staff')} (${sc.club?.club_name ?? sc.club_id ?? '—'})`
        case 'member': return `${tr('Miembro', 'Member')}: ${sc.member?.applicant_name ?? sc.member_id ?? '—'}`
        case 'staff': return `${tr('Personal', 'Staff')}: ${sc.staff?.name ?? sc.staff_id ?? '—'}`
        default: return tr('Alcance desconocido', 'Unknown scope')
    }
}

const formatISODateLocal = (val) => {
    if (!val) return '—'
    const [y, m, d] = String(val).slice(0, 10).split('-').map(Number)
    const dt = new Date(y, m - 1, d)
    return new Intl.DateTimeFormat(undefined, { year: 'numeric', month: 'short', day: '2-digit' }).format(dt)
}
const formatDateTimeLocal = (val) => {
    if (!val) return tr('Nunca', 'Never')
    const dt = new Date(val)
    if (Number.isNaN(dt.getTime())) return tr('Nunca', 'Never')
    return new Intl.DateTimeFormat(undefined, {
        year: 'numeric',
        month: 'short',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
    }).format(dt)
}

// Selection state
const selectedConceptId = ref(null)
const selectedScopeId = ref(null)
const selectedMemberId = ref(null)
const selectedStaffId = ref(null)

const filteredConcepts = computed(() => {
    if (!form.club_id) return props.concepts || []
    return (props.concepts || []).filter(c => Number(c.club_id) === Number(form.club_id))
})

const eventTitleForConcept = (concept) => concept?.event?.title || concept?.event_title || null
const isEventConcept = (concept) => Boolean(concept?.event_id && concept?.event_fee_component_id)
const isRequiredEventConcept = (concept) => Boolean(concept?.event_fee_component?.is_required ?? true)
const conceptAmount = (concept) => Number(concept?.amount ?? 0)
const eventConceptGroups = computed(() => {
    const groups = new Map()

    filteredConcepts.value
        .filter(isEventConcept)
        .forEach((concept) => {
            const key = `${concept.event_id}:${concept.club_id}`
            if (!groups.has(key)) {
                groups.set(key, {
                    id: concept.id,
                    concept: eventTitleForConcept(concept) || concept.concept,
                    amount: 0,
                    club_id: concept.club_id,
                    event_id: concept.event_id,
                    event_title: eventTitleForConcept(concept) || concept.concept,
                    is_event_bundle: true,
                    concepts: [],
                    scopes: concept.scopes || [],
                    payment_expected_by: concept.payment_expected_by,
                    reusable: false,
                })
            }

            const group = groups.get(key)
            group.concepts.push(concept)
            group.amount += conceptAmount(concept)
            group.id = group.concepts
                .slice()
                .sort((a, b) => Number(a.event_fee_component?.sort_order ?? 0) - Number(b.event_fee_component?.sort_order ?? 0) || Number(a.id) - Number(b.id))[0].id
            group.scopes = group.concepts[0]?.scopes || group.scopes
        })

    return Array.from(groups.values()).map((group) => ({
        ...group,
        amount: Number(group.amount.toFixed(2)),
        concepts: group.concepts.sort((a, b) => Number(a.event_fee_component?.sort_order ?? 0) - Number(b.event_fee_component?.sort_order ?? 0) || Number(a.id) - Number(b.id)),
    }))
})

const selectableConcepts = computed(() => {
    const groupedEventConceptIds = new Set(eventConceptGroups.value.flatMap(group => group.concepts.map(concept => Number(concept.id))))
    return [
        ...eventConceptGroups.value,
        ...filteredConcepts.value.filter(concept => !groupedEventConceptIds.has(Number(concept.id))),
    ]
})

const filteredMembers = computed(() => {
    if (!form.club_id) return props.members || []
    return (props.members || []).filter(m => Number(m.club_id) === Number(form.club_id))
})

const filteredStaff = computed(() => {
    if (!form.club_id) return props.staff || []
    return (props.staff || []).filter(s => Number(s.club_id) === Number(form.club_id))
})
const filteredAccounts = computed(() => {
    if (!form.club_id) return props.accounts || []
    return (props.accounts || []).filter(a => Number(a.club_id) === Number(form.club_id))
})

const selectedConcept = computed(() => selectableConcepts.value.find(c => Number(c.id) === Number(selectedConceptId.value)) || null)
const selectedEventComponentIds = ref([])
const selectedEventBundleConcepts = computed(() => selectedConcept.value?.is_event_bundle ? selectedConcept.value.concepts || [] : [])
const selectedEventComponentConcepts = computed(() => {
    const selectedIds = new Set(selectedEventComponentIds.value.map(id => Number(id)))
    return selectedEventBundleConcepts.value.filter(concept => selectedIds.has(Number(concept.id)))
})
const scopesForConcept = computed(() => selectedConcept.value?.scopes ?? [])
const selectedScope = computed(() => scopesForConcept.value.find(s => s.id === selectedScopeId.value) || null)
const selectedConceptExpected = computed(() => selectedConcept.value?.amount ?? '')
const customConceptMode = ref(false)
const customConceptText = ref('')
const customPayTo = ref(null)
const selectedPayeeKey = ref(null)
const prefillApplied = ref(false)
const prefillMemberId = ref(null)
const prefillStaffId = ref(null)

const buildPayeeKey = (type, id) => `${type}:${id}`
const buildCompletedTargetKey = (conceptId, type, id) => `${conceptId}|${type}|${id}`
const payeeLabelPrefix = {
    member: tr('Miembro', 'Member'),
    staff: tr('Personal', 'Staff'),
}
const completedPaymentTargetSet = computed(() => new Set(props.completed_payment_targets || []))
const paymentTotalsMap = computed(() => props.payment_totals || {})
const selectedConceptIsReusable = computed(() => Boolean(selectedConcept.value?.reusable))

const payeeOptions = computed(() => {
    const options = []
    const seenIds = new Set()
    const pushOption = (option) => {
        if (!option?.id) return
        if (!customConceptMode.value && !selectedConceptIsReusable.value && option.scopeId && selectedConceptId.value) {
            const conceptIdsToCheck = selectedEventBundleConcepts.value.length
                ? selectedEventBundleConcepts.value.map(concept => concept.id)
                : [selectedConceptId.value]
            const allSelectedConceptsComplete = conceptIdsToCheck.every((conceptId) => {
                const completionKey = buildCompletedTargetKey(conceptId, option.type, option.id)
                return completedPaymentTargetSet.value.has(completionKey)
            })
            if (allSelectedConceptsComplete) {
                return
            }
        }
        const dedupeKey = buildPayeeKey(option.type, option.id)
        if (seenIds.has(dedupeKey)) return
        seenIds.add(dedupeKey)
        options.push({
            ...option,
            key: dedupeKey,
            label: `${payeeLabelPrefix[option.type]}: ${option.name}`,
        })
    }

    if (customConceptMode.value) {
        filteredMembers.value.forEach((member) => {
            pushOption({
                type: 'member',
                id: Number(member.id),
                name: member.applicant_name,
                scopeId: null,
            })
        })
        filteredStaff.value.forEach((staff) => {
            pushOption({
                type: 'staff',
                id: Number(staff.id),
                name: staff.name,
                scopeId: null,
            })
        })
        return options
    }

    scopesForConcept.value.forEach((scope) => {
        switch (scope.scope_type) {
            case 'member': {
                const memberId = Number(scope.member_id || scope.member?.id)
                const member = filteredMembers.value.find((item) => Number(item.id) === memberId)
                if (member) {
                    pushOption({
                        type: 'member',
                        id: memberId,
                        name: member.applicant_name,
                        scopeId: scope.id,
                    })
                }
                break
            }
            case 'staff': {
                const staffId = Number(scope.staff_id || scope.staff?.id)
                const staff = filteredStaff.value.find((item) => Number(item.id) === staffId)
                if (staff) {
                    pushOption({
                        type: 'staff',
                        id: staffId,
                        name: staff.name,
                        scopeId: scope.id,
                    })
                }
                break
            }
            case 'staff_wide':
                filteredStaff.value.forEach((staff) => {
                    pushOption({
                        type: 'staff',
                        id: Number(staff.id),
                        name: staff.name,
                        scopeId: scope.id,
                    })
                })
                break
            case 'class': {
                const classId = Number(scope.class_id || scope.class?.id)
                filteredMembers.value
                    .filter((member) => {
                        const memberClassId = member.class_id ?? member.current_class?.id ?? member.club_class_id
                        return Number(memberClassId) === classId
                    })
                    .forEach((member) => {
                        pushOption({
                            type: 'member',
                            id: Number(member.id),
                            name: member.applicant_name,
                            scopeId: scope.id,
                        })
                    })
                break
            }
            case 'club_wide':
            default:
                filteredMembers.value.forEach((member) => {
                    pushOption({
                        type: 'member',
                        id: Number(member.id),
                        name: member.applicant_name,
                        scopeId: scope.id,
                    })
                })
                break
        }
    })

    return options
})

const selectedPayee = computed(() => payeeOptions.value.find(option => option.key === selectedPayeeKey.value) || null)
const paymentTotalForConcept = (concept) => {
    if (!concept?.id || !selectedPayee.value) return 0
    const key = buildCompletedTargetKey(concept.id, selectedPayee.value.type, selectedPayee.value.id)
    return Number(paymentTotalsMap.value[key] ?? 0)
}
const remainingForEventConcept = (concept) => Math.max(conceptAmount(concept) - paymentTotalForConcept(concept), 0)
const pendingRequiredEventConcepts = computed(() => selectedEventBundleConcepts.value.filter(concept => isRequiredEventConcept(concept) && remainingForEventConcept(concept) > 0))
const pendingEventConcepts = computed(() => selectedEventBundleConcepts.value.filter(concept => remainingForEventConcept(concept) > 0))
const defaultSelectedEventComponentIds = () => {
    const requiredPending = pendingRequiredEventConcepts.value
    return (requiredPending.length ? requiredPending : pendingEventConcepts.value).map(concept => concept.id)
}
const selectedPayeePaymentTotal = computed(() => {
    if (!selectedConceptId.value || !selectedPayee.value) return 0
    if (selectedEventBundleConcepts.value.length) {
        return selectedEventBundleConcepts.value.reduce((sum, concept) => sum + paymentTotalForConcept(concept), 0)
    }
    const key = buildCompletedTargetKey(selectedConceptId.value, selectedPayee.value.type, selectedPayee.value.id)
    return Number(paymentTotalsMap.value[key] ?? 0)
})
const selectedRemainingAmount = computed(() => {
    if (selectedConceptIsReusable.value) return null
    if (customConceptMode.value || form.payment_type === 'initial') return null
    if (selectedEventBundleConcepts.value.length) {
        return selectedEventComponentConcepts.value.reduce((sum, concept) => sum + remainingForEventConcept(concept), 0)
    }
    const expected = Number(selectedConceptExpected.value ?? 0)
    if (!Number.isFinite(expected) || expected <= 0) return null
    return Math.max(expected - selectedPayeePaymentTotal.value, 0)
})
const formMode = computed(() => customConceptMode.value ? 'manual' : 'concept')
const pageTitle = computed(() => tr('Ingresos', 'Income'))
const modeDescription = computed(() => customConceptMode.value
    ? tr('Registra un ingreso manual para una cuenta del club.', 'Record manual income for a club account.')
    : tr('Selecciona un concepto y el sistema calcula el pendiente por pagador.', 'Select a concept and the system calculates the pending amount per payer.')
)
const pendingReceiptGroups = computed(() => {
    const groups = new Map()

    ;(props.pending_receipts || []).forEach((receipt) => {
        const payerName = receipt.member_name || receipt.staff_name || tr('Sin nombre', 'No name')
        const payerType = receipt.member_name ? 'member' : (receipt.staff_name ? 'staff' : 'unknown')
        const key = `${payerType}:${payerName}`

        if (!groups.has(key)) {
            groups.set(key, {
                key,
                payer_name: payerName,
                payer_type: payerType,
                label: payerName,
                total_amount: 0,
                receipts: [],
            })
        }

        const group = groups.get(key)
        group.receipts.push(receipt)
        group.total_amount += Number(receipt.amount_paid || 0)
    })

    return Array.from(groups.values()).sort((a, b) => a.payer_name.localeCompare(b.payer_name))
})

const syncAmountToRemaining = () => {
    if (customConceptMode.value || form.payment_type === 'initial') return
    if (!selectedConceptId.value || !selectedPayeeKey.value) return
    if (selectedConceptIsReusable.value) {
        form.amount_paid = String(Number(selectedConceptExpected.value ?? 0).toFixed(2))
        return
    }
    if (selectedRemainingAmount.value === null || selectedRemainingAmount.value === undefined) return
    const currentAmount = Number(form.amount_paid)
    const remainingAmount = Number(selectedRemainingAmount.value)

    if (!Number.isFinite(currentAmount)) {
        form.amount_paid = String(remainingAmount.toFixed(2))
        return
    }

    if (currentAmount > remainingAmount) {
        form.amount_paid = String(remainingAmount.toFixed(2))
    }
}

// Form
const form = useForm({
    club_id: null,
    payment_concept_id: null,
    member_id: null,
    staff_id: null,
    amount_paid: '',
    payment_date: new Date().toISOString().slice(0, 10),
    payment_type: 'cash',
    zelle_phone: '',
    check_image: null,
    notes: '',
})

const editForm = useForm({
    amount_paid: '',
    payment_date: new Date().toISOString().slice(0, 10),
    payment_type: 'cash',
    zelle_phone: '',
    check_image: null,
    notes: '',
})
const editingPaymentId = ref(null)
const editCheckPreviewUrl = ref(null)
const createAmountNumber = computed(() => {
    if (form.amount_paid === '' || form.amount_paid === null) return null
    const parsed = Number(form.amount_paid)
    return Number.isFinite(parsed) ? parsed : null
})
const showCreateZeroWarning = computed(() => createAmountNumber.value !== null && createAmountNumber.value <= 0)
const editAmountNumber = computed(() => {
    if (editForm.amount_paid === '' || editForm.amount_paid === null) return null
    const parsed = Number(editForm.amount_paid)
    return Number.isFinite(parsed) ? parsed : null
})
const showEditZeroWarning = computed(() => editAmountNumber.value !== null && editAmountNumber.value <= 0)
const editingPayment = computed(() => (props.payments || []).find(p => Number(p.id) === Number(editingPaymentId.value)) || null)

watch(selectedConceptId, (id) => {
    if (customConceptMode.value) return
    form.payment_concept_id = id ?? null
    if (!form.club_id && allowedClubs.value?.length) {
        form.club_id = allowedClubs.value[0].id
    }
    selectedScopeId.value = null
    selectedMemberId.value = null
    selectedStaffId.value = null
    selectedPayeeKey.value = null
    selectedEventComponentIds.value = defaultSelectedEventComponentIds()
    form.amount_paid = ''
})

watch(selectedMemberId, (id) => {
    form.member_id = id ?? null
})

watch(selectedStaffId, (id) => {
    form.staff_id = id ?? null
})

watch(selectedPayee, (payee) => {
    form.member_id = null
    form.staff_id = null
    selectedMemberId.value = null
    selectedStaffId.value = null
    if (!payee) {
        selectedScopeId.value = null
        return
    }

    selectedScopeId.value = payee.scopeId ?? selectedScopeId.value
    if (payee.type === 'member') {
        selectedMemberId.value = payee.id
    }
    if (payee.type === 'staff') {
        selectedStaffId.value = payee.id
    }
})

watch([selectedPayeeKey, selectedConceptId, paymentTotalsMap], () => {
    if (!selectedEventBundleConcepts.value.length) {
        selectedEventComponentIds.value = []
        return
    }

    selectedEventComponentIds.value = defaultSelectedEventComponentIds()
}, { immediate: true })

watch([selectedRemainingAmount, selectedPayeeKey, selectedConceptId, customConceptMode, () => form.payment_type], ([remaining, payeeKey, conceptId, isManual, paymentType]) => {
    if (isManual || paymentType === 'initial') return
    if (!conceptId || !payeeKey) {
        form.amount_paid = ''
        return
    }
    if (selectedConceptIsReusable.value) {
        form.amount_paid = String(Number(selectedConceptExpected.value ?? 0).toFixed(2))
        return
    }
    if (remaining === null || remaining === undefined) {
        form.amount_paid = ''
        return
    }
    form.amount_paid = String(Number(remaining).toFixed(2))
}, { immediate: true })

// Reset concept when club changes
watch(() => form.club_id, () => {
    if (customConceptMode.value && filteredAccounts.value.length) {
        customPayTo.value = filteredAccounts.value[0].pay_to
    }
    selectedConceptId.value = null
    selectedScopeId.value = null
    selectedMemberId.value = null
    selectedStaffId.value = null
    selectedPayeeKey.value = null
    form.payment_concept_id = null
    page.value = 1
    if (selectableConcepts.value.length) {
        selectedConceptId.value = selectableConcepts.value[0].id
        form.payment_concept_id = selectedConceptId.value
    }
})

// Default club selection
watch(allowedClubs, (val) => {
    if (!form.club_id && Array.isArray(val) && val.length) {
        form.club_id = val[0].id
    }
}, { immediate: true })

// Prefill club_id from initial props (explicit club or first in list)
if (!form.club_id) {
    form.club_id = props.club?.id ?? (allowedClubs.value?.[0]?.id ?? null)
}

const applyPrefill = () => {
    if (prefillApplied.value) return
    const prefill = props.prefill || {}
    if (!prefill.concept_id && !prefill.member_id && !prefill.staff_id) return

    if (prefill.club_id && allowedClubs.value.some(c => Number(c.id) === Number(prefill.club_id))) {
        form.club_id = Number(prefill.club_id)
    }

    const concept = (selectableConcepts.value || []).find(c => Number(c.id) === Number(prefill.concept_id))
        || eventConceptGroups.value.find(group => group.concepts.some(item => Number(item.id) === Number(prefill.concept_id)))
    if (concept) {
        selectedConceptId.value = concept.id
        form.payment_concept_id = concept.id
    }

    if (prefill.member_id) {
        prefillMemberId.value = Number(prefill.member_id)
    }
    if (prefill.staff_id) {
        prefillStaffId.value = Number(prefill.staff_id)
    }
    if (prefill.amount) {
        form.amount_paid = String(prefill.amount)
    }

    prefillApplied.value = true
    nextTick(() => {
        if (prefillMemberId.value) {
            selectedPayeeKey.value = buildPayeeKey('member', prefillMemberId.value)
        }
        if (prefillStaffId.value) {
            selectedPayeeKey.value = buildPayeeKey('staff', prefillStaffId.value)
        }
    })
}

watch([selectableConcepts, () => form.club_id], applyPrefill, { immediate: true })

watch(customConceptMode, (val) => {
    form.clearErrors()
    if (val) {
        selectedConceptId.value = null
        selectedScopeId.value = null
        form.payment_concept_id = null
        selectedMemberId.value = null
        selectedStaffId.value = null
        selectedPayeeKey.value = null
        customConceptText.value = ''
        customPayTo.value = filteredAccounts.value[0]?.pay_to ?? null
    }
})

watch(payeeOptions, (options) => {
    if (form.payment_type === 'initial') return
    if (!Array.isArray(options) || !options.length) {
        selectedPayeeKey.value = null
        return
    }
    const hasCurrent = options.some(option => option.key === selectedPayeeKey.value)
    if (!hasCurrent) {
        selectedPayeeKey.value = options[0].key
    }
}, { immediate: true })

// When club changes, reset selections and pick first concept for that club
watch(() => form.club_id, () => {
    selectedConceptId.value = selectableConcepts.value[0]?.id ?? null
    selectedScopeId.value = null
    selectedMemberId.value = null
    selectedStaffId.value = null
    selectedPayeeKey.value = null
    form.payment_concept_id = selectedConceptId.value
})

// Reset conditional fields when payment_type changes
watch(() => form.payment_type, (t) => {
    if (t !== 'zelle') form.zelle_phone = ''
    if (t !== 'check') form.check_image = null
    if (t === 'initial') {
        selectedMemberId.value = null
        selectedStaffId.value = null
        selectedPayeeKey.value = null
    }
})

watch(() => editForm.payment_type, (t) => {
    if (t !== 'zelle') editForm.zelle_phone = ''
    if (t !== 'check') {
        editForm.check_image = null
        editCheckPreviewUrl.value = editingPayment.value?.check_image_path ? `/storage/${editingPayment.value.check_image_path}` : null
    }
})

// File handling
const checkPreviewUrl = ref(null)
const onCheckFileChange = (e) => {
    const file = e.target.files?.[0]
    form.check_image = file || null
    checkPreviewUrl.value = file ? URL.createObjectURL(file) : null
}

const onEditCheckFileChange = (e) => {
    const file = e.target.files?.[0]
    editForm.check_image = file || null
    editCheckPreviewUrl.value = file ? URL.createObjectURL(file) : null
}

const reloadPaymentData = () => router.reload({
    only: ['payments', 'pending_receipts', 'pending_parent_transfers', 'completed_payment_targets', 'payment_totals', 'concepts'],
    preserveScroll: true,
})

const submitting = ref(false)
const bulkDownloadKey = ref(null)
const showPendingReceipts = ref(false)
const showPendingParentTransfers = ref(true)
const activeParentTransferAction = ref(null)

const slugifyLabel = (value) => String(value || 'payment-receipts')
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '')
    || 'payment-receipts'

const downloadReceiptGroup = async (group) => {
    bulkDownloadKey.value = group.key
    try {
        await downloadBulkReceipts(
            group.receipts.map(receipt => receipt.id),
            `recibos-${slugifyLabel(group.label)}`
        )
        window.setTimeout(() => {
            reloadPaymentData()
        }, 1500)
    } finally {
        bulkDownloadKey.value = null
    }
}

const approveParentTransfer = (transfer) => {
    activeParentTransferAction.value = `approve:${transfer.id}`
    router.post(route('club.director.payments.parent-transfers.approve', { submission: transfer.id }), {}, {
        preserveScroll: true,
        onFinish: () => {
            activeParentTransferAction.value = null
        },
    })
}

const rejectParentTransfer = (transfer) => {
    const reviewNotes = window.prompt(tr('Motivo del rechazo (opcional):', 'Rejection reason (optional):'), '') ?? ''
    activeParentTransferAction.value = `reject:${transfer.id}`
    router.post(route('club.director.payments.parent-transfers.reject', { submission: transfer.id }), {
        review_notes: reviewNotes,
    }, {
        preserveScroll: true,
        onFinish: () => {
            activeParentTransferAction.value = null
        },
    })
}

const submit = async () => {
    form.clearErrors()
    if (createAmountNumber.value !== null && createAmountNumber.value <= 0) {
        form.setError('amount_paid', tr('No se recomienda registrar pagos en 0.00. Corrige el monto antes de guardar.', 'Recording payments at 0.00 is not recommended. Correct the amount before saving.'))
        return
    }
    if (!form.club_id && props.clubs?.length) {
        form.club_id = props.clubs[0].id
    }
    if (!form.club_id) {
        form.setError('club_id', tr('Selecciona un club.', 'Select a club.'))
        return
    }
    if (customConceptMode.value || form.payment_type === 'initial') {
        if (!customConceptText.value) {
            customConceptText.value = tr('Saldo inicial', 'Opening balance')
        }
        if (form.payment_type !== 'initial') {
            if (!selectedPayee.value) {
                form.setError('member_id', tr('Selecciona un pagador.', 'Select a payer.'))
                return
            }
        }
    } else {
        if (!selectedPayee.value) {
            form.setError('payment_concept_id', tr('Selecciona un pagador valido para este concepto.', 'Select a valid payer for this concept.'))
            return
        }
        if (selectedEventBundleConcepts.value.length && !selectedEventComponentConcepts.value.length) {
            form.setError('payment_concept_id', tr('Selecciona al menos un componente del evento.', 'Select at least one event component.'))
            return
        }
        if (!selectedScopeId.value) {
            form.setError('payment_concept_id', tr('No se encontro un alcance valido para el pagador seleccionado.', 'No valid scope was found for the selected payer.'))
            return
        }
    }

    submitting.value = true
    try {
        const payload = { ...form.data() }
        if (customConceptMode.value || form.payment_type === 'initial') {
            payload.payment_concept_id = null
            payload.concept_text = customConceptText.value || tr('Saldo inicial', 'Opening balance')
            payload.pay_to = customPayTo.value || 'club_budget'
        } else if (selectedEventBundleConcepts.value.length) {
            payload.payment_concept_id = null
            payload.concept_text = selectedConcept.value?.event_title || selectedConcept.value?.concept || tr('Pago de evento', 'Event payment')
            payload.event_concept_ids = selectedEventComponentConcepts.value.map(concept => concept.id)
        }
        await createClubPayment(payload)
        showToast(tr('Ingreso guardado correctamente.', 'Income saved successfully.'), 'success')
        form.reset('amount_paid', 'notes', 'check_image', 'zelle_phone')
        if (customConceptMode.value) {
            customConceptText.value = ''
        }
        reloadPaymentData()
    } catch (err) {
        if (err?.response?.status === 422) {
            const errs = err.response.data.errors || {}
            if (Object.keys(errs).length) {
                Object.entries(errs).forEach(([field, messages]) => {
                    form.setError(field, Array.isArray(messages) ? messages[0] : messages)
                })
            } else if (err.response.data.message) {
                form.setError('form', err.response.data.message)
            }
        } else {
            console.error(err)
            form.setError('form', tr('Error inesperado. Intenta de nuevo.', 'Unexpected error. Try again.'))
        }
    } finally {
        submitting.value = false
    }
}

const startEditPayment = (payment) => {
    editingPaymentId.value = payment.id
    editCheckPreviewUrl.value = payment.check_image_path ? `/storage/${payment.check_image_path}` : null
    editForm.clearErrors()
    editForm.amount_paid = String(payment.amount_paid ?? '')
    editForm.payment_date = payment.payment_date ? String(payment.payment_date).slice(0, 10) : new Date().toISOString().slice(0, 10)
    editForm.payment_type = payment.payment_type || 'cash'
    editForm.zelle_phone = payment.zelle_phone || ''
    editForm.check_image = null
    editForm.notes = payment.notes || ''
}

const cancelEditPayment = () => {
    editingPaymentId.value = null
    editCheckPreviewUrl.value = null
    editForm.reset()
    editForm.clearErrors()
}

const submitEditPayment = async () => {
    if (!editingPaymentId.value) return
    editForm.clearErrors()
    if (editAmountNumber.value !== null && editAmountNumber.value <= 0) {
        editForm.setError('amount_paid', tr('No se recomienda registrar pagos en 0.00. Corrige el monto antes de guardar.', 'Recording payments at 0.00 is not recommended. Correct the amount before saving.'))
        return
    }

    try {
        await updateClubPayment(editingPaymentId.value, editForm.data())
        cancelEditPayment()
        reloadPaymentData()
    } catch (err) {
        if (err?.response?.status === 422) {
            const errs = err.response.data.errors || {}
            if (Object.keys(errs).length) {
                Object.entries(errs).forEach(([field, messages]) => {
                    editForm.setError(field, Array.isArray(messages) ? messages[0] : messages)
                })
            } else if (err.response.data.message) {
                editForm.setError('form', err.response.data.message)
            }
        } else {
            console.error(err)
            editForm.setError('form', tr('Error inesperado. Intenta de nuevo.', 'Unexpected error. Try again.'))
        }
    }
}

const downloadReceipt = (payment) => {
    const receiptId = payment?.receipt?.id
    if (!receiptId) return
    window.open(route('payment-receipts.download', receiptId), '_blank')
}

const paymentConceptLabel = (payment) =>
    payment?.event_title
    || payment?.concept?.event_title
    || payment?.concept?.concept
    || payment?.concept_text
    || '—'

// Searching/pagination of recent payments
const searchTerm = ref('')
const pageSize = ref(10)
const page = ref(1)

const filteredPayments = computed(() => {
    const q = (searchTerm.value || '').toLowerCase().trim()
    const clubFiltered = (props.payments || []).filter(p => {
        if (!form.club_id) return true
        return Number(p.club_id) === Number(form.club_id)
    })
    if (!q) return clubFiltered
    return clubFiltered.filter(p => {
        const name = (p.member_display_name ?? p.staff_display_name ?? '').toLowerCase()
        const concept = paymentConceptLabel(p).toLowerCase()
        return name.includes(q) || concept.includes(q)
    })
})

watch(searchTerm, () => { page.value = 1 })

const totalPages = computed(() => Math.max(1, Math.ceil(filteredPayments.value.length / pageSize.value)))
const startIdx = computed(() => (page.value - 1) * pageSize.value)
const endIdx = computed(() => Math.min(startIdx.value + pageSize.value, filteredPayments.value.length))
const pagedPayments = computed(() => filteredPayments.value.slice(startIdx.value, endIdx.value))

const go = (n) => { page.value = Math.min(totalPages.value, Math.max(1, n)) }
const setFormMode = (mode) => {
    form.clearErrors()
    customConceptMode.value = mode === 'manual'
}

</script>

<template>
    <PathfinderLayout>
        <div class="min-h-screen bg-white">
            <header class="px-4 pt-5 pb-3 sm:px-6">
                <div class="flex items-center gap-3">
                    <CreditCardIcon class="h-6 w-6 text-gray-700" />
                    <h1 class="text-lg font-semibold text-gray-900">{{ pageTitle }}</h1>
                </div>
                <div class="mt-2 flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
                    <div class="space-y-1">
                        <p class="text-sm text-gray-600">
                            {{ tr('Sesion iniciada como', 'Signed in as') }} <strong>{{ auth_user?.name }}</strong>
                        </p>
                        <p class="text-sm text-gray-700">
                            {{ tr('Club activo', 'Active club') }}: <strong>{{ currentClubName }}</strong>
                        </p>
                    </div>
                    <div v-if="canSelectClub" class="flex items-center gap-2 text-sm">
                        <label class="text-gray-700">{{ tr('Cambiar club', 'Change club') }}:</label>
                        <select v-model="form.club_id"
                            class="rounded border-gray-300 py-1 text-sm focus:border-blue-500 focus:ring-blue-500">
                            <option v-for="c in allowedClubs" :key="c.id" :value="c.id">{{ c.club_name }}</option>
                        </select>
                    </div>
                </div>
            </header>

            <main class="px-4 pb-24 sm:px-6">
                <section class="space-y-6">
                    <div class="rounded-2xl border border-gray-200 p-4 shadow-sm sm:p-5">
                        <div class="flex flex-col gap-4 border-b border-gray-200 pb-4 sm:flex-row sm:items-start sm:justify-between">
                            <div>
                                <h2 class="text-base font-semibold text-gray-900">{{ tr('Registrar ingreso', 'Record Income') }}</h2>
                                <p class="mt-0.5 text-sm text-gray-600">{{ modeDescription }}</p>
                            </div>
                            <div class="inline-flex rounded-xl border border-gray-200 bg-gray-50 p-1">
                                <button
                                    type="button"
                                    class="rounded-lg px-3 py-1.5 text-sm font-medium transition"
                                    :class="formMode === 'concept' ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-600 hover:text-gray-900'"
                                    @click="setFormMode('concept')"
                                >
                                    {{ tr('Por concepto', 'By concept') }}
                                </button>
                                <button
                                    type="button"
                                    class="rounded-lg px-3 py-1.5 text-sm font-medium transition"
                                    :class="formMode === 'manual' ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-600 hover:text-gray-900'"
                                    @click="setFormMode('manual')"
                                >
                                    {{ tr('Manual', 'Manual') }}
                                </button>
                            </div>
                        </div>

                        <div class="mt-5 grid grid-cols-1 gap-5 lg:grid-cols-2">
                            <div class="space-y-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700">
                                        {{ customConceptMode ? tr('Descripcion del ingreso', 'Income description') : tr('Concepto', 'Concept') }}
                                    </label>
                                    <select
                                        v-if="!customConceptMode"
                                        v-model="selectedConceptId"
                                        class="mt-1 w-full rounded-lg border-gray-300 py-2 text-sm focus:border-blue-500 focus:ring-blue-500"
                                    >
                                        <option :value="null" disabled>{{ tr('Selecciona un concepto...', 'Select a concept...') }}</option>
                                        <option v-for="c in selectableConcepts" :key="c.is_event_bundle ? `event-${c.event_id}-${c.club_id}` : c.id" :value="c.id">
                                            {{ c.is_event_bundle ? `● ${c.event_title}` : c.concept }} • {{ Number(c.amount ?? 0).toFixed(2) }}
                                        </option>
                                    </select>
                                    <input
                                        v-else
                                        v-model="customConceptText"
                                        type="text"
                                        class="mt-1 w-full rounded-lg border-gray-300 py-2 text-sm focus:border-blue-500 focus:ring-blue-500"
                                        :placeholder="tr('Ej. Actividad especial Sunbeams', 'Ex. Special Sunbeams activity')"
                                    />
                                    <div v-if="form.errors.payment_concept_id" class="mt-1 text-sm text-red-600">
                                        {{ form.errors.payment_concept_id }}
                                    </div>
                                    <div v-if="selectedEventBundleConcepts.length && !customConceptMode" class="mt-3 rounded-lg border border-blue-100 bg-blue-50 p-3">
                                        <div class="flex items-center justify-between gap-3">
                                            <div>
                                                <div class="text-xs font-semibold uppercase tracking-wide text-blue-700">{{ tr('Desglose del evento', 'Event breakdown') }}</div>
                                                <div class="mt-0.5 text-sm font-medium text-gray-900">{{ selectedConcept.event_title }}</div>
                                            </div>
                                            <div class="flex items-center gap-3">
                                                <button
                                                    type="button"
                                                    class="text-xs font-medium text-blue-700 hover:underline"
                                                    @click="selectedEventComponentIds = defaultSelectedEventComponentIds()"
                                                >
                                                    {{ tr('Marcar obligatorio', 'Mark required') }}
                                                </button>
                                                <button
                                                    type="button"
                                                    class="text-xs font-medium text-blue-700 hover:underline"
                                                    @click="selectedEventComponentIds = pendingEventConcepts.map(concept => concept.id)"
                                                >
                                                    {{ tr('Marcar todo', 'Mark all') }}
                                                </button>
                                            </div>
                                        </div>
                                        <div class="mt-3 space-y-2">
                                            <label
                                                v-for="component in selectedEventBundleConcepts"
                                                :key="component.id"
                                                class="flex items-start gap-3 rounded-md bg-white px-3 py-2 text-sm"
                                                :class="remainingForEventConcept(component) <= 0 ? 'opacity-60' : ''"
                                            >
                                                <input
                                                    v-model="selectedEventComponentIds"
                                                    type="checkbox"
                                                    class="mt-0.5 rounded border-gray-300 text-blue-600 focus:ring-blue-500"
                                                    :value="component.id"
                                                    :disabled="remainingForEventConcept(component) <= 0 || (isRequiredEventConcept(component) && remainingForEventConcept(component) > 0)"
                                                />
                                                <span class="flex-1">
                                                    <span class="flex flex-wrap items-center gap-2 font-medium text-gray-900">
                                                        <span>{{ component.event_fee_component?.label || component.concept }}</span>
                                                        <span class="rounded-full px-2 py-0.5 text-[11px] font-semibold" :class="isRequiredEventConcept(component) ? 'bg-emerald-50 text-emerald-700' : 'bg-blue-50 text-blue-700'">
                                                            {{ isRequiredEventConcept(component) ? tr('Obligatorio', 'Required') : tr('Opcional', 'Optional') }}
                                                        </span>
                                                    </span>
                                                    <span class="block text-xs text-gray-500">
                                                        {{ tr('Esperado', 'Expected') }} ${{ conceptAmount(component).toFixed(2) }} · {{ tr('Pagado', 'Paid') }} ${{ paymentTotalForConcept(component).toFixed(2) }} · {{ tr('Pendiente', 'Pending') }} ${{ remainingForEventConcept(component).toFixed(2) }}
                                                    </span>
                                                </span>
                                            </label>
                                        </div>
                                    </div>
                                </div>

                                <div v-if="customConceptMode">
                                    <label class="block text-sm font-medium text-gray-700">{{ tr('Cuenta', 'Account') }}</label>
                                    <select
                                        v-model="customPayTo"
                                        class="mt-1 w-full rounded-lg border-gray-300 py-2 text-sm focus:border-blue-500 focus:ring-blue-500"
                                    >
                                        <option v-for="a in filteredAccounts" :key="a.pay_to" :value="a.pay_to">{{ a.label }}</option>
                                    </select>
                                </div>

                                <div>
                                    <label class="block text-sm font-medium text-gray-700">{{ tr('Pagador', 'Payer') }}</label>
                                    <div v-if="form.payment_type === 'initial'" class="mt-1 text-xs text-gray-500">
                                        {{ tr('Saldo inicial no requiere pagador.', 'Opening balance does not require a payer.') }}
                                    </div>
                                    <template v-else>
                                        <select
                                            v-model="selectedPayeeKey"
                                            :disabled="!payeeOptions.length"
                                            class="mt-1 w-full rounded-lg border-gray-300 py-2 text-sm focus:border-blue-500 focus:ring-blue-500 disabled:bg-gray-50"
                                        >
                                            <option :value="null" disabled>{{ tr('Selecciona un pagador...', 'Select a payer...') }}</option>
                                            <option v-for="option in payeeOptions" :key="option.key" :value="option.key">
                                                {{ option.label }}
                                            </option>
                                        </select>
                                        <div v-if="selectedScope && !customConceptMode" class="mt-1 text-xs text-gray-500">
                                            {{ tr('Alcance aplicado', 'Applied scope') }}: {{ scopeLabel(selectedScope) }}
                                        </div>
                                        <div v-else-if="!payeeOptions.length" class="mt-1 text-xs text-amber-700">
                                            {{ tr('No hay pagadores disponibles para este concepto.', 'No payers are available for this concept.') }}
                                        </div>
                                        <div v-if="form.errors.member_id" class="mt-1 text-sm text-red-600">
                                            {{ form.errors.member_id }}
                                        </div>
                                        <div v-if="form.errors.staff_id" class="mt-1 text-sm text-red-600">
                                            {{ form.errors.staff_id }}
                                        </div>
                                    </template>
                                </div>

                                <div>
                                    <label class="block text-sm font-medium text-gray-700">{{ tr('Tipo de pago', 'Payment type') }}</label>
                                    <div class="mt-2 flex flex-wrap items-center gap-2">
                                        <label v-for="t in payment_types" :key="t" class="inline-flex items-center gap-2 rounded-lg border border-gray-200 px-3 py-2">
                                            <input type="radio" class="text-blue-600 focus:ring-blue-500" :value="t" v-model="form.payment_type" />
                                            <span class="capitalize text-sm text-gray-700">{{ t === 'initial' ? tr('Saldo inicial', 'Opening balance') : t }}</span>
                                        </label>
                                    </div>
                                    <div v-if="form.errors.payment_type" class="mt-1 text-sm text-red-600">
                                        {{ form.errors.payment_type }}
                                    </div>
                                </div>

                                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700">{{ tr('Importe', 'Amount') }}</label>
                                        <div class="mt-1 relative">
                                            <input
                                                v-model="form.amount_paid"
                                                type="number"
                                                step="0.01"
                                                min="0"
                                                @blur="syncAmountToRemaining"
                                                class="w-full rounded-lg border-gray-300 pl-9 pr-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500"
                                                placeholder="0.00"
                                            />
                                            <CurrencyDollarIcon class="pointer-events-none absolute left-2 top-1/2 h-5 w-5 -translate-y-1/2 text-gray-400" />
                                        </div>
                                        <div v-if="showCreateZeroWarning" class="mt-1 rounded border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800">
                                            {{ tr('Registrar pagos en 0.00 no es recomendable. Verifica el importe antes de guardar.', 'Recording payments at 0.00 is not recommended. Verify the amount before saving.') }}
                                        </div>
                                        <div v-if="selectedConceptIsReusable && !customConceptMode && form.payment_type !== 'initial'" class="mt-2">
                                            <span
                                                class="inline-flex items-center rounded-full bg-blue-100 px-3 py-1 text-xs font-medium text-blue-800"
                                                :title="tr('Cada registro debe cobrarse por el importe completo del concepto.', 'Each record must be charged for the full concept amount.')"
                                            >
                                                {{ tr('Concepto reutilizable', 'Reusable concept') }}
                                            </span>
                                        </div>
                                        <div v-if="form.errors.amount_paid" class="mt-1 text-sm text-red-600">
                                            {{ form.errors.amount_paid }}
                                        </div>
                                    </div>

                                    <div>
                                        <label class="block text-sm font-medium text-gray-700">{{ tr('Fecha', 'Date') }}</label>
                                        <div class="mt-1 relative">
                                            <input
                                                v-model="form.payment_date"
                                                type="date"
                                                class="w-full rounded-lg border-gray-300 pl-9 pr-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500"
                                            />
                                            <CalendarDaysIcon class="pointer-events-none absolute left-2 top-1/2 h-5 w-5 -translate-y-1/2 text-gray-400" />
                                        </div>
                                        <div v-if="form.errors.payment_date" class="mt-1 text-sm text-red-600">
                                            {{ form.errors.payment_date }}
                                        </div>
                                    </div>
                                </div>

                                <div>
                                    <label class="block text-sm font-medium text-gray-700">{{ tr('Notas', 'Notes') }}</label>
                                    <textarea
                                        v-model="form.notes"
                                        rows="3"
                                        class="mt-1 w-full rounded-lg border-gray-300 py-2 text-sm focus:border-blue-500 focus:ring-blue-500"
                                        :placeholder="tr('Observaciones sobre este ingreso...', 'Notes about this income...')"
                                    ></textarea>
                                </div>
                            </div>

                            <div class="space-y-4">
                                <div class="rounded-2xl border border-blue-100 bg-blue-50 p-4">
                                    <div class="text-xs font-semibold uppercase tracking-wide text-blue-700">{{ tr('Resumen', 'Summary') }}</div>
                                    <div class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2">
                                        <div>
                                            <div class="text-xs text-blue-700">{{ tr('Modo', 'Mode') }}</div>
                                            <div class="text-sm font-medium text-gray-900">{{ formMode === 'manual' ? tr('Ingreso manual', 'Manual income') : tr('Por concepto', 'By concept') }}</div>
                                        </div>
                                        <div>
                                            <div class="text-xs text-blue-700">{{ tr('Club', 'Club') }}</div>
                                            <div class="text-sm font-medium text-gray-900">{{ currentClubName }}</div>
                                        </div>
                                        <div v-if="selectedConcept && !customConceptMode">
                                            <div class="text-xs text-blue-700">{{ tr('Esperado', 'Expected') }}</div>
                                            <div class="text-sm font-medium text-gray-900">{{ selectedConceptExpected || '—' }}</div>
                                        </div>
                                        <div v-if="selectedConcept && !customConceptMode">
                                            <div class="text-xs text-blue-700">{{ tr('Reusar', 'Reusable') }}</div>
                                            <div class="text-sm font-medium text-gray-900">{{ selectedConceptIsReusable ? tr('Si', 'Yes') : tr('No', 'No') }}</div>
                                        </div>
                                        <div v-if="selectedRemainingAmount !== null">
                                            <div class="text-xs text-blue-700">{{ tr('Pendiente', 'Pending') }}</div>
                                            <div class="text-sm font-medium text-gray-900">${{ Number(selectedRemainingAmount).toFixed(2) }}</div>
                                        </div>
                                        <div v-if="selectedConcept && !customConceptMode">
                                            <div class="text-xs text-blue-700">{{ tr('Vence', 'Due') }}</div>
                                            <div class="text-sm font-medium text-gray-900">{{ formatISODateLocal(selectedConcept.payment_expected_by) }}</div>
                                        </div>
                                        <div v-if="selectedPayee && !customConceptMode">
                                            <div class="text-xs text-blue-700">{{ tr('Pagado acumulado', 'Total paid') }}</div>
                                            <div class="text-sm font-medium text-gray-900">${{ Number(selectedPayeePaymentTotal).toFixed(2) }}</div>
                                        </div>
                                    </div>
                                </div>

                                <div v-if="form.payment_type === 'zelle'">
                                    <label class="block text-sm font-medium text-gray-700">{{ tr('Teléfono Zelle del remitente', 'Sender Zelle phone') }}</label>
                                    <input
                                        v-model="form.zelle_phone"
                                        type="text"
                                        inputmode="tel"
                                        class="mt-1 w-full rounded-lg border-gray-300 py-2 text-sm focus:border-blue-500 focus:ring-blue-500"
                                        placeholder="(555) 555-5555"
                                    />
                                    <div class="mt-1 text-xs text-gray-500">
                                        {{ tr('La cuenta bancaria del club define el número receptor; aquí guarda el número desde donde se envió el dinero.', 'The club bank account defines the receiving number; store here the number where the money was sent from.') }}
                                    </div>
                                    <div v-if="form.errors.zelle_phone" class="mt-1 text-sm text-red-600">
                                        {{ form.errors.zelle_phone }}
                                    </div>
                                </div>

                                <div v-if="form.payment_type === 'check'">
                                    <label class="block text-sm font-medium text-gray-700">{{ tr('Foto del cheque', 'Check photo') }}</label>
                                    <div class="mt-1 flex items-center gap-3">
                                        <input
                                            type="file"
                                            accept="image/*"
                                            @change="onCheckFileChange"
                                            class="block w-full text-sm text-gray-700 file:mr-3 file:rounded-md file:border file:border-gray-300 file:bg-white file:px-3 file:py-2 file:text-sm file:font-medium hover:file:bg-gray-50"
                                        />
                                        <PhotoIcon v-if="!checkPreviewUrl" class="h-6 w-6 text-gray-400" />
                                        <img v-if="checkPreviewUrl" :src="checkPreviewUrl" alt="Check preview" class="h-10 w-auto rounded border" />
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="mt-5 flex items-center justify-end gap-3 border-t border-gray-200 pt-4">
                            <button
                                type="button"
                                @click="submit"
                                :disabled="submitting"
                                class="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-60"
                            >
                                <ArrowPathIcon v-if="submitting" class="h-4 w-4 animate-spin" />
                                <span>{{ submitting ? tr('Guardando...', 'Saving...') : tr('Guardar ingreso', 'Save income') }}</span>
                            </button>
                        </div>

                        <div v-if="form.hasErrors" class="mt-3 rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-800">
                            <ul class="list-disc list-inside">
                                <li v-for="(msg, key) in form.errors" :key="key">{{ msg }}</li>
                            </ul>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
                        <div class="rounded-2xl border border-gray-200 p-4 shadow-sm">
                            <h3 class="text-sm font-semibold text-gray-900">{{ tr('Guia rapida', 'Quick Guide') }}</h3>
                            <ul class="mt-3 space-y-2 text-sm text-gray-600">
                                <li>{{ tr('Usa', 'Use') }} <strong>{{ tr('Por concepto', 'By concept') }}</strong> {{ tr('para cuotas o cobros ya definidos.', 'for fees or charges already defined.') }}</li>
                                <li>{{ tr('Usa', 'Use') }} <strong>{{ tr('Manual', 'Manual') }}</strong> {{ tr('para ingresos extraordinarios.', 'for extraordinary income.') }}</li>
                                <li>{{ tr('Si el concepto ya fue cubierto por completo, ese pagador desaparece de la lista.', 'If the concept is fully covered, that payer disappears from the list.') }}</li>
                                <li>{{ tr('En pagos parciales puedes registrar menos del pendiente, pero no mas.', 'For partial payments you can record less than the pending amount, but not more.') }}</li>
                            </ul>
                        </div>

                        <div class="rounded-2xl border border-gray-200 p-4 shadow-sm">
                            <h3 class="text-sm font-semibold text-gray-900">{{ tr('Estado actual', 'Current Status') }}</h3>
                            <dl class="mt-3 space-y-3 text-sm">
                                <div class="flex items-center justify-between gap-3">
                                    <dt class="text-gray-500">{{ tr('Conceptos activos', 'Active concepts') }}</dt>
                                    <dd class="font-medium text-gray-900">{{ filteredConcepts.length }}</dd>
                                </div>
                                <div class="flex items-center justify-between gap-3">
                                    <dt class="text-gray-500">{{ tr('Pagadores disponibles', 'Available payers') }}</dt>
                                    <dd class="font-medium text-gray-900">{{ payeeOptions.length }}</dd>
                                </div>
                                <div class="flex items-center justify-between gap-3">
                                    <dt class="text-gray-500">{{ tr('Ingresos en esta vista', 'Income in this view') }}</dt>
                                    <dd class="font-medium text-gray-900">{{ filteredPayments.length }}</dd>
                                </div>
                            </dl>
                        </div>
                    </div>
                </section>

                <section class="mt-6 rounded-2xl border border-blue-200 bg-blue-50/40 p-4 shadow-sm">
                    <button
                        type="button"
                        class="flex w-full items-center justify-between gap-3 text-left"
                        @click="showPendingParentTransfers = !showPendingParentTransfers"
                    >
                        <div>
                            <h3 class="text-sm font-semibold text-gray-900">{{ tr('Transferencias pendientes de padres', 'Pending Parent Transfers') }}</h3>
                            <p class="mt-1 text-xs text-gray-600">{{ tr('Comprobantes enviados desde el portal de padres para validar fondos y generar el recibo.', 'Receipts sent from the parent portal to validate funds and generate the receipt.') }}</p>
                        </div>
                        <div class="flex items-center gap-3">
                            <div class="text-xs font-medium text-blue-800">
                                {{ props.pending_parent_transfers.length }} {{ tr('pendientes', 'pending') }}
                            </div>
                            <span class="text-xs font-semibold text-blue-900">
                                {{ showPendingParentTransfers ? tr('Ocultar', 'Hide') : tr('Mostrar', 'Show') }}
                            </span>
                        </div>
                    </button>

                    <div v-if="!props.pending_parent_transfers.length" class="mt-3 text-sm text-gray-600">
                        {{ tr('No hay transferencias pendientes de validar.', 'There are no pending transfers to validate.') }}
                    </div>

                    <div v-if="parentTransferError" class="mt-3 rounded-xl border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
                        {{ parentTransferError }}
                    </div>

                    <div v-if="props.pending_parent_transfers.length && showPendingParentTransfers" class="mt-4 overflow-x-auto">
                        <table class="min-w-full text-sm text-gray-700">
                            <thead class="bg-blue-50/70">
                                <tr>
                                    <th class="px-3 py-2 text-left font-semibold">{{ tr('Menor / padre', 'Child / parent') }}</th>
                                    <th class="px-3 py-2 text-left font-semibold">{{ tr('Concepto', 'Concept') }}</th>
                                    <th class="px-3 py-2 text-left font-semibold">{{ tr('Monto', 'Amount') }}</th>
                                    <th class="px-3 py-2 text-left font-semibold">{{ tr('Fecha', 'Date') }}</th>
                                    <th class="px-3 py-2 text-left font-semibold">{{ tr('Comprobante', 'Proof') }}</th>
                                    <th class="px-3 py-2 text-left font-semibold">{{ tr('Acciones', 'Actions') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="transfer in props.pending_parent_transfers" :key="transfer.id" class="border-t border-blue-200/70">
                                    <td class="px-3 py-2">
                                        <div class="font-medium text-gray-900">{{ transfer.member_name }}</div>
                                        <div class="text-xs text-gray-500">{{ transfer.parent_name }}<span v-if="transfer.parent_email"> • {{ transfer.parent_email }}</span></div>
                                    </td>
                                    <td class="px-3 py-2">
                                        <div>{{ transfer.concept_name || '—' }}</div>
                                        <div v-if="transfer.event_title" class="text-xs text-gray-500">{{ transfer.event_title }}</div>
                                        <div v-if="transfer.reference" class="text-xs text-gray-500">Ref. {{ transfer.reference }}</div>
                                    </td>
                                    <td class="px-3 py-2">
                                        <div class="font-medium text-gray-900">${{ Number(transfer.amount || 0).toFixed(2) }}</div>
                                        <div v-if="transfer.expected_amount !== null && transfer.expected_amount !== undefined" class="text-xs text-gray-500">
                                            {{ tr('Esperado', 'Expected') }}: ${{ Number(transfer.expected_amount || 0).toFixed(2) }}
                                        </div>
                                    </td>
                                    <td class="px-3 py-2">
                                        <div>{{ transfer.payment_date || '—' }}</div>
                                        <div class="text-xs text-gray-500">{{ formatDateTimeLocal(transfer.created_at) }}</div>
                                    </td>
                                    <td class="px-3 py-2">
                                        <a v-if="transfer.receipt_image_url" :href="transfer.receipt_image_url" target="_blank" rel="noopener" class="text-sm font-medium text-blue-600 hover:underline">
                                            {{ tr('Ver imagen', 'View image') }}
                                        </a>
                                        <div v-if="transfer.notes" class="mt-1 text-xs text-gray-500">{{ transfer.notes }}</div>
                                    </td>
                                    <td class="px-3 py-2">
                                        <div class="flex flex-wrap gap-2">
                                            <button
                                                type="button"
                                                class="rounded-lg bg-emerald-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-emerald-700 disabled:cursor-not-allowed disabled:opacity-60"
                                                :disabled="activeParentTransferAction === `approve:${transfer.id}` || activeParentTransferAction === `reject:${transfer.id}`"
                                                @click="approveParentTransfer(transfer)"
                                            >
                                                {{ activeParentTransferAction === `approve:${transfer.id}` ? tr('Aprobando...', 'Approving...') : tr('Aprobar', 'Approve') }}
                                            </button>
                                            <button
                                                type="button"
                                                class="rounded-lg bg-red-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-red-700 disabled:cursor-not-allowed disabled:opacity-60"
                                                :disabled="activeParentTransferAction === `approve:${transfer.id}` || activeParentTransferAction === `reject:${transfer.id}`"
                                                @click="rejectParentTransfer(transfer)"
                                            >
                                                {{ activeParentTransferAction === `reject:${transfer.id}` ? tr('Rechazando...', 'Rejecting...') : tr('Rechazar', 'Reject') }}
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div v-else-if="props.pending_parent_transfers.length" class="mt-3 text-sm text-blue-900">
                        {{ tr('La lista de transferencias pendientes esta colapsada.', 'The pending transfers list is collapsed.') }}
                    </div>
                </section>

                <section class="mt-6 rounded-2xl border border-amber-200 bg-amber-50/40 p-4 shadow-sm">
                    <button
                        type="button"
                        class="flex w-full items-center justify-between gap-3 text-left"
                        @click="showPendingReceipts = !showPendingReceipts"
                    >
                        <div>
                            <h3 class="text-sm font-semibold text-gray-900">{{ tr('Recibos pendientes de enviar', 'Receipts Pending Send') }}</h3>
                            <p class="mt-1 text-xs text-gray-600">{{ tr('Recibos que requieren seguimiento manual por miembro o falta de correo.', 'Receipts that require manual follow-up by member or missing email.') }}</p>
                        </div>
                        <div class="flex items-center gap-3">
                            <div class="text-xs font-medium text-amber-800">
                                {{ props.pending_receipts.length }} {{ tr('pendientes', 'pending') }}
                            </div>
                            <span class="text-xs font-semibold text-amber-900">
                                {{ showPendingReceipts ? tr('Ocultar', 'Hide') : tr('Mostrar', 'Show') }}
                            </span>
                        </div>
                    </button>

                    <div v-if="!props.pending_receipts.length" class="mt-3 text-sm text-gray-600">
                        {{ tr('No hay recibos pendientes de envio manual.', 'There are no receipts pending manual send.') }}
                    </div>

                    <div v-else-if="showPendingReceipts" class="mt-4 space-y-4">
                        <div
                            v-for="group in pendingReceiptGroups"
                            :key="group.key"
                            class="overflow-hidden rounded-2xl border border-amber-200/80 bg-white/80"
                        >
                            <div class="flex flex-col gap-3 border-b border-amber-200/70 px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
                                <div>
                                    <div class="font-semibold text-gray-900">{{ group.payer_name }}</div>
                                    <div class="mt-1 text-xs text-gray-500">
                                        {{ group.receipts.length }} recibo(s) pendientes · ${{ group.total_amount.toFixed(2) }}
                                    </div>
                                </div>
                                <button
                                    type="button"
                                    class="inline-flex items-center justify-center rounded-lg bg-amber-600 px-3 py-2 text-sm font-medium text-white transition hover:bg-amber-700 disabled:cursor-not-allowed disabled:opacity-60"
                                    :disabled="bulkDownloadKey === group.key"
                                    @click="downloadReceiptGroup(group)"
                                >
                                    {{ bulkDownloadKey === group.key ? tr('Preparando ZIP...', 'Preparing ZIP...') : tr('Descargar todos', 'Download all') }}
                                </button>
                            </div>

                            <div class="overflow-x-auto">
                                <table class="min-w-full text-sm text-gray-700">
                                    <thead class="bg-amber-50/60">
                                        <tr>
                                            <th class="px-3 py-2 text-left font-semibold">{{ tr('Recibo', 'Receipt') }}</th>
                                            <th class="px-3 py-2 text-left font-semibold">{{ tr('Concepto', 'Concept') }}</th>
                                            <th class="px-3 py-2 text-left font-semibold">{{ tr('Correo', 'Email') }}</th>
                                            <th class="px-3 py-2 text-left font-semibold">{{ tr('Motivo', 'Reason') }}</th>
                                            <th class="px-3 py-2 text-left font-semibold">{{ tr('Ultima descarga', 'Last download') }}</th>
                                            <th class="px-3 py-2 text-left font-semibold">{{ tr('Accion', 'Action') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr v-for="receipt in group.receipts" :key="receipt.id" class="border-t border-amber-200/70">
                                            <td class="px-3 py-2">
                                                <div class="font-medium text-gray-900">{{ receipt.receipt_number }}</div>
                                                <div class="text-xs text-gray-500">{{ receipt.payment_date || receipt.issued_at || '—' }}</div>
                                            </td>
                                            <td class="px-3 py-2">
                                                <div>{{ receipt.concept_name || '—' }}</div>
                                                <div class="text-xs text-gray-500">${{ Number(receipt.amount_paid || 0).toFixed(2) }}</div>
                                            </td>
                                            <td class="px-3 py-2">
                                                {{ receipt.issued_to_email || tr('Sin correo', 'No email') }}
                                            </td>
                                            <td class="px-3 py-2">
                                                <span class="inline-flex items-center rounded-full bg-amber-100 px-2 py-0.5 text-[11px] font-medium text-amber-800">
                                                    {{ receipt.reason }}
                                                </span>
                                            </td>
                                            <td class="px-3 py-2">
                                                {{ formatDateTimeLocal(receipt.last_downloaded_at) }}
                                            </td>
                                            <td class="px-3 py-2">
                                                <a :href="receipt.download_url" target="_blank" rel="noopener" class="text-sm font-medium text-blue-600 hover:underline">
                                                    {{ tr('Descargar', 'Download') }}
                                                </a>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <div v-else class="mt-3 text-sm text-amber-900">
                        {{ tr('La lista de recibos pendientes esta colapsada.', 'The pending receipts list is collapsed.') }}
                    </div>
                </section>

                <section class="mt-6">
                    <div class="flex items-center justify-between gap-2">
                        <div class="flex items-center gap-2">
                            <UserGroupIcon class="h-5 w-5 text-gray-500" />
                            <h3 class="text-sm font-semibold text-gray-900">{{ tr('Ingresos recientes', 'Recent Income') }}</h3>
                        </div>

                        <div class="relative w-64">
                            <input v-model="searchTerm" type="text" :placeholder="tr('Buscar por nombre o concepto', 'Search by name or concept')"
                                class="w-full rounded-lg border border-gray-300 py-1.5 pl-3 pr-8 text-sm focus:border-blue-500 focus:ring-blue-500" />
                            <svg class="pointer-events-none absolute right-2 top-2.5 h-4 w-4 text-gray-400" fill="none"
                                viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="m21 21-4.35-4.35M11 19a8 8 0 1 1 0-16 8 8 0 0 1 0 16z" />
                            </svg>
                        </div>
                    </div>

                    <div v-if="(props.payments || []).length"
                        class="mt-2 flex items-center justify-between text-xs text-gray-600">
                        <div>{{ tr('Mostrando', 'Showing') }} {{ filteredPayments.length ? startIdx + 1 : 0 }}-{{ endIdx }} {{ tr('de', 'of') }} {{ filteredPayments.length }}</div>
                        <div>10 {{ tr('por pagina', 'per page') }}</div>
                    </div>

                    <div v-if="!props.payments?.length" class="mt-2 text-sm text-gray-500">{{ tr('No hay ingresos aun.', 'There is no income yet.') }}</div>

                    <ul v-else class="mt-2 divide-y divide-gray-200 rounded-2xl border border-gray-200">
                        <li v-for="p in pagedPayments" :key="p.id" class="p-3 sm:p-4">
                            <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                                <div class="flex-1">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <div class="text-sm font-medium text-gray-900">
                                            {{ p.member_display_name ?? p.staff_display_name ?? '—' }}
                                        </div>

                                        <span
                                            class="inline-flex items-center rounded-full bg-gray-100 px-2 py-0.5 text-[11px] font-medium capitalize text-gray-700">
                                            {{ p.payment_type }}
                                        </span>
                                        <span v-if="p.pay_to || p.account_label"
                                            class="inline-flex items-center rounded-full bg-blue-50 px-2 py-0.5 text-[11px] font-medium text-blue-700">
                                            {{ p.account_label ?? p.pay_to }}
                                        </span>
                                        <span v-if="p.event_title || p.concept?.event_title"
                                            class="inline-flex items-center rounded-full bg-indigo-50 px-2 py-0.5 text-[11px] font-medium text-indigo-700">
                                            ● {{ p.event_title || p.concept?.event_title }}
                                        </span>

                                        <span v-if="p.concept?.reusable"
                                            class="inline-flex items-center rounded-full bg-blue-100 px-2 py-0.5 text-[11px] font-medium text-blue-800">
                                            {{ tr('Reutilizable', 'Reusable') }}
                                        </span>
                                        <span v-else-if="Number(p.balance_due_after ?? 0) > 0"
                                            class="inline-flex items-center rounded-full bg-amber-100 px-2 py-0.5 text-[11px] font-medium text-amber-800"
                                            :title="tr('Saldo restante despues de este pago', 'Remaining balance after this payment')">
                                            {{ tr('Pendiente', 'Pending') }} ${{ Number(p.balance_due_after).toFixed(2) }}
                                        </span>
                                        <span v-else
                                            class="inline-flex items-center rounded-full bg-emerald-100 px-2 py-0.5 text-[11px] font-medium text-emerald-800">
                                            {{ tr('Pagado completo', 'Paid in full') }}
                                        </span>
                                    </div>

                                    <div class="mt-0.5 text-xs text-gray-600">
                                        <b>{{ paymentConceptLabel(p) }}</b>
                                        • {{ tr('Esperado', 'Expected') }}: {{ p.expected_amount ?? p.concept?.amount ?? '—' }}
                                        <span v-if="p.pay_to || p.account_label"> • {{ tr('Cuenta', 'Account') }}: {{ p.account_label ?? p.pay_to }}</span>
                                        • {{ tr('Pagado', 'Paid') }}: ${{ Number(p.amount_paid ?? 0).toFixed(2) }}
                                        • {{ tr('Fecha', 'Date') }}: {{ formatISODateLocal(p.payment_date) }}
                                    </div>
                                    <div v-if="p.allocations?.length" class="mt-2 grid gap-1 text-xs text-gray-600 sm:grid-cols-2">
                                        <div
                                            v-for="allocation in p.allocations"
                                            :key="allocation.id"
                                            class="rounded-md bg-gray-50 px-2 py-1"
                                        >
                                            {{ allocation.component_label || allocation.concept_name }}: ${{ Number(allocation.amount || 0).toFixed(2) }}
                                        </div>
                                    </div>

                                    <div class="mt-0.5 text-xs text-gray-600">
                                        {{ tr('Recibido por', 'Received by') }}: {{ p.received_by?.name ?? '—' }}
	                                        <span v-if="p.payment_type === 'zelle' && p.zelle_phone"> • {{ tr('Zelle remitente', 'Sender Zelle') }}: {{ p.zelle_phone }}</span>
                                    </div>

                                    <div v-if="p.payment_type === 'check' && p.check_image_path" class="mt-2">
                                        <a :href="`/storage/${p.check_image_path}`" target="_blank" rel="noopener" class="inline-block" :title="tr('Abrir imagen del cheque', 'Open check image')">
                                            <img :src="`/storage/${p.check_image_path}`" :alt="tr('Imagen del cheque', 'Check image')"
                                                class="h-24 w-auto rounded border object-cover" />
                                        </a>
                                    </div>
                                </div>

                                <div class="text-right">
                                    <div class="text-sm font-semibold text-gray-900">
                                        ${{ Number(p.amount_paid ?? 0).toFixed(2) }}
                                    </div>
                                    <div class="text-xs text-gray-600">
                                        {{ formatISODateLocal(p.payment_date) }}
                                    </div>
                                    <div v-if="canEditPayments" class="mt-2 flex items-center justify-end gap-3">
                                        <button
                                            v-if="p.receipt?.id"
                                            type="button"
                                            class="text-xs font-medium text-emerald-700 hover:underline"
                                            @click="downloadReceipt(p)"
                                        >
                                            {{ tr('Recibo', 'Receipt') }}
                                        </button>
                                        <button
                                            v-if="canEditPayments"
                                            type="button"
                                            class="text-xs font-medium text-blue-600 hover:underline"
                                            @click="startEditPayment(p)"
                                        >
                                            {{ tr('Editar', 'Edit') }}
                                        </button>
                                    </div>
                                    <div v-if="p.receipt?.id" class="mt-1 text-[11px] text-gray-500">
                                        {{ tr('Ultima descarga', 'Last download') }}: {{ formatDateTimeLocal(p.receipt.last_downloaded_at) }}
                                    </div>
                                    <div v-if="props.auth_user?.profile_type === 'club_director'" class="mt-1 text-[11px] text-gray-500">
                                        {{ tr('Correcciones: usa el modulo de correcciones contables para revertir ingresos.', 'Corrections: use the accounting corrections module to reverse income.') }}
                                    </div>
                                </div>
                            </div>

                        </li>
                    </ul>

                    <div v-if="filteredPayments.length > pageSize" class="mt-3 flex items-center justify-between">
                        <button class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-50 disabled:opacity-50"
                            :disabled="page <= 1" @click="go(page - 1)">
                            {{ tr('Anterior', 'Previous') }}
                        </button>

                        <div class="text-xs text-gray-600">{{ tr('Pagina', 'Page') }} {{ page }} {{ tr('de', 'of') }} {{ totalPages }}</div>

                        <button class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-50 disabled:opacity-50"
                            :disabled="page >= totalPages" @click="go(page + 1)">
                            {{ tr('Siguiente', 'Next') }}
                        </button>
                    </div>
                </section>
            </main>

            <div v-if="editingPayment" class="fixed inset-0 z-40 flex items-center justify-center bg-black/40 px-4 py-6">
                <div class="max-h-[90vh] w-full max-w-2xl overflow-y-auto rounded-2xl bg-white p-5 shadow-xl">
                    <div class="flex items-start justify-between gap-4 border-b border-gray-200 pb-4">
                        <div>
                            <h3 class="text-base font-semibold text-gray-900">{{ tr('Editar ingreso', 'Edit Income') }}</h3>
                            <p class="mt-1 text-sm text-gray-600">
                                {{ editingPayment.member_display_name ?? editingPayment.staff_display_name ?? '—' }}
                                • {{ editingPayment.concept?.concept ?? editingPayment.concept_text ?? '—' }}
                            </p>
                        </div>
                        <button type="button" class="text-sm text-gray-500 hover:text-gray-800" @click="cancelEditPayment">
                            {{ tr('Cerrar', 'Close') }}
                        </button>
                    </div>

                    <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div>
                            <label class="block text-sm font-medium text-gray-700">{{ tr('Importe', 'Amount') }}</label>
                            <input
                                v-model="editForm.amount_paid"
                                type="number"
                                step="0.01"
                                min="0"
                                class="mt-1 w-full rounded-lg border-gray-300 py-2 text-sm focus:border-blue-500 focus:ring-blue-500"
                            />
                            <div v-if="showEditZeroWarning" class="mt-1 rounded border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800">
                                {{ tr('Registrar pagos en 0.00 no es recomendable. Corrige el importe antes de guardar.', 'Recording payments at 0.00 is not recommended. Correct the amount before saving.') }}
                            </div>
                            <div v-if="editForm.errors.amount_paid" class="mt-1 text-sm text-red-600">
                                {{ editForm.errors.amount_paid }}
                            </div>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">{{ tr('Fecha', 'Date') }}</label>
                            <input
                                v-model="editForm.payment_date"
                                type="date"
                                class="mt-1 w-full rounded-lg border-gray-300 py-2 text-sm focus:border-blue-500 focus:ring-blue-500"
                            />
                            <div v-if="editForm.errors.payment_date" class="mt-1 text-sm text-red-600">
                                {{ editForm.errors.payment_date }}
                            </div>
                        </div>
                    </div>

                    <div class="mt-4">
                        <label class="block text-sm font-medium text-gray-700">{{ tr('Tipo de pago', 'Payment type') }}</label>
                        <div class="mt-2 flex flex-wrap items-center gap-2">
                            <label v-for="t in payment_types" :key="`edit-modal-${t}`" class="inline-flex items-center gap-2 rounded-lg border border-gray-200 px-3 py-2">
                                <input type="radio" class="text-blue-600 focus:ring-blue-500" :value="t" v-model="editForm.payment_type" />
                                <span class="capitalize text-sm text-gray-700">{{ t === 'initial' ? tr('Saldo inicial', 'Opening balance') : t }}</span>
                            </label>
                        </div>
                        <div v-if="editForm.errors.payment_type" class="mt-1 text-sm text-red-600">
                            {{ editForm.errors.payment_type }}
                        </div>
                    </div>

                    <div v-if="editForm.payment_type === 'zelle'" class="mt-4">
                        <label class="block text-sm font-medium text-gray-700">{{ tr('Teléfono Zelle del remitente', 'Sender Zelle phone') }}</label>
                        <input
                            v-model="editForm.zelle_phone"
                            type="text"
                            inputmode="tel"
                            class="mt-1 w-full rounded-lg border-gray-300 py-2 text-sm focus:border-blue-500 focus:ring-blue-500"
                            placeholder="(555) 555-5555"
                        />
                        <div class="mt-1 text-xs text-gray-500">
                            {{ tr('La cuenta bancaria del club define el número receptor; aquí guarda el número desde donde se envió el dinero.', 'The club bank account defines the receiving number; store here the number where the money was sent from.') }}
                        </div>
                        <div v-if="editForm.errors.zelle_phone" class="mt-1 text-sm text-red-600">
                            {{ editForm.errors.zelle_phone }}
                        </div>
                    </div>

                    <div v-if="editForm.payment_type === 'check'" class="mt-4">
                        <label class="block text-sm font-medium text-gray-700">{{ tr('Foto del cheque', 'Check photo') }}</label>
                        <div class="mt-1 flex items-center gap-3">
                            <input
                                type="file"
                                accept="image/*"
                                @change="onEditCheckFileChange"
                                class="block w-full text-sm text-gray-700 file:mr-3 file:rounded-md file:border file:border-gray-300 file:bg-white file:px-3 file:py-2 file:text-sm file:font-medium hover:file:bg-gray-50"
                            />
                            <PhotoIcon v-if="!editCheckPreviewUrl" class="h-6 w-6 text-gray-400" />
                            <img v-if="editCheckPreviewUrl" :src="editCheckPreviewUrl" :alt="tr('Vista previa del cheque', 'Check preview')" class="h-10 w-auto rounded border" />
                        </div>
                    </div>

                    <div class="mt-4">
                        <label class="block text-sm font-medium text-gray-700">{{ tr('Notas', 'Notes') }}</label>
                        <textarea
                            v-model="editForm.notes"
                            rows="3"
                            class="mt-1 w-full rounded-lg border-gray-300 py-2 text-sm focus:border-blue-500 focus:ring-blue-500"
                            :placeholder="tr('Observaciones sobre este ingreso...', 'Notes about this income...')"
                        ></textarea>
                    </div>

                    <div v-if="editForm.errors.form" class="mt-3 text-sm text-red-600">
                        {{ editForm.errors.form }}
                    </div>

                    <div class="mt-5 flex items-center justify-end gap-3 border-t border-gray-200 pt-4">
                        <button
                            type="button"
                            class="rounded-lg border border-gray-300 px-4 py-2 text-sm text-gray-700 hover:bg-white"
                            @click="cancelEditPayment"
                        >
                            {{ tr('Cancelar', 'Cancel') }}
                        </button>
                        <button
                            type="button"
                            class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700"
                            @click="submitEditPayment"
                        >
                            {{ tr('Guardar cambios', 'Save changes') }}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </PathfinderLayout>
</template>
