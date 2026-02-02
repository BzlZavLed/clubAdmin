<script setup>
import { ref, computed, watch, onMounted } from 'vue'
import PathfinderLayout from '@/Layouts/PathfinderLayout.vue'
import { FunnelIcon, ArrowPathIcon } from '@heroicons/vue/24/outline'
import axios from 'axios'
import { fetchFinancialReportBootstrap } from '@/Services/api'

// ─────────────────────────────────────
// UI / Filter state
// ─────────────────────────────────────
const mode = ref('account') // 'concept' | 'account'

const selectedConceptId = ref(null)
const selectedScopeType = ref(null)  // 'club_wide' | 'class' | 'member' | 'staff' | 'staff_wide'
const selectedScopeId = ref(null)    // e.g. class_id when scope_type === 'class'
const selectedMemberId = ref(null)
const selectedStaffId = ref(null)
const selectedPayTo = ref(null)

const dateFrom = ref('')
const dateTo = ref('')
const reportError = ref('')

// ─────────────────────────────────────
// Server data (preloaded)
// ─────────────────────────────────────
const club = ref(null)
const clubs = ref([])
const selectedClubId = ref(null)
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
const payToLabel = (val) => {
    const m = payTo.value.find(p => p.value === val)
    return m?.label || (val ?? '—')
}

const selectedConcept = computed(() =>
    (concepts.value || []).find(c => c.id === selectedConceptId.value) || null
)

const conceptScopeLabel = computed(() => {
    const sc = selectedConcept.value?.scopes?.[0]
    if (!sc) return '—'
    switch (sc.scope_type) {
        case 'club_wide': return `Club completo (${sc.club?.club_name ?? sc.club_id ?? '—'})`
        case 'class': return `Clase: ${sc.class?.class_name ?? sc.class_id ?? '—'}`
        case 'member': return `ID de miembro: ${sc.member_id ?? '—'}`
        case 'staff_wide': return `Personal completo (${sc.club?.club_name ?? sc.club_id ?? '—'})`
        case 'staff': return `ID de personal: ${sc.staff_id ?? '—'}`
        default: return sc.scope_type
    }
})

const setDefaultPayTo = () => {
    selectedPayTo.value = 'all'
}

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
const payments = ref([]) // legacy
const summary = ref({
    payments_count: 0,
    amount_paid_sum: 0,
    expected_sum: 0,
    balance_remaining: 0,
    by_payment_type: {},
})
const accountsReport = ref([])

// Scope mode
const scopeBlocks = ref([]) // [{ scope, concepts:[{concept, payments, summary}], summary }]
const activeTabForScope = ref({}) // per scope index -> concept tab index
const currentTabIndex = (sIdx) => activeTabForScope.value[sIdx] ?? 0
const setTab = (sIdx, tIdx) => { activeTabForScope.value[sIdx] = tIdx }

// Metrics builders (for any summary object)
const summaryItemsFor = (sum) => ([
    { key: 'payments_count', label: 'Pagos', value: (sum?.payments_count ?? 0).toLocaleString(), tone: 'text-gray-900' },
    { key: 'amount_paid_sum', label: 'Total pagado', value: fmtMoney(sum?.amount_paid_sum ?? 0), tone: 'text-emerald-700' },
    { key: 'expected_sum', label: 'Esperado', value: fmtMoney(sum?.expected_sum ?? 0), tone: 'text-blue-700' },
    {
        key: 'balance_remaining', label: 'Pendiente', value: fmtMoney(sum?.balance_remaining ?? 0),
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
    if (!selectedScopeType.value) throw new Error('Selecciona un tipo de alcance.')

    // scopes that require an explicit id
    const needsId = ['class', 'member', 'staff']
    if (needsId.includes(selectedScopeType.value) && !selectedScopeId.value) {
        throw new Error(`Selecciona un ${selectedScopeType.value}.`)
    }

    // club_wide / staff_wide carry the club id as scope_id for backend
    let scopeId = selectedScopeId.value ?? null
    if (['club_wide', 'staff_wide'].includes(selectedScopeType.value)) {
        scopeId = selectedClubId.value ?? club.value?.id ?? null
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
    accountsReport.value = []
    scopeBlocks.value = []
    activeTabForScope.value = {}

    // basic client-side date validation
    if (dateFrom.value && dateTo.value && dateTo.value < dateFrom.value) {
        reportError.value = 'La fecha final no puede ser anterior a la fecha inicial.'
        loading.value = false
        return
    }

    try {
        let params = {}

        if (selectedConceptId.value) {
            mode.value = 'concept'
            params = { mode: 'concept', concept_id: selectedConceptId.value }
            if (dateFrom.value) params.date_from = dateFrom.value
            if (dateTo.value) params.date_to = dateTo.value
            if (selectedClubId.value) params.club_id = selectedClubId.value
            if (selectedPayTo.value && selectedPayTo.value !== 'all') params.pay_to = selectedPayTo.value
        } else {
            mode.value = 'account'
            params = { mode: 'account' }
            if (dateFrom.value) params.date_from = dateFrom.value
            if (dateTo.value) params.date_to = dateTo.value
            if (selectedClubId.value) params.club_id = selectedClubId.value
            if (selectedPayTo.value && selectedPayTo.value !== 'all') params.pay_to = selectedPayTo.value
        }

        const { data } = await axios.get(route('financial.report'), { params })

        accountsReport.value = data?.data?.accounts ?? []
        payments.value = data?.data?.payments ?? []
        summary.value = data?.data?.summary ?? summary.value
        scopeBlocks.value = []
    } catch (e) {
        console.error(e)
        reportError.value = e?.response?.data?.message || 'No se pudo obtener el reporte.'
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
        clubs.value = payload.data.clubs || []
        selectedClubId.value = payload.data.club_id || (clubs.value[0]?.id ?? null)
        concepts.value = payload.data.concepts || []
        scopes.value = payload.data.scopes || []
        members.value = payload.data.members || []
        classes.value = payload.data.classes || []
        staff.value = payload.data.staff || []
        scopeTypes.value = payload.data.scope_types || []
        payTo.value = payload.data.pay_to || []
        setDefaultPayTo()
    } catch (e) {
        console.error(e)
        loadError.value = 'No se pudo cargar la informacion del reporte.'
    } finally {
        loading.value = false
    }
})

watch(selectedClubId, async (id, old) => {
    if (id && id !== old) {
        loading.value = true
        try {
            const payload = await fetchFinancialReportBootstrap(id)
            club.value = payload.data.club
            clubs.value = payload.data.clubs || clubs.value
            concepts.value = payload.data.concepts || []
            scopes.value = payload.data.scopes || []
            members.value = payload.data.members || []
            classes.value = payload.data.classes || []
            staff.value = payload.data.staff || []
            scopeTypes.value = payload.data.scope_types || []
            payTo.value = payload.data.pay_to || []
            setDefaultPayTo()
            // reset selections to avoid cross-club mismatch
            selectedConceptId.value = null
            selectedScopeType.value = null
            selectedScopeId.value = null
            selectedMemberId.value = null
            selectedStaffId.value = null
            selectedPayTo.value = selectedPayTo.value ?? null
            payments.value = []
            summary.value = { payments_count: 0, amount_paid_sum: 0, expected_sum: 0, balance_remaining: 0, by_payment_type: {} }
            scopeBlocks.value = []
            activeTabForScope.value = {}
            reportError.value = ''
        } catch (e) {
            console.error(e)
            reportError.value = 'No se pudo cargar la informacion del club seleccionado.'
        } finally {
            loading.value = false
        }
    }
})
</script>

<template>
    <PathfinderLayout>
        <div class="min-h-screen bg-white px-4 pb-24 sm:px-6">
            <header class="pt-5 pb-3 flex items-center gap-3">
                <FunnelIcon class="h-6 w-6 text-gray-700" />
                <h1 class="text-lg font-semibold text-gray-900">Reporte financiero</h1>
                <div class="ml-auto flex items-center gap-2 text-sm">
                    <label class="text-gray-700">Club:</label>
                    <select v-model="selectedClubId"
                        class="rounded border-gray-300 py-1 text-sm focus:border-blue-500 focus:ring-blue-500">
                        <option v-for="c in clubs" :key="c.id" :value="c.id">{{ c.club_name }}</option>
                    </select>
                </div>
            </header>

            <!-- FILTER BAR -->
            <section class="rounded-2xl border border-gray-200 p-4 shadow-sm">
                <h2 class="text-base font-semibold text-gray-900">Opciones de filtro</h2>
                <p class="mt-0.5 text-sm text-gray-600">
                    Puedes generar el reporte solo por cuenta, solo por concepto, o combinando ambos.
                </p>

                <div v-if="loadError" class="mt-2 text-sm text-red-600">{{ loadError }}</div>

                <!-- Account filter -->
                <div class="mt-4 grid gap-3 sm:grid-cols-2">
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Cuenta (pay_to)</label>
                        <select v-model="selectedPayTo"
                            class="mt-1 w-full rounded-lg border-gray-300 py-2 text-sm focus:border-blue-500 focus:ring-blue-500">
                            <option value="all">Todas las cuentas</option>
                            <option v-for="p in payTo" :key="p.value" :value="p.value">{{ p.label }}</option>
                        </select>
                    </div>
                </div>

                <!-- Concept filters -->
                <div class="mt-4 grid gap-3 sm:grid-cols-2">
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Concepto</label>
                        <select v-model="selectedConceptId"
                            class="mt-1 w-full rounded-lg border-gray-300 py-2 text-sm focus:border-blue-500 focus:ring-blue-500">
                            <option :value="null" disabled>Selecciona un concepto…</option>
                            <option v-for="c in concepts" :key="c.id" :value="c.id">{{ c.concept }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Rango de fechas (opcional)</label>
                        <div class="mt-1 flex gap-2">
                            <input type="date" v-model="dateFrom"
                                class="w-full rounded-lg border-gray-300 py-2 text-sm" />
                            <input type="date" v-model="dateTo"
                                class="w-full rounded-lg border-gray-300 py-2 text-sm" />
                        </div>
                    </div>
                </div>

                <div class="mt-5 flex justify-end">
                    <button @click="fetchReport" :disabled="loading"
                        class="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-60">
                        <ArrowPathIcon v-if="loading" class="h-4 w-4 animate-spin" />
                        <span>{{ loading ? 'Cargando…' : 'Generar reporte' }}</span>
                    </button>
                </div>
            </section>

            <!-- RESULTS -->
            <section v-if="accountsReport.length" class="mt-6 space-y-6">
                <div v-for="acc in accountsReport" :key="acc.pay_to"
                    class="rounded-2xl border border-gray-200 shadow-sm overflow-hidden bg-white">
                    <div class="p-4 border-b border-gray-100">
                        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                            <div>
                                <h3 class="text-base font-semibold text-gray-900">{{ acc.label }}</h3>
                                <p class="text-xs text-gray-600">{{ acc.pay_to }}</p>
                            </div>
                            <div class="grid grid-cols-3 gap-4 text-right">
                                <div>
                                    <div class="text-xs text-gray-500">Ingresos</div>
                                    <div class="font-semibold text-emerald-700">{{ fmtMoney(acc.totals.paid) }}</div>
                                </div>
                                <div>
                                    <div class="text-xs text-gray-500">Gastos</div>
                                    <div class="font-semibold text-amber-700">{{ fmtMoney(acc.totals.spent) }}</div>
                                </div>
                                <div>
                                    <div class="text-xs text-gray-500">Neto</div>
                                    <div class="font-semibold text-gray-900">{{ fmtMoney(acc.totals.net) }}</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="md:hidden p-4 space-y-3">
                        <div v-for="e in acc.entries" :key="`${e.entry_type}-${e.id}`"
                            class="rounded-xl border border-gray-200 bg-white p-3 shadow-sm">
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <div class="text-xs text-gray-500">{{ formatDateMDY(e.date) }}</div>
                                    <div class="text-sm font-semibold text-gray-900">{{ e.concept }}</div>
                                </div>
                                <span :class="[
                                    'inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-semibold',
                                    e.entry_type === 'payment' ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700'
                                ]">
                                    {{ e.entry_type === 'payment' ? 'Ingreso' : 'Gasto' }}
                                </span>
                            </div>
                            <div v-if="acc.pay_to === 'reimbursement_to'" class="mt-1 text-xs text-gray-600">
                                <span class="font-medium text-gray-700">Miembro/Personal:</span> {{ e.member ?? e.staff ?? '—' }}
                            </div>
                            <div class="mt-2 text-sm">
                                <span v-if="e.entry_type === 'expense'" class="font-semibold text-amber-700">-{{ fmtMoney(e.amount) }}</span>
                                <span v-else class="font-semibold text-emerald-700">{{ fmtMoney(e.amount) }}</span>
                            </div>
                        </div>
                        <div class="rounded-xl border border-gray-200 bg-gray-50 p-3 text-sm">
                            <div class="flex items-center justify-between">
                                <span class="text-gray-600">Totales</span>
                                <div class="text-right">
                                    <div class="text-amber-700">-{{ fmtMoney(acc.totals.spent) }}</div>
                                    <div class="text-emerald-700">{{ fmtMoney(acc.totals.paid) }}</div>
                                </div>
                            </div>
                            <div class="mt-2 flex items-center justify-between font-semibold">
                                <span class="text-gray-700">Saldo de la cuenta</span>
                                <span>{{ fmtMoney(acc.totals.net) }}</span>
                            </div>
                        </div>
                    </div>

                    <div class="hidden md:block overflow-x-auto">
                        <table class="min-w-full text-sm text-gray-700">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-4 py-2 text-left font-semibold">Fecha</th>
                                    <th class="px-4 py-2 text-left font-semibold">Tipo</th>
                                    <th v-if="acc.pay_to === 'reimbursement_to'" class="px-4 py-2 text-left font-semibold">Miembro/Personal</th>
                                    <th class="px-4 py-2 text-left font-semibold">Concepto</th>
                                    <th class="px-4 py-2 text-right font-semibold">Cargos</th>
                                    <th class="px-4 py-2 text-right font-semibold">Abonos</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="e in acc.entries" :key="`${e.entry_type}-${e.id}`" class="border-t">
                                    <td class="px-4 py-2">{{ formatDateMDY(e.date) }}</td>
                                    <td class="px-4 py-2">
                                        <span :class="[
                                            'inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold',
                                            e.entry_type === 'payment' ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700'
                                        ]">
                                            {{ e.entry_type === 'payment' ? 'Ingreso' : 'Gasto' }}
                                        </span>
                                    </td>
                                    <td v-if="acc.pay_to === 'reimbursement_to'" class="px-4 py-2">{{ e.member ?? e.staff ?? '—' }}</td>
                                    <td class="px-4 py-2">{{ e.concept }}</td>
                                    <td class="px-4 py-2 text-right">
                                        <span v-if="e.entry_type === 'expense'" class="text-amber-700">-{{ fmtMoney(e.amount) }}</span>
                                        <span v-else class="text-gray-400">—</span>
                                    </td>
                                    <td class="px-4 py-2 text-right">
                                        <span v-if="e.entry_type === 'payment'" class="text-emerald-700">{{ fmtMoney(e.amount) }}</span>
                                        <span v-else class="text-gray-400">—</span>
                                    </td>
                                </tr>
                            </tbody>
                            <tfoot>
                                <tr class="border-t bg-gray-50 font-semibold">
                                    <td class="px-4 py-2" :colspan="acc.pay_to === 'reimbursement_to' ? 4 : 3">Totales</td>
                                    <td class="px-4 py-2 text-right text-amber-700">-{{ fmtMoney(acc.totals.spent) }}</td>
                                    <td class="px-4 py-2 text-right text-emerald-700">{{ fmtMoney(acc.totals.paid) }}</td>
                                </tr>
                                <tr class="border-t bg-white font-semibold">
                                    <td class="px-4 py-2" :colspan="acc.pay_to === 'reimbursement_to' ? 4 : 3">Saldo de la cuenta</td>
                                    <td class="px-4 py-2 text-right" :colspan="2">{{ fmtMoney(acc.totals.net) }}</td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </section>

            <div v-else-if="!loading" class="mt-6 text-sm text-gray-500 text-center">
                No se encontraron pagos para los filtros seleccionados.
            </div>

            <div v-if="reportError" class="mt-3 text-sm text-red-600 text-center">{{ reportError }}</div>
        </div>
    </PathfinderLayout>
</template>
