<script setup>
import { computed, ref, watch } from 'vue'
import axios from 'axios'
import { useLocale } from '@/Composables/useLocale'

const props = defineProps({
    items: {
        type: Array,
        default: () => []
    },
    eventId: {
        type: Number,
        required: true,
    },
    clubId: {
        type: Number,
        required: true,
    },
    paymentSummary: {
        type: Object,
        default: () => ({ total_received: 0 })
    },
    accounts: {
        type: Array,
        default: () => [],
    },
    paymentRecords: {
        type: Array,
        default: () => [],
    },
    conceptLabel: {
        type: String,
        default: '',
    },
    expectedPaymentsTotal: {
        type: Number,
        default: 0,
    },
})
const emit = defineEmits(['updated'])
const { tr, locale } = useLocale()

const expenseForm = ref({
    category: '',
    description: '',
    qty: 1,
    unit_cost: '',
    funding_source: '',
    expense_date: new Date().toISOString().slice(0, 10),
    notes: '',
    receipt_image: null,
})
const saving = ref(false)
const formError = ref('')
const formSuccess = ref('')
const showPaymentsModal = ref(false)
const uploadingReceiptId = ref(null)
const receiptInputs = ref({})
const editingItemId = ref(null)

const fundingOptions = computed(() => {
    return (props.accounts || []).map((account) => ({
        value: account.value || account.pay_to,
        label: account.label || account.value || account.pay_to,
        balance: Number(account.balance || 0),
    }))
})

watch(fundingOptions, (options) => {
    if (!expenseForm.value.funding_source && options.length) {
        expenseForm.value.funding_source = options[0].value
    }
}, { immediate: true })

const formatCurrency = (value) => {
    const number = Number(value || 0)
    return new Intl.NumberFormat(locale.value === 'en' ? 'en-US' : 'es-US', { style: 'currency', currency: 'USD' }).format(number)
}

const expensesTotal = () => {
    return (props.items || []).reduce((sum, item) => {
        return sum + Number(item?.total || 0)
    }, 0)
}

const grandTotal = computed(() => expensesTotal())

const formatDate = (value) => {
    if (!value) return '—'
    const normalized = String(value).split('T')[0]
    const parsed = new Date(`${normalized}T00:00:00`)
    if (Number.isNaN(parsed.getTime())) return value
    return parsed.toLocaleDateString('en-US', {
        month: '2-digit',
        day: '2-digit',
        year: 'numeric',
    })
}

const outstandingIncome = computed(() => {
    const expected = Number(props.expectedPaymentsTotal || 0)
    const received = Number(props.paymentSummary?.total_received || 0)
    return Math.max(expected - received, 0)
})

const refreshBudget = async () => {
    const { data } = await axios.get(route('event-budget-items.index', { event: props.eventId }))
    const accountData = (await axios.get(route('clubs.accounts.index', { club: props.clubId }))).data?.data ?? null
    emit('updated', {
        items: data?.budget_items || [],
        accounts: Array.isArray(accountData) ? accountData : null,
    })
}

const addExpense = async () => {
    formError.value = ''
    formSuccess.value = ''

    const payload = {
        category: (expenseForm.value.category || '').trim(),
        description: (expenseForm.value.description || '').trim(),
        qty: Number(expenseForm.value.qty || 0),
        unit_cost: Number(expenseForm.value.unit_cost || 0),
        funding_source: (expenseForm.value.funding_source || '').trim() || null,
        expense_date: expenseForm.value.expense_date || null,
        notes: (expenseForm.value.notes || '').trim() || null,
    }

    if (!payload.category || !payload.description) {
        formError.value = tr('La categoría y la descripción son obligatorias.', 'Category and description are required.')
        return
    }
    if (payload.qty <= 0) {
        formError.value = tr('La cantidad debe ser mayor a 0.', 'Quantity must be greater than 0.')
        return
    }
    if (!payload.expense_date) {
        formError.value = tr('La fecha del gasto es obligatoria.', 'Expense date is required.')
        return
    }
    if (payload.unit_cost < 0) {
        formError.value = tr('El costo unitario no puede ser negativo.', 'Unit cost cannot be negative.')
        return
    }
    if (!payload.funding_source) {
        formError.value = tr('La fuente de fondos es obligatoria.', 'Funding source is required.')
        return
    }

    saving.value = true
    try {
        const formPayload = new FormData()
        Object.entries(payload).forEach(([key, value]) => {
            if (value !== null && value !== undefined) formPayload.append(key, value)
        })
        if (expenseForm.value.receipt_image) {
            formPayload.append('receipt_image', expenseForm.value.receipt_image)
        }

        const url = editingItemId.value
            ? route('event-budget-items.update', { eventBudgetItem: editingItemId.value })
            : route('event-budget-items.store', { event: props.eventId })
        if (editingItemId.value) {
            formPayload.append('_method', 'PUT')
        }

        await axios.post(url, formPayload, {
            headers: { 'Content-Type': 'multipart/form-data' },
        })
        expenseForm.value = {
            category: '',
            description: '',
            qty: 1,
            unit_cost: '',
            funding_source: fundingOptions.value[0]?.value || '',
            expense_date: new Date().toISOString().slice(0, 10),
            notes: '',
            receipt_image: null,
        }
        editingItemId.value = null
        formSuccess.value = tr('Gasto guardado en el presupuesto.', 'Expense saved to budget.')
        await refreshBudget()
    } catch (error) {
        formError.value = error?.response?.data?.message || tr('No se pudo agregar el gasto.', 'Unable to add expense.')
    } finally {
        saving.value = false
    }
}

const startEdit = (item) => {
    editingItemId.value = item.id
    expenseForm.value = {
        category: item.category || '',
        description: item.description || '',
        qty: Number(item.qty || 1),
        unit_cost: Number(item.unit_cost || 0),
        funding_source: item.funding_source || (fundingOptions.value[0]?.value || ''),
        expense_date: item.expense_date || new Date().toISOString().slice(0, 10),
        notes: item.notes || '',
        receipt_image: null,
    }
    formError.value = ''
    formSuccess.value = ''
}

const cancelEdit = () => {
    editingItemId.value = null
    expenseForm.value = {
        category: '',
        description: '',
        qty: 1,
        unit_cost: '',
        funding_source: fundingOptions.value[0]?.value || '',
        expense_date: new Date().toISOString().slice(0, 10),
        notes: '',
        receipt_image: null,
    }
}

const removeExpense = async (item) => {
    if (!window.confirm(tr('¿Eliminar este gasto del evento?', 'Delete this event expense?'))) return

    formError.value = ''
    formSuccess.value = ''
    try {
        await axios.delete(route('event-budget-items.destroy', { eventBudgetItem: item.id }))
        if (editingItemId.value === item.id) {
            cancelEdit()
        }
        await refreshBudget()
    } catch (error) {
        formError.value = error?.response?.data?.message || tr('No se pudo eliminar el gasto.', 'Unable to delete expense.')
    }
}

const setReceiptInput = (id, el) => {
    if (el) {
        receiptInputs.value[id] = el
    }
}

const triggerReceiptUpload = (id) => {
    receiptInputs.value[id]?.click()
}

const handleReceiptSelected = async (item, event) => {
    const [file] = event.target.files || []
    event.target.value = ''
    if (!file || !item?.id) return

    uploadingReceiptId.value = item.id
    try {
        const payload = new FormData()
        payload.append('receipt_image', file)
        await axios.post(route('event-budget-items.receipt', { eventBudgetItem: item.id }), payload, {
            headers: { 'Content-Type': 'multipart/form-data' },
        })
        await refreshBudget()
    } catch (error) {
        formError.value = error?.response?.data?.message || tr('No se pudo subir el recibo.', 'Unable to upload receipt.')
    } finally {
        uploadingReceiptId.value = null
    }
}
</script>

<template>
    <div class="space-y-3">
        <div class="rounded-lg border bg-green-50 p-3">
            <div class="flex items-center justify-between gap-2">
                <div class="text-sm font-semibold text-green-800">{{ tr('Ingresos', 'Income') }}</div>
                <button
                    type="button"
                    class="text-xs text-green-700 underline underline-offset-2 hover:text-green-900"
                    @click="showPaymentsModal = true"
                >
                    {{ tr('Pagos recibidos', 'Received payments') }}
                </button>
            </div>
            <div class="mt-1 text-sm text-green-900">
                {{ tr('Pagos esperados (lista de menores):', 'Expected payments (kids list):') }} <span class="font-semibold">{{ formatCurrency(expectedPaymentsTotal || 0) }}</span>
            </div>
            <div class="mt-1 text-sm text-green-900">
                {{ tr('Pagos de participantes recibidos:', 'Participant payments received:') }} <span class="font-semibold">{{ formatCurrency(paymentSummary?.total_received || 0) }}</span>
            </div>
            <div class="mt-1 text-xs text-green-700">
                {{ tr('Monto esperado pendiente:', 'Outstanding expected amount:') }} {{ formatCurrency(outstandingIncome) }}
            </div>
        </div>

        <div class="rounded-lg border bg-white p-3">
            <div class="text-sm font-semibold text-gray-800">{{ tr('Gastos', 'Expenses') }}</div>
            <div class="mt-1 text-xs text-gray-500">{{ tr('Total de gastos:', 'Total expenses:') }} {{ formatCurrency(expensesTotal()) }}</div>
        </div>

        <div class="rounded-lg border bg-white p-3 space-y-2">
            <div class="text-sm font-semibold text-gray-800">
                {{ editingItemId ? tr('Editar gasto', 'Edit Expense') : tr('Agregar gasto', 'Add Expense') }}
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-2">
                <input v-model="expenseForm.category" class="border rounded px-2 py-1 text-sm" :placeholder="tr('Categoría', 'Category')" />
                <input v-model="expenseForm.description" class="border rounded px-2 py-1 text-sm" :placeholder="tr('Descripción', 'Description')" />
                <input v-model.number="expenseForm.qty" type="number" min="0.01" step="0.01" class="border rounded px-2 py-1 text-sm" :placeholder="tr('Cant.', 'Qty')" />
                <input v-model.number="expenseForm.unit_cost" type="number" min="0" step="0.01" class="border rounded px-2 py-1 text-sm" :placeholder="tr('Costo unitario', 'Unit cost')" />
                <input v-model="expenseForm.expense_date" type="date" class="border rounded px-2 py-1 text-sm" />
                <select v-model="expenseForm.funding_source" class="border rounded px-2 py-1 text-sm">
                    <option value="">{{ tr('Fuente de fondos (opcional)', 'Funding source (optional)') }}</option>
                    <option v-for="account in fundingOptions" :key="account.value" :value="account.value">
                        {{ account.label }} · {{ formatCurrency(account.balance) }}
                    </option>
                </select>
                <input v-model="expenseForm.notes" class="border rounded px-2 py-1 text-sm" :placeholder="tr('Notas (opcional)', 'Notes (optional)')" />
                <label class="inline-flex cursor-pointer items-center justify-center rounded border border-gray-300 px-2 py-1 text-sm text-gray-700 hover:bg-gray-50">
                    <input type="file" accept="image/*" class="hidden" @change="expenseForm.receipt_image = $event.target.files?.[0] || null" />
                    {{ expenseForm.receipt_image ? expenseForm.receipt_image.name : tr('Subir recibo', 'Upload receipt') }}
                </label>
            </div>
            <div v-if="formError" class="text-xs text-red-600">{{ formError }}</div>
            <div v-if="formSuccess" class="text-xs text-green-600">{{ formSuccess }}</div>
            <button
                type="button"
                class="px-3 py-1 rounded text-sm bg-blue-600 text-white disabled:opacity-60"
                :disabled="saving"
                @click="addExpense"
            >
                {{ saving ? tr('Guardando...', 'Saving...') : (editingItemId ? tr('Guardar cambios', 'Save changes') : tr('Agregar gasto', 'Add expense')) }}
            </button>
            <button
                v-if="editingItemId"
                type="button"
                class="ml-2 px-3 py-1 rounded text-sm bg-gray-100 text-gray-700"
                @click="cancelEdit"
            >
                {{ tr('Cancelar', 'Cancel') }}
            </button>
        </div>

        <div v-if="showPaymentsModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
            <div class="w-full max-w-4xl rounded-lg border bg-white shadow-xl">
                <div class="flex items-center justify-between border-b px-4 py-3">
                    <div>
                        <h3 class="text-sm font-semibold text-gray-800">{{ tr('Pagos recibidos para el concepto del evento', 'Payments Received for Event Concept') }}</h3>
                        <div class="text-xs text-gray-500">
                            {{ conceptLabel ? `${tr('Concepto', 'Concept')}: ${conceptLabel}` : `${tr('Concepto', 'Concept')}: —` }}
                        </div>
                    </div>
                    <button type="button" class="text-sm text-gray-500 hover:text-gray-700" @click="showPaymentsModal = false">{{ tr('Cerrar', 'Close') }}</button>
                </div>
                <div class="max-h-[70vh] overflow-auto p-4">
                    <table class="min-w-full text-sm">
                        <thead class="bg-gray-50 text-gray-600">
                            <tr>
                                <th class="px-3 py-2 text-left">{{ tr('Fecha', 'Date') }}</th>
                                <th class="px-3 py-2 text-left">{{ tr('Pagador', 'Payer') }}</th>
                                <th class="px-3 py-2 text-left">{{ tr('Tipo', 'Type') }}</th>
                                <th class="px-3 py-2 text-left">{{ tr('Método', 'Method') }}</th>
                                <th class="px-3 py-2 text-right">{{ tr('Monto', 'Amount') }}</th>
                                <th class="px-3 py-2 text-left">{{ tr('Recibido por', 'Received By') }}</th>
                                <th class="px-3 py-2 text-left">{{ tr('Notas', 'Notes') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="payment in paymentRecords" :key="payment.id" class="border-t">
                                <td class="px-3 py-2">{{ formatDate(payment.payment_date) }}</td>
                                <td class="px-3 py-2">{{ payment.payer_name || '—' }}</td>
                                <td class="px-3 py-2 capitalize">{{ payment.payer_type || '—' }}</td>
                                <td class="px-3 py-2 capitalize">{{ payment.payment_type || '—' }}</td>
                                <td class="px-3 py-2 text-right">{{ formatCurrency(payment.amount_paid) }}</td>
                                <td class="px-3 py-2">{{ payment.received_by || '—' }}</td>
                                <td class="px-3 py-2">{{ payment.notes || '—' }}</td>
                            </tr>
                            <tr v-if="!paymentRecords.length">
                                <td colspan="7" class="px-3 py-6 text-center text-gray-500">{{ tr('No se encontraron pagos para este concepto del evento.', 'No payments found for this event concept.') }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="overflow-x-auto bg-white rounded-lg border">
        <table class="min-w-full text-xs">
            <thead class="bg-gray-50 text-gray-600">
                <tr>
                    <th class="text-left px-2 py-2">{{ tr('Categoría', 'Category') }}</th>
                    <th class="text-left px-2 py-2">{{ tr('Descripción', 'Description') }}</th>
                    <th class="text-left px-2 py-2">{{ tr('Fecha', 'Date') }}</th>
                    <th class="text-left px-2 py-2">{{ tr('Cuenta', 'Account') }}</th>
                    <th class="text-right px-2 py-2">{{ tr('Cant.', 'Qty') }}</th>
                    <th class="text-right px-2 py-2">{{ tr('Costo unitario', 'Unit Cost') }}</th>
                    <th class="text-right px-2 py-2">{{ tr('Total', 'Total') }}</th>
                    <th class="text-left px-2 py-2">{{ tr('Recibo', 'Receipt') }}</th>
                    <th class="text-left px-2 py-2">{{ tr('Acciones', 'Actions') }}</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="item in items" :key="item.id" class="border-t">
                    <td class="px-2 py-2 align-top">{{ item.category }}</td>
                    <td class="px-2 py-2 align-top">
                        <div>{{ item.description }}</div>
                        <div v-if="item.reimbursement_expense" class="mt-1 text-[11px] text-amber-700">
                            {{ tr('Reembolso pendiente', 'Pending reimbursement') }}:
                            {{ formatCurrency(item.reimbursement_expense.amount) }}
                            <span v-if="item.reimbursement_expense.reimbursed_to"> · {{ item.reimbursement_expense.reimbursed_to }}</span>
                        </div>
                    </td>
                    <td class="px-2 py-2 align-top">{{ formatDate(item.expense_date) }}</td>
                    <td class="px-2 py-2 align-top">{{ fundingOptions.find((option) => option.value === item.funding_source)?.label || item.funding_source || '—' }}</td>
                    <td class="px-2 py-2 align-top text-right">{{ item.qty }}</td>
                    <td class="px-2 py-2 align-top text-right">{{ formatCurrency(item.unit_cost) }}</td>
                    <td class="px-2 py-2 align-top text-right">{{ formatCurrency(item.total) }}</td>
                    <td class="px-2 py-2 align-top">
                        <div class="flex items-center gap-2">
                            <a v-if="item.receipt_url" :href="item.receipt_url" target="_blank" rel="noopener" class="text-blue-600 hover:underline">
                                {{ tr('Ver', 'View') }}
                            </a>
                            <label class="inline-flex cursor-pointer items-center text-xs text-gray-700 hover:text-blue-700">
                                <input :ref="(el) => setReceiptInput(item.id, el)" type="file" accept="image/*" class="hidden" @change="handleReceiptSelected(item, $event)" />
                                <span @click.prevent="triggerReceiptUpload(item.id)">
                                    {{ uploadingReceiptId === item.id ? tr('Subiendo...', 'Uploading...') : (item.receipt_url ? tr('Reemplazar', 'Replace') : tr('Subir', 'Upload')) }}
                                </span>
                            </label>
                        </div>
                    </td>
                    <td class="px-2 py-2 align-top whitespace-nowrap">
                        <button type="button" class="text-blue-600 hover:underline mr-2" @click="startEdit(item)">
                            {{ tr('Editar', 'Edit') }}
                        </button>
                        <button type="button" class="text-red-600 hover:underline" @click="removeExpense(item)">
                            {{ tr('Eliminar', 'Delete') }}
                        </button>
                    </td>
                </tr>
                <tr v-if="items.length" class="border-t bg-gray-50 font-semibold text-gray-800">
                    <td colspan="6" class="px-2 py-2 text-right">{{ tr('Total', 'Total') }}</td>
                    <td class="px-2 py-2 text-right">{{ formatCurrency(grandTotal) }}</td>
                    <td class="px-2 py-2"></td>
                    <td class="px-2 py-2"></td>
                </tr>
                <tr v-if="!items.length">
                    <td colspan="9" class="px-2 py-6 text-center text-gray-500">{{ tr('Aún no hay partidas de presupuesto.', 'No budget items yet.') }}</td>
                </tr>
            </tbody>
        </table>
        </div>
    </div>
</template>
