<script setup>
import { ref, computed, watch } from 'vue'
import { useForm } from '@inertiajs/vue3'
import PathfinderLayout from '@/Layouts/PathfinderLayout.vue'
import { ArrowPathIcon, BanknotesIcon, ExclamationTriangleIcon } from '@heroicons/vue/24/outline'
import { fetchExpenses, createExpense, uploadExpenseReceipt, markExpenseReimbursed } from '@/Services/api'
import { useGeneral } from '@/Composables/useGeneral'

const payToOptions = ref([])
const expenses = ref([])
const accounts = ref([])
const clubs = ref([])
const loading = ref(false)
const loadError = ref('')
const saving = ref(false)
const uploadingId = ref(null)
const rowErrors = ref({})
const reimbursingId = ref(null)
const reimbursePayToByExpense = ref({})
const { showToast } = useGeneral()

const form = useForm({
    club_id: null,
    pay_to: null,
    amount: '',
    expense_date: new Date().toISOString().slice(0, 10),
    description: '',
    receipt_image: null,
})

const receiptInputs = ref({})
const newReceiptInput = ref(null)

const payToLabel = (val) => {
    const m = payToOptions.value.find(p => p.value === val)
    return m?.label || (val ?? 'Sin asignar')
}
const reimbursePayToOptions = computed(() => accounts.value.filter(a => a.pay_to !== 'reimbursement_to'))
const defaultReimbursePayTo = computed(() => {
    const clubBudget = reimbursePayToOptions.value.find(a => a.pay_to === 'club_budget')
    return clubBudget?.pay_to || reimbursePayToOptions.value[0]?.pay_to || null
})

const fmtMoney = (n) => `$${Number(n ?? 0).toFixed(2)}`
const selectedBalance = computed(() => {
    const acc = accounts.value.find(a => a.pay_to === form.pay_to)
    return acc?.balance ?? null
})
const disableSave = computed(() => {
    return saving.value
})
const amountExceedsBalance = computed(() => {
    if (selectedBalance.value === null) return false
    const amt = Number(form.amount || 0)
    return amt > Number(selectedBalance.value || 0)
})
const shortfallAmount = computed(() => {
    if (selectedBalance.value === null) return 0
    const amt = Number(form.amount || 0)
    const bal = Number(selectedBalance.value || 0)
    return Math.max(amt - bal, 0)
})

const receiptHref = (expense) => {
    if (!expense) return null
    if (expense.receipt_url) return expense.receipt_url
    if (expense.receipt_path) return `${window.location.origin}/storage/${expense.receipt_path}`
    return null
}

const loadData = async (clubId = null) => {
    loading.value = true
    loadError.value = ''
    try {
        const safeClubId = (clubId && typeof clubId === 'object') ? form.club_id : clubId
        const { data } = await fetchExpenses(safeClubId || form.club_id)
        payToOptions.value = data?.pay_to || []
        accounts.value = data?.accounts || []
        expenses.value = Array.isArray(data?.expenses) ? data.expenses : []
        clubs.value = Array.isArray(data?.clubs) ? data.clubs : []
        expenses.value.forEach((e) => {
            if (e.status === 'pending_reimbursement' && !reimbursePayToByExpense.value[e.id]) {
                reimbursePayToByExpense.value[e.id] = defaultReimbursePayTo.value
            }
        })
        if (!form.club_id) {
            suppressClubWatch = true
            form.club_id = data?.club_id || (clubs.value[0]?.id ?? null)
        }
        if (!form.pay_to && payToOptions.value.length) form.pay_to = payToOptions.value[0].value
    } catch (e) {
        console.error(e)
        loadError.value = e?.response?.data?.message || 'No se pudieron cargar los gastos.'
    } finally {
        loading.value = false
    }
}

const submit = async () => {
    saving.value = true
    form.clearErrors()
    if (!form.club_id && clubs.value.length) form.club_id = clubs.value[0].id
    if (!form.club_id) {
        form.setError('club_id', 'Selecciona un club')
        saving.value = false
        return
    }
    try {
        const { data } = await createExpense(form.data())
        const split = data?.data?.split_expense ?? null
        if (split) {
            showToast(`Gasto registrado. Se creó un reembolso pendiente por ${fmtMoney(split.amount)}.`, 'info')
        }
        await loadData(form.club_id)
        form.amount = ''
        form.description = ''
        form.receipt_image = null
        if (newReceiptInput.value) newReceiptInput.value.value = ''
    } catch (e) {
        if (e?.response?.status === 422) {
            const errs = e.response.data.errors || {}
            Object.entries(errs).forEach(([field, messages]) => {
                form.setError(field, Array.isArray(messages) ? messages[0] : messages)
            })
        }
        showToast(e?.response?.data?.message || 'No se pudo guardar el gasto.', 'error')
        console.error(e)
    } finally {
        saving.value = false
    }
}

let suppressClubWatch = false
watch(
    () => form.club_id,
    async (id, old) => {
        // Initial run
        if (old === undefined) {
            await loadData(id)
            return
        }

        // Skip when we programmatically set club_id during bootstrap
        if (suppressClubWatch) {
            suppressClubWatch = false
            return
        }

        if (id && id !== old) {
            await loadData(id)
        }
    },
    { immediate: true }
)


const onNewReceiptChange = (event) => {
    const [file] = event.target.files || []
    form.receipt_image = file || null
}

const triggerReceiptUpload = (expenseId) => {
    const input = receiptInputs.value[expenseId]
    if (input) input.click()
}

const handleReceiptSelected = async (expenseId, event) => {
    const [file] = event.target.files || []
    event.target.value = ''
    if (!file) return

    rowErrors.value = { ...rowErrors.value, [expenseId]: '' }
    uploadingId.value = expenseId
    try {
        const { data } = await uploadExpenseReceipt(expenseId, file)
        const idx = expenses.value.findIndex(e => e.id === expenseId)
        if (idx !== -1) expenses.value[idx] = data?.data
    } catch (e) {
        rowErrors.value = {
            ...rowErrors.value,
                [expenseId]: e?.response?.data?.message || 'No se pudo subir el recibo.',
        }
        console.error(e)
    } finally {
        uploadingId.value = null
    }
}


const markReimbursed = async (expense) => {
    if (!expense || expense.status !== 'pending_reimbursement') return
    const payTo = reimbursePayToByExpense.value[expense.id] || defaultReimbursePayTo.value
    if (!payTo) {
        rowErrors.value = { ...rowErrors.value, [expense.id]: 'Selecciona una cuenta para reembolsar.' }
        return
    }
    reimbursingId.value = expense.id
    rowErrors.value = { ...rowErrors.value, [expense.id]: '' }
    try {
        await markExpenseReimbursed(expense.id, payTo)
        showToast('Reembolso registrado.', 'success')
        await loadData(form.club_id)
    } catch (e) {
        rowErrors.value = {
            ...rowErrors.value,
            [expense.id]: e?.response?.data?.message || 'No se pudo marcar el reembolso.',
        }
        console.error(e)
    } finally {
        reimbursingId.value = null
    }
}
</script>

<template>
    <PathfinderLayout>
        <div class="min-h-screen bg-white px-4 pb-24 sm:px-6">
            <header class="pt-5 pb-3 flex items-center gap-3">
                <BanknotesIcon class="h-6 w-6 text-gray-700" />
                <div>
                    <h1 class="text-lg font-semibold text-gray-900">Gastos</h1>
                    <p class="text-sm text-gray-600">Registra egresos contra cuentas pay_to.</p>
                </div>
            </header>

            <section class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm">
                <div class="flex items-center justify-between gap-3">
                    <h2 class="text-base font-semibold text-gray-900">Nuevo gasto</h2>
                    <button @click="loadData" :disabled="loading"
                        class="inline-flex items-center gap-2 rounded-lg border border-gray-200 px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-60">
                        <ArrowPathIcon v-if="loading" class="h-4 w-4 animate-spin" />
                        <span>{{ loading ? 'Recargando…' : 'Recargar' }}</span>
                    </button>
                </div>

                <div v-if="loadError" class="mt-2 text-sm text-red-600">{{ loadError }}</div>

                <div class="mt-4 grid gap-4 md:grid-cols-2">
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Club</label>
                        <select v-model="form.club_id" class="mt-1 w-full rounded border-gray-300 py-2 text-sm focus:border-blue-500 focus:ring-blue-500">
                            <option v-for="c in clubs" :key="c.id" :value="c.id">{{ c.club_name }}</option>
                        </select>
                        <div v-if="form.errors.club_id" class="mt-1 text-sm text-red-600">{{ form.errors.club_id }}</div>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700">Cuenta</label>
                        <select v-model="form.pay_to" class="mt-1 w-full rounded border-gray-300 py-2 text-sm focus:border-blue-500 focus:ring-blue-500">
                            <option v-for="p in payToOptions" :key="p.value" :value="p.value">{{ p.label }}</option>
                        </select>
                        <p v-if="selectedBalance !== null" class="text-xs text-gray-500 mt-1">Saldo actual: {{ fmtMoney(selectedBalance) }}</p>
                        <div v-if="form.errors.pay_to" class="mt-1 text-sm text-red-600">{{ form.errors.pay_to }}</div>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700">Monto</label>
                        <input type="number" step="0.01" min="0" v-model="form.amount"
                            class="mt-1 w-full rounded border-gray-300 py-2 text-sm focus:border-blue-500 focus:ring-blue-500" />
                        <div v-if="form.errors.amount" class="mt-1 text-sm text-red-600">{{ form.errors.amount }}</div>
                        <div v-else-if="amountExceedsBalance" class="mt-1 text-sm text-amber-700 border border-amber-200 bg-amber-50 rounded px-2 py-1">
                            El monto excede el saldo actual. Se registrará un reembolso pendiente por {{ fmtMoney(shortfallAmount) }}.
                        </div>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700">Fecha</label>
                        <input type="date" v-model="form.expense_date"
                            class="mt-1 w-full rounded border-gray-300 py-2 text-sm focus:border-blue-500 focus:ring-blue-500" />
                        <div v-if="form.errors.expense_date" class="mt-1 text-sm text-red-600">{{ form.errors.expense_date }}</div>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700">Descripcion</label>
                        <textarea rows="2" v-model="form.description"
                            class="mt-1 w-full rounded border-gray-300 py-2 text-sm focus:border-blue-500 focus:ring-blue-500"></textarea>
                        <div v-if="form.errors.description" class="mt-1 text-sm text-red-600">{{ form.errors.description }}</div>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700">Imagen del recibo (opcional)</label>
                        <input type="file" accept="image/*" @change="onNewReceiptChange" ref="newReceiptInput"
                            class="mt-1 block w-full text-sm text-gray-700" />
                        <p class="mt-1 text-xs text-gray-500">Adjunta ahora para marcar como completado, o agrega luego desde la tabla.</p>
                        <div v-if="form.errors.receipt_image" class="mt-1 text-sm text-red-600">{{ form.errors.receipt_image }}</div>
                    </div>

                </div>

                <div class="mt-4 flex justify-end">
                    <button @click="submit" :disabled="disableSave"
                        class="inline-flex items-center gap-2 rounded-lg bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700 disabled:opacity-60">
                        <ArrowPathIcon v-if="saving" class="h-4 w-4 animate-spin" />
                        <span>{{ saving ? 'Guardando…' : 'Guardar gasto' }}</span>
                    </button>
                </div>
            </section>

            <section class="mt-6 rounded-2xl border border-gray-200 bg-white p-4 shadow-sm">
                <h2 class="text-base font-semibold text-gray-900">Gastos recientes</h2>
                <div v-if="loading" class="mt-2 text-sm text-gray-500">Cargando…</div>
                <div v-else-if="!expenses.length" class="mt-2 text-sm text-gray-500">No hay gastos aun.</div>
                <div v-else class="mt-3">
                    <div class="space-y-3 md:hidden">
                        <div v-for="e in expenses" :key="e.id" class="rounded-xl border border-gray-200 bg-white p-3 shadow-sm">
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <div class="text-sm font-semibold text-gray-900">{{ fmtMoney(e.amount) }}</div>
                                    <div class="text-xs text-gray-600">{{ new Date(e.expense_date).toLocaleDateString() }}</div>
                                </div>
                                <span
                                    :class="[
                                        'inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-semibold',
                                        e.status === 'completed'
                                            ? 'bg-emerald-50 text-emerald-700 ring-1 ring-emerald-100'
                                            : e.status === 'pending_reimbursement'
                                            ? 'bg-purple-50 text-purple-700 ring-1 ring-purple-100'
                                            : 'bg-amber-50 text-amber-700 ring-1 ring-amber-100'
                                    ]">
                                    {{
                                        e.status === 'completed'
                                            ? 'Completado'
                                            : e.status === 'pending_reimbursement'
                                            ? 'Reembolso pendiente'
                                            : 'En proceso'
                                    }}
                                </span>
                            </div>
                            <div class="mt-2 text-xs text-gray-600">
                                <div><span class="font-medium text-gray-700">Cuenta:</span> {{ payToLabel(e.pay_to) }}</div>
                                <div><span class="font-medium text-gray-700">Descripcion:</span> {{ e.description || '—' }}</div>
                                <div><span class="font-medium text-gray-700">Reembolsado a:</span> {{ e.reimbursed_to || '—' }}</div>
                            </div>
                            <div class="mt-3 flex flex-wrap items-center gap-2">
                                <a v-if="receiptHref(e)" :href="receiptHref(e)" target="_blank" rel="noreferrer"
                                    class="inline-flex items-center rounded-md border border-gray-200 px-2 py-1 text-xs font-medium text-gray-700 hover:bg-gray-50">
                                    Ver recibo
                                </a>
                                <span v-else class="text-xs text-gray-400 inline-flex items-center gap-1">
                                    <ExclamationTriangleIcon class="h-4 w-4 text-amber-600" />
                                    Sin recibo
                                </span>
                                <button
                                    v-if="e.status !== 'completed'"
                                    @click="triggerReceiptUpload(e.id)"
                                    :disabled="uploadingId === e.id"
                                    class="inline-flex items-center gap-1 rounded-md bg-blue-50 px-2 py-1 text-xs font-medium text-blue-700 hover:bg-blue-100 disabled:opacity-60">
                                    <ArrowPathIcon v-if="uploadingId === e.id" class="h-3.5 w-3.5 animate-spin" />
                                    <span>{{ uploadingId === e.id ? 'Subiendo…' : 'Cargar imagen' }}</span>
                                </button>
                                <input type="file" accept="image/*" class="hidden"
                                    :ref="el => { if (el) receiptInputs[e.id] = el }"
                                    @change="(ev) => handleReceiptSelected(e.id, ev)" />
                            </div>
                            <div v-if="rowErrors[e.id]" class="mt-2 text-xs text-red-600">{{ rowErrors[e.id] }}</div>
                            <div v-if="e.status === 'pending_reimbursement'" class="mt-3 flex flex-wrap items-center gap-2">
                                <select
                                    v-model="reimbursePayToByExpense[e.id]"
                                    class="rounded border-gray-300 py-1 text-xs focus:border-blue-500 focus:ring-blue-500">
                                    <option v-for="a in reimbursePayToOptions" :key="a.pay_to" :value="a.pay_to">
                                        {{ a.label }}
                                    </option>
                                </select>
                                <button
                                    @click="markReimbursed(e)"
                                    :disabled="reimbursingId === e.id"
                                    class="inline-flex items-center gap-1 rounded-md bg-emerald-50 px-2 py-1 text-xs font-medium text-emerald-700 hover:bg-emerald-100 disabled:opacity-60">
                                    <ArrowPathIcon v-if="reimbursingId === e.id" class="h-3.5 w-3.5 animate-spin" />
                                    <span>{{ reimbursingId === e.id ? 'Procesando…' : 'Marcar reembolsado' }}</span>
                                </button>
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
                                <td class="px-4 py-2">{{ payToLabel(e.pay_to) }}</td>
                                <td class="px-4 py-2">{{ fmtMoney(e.amount) }}</td>
                                <td class="px-4 py-2">
                                    <span
                                        :class="[
                                            'inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold',
                                            e.status === 'completed'
                                                ? 'bg-emerald-50 text-emerald-700 ring-1 ring-emerald-100'
                                                : e.status === 'pending_reimbursement'
                                                ? 'bg-purple-50 text-purple-700 ring-1 ring-purple-100'
                                                : 'bg-amber-50 text-amber-700 ring-1 ring-amber-100'
                                        ]">
                                        {{
                                            e.status === 'completed'
                                                ? 'Completado'
                                                : e.status === 'pending_reimbursement'
                                                ? 'Reembolso pendiente'
                                                : 'En proceso'
                                        }}
                                    </span>
                                    <div v-if="e.status === 'pending_reimbursement'" class="mt-2 flex flex-wrap items-center gap-2">
                                        <select
                                            v-model="reimbursePayToByExpense[e.id]"
                                            class="rounded border-gray-300 py-1 text-xs focus:border-blue-500 focus:ring-blue-500">
                                            <option v-for="a in reimbursePayToOptions" :key="a.pay_to" :value="a.pay_to">
                                                {{ a.label }}
                                            </option>
                                        </select>
                                        <button
                                            @click="markReimbursed(e)"
                                            :disabled="reimbursingId === e.id"
                                            class="inline-flex items-center gap-1 rounded-md bg-emerald-50 px-2 py-1 text-xs font-medium text-emerald-700 hover:bg-emerald-100 disabled:opacity-60">
                                            <ArrowPathIcon v-if="reimbursingId === e.id" class="h-3.5 w-3.5 animate-spin" />
                                            <span>{{ reimbursingId === e.id ? 'Procesando…' : 'Marcar reembolsado' }}</span>
                                        </button>
                                    </div>
                                </td>
                                <td class="px-4 py-2">
                                    <div class="flex flex-col gap-1">
                                        <div class="flex items-center gap-2">
                                            <a v-if="receiptHref(e)" :href="receiptHref(e)" target="_blank" rel="noreferrer"
                                                class="inline-flex items-center rounded-md border border-gray-200 px-2 py-1 text-xs font-medium text-gray-700 hover:bg-gray-50">
                                                Ver recibo
                                            </a>
                                            <span v-else class="text-xs text-gray-400 inline-flex items-center gap-1">
                                                <ExclamationTriangleIcon class="h-4 w-4 text-amber-600" />
                                                Sin recibo
                                            </span>

                                            <button
                                                v-if="e.status !== 'completed'"
                                                @click="triggerReceiptUpload(e.id)"
                                                :disabled="uploadingId === e.id"
                                                class="inline-flex items-center gap-1 rounded-md bg-blue-50 px-2 py-1 text-xs font-medium text-blue-700 hover:bg-blue-100 disabled:opacity-60">
                                                <ArrowPathIcon v-if="uploadingId === e.id" class="h-3.5 w-3.5 animate-spin" />
                                                <span>{{ uploadingId === e.id ? 'Subiendo…' : 'Cargar imagen' }}</span>
                                            </button>
                                            <input type="file" accept="image/*" class="hidden"
                                                :ref="el => { if (el) receiptInputs[e.id] = el }"
                                                @change="(ev) => handleReceiptSelected(e.id, ev)" />
                                        </div>
                                        <div v-if="rowErrors[e.id]" class="text-xs text-red-600">{{ rowErrors[e.id] }}</div>
                                    </div>
                                </td>
                                <td class="px-4 py-2">
                                    <div>{{ e.reimbursed_to || '—' }}</div>
                                    <div v-if="rowErrors[e.id]" class="text-xs text-red-600 mt-1">{{ rowErrors[e.id] }}</div>
                                </td>
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
