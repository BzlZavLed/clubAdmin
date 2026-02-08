<script setup>
import { ref, onMounted, watch } from 'vue'
import PathfinderLayout from '@/Layouts/PathfinderLayout.vue'
import { ArrowPathIcon, ExclamationTriangleIcon } from '@heroicons/vue/24/outline'
import { fetchFinancialReportBootstrap, fetchFinancialAccountBalances, uploadReimbursementReceipt } from '@/Services/api'
import { useGeneral } from '@/Composables/useGeneral'
import { compressImage } from '@/Utils/imageCompression'

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

const MAX_RECEIPT_MB = 5
const MAX_RECEIPT_BYTES = MAX_RECEIPT_MB * 1024 * 1024
const MAX_RECEIPT_DIM = 1600

const payToLabel = (val) => {
    const match = (payTo.value || []).find(p => p.value === val)
    return match?.label || (val ?? 'Sin asignar')
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
        loadError.value = e?.response?.data?.message || 'No se pudo cargar la informacion de cuentas.'
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
        balancesError.value = e?.response?.data?.message || 'No se pudieron cargar los saldos.'
    } finally {
        balancesLoading.value = false
    }
}

const fmtMoney = (n) => `$${Number(n ?? 0).toFixed(2)}`
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
        showToast(`La imagen supera ${MAX_RECEIPT_MB}MB. Intentando comprimir...`, 'info')
        try {
            uploadFile = await compressImage(file, { maxBytes: MAX_RECEIPT_BYTES, maxDim: MAX_RECEIPT_DIM })
        } catch {
            showToast('No se pudo comprimir la imagen.', 'error')
            return
        }
        if (uploadFile.size > MAX_RECEIPT_BYTES) {
            showToast(`La imagen sigue siendo muy grande. Maximo ${MAX_RECEIPT_MB}MB, actual ${fmtBytes(uploadFile.size)}.`, 'error')
            return
        }
    }

    uploadingReimbursementId.value = expense.id
    try {
        const { data } = await uploadReimbursementReceipt(expense.id, uploadFile)
        const idx = expenses.value.findIndex(e => e.id === expense.id)
        if (idx !== -1) expenses.value[idx] = data?.data
        showToast('Recibo de reembolso subido.', 'success')
    } catch (e) {
        showToast(e?.response?.data?.message || 'No se pudo subir el recibo de reembolso.', 'error')
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
                    <h1 class="text-lg font-semibold text-gray-900">Saldos de cuentas</h1>
                    <p class="text-sm text-gray-600">Entradas, gastos y saldo por cuenta (pay_to).</p>
                </div>
            </header>

            <section class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm">
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                    <div>
                        <h2 class="text-base font-semibold text-gray-900">Cuentas</h2>
                        <p class="text-sm text-gray-600">Cargado automaticamente desde conceptos de pago.</p>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <div class="flex items-center gap-2 text-sm">
                            <label class="text-gray-700">Club:</label>
                            <select v-model="selectedClubId"
                                class="rounded border-gray-300 py-1 text-sm focus:border-blue-500 focus:ring-blue-500">
                                <option v-for="c in clubs" :key="c.id" :value="c.id">{{ c.club_name }}</option>
                            </select>
                        </div>
                        <button @click="() => loadBootstrap(selectedClubId)" :disabled="loading"
                            class="inline-flex items-center gap-2 rounded-lg border border-gray-200 px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-60">
                            <ArrowPathIcon v-if="loading" class="h-4 w-4 animate-spin" />
                            <span>{{ loading ? 'Recargando…' : 'Recargar cuentas' }}</span>
                        </button>
                        <button @click="() => loadBalances(selectedClubId)" :disabled="balancesLoading"
                            class="inline-flex items-center gap-2 rounded-lg border border-blue-200 px-3 py-1.5 text-sm font-medium text-blue-700 hover:bg-blue-50 disabled:opacity-60">
                            <ArrowPathIcon v-if="balancesLoading" class="h-4 w-4 animate-spin" />
                            <span>{{ balancesLoading ? 'Actualizando…' : 'Actualizar saldos' }}</span>
                        </button>
                        <a :href="route('financial.accounts.pdf', { club_id: selectedClubId })" target="_blank" rel="noopener"
                            class="inline-flex items-center gap-2 rounded-lg border border-gray-200 px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50">
                            <span>Descargar PDF</span>
                        </a>
                    </div>
                </div>

                <div v-if="loadError" class="mt-2 text-sm text-red-600">{{ loadError }}</div>
                <div v-if="balancesError" class="mt-2 text-sm text-red-600">{{ balancesError }}</div>

                <div v-if="!accountBalances.length && !balancesLoading" class="mt-3 text-sm text-gray-500">
                    Aun no hay datos de cuentas.
                </div>

                <div v-else class="mt-3">
                    <div class="space-y-3 md:hidden">
                        <div v-for="acc in accountBalances" :key="acc.account || acc.label"
                            class="rounded-xl border border-gray-200 bg-white p-3 shadow-sm">
                            <div class="text-sm font-semibold text-gray-900">{{ acc.label || payToLabel(acc.account) }}</div>
                            <div class="text-xs text-gray-600">{{ acc.account ?? 'Sin asignar' }}</div>
                            <div class="mt-2 grid grid-cols-3 gap-2 text-xs">
                                <div>
                                    <div class="text-gray-500">Entradas</div>
                                    <div class="font-semibold text-emerald-700">{{ fmtMoney(acc.entries) }}</div>
                                </div>
                                <div>
                                    <div class="text-gray-500">Gastos</div>
                                    <div class="font-semibold text-amber-700">{{ fmtMoney(acc.expenses) }}</div>
                                </div>
                                <div>
                                    <div class="text-gray-500">Saldo</div>
                                    <div class="font-semibold" :class="Number(acc.balance) > 0 ? 'text-emerald-700' : 'text-red-700'">
                                        {{ fmtMoney(acc.balance) }}
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="hidden md:block overflow-x-auto">
                        <table class="min-w-full text-sm text-gray-700">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-2 text-left font-semibold">Cuenta</th>
                                <th class="px-4 py-2 text-left font-semibold">Entradas</th>
                                <th class="px-4 py-2 text-left font-semibold">Gastos</th>
                                <th class="px-4 py-2 text-left font-semibold">Saldo</th>
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
                            </tr>
                        </tbody>
                    </table>
                    </div>
                </div>
            </section>

            <section class="mt-6 rounded-2xl border border-gray-200 bg-white p-4 shadow-sm">
                <div class="flex items-center justify-between gap-2">
                    <div>
                        <h2 class="text-base font-semibold text-gray-900">Entradas de ingresos</h2>
                        <p class="text-sm text-gray-600">Pagos registrados con su cuenta (pay_to).</p>
                    </div>
                </div>

                <div v-if="balancesLoading" class="mt-2 text-sm text-gray-500">Cargando…</div>
                <div v-else-if="!accountPayments.length" class="mt-2 text-sm text-gray-500">No se encontraron pagos.</div>
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
                                <div><span class="font-medium text-gray-700">Cuenta:</span> {{ p.account_label || payToLabel(p.account) }}</div>
                                <div><span class="font-medium text-gray-700">Concepto:</span> {{ p.concept }}</div>
                                <div><span class="font-medium text-gray-700">Pagador:</span> {{ p.member?.applicant_name ?? p.staff?.name ?? '—' }}</div>
                            </div>
                            <div class="mt-3">
                                <a v-if="p.receipt_url"
                                    :href="p.receipt_url"
                                    target="_blank" rel="noopener"
                                    class="inline-flex items-center rounded-md border border-gray-200 px-2 py-1 text-xs font-medium text-gray-700 hover:bg-gray-50">
                                    Ver recibo
                                </a>
                                <span v-else class="text-xs text-gray-400 inline-flex items-center gap-1">
                                    <ExclamationTriangleIcon class="h-4 w-4 text-amber-600" />
                                    Sin recibo
                                </span>
                            </div>
                        </div>
                    </div>

                    <div class="hidden md:block overflow-x-auto">
                        <table class="min-w-full text-sm text-gray-700">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-2 text-left font-semibold">Fecha</th>
                                <th class="px-4 py-2 text-left font-semibold">Cuenta</th>
                                <th class="px-4 py-2 text-left font-semibold">Concepto</th>
                                <th class="px-4 py-2 text-left font-semibold">Pagador</th>
                                <th class="px-4 py-2 text-left font-semibold">Monto</th>
                                <th class="px-4 py-2 text-left font-semibold">Tipo</th>
                                <th class="px-4 py-2 text-left font-semibold">Recibo</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="p in accountPayments" :key="p.id" class="border-t">
                                <td class="px-4 py-2">{{ new Date(p.payment_date).toLocaleDateString() }}</td>
                                <td class="px-4 py-2">{{ p.account_label || payToLabel(p.account) }}</td>
                                <td class="px-4 py-2">{{ p.concept }}</td>
                                <td class="px-4 py-2">{{ p.member?.applicant_name ?? p.staff?.name ?? '—' }}</td>
                                <td class="px-4 py-2">{{ fmtMoney(p.amount_paid) }}</td>
                                <td class="px-4 py-2 capitalize">{{ p.payment_type }}</td>
                                <td class="px-4 py-2">
                                    <div class="flex items-center gap-2">
                                        <span v-if="p.receipt_ref" class="text-xs text-gray-600">{{ p.receipt_ref }}</span>
                                        <a v-if="p.receipt_url"
                                            :href="p.receipt_url"
                                            target="_blank" rel="noopener"
                                            class="inline-flex items-center rounded-md border border-gray-200 px-2 py-1 text-xs font-medium text-gray-700 hover:bg-gray-50">
                                            Ver recibo
                                        </a>
                                        <span v-else class="text-xs text-gray-400 inline-flex items-center gap-1">
                                            <ExclamationTriangleIcon class="h-4 w-4 text-amber-600" />
                                            Sin recibo
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
                        <h2 class="text-base font-semibold text-gray-900">Gastos</h2>
                        <p class="text-sm text-gray-600">Egresos registrados contra cuentas.</p>
                    </div>
                </div>

                <div v-if="balancesLoading" class="mt-2 text-sm text-gray-500">Cargando…</div>
                <div v-else-if="!expenses.length" class="mt-2 text-sm text-gray-500">No se encontraron gastos.</div>
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
                                    {{ e.status === 'completed' ? 'Completado' : e.status === 'pending_reimbursement' ? 'Reembolso pendiente' : 'En proceso' }}
                                </span>
                            </div>
                            <div class="mt-2 text-xs text-gray-600">
                                <div><span class="font-medium text-gray-700">Cuenta:</span> {{ e.pay_to_label || payToLabel(e.pay_to) }}</div>
                                <div><span class="font-medium text-gray-700">Reembolsado a:</span> {{ e.reimbursed_to || '—' }}</div>
                                <div><span class="font-medium text-gray-700">Descripcion:</span> {{ e.description || '—' }}</div>
                            </div>
                            <div class="mt-3">
                                <div class="flex flex-wrap items-center gap-2">
                                    <a v-if="e.receipt_url"
                                        :href="e.receipt_url"
                                        target="_blank" rel="noopener"
                                        class="inline-flex items-center rounded-md border border-gray-200 px-2 py-1 text-xs font-medium text-gray-700 hover:bg-gray-50">
                                        Recibo gasto
                                    </a>
                                    <span v-else class="text-xs text-gray-400 inline-flex items-center gap-1">
                                        <ExclamationTriangleIcon class="h-4 w-4 text-amber-600" />
                                        Sin recibo gasto
                                    </span>
                                    <a v-if="e.reimbursement_receipt_url"
                                        :href="e.reimbursement_receipt_url"
                                        target="_blank" rel="noopener"
                                        class="inline-flex items-center rounded-md border border-gray-200 px-2 py-1 text-xs font-medium text-gray-700 hover:bg-gray-50">
                                        Recibo reembolso
                                    </a>
                                    <button
                                        v-if="e.pay_to === 'reimbursement_to' && e.status === 'completed' && !e.reimbursement_receipt_url"
                                        @click="triggerReimbursementReceiptUpload(e.id)"
                                        class="inline-flex items-center gap-1 rounded-md bg-blue-50 px-2 py-1 text-xs font-medium text-blue-700 hover:bg-blue-100">
                                        <span>Adjuntar recibo reembolso</span>
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
                                <th class="px-4 py-2 text-left font-semibold">Fecha</th>
                                <th class="px-4 py-2 text-left font-semibold">Cuenta</th>
                                <th class="px-4 py-2 text-left font-semibold">Monto</th>
                                <th class="px-4 py-2 text-left font-semibold">Estado</th>
                                <th class="px-4 py-2 text-left font-semibold">Recibo</th>
                                <th class="px-4 py-2 text-left font-semibold">Reembolsado a</th>
                                <th class="px-4 py-2 text-left font-semibold">Descripcion</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="e in expenses" :key="e.id" class="border-t">
                                <td class="px-4 py-2">{{ new Date(e.expense_date).toLocaleDateString() }}</td>
                                <td class="px-4 py-2">{{ e.pay_to_label || payToLabel(e.pay_to) }}</td>
                                <td class="px-4 py-2">{{ fmtMoney(e.amount) }}</td>
                                <td class="px-4 py-2">
                                    <span
                                        :class="[
                                            'inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold',
                        e.status === 'completed'
                            ? 'bg-emerald-50 text-emerald-700 ring-1 ring-emerald-100'
                            : 'bg-amber-50 text-amber-700 ring-1 ring-amber-100'
                    ]">
                                        {{ e.status === 'completed' ? 'Completado' : 'En proceso' }}
                                    </span>
                                </td>
                                <td class="px-4 py-2">
                                    <div class="flex items-center gap-2">
                                        <span v-if="e.receipt_ref" class="text-xs text-gray-600">{{ e.receipt_ref }}</span>
                                        <a v-if="e.receipt_url"
                                            :href="e.receipt_url"
                                            target="_blank" rel="noopener"
                                            class="inline-flex items-center rounded-md border border-gray-200 px-2 py-1 text-xs font-medium text-gray-700 hover:bg-gray-50">
                                            Recibo gasto
                                        </a>
                                        <span v-else class="text-xs text-gray-400 inline-flex items-center gap-1">
                                            <ExclamationTriangleIcon class="h-4 w-4 text-amber-600" />
                                            Sin recibo gasto
                                        </span>
                                        <span v-if="e.reimbursement_receipt_ref" class="text-xs text-gray-600">{{ e.reimbursement_receipt_ref }}</span>
                                        <a v-if="e.reimbursement_receipt_url"
                                            :href="e.reimbursement_receipt_url"
                                            target="_blank" rel="noopener"
                                            class="inline-flex items-center rounded-md border border-gray-200 px-2 py-1 text-xs font-medium text-gray-700 hover:bg-gray-50">
                                            Recibo reembolso
                                        </a>
                                        <button
                                            v-if="e.pay_to === 'reimbursement_to' && e.status === 'completed' && !e.reimbursement_receipt_url"
                                            @click="triggerReimbursementReceiptUpload(e.id)"
                                            class="inline-flex items-center gap-1 rounded-md bg-blue-50 px-2 py-1 text-xs font-medium text-blue-700 hover:bg-blue-100">
                                            <span>Adjuntar recibo reembolso</span>
                                        </button>
                                        <input type="file" accept="image/*" class="hidden"
                                            :ref="el => { if (el) reimbursementReceiptInputs[e.id] = el }"
                                            @change="(ev) => handleReimbursementReceiptSelected(e, ev)" />
                                    </div>
                                </td>
                                <td class="px-4 py-2">{{ e.reimbursed_to || '—' }}</td>
                                <td class="px-4 py-2">{{ e.description || '—' }}</td>
                            </tr>
                        </tbody>
                    </table>
                    </div>
                </div>
            </section>
        </div>
    </PathfinderLayout>
</template>
