<script setup>
import axios from 'axios'
import { nextTick, onBeforeUnmount, onMounted, ref } from 'vue'

const props = defineProps({
    receipt: { type: Object, required: true },
    submit_url: { type: String, required: true },
})

const receiptState = ref({ ...props.receipt })
const signatureCanvas = ref(null)
const signerName = ref(props.receipt?.reimbursed_to || '')
const acknowledged = ref(false)
const isDrawing = ref(false)
const hasSignature = ref(false)
const saving = ref(false)
const errorMessage = ref('')
const successMessage = ref('')

const moneyFormatter = new Intl.NumberFormat('en-US', {
    style: 'currency',
    currency: 'USD',
})

const formatMoney = (value) => moneyFormatter.format(Number(value || 0))
const formatDate = (value) => value ? new Date(`${value}T00:00:00`).toLocaleDateString() : 'Pendiente'
const locationLabel = (location) => {
    if (location === 'cash') return 'Efectivo'
    if (location === 'bank') return 'Banco'
    return location || 'No definido'
}

const configureCanvas = () => {
    const canvas = signatureCanvas.value
    if (!canvas || receiptState.value.signed_at) return

    const rect = canvas.getBoundingClientRect()
    const ratio = window.devicePixelRatio || 1
    canvas.width = Math.max(1, Math.floor(rect.width * ratio))
    canvas.height = Math.max(1, Math.floor(rect.height * ratio))

    const context = canvas.getContext('2d')
    context.fillStyle = '#ffffff'
    context.fillRect(0, 0, canvas.width, canvas.height)
    context.strokeStyle = '#111827'
    context.lineWidth = 2.5 * ratio
    context.lineCap = 'round'
    context.lineJoin = 'round'
    hasSignature.value = false
}

const pointFromEvent = (event) => {
    const canvas = signatureCanvas.value
    const rect = canvas.getBoundingClientRect()

    return {
        x: (event.clientX - rect.left) * (canvas.width / rect.width),
        y: (event.clientY - rect.top) * (canvas.height / rect.height),
    }
}

const startSignature = (event) => {
    if (receiptState.value.signed_at) return

    isDrawing.value = true
    hasSignature.value = true
    const context = signatureCanvas.value.getContext('2d')
    const point = pointFromEvent(event)
    context.beginPath()
    context.moveTo(point.x, point.y)
}

const drawSignature = (event) => {
    if (!isDrawing.value || receiptState.value.signed_at) return

    const context = signatureCanvas.value.getContext('2d')
    const point = pointFromEvent(event)
    context.lineTo(point.x, point.y)
    context.stroke()
}

const stopSignature = () => {
    isDrawing.value = false
}

const clearSignature = () => {
    hasSignature.value = false
    configureCanvas()
}

const submitSignature = async () => {
    errorMessage.value = ''
    successMessage.value = ''

    if (!signerName.value.trim()) {
        errorMessage.value = 'Escribe el nombre de quien recibe el reembolso.'
        return
    }

    if (!hasSignature.value) {
        errorMessage.value = 'Agrega la firma antes de confirmar el recibo.'
        return
    }

    if (!acknowledged.value) {
        errorMessage.value = 'Confirma que recibiste el reembolso completo.'
        return
    }

    saving.value = true
    try {
        const { data } = await axios.post(props.submit_url, {
            signer_name: signerName.value.trim(),
            signature_data: signatureCanvas.value.toDataURL('image/png'),
            acknowledged: true,
        }, {
            headers: { Accept: 'application/json' },
        })

        receiptState.value = data.data || receiptState.value
        successMessage.value = data.message || 'Recibo firmado.'
    } catch (error) {
        errorMessage.value = error?.response?.data?.message || 'No se pudo guardar la firma.'
    } finally {
        saving.value = false
    }
}

onMounted(() => {
    nextTick(configureCanvas)
    window.addEventListener('resize', configureCanvas)
})

onBeforeUnmount(() => {
    window.removeEventListener('resize', configureCanvas)
})
</script>

<template>
    <main class="min-h-screen bg-gray-100 px-4 py-6 text-gray-900 sm:px-6">
        <section class="mx-auto max-w-3xl overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
            <header class="border-b border-gray-200 px-5 py-4 sm:px-6">
                <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Recibo de reembolso</p>
                <div class="mt-2 flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <h1 class="text-xl font-semibold text-gray-950">{{ receiptState.club_name || 'Club' }}</h1>
                        <p class="text-sm text-gray-600">Reembolso #{{ receiptState.id }}</p>
                    </div>
                    <p class="text-2xl font-semibold text-gray-950">{{ formatMoney(receiptState.amount) }}</p>
                </div>
            </header>

            <div class="grid gap-5 px-5 py-5 sm:px-6">
                <div class="grid gap-3 text-sm sm:grid-cols-2">
                    <div>
                        <p class="text-xs font-medium uppercase tracking-wide text-gray-500">Recibe</p>
                        <p class="mt-1 font-semibold text-gray-950">{{ receiptState.reimbursed_to || 'Persona reembolsada' }}</p>
                        <p v-if="receiptState.payee?.email" class="text-gray-600">{{ receiptState.payee.email }}</p>
                        <p v-if="receiptState.payee?.phone" class="text-gray-600">{{ receiptState.payee.phone }}</p>
                    </div>
                    <div>
                        <p class="text-xs font-medium uppercase tracking-wide text-gray-500">Liquidacion</p>
                        <p class="mt-1 text-gray-700">{{ formatDate(receiptState.settlement_date) }}</p>
                        <p class="text-gray-700">{{ receiptState.settlement_account_label || receiptState.settlement_account }} · {{ locationLabel(receiptState.settlement_location) }}</p>
                        <p v-if="receiptState.settled_by" class="text-gray-600">Registrado por {{ receiptState.settled_by }}</p>
                    </div>
                </div>

                <div v-if="receiptState.origin_expense" class="rounded-lg border border-gray-200 bg-gray-50 px-4 py-3 text-sm">
                    <p class="font-semibold text-gray-950">Gasto relacionado #{{ receiptState.origin_expense.id }}</p>
                    <p class="mt-1 text-gray-700">{{ receiptState.origin_expense.description || 'Sin descripcion' }}</p>
                    <p class="mt-1 text-gray-600">
                        {{ formatDate(receiptState.origin_expense.expense_date) }} · {{ formatMoney(receiptState.origin_expense.amount) }}
                    </p>
                </div>

                <div v-if="receiptState.signed_at" class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3">
                    <p class="text-sm font-semibold text-emerald-900">Recibo firmado</p>
                    <p class="mt-1 text-sm text-emerald-800">
                        {{ receiptState.signer_name || receiptState.reimbursed_to }} confirmo el reembolso el {{ receiptState.signed_at }}.
                    </p>
                    <a
                        v-if="receiptState.download_url"
                        :href="receiptState.download_url"
                        class="mt-3 inline-flex min-h-10 items-center justify-center rounded-lg bg-emerald-800 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-900"
                    >
                        Descargar recibo PDF
                    </a>
                    <img
                        v-if="receiptState.signature_url"
                        :src="receiptState.signature_url"
                        alt="Firma registrada"
                        class="mt-3 max-h-32 rounded border border-emerald-200 bg-white"
                    />
                </div>

                <form v-else class="grid gap-4" @submit.prevent="submitSignature">
                    <div>
                        <label class="text-sm font-medium text-gray-700">Nombre de quien recibe</label>
                        <input
                            v-model="signerName"
                            type="text"
                            class="mt-1 w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-red-500 focus:ring-red-500"
                            autocomplete="name"
                        />
                    </div>

                    <div>
                        <div class="mb-2 flex items-center justify-between gap-3">
                            <label class="text-sm font-medium text-gray-700">Firma</label>
                            <button
                                type="button"
                                class="text-sm font-semibold text-gray-600 hover:text-gray-950"
                                @click="clearSignature"
                            >
                                Limpiar firma
                            </button>
                        </div>
                        <canvas
                            ref="signatureCanvas"
                            class="h-56 w-full touch-none rounded-lg border border-gray-300 bg-white"
                            @pointerdown.prevent="startSignature"
                            @pointermove.prevent="drawSignature"
                            @pointerup.prevent="stopSignature"
                            @pointercancel.prevent="stopSignature"
                            @pointerleave.prevent="stopSignature"
                        ></canvas>
                    </div>

                    <label class="flex gap-3 rounded-lg border border-gray-200 bg-gray-50 px-4 py-3 text-sm text-gray-700">
                        <input
                            v-model="acknowledged"
                            type="checkbox"
                            class="mt-0.5 rounded border-gray-300 text-red-600 focus:ring-red-500"
                        />
                        <span>Confirmo que recibi el reembolso completo indicado en este recibo.</span>
                    </label>

                    <p v-if="errorMessage" class="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-medium text-rose-700">{{ errorMessage }}</p>
                    <p v-if="successMessage" class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-700">{{ successMessage }}</p>

                    <button
                        type="submit"
                        class="inline-flex min-h-11 items-center justify-center rounded-lg bg-red-700 px-4 py-2 text-sm font-semibold text-white hover:bg-red-800 disabled:opacity-60"
                        :disabled="saving"
                    >
                        {{ saving ? 'Guardando firma...' : 'Confirmar recibo' }}
                    </button>
                </form>
            </div>
        </section>
    </main>
</template>
