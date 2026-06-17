<script setup>
import axios from 'axios'
import { nextTick, onMounted, ref } from 'vue'

const props = defineProps({
    signature_request: { type: Object, required: true },
    submit_url: { type: String, required: true },
})

const requestState = ref({ ...props.signature_request })
const signatureCanvas = ref(null)
const signerName = ref(props.signature_request?.signer_name || '')
const acknowledged = ref(false)
const isDrawing = ref(false)
const hasSignature = ref(false)
const saving = ref(false)
const errorMessage = ref('')
const successMessage = ref('')

const configureCanvas = () => {
    const canvas = signatureCanvas.value
    if (!canvas || requestState.value.signed_at) return

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
    if (requestState.value.signed_at) return
    isDrawing.value = true
    hasSignature.value = true
    const context = signatureCanvas.value.getContext('2d')
    const point = pointFromEvent(event)
    context.beginPath()
    context.moveTo(point.x, point.y)
}

const drawSignature = (event) => {
    if (!isDrawing.value || requestState.value.signed_at) return
    const context = signatureCanvas.value.getContext('2d')
    const point = pointFromEvent(event)
    context.lineTo(point.x, point.y)
    context.stroke()
}

const stopSignature = () => {
    isDrawing.value = false
}

const submitSignature = async () => {
    errorMessage.value = ''
    successMessage.value = ''

    if (!signerName.value.trim()) {
        errorMessage.value = 'Escribe tu nombre.'
        return
    }

    if (!hasSignature.value) {
        errorMessage.value = 'Agrega tu firma antes de enviar.'
        return
    }

    if (!acknowledged.value) {
        errorMessage.value = 'Confirma que leiste y estas de acuerdo.'
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

        requestState.value = data.data || requestState.value
        successMessage.value = data.message || 'Firma guardada.'
    } catch (error) {
        errorMessage.value = error?.response?.data?.message || 'No se pudo guardar la firma.'
    } finally {
        saving.value = false
    }
}

onMounted(() => nextTick(configureCanvas))
</script>

<template>
    <main class="min-h-screen bg-gray-100 px-4 py-6 text-gray-900 sm:px-6">
        <section class="mx-auto max-w-4xl overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
            <header class="border-b border-gray-200 px-5 py-4 sm:px-6">
                <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Pathfinder Club Yearly Application</p>
                <div class="mt-2 flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <h1 class="text-xl font-semibold text-gray-950">{{ requestState.application.club_name || 'Club' }}</h1>
                        <p class="text-sm text-gray-600">{{ requestState.application.application_year }} · {{ requestState.role_label }}</p>
                    </div>
                    <span class="rounded bg-gray-100 px-3 py-1 text-sm font-semibold text-gray-700">
                        {{ requestState.signed_at ? 'Firmado' : 'Pendiente de firma' }}
                    </span>
                </div>
            </header>

            <div class="grid gap-5 px-5 py-5 sm:px-6">
                <div class="grid gap-3 text-sm sm:grid-cols-2">
                    <div>
                        <p class="text-xs font-medium uppercase tracking-wide text-gray-500">Sponsoring Church/Iglesia</p>
                        <p class="mt-1 font-semibold text-gray-950">{{ requestState.application.sponsoring_church || '—' }}</p>
                    </div>
                    <div>
                        <p class="text-xs font-medium uppercase tracking-wide text-gray-500">Pastor</p>
                        <p class="mt-1 font-semibold text-gray-950">{{ requestState.application.pastor || '—' }}</p>
                    </div>
                    <div>
                        <p class="text-xs font-medium uppercase tracking-wide text-gray-500">Elected Club Director</p>
                        <p class="mt-1 font-semibold text-gray-950">{{ requestState.application.elected_club_director || '—' }}</p>
                    </div>
                    <div>
                        <p class="text-xs font-medium uppercase tracking-wide text-gray-500">Director's Phone Number</p>
                        <p class="mt-1 font-semibold text-gray-950">{{ requestState.application.director_phone_number || '—' }}</p>
                    </div>
                    <div class="sm:col-span-2">
                        <p class="text-xs font-medium uppercase tracking-wide text-gray-500">Mailing Address</p>
                        <p class="mt-1 font-semibold text-gray-950">{{ requestState.application.mailing_address || '—' }}</p>
                    </div>
                </div>

                <div class="space-y-4 rounded border border-gray-200 bg-gray-50 p-4 text-sm leading-6 text-gray-800">
                    <div>
                        <h2 class="font-semibold text-gray-900">The Philosophy of Pathfindering</h2>
                        <p class="mt-2">
                            The purpose of having a Pathfinder Club is to lead its membership into a growing, redemptive relationship with Christ, and to build its membership into responsible, mature individuals and to involve its membership in active selfless service. All Pathfinder leaders are Christians, working hand in hand with parents, teachers, and pastors providing optimum opportunities for Christian development, The Pathfinder Club is an extension of the home, school and church, it is an experiential environment where growth and learning flourish. The membership involves youth in grades 5-10 who have a desire for group activities ranging from community and world mission projects to nature, out door work and camping activities
                        </p>
                        <p class="mt-3">
                            AY Pathfindering class curriculum and AY Honors. Above all, Pathfindering gives youth an environment in which to actively expand their personal experience with Christ.
                        </p>
                    </div>
                    <div>
                        <h2 class="font-semibold text-gray-900">Your Commitment to Pathfindering</h2>
                        <p class="mt-2">
                            We, the undersigned, have read, understand, and are in full agreement with the above Philosophy of Pathfindering and agree to support our club through those means with which the Lord has blessed this church, including finances, staff volunteers, securing a place to meet, transportation on outings, and other such needs as my arise in the fulfillment of this ministry, and to assist and support the work of the Pathfinder ministry in this conference and around the world.
                        </p>
                    </div>
                </div>

                <div v-if="requestState.signed_at" class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3">
                    <p class="text-sm font-semibold text-emerald-900">Firma registrada</p>
                    <p class="mt-1 text-sm text-emerald-800">
                        {{ requestState.signer_name }} firmo este documento el {{ requestState.signed_at }}.
                    </p>
                    <img
                        v-if="requestState.signature_url"
                        :src="requestState.signature_url"
                        alt="Firma registrada"
                        class="mt-3 max-h-32 rounded border border-emerald-200 bg-white"
                    />
                </div>

                <form v-else class="grid gap-4" @submit.prevent="submitSignature">
                    <div>
                        <label class="text-sm font-medium text-gray-700">Nombre del firmante</label>
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
                            <button type="button" class="text-sm font-semibold text-gray-600 hover:text-gray-950" @click="configureCanvas">
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
                        <span>Confirmo que he leido y estoy de acuerdo con el compromiso presentado en esta aplicacion.</span>
                    </label>

                    <p v-if="errorMessage" class="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-medium text-rose-700">{{ errorMessage }}</p>
                    <p v-if="successMessage" class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-700">{{ successMessage }}</p>

                    <button
                        type="submit"
                        class="inline-flex min-h-11 items-center justify-center rounded-lg bg-red-700 px-4 py-2 text-sm font-semibold text-white hover:bg-red-800 disabled:opacity-60"
                        :disabled="saving"
                    >
                        {{ saving ? 'Guardando firma...' : 'Firmar aplicacion' }}
                    </button>
                </form>
            </div>
        </section>
    </main>
</template>
