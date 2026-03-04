<script setup>
import { computed, ref } from 'vue'
import axios from 'axios'

const props = defineProps({
    items: {
        type: Array,
        default: () => []
    },
    eventId: {
        type: Number,
        required: true,
    },
    paymentSummary: {
        type: Object,
        default: () => ({ total_received: 0 })
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

const expenseForm = ref({
    category: 'Transportation',
    description: '',
    qty: 1,
    unit_cost: '',
    funding_source: '',
    notes: '',
})
const saving = ref(false)
const formError = ref('')
const formSuccess = ref('')
const showPaymentsModal = ref(false)

const formatCurrency = (value) => {
    const number = Number(value || 0)
    return new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' }).format(number)
}

const expensesTotal = () => {
    return (props.items || []).reduce((sum, item) => {
        return sum + Number(item?.total || 0)
    }, 0)
}

const formatDate = (value) => {
    if (!value) return '—'
    const parsed = new Date(`${value}T00:00:00`)
    if (Number.isNaN(parsed.getTime())) return value
    return parsed.toLocaleDateString()
}

const outstandingIncome = computed(() => {
    const expected = Number(props.expectedPaymentsTotal || 0)
    const received = Number(props.paymentSummary?.total_received || 0)
    return Math.max(expected - received, 0)
})

const refreshBudget = async () => {
    const { data } = await axios.get(route('event-budget-items.index', { event: props.eventId }))
    emit('updated', data?.budget_items || [])
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
        notes: (expenseForm.value.notes || '').trim() || null,
    }

    if (!payload.category || !payload.description) {
        formError.value = 'Category and description are required.'
        return
    }
    if (payload.qty <= 0) {
        formError.value = 'Quantity must be greater than 0.'
        return
    }
    if (payload.unit_cost < 0) {
        formError.value = 'Unit cost cannot be negative.'
        return
    }

    saving.value = true
    try {
        await axios.post(route('event-budget-items.store', { event: props.eventId }), payload)
        expenseForm.value = {
            category: 'Transportation',
            description: '',
            qty: 1,
            unit_cost: '',
            funding_source: '',
            notes: '',
        }
        formSuccess.value = 'Expense added to budget.'
        await refreshBudget()
    } catch (error) {
        formError.value = error?.response?.data?.message || 'Unable to add expense.'
    } finally {
        saving.value = false
    }
}
</script>

<template>
    <div class="space-y-3">
        <div class="rounded-lg border bg-green-50 p-3">
            <div class="flex items-center justify-between gap-2">
                <div class="text-sm font-semibold text-green-800">Income</div>
                <button
                    type="button"
                    class="text-xs text-green-700 underline underline-offset-2 hover:text-green-900"
                    @click="showPaymentsModal = true"
                >
                    Received payments
                </button>
            </div>
            <div class="mt-1 text-sm text-green-900">
                Expected payments (kids list): <span class="font-semibold">{{ formatCurrency(expectedPaymentsTotal || 0) }}</span>
            </div>
            <div class="mt-1 text-sm text-green-900">
                Participant payments received: <span class="font-semibold">{{ formatCurrency(paymentSummary?.total_received || 0) }}</span>
            </div>
            <div class="mt-1 text-xs text-green-700">
                Outstanding expected amount: {{ formatCurrency(outstandingIncome) }}
            </div>
        </div>

        <div class="rounded-lg border bg-white p-3">
            <div class="text-sm font-semibold text-gray-800">Expenses</div>
            <div class="mt-1 text-xs text-gray-500">Total expenses: {{ formatCurrency(expensesTotal()) }}</div>
        </div>

        <div class="rounded-lg border bg-white p-3 space-y-2">
            <div class="text-sm font-semibold text-gray-800">Add Expense</div>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-2">
                <input v-model="expenseForm.category" class="border rounded px-2 py-1 text-sm" placeholder="Category" />
                <input v-model="expenseForm.description" class="border rounded px-2 py-1 text-sm" placeholder="Description" />
                <input v-model.number="expenseForm.qty" type="number" min="0.01" step="0.01" class="border rounded px-2 py-1 text-sm" placeholder="Qty" />
                <input v-model.number="expenseForm.unit_cost" type="number" min="0" step="0.01" class="border rounded px-2 py-1 text-sm" placeholder="Unit cost" />
                <input v-model="expenseForm.funding_source" class="border rounded px-2 py-1 text-sm" placeholder="Funding source (optional)" />
                <input v-model="expenseForm.notes" class="border rounded px-2 py-1 text-sm" placeholder="Notes (optional)" />
            </div>
            <div v-if="formError" class="text-xs text-red-600">{{ formError }}</div>
            <div v-if="formSuccess" class="text-xs text-green-600">{{ formSuccess }}</div>
            <button
                type="button"
                class="px-3 py-1 rounded text-sm bg-blue-600 text-white disabled:opacity-60"
                :disabled="saving"
                @click="addExpense"
            >
                {{ saving ? 'Saving...' : 'Add expense' }}
            </button>
        </div>

        <div v-if="showPaymentsModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
            <div class="w-full max-w-4xl rounded-lg border bg-white shadow-xl">
                <div class="flex items-center justify-between border-b px-4 py-3">
                    <div>
                        <h3 class="text-sm font-semibold text-gray-800">Payments Received for Event Concept</h3>
                        <div class="text-xs text-gray-500">
                            {{ conceptLabel ? `Concept: ${conceptLabel}` : 'Concept: —' }}
                        </div>
                    </div>
                    <button type="button" class="text-sm text-gray-500 hover:text-gray-700" @click="showPaymentsModal = false">Close</button>
                </div>
                <div class="max-h-[70vh] overflow-auto p-4">
                    <table class="min-w-full text-sm">
                        <thead class="bg-gray-50 text-gray-600">
                            <tr>
                                <th class="px-3 py-2 text-left">Date</th>
                                <th class="px-3 py-2 text-left">Payer</th>
                                <th class="px-3 py-2 text-left">Type</th>
                                <th class="px-3 py-2 text-left">Method</th>
                                <th class="px-3 py-2 text-right">Amount</th>
                                <th class="px-3 py-2 text-left">Received By</th>
                                <th class="px-3 py-2 text-left">Notes</th>
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
                                <td colspan="7" class="px-3 py-6 text-center text-gray-500">No payments found for this event concept.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="overflow-x-auto bg-white rounded-lg border">
        <table class="min-w-full text-sm">
            <thead class="bg-gray-50 text-gray-600">
                <tr>
                    <th class="text-left px-4 py-2">Category</th>
                    <th class="text-left px-4 py-2">Description</th>
                    <th class="text-right px-4 py-2">Qty</th>
                    <th class="text-right px-4 py-2">Unit Cost</th>
                    <th class="text-right px-4 py-2">Total</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="item in items" :key="item.id" class="border-t">
                    <td class="px-4 py-2">{{ item.category }}</td>
                    <td class="px-4 py-2">{{ item.description }}</td>
                    <td class="px-4 py-2 text-right">{{ item.qty }}</td>
                    <td class="px-4 py-2 text-right">{{ formatCurrency(item.unit_cost) }}</td>
                    <td class="px-4 py-2 text-right">{{ formatCurrency(item.total) }}</td>
                </tr>
                <tr v-if="!items.length">
                    <td colspan="5" class="px-4 py-6 text-center text-gray-500">No budget items yet.</td>
                </tr>
            </tbody>
        </table>
        </div>
    </div>
</template>
