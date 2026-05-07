<script setup>
import { computed, ref, watch } from 'vue'
import PathfinderLayout from '@/Layouts/PathfinderLayout.vue'
import { ArrowPathIcon, ExclamationTriangleIcon } from '@heroicons/vue/24/outline'
import { fetchAccountingCorrections, reverseAccountingPayment, reverseAccountingExpense, reverseAccountingReimbursement } from '@/Services/api'
import { useGeneral } from '@/Composables/useGeneral'
import { useAuth } from '@/Composables/useAuth'
import { useLocale } from '@/Composables/useLocale'

const props = defineProps({
    club_id: { type: Number, default: null },
    clubs: { type: Array, default: () => [] },
    payments: { type: Array, default: () => [] },
    reimbursements: { type: Array, default: () => [] },
    expenses: { type: Array, default: () => [] },
})

const { showToast } = useGeneral()
const { user } = useAuth()
const { tr } = useLocale()
const loading = ref(false)
const loadError = ref('')
const payments = ref(Array.isArray(props.payments) ? props.payments : [])
const reimbursements = ref(Array.isArray(props.reimbursements) ? props.reimbursements : [])
const expenses = ref(Array.isArray(props.expenses) ? props.expenses : [])
const clubs = ref(Array.isArray(props.clubs) ? props.clubs : [])
const selectedClubId = ref(props.club_id ?? props.clubs?.[0]?.id ?? null)
const reversingPaymentId = ref(null)
const reversingExpenseId = ref(null)
const reversingReimbursementId = ref(null)
const paymentReasons = ref({})
const reimbursementReasons = ref({})
const expenseReasons = ref({})
const paymentDates = ref({})
const reimbursementDates = ref({})
const expenseDates = ref({})
const today = new Date().toISOString().slice(0, 10)
const canSelectClub = computed(() => user.value?.profile_type === 'superadmin')

const fmtMoney = (amount) => {
    const value = Number(amount ?? 0)
    return new Intl.NumberFormat(undefined, { style: 'currency', currency: 'USD' }).format(value)
}

const fmtDate = (value) => {
    if (!value) return '—'
    return new Date(`${value}T00:00:00`).toLocaleDateString()
}

const movementLabel = (payment) => payment.member_display_name || payment.staff_display_name || payment.received_by_name || tr('Movimiento', 'Movement')
const paymentConceptLabel = (payment) => payment.concept_name || payment.concept_text || tr('Ingreso', 'Income')

const hydrateDefaults = () => {
    payments.value.forEach((payment) => {
        if (!paymentDates.value[payment.id]) paymentDates.value[payment.id] = today
    })
    reimbursements.value.forEach((expense) => {
        if (!reimbursementDates.value[expense.id]) reimbursementDates.value[expense.id] = today
    })
    expenses.value.forEach((expense) => {
        if (!expenseDates.value[expense.id]) expenseDates.value[expense.id] = today
    })
}

const loadData = async (clubId = null) => {
    loading.value = true
    loadError.value = ''
    try {
        const { data } = await fetchAccountingCorrections(clubId || selectedClubId.value)
        payments.value = Array.isArray(data?.payments) ? data.payments : []
        reimbursements.value = Array.isArray(data?.reimbursements) ? data.reimbursements : []
        expenses.value = Array.isArray(data?.expenses) ? data.expenses : []
        clubs.value = Array.isArray(data?.clubs) ? data.clubs : []
        selectedClubId.value = data?.club_id ?? selectedClubId.value
        hydrateDefaults()
    } catch (error) {
        console.error(error)
        loadError.value = error?.response?.data?.message || tr('No se pudieron cargar las correcciones contables.', 'Could not load accounting corrections.')
    } finally {
        loading.value = false
    }
}

const reverseReimbursement = async (expense) => {
    const reason = (reimbursementReasons.value[expense.id] || '').trim()
    const correctionDate = reimbursementDates.value[expense.id] || today

    if (!reason) {
        showToast(tr('Escribe el motivo de la correccion antes de revertir el reembolso.', 'Enter the correction reason before reversing the reimbursement.'), 'error')
        return
    }

    reversingReimbursementId.value = expense.id
    try {
        await reverseAccountingReimbursement(expense.id, {
            reason,
            correction_date: correctionDate,
        })
        showToast(tr('Reembolso revertido con sus movimientos relacionados.', 'Reimbursement reversed with its related movements.'), 'success')
        reimbursementReasons.value[expense.id] = ''
        await loadData(selectedClubId.value)
    } catch (error) {
        console.error(error)
        showToast(error?.response?.data?.message || tr('No se pudo revertir el reembolso.', 'Could not reverse the reimbursement.'), 'error')
    } finally {
        reversingReimbursementId.value = null
    }
}

const reversePayment = async (payment) => {
    const reason = (paymentReasons.value[payment.id] || '').trim()
    const correctionDate = paymentDates.value[payment.id] || today

    if (!reason) {
        showToast(tr('Escribe el motivo de la correccion antes de revertir el ingreso.', 'Enter the correction reason before reversing the income.'), 'error')
        return
    }

    reversingPaymentId.value = payment.id
    try {
        await reverseAccountingPayment(payment.id, {
            reason,
            correction_date: correctionDate,
        })
        showToast(tr('Ingreso revertido con un movimiento opuesto.', 'Income reversed with an opposite movement.'), 'success')
        paymentReasons.value[payment.id] = ''
        await loadData(selectedClubId.value)
    } catch (error) {
        console.error(error)
        showToast(error?.response?.data?.message || tr('No se pudo revertir el ingreso.', 'Could not reverse the income.'), 'error')
    } finally {
        reversingPaymentId.value = null
    }
}

const reverseExpense = async (expense) => {
    const reason = (expenseReasons.value[expense.id] || '').trim()
    const correctionDate = expenseDates.value[expense.id] || today

    if (!reason) {
        showToast(tr('Escribe el motivo de la correccion antes de revertir el gasto.', 'Enter the correction reason before reversing the expense.'), 'error')
        return
    }

    reversingExpenseId.value = expense.id
    try {
        await reverseAccountingExpense(expense.id, {
            reason,
            correction_date: correctionDate,
        })
        showToast(tr('Gasto revertido con un movimiento opuesto.', 'Expense reversed with an opposite movement.'), 'success')
        expenseReasons.value[expense.id] = ''
        await loadData(selectedClubId.value)
    } catch (error) {
        console.error(error)
        showToast(error?.response?.data?.message || tr('No se pudo revertir el gasto.', 'Could not reverse the expense.'), 'error')
    } finally {
        reversingExpenseId.value = null
    }
}

watch(
    () => selectedClubId.value,
    async (clubId, oldClubId) => {
        if (!clubId || clubId === oldClubId) return
        await loadData(clubId)
    }
)

hydrateDefaults()
</script>

<template>
    <PathfinderLayout>
        <template #title>{{ tr('Correcciones contables', 'Accounting Corrections') }}</template>

        <div class="space-y-6">
            <section class="rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
                <div class="flex items-start gap-3">
                    <ExclamationTriangleIcon class="mt-0.5 h-5 w-5 shrink-0" />
                    <div>
                        <p class="font-semibold">{{ tr('Este modulo no elimina movimientos.', 'This module does not delete movements.') }}</p>
                        <p class="mt-1">
                            {{ tr('Cada correccion crea un movimiento opuesto para dejar rastro completo en contabilidad. Los reembolsos pendientes y completados se revierten desde su propia seccion para mantener el paquete contable completo.', 'Each correction creates an opposite movement to keep a complete accounting trail. Pending and completed reimbursements are reversed from their own section to keep the accounting package complete.') }}
                        </p>
                    </div>
                </div>
            </section>

            <section class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm">
                <div class="flex flex-col gap-3 md:flex-row md:items-end md:justify-between">
                    <div>
                        <h2 class="text-lg font-semibold text-gray-900">{{ tr('Movimiento a corregir', 'Movement to Correct') }}</h2>
                        <p class="text-sm text-gray-600">{{ tr('Disponible solo para director del club.', 'Available only to the club director.') }}</p>
                    </div>
                    <div v-if="clubs.length" class="w-full md:w-72">
                        <label class="mb-1 block text-sm font-medium text-gray-700">{{ tr('Club', 'Club') }}</label>
                        <select
                            v-model="selectedClubId"
                            class="w-full rounded-xl border border-gray-300 px-3 py-2 text-sm focus:border-red-400 focus:outline-none focus:ring-2 focus:ring-red-100"
                            :disabled="!canSelectClub && clubs.length <= 1"
                        >
                            <option v-for="club in clubs" :key="club.id" :value="club.id">
                                {{ club.club_name }}
                            </option>
                        </select>
                    </div>
                </div>
            </section>

            <div v-if="loadError" class="rounded-2xl border border-red-200 bg-red-50 p-4 text-sm text-red-700">
                {{ loadError }}
            </div>

            <section class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm">
                <div class="mb-4 flex items-center justify-between">
                    <div>
                        <h2 class="text-lg font-semibold text-gray-900">{{ tr('Ingresos', 'Income') }}</h2>
                        <p class="text-sm text-gray-600">{{ tr('Revertir un ingreso crea otro ingreso por el mismo monto en negativo.', 'Reversing income creates another income movement for the same amount in negative.') }}</p>
                    </div>
                    <div class="text-sm text-gray-500">{{ payments.length }} {{ tr('movimientos', 'movements') }}</div>
                </div>

                <div v-if="loading" class="py-8 text-sm text-gray-500">{{ tr('Cargando ingresos...', 'Loading income...') }}</div>
                <div v-else-if="!payments.length" class="py-8 text-sm text-gray-500">{{ tr('No hay ingresos disponibles para correccion.', 'No income movements are available for correction.') }}</div>
                <div v-else class="space-y-4">
                    <article v-for="payment in payments" :key="payment.id" class="rounded-2xl border border-gray-200 p-4">
                        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                            <div class="space-y-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-semibold text-emerald-700">{{ tr('Ingreso', 'Income') }} #{{ payment.id }}</span>
                                    <span v-if="payment.reversal" class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-700">
                                        {{ tr('Revertido con', 'Reversed with') }} #{{ payment.reversal.id }}
                                    </span>
                                </div>
                                <div class="text-base font-semibold text-gray-900">{{ paymentConceptLabel(payment) }}</div>
                                <div class="text-sm text-gray-600">{{ movementLabel(payment) }}</div>
                                <div class="text-sm text-gray-600">{{ payment.account_label || payment.pay_to || tr('Sin cuenta', 'No account') }} • {{ fmtDate(payment.payment_date) }}</div>
                                <div class="text-lg font-semibold text-emerald-700">{{ fmtMoney(payment.amount_paid) }}</div>
                                <div v-if="payment.notes" class="text-sm text-gray-500">{{ payment.notes }}</div>
                            </div>

                            <div class="w-full max-w-xl space-y-3" :class="{ 'opacity-60': !payment.can_reverse }">
                                <div class="grid gap-3 md:grid-cols-[160px,1fr]">
                                    <div>
                                        <label class="mb-1 block text-sm font-medium text-gray-700">{{ tr('Fecha', 'Date') }}</label>
                                        <input
                                            v-model="paymentDates[payment.id]"
                                            type="date"
                                            class="w-full rounded-xl border border-gray-300 px-3 py-2 text-sm focus:border-red-400 focus:outline-none focus:ring-2 focus:ring-red-100"
                                            :disabled="!payment.can_reverse"
                                        />
                                    </div>
                                    <div>
                                        <label class="mb-1 block text-sm font-medium text-gray-700">{{ tr('Motivo', 'Reason') }}</label>
                                        <input
                                            v-model="paymentReasons[payment.id]"
                                            type="text"
                                            :placeholder="tr('Ej. ingreso duplicado, monto mal registrado', 'Ex. duplicate income, amount recorded incorrectly')"
                                            class="w-full rounded-xl border border-gray-300 px-3 py-2 text-sm focus:border-red-400 focus:outline-none focus:ring-2 focus:ring-red-100"
                                            :disabled="!payment.can_reverse"
                                        />
                                    </div>
                                </div>

                                <div class="flex items-center justify-end">
                                    <button
                                        type="button"
                                        class="inline-flex items-center gap-2 rounded-xl bg-red-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-red-700 disabled:cursor-not-allowed disabled:bg-gray-300"
                                        :disabled="!payment.can_reverse || reversingPaymentId === payment.id"
                                        @click="reversePayment(payment)"
                                    >
                                        <ArrowPathIcon class="h-4 w-4" />
                                        {{ reversingPaymentId === payment.id ? tr('Revirtiendo...', 'Reversing...') : tr('Revertir ingreso', 'Reverse income') }}
                                    </button>
                                </div>
                            </div>
                        </div>
                    </article>
                </div>
            </section>

            <section class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm">
                <div class="mb-4 flex items-center justify-between">
                    <div>
                        <h2 class="text-lg font-semibold text-gray-900">{{ tr('Reembolsos', 'Reimbursements') }}</h2>
                        <p class="text-sm text-gray-600">{{ tr('Un reembolso pendiente revierte la solicitud. Un reembolso completado revierte la solicitud, la liquidacion interna y la salida de fondos.', 'A pending reimbursement reverses the request. A completed reimbursement reverses the request, the internal settlement, and the funds outflow.') }}</p>
                    </div>
                    <div class="text-sm text-gray-500">{{ reimbursements.length }} {{ tr('movimientos', 'movements') }}</div>
                </div>

                <div v-if="loading" class="py-8 text-sm text-gray-500">{{ tr('Cargando reembolsos...', 'Loading reimbursements...') }}</div>
                <div v-else-if="!reimbursements.length" class="py-8 text-sm text-gray-500">{{ tr('No hay reembolsos disponibles para correccion.', 'No reimbursements are available for correction.') }}</div>
                <div v-else class="space-y-4">
                    <article v-for="expense in reimbursements" :key="expense.id" class="rounded-2xl border border-gray-200 p-4">
                        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                            <div class="space-y-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="rounded-full bg-violet-50 px-2.5 py-1 text-xs font-semibold text-violet-700">{{ tr('Reembolso', 'Reimbursement') }} #{{ expense.id }}</span>
                                    <span class="rounded-full px-2.5 py-1 text-xs font-semibold" :class="expense.is_completed ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700'">
                                        {{ expense.is_completed ? tr('Completado', 'Completed') : tr('Pendiente', 'Pending') }}
                                    </span>
                                    <span v-if="expense.reversal" class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-700">
                                        {{ tr('Revertido con', 'Reversed with') }} #{{ expense.reversal.id }}
                                    </span>
                                </div>
                                <div class="text-base font-semibold text-gray-900">{{ expense.description || tr('Reembolso sin descripcion', 'Reimbursement without description') }}</div>
                                <div class="text-sm text-gray-600">{{ expense.reimbursed_to || tr('Sin beneficiario', 'No beneficiary') }} • {{ fmtDate(expense.expense_date) }}</div>
                                <div class="text-lg font-semibold text-violet-700">{{ fmtMoney(expense.amount) }}</div>
                                <div v-if="expense.settlement" class="text-sm text-gray-500">
                                    {{ tr('Liquidacion contra', 'Settlement against') }} {{ expense.settlement.pay_to || tr('cuenta', 'account') }} {{ tr('el', 'on') }} {{ fmtDate(expense.settlement.expense_date) }}
                                </div>
                                <div v-if="expense.settlement_payment" class="text-sm text-gray-500">
                                    {{ tr('Credito interno', 'Internal credit') }} #{{ expense.settlement_payment.id }} {{ tr('el', 'on') }} {{ fmtDate(expense.settlement_payment.payment_date) }}
                                </div>
                            </div>

                            <div class="w-full max-w-xl space-y-3" :class="{ 'opacity-60': !expense.can_reverse }">
                                <div class="grid gap-3 md:grid-cols-[160px,1fr]">
                                    <div>
                                        <label class="mb-1 block text-sm font-medium text-gray-700">{{ tr('Fecha', 'Date') }}</label>
                                        <input
                                            v-model="reimbursementDates[expense.id]"
                                            type="date"
                                            class="w-full rounded-xl border border-gray-300 px-3 py-2 text-sm focus:border-red-400 focus:outline-none focus:ring-2 focus:ring-red-100"
                                            :disabled="!expense.can_reverse"
                                        />
                                    </div>
                                    <div>
                                        <label class="mb-1 block text-sm font-medium text-gray-700">{{ tr('Motivo', 'Reason') }}</label>
                                        <input
                                            v-model="reimbursementReasons[expense.id]"
                                            type="text"
                                            :placeholder="tr('Ej. reembolso duplicado, no debio liquidarse', 'Ex. duplicate reimbursement, should not have been settled')"
                                            class="w-full rounded-xl border border-gray-300 px-3 py-2 text-sm focus:border-red-400 focus:outline-none focus:ring-2 focus:ring-red-100"
                                            :disabled="!expense.can_reverse"
                                        />
                                    </div>
                                </div>

                                <div class="flex items-center justify-end">
                                    <button
                                        type="button"
                                        class="inline-flex items-center gap-2 rounded-xl bg-red-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-red-700 disabled:cursor-not-allowed disabled:bg-gray-300"
                                        :disabled="!expense.can_reverse || reversingReimbursementId === expense.id"
                                        @click="reverseReimbursement(expense)"
                                    >
                                        <ArrowPathIcon class="h-4 w-4" />
                                        {{ reversingReimbursementId === expense.id ? tr('Revirtiendo...', 'Reversing...') : tr('Revertir reembolso', 'Reverse reimbursement') }}
                                    </button>
                                </div>
                            </div>
                        </div>
                    </article>
                </div>
            </section>

            <section class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm">
                <div class="mb-4 flex items-center justify-between">
                    <div>
                        <h2 class="text-lg font-semibold text-gray-900">{{ tr('Gastos', 'Expenses') }}</h2>
                        <p class="text-sm text-gray-600">{{ tr('Revertir un gasto crea otro gasto por el mismo monto en negativo.', 'Reversing an expense creates another expense for the same amount in negative.') }}</p>
                    </div>
                    <div class="text-sm text-gray-500">{{ expenses.length }} {{ tr('movimientos', 'movements') }}</div>
                </div>

                <div v-if="loading" class="py-8 text-sm text-gray-500">{{ tr('Cargando gastos...', 'Loading expenses...') }}</div>
                <div v-else-if="!expenses.length" class="py-8 text-sm text-gray-500">{{ tr('No hay gastos disponibles para correccion.', 'No expenses are available for correction.') }}</div>
                <div v-else class="space-y-4">
                    <article v-for="expense in expenses" :key="expense.id" class="rounded-2xl border border-gray-200 p-4">
                        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                            <div class="space-y-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="rounded-full bg-amber-50 px-2.5 py-1 text-xs font-semibold text-amber-700">{{ tr('Gasto', 'Expense') }} #{{ expense.id }}</span>
                                    <span v-if="expense.reversal" class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-700">
                                        {{ tr('Revertido con', 'Reversed with') }} #{{ expense.reversal.id }}
                                    </span>
                                </div>
                                <div class="text-base font-semibold text-gray-900">{{ expense.description || tr('Gasto sin descripcion', 'Expense without description') }}</div>
                                <div class="text-sm text-gray-600">{{ expense.pay_to || tr('Sin cuenta', 'No account') }} • {{ fmtDate(expense.expense_date) }}</div>
                                <div class="text-lg font-semibold text-amber-700">{{ fmtMoney(expense.amount) }}</div>
                                <div v-if="expense.reimbursed_to" class="text-sm text-gray-500">{{ tr('Reembolso a:', 'Reimbursement to:') }} {{ expense.reimbursed_to }}</div>
                                <div v-if="expense.created_by_name" class="text-sm text-gray-500">{{ tr('Registrado por:', 'Registered by:') }} {{ expense.created_by_name }}</div>
                            </div>

                            <div class="w-full max-w-xl space-y-3" :class="{ 'opacity-60': !expense.can_reverse }">
                                <div class="grid gap-3 md:grid-cols-[160px,1fr]">
                                    <div>
                                        <label class="mb-1 block text-sm font-medium text-gray-700">{{ tr('Fecha', 'Date') }}</label>
                                        <input
                                            v-model="expenseDates[expense.id]"
                                            type="date"
                                            class="w-full rounded-xl border border-gray-300 px-3 py-2 text-sm focus:border-red-400 focus:outline-none focus:ring-2 focus:ring-red-100"
                                            :disabled="!expense.can_reverse"
                                        />
                                    </div>
                                    <div>
                                        <label class="mb-1 block text-sm font-medium text-gray-700">{{ tr('Motivo', 'Reason') }}</label>
                                        <input
                                            v-model="expenseReasons[expense.id]"
                                            type="text"
                                            :placeholder="tr('Ej. gasto duplicado, cuenta incorrecta', 'Ex. duplicate expense, incorrect account')"
                                            class="w-full rounded-xl border border-gray-300 px-3 py-2 text-sm focus:border-red-400 focus:outline-none focus:ring-2 focus:ring-red-100"
                                            :disabled="!expense.can_reverse"
                                        />
                                    </div>
                                </div>

                                <div class="flex items-center justify-end">
                                    <button
                                        type="button"
                                        class="inline-flex items-center gap-2 rounded-xl bg-red-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-red-700 disabled:cursor-not-allowed disabled:bg-gray-300"
                                        :disabled="!expense.can_reverse || reversingExpenseId === expense.id"
                                        @click="reverseExpense(expense)"
                                    >
                                        <ArrowPathIcon class="h-4 w-4" />
                                        {{ reversingExpenseId === expense.id ? tr('Revirtiendo...', 'Reversing...') : tr('Revertir gasto', 'Reverse expense') }}
                                    </button>
                                </div>
                            </div>
                        </div>
                    </article>
                </div>
            </section>
        </div>
    </PathfinderLayout>
</template>
