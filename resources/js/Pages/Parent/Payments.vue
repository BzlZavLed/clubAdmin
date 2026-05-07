<script setup>
import { computed, ref } from 'vue'
import { useForm, usePage } from '@inertiajs/vue3'
import PathfinderLayout from '@/Layouts/PathfinderLayout.vue'
import InputError from '@/Components/InputError.vue'
import { useLocale } from '@/Composables/useLocale'
import { CreditCardIcon, ArrowUpTrayIcon, ClockIcon, CheckCircleIcon } from '@heroicons/vue/24/outline'

const props = defineProps({
    auth_user: Object,
    expected_payments: { type: Array, default: () => [] },
    transfer_submissions: { type: Array, default: () => [] },
    receipts: { type: Array, default: () => [] },
})

const page = usePage()
const { tr, locale } = useLocale()
const selectedCharge = ref(null)
const previewUrl = ref(null)

const flashSuccess = computed(() => page.props.flash?.success || null)

const transferForm = useForm({
    payment_concept_id: null,
    member_id: null,
    amount: '',
    payment_date: new Date().toISOString().slice(0, 10),
    reference: '',
    notes: '',
    receipt_image: null,
})

const formatMoney = (value) => Number(value || 0).toFixed(2)

const formatDate = (value) => {
    if (!value) return '—'
    const dt = new Date(`${String(value).slice(0, 10)}T00:00:00`)
    if (Number.isNaN(dt.getTime())) return String(value)
    return new Intl.DateTimeFormat(locale.value === 'en' ? 'en-US' : 'es-ES', { year: 'numeric', month: 'short', day: '2-digit' }).format(dt)
}

const formatDateTime = (value) => {
    if (!value) return '—'
    const dt = new Date(value)
    if (Number.isNaN(dt.getTime())) return String(value)
    return new Intl.DateTimeFormat(locale.value === 'en' ? 'en-US' : 'es-ES', {
        year: 'numeric',
        month: 'short',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
    }).format(dt)
}

const statusLabel = (status) => {
    switch (status) {
        case 'paid': return tr('Pagado', 'Paid')
        case 'pending_review': return tr('En revisión', 'In review')
        case 'approved': return tr('Aprobado', 'Approved')
        case 'rejected': return tr('Rechazado', 'Rejected')
        case 'optional': return tr('Opcional', 'Optional')
        default: return tr('Pendiente', 'Pending')
    }
}

const statusClass = (status) => {
    switch (status) {
        case 'paid':
        case 'approved':
            return 'bg-emerald-100 text-emerald-800'
        case 'pending_review':
        case 'pending':
            return 'bg-amber-100 text-amber-800'
        case 'rejected':
            return 'bg-red-100 text-red-800'
        case 'optional':
            return 'bg-blue-100 text-blue-800'
        default:
            return 'bg-blue-100 text-blue-800'
    }
}

const depositAccountLines = (account) => {
    if (!account) return []
    return [
        account.bank_name ? `${tr('Banco', 'Bank')}: ${account.bank_name}` : null,
        account.account_holder ? `${tr('Titular', 'Account holder')}: ${account.account_holder}` : null,
        account.account_type ? `${tr('Tipo', 'Type')}: ${account.account_type}` : null,
        account.account_number ? `${tr('Cuenta', 'Account')}: ${account.account_number}` : null,
        account.routing_number ? `Routing: ${account.routing_number}` : null,
        account.zelle_email ? `Zelle: ${account.zelle_email}` : null,
        account.zelle_phone ? `${tr('Zelle tel', 'Zelle phone')}: ${account.zelle_phone}` : null,
    ].filter(Boolean)
}

const openTransferModal = (charge) => {
    selectedCharge.value = charge
    transferForm.reset()
    transferForm.clearErrors()
    transferForm.payment_concept_id = charge.concept_id
    transferForm.member_id = charge.member_id
    transferForm.amount = charge.reusable
        ? String(Number(charge.expected_amount || 0).toFixed(2))
        : String(Number(charge.remaining_amount || charge.expected_amount || 0).toFixed(2))
    transferForm.payment_date = new Date().toISOString().slice(0, 10)
    transferForm.reference = ''
    transferForm.notes = ''
    transferForm.receipt_image = null
    previewUrl.value = null
}

const closeTransferModal = () => {
    selectedCharge.value = null
    transferForm.reset()
    transferForm.clearErrors()
    previewUrl.value = null
}

const onFileChange = (event) => {
    const file = event.target.files?.[0] || null
    transferForm.receipt_image = file
    previewUrl.value = file ? URL.createObjectURL(file) : null
}

const submitTransfer = () => {
    transferForm.post(route('parent.payments.transfers.store'), {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            closeTransferModal()
        },
    })
}

const pendingCount = computed(() => props.transfer_submissions.filter(item => item.status === 'pending').length)
</script>

<template>
    <PathfinderLayout>
        <template #title>{{ tr('Pagos', 'Payments') }}</template>

        <div class="space-y-6">
            <section class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
                <div class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
                    <div>
                        <div class="flex items-center gap-2">
                            <CreditCardIcon class="h-6 w-6 text-gray-600" />
                            <h1 class="text-xl font-semibold text-gray-900">{{ tr('Pagos del club', 'Club payments') }}</h1>
                        </div>
                        <p class="mt-2 text-sm text-gray-600">
                            {{ tr('Revisa cargos esperados para tus hijos, envía comprobantes de transferencia y descarga recibos aprobados.', 'Review expected charges for your children, submit transfer receipts, and download approved receipts.') }}
                        </p>
                    </div>
                    <div class="grid grid-cols-2 gap-3 text-sm md:min-w-[280px]">
                        <div class="rounded-xl border border-gray-200 bg-gray-50 px-4 py-3">
                            <div class="text-gray-500">{{ tr('Cargos visibles', 'Visible charges') }}</div>
                            <div class="mt-1 text-lg font-semibold text-gray-900">{{ expected_payments.length }}</div>
                        </div>
                        <div class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3">
                            <div class="text-amber-700">{{ tr('En revisión', 'In review') }}</div>
                            <div class="mt-1 text-lg font-semibold text-amber-900">{{ pendingCount }}</div>
                        </div>
                    </div>
                </div>

                <div v-if="flashSuccess" class="mt-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
                    {{ flashSuccess }}
                </div>
            </section>

            <section class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
                <div class="flex items-center justify-between gap-3">
                    <div>
                        <h2 class="text-lg font-semibold text-gray-900">{{ tr('Pagos esperados', 'Expected payments') }}</h2>
                        <p class="mt-1 text-sm text-gray-600">{{ tr('Cargos que aplican según club, clase, menor o participación en evento.', 'Charges that apply by club, class, child, or event participation.') }}</p>
                    </div>
                </div>

                <div v-if="!expected_payments.length" class="mt-4 rounded-xl border border-dashed border-gray-200 bg-gray-50 p-6 text-sm text-gray-500">
                    {{ tr('No hay cargos visibles para tus hijos en este momento.', 'There are no visible charges for your children right now.') }}
                </div>

                <div v-else class="mt-4 space-y-4">
                    <article
                        v-for="charge in expected_payments"
                        :key="charge.row_key"
                        class="rounded-2xl border border-gray-200 p-4"
                    >
                        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                            <div class="space-y-2">
                                <div class="flex flex-wrap items-center gap-2">
                                    <h3 class="text-base font-semibold text-gray-900">{{ charge.concept_name }}</h3>
                                    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium" :class="statusClass(charge.status)">
                                        {{ statusLabel(charge.status) }}
                                    </span>
                                    <span v-if="charge.event_title" class="inline-flex items-center rounded-full bg-blue-100 px-2 py-0.5 text-xs font-medium text-blue-800">
                                        {{ charge.event_title }}
                                    </span>
                                </div>
                                <div class="text-sm text-gray-700">
                                    {{ charge.member_name }} <span class="text-gray-400">•</span> {{ charge.club_name || '—' }}
                                    <template v-if="charge.class_name">
                                        <span class="text-gray-400">•</span> {{ charge.class_name }}
                                    </template>
                                </div>
                                <div class="text-xs text-gray-500">
                                    {{ charge.scope_label }}
                                    <template v-if="charge.due_date">
                                        <span class="mx-1">•</span> {{ tr('Vence', 'Due') }} {{ formatDate(charge.due_date) }}
                                    </template>
                                </div>
                                <div v-if="charge.deposit_account" class="rounded-xl border border-blue-100 bg-blue-50 px-3 py-2 text-sm text-blue-900">
                                    <div class="font-medium">{{ charge.deposit_account.label || charge.deposit_account_label || tr('Cuenta de depósito', 'Deposit account') }}</div>
                                    <div class="mt-1 grid gap-1 md:grid-cols-2">
                                        <div v-for="line in depositAccountLines(charge.deposit_account)" :key="`${charge.row_key}-${line}`">{{ line }}</div>
                                    </div>
                                    <div v-if="charge.deposit_account.deposit_instructions" class="mt-2 text-xs text-blue-800">
                                        {{ charge.deposit_account.deposit_instructions }}
                                    </div>
                                </div>
                                <div v-else-if="charge.can_submit_transfer" class="rounded-xl border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800">
                                    {{ tr('El club todavía no publicó datos de depósito para', 'The club has not published deposit information for') }} {{ charge.deposit_account_label || charge.pay_to || tr('esta cuenta', 'this account') }}.
                                </div>
                            </div>

                            <div class="grid gap-2 text-sm lg:min-w-[320px]">
                                <div class="grid grid-cols-3 gap-2">
                                    <div class="rounded-xl bg-gray-50 px-3 py-2">
                                        <div class="text-xs text-gray-500">{{ tr('Esperado', 'Expected') }}</div>
                                        <div class="font-semibold text-gray-900">${{ formatMoney(charge.expected_amount) }}</div>
                                    </div>
                                    <div class="rounded-xl bg-gray-50 px-3 py-2">
                                        <div class="text-xs text-gray-500">{{ tr('Pagado', 'Paid') }}</div>
                                        <div class="font-semibold text-gray-900">${{ formatMoney(charge.paid_amount) }}</div>
                                    </div>
                                    <div class="rounded-xl bg-gray-50 px-3 py-2">
                                        <div class="text-xs text-gray-500">{{ tr('Pendiente', 'Pending') }}</div>
                                        <div class="font-semibold text-gray-900">
                                            ${{ formatMoney(charge.reusable ? charge.expected_amount : charge.remaining_amount) }}
                                        </div>
                                    </div>
                                </div>
                                <div v-if="charge.pending_amount > 0" class="rounded-xl border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800">
                                    {{ tr('Ya hay', 'There is already') }} ${{ formatMoney(charge.pending_amount) }} {{ tr('enviado para revisión en este cargo.', 'submitted for review on this charge.') }}
                                </div>
                                <div v-if="charge.transfer_blocked_reason" class="rounded-xl border border-blue-200 bg-blue-50 px-3 py-2 text-xs text-blue-800">
                                    {{ charge.transfer_blocked_reason }}
                                </div>
                                <div class="flex flex-wrap justify-end gap-2">
                                    <button
                                        v-if="charge.can_submit_transfer"
                                        type="button"
                                        class="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700"
                                        @click="openTransferModal(charge)"
                                    >
                                        <ArrowUpTrayIcon class="h-4 w-4" />
                                        {{ tr('Enviar comprobante', 'Submit receipt') }}
                                    </button>
                                </div>
                            </div>
                        </div>
                    </article>
                </div>
            </section>

            <section class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
                <div class="flex items-center gap-2">
                    <ClockIcon class="h-5 w-5 text-amber-600" />
                    <div>
                        <h2 class="text-lg font-semibold text-gray-900">{{ tr('Transferencias enviadas', 'Submitted transfers') }}</h2>
                        <p class="mt-1 text-sm text-gray-600">{{ tr('Historial de comprobantes enviados desde el portal de padres.', 'History of receipts submitted from the parent portal.') }}</p>
                    </div>
                </div>

                <div v-if="!transfer_submissions.length" class="mt-4 rounded-xl border border-dashed border-gray-200 bg-gray-50 p-6 text-sm text-gray-500">
                    {{ tr('Aún no has enviado comprobantes de transferencia.', 'You have not submitted transfer receipts yet.') }}
                </div>

                <div v-else class="mt-4 space-y-3">
                    <article v-for="submission in transfer_submissions" :key="submission.id" class="rounded-2xl border border-gray-200 p-4">
                        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                            <div class="space-y-2">
                                <div class="flex flex-wrap items-center gap-2">
                                    <div class="font-semibold text-gray-900">{{ submission.concept_name }}</div>
                                    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium" :class="statusClass(submission.status)">
                                        {{ statusLabel(submission.status) }}
                                    </span>
                                </div>
                                <div class="text-sm text-gray-700">
                                    {{ submission.member_name }} <span class="text-gray-400">•</span> {{ submission.club_name || '—' }}
                                    <template v-if="submission.event_title">
                                        <span class="text-gray-400">•</span> {{ submission.event_title }}
                                    </template>
                                </div>
                                <div class="text-xs text-gray-500">
                                    {{ tr('Enviado', 'Submitted') }} {{ formatDateTime(submission.payment_date) }}
                                    <template v-if="submission.reference">
                                        <span class="mx-1">•</span> Ref. {{ submission.reference }}
                                    </template>
                                </div>
                                <div v-if="submission.notes" class="text-sm text-gray-600">
                                    {{ submission.notes }}
                                </div>
                                <div v-if="submission.review_notes" class="rounded-lg bg-gray-50 px-3 py-2 text-sm text-gray-700">
                                    {{ submission.review_notes }}
                                </div>
                            </div>

                            <div class="space-y-2 text-sm lg:min-w-[280px]">
                                <div class="rounded-xl bg-gray-50 px-3 py-2">
                                    <div class="text-xs text-gray-500">{{ tr('Monto enviado', 'Submitted amount') }}</div>
                                    <div class="font-semibold text-gray-900">${{ formatMoney(submission.amount) }}</div>
                                </div>
                                <div class="flex flex-wrap gap-3">
                                    <a
                                        v-if="submission.receipt_image_url"
                                        :href="submission.receipt_image_url"
                                        target="_blank"
                                        rel="noopener"
                                        class="text-sm font-medium text-blue-600 hover:underline"
                                    >
                                        {{ tr('Ver comprobante', 'View receipt') }}
                                    </a>
                                    <a
                                        v-if="submission.approved_receipt_url"
                                        :href="submission.approved_receipt_url"
                                        target="_blank"
                                        rel="noopener"
                                        class="text-sm font-medium text-emerald-600 hover:underline"
                                    >
                                        {{ tr('Descargar recibo', 'Download receipt') }}
                                    </a>
                                </div>
                            </div>
                        </div>
                    </article>
                </div>
            </section>

            <section class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
                <div class="flex items-center gap-2">
                    <CheckCircleIcon class="h-5 w-5 text-emerald-600" />
                    <div>
                        <h2 class="text-lg font-semibold text-gray-900">{{ tr('Recibos emitidos', 'Issued receipts') }}</h2>
                        <p class="mt-1 text-sm text-gray-600">{{ tr('Recibos generados por pagos aprobados o registrados directamente por el club.', 'Receipts generated from approved payments or payments registered directly by the club.') }}</p>
                    </div>
                </div>

                <div v-if="!receipts.length" class="mt-4 rounded-xl border border-dashed border-gray-200 bg-gray-50 p-6 text-sm text-gray-500">
                    {{ tr('No hay recibos disponibles todavía.', 'There are no receipts available yet.') }}
                </div>

                <div v-else class="mt-4 space-y-3">
                    <article v-for="receipt in receipts" :key="receipt.id" class="rounded-2xl border border-gray-200 p-4">
                        <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                            <div class="space-y-1 text-sm">
                                <div class="font-semibold text-gray-900">{{ receipt.receipt_number }}</div>
                                <div class="text-gray-700">{{ receipt.member_name }} <span class="text-gray-400">•</span> {{ receipt.concept_name || '—' }}</div>
                                <div class="text-xs text-gray-500">
                                    {{ receipt.club_name || '—' }} <span class="mx-1">•</span> {{ formatDate(receipt.payment_date) }} <span class="mx-1">•</span> {{ receipt.payment_type || '—' }}
                                </div>
                            </div>
                            <div class="flex items-center gap-4">
                                <div class="text-right text-sm">
                                    <div class="text-xs text-gray-500">{{ tr('Monto', 'Amount') }}</div>
                                    <div class="font-semibold text-gray-900">${{ formatMoney(receipt.amount_paid) }}</div>
                                </div>
                                <a :href="receipt.download_url" target="_blank" rel="noopener" class="text-sm font-medium text-blue-600 hover:underline">
                                    {{ tr('Descargar', 'Download') }}
                                </a>
                            </div>
                        </div>
                    </article>
                </div>
            </section>
        </div>

        <div v-if="selectedCharge" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 px-4">
            <div class="w-full max-w-xl rounded-2xl bg-white p-6 shadow-xl">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <h3 class="text-lg font-semibold text-gray-900">{{ tr('Enviar comprobante', 'Submit receipt') }}</h3>
                        <p class="mt-1 text-sm text-gray-600">
                            {{ selectedCharge.member_name }} • {{ selectedCharge.concept_name }}
                        </p>
                    </div>
                    <button type="button" class="text-gray-500 hover:text-gray-700" @click="closeTransferModal">✕</button>
                </div>

                <form class="mt-5 space-y-4" @submit.prevent="submitTransfer">
                    <div v-if="selectedCharge.deposit_account" class="rounded-xl border border-blue-100 bg-blue-50 px-3 py-3 text-sm text-blue-900">
                        <div class="font-medium">{{ selectedCharge.deposit_account.label || selectedCharge.deposit_account_label || tr('Cuenta de depósito', 'Deposit account') }}</div>
                        <div class="mt-1 grid gap-1 md:grid-cols-2">
                            <div v-for="line in depositAccountLines(selectedCharge.deposit_account)" :key="`modal-${line}`">{{ line }}</div>
                        </div>
                        <div v-if="selectedCharge.deposit_account.deposit_instructions" class="mt-2 text-xs text-blue-800">
                            {{ selectedCharge.deposit_account.deposit_instructions }}
                        </div>
                    </div>

                    <div class="grid gap-4 md:grid-cols-2">
                        <div>
                            <label class="block text-sm font-medium text-gray-700">{{ tr('Monto', 'Amount') }}</label>
                            <input v-model="transferForm.amount" type="number" min="0.01" step="0.01" class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm" />
                            <InputError class="mt-1" :message="transferForm.errors.amount" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">{{ tr('Fecha de transferencia', 'Transfer date') }}</label>
                            <input v-model="transferForm.payment_date" type="date" class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm" />
                            <InputError class="mt-1" :message="transferForm.errors.payment_date" />
                        </div>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700">{{ tr('Referencia', 'Reference') }}</label>
                        <input v-model="transferForm.reference" type="text" class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm" />
                        <InputError class="mt-1" :message="transferForm.errors.reference" />
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700">{{ tr('Comprobante', 'Receipt') }}</label>
                        <input type="file" accept="image/*" class="mt-1 block w-full text-sm" @change="onFileChange" />
                        <InputError class="mt-1" :message="transferForm.errors.receipt_image" />
                        <a v-if="previewUrl" :href="previewUrl" target="_blank" rel="noopener" class="mt-2 inline-block text-sm text-blue-600 hover:underline">
                            {{ tr('Ver imagen seleccionada', 'View selected image') }}
                        </a>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700">{{ tr('Notas', 'Notes') }}</label>
                        <textarea v-model="transferForm.notes" rows="3" class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm"></textarea>
                        <InputError class="mt-1" :message="transferForm.errors.notes" />
                    </div>

                    <div class="flex justify-end gap-3">
                        <button type="button" class="rounded-lg border border-gray-300 px-4 py-2 text-sm text-gray-700 hover:bg-gray-50" @click="closeTransferModal">
                            {{ tr('Cancelar', 'Cancel') }}
                        </button>
                        <button type="submit" class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700" :disabled="transferForm.processing">
                            {{ transferForm.processing ? tr('Enviando...', 'Submitting...') : tr('Enviar comprobante', 'Submit receipt') }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </PathfinderLayout>
</template>
