<script setup>
import { ref, computed, onMounted, watch } from 'vue'
import PathfinderLayout from '@/Layouts/PathfinderLayout.vue'
import { ArrowPathIcon, ExclamationTriangleIcon } from '@heroicons/vue/24/outline'
import { fetchFinancialReportBootstrap, fetchFinancialAccountBalances, uploadReimbursementReceipt, recalculateAccounts } from '@/Services/api'
import { useGeneral } from '@/Composables/useGeneral'
import { compressImage } from '@/Utils/imageCompression'
import { useAuth } from '@/Composables/useAuth'
import { useLocale } from '@/Composables/useLocale'

const payTo = ref([])
const accountBalances = ref([])
const accountPayments = ref([])
const expenses = ref([])
const receipts = ref([])
const loading = ref(false)
const loadError = ref('')
const balancesLoading = ref(false)
const balancesError = ref('')
const clubs = ref([])
const selectedClubId = ref(null)
const uploadingReimbursementId = ref(null)
const reimbursementReceiptInputs = ref({})
const { showToast } = useGeneral()
const { user } = useAuth()
const { tr } = useLocale()
const canSelectClub = computed(() => user.value?.profile_type === 'superadmin')

const MAX_RECEIPT_MB = 5
const MAX_RECEIPT_BYTES = MAX_RECEIPT_MB * 1024 * 1024
const MAX_RECEIPT_DIM = 1600

const payToLabel = (val) => {
    const match = (payTo.value || []).find(p => p.value === val)
    return match?.label || (val ?? tr('Sin asignar', 'Unassigned'))
}

const loadBootstrap = async (clubId = null) => {
    loading.value = true
    loadError.value = ''
    try {
        const payload = await fetchFinancialReportBootstrap(clubId)
        payTo.value = payload.data.pay_to || []
        clubs.value = payload.data.clubs || []
        if (!selectedClubId.value) {
            selectedClubId.value = payload.data.club_id || (clubs.value[0]?.id ?? null)
        }
        await loadBalances(selectedClubId.value)
    } catch (e) {
        console.error(e)
        loadError.value = e?.response?.data?.message || tr('No se pudo cargar la informacion de cuentas.', 'Could not load account information.')
    } finally {
        loading.value = false
    }
}

const loadBalances = async (clubId = null) => {
    balancesError.value = ''
    balancesLoading.value = true
    try {
        const { data } = await fetchFinancialAccountBalances(clubId || selectedClubId.value)
        if (!selectedClubId.value) {
            selectedClubId.value = data?.club_id || null
        }
        const accs = data?.accounts ?? []
        accountBalances.value = Array.isArray(accs) ? accs : Object.values(accs)
        const pays = data?.payments ?? []
        accountPayments.value = Array.isArray(pays) ? pays : Object.values(pays)
        const exps = data?.expenses ?? []
        expenses.value = Array.isArray(exps) ? exps : Object.values(exps)
        receipts.value = Array.isArray(data?.receipts) ? data.receipts : []
        if (!clubs.value.length && data?.clubs) clubs.value = data.clubs
    } catch (e) {
        console.error(e)
        balancesError.value = e?.response?.data?.message || tr('No se pudieron cargar los saldos.', 'Could not load balances.')
    } finally {
        balancesLoading.value = false
    }
}

const refreshBalances = async (clubId = null) => {
    const targetClubId = clubId || selectedClubId.value
    if (!targetClubId) return

    balancesError.value = ''
    balancesLoading.value = true
    try {
        await recalculateAccounts(targetClubId)
        await loadBalances(targetClubId)
        showToast(tr('Saldos recalculados.', 'Balances recalculated.'), 'success')
    } catch (e) {
        console.error(e)
        balancesError.value = e?.response?.data?.message || tr('No se pudieron recalcular los saldos.', 'Could not recalculate balances.')
    } finally {
        balancesLoading.value = false
    }
}

const fmtMoney = (n) => `$${Number(n ?? 0).toFixed(2)}`
const locationLabel = (value) => value === 'bank' ? tr('Banco', 'Bank') : value === 'cash' ? tr('Efectivo', 'Cash') : value === 'internal' ? tr('Interno', 'Internal') : '—'
const fmtBytes = (bytes) => {
    if (!Number.isFinite(bytes)) return '—'
    const mb = bytes / (1024 * 1024)
    return `${mb.toFixed(2)}MB`
}

const triggerReimbursementReceiptUpload = (expenseId) => {
    const input = reimbursementReceiptInputs.value[expenseId]
    if (input) input.click()
}

const handleReimbursementReceiptSelected = async (expense, event) => {
    const [file] = event.target.files || []
    event.target.value = ''
    if (!file || !expense?.id) return

    let uploadFile = file
    if (file.size > MAX_RECEIPT_BYTES) {
        showToast(tr(`La imagen supera ${MAX_RECEIPT_MB}MB. Intentando comprimir...`, `The image is larger than ${MAX_RECEIPT_MB}MB. Trying to compress...`), 'info')
        try {
            uploadFile = await compressImage(file, { maxBytes: MAX_RECEIPT_BYTES, maxDim: MAX_RECEIPT_DIM })
        } catch {
            showToast(tr('No se pudo comprimir la imagen.', 'Could not compress the image.'), 'error')
            return
        }
        if (uploadFile.size > MAX_RECEIPT_BYTES) {
            showToast(tr(`La imagen sigue siendo muy grande. Maximo ${MAX_RECEIPT_MB}MB, actual ${fmtBytes(uploadFile.size)}.`, `The image is still too large. Maximum ${MAX_RECEIPT_MB}MB, current ${fmtBytes(uploadFile.size)}.`), 'error')
            return
        }
    }

    uploadingReimbursementId.value = expense.id
    try {
        const { data } = await uploadReimbursementReceipt(expense.id, uploadFile)
        const idx = expenses.value.findIndex(e => e.id === expense.id)
        if (idx !== -1) expenses.value[idx] = data?.data
        showToast(tr('Recibo de reembolso subido.', 'Reimbursement receipt uploaded.'), 'success')
    } catch (e) {
        showToast(e?.response?.data?.message || tr('No se pudo subir el recibo de reembolso.', 'Could not upload reimbursement receipt.'), 'error')
    } finally {
        uploadingReimbursementId.value = null
    }
}

onMounted(loadBootstrap)

watch(selectedClubId, async (id, old) => {
    if (id && id !== old) {
        await loadBalances(id)
    }
})
</script>

<template>
    <PathfinderLayout>
        <div class="min-h-screen bg-white px-4 pb-24 sm:px-6">
            <header class="pt-5 pb-3 flex items-center gap-3">
                <div class="h-6 w-6 rounded bg-blue-50 border border-blue-100 flex items-center justify-center">
                    <span class="text-blue-700 text-xs font-semibold">$</span>
                </div>
                <div>
                    <h1 class="text-lg font-semibold text-gray-900">{{ tr('Saldos de cuentas', 'Account Balances') }}</h1>
                    <p class="text-sm text-gray-600">{{ tr('Entradas, gastos y saldo por cuenta (pay_to).', 'Income, expenses, and balance by account (pay_to).') }}</p>
                </div>
            </header>

            <section class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm">
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                    <div>
                        <h2 class="text-base font-semibold text-gray-900">{{ tr('Cuentas', 'Accounts') }}</h2>
                        <p class="text-sm text-gray-600">{{ tr('Cargado automaticamente desde conceptos de pago.', 'Loaded automatically from payment concepts.') }}</p>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <div class="flex items-center gap-2 text-sm">
                            <label class="text-gray-700">{{ tr('Club:', 'Club:') }}</label>
                            <select v-if="canSelectClub" v-model="selectedClubId"
                                class="rounded border-gray-300 py-1 text-sm focus:border-blue-500 focus:ring-blue-500">
                                <option v-for="c in clubs" :key="c.id" :value="c.id">{{ c.club_name }}</option>
                            </select>
                            <strong v-else class="text-gray-700">
                                {{ clubs.find(c => String(c.id) === String(selectedClubId))?.club_name || '—' }}
                            </strong>
                        </div>
                        <button @click="() => loadBootstrap(selectedClubId)" :disabled="loading"
                            class="inline-flex items-center gap-2 rounded-lg border border-gray-200 px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-60">
                            <ArrowPathIcon v-if="loading" class="h-4 w-4 animate-spin" />
                            <span>{{ loading ? tr('Recargando...', 'Reloading...') : tr('Recargar cuentas', 'Reload accounts') }}</span>
                        </button>
                        <button @click="() => refreshBalances(selectedClubId)" :disabled="balancesLoading"
                            class="inline-flex items-center gap-2 rounded-lg border border-blue-200 px-3 py-1.5 text-sm font-medium text-blue-700 hover:bg-blue-50 disabled:opacity-60">
                            <ArrowPathIcon v-if="balancesLoading" class="h-4 w-4 animate-spin" />
                            <span>{{ balancesLoading ? tr('Actualizando...', 'Updating...') : tr('Actualizar saldos', 'Update balances') }}</span>
                        </button>
                        <a :href="route('financial.accounts.pdf', { club_id: selectedClubId })" target="_blank" rel="noopener"
                            class="inline-flex items-center gap-2 rounded-lg border border-gray-200 px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50">
                            <span>{{ tr('Descargar PDF', 'Download PDF') }}</span>
                        </a>
                    </div>
                </div>

                <div v-if="loadError" class="mt-2 text-sm text-red-600">{{ loadError }}</div>
                <div v-if="balancesError" class="mt-2 text-sm text-red-600">{{ balancesError }}</div>

                <div v-if="!accountBalances.length && !balancesLoading" class="mt-3 text-sm text-gray-500">
                    {{ tr('Aun no hay datos de cuentas.', 'There is no account data yet.') }}
                </div>

                <div v-else class="mt-3">
                    <div class="space-y-3 md:hidden">
                        <div v-for="acc in accountBalances" :key="acc.account || acc.label"
                            class="rounded-xl border border-gray-200 bg-white p-3 shadow-sm">
                            <div class="text-sm font-semibold text-gray-900">{{ acc.label || payToLabel(acc.account) }}</div>
                            <div class="text-xs text-gray-600">{{ acc.account ?? tr('Sin asignar', 'Unassigned') }}</div>
                            <div class="mt-2 grid grid-cols-2 gap-2 text-xs sm:grid-cols-5">
                                <div>
                                    <div class="text-gray-500">{{ tr('Entradas', 'Income') }}</div>
                                    <div class="font-semibold text-emerald-700">{{ fmtMoney(acc.entries) }}</div>
                                </div>
                                <div>
                                    <div class="text-gray-500">{{ tr('Gastos', 'Expenses') }}</div>
                                    <div class="font-semibold text-amber-700">{{ fmtMoney(acc.expenses) }}</div>
                                </div>
                                <div>
                                    <div class="text-gray-500">{{ tr('Saldo', 'Balance') }}</div>
                                    <div class="font-semibold" :class="Number(acc.balance) > 0 ? 'text-emerald-700' : 'text-red-700'">
                                        {{ fmtMoney(acc.balance) }}
                                    </div>
                                </div>
                                <div>
                                    <div class="text-gray-500">{{ tr('Efectivo', 'Cash') }}</div>
                                    <div class="font-semibold text-gray-900">{{ fmtMoney(acc.cash_balance) }}</div>
                                </div>
                                <div>
                                    <div class="text-gray-500">{{ tr('Banco', 'Bank') }}</div>
                                    <div class="font-semibold text-gray-900">{{ fmtMoney(acc.bank_balance) }}</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="hidden md:block overflow-x-auto">
                        <table class="min-w-full text-sm text-gray-700">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-2 text-left font-semibold">{{ tr('Cuenta', 'Account') }}</th>
                                <th class="px-4 py-2 text-left font-semibold">{{ tr('Entradas', 'Income') }}</th>
                                <th class="px-4 py-2 text-left font-semibold">{{ tr('Gastos', 'Expenses') }}</th>
                                <th class="px-4 py-2 text-left font-semibold">{{ tr('Saldo', 'Balance') }}</th>
                                <th class="px-4 py-2 text-left font-semibold">{{ tr('Efectivo', 'Cash') }}</th>
                                <th class="px-4 py-2 text-left font-semibold">{{ tr('Banco', 'Bank') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="acc in accountBalances" :key="acc.account || acc.label" class="border-t">
                                <td class="px-4 py-2">{{ acc.label || payToLabel(acc.account) }}</td>
                                <td class="px-4 py-2">{{ fmtMoney(acc.entries) }}</td>
                                <td class="px-4 py-2">{{ fmtMoney(acc.expenses) }}</td>
                                <td class="px-4 py-2 font-semibold"
                                    :class="Number(acc.balance) > 0 ? 'text-emerald-700' : 'text-red-700'">
                                    {{ fmtMoney(acc.balance) }}
                                </td>
                                <td class="px-4 py-2">{{ fmtMoney(acc.cash_balance) }}</td>
                                <td class="px-4 py-2">{{ fmtMoney(acc.bank_balance) }}</td>
                            </tr>
                        </tbody>
                    </table>
                    </div>
                </div>
            </section>

            <section class="mt-6 rounded-2xl border border-gray-200 bg-white p-4 shadow-sm">
                <div class="flex items-center justify-between gap-2">
                    <div>
                        <h2 class="text-base font-semibold text-gray-900">{{ tr('Entradas de ingresos', 'Income Entries') }}</h2>
                        <p class="text-sm text-gray-600">{{ tr('Pagos registrados con su cuenta (pay_to).', 'Payments recorded with their account (pay_to).') }}</p>
                    </div>
                </div>

                <div v-if="balancesLoading" class="mt-2 text-sm text-gray-500">{{ tr('Cargando...', 'Loading...') }}</div>
                <div v-else-if="!accountPayments.length" class="mt-2 text-sm text-gray-500">{{ tr('No se encontraron pagos.', 'No payments found.') }}</div>
                <div v-else class="mt-3">
                    <div class="space-y-3 md:hidden">
                        <div v-for="p in accountPayments" :key="p.id" class="rounded-xl border border-gray-200 bg-white p-3 shadow-sm">
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <div class="text-sm font-semibold text-gray-900">{{ fmtMoney(p.amount_paid) }}</div>
                                    <div class="text-xs text-gray-600">{{ new Date(p.payment_date).toLocaleDateString() }}</div>
                                </div>
                                <span class="inline-flex items-center rounded-full bg-emerald-50 px-2 py-0.5 text-[11px] font-semibold text-emerald-700">
                                    {{ p.payment_type }}
                                </span>
                            </div>
                            <div class="mt-2 text-xs text-gray-600">
                                <div><span class="font-medium text-gray-700">{{ tr('Cuenta:', 'Account:') }}</span> {{ p.account_label || payToLabel(p.account) }}</div>
                                <div><span class="font-medium text-gray-700">{{ tr('Ubicación:', 'Location:') }}</span> {{ locationLabel(p.location) }}</div>
                                <div><span class="font-medium text-gray-700">{{ tr('Concepto:', 'Concept:') }}</span> {{ p.concept }}</div>
                                <div><span class="font-medium text-gray-700">{{ tr('Pagador:', 'Payer:') }}</span> {{ p.member?.applicant_name ?? p.staff?.name ?? '—' }}</div>
                                <div v-if="p.payment_type === 'zelle' && p.zelle_phone"><span class="font-medium text-gray-700">{{ tr('Zelle remitente:', 'Zelle sender:') }}</span> {{ p.zelle_phone }}</div>
                            </div>
                            <div class="mt-3">
                                <a v-if="p.receipt_url"
                                    :href="p.receipt_url"
                                    target="_blank" rel="noopener"
                                    class="inline-flex items-center rounded-md border border-gray-200 px-2 py-1 text-xs font-medium text-gray-700 hover:bg-gray-50">
                                    {{ tr('Ver recibo', 'View receipt') }}
                                </a>
                                <span v-else class="text-xs text-gray-400 inline-flex items-center gap-1">
                                    <ExclamationTriangleIcon class="h-4 w-4 text-amber-600" />
                                    {{ tr('Sin recibo', 'No receipt') }}
                                </span>
                            </div>
                        </div>
                    </div>

                    <div class="hidden md:block overflow-x-auto">
                        <table class="min-w-full text-sm text-gray-700">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-2 text-left font-semibold">{{ tr('Fecha', 'Date') }}</th>
                                <th class="px-4 py-2 text-left font-semibold">{{ tr('Cuenta', 'Account') }}</th>
                                <th class="px-4 py-2 text-left font-semibold">{{ tr('Concepto', 'Concept') }}</th>
                                <th class="px-4 py-2 text-left font-semibold">{{ tr('Pagador', 'Payer') }}</th>
                                <th class="px-4 py-2 text-left font-semibold">{{ tr('Monto', 'Amount') }}</th>
                                <th class="px-4 py-2 text-left font-semibold">{{ tr('Tipo', 'Type') }}</th>
                                <th class="px-4 py-2 text-left font-semibold">{{ tr('Ubicación', 'Location') }}</th>
                                <th class="px-4 py-2 text-left font-semibold">{{ tr('Recibo', 'Receipt') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="p in accountPayments" :key="p.id" class="border-t">
                                <td class="px-4 py-2">{{ new Date(p.payment_date).toLocaleDateString() }}</td>
                                <td class="px-4 py-2">{{ p.account_label || payToLabel(p.account) }}</td>
                                <td class="px-4 py-2">{{ p.concept }}</td>
                                <td class="px-4 py-2">{{ p.member?.applicant_name ?? p.staff?.name ?? '—' }}</td>
                                <td class="px-4 py-2">{{ fmtMoney(p.amount_paid) }}</td>
                                <td class="px-4 py-2 capitalize">
                                    <div>{{ p.payment_type }}</div>
                                    <div v-if="p.payment_type === 'zelle' && p.zelle_phone" class="text-xs text-gray-500">{{ tr('De', 'From') }} {{ p.zelle_phone }}</div>
                                </td>
                                <td class="px-4 py-2">{{ locationLabel(p.location) }}</td>
                                <td class="px-4 py-2">
                                    <div class="flex items-center gap-2">
                                        <span v-if="p.receipt_ref" class="text-xs text-gray-600">{{ p.receipt_ref }}</span>
                                        <a v-if="p.receipt_url"
                                            :href="p.receipt_url"
                                            target="_blank" rel="noopener"
                                            class="inline-flex items-center rounded-md border border-gray-200 px-2 py-1 text-xs font-medium text-gray-700 hover:bg-gray-50">
                                            {{ tr('Ver recibo', 'View receipt') }}
                                        </a>
                                        <span v-else class="text-xs text-gray-400 inline-flex items-center gap-1">
                                            <ExclamationTriangleIcon class="h-4 w-4 text-amber-600" />
                                            {{ tr('Sin recibo', 'No receipt') }}
                                        </span>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    </div>
                </div>
            </section>

            <section class="mt-6 rounded-2xl border border-gray-200 bg-white p-4 shadow-sm">
                <div class="flex items-center justify-between gap-2">
                    <div>
                        <h2 class="text-base font-semibold text-gray-900">{{ tr('Gastos', 'Expenses') }}</h2>
                        <p class="text-sm text-gray-600">{{ tr('Egresos registrados contra cuentas.', 'Expenses recorded against accounts.') }}</p>
                    </div>
                </div>

                <div v-if="balancesLoading" class="mt-2 text-sm text-gray-500">{{ tr('Cargando...', 'Loading...') }}</div>
                <div v-else-if="!expenses.length" class="mt-2 text-sm text-gray-500">{{ tr('No se encontraron gastos.', 'No expenses found.') }}</div>
                <div v-else class="mt-3">
                    <div class="space-y-3 md:hidden">
                        <div v-for="e in expenses" :key="e.id" class="rounded-xl border border-gray-200 bg-white p-3 shadow-sm">
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <div class="text-sm font-semibold text-gray-900">{{ fmtMoney(e.amount) }}</div>
                                    <div class="text-xs text-gray-600">{{ new Date(e.expense_date).toLocaleDateString() }}</div>
                                </div>
                                <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-semibold"
                                    :class="e.status === 'completed' ? 'bg-emerald-50 text-emerald-700' : e.status === 'pending_reimbursement' ? 'bg-purple-50 text-purple-700' : 'bg-amber-50 text-amber-700'">
                                    {{ e.status === 'completed' ? tr('Completado', 'Completed') : e.status === 'pending_reimbursement' ? tr('Reembolso pendiente', 'Reimbursement pending') : tr('En proceso', 'In process') }}
                                </span>
                            </div>
                            <div class="mt-2 text-xs text-gray-600">
                                <div><span class="font-medium text-gray-700">{{ tr('Cuenta:', 'Account:') }}</span> {{ e.pay_to_label || payToLabel(e.pay_to) }}</div>
                                <div><span class="font-medium text-gray-700">{{ tr('Ubicación:', 'Location:') }}</span> {{ locationLabel(e.location) }}</div>
                                <div v-if="e.is_event_related"><span class="font-medium text-gray-700">{{ tr('Origen:', 'Source:') }}</span> {{ tr('Evento', 'Event') }}{{ e.event_title ? ` · ${e.event_title}` : '' }}</div>
                                <div><span class="font-medium text-gray-700">{{ tr('Reembolsado a:', 'Reimbursed to:') }}</span> {{ e.reimbursed_to || '—' }}</div>
                                <div><span class="font-medium text-gray-700">{{ tr('Descripcion:', 'Description:') }}</span> {{ e.description || '—' }}</div>
                            </div>
                            <div class="mt-3">
                                <div class="flex flex-wrap items-center gap-2">
                                    <a v-if="e.receipt_url"
                                        :href="e.receipt_url"
                                        target="_blank" rel="noopener"
                                        class="inline-flex items-center rounded-md border border-gray-200 px-2 py-1 text-xs font-medium text-gray-700 hover:bg-gray-50">
                                        {{ tr('Recibo gasto', 'Expense receipt') }}
                                    </a>
                                    <span v-else class="text-xs text-gray-400 inline-flex items-center gap-1">
                                        <ExclamationTriangleIcon class="h-4 w-4 text-amber-600" />
                                        {{ tr('Sin recibo gasto', 'No expense receipt') }}
                                    </span>
                                    <a v-if="e.reimbursement_receipt_url"
                                        :href="e.reimbursement_receipt_url"
                                        target="_blank" rel="noopener"
                                        class="inline-flex items-center rounded-md border border-gray-200 px-2 py-1 text-xs font-medium text-gray-700 hover:bg-gray-50">
                                        {{ tr('Recibo reembolso', 'Reimbursement receipt') }}
                                    </a>
                                    <button
                                        v-if="e.pay_to === 'reimbursement_to' && e.status === 'completed' && !e.reimbursement_receipt_url"
                                        @click="triggerReimbursementReceiptUpload(e.id)"
                                        class="inline-flex items-center gap-1 rounded-md bg-blue-50 px-2 py-1 text-xs font-medium text-blue-700 hover:bg-blue-100">
                                        <span>{{ tr('Adjuntar recibo reembolso', 'Attach reimbursement receipt') }}</span>
                                    </button>
                                    <input type="file" accept="image/*" class="hidden"
                                        :ref="el => { if (el) reimbursementReceiptInputs[e.id] = el }"
                                        @change="(ev) => handleReimbursementReceiptSelected(e, ev)" />
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="hidden md:block overflow-x-auto">
                        <table class="min-w-full text-sm text-gray-700">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-2 text-left font-semibold">{{ tr('Fecha', 'Date') }}</th>
                                <th class="px-4 py-2 text-left font-semibold">{{ tr('Cuenta', 'Account') }}</th>
                                <th class="px-4 py-2 text-left font-semibold">{{ tr('Ubicación', 'Location') }}</th>
                                <th class="px-4 py-2 text-left font-semibold">{{ tr('Monto', 'Amount') }}</th>
                                <th class="px-4 py-2 text-left font-semibold">{{ tr('Estado', 'Status') }}</th>
                                <th class="px-4 py-2 text-left font-semibold">{{ tr('Recibo', 'Receipt') }}</th>
                                <th class="px-4 py-2 text-left font-semibold">{{ tr('Reembolsado a', 'Reimbursed to') }}</th>
                                <th class="px-4 py-2 text-left font-semibold">{{ tr('Descripcion', 'Description') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="e in expenses" :key="e.id" class="border-t">
                                <td class="px-4 py-2">{{ new Date(e.expense_date).toLocaleDateString() }}</td>
                                <td class="px-4 py-2">{{ e.pay_to_label || payToLabel(e.pay_to) }}</td>
                                <td class="px-4 py-2">{{ locationLabel(e.location) }}</td>
                                <td class="px-4 py-2">{{ fmtMoney(e.amount) }}</td>
                                <td class="px-4 py-2">
                                    <span
                                        :class="[
                                            'inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold',
                        e.status === 'completed'
                            ? 'bg-emerald-50 text-emerald-700 ring-1 ring-emerald-100'
                            : 'bg-amber-50 text-amber-700 ring-1 ring-amber-100'
                    ]">
                                        {{ e.status === 'completed' ? tr('Completado', 'Completed') : tr('En proceso', 'In process') }}
                                    </span>
                                </td>
                                <td class="px-4 py-2">
                                    <div class="flex items-center gap-2">
                                        <span v-if="e.receipt_ref" class="text-xs text-gray-600">{{ e.receipt_ref }}</span>
                                        <a v-if="e.receipt_url"
                                            :href="e.receipt_url"
                                            target="_blank" rel="noopener"
                                            class="inline-flex items-center rounded-md border border-gray-200 px-2 py-1 text-xs font-medium text-gray-700 hover:bg-gray-50">
                                            {{ tr('Recibo gasto', 'Expense receipt') }}
                                        </a>
                                        <span v-else class="text-xs text-gray-400 inline-flex items-center gap-1">
                                            <ExclamationTriangleIcon class="h-4 w-4 text-amber-600" />
                                            {{ tr('Sin recibo gasto', 'No expense receipt') }}
                                        </span>
                                        <span v-if="e.reimbursement_receipt_ref" class="text-xs text-gray-600">{{ e.reimbursement_receipt_ref }}</span>
                                        <a v-if="e.reimbursement_receipt_url"
                                            :href="e.reimbursement_receipt_url"
                                            target="_blank" rel="noopener"
                                            class="inline-flex items-center rounded-md border border-gray-200 px-2 py-1 text-xs font-medium text-gray-700 hover:bg-gray-50">
                                            {{ tr('Recibo reembolso', 'Reimbursement receipt') }}
                                        </a>
                                        <button
                                            v-if="e.pay_to === 'reimbursement_to' && e.status === 'completed' && !e.reimbursement_receipt_url"
                                            @click="triggerReimbursementReceiptUpload(e.id)"
                                            class="inline-flex items-center gap-1 rounded-md bg-blue-50 px-2 py-1 text-xs font-medium text-blue-700 hover:bg-blue-100">
                                            <span>{{ tr('Adjuntar recibo reembolso', 'Attach reimbursement receipt') }}</span>
                                        </button>
                                        <input type="file" accept="image/*" class="hidden"
                                            :ref="el => { if (el) reimbursementReceiptInputs[e.id] = el }"
                                            @change="(ev) => handleReimbursementReceiptSelected(e, ev)" />
                                    </div>
                                </td>
                                <td class="px-4 py-2">{{ e.reimbursed_to || '—' }}</td>
                                <td class="px-4 py-2">
                                    <div>{{ e.description || '—' }}</div>
                                    <div v-if="e.is_event_related" class="mt-1 text-xs text-blue-600">
                                        {{ tr('Evento', 'Event') }}{{ e.event_title ? ` · ${e.event_title}` : '' }}
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    </div>
                </div>
            </section>
        </div>
    </PathfinderLayout>
</template>
