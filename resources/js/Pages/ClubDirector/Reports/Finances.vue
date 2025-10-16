<script setup>
import { ref, computed, watch, onMounted } from 'vue'
import PathfinderLayout from '@/Layouts/PathfinderLayout.vue'
import { FunnelIcon, ArrowPathIcon } from '@heroicons/vue/24/outline'
import axios from 'axios'
import { fetchFinancialReportBootstrap } from '@/Services/api'

// ─────────────────────────────────────
// UI / Filter state
// ─────────────────────────────────────
const mode = ref('concept') // 'concept' | 'scope' | 'date' | 'member'

const selectedConceptId = ref(null)
const selectedScopeType = ref(null)  // 'club_wide' | 'class' | 'member' | 'staff' | 'staff_wide'
const selectedScopeId = ref(null)    // e.g. class_id when scope_type === 'class'
const selectedMemberId = ref(null)
const selectedStaffId = ref(null)

const dateFrom = ref('')
const dateTo = ref('')
const reportError = ref('')

// ─────────────────────────────────────
// Server data (preloaded)
// ─────────────────────────────────────
const club = ref(null)
const concepts = ref([])     // [{ id, concept, amount, payment_expected_by, scopes: [...] }]
const scopes = ref([])       // list of payment_concept_scopes (if you need raw)
const members = ref([])      // [{ id, applicant_name }]
const classes = ref([])      // [{ id, class_name }]
const staff = ref([])        // [{ id, name }]
const scopeTypes = ref([])   // catalog: [{ value, label }]
const payTo = ref([])        // catalog: [{ value, label }]
const loadError = ref(null)

// ─────────────────────────────────────
/** Helpers */
// ─────────────────────────────────────
const fmtMoney = (n) => `$${Number(n ?? 0).toFixed(2)}`
const fmtNumber = (n) => Number(n ?? 0).toLocaleString()
const formatDateMDY = (dateStr) => {
    if (!dateStr) return '—'
    const d = new Date(dateStr)
    if (isNaN(d)) return dateStr
    const mm = String(d.getMonth() + 1).padStart(2, '0')
    const dd = String(d.getDate()).padStart(2, '0')
    const yyyy = d.getFullYear()
    return `${mm}-${dd}-${yyyy}`
}

const selectedConcept = computed(() =>
    (concepts.value || []).find(c => c.id === selectedConceptId.value) || null
)

const conceptScopeLabel = computed(() => {
    const sc = selectedConcept.value?.scopes?.[0]
    if (!sc) return '—'
    switch (sc.scope_type) {
        case 'club_wide': return `Club wide (${sc.club?.club_name ?? sc.club_id ?? '—'})`
        case 'class': return `Class: ${sc.class?.class_name ?? sc.class_id ?? '—'}`
        case 'member': return `Member ID: ${sc.member_id ?? '—'}`
        case 'staff_wide': return `Staff wide (${sc.club?.club_name ?? sc.club_id ?? '—'})`
        case 'staff': return `Staff ID: ${sc.staff_id ?? '—'}`
        default: return sc.scope_type
    }
})

// For scope filters: only show class list when scope_type === 'class'
const activeScopeOptions = computed(() => {
    if (selectedScopeType.value !== 'class') return []
    return classes.value
})

// ─────────────────────────────────────
// Results (from fetchReport)
// ─────────────────────────────────────
const loading = ref(false)

// Concept mode
const payments = ref([]) // array of payment rows (concept mode)
const summary = ref({
    payments_count: 0,
    amount_paid_sum: 0,
    expected_sum: 0,
    balance_remaining: 0,
    by_payment_type: {},
})

// Scope mode
const scopeBlocks = ref([]) // [{ scope, concepts:[{concept, payments, summary}], summary }]
const activeTabForScope = ref({}) // per scope index -> concept tab index
const currentTabIndex = (sIdx) => activeTabForScope.value[sIdx] ?? 0
const setTab = (sIdx, tIdx) => { activeTabForScope.value[sIdx] = tIdx }

// Metrics builders (for any summary object)
const summaryItemsFor = (sum) => ([
    { key: 'payments_count', label: 'Payments', value: (sum?.payments_count ?? 0).toLocaleString(), tone: 'text-gray-900' },
    { key: 'amount_paid_sum', label: 'Total Paid', value: fmtMoney(sum?.amount_paid_sum ?? 0), tone: 'text-emerald-700' },
    { key: 'expected_sum', label: 'Expected', value: fmtMoney(sum?.expected_sum ?? 0), tone: 'text-blue-700' },
    {
        key: 'balance_remaining', label: 'Remaining', value: fmtMoney(sum?.balance_remaining ?? 0),
        tone: Number(sum?.balance_remaining ?? 0) > 0 ? 'text-amber-700' : 'text-gray-700'
    },
])

const paymentTypeBreakdownFor = (sum) => {
    const map = sum?.by_payment_type ?? {}
    const total = Object.values(map).reduce((a, b) => a + Number(b || 0), 0)
    return Object.entries(map).map(([type, amt]) => ({
        type,
        amount: fmtMoney(amt),
        pct: total > 0 ? Math.round((Number(amt) / total) * 100) : 0,
    }))
}

// Concept-mode computed variants (for your existing summary card)
const summaryItems = computed(() => summaryItemsFor(summary.value))
const paymentTypeBreakdown = computed(() => paymentTypeBreakdownFor(summary.value))

// ─────────────────────────────────────
// Payload builders & fetch
// ─────────────────────────────────────
const buildScopePayload = () => {
    if (!selectedScopeType.value) throw new Error('Please choose a scope type.')

    // scopes that require an explicit id
    const needsId = ['class', 'member', 'staff']
    if (needsId.includes(selectedScopeType.value) && !selectedScopeId.value) {
        throw new Error(`Please select a ${selectedScopeType.value}.`)
    }

    // club_wide / staff_wide carry the club id as scope_id for backend
    let scopeId = selectedScopeId.value ?? null
    if (['club_wide', 'staff_wide'].includes(selectedScopeType.value)) {
        scopeId = club.value?.id ?? null
    }

    const params = {
        mode: 'scope',
        scope_type: selectedScopeType.value,
        scope_id: scopeId,
    }
    if (dateFrom.value) params.date_from = dateFrom.value
    if (dateTo.value) params.date_to = dateTo.value
    return params
}

const fetchReport = async () => {
    reportError.value = ''
    loading.value = true

    // reset result states
    payments.value = []
    summary.value = { payments_count: 0, amount_paid_sum: 0, expected_sum: 0, balance_remaining: 0, by_payment_type: {} }
    scopeBlocks.value = []
    activeTabForScope.value = {}

    // basic client-side date validation
    if (dateFrom.value && dateTo.value && dateTo.value < dateFrom.value) {
        reportError.value = 'End date cannot be earlier than start date.'
        loading.value = false
        return
    }

    try {
        let params = {}

        if (mode.value === 'concept') {
            if (!selectedConceptId.value) {
                reportError.value = 'Please select a concept.'
                return
            }
            params = { mode: 'concept', concept_id: selectedConceptId.value }
            if (dateFrom.value) params.date_from = dateFrom.value
            if (dateTo.value) params.date_to = dateTo.value
        } else if (mode.value === 'scope') {
            params = buildScopePayload()
        } else {
            reportError.value = 'Only concept/scope mode implemented in this step.'
            return
        }

        const { data } = await axios.get(route('financial.report'), { params })

        if (mode.value === 'concept') {
            payments.value = data?.data?.payments ?? []
            summary.value = data?.data?.summary ?? summary.value
        }

        if (mode.value === 'scope') {
            scopeBlocks.value = data?.data?.scopes ?? []
            // activeTabForScope stays {} until user clicks; defaults to 0 via currentTabIndex()
        }
    } catch (e) {
        console.error(e)
        reportError.value = e?.response?.data?.message || 'Failed to fetch report.'
    } finally {
        loading.value = false
    }
}

// Reset selections when switching modes
watch(mode, () => {
    selectedConceptId.value = null
    selectedScopeType.value = null
    selectedScopeId.value = null
    selectedMemberId.value = null
    selectedStaffId.value = null
    payments.value = []
    summary.value = { payments_count: 0, amount_paid_sum: 0, expected_sum: 0, balance_remaining: 0, by_payment_type: {} }
    scopeBlocks.value = []
    activeTabForScope.value = {}
    reportError.value = ''
})

// ─────────────────────────────────────
// Bootstrap preload
// ─────────────────────────────────────
onMounted(async () => {
    loading.value = true
    loadError.value = null
    try {
        const payload = await fetchFinancialReportBootstrap()
        // Backend returns { data: { ... } }
        club.value = payload.data.club
        concepts.value = payload.data.concepts || []
        scopes.value = payload.data.scopes || []
        members.value = payload.data.members || []
        classes.value = payload.data.classes || []
        staff.value = payload.data.staff || []
        scopeTypes.value = payload.data.scope_types || []
        payTo.value = payload.data.pay_to || []
    } catch (e) {
        console.error(e)
        loadError.value = 'Failed to load report data.'
    } finally {
        loading.value = false
    }
})
</script>

<template>
    <PathfinderLayout>
        <div class="min-h-screen bg-white px-4 pb-24 sm:px-6">
            <header class="pt-5 pb-3 flex items-center gap-3">
                <FunnelIcon class="h-6 w-6 text-gray-700" />
                <h1 class="text-lg font-semibold text-gray-900">Financial Report</h1>
            </header>

            <!-- FILTER BAR -->
            <section class="rounded-2xl border border-gray-200 p-4 shadow-sm">
                <h2 class="text-base font-semibold text-gray-900">Filter options</h2>
                <p class="mt-0.5 text-sm text-gray-600">Generate a financial report by concept, scope, date, or
                    member/staff.</p>

                <div v-if="loadError" class="mt-2 text-sm text-red-600">{{ loadError }}</div>

                <!-- Mode selector -->
                <div class="mt-4 flex flex-wrap gap-3">
                    <label class="inline-flex items-center gap-2 cursor-pointer text-sm">
                        <input type="radio" class="text-blue-600" v-model="mode" value="concept" />
                        <span>Concept</span>
                    </label>
                    <label class="inline-flex items-center gap-2 cursor-pointer text-sm">
                        <input type="radio" class="text-blue-600" v-model="mode" value="scope" />
                        <span>Scope</span>
                    </label>
                    <label class="inline-flex items-center gap-2 cursor-pointer text-sm">
                        <input type="radio" class="text-blue-600" v-model="mode" value="date" />
                        <span>Date / Range</span>
                    </label>
                    <label class="inline-flex items-center gap-2 cursor-pointer text-sm">
                        <input type="radio" class="text-blue-600" v-model="mode" value="member" />
                        <span>Member / Staff</span>
                    </label>
                </div>

                <!-- Concept filters -->
                <div v-if="mode === 'concept'" class="mt-4 grid gap-3 sm:grid-cols-2">
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Concept</label>
                        <select v-model="selectedConceptId"
                            class="mt-1 w-full rounded-lg border-gray-300 py-2 text-sm focus:border-blue-500 focus:ring-blue-500">
                            <option :value="null" disabled>Select a concept…</option>
                            <option v-for="c in concepts" :key="c.id" :value="c.id">{{ c.concept }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Date range (optional)</label>
                        <div class="mt-1 flex gap-2">
                            <input type="date" v-model="dateFrom"
                                class="w-full rounded-lg border-gray-300 py-2 text-sm" />
                            <input type="date" v-model="dateTo"
                                class="w-full rounded-lg border-gray-300 py-2 text-sm" />
                        </div>
                    </div>
                </div>

                <!-- Scope filters -->
                <div v-if="mode === 'scope'" class="mt-4 grid gap-3 sm:grid-cols-3">
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Scope type</label>
                        <select v-model="selectedScopeType"
                            class="mt-1 w-full rounded-lg border-gray-300 py-2 text-sm focus:border-blue-500 focus:ring-blue-500">
                            <option :value="null" disabled>Select type…</option>
                            <option v-for="c in scopeTypes" :key="c.value" :value="c.value">{{ c.label }}</option>
                        </select>
                    </div>

                    <div v-if="selectedScopeType === 'class'">
                        <label class="block text-sm font-medium text-gray-700">Select class</label>
                        <select v-model="selectedScopeId"
                            class="mt-1 w-full rounded-lg border-gray-300 py-2 text-sm focus:border-blue-500 focus:ring-blue-500">
                            <option :value="null" disabled>Select class…</option>
                            <option v-for="cl in activeScopeOptions" :key="cl.id" :value="cl.id">{{ cl.class_name }}
                            </option>
                        </select>
                    </div>

                    <div v-if="selectedScopeType === 'member'">
                        <label class="block text-sm font-medium text-gray-700">Select member</label>
                        <select v-model="selectedScopeId"
                            class="mt-1 w-full rounded-lg border-gray-300 py-2 text-sm focus:border-blue-500 focus:ring-blue-500">
                            <option :value="null" disabled>Select member…</option>
                            <option v-for="m in members" :key="m.id" :value="m.id">{{ m.applicant_name }}</option>
                        </select>
                    </div>

                    <div v-if="selectedScopeType === 'staff'">
                        <label class="block text-sm font-medium text-gray-700">Select staff</label>
                        <select v-model="selectedScopeId"
                            class="mt-1 w-full rounded-lg border-gray-300 py-2 text-sm focus:border-blue-500 focus:ring-blue-500">
                            <option :value="null" disabled>Select staff…</option>
                            <option v-for="s in staff" :key="s.id" :value="s.id">{{ s.name }}</option>
                        </select>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700">Date range (optional)</label>
                        <div class="mt-1 flex gap-2">
                            <input type="date" v-model="dateFrom"
                                class="w-full rounded-lg border-gray-300 py-2 text-sm" />
                            <input type="date" v-model="dateTo"
                                class="w-full rounded-lg border-gray-300 py-2 text-sm" />
                        </div>
                    </div>
                </div>

                <!-- Member/Staff (placeholder; not implemented server-side yet) -->
                <div v-if="mode === 'member'" class="mt-4 grid gap-3 sm:grid-cols-3">
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Member</label>
                        <select v-model="selectedMemberId"
                            class="mt-1 w-full rounded-lg border-gray-300 py-2 text-sm focus:border-blue-500 focus:ring-blue-500">
                            <option :value="null" disabled>Select member…</option>
                            <option v-for="m in members" :key="m.id" :value="m.id">{{ m.applicant_name }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Staff</label>
                        <select v-model="selectedStaffId"
                            class="mt-1 w-full rounded-lg border-gray-300 py-2 text-sm focus:border-blue-500 focus:ring-blue-500">
                            <option :value="null" disabled>Select staff…</option>
                            <option v-for="s in staff" :key="s.id" :value="s.id">{{ s.name }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Date range (optional)</label>
                        <div class="mt-1 flex gap-2">
                            <input type="date" v-model="dateFrom"
                                class="w-full rounded-lg border-gray-300 py-2 text-sm" />
                            <input type="date" v-model="dateTo"
                                class="w-full rounded-lg border-gray-300 py-2 text-sm" />
                        </div>
                    </div>
                </div>

                <!-- Date-only (placeholder; not implemented server-side yet) -->
                <div v-if="mode === 'date'" class="mt-4">
                    <label class="block text-sm font-medium text-gray-700">Select date range</label>
                    <div class="mt-1 flex gap-2">
                        <input type="date" v-model="dateFrom" class="w-full rounded-lg border-gray-300 py-2 text-sm" />
                        <input type="date" v-model="dateTo" class="w-full rounded-lg border-gray-300 py-2 text-sm" />
                    </div>
                </div>

                <div class="mt-5 flex justify-end">
                    <button @click="fetchReport" :disabled="loading"
                        class="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-60">
                        <ArrowPathIcon v-if="loading" class="h-4 w-4 animate-spin" />
                        <span>{{ loading ? 'Loading…' : 'Generate report' }}</span>
                    </button>
                </div>
            </section>

            <!-- CONCEPT MODE RESULTS -->
            <section v-if="payments.length && mode === 'concept'"
                class="mt-6 rounded-2xl border border-gray-200 shadow-sm overflow-x-auto">
                <div v-if="selectedConcept" class="mt-4 bg-white p-4 shadow-sm">
                    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                        <div>
                            <h3 class="text-base font-semibold text-gray-900">{{ selectedConcept.concept }}</h3>
                            <p class="text-xs text-gray-600">
                                Scope: {{ conceptScopeLabel }} •
                                Due by: {{ formatDateMDY(selectedConcept.payment_expected_by) }}
                            </p>
                        </div>
                        <div class="text-right">
                            <div class="text-xs text-gray-500">Amount to charge</div>
                            <div class="text-lg font-semibold text-blue-700">{{ fmtMoney(selectedConcept.amount) }}
                            </div>
                        </div>
                    </div>
                </div>

                <table class="min-w-full text-sm text-gray-700">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-2 text-left font-semibold">Date</th>
                            <th class="px-4 py-2 text-left font-semibold">Member/Staff</th>
                            <th class="px-4 py-2 text-left font-semibold">Concept</th>
                            <th class="px-4 py-2 text-left font-semibold">Amount</th>
                            <th class="px-4 py-2 text-left font-semibold">Payment Type</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="p in payments" :key="p.id" class="border-t">
                            <td class="px-4 py-2">{{ formatDateMDY(p.payment_date) }}</td>
                            <td class="px-4 py-2">{{ p.member?.applicant_name ?? p.staff?.name ?? '—' }}</td>
                            <td class="px-4 py-2">{{ p.concept?.concept ?? '—' }}</td>
                            <td class="px-4 py-2">${{ Number(p.amount_paid ?? 0).toFixed(2) }}</td>
                            <td class="px-4 py-2 capitalize">{{ p.payment_type }}</td>
                        </tr>
                    </tbody>
                </table>

                <!-- Summary (concept mode) -->
                <div v-if="summary && (summary.amount_paid_sum || summary.expected_sum || summary.balance_remaining)"
                    class="mt-4 rounded-xl border border-gray-200 bg-gray-50 p-4 shadow-sm">
                    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                        <div>
                            <h4 class="text-sm font-semibold text-gray-900">Summary</h4>
                            <p class="text-xs text-gray-600">Totals for current filters</p>
                        </div>
                        <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
                            <div v-for="item in summaryItems" :key="item.key"
                                class="flex flex-col text-center sm:text-left">
                                <span class="text-xs text-gray-500">{{ item.label }}</span>
                                <span :class="['font-semibold', item.tone]">{{ item.value }}</span>
                            </div>
                        </div>
                    </div>

                    <div class="my-3 h-px bg-gray-200"></div>

                    <div class="flex flex-wrap items-center gap-4 text-sm text-gray-800">
                        <div v-for="pt in paymentTypeBreakdown" :key="pt.type" class="flex items-baseline gap-2">
                            <span class="text-xs text-gray-500 capitalize">{{ pt.type }}</span>
                            <span class="font-semibold">{{ pt.amount }}</span>
                            <span class="text-xs text-gray-500">({{ pt.pct }}%)</span>
                        </div>
                        <div v-if="!paymentTypeBreakdown.length" class="text-xs text-gray-500">No payments by type yet.
                        </div>
                    </div>
                </div>
            </section>

            <!-- SCOPE MODE RESULTS -->
            <section v-if="mode === 'scope' && scopeBlocks.length" class="mt-6 space-y-8">
                <div v-for="(blk, sIdx) in scopeBlocks" :key="blk.scope.identity_key"
                    class="rounded-2xl border border-gray-200 shadow-sm overflow-hidden bg-white">

                    <!-- Scope header + rollup -->
                    <div class="p-4">
                        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                            <div>
                                <h3 class="text-base font-semibold text-gray-900">{{ blk.scope.label }}</h3>
                                <p class="text-xs text-gray-600">
                                    Type: {{ blk.scope.type }}
                                    <span v-if="blk.scope.staff_all"> • Staff wide</span>
                                </p>
                            </div>

                            <div class="rounded-xl border border-gray-200 bg-gray-50 p-3">
                                <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
                                    <div v-for="item in summaryItemsFor(blk.summary)" :key="item.key"
                                        class="flex flex-col text-center sm:text-left">
                                        <span class="text-xs text-gray-500">{{ item.label }}</span>
                                        <span :class="['font-semibold', item.tone]">{{ item.value }}</span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Scope payment type breakdown -->
                        <div class="mt-3 flex flex-wrap items-center gap-4 text-sm text-gray-800">
                            <div v-for="pt in paymentTypeBreakdownFor(blk.summary)" :key="pt.type"
                                class="flex items-baseline gap-2">
                                <span class="text-xs text-gray-500 capitalize">{{ pt.type }}</span>
                                <span class="font-semibold">{{ pt.amount }}</span>
                                <span class="text-xs text-gray-500">({{ pt.pct }}%)</span>
                            </div>
                            <div v-if="!paymentTypeBreakdownFor(blk.summary).length" class="text-xs text-gray-500">
                                No payments by type yet.
                            </div>
                        </div>
                    </div>

                    <!-- Concept tabs under this scope -->
                    <div class="px-4">
                        <div class="flex flex-wrap gap-2 border-b border-gray-200">
                            <button v-for="(c, tIdx) in blk.concepts" :key="c.concept.id" type="button"
                                @click="setTab(sIdx, tIdx)" class="px-3 py-2 text-sm" :class="currentTabIndex(sIdx) === tIdx
                                    ? 'border-b-2 border-blue-600 text-blue-700 font-medium'
                                    : 'text-gray-600 hover:text-gray-900'">
                                {{ c.concept.concept }} — {{ fmtMoney(c.concept.amount) }}
                            </button>
                        </div>
                    </div>

                    <!-- Active concept for this scope -->
                    <div class="p-4">
                        <template v-if="blk.concepts.length">
                            <div class="mb-3 rounded-lg border border-gray-200 bg-white p-3">
                                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
                                    <div>
                                        <div class="text-sm font-semibold text-gray-900">
                                            {{ blk.concepts[currentTabIndex(sIdx)].concept.concept }}
                                        </div>
                                        <div class="text-xs text-gray-600">
                                            Due by: {{
                                                formatDateMDY(blk.concepts[currentTabIndex(sIdx)].concept.payment_expected_by)
                                            }}
                                        </div>
                                    </div>
                                    <div class="text-right">
                                        <div class="text-xs text-gray-500">Amount to charge</div>
                                        <div class="text-lg font-semibold text-blue-700">
                                            {{ fmtMoney(blk.concepts[currentTabIndex(sIdx)].concept.amount) }}
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="rounded-2xl border border-gray-200 overflow-x-auto">
                                <table class="min-w-full text-sm text-gray-700">
                                    <thead class="bg-gray-50">
                                        <tr>
                                            <th class="px-4 py-2 text-left font-semibold">Date</th>
                                            <th class="px-4 py-2 text-left font-semibold">Member/Staff</th>
                                            <th class="px-4 py-2 text-left font-semibold">Concept</th>
                                            <th class="px-4 py-2 text-left font-semibold">Amount</th>
                                            <th class="px-4 py-2 text-left font-semibold">Payment Type</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr v-for="p in blk.concepts[currentTabIndex(sIdx)].payments" :key="p.id"
                                            class="border-t">
                                            <td class="px-4 py-2">{{ formatDateMDY(p.payment_date) }}</td>
                                            <td class="px-4 py-2">{{ p.member?.applicant_name ?? p.staff?.name ?? '—' }}
                                            </td>
                                            <td class="px-4 py-2">{{ blk.concepts[currentTabIndex(sIdx)].concept.concept
                                                }}</td>
                                            <td class="px-4 py-2">${{ Number(p.amount_paid ?? 0).toFixed(2) }}</td>
                                            <td class="px-4 py-2 capitalize">{{ p.payment_type }}</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>

                            <div class="mt-4 rounded-xl border border-gray-200 bg-gray-50 p-4">
                                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                                    <div>
                                        <h4 class="text-sm font-semibold text-gray-900">Summary</h4>
                                        <p class="text-xs text-gray-600">Totals for this concept</p>
                                    </div>
                                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
                                        <div v-for="item in summaryItemsFor(blk.concepts[currentTabIndex(sIdx)].summary)"
                                            :key="item.key" class="flex flex-col text-center sm:text-left">
                                            <span class="text-xs text-gray-500">{{ item.label }}</span>
                                            <span :class="['font-semibold', item.tone]">{{ item.value }}</span>
                                        </div>
                                    </div>
                                </div>

                                <div class="my-3 h-px bg-gray-200"></div>

                                <div class="flex flex-wrap items-center gap-4 text-sm text-gray-800">
                                    <div v-for="pt in paymentTypeBreakdownFor(blk.concepts[currentTabIndex(sIdx)].summary)"
                                        :key="pt.type" class="flex items-baseline gap-2">
                                        <span class="text-xs text-gray-500 capitalize">{{ pt.type }}</span>
                                        <span class="font-semibold">{{ pt.amount }}</span>
                                        <span class="text-xs text-gray-500">({{ pt.pct }}%)</span>
                                    </div>
                                    <div v-if="!paymentTypeBreakdownFor(blk.concepts[currentTabIndex(sIdx)].summary).length"
                                        class="text-xs text-gray-500">
                                        No payments by type yet.
                                    </div>
                                </div>
                            </div>
                        </template>

                        <div v-else class="text-sm text-gray-500">No concepts found under this scope.</div>
                    </div>
                </div>
            </section>

            <div v-else-if="!loading" class="mt-6 text-sm text-gray-500 text-center">
                No payments found for the selected filters.
            </div>

            <div v-if="reportError" class="mt-3 text-sm text-red-600 text-center">{{ reportError }}</div>
        </div>
    </PathfinderLayout>
</template>
