<script setup>
import PathfinderLayout from "@/Layouts/PathfinderLayout.vue";
import { ref, computed, watch } from 'vue'
import { useForm, router } from '@inertiajs/vue3'
import {
    CreditCardIcon,
    UserIcon,
    CalendarDaysIcon,
    CurrencyDollarIcon,
    PhotoIcon,
    ArrowPathIcon,
    UserGroupIcon
} from '@heroicons/vue/24/outline'
import { createClubPayment } from '@/Services/api'

const props = defineProps({
    auth_user: { type: Object, required: true },
    user: Object,
    club: { type: Object, required: true },
    clubs: { type: Array, default: () => [] },
    members: { type: Array, required: true },
    staff: { type: Array, required: true },
    concepts: { type: Array, required: true },
    payments: { type: Array, required: true },
    payment_types: { type: Array, required: true },
})

const scopeLabel = (sc) => {
    if (!sc) return 'No scope'
    switch (sc.scope_type) {
        case 'club_wide': return `Club wide (${sc.club?.club_name ?? sc.club_id ?? '—'})`
        case 'class': return `Class: ${sc.class?.class_name ?? sc.class_id ?? '—'}`
        case 'staff_wide': return `Staff wide (${sc.club?.club_name ?? sc.club_id ?? '—'})`
        case 'member': return `Member: ${sc.member?.applicant_name ?? sc.member_id ?? '—'}`
        case 'staff': return `Staff: ${sc.staff?.name ?? sc.staff_id ?? '—'}`
        default: return 'Unknown scope'
    }
}

const formatISODateLocal = (val) => {
    if (!val) return '—'
    const [y, m, d] = String(val).slice(0, 10).split('-').map(Number)
    const dt = new Date(y, m - 1, d)
    return new Intl.DateTimeFormat(undefined, { year: 'numeric', month: 'short', day: '2-digit' }).format(dt)
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

const filteredMembers = computed(() => {
    if (!form.club_id) return props.members || []
    return (props.members || []).filter(m => Number(m.club_id) === Number(form.club_id))
})

const filteredStaff = computed(() => {
    if (!form.club_id) return props.staff || []
    return (props.staff || []).filter(s => Number(s.club_id) === Number(form.club_id))
})

const selectedConcept = computed(() => filteredConcepts.value.find(c => c.id === selectedConceptId.value) || null)
const scopesForConcept = computed(() => selectedConcept.value?.scopes ?? [])
const selectedScope = computed(() => scopesForConcept.value.find(s => s.id === selectedScopeId.value) || null)
const selectedConceptExpected = computed(() => selectedConcept.value?.amount ?? '')

const scopePayerType = computed(() => {
    const st = selectedScope.value?.scope_type
    if (!st) return null
    return st === 'staff' || st === 'staff_wide' ? 'staff' : 'member'
})

const payeeOptions = computed(() => {
    const scope = selectedScope.value
    if (!scope) return []
    switch (scope.scope_type) {
        case 'member':
            return filteredMembers.value.filter(m => Number(m.id) === Number(scope.member_id || scope.member?.id))
        case 'staff':
            return filteredStaff.value.filter(s => Number(s.id) === Number(scope.staff_id || scope.staff?.id))
        case 'staff_wide':
            return filteredStaff.value
        case 'class': {
            const classId = scope.class_id || scope.class?.id
            if (!classId) return filteredMembers.value
            return filteredMembers.value.filter(m => {
                const memberClassId = m.class_id ?? m.current_class.id ?? m.club_class_id
                return Number(memberClassId) === Number(classId)
            })

        }
        case 'club_wide':
        default:
            return props.members
    }
})

// Form
const form = useForm({
    club_id: null,
    payment_concept_id: null,
    member_adventurer_id: null,
    staff_adventurer_id: null,
    amount_paid: '',
    payment_date: new Date().toISOString().slice(0, 10),
    payment_type: 'cash',
    zelle_phone: '',
    check_image: null,
    notes: '',
})

watch(selectedConceptId, (id) => {
    form.payment_concept_id = id ?? null
    if (!form.club_id && props.clubs?.length) {
        form.club_id = props.clubs[0].id
    }
    selectedScopeId.value = scopesForConcept.value[0]?.id ?? null
    selectedMemberId.value = null
    selectedStaffId.value = null
    const expected = selectedConceptExpected.value
    form.amount_paid = expected ? String(expected) : ''
})

watch(selectedMemberId, (id) => {
    form.member_adventurer_id = id ?? null
})

watch(selectedStaffId, (id) => {
    form.staff_adventurer_id = id ?? null
})

watch(selectedScopeId, (id) => {
    form.member_adventurer_id = null
    form.staff_adventurer_id = null
    selectedMemberId.value = null
    selectedStaffId.value = null
    const scope = scopesForConcept.value.find(s => s.id === id)
    if (!scope) return
    if (scope.scope_type === 'member') {
        selectedMemberId.value = scope.member_id || scope.member?.id || null
    }
    if (scope.scope_type === 'staff') {
        selectedStaffId.value = scope.staff_id || scope.staff?.id || null
    }
})

// Reset concept when club changes
watch(() => form.club_id, () => {
    selectedConceptId.value = null
    selectedScopeId.value = null
    selectedMemberId.value = null
    selectedStaffId.value = null
    form.payment_concept_id = null
    page.value = 1
    if (filteredConcepts.value.length) {
        selectedConceptId.value = filteredConcepts.value[0].id
        form.payment_concept_id = selectedConceptId.value
        selectedScopeId.value = scopesForConcept.value[0]?.id ?? null
    }
})

// Default club selection
watch(() => props.clubs, (val) => {
    if (!form.club_id && Array.isArray(val) && val.length) {
        form.club_id = val[0].id
    }
}, { immediate: true })

// Prefill club_id from initial props (explicit club or first in list)
if (!form.club_id) {
    form.club_id = props.club?.id ?? (props.clubs?.[0]?.id ?? null)
}

// When club changes, reset selections and pick first concept for that club
watch(() => form.club_id, () => {
    selectedConceptId.value = filteredConcepts.value[0]?.id ?? null
    selectedScopeId.value = null
    selectedMemberId.value = null
    selectedStaffId.value = null
    form.payment_concept_id = selectedConceptId.value
})

// Reset conditional fields when payment_type changes
watch(() => form.payment_type, (t) => {
    if (t !== 'zelle') form.zelle_phone = ''
    if (t !== 'check') form.check_image = null
})

// File handling
const checkPreviewUrl = ref(null)
const onCheckFileChange = (e) => {
    const file = e.target.files?.[0]
    form.check_image = file || null
    checkPreviewUrl.value = file ? URL.createObjectURL(file) : null
}

const submitting = ref(false)
const submit = async () => {
    form.clearErrors()
    if (!form.club_id && props.clubs?.length) {
        form.club_id = props.clubs[0].id
    }
    if (!form.club_id) {
        form.setError('club_id', 'Select a club.')
        return
    }
    if (!selectedScope.value) {
        form.setError('payment_concept_id', 'Select a scope before saving.')
        return
    }
    if (scopePayerType.value === 'member' && !selectedMemberId.value) {
        form.setError('member_adventurer_id', 'Select a member for this scope.')
        return
    }
    if (scopePayerType.value === 'staff' && !selectedStaffId.value) {
        form.setError('staff_adventurer_id', 'Select a staff for this scope.')
        return
    }

    submitting.value = true
    try {
        const payload = { ...form.data() }
        delete payload.club_id
        await createClubPayment(payload)
        form.reset('amount_paid', 'notes', 'check_image', 'zelle_phone')
        router.reload({ only: ['payments'] })
    } catch (err) {
        if (err?.response?.status === 422) {
            const errs = err.response.data.errors || {}
            Object.entries(errs).forEach(([field, messages]) => {
                form.setError(field, Array.isArray(messages) ? messages[0] : messages)
            })
        } else {
            console.error(err)
            form.setError('form', 'Unexpected error. Please try again.')
        }
    } finally {
        submitting.value = false
    }
}

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
        const name = (p.member?.applicant_name ?? p.staff?.name ?? '').toLowerCase()
        const concept = (p.concept?.concept ?? '').toLowerCase()
        return name.includes(q) || concept.includes(q)
    })
})

watch(searchTerm, () => { page.value = 1 })

const totalPages = computed(() => Math.max(1, Math.ceil(filteredPayments.value.length / pageSize.value)))
const startIdx = computed(() => (page.value - 1) * pageSize.value)
const endIdx = computed(() => Math.min(startIdx.value + pageSize.value, filteredPayments.value.length))
const pagedPayments = computed(() => filteredPayments.value.slice(startIdx.value, endIdx.value))

const go = (n) => { page.value = Math.min(totalPages.value, Math.max(1, n)) }

</script>

<template>
    <PathfinderLayout>
        <div class="min-h-screen bg-white">
            <!-- Header -->
            <header class="px-4 pt-5 pb-3 sm:px-6">
                <div class="flex items-center gap-3">
                    <CreditCardIcon class="h-6 w-6 text-gray-700" />
                    <h1 class="text-lg font-semibold text-gray-900">Director Payments</h1>
                </div>
                <div class="mt-2 flex flex-col sm:flex-row sm:items-center sm:gap-4">
                    <p class="text-sm text-gray-600">
                        Signed in as <strong>{{ auth_user?.name }}</strong>
                    </p>
                    <div class="flex items-center gap-2 text-sm">
                        <label class="text-gray-700">Club:</label>
                        <select v-model="form.club_id"
                            class="rounded border-gray-300 py-1 text-sm focus:border-blue-500 focus:ring-blue-500">
                            <option v-for="c in clubs" :key="c.id" :value="c.id">{{ c.club_name }}</option>
                        </select>
                    </div>
                </div>
            </header>

            <main class="px-4 pb-24 sm:px-6">
                <!-- Form card -->
                <section class="rounded-2xl border border-gray-200 p-4 sm:p-5 shadow-sm">
                    <h2 class="text-base font-semibold text-gray-900">Record a payment</h2>
                    <p class="mt-0.5 text-sm text-gray-600">Select the payer (member or staff) and concept.</p>

                    <!-- Concept -->
                    <div class="mt-4">
                        <label class="block text-sm font-medium text-gray-700">Payment concept</label>
                        <select v-model="selectedConceptId"
                            class="mt-1 w-full rounded-lg border-gray-300 py-2 text-sm focus:border-blue-500 focus:ring-blue-500">
                            <option :value="null" disabled>Select a concept…</option>
                            <option v-for="c in filteredConcepts" :key="c.id" :value="c.id">
                                {{ c.concept }} • {{ c.amount ?? '—' }}
                            </option>
                        </select>
                        <div class="mt-1 text-xs text-gray-500" v-if="selectedConcept">
                            <span class="font-medium">Scope:</span>
                            <span>{{ selectedConcept.scopes?.[0] ? scopeLabel(selectedConcept.scopes[0]) : 'No scope' }}</span>
                            <span class="ml-2">•</span>
                            <span class="ml-2"><span class="font-medium">Expected:</span> {{ selectedConceptExpected || '—' }}</span>
                            <span class="ml-2">•</span>
                            <span class="ml-2"><span class="font-medium">Due by:</span> {{ formatISODateLocal(selectedConcept.payment_expected_by) }}</span>
                        </div>
                        <div v-if="form.errors.payment_concept_id" class="mt-1 text-sm text-red-600">
                            {{ form.errors.payment_concept_id }}
                        </div>
                    </div>

                    <!-- Payee -->
                    <div class="mt-4">
                        <label class="block text-sm font-medium text-gray-700">Payee</label>
                        <div class="text-xs text-gray-600 mb-1" v-if="selectedScope">
                            Scope: {{ scopeLabel(selectedScope) }}
                        </div>
                        <div v-else-if="selectedConcept" class="text-xs text-amber-700 mb-1">No scopes for this concept.</div>
                        <div v-if="scopePayerType === 'member'">
                            <select v-model="selectedMemberId" :disabled="!selectedScope"
                                class="mt-1 w-full rounded-lg border-gray-300 py-2 text-sm focus:border-blue-500 focus:ring-blue-500 disabled:bg-gray-50">
                                <option :value="null" disabled>Select member…</option>
                                <option v-for="m in payeeOptions" :key="m.id" :value="m.id">{{ m.applicant_name }}</option>
                            </select>
                            <div v-if="form.errors.member_adventurer_id" class="mt-1 text-sm text-red-600">
                                {{ form.errors.member_adventurer_id }}
                            </div>
                        </div>
                        <div v-else-if="scopePayerType === 'staff'">
                            <select v-model="selectedStaffId" :disabled="!selectedScope"
                                class="mt-1 w-full rounded-lg border-gray-300 py-2 text-sm focus:border-blue-500 focus:ring-blue-500 disabled:bg-gray-50">
                                <option :value="null" disabled>Select staff…</option>
                                <option v-for="s in payeeOptions" :key="s.id" :value="s.id">{{ s.name }}</option>
                            </select>
                            <div v-if="form.errors.staff_adventurer_id" class="mt-1 text-sm text-red-600">
                                {{ form.errors.staff_adventurer_id }}
                            </div>
                        </div>
                        <div v-else class="text-xs text-gray-500 mt-1">Select a concept and scope to choose who pays.</div>
                    </div>

                    <!-- Amount / Date -->
                    <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Amount paid</label>
                            <div class="mt-1 relative">
                                <input v-model="form.amount_paid" type="number" step="0.01" min="0"
                                    class="w-full rounded-lg border-gray-300 pl-9 pr-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500"
                                    placeholder="0.00" />
                                <CurrencyDollarIcon
                                    class="pointer-events-none absolute left-2 top-1/2 -translate-y-1/2 h-5 w-5 text-gray-400" />
                            </div>
                            <div v-if="form.errors.amount_paid" class="mt-1 text-sm text-red-600">
                                {{ form.errors.amount_paid }}
                            </div>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700">Payment date</label>
                            <div class="mt-1 relative">
                                <input v-model="form.payment_date" type="date"
                                    class="w-full rounded-lg border-gray-300 pl-9 pr-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500" />
                                <CalendarDaysIcon
                                    class="pointer-events-none absolute left-2 top-1/2 -translate-y-1/2 h-5 w-5 text-gray-400" />
                            </div>
                            <div v-if="form.errors.payment_date" class="mt-1 text-sm text-red-600">
                                {{ form.errors.payment_date }}
                            </div>
                        </div>
                    </div>

                    <!-- Payment Type -->
                    <div class="mt-4">
                        <label class="block text-sm font-medium text-gray-700">Payment type</label>
                        <div class="mt-2 flex flex-wrap items-center gap-2">
                            <label v-for="t in payment_types" :key="t" class="inline-flex items-center gap-2">
                                <input type="radio" class="text-blue-600 focus:ring-blue-500" :value="t" v-model="form.payment_type" />
                                <span class="capitalize text-sm text-gray-700">{{ t }}</span>
                            </label>
                        </div>
                        <div v-if="form.errors.payment_type" class="mt-1 text-sm text-red-600">
                            {{ form.errors.payment_type }}
                        </div>
                    </div>

                    <!-- Conditional fields -->
                    <div v-if="form.payment_type === 'zelle'" class="mt-4">
                        <label class="block text-sm font-medium text-gray-700">Zelle phone</label>
                        <input v-model="form.zelle_phone" type="text" inputmode="tel"
                            class="mt-1 w-full rounded-lg border-gray-300 py-2 text-sm focus:border-blue-500 focus:ring-blue-500"
                            placeholder="(555) 555-5555" />
                        <div v-if="form.errors.zelle_phone" class="mt-1 text-sm text-red-600">
                            {{ form.errors.zelle_phone }}
                        </div>
                    </div>

                    <div v-if="form.payment_type === 'check'" class="mt-4">
                        <label class="block text-sm font-medium text-gray-700">Check photo</label>
                        <div class="mt-1 flex items-center gap-3">
                            <input type="file" accept="image/*" @change="onCheckFileChange"
                                class="block w-full text-sm text-gray-700 file:mr-3 file:rounded-md file:border file:border-gray-300 file:bg-white file:px-3 file:py-2 file:text-sm file:font-medium hover:file:bg-gray-50" />
                            <PhotoIcon v-if="!checkPreviewUrl" class="h-6 w-6 text-gray-400" />
                            <img v-if="checkPreviewUrl" :src="checkPreviewUrl" alt="Check preview"
                                class="h-10 w-auto rounded border" />
                        </div>
                    </div>

                    <!-- Notes -->
                    <div class="mt-4">
                        <label class="block text-sm font-medium text-gray-700">Notes (optional)</label>
                        <textarea v-model="form.notes" rows="2"
                            class="mt-1 w-full rounded-lg border-gray-300 py-2 text-sm focus:border-blue-500 focus:ring-blue-500"
                            placeholder="Any remarks about this payment…"></textarea>
                    </div>

                    <!-- Submit -->
                    <div class="mt-5 flex items-center justify-end gap-3">
                        <button type="button" @click="submit" :disabled="submitting"
                            class="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-60">
                            <ArrowPathIcon v-if="submitting" class="h-4 w-4 animate-spin" />
                            <span>{{ submitting ? 'Saving…' : 'Save payment' }}</span>
                        </button>
                    </div>

                    <div v-if="form.hasErrors"
                        class="mt-3 rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-800">
                        <ul class="list-disc list-inside">
                            <li v-for="(msg, key) in form.errors" :key="key">{{ msg }}</li>
                        </ul>
                    </div>
                </section>

                <!-- Recent payments -->
                <section class="mt-6">
                    <div class="flex items-center justify-between gap-2">
                        <div class="flex items-center gap-2">
                            <UserGroupIcon class="h-5 w-5 text-gray-500" />
                            <h3 class="text-sm font-semibold text-gray-900">Recent payments</h3>
                        </div>

                        <div class="relative w-64">
                            <input v-model="searchTerm" type="text" placeholder="Search by name or concept"
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
                        <div>Showing {{ filteredPayments.length ? startIdx + 1 : 0 }}–{{ endIdx }} of {{ filteredPayments.length }}</div>
                        <div class="flex items-center gap-2">
                            <label class="hidden sm:block">Per page</label>
                            <select v-model.number="pageSize"
                                class="rounded border-gray-300 py-1 text-xs focus:border-blue-500 focus:ring-blue-500">
                                <option :value="5">5</option>
                                <option :value="10">10</option>
                                <option :value="20">20</option>
                                <option :value="50">50</option>
                            </select>
                        </div>
                    </div>

                    <div v-if="!props.payments?.length" class="mt-2 text-sm text-gray-500">No payments yet.</div>

                    <ul v-else class="mt-2 divide-y divide-gray-200 rounded-2xl border border-gray-200">
                        <li v-for="p in pagedPayments" :key="p.id" class="p-3 sm:p-4">
                            <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                                <div class="flex-1">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <div class="text-sm font-medium text-gray-900">
                                            {{ p.member?.applicant_name ?? p.staff?.name ?? '—' }}
                                        </div>

                                        <span
                                            class="inline-flex items-center rounded-full bg-gray-100 px-2 py-0.5 text-[11px] font-medium capitalize text-gray-700">
                                            {{ p.payment_type }}
                                        </span>

                                        <span v-if="Number(p.balance_due_after ?? 0) > 0"
                                            class="inline-flex items-center rounded-full bg-amber-100 px-2 py-0.5 text-[11px] font-medium text-amber-800"
                                            title="Remaining balance after this payment">
                                            Pending ${{ Number(p.balance_due_after).toFixed(2) }}
                                        </span>
                                        <span v-else
                                            class="inline-flex items-center rounded-full bg-emerald-100 px-2 py-0.5 text-[11px] font-medium text-emerald-800">
                                            Paid in full
                                        </span>
                                    </div>

                                    <div class="mt-0.5 text-xs text-gray-600">
                                        <b>{{ p.concept?.concept ?? '—' }}</b>
                                        • Expected: {{ p.expected_amount ?? p.concept?.amount ?? '—' }}
                                        • Paid: ${{ Number(p.amount_paid ?? 0).toFixed(2) }}
                                        • Date: {{ formatISODateLocal(p.payment_date) }}
                                    </div>

                                    <div class="mt-0.5 text-xs text-gray-600">
                                        Received by: {{ p.received_by?.name ?? '—' }}
                                        <span v-if="p.payment_type === 'zelle' && p.zelle_phone"> • Zelle: {{ p.zelle_phone }}</span>
                                    </div>

                                    <div v-if="p.payment_type === 'check' && p.check_image_path" class="mt-2">
                                        <a :href="`/storage/${p.check_image_path}`" target="_blank" rel="noopener" class="inline-block" title="Open check image">
                                            <img :src="`/storage/${p.check_image_path}`" alt="Check image"
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
                                </div>
                            </div>
                        </li>
                    </ul>

                    <div v-if="filteredPayments.length > pageSize" class="mt-3 flex items-center justify-between">
                        <button class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-50 disabled:opacity-50"
                            :disabled="page <= 1" @click="go(page - 1)">
                            Prev
                        </button>

                        <div class="text-xs text-gray-600">Page {{ page }} of {{ totalPages }}</div>

                        <button class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-50 disabled:opacity-50"
                            :disabled="page >= totalPages" @click="go(page + 1)">
                            Next
                        </button>
                    </div>
                </section>
            </main>
        </div>
    </PathfinderLayout>
</template>
