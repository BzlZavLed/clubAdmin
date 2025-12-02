<script setup>
import { ref, onMounted, watch } from 'vue'
import PathfinderLayout from '@/Layouts/PathfinderLayout.vue'
import { ArrowPathIcon } from '@heroicons/vue/24/outline'
import { fetchFinancialReportBootstrap, fetchFinancialAccountBalances } from '@/Services/api'

const payTo = ref([])
const accountBalances = ref([])
const accountPayments = ref([])
const expenses = ref([])
const loading = ref(false)
const loadError = ref('')
const balancesLoading = ref(false)
const balancesError = ref('')
const clubs = ref([])
const selectedClubId = ref(null)

const payToLabel = (val) => {
    const match = (payTo.value || []).find(p => p.value === val)
    return match?.label || (val ?? 'Unassigned')
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
        loadError.value = e?.response?.data?.message || 'Failed to load account data.'
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
        if (!clubs.value.length && data?.clubs) clubs.value = data.clubs
    } catch (e) {
        console.error(e)
        balancesError.value = e?.response?.data?.message || 'Failed to load balances.'
    } finally {
        balancesLoading.value = false
    }
}

const fmtMoney = (n) => `$${Number(n ?? 0).toFixed(2)}`

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
                    <h1 class="text-lg font-semibold text-gray-900">Account Balances</h1>
                    <p class="text-sm text-gray-600">Entries, expenses, and balance by account (pay_to).</p>
                </div>
            </header>

            <section class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm">
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                    <div>
                        <h2 class="text-base font-semibold text-gray-900">Accounts</h2>
                        <p class="text-sm text-gray-600">Auto-loaded from payment concepts.</p>
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
                            <span>{{ loading ? 'Reloading…' : 'Reload accounts' }}</span>
                        </button>
                        <button @click="() => loadBalances(selectedClubId)" :disabled="balancesLoading"
                            class="inline-flex items-center gap-2 rounded-lg border border-blue-200 px-3 py-1.5 text-sm font-medium text-blue-700 hover:bg-blue-50 disabled:opacity-60">
                            <ArrowPathIcon v-if="balancesLoading" class="h-4 w-4 animate-spin" />
                            <span>{{ balancesLoading ? 'Refreshing…' : 'Refresh balances' }}</span>
                        </button>
                        <a :href="route('financial.accounts.pdf', { club_id: selectedClubId })" target="_blank" rel="noopener"
                            class="inline-flex items-center gap-2 rounded-lg border border-gray-200 px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50">
                            <span>Download PDF</span>
                        </a>
                    </div>
                </div>

                <div v-if="loadError" class="mt-2 text-sm text-red-600">{{ loadError }}</div>
                <div v-if="balancesError" class="mt-2 text-sm text-red-600">{{ balancesError }}</div>

                <div v-if="!accountBalances.length && !balancesLoading" class="mt-3 text-sm text-gray-500">
                    No account data yet.
                </div>

                <div v-else class="mt-3 overflow-x-auto">
                    <table class="min-w-full text-sm text-gray-700">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-2 text-left font-semibold">Account</th>
                                <th class="px-4 py-2 text-left font-semibold">Entries</th>
                                <th class="px-4 py-2 text-left font-semibold">Expenses</th>
                                <th class="px-4 py-2 text-left font-semibold">Balance</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="acc in accountBalances" :key="acc.account || acc.label" class="border-t">
                                <td class="px-4 py-2">{{ payToLabel(acc.account) }}</td>
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
            </section>

            <section class="mt-6 rounded-2xl border border-gray-200 bg-white p-4 shadow-sm">
                <div class="flex items-center justify-between gap-2">
                    <div>
                        <h2 class="text-base font-semibold text-gray-900">Income entries</h2>
                        <p class="text-sm text-gray-600">Recorded payments with their account (pay_to).</p>
                    </div>
                </div>

                <div v-if="balancesLoading" class="mt-2 text-sm text-gray-500">Loading…</div>
                <div v-else-if="!accountPayments.length" class="mt-2 text-sm text-gray-500">No payments found.</div>
                <div v-else class="mt-3 overflow-x-auto">
                    <table class="min-w-full text-sm text-gray-700">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-2 text-left font-semibold">Date</th>
                                <th class="px-4 py-2 text-left font-semibold">Account</th>
                                <th class="px-4 py-2 text-left font-semibold">Concept</th>
                                <th class="px-4 py-2 text-left font-semibold">Payer</th>
                                <th class="px-4 py-2 text-left font-semibold">Amount</th>
                                <th class="px-4 py-2 text-left font-semibold">Type</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="p in accountPayments" :key="p.id" class="border-t">
                                <td class="px-4 py-2">{{ new Date(p.payment_date).toLocaleDateString() }}</td>
                                <td class="px-4 py-2">{{ payToLabel(p.account) }}</td>
                                <td class="px-4 py-2">{{ p.concept }}</td>
                                <td class="px-4 py-2">{{ p.member?.applicant_name ?? p.staff?.name ?? '—' }}</td>
                                <td class="px-4 py-2">{{ fmtMoney(p.amount_paid) }}</td>
                                <td class="px-4 py-2 capitalize">{{ p.payment_type }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="mt-6 rounded-2xl border border-gray-200 bg-white p-4 shadow-sm">
                <div class="flex items-center justify-between gap-2">
                    <div>
                        <h2 class="text-base font-semibold text-gray-900">Expenses</h2>
                        <p class="text-sm text-gray-600">Outgoing amounts recorded against accounts.</p>
                    </div>
                </div>

                <div v-if="balancesLoading" class="mt-2 text-sm text-gray-500">Loading…</div>
                <div v-else-if="!expenses.length" class="mt-2 text-sm text-gray-500">No expenses found.</div>
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
                                <td class="px-4 py-2">{{ e.pay_to_label || payToLabel(e.pay_to) }}</td>
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
