<script setup>
import { ref, computed, onMounted } from 'vue'
import { useForm } from '@inertiajs/vue3'
import PathfinderLayout from '@/Layouts/PathfinderLayout.vue'
import { ArrowPathIcon, BanknotesIcon } from '@heroicons/vue/24/outline'
import { fetchExpenses, createExpense } from '@/Services/api'

const payToOptions = ref([])
const expenses = ref([])
const accounts = ref([])
const loading = ref(false)
const loadError = ref('')
const saving = ref(false)

const form = useForm({
    pay_to: null,
    amount: '',
    expense_date: new Date().toISOString().slice(0, 10),
    description: '',
    reimbursed_to: '',
})

const payToLabel = (val) => {
    const m = payToOptions.value.find(p => p.value === val)
    return m?.label || (val ?? 'Unassigned')
}

const fmtMoney = (n) => `$${Number(n ?? 0).toFixed(2)}`
const selectedBalance = computed(() => {
    const acc = accounts.value.find(a => a.pay_to === form.pay_to)
    return acc?.balance ?? null
})

const loadData = async () => {
    loading.value = true
    loadError.value = ''
    try {
        const { data } = await fetchExpenses()
        payToOptions.value = data?.pay_to || []
        accounts.value = data?.accounts || []
        expenses.value = Array.isArray(data?.expenses) ? data.expenses : []
        if (!form.pay_to && payToOptions.value.length) form.pay_to = payToOptions.value[0].value
    } catch (e) {
        console.error(e)
        loadError.value = e?.response?.data?.message || 'Failed to load expenses.'
    } finally {
        loading.value = false
    }
}

const submit = async () => {
    saving.value = true
    form.clearErrors()
    try {
        const { data } = await createExpense(form.data())
        expenses.value = [data?.data, ...expenses.value]
        form.amount = ''
        form.description = ''
    } catch (e) {
        if (e?.response?.status === 422) {
            const errs = e.response.data.errors || {}
            Object.entries(errs).forEach(([field, messages]) => {
                form.setError(field, Array.isArray(messages) ? messages[0] : messages)
            })
        }
        console.error(e)
    } finally {
        saving.value = false
    }
}

onMounted(loadData)
</script>

<template>
    <PathfinderLayout>
        <div class="min-h-screen bg-white px-4 pb-24 sm:px-6">
            <header class="pt-5 pb-3 flex items-center gap-3">
                <BanknotesIcon class="h-6 w-6 text-gray-700" />
                <div>
                    <h1 class="text-lg font-semibold text-gray-900">Expenses</h1>
                    <p class="text-sm text-gray-600">Record outgoing money against pay_to accounts.</p>
                </div>
            </header>

            <section class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm">
                <div class="flex items-center justify-between gap-3">
                    <h2 class="text-base font-semibold text-gray-900">New expense</h2>
                    <button @click="loadData" :disabled="loading"
                        class="inline-flex items-center gap-2 rounded-lg border border-gray-200 px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-60">
                        <ArrowPathIcon v-if="loading" class="h-4 w-4 animate-spin" />
                        <span>{{ loading ? 'Reloading…' : 'Reload' }}</span>
                    </button>
                </div>

                <div v-if="loadError" class="mt-2 text-sm text-red-600">{{ loadError }}</div>

                <div class="mt-4 grid gap-4 md:grid-cols-2">
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Account (pay_to)</label>
                        <select v-model="form.pay_to" class="mt-1 w-full rounded border-gray-300 py-2 text-sm focus:border-blue-500 focus:ring-blue-500">
                            <option v-for="p in payToOptions" :key="p.value" :value="p.value">{{ p.label }}</option>
                        </select>
                        <p v-if="selectedBalance !== null" class="text-xs text-gray-500 mt-1">Current balance: {{ fmtMoney(selectedBalance) }}</p>
                        <div v-if="form.errors.pay_to" class="mt-1 text-sm text-red-600">{{ form.errors.pay_to }}</div>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700">Amount</label>
                        <input type="number" step="0.01" min="0" v-model="form.amount"
                            class="mt-1 w-full rounded border-gray-300 py-2 text-sm focus:border-blue-500 focus:ring-blue-500" />
                        <div v-if="form.errors.amount" class="mt-1 text-sm text-red-600">{{ form.errors.amount }}</div>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700">Date</label>
                        <input type="date" v-model="form.expense_date"
                            class="mt-1 w-full rounded border-gray-300 py-2 text-sm focus:border-blue-500 focus:ring-blue-500" />
                        <div v-if="form.errors.expense_date" class="mt-1 text-sm text-red-600">{{ form.errors.expense_date }}</div>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700">Description</label>
                        <textarea rows="2" v-model="form.description"
                            class="mt-1 w-full rounded border-gray-300 py-2 text-sm focus:border-blue-500 focus:ring-blue-500"></textarea>
                        <div v-if="form.errors.description" class="mt-1 text-sm text-red-600">{{ form.errors.description }}</div>
                    </div>

                    <div v-if="form.pay_to === 'reimbursement_to'">
                        <label class="block text-sm font-medium text-gray-700">Reimbursed to</label>
                        <input type="text" v-model="form.reimbursed_to"
                            class="mt-1 w-full rounded border-gray-300 py-2 text-sm focus:border-blue-500 focus:ring-blue-500"
                            placeholder="Name of payee" />
                        <div v-if="form.errors.reimbursed_to" class="mt-1 text-sm text-red-600">{{ form.errors.reimbursed_to }}</div>
                    </div>
                </div>

                <div class="mt-4 flex justify-end">
                    <button @click="submit" :disabled="saving"
                        class="inline-flex items-center gap-2 rounded-lg bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700 disabled:opacity-60">
                        <ArrowPathIcon v-if="saving" class="h-4 w-4 animate-spin" />
                        <span>{{ saving ? 'Saving…' : 'Save expense' }}</span>
                    </button>
                </div>
            </section>

            <section class="mt-6 rounded-2xl border border-gray-200 bg-white p-4 shadow-sm">
                <h2 class="text-base font-semibold text-gray-900">Recent expenses</h2>
                <div v-if="loading" class="mt-2 text-sm text-gray-500">Loading…</div>
                <div v-else-if="!expenses.length" class="mt-2 text-sm text-gray-500">No expenses yet.</div>
                <div v-else class="mt-3 overflow-x-auto">
                    <table class="min-w-full text-sm text-gray-700">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-2 text-left font-semibold">Date</th>
                                <th class="px-4 py-2 text-left font-semibold">Account</th>
                                <th class="px-4 py-2 text-left font-semibold">Amount</th>
                                <th class="px-4 py-2 text-left font-semibold">Reimbursed to</th>
                                <th class="px-4 py-2 text-left font-semibold">Description</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="e in expenses" :key="e.id" class="border-t">
                                <td class="px-4 py-2">{{ new Date(e.expense_date).toLocaleDateString() }}</td>
                                <td class="px-4 py-2">{{ payToLabel(e.pay_to) }}</td>
                                <td class="px-4 py-2">{{ fmtMoney(e.amount) }}</td>
                                <td class="px-4 py-2">{{ e.reimbursed_to || '—' }}</td>
                                <td class="px-4 py-2">{{ e.description || '—' }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </PathfinderLayout>
</template>
