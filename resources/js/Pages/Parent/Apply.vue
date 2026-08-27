<script setup>
import { ref, computed, watch, onMounted, onBeforeUnmount, nextTick } from 'vue'
import { useForm, usePage, router } from '@inertiajs/vue3'
import { formatPhoneNumber, forceLogout } from '@/Helpers/general'
import PathfinderLayout from '@/Layouts/PathfinderLayout.vue'
import { useGeneral } from '@/Composables/useGeneral'
import { useLocale } from '@/Composables/useLocale'

const page = usePage()
const auth_user = ref(page.props.auth_user || {})
const clubs = ref(page.props.clubs || [])
const sameAsHomeAddress = ref(false)
const { locale, tr } = useLocale()

const showSuccess = ref(false)
const currentStep = ref(1)
const selectedClubId = ref(null)
const { showToast } = useGeneral()
const isMobileDevice = ref(false)
const signatureMode = ref('typed')
const signatureCanvas = ref(null)
const hasDrawnSignature = ref(false)
let signatureContext = null
let isDrawingSignature = false

const matchingClubs = computed(() =>
    clubs.value.filter(club => club.church_name === auth_user.value.church_name)
)
const selectedClub = computed(() =>
    matchingClubs.value.find(club => club.id === form.club_id) || null
)
const isPathfinderClub = computed(() => selectedClub.value?.club_type === 'pathfinders')
const isMasterGuideClub = computed(() => selectedClub.value?.club_type === 'master_guide')
const totalSteps = computed(() => isMasterGuideClub.value ? 3 : 4)

const nextStep = () => {
    if (currentStep.value === 1 && !form.club_id) {
        showToast(tr('Selecciona un club para continuar.', 'Select a club to continue.'), 'error')
        return
    }
    if (currentStep.value === 2 && (!form.applicant_name || (!isMasterGuideClub.value && !form.birthdate))) {
        showToast(tr('Completa los datos básicos del menor.', 'Complete the child’s basic information.'), 'error')
        return
    }
    currentStep.value = Math.min(currentStep.value + 1, totalSteps.value)
}

const previousStep = () => {
    currentStep.value = Math.max(1, currentStep.value - 1)
}

const form = useForm({
    club_id: matchingClubs.value.length === 1 ? matchingClubs.value[0].id : '',
    club_name: '',
    director_name: '',
    church_name: '',

    applicant_name: '',
    birthdate: '',
    age: '',
    grade: '',
    mailing_address: '',
    cell_number: '',
    emergency_contact: '',
    emergency_contact_name: '',
    emergency_contact_phone: '',
    emergency_contact_email: '',

    investiture_classes: [],
    allergies: '',
    physical_restrictions: '',
    health_history: '',

    parent_name: '',
    parent_cell: '',
    home_address: '',
    email_address: '',
    signature: '',
    signature_type: 'typed',
    signature_data: '',
    program_year: 1,
})

const detectMobileDevice = () => {
    isMobileDevice.value = window.matchMedia('(pointer: coarse)').matches || window.innerWidth < 768
}

const prepareSignatureCanvas = () => {
    const canvas = signatureCanvas.value
    if (!canvas) return

    const existingSignature = form.signature_data
    const width = Math.max(canvas.getBoundingClientRect().width, 280)
    const height = 180
    const pixelRatio = Math.max(window.devicePixelRatio || 1, 1)
    canvas.width = Math.round(width * pixelRatio)
    canvas.height = Math.round(height * pixelRatio)
    canvas.style.height = `${height}px`

    signatureContext = canvas.getContext('2d')
    signatureContext.setTransform(pixelRatio, 0, 0, pixelRatio, 0, 0)
    signatureContext.lineCap = 'round'
    signatureContext.lineJoin = 'round'
    signatureContext.lineWidth = 2.5
    signatureContext.strokeStyle = '#0f172a'

    if (existingSignature) {
        const image = new Image()
        image.onload = () => signatureContext?.drawImage(image, 0, 0, width, height)
        image.src = existingSignature
    }
}

const signaturePoint = (event) => {
    const rect = signatureCanvas.value.getBoundingClientRect()
    return { x: event.clientX - rect.left, y: event.clientY - rect.top }
}

const startSignature = (event) => {
    if (!signatureContext) prepareSignatureCanvas()
    const point = signaturePoint(event)
    isDrawingSignature = true
    event.currentTarget.setPointerCapture?.(event.pointerId)
    signatureContext.beginPath()
    signatureContext.moveTo(point.x, point.y)
}

const drawSignature = (event) => {
    if (!isDrawingSignature || !signatureContext) return
    const point = signaturePoint(event)
    signatureContext.lineTo(point.x, point.y)
    signatureContext.stroke()
}

const finishSignature = () => {
    if (!isDrawingSignature || !signatureCanvas.value) return
    isDrawingSignature = false
    signatureContext?.closePath()
    form.signature_data = signatureCanvas.value.toDataURL('image/png')
    hasDrawnSignature.value = true
}

const clearSignature = () => {
    const canvas = signatureCanvas.value
    if (canvas) {
        const pixelRatio = Math.max(window.devicePixelRatio || 1, 1)
        signatureContext?.save()
        signatureContext?.setTransform(1, 0, 0, 1, 0, 0)
        signatureContext?.clearRect(0, 0, canvas.width, canvas.height)
        signatureContext?.restore()
        signatureContext?.setTransform(pixelRatio, 0, 0, pixelRatio, 0, 0)
    }
    form.signature_data = ''
    hasDrawnSignature.value = false
}

const selectSignatureMode = async (mode) => {
    signatureMode.value = mode
    form.signature_type = mode
    if (mode === 'drawn') {
        await nextTick()
        prepareSignatureCanvas()
    }
}

const handleSignatureResize = () => {
    detectMobileDevice()
    if (signatureMode.value === 'drawn' && currentStep.value === 4) {
        prepareSignatureCanvas()
    }
}

onMounted(() => {
    detectMobileDevice()
    window.addEventListener('resize', handleSignatureResize)
})

onBeforeUnmount(() => window.removeEventListener('resize', handleSignatureResize))


watch(sameAsHomeAddress, (checked) => {
    if (checked) {
        form.mailing_address = form.home_address
    }
})

watch(() => form.home_address, (newVal) => {
    if (sameAsHomeAddress.value) {
        form.mailing_address = newVal
    }
})

watch(auth_user, (user) => {
    if (user) {
        form.parent_name = user.name || ''
        form.email_address = user.email || ''
        form.church_name = user.church_name || ''
        form.signature = user.name || ''
    }
}, { immediate: true })

watch(() => form.club_id, (id) => {
    const selected = matchingClubs.value.find(club => club.id === id)
    if (selected) {
        form.club_name = selected.club_name
        form.director_name = selected.director_name
        form.church_name = selected.church_name
    } else {
        form.club_name = ''
        form.director_name = ''
        form.church_name = ''
    }
}, { immediate: true })

watch(() => form.birthdate, (newDate) => {
    if (newDate) {
        const today = new Date()
        const birth = new Date(newDate)
        let age = today.getFullYear() - birth.getFullYear()
        const m = today.getMonth() - birth.getMonth()

        if (m < 0 || (m === 0 && today.getDate() < birth.getDate())) {
            age--
        }

        form.age = age
    } else {
        form.age = ''
    }
})

const submit = () => {
    if (!isMasterGuideClub.value) {
        if (form.signature_type === 'drawn' && !form.signature_data) {
            showToast(tr('Dibuja tu firma antes de enviar.', 'Draw your signature before submitting.'), 'error')
            return
        }
        if (form.signature_type === 'typed' && !form.signature.trim()) {
            showToast(tr('Escribe tu nombre como firma antes de enviar.', 'Type your name as your signature before submitting.'), 'error')
            return
        }
    }

    const payload = isMasterGuideClub.value
        ? {
            club_id: form.club_id,
            applicant_name: form.applicant_name,
            phone: form.cell_number,
            address: form.home_address || form.mailing_address,
            email: form.email_address,
            emergency_contact_name: form.emergency_contact_name,
            emergency_contact_phone: form.emergency_contact_phone,
            emergency_contact_email: form.emergency_contact_email,
            program_year: form.program_year,
            is_sda: true,
        }
        : isPathfinderClub.value
            ? {
                ...form.data(),
                father_guardian_name: form.parent_name,
                father_guardian_email: form.email_address,
                father_guardian_phone: form.parent_cell,
                parent_guardian_signature: form.signature,
                signed_at: new Date().toISOString().slice(0, 10),
            }
            : { ...form.data() }

    form.transform(() => payload).post('/parent/apply', {
        preserveScroll: true,
        onSuccess: () => {
            form.transform(data => data)
            form.reset()
            form.signature = auth_user.value.name || ''
            signatureMode.value = 'typed'
            hasDrawnSignature.value = false
            currentStep.value = 1
            showSuccess.value = true
            showToast('Member registered successfully!', 'success')
        },
        onError: (e) => {
            form.transform(data => data)
            const firstError = Object.values(form.errors)[0]
            if (firstError) {
                showToast(firstError, 'error')
            }
        },
        onFinish: () => form.transform(data => data),
    })
}

function onCellNumberInput(event) {
    form.cell_number = formatPhoneNumber(event.target.value)
}

function onParentCellNumberInput(event) {
    form.parent_cell = formatPhoneNumber(event.target.value)
}

const labels = {
    en: {
        title: 'Member Registration',
        clubName: 'Club Name',
        churchName: 'Church Name',
        directorName: 'Director Name',
        applicantName: 'Applicant Name',
        dob: 'Birthdate',
        age: 'Age',
        grade: 'Grade',
        phone: 'Phone',
        email: 'Parent Email',
        parentName: 'Parent Name',
        parentPhone: 'Parent Phone',
        homeAddress: 'Home Address',
        mailingAddress: 'Mailing Address',
        sameAsHome: 'Same as home address',
        emergency: 'Emergency Contact',
        investiture: 'Investiture Class',
        allergies: 'Allergies',
        restrictions: 'Physical Restrictions',
        health: 'Health History',
        signature: 'Signature (Typed)',
        signatureChoice: 'How would you like to sign?',
        typeSignature: 'Type my name',
        drawSignature: 'Draw signature',
        drawHint: 'Use your finger inside the box below.',
        clearSignature: 'Clear and try again',
        submit: 'Submit Registration',
        pathfinderName: 'Name',
        programYear: 'Program Year',
        year1: 'Year 1',
        year2: 'Year 2',
    },
    es: {
        title: 'Registro de Miembro',
        clubName: 'Nombre del club',
        churchName: 'Iglesia',
        directorName: 'Director',
        applicantName: 'Nombre del solicitante',
        dob: 'Fecha de nacimiento',
        age: 'Edad',
        grade: 'Grado',
        phone: 'Teléfono',
        email: 'Correo del padre/madre',
        parentName: 'Nombre del padre/madre',
        parentPhone: 'Teléfono del padre/madre',
        homeAddress: 'Dirección residencial',
        mailingAddress: 'Dirección postal',
        sameAsHome: 'Igual a la dirección residencial',
        emergency: 'Contacto de emergencia',
        investiture: 'Clase de investidura',
        allergies: 'Alergias',
        restrictions: 'Restricciones físicas',
        health: 'Historial médico',
        signature: 'Firma (escrita)',
        signatureChoice: '¿Cómo deseas firmar?',
        typeSignature: 'Escribir mi nombre',
        drawSignature: 'Dibujar firma',
        drawHint: 'Usa tu dedo dentro del recuadro.',
        clearSignature: 'Borrar e intentar de nuevo',
        submit: 'Enviar registro',
        pathfinderName: 'Nombre',
        programYear: 'Año del programa',
        year1: 'Año 1',
        year2: 'Año 2',
    }
}
const t = (key) => labels[locale.value]?.[key] || key
</script>
<template>
    <PathfinderLayout>
        <template #title>{{ t('title') }}</template>

        <div class="enrollment-page p-4 sm:p-6">
            <div v-if="showSuccess" class="mb-4 text-green-700 bg-green-100 p-3 rounded">
                Member registered successfully!
            </div>
            <div v-if="Object.keys(form.errors).length" class="mb-4 text-red-700 bg-red-100 p-3 rounded text-sm">
                Please fix the highlighted fields.
            </div>

            <form novalidate @submit.prevent="submit" class="enrollment-wizard space-y-8 bg-white">
                <div class="wizard-heading">
                    <div>
                        <p class="wizard-step-number">{{ currentStep }} <span>→</span></p>
                        <p class="wizard-step-title">{{ currentStep === 1 ? tr('Selecciona el club', 'Select the club') : (currentStep === 2 ? tr('Datos del menor', 'Child details') : (currentStep === totalSteps ? tr('Revisa y envía la solicitud', 'Review and send your request') : tr('Información de contacto', 'Contact information'))) }}</p>
                    </div>
                </div>
                <!-- Club Selection -->
                <div v-if="currentStep === 1">
                    <label>{{ t('clubName') }}</label>
                    <select v-model="form.club_id" class="w-full p-2 border rounded">
                        <option disabled value="">-- Choose a club --</option>
                        <option v-for="club in matchingClubs" :key="club.id" :value="club.id">
                            {{ club.club_name }}
                        </option>
                    </select>
                    <p v-if="form.errors.club_id" class="text-red-600 text-sm mt-1">{{ form.errors.club_id }}</p>
                </div>
                <div v-if="currentStep === 1">
                    <label>{{ t('churchName') }}</label>
                    <input v-model="form.church_name" type="text" class="w-full p-2 border rounded" readonly />
                </div>
                <div v-if="currentStep === 1">
                    <label>{{ t('directorName') }}</label>
                    <input v-model="form.director_name" type="text" class="w-full p-2 border rounded" readonly />
                </div>
                <div v-if="isMasterGuideClub" class="space-y-4">
                    <div v-if="currentStep === 2">
                        <label>{{ t('pathfinderName') }}</label>
                        <input v-model="form.applicant_name" type="text" class="w-full p-2 border rounded" required />
                        <p v-if="form.errors.applicant_name" class="text-red-600 text-sm mt-1">{{ form.errors.applicant_name }}</p>
                    </div>
                    <div v-if="currentStep === 2" class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label>{{ t('programYear') }}</label>
                            <select v-model.number="form.program_year" class="w-full p-2 border rounded" required>
                                <option :value="1">{{ t('year1') }}</option>
                                <option :value="2">{{ t('year2') }}</option>
                            </select>
                        </div>
                        <div>
                            <label>{{ t('phone') }}</label>
                            <input :value="form.cell_number" @input="onCellNumberInput" type="text"
                                class="w-full p-2 border rounded" placeholder="(123) 456 7890" />
                            <p v-if="form.errors.phone" class="text-red-600 text-sm mt-1">{{ form.errors.phone }}</p>
                        </div>
                    </div>
                    <div v-if="currentStep === 3">
                        <label>Email</label>
                        <input v-model="form.email_address" type="email" class="w-full p-2 border rounded" />
                        <p v-if="form.errors.email" class="text-red-600 text-sm mt-1">{{ form.errors.email }}</p>
                    </div>
                    <div v-if="currentStep === 3">
                        <label>{{ t('homeAddress') }}</label>
                        <input v-model="form.home_address" type="text" class="w-full p-2 border rounded" />
                        <p v-if="form.errors.address" class="text-red-600 text-sm mt-1">{{ form.errors.address }}</p>
                    </div>
                    <div v-if="currentStep === 3" class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label>{{ locale === 'es' ? 'Nombre del contacto de emergencia' : 'Emergency contact name' }}</label>
                            <input v-model="form.emergency_contact_name" type="text" class="w-full p-2 border rounded" />
                            <p v-if="form.errors.emergency_contact_name" class="text-red-600 text-sm mt-1">{{ form.errors.emergency_contact_name }}</p>
                        </div>
                        <div>
                            <label>{{ locale === 'es' ? 'Telefono de emergencia' : 'Emergency phone' }}</label>
                            <input v-model="form.emergency_contact_phone" type="text" class="w-full p-2 border rounded" />
                            <p v-if="form.errors.emergency_contact_phone" class="text-red-600 text-sm mt-1">{{ form.errors.emergency_contact_phone }}</p>
                        </div>
                        <div class="sm:col-span-2">
                            <label>{{ locale === 'es' ? 'Correo del contacto de emergencia' : 'Emergency contact email' }}</label>
                            <input v-model="form.emergency_contact_email" type="email" class="w-full p-2 border rounded" />
                            <p v-if="form.errors.emergency_contact_email" class="text-red-600 text-sm mt-1">{{ form.errors.emergency_contact_email }}</p>
                        </div>
                    </div>
                </div>

                <div v-else-if="isPathfinderClub" class="space-y-4">
                    <div v-if="currentStep === 2">
                        <label>{{ t('pathfinderName') }}</label>
                        <input v-model="form.applicant_name" type="text" class="w-full p-2 border rounded" required />
                        <p v-if="form.errors.applicant_name" class="text-red-600 text-sm mt-1">{{ form.errors.applicant_name }}</p>
                    </div>
                    <div v-if="currentStep === 2" class="flex flex-col sm:flex-row gap-4">
                        <div class="flex-1">
                            <label>{{ t('dob') }}</label>
                            <input v-model="form.birthdate" type="date" class="w-full p-2 border rounded" required />
                            <p v-if="form.errors.birthdate" class="text-red-600 text-sm mt-1">{{ form.errors.birthdate }}</p>
                        </div>
                        <div class="flex-1">
                            <label>{{ t('phone') }}</label>
                            <input :value="form.cell_number" @input="onCellNumberInput" type="text"
                                class="w-full p-2 border rounded" placeholder="(123) 456 7890" />
                            <p v-if="form.errors.cell_number" class="text-red-600 text-sm mt-1">{{ form.errors.cell_number }}</p>
                        </div>
                    </div>
                    <div v-if="currentStep === 3">
                        <label>{{ t('email') }}</label>
                        <input v-model="form.email_address" type="email" class="w-full p-2 border rounded" required />
                        <p v-if="form.errors.email_address" class="text-red-600 text-sm mt-1">{{ form.errors.email_address }}</p>
                    </div>
                    <div v-if="currentStep === 3">
                        <label>{{ t('parentName') }}</label>
                        <input v-model="form.parent_name" type="text" class="w-full p-2 border rounded" required />
                        <p v-if="form.errors.parent_name" class="text-red-600 text-sm mt-1">{{ form.errors.parent_name }}</p>
                    </div>
                    <div v-if="currentStep === 3">
                        <label>{{ t('parentPhone') }}</label>
                        <input :value="form.parent_cell" @input="onParentCellNumberInput" type="text"
                            class="w-full p-2 border rounded" placeholder="(123) 456 7890" />
                        <p v-if="form.errors.parent_cell" class="text-red-600 text-sm mt-1">{{ form.errors.parent_cell }}</p>
                    </div>
                </div>

                <div v-else class="space-y-4">
                    <div v-if="currentStep === 2">
                        <label>Applicant Name</label>
                        <input v-model="form.applicant_name" type="text" class="w-full p-2 border rounded" required />
                        <p v-if="form.errors.applicant_name" class="text-red-600 text-sm mt-1">{{ form.errors.applicant_name }}</p>
                    </div>

                    <div v-if="currentStep === 2" class="flex flex-col sm:flex-row gap-4">
                        <div class="flex-1">
                            <label>Birthdate</label>
                            <input v-model="form.birthdate" type="date" class="w-full p-2 border rounded" required />
                            <p v-if="form.errors.birthdate" class="text-red-600 text-sm mt-1">{{ form.errors.birthdate }}</p>
                        </div>
                        <div class="flex-1">
                            <label>Age</label>
                            <input v-model="form.age" type="number" class="w-full p-2 border rounded" />
                        </div>
                        <div class="flex-1">
                            <label>Grade</label>
                            <input v-model="form.grade" type="text" class="w-full p-2 border rounded" />
                            <p v-if="form.errors.grade" class="text-red-600 text-sm mt-1">{{ form.errors.grade }}</p>
                        </div>
                    </div>
                    <div v-if="currentStep === 2">
                        <label>Cell Number</label>
                        <input :value="form.cell_number" @input="onCellNumberInput" type="text"
                            class="w-full p-2 border rounded" placeholder="(123) 456 7890" />
                        <p v-if="form.errors.cell_number" class="text-red-600 text-sm mt-1">{{ form.errors.cell_number }}</p>
                    </div>

                    <div v-if="currentStep === 3">
                        <label>Emergency Contact</label>
                        <input v-model="form.emergency_contact" type="text" class="w-full p-2 border rounded" />
                    </div>

                    <div v-if="currentStep === 4">
                        <label class="block mb-1">Investiture Class</label>
                        <div class="flex flex-wrap gap-2">
                            <label
                                v-for="level in ['Little Lambs', 'Eager Beavers', 'Busy Bee', 'Sunbeam', 'Builder', 'Helping Hand']"
                                :key="level" class="inline-flex items-center">
                                <input type="checkbox" :value="level" v-model="form.investiture_classes" class="mr-2" />
                                {{ level }}
                            </label>
                        </div>
                    </div>

                    <div v-if="currentStep === 4">
                        <label>Allergies</label>
                        <textarea v-model="form.allergies" class="w-full p-2 border rounded"></textarea>
                        <p v-if="form.errors.allergies" class="text-red-600 text-sm mt-1">{{ form.errors.allergies }}</p>
                    </div>

                    <div v-if="currentStep === 4">
                        <label>Physical Restrictions</label>
                        <textarea v-model="form.physical_restrictions" class="w-full p-2 border rounded"></textarea>
                        <p v-if="form.errors.physical_restrictions" class="text-red-600 text-sm mt-1">{{ form.errors.physical_restrictions }}</p>
                    </div>

                    <div v-if="currentStep === 4">
                        <label>Health History</label>
                        <textarea v-model="form.health_history" class="w-full p-2 border rounded"></textarea>
                        <p v-if="form.errors.health_history" class="text-red-600 text-sm mt-1">{{ form.errors.health_history }}</p>
                    </div>

                    <div v-if="currentStep === 3">
                        <label>Parent Name</label>
                        <input v-model="form.parent_name" type="text" class="w-full p-2 border rounded" required />
                        <p v-if="form.errors.parent_name" class="text-red-600 text-sm mt-1">{{ form.errors.parent_name }}</p>
                    </div>

                    <div v-if="currentStep === 3">
                        <label>Parent Cell</label>
                        <input :value="form.parent_cell" @input="onParentCellNumberInput" type="text"
                            class="w-full p-2 border rounded" placeholder="(123) 456 7890" />
                        <p v-if="form.errors.parent_cell" class="text-red-600 text-sm mt-1">{{ form.errors.parent_cell }}</p>
                    </div>

                    <div v-if="currentStep === 3">
                        <label>Home Address</label>
                        <input v-model="form.home_address" type="text" class="w-full p-2 border rounded" />
                        <p v-if="form.errors.home_address" class="text-red-600 text-sm mt-1">{{ form.errors.home_address }}</p>
                    </div>
                    <div v-if="currentStep === 3">
                        <label>Mailing Address</label>
                        <input v-model="form.mailing_address" type="text" class="w-full p-2 border rounded" />
                        <p v-if="form.errors.mailing_address" class="text-red-600 text-sm mt-1">{{ form.errors.mailing_address }}</p>
                    </div>
                    <div v-if="currentStep === 3" class="flex items-center mb-2">
                        <input id="same-address" type="checkbox" v-model="sameAsHomeAddress" class="mr-2" />
                        <label for="same-address">Same as home address</label>
                    </div>
                    <div v-if="currentStep === 3">
                        <label>Email Address</label>
                        <input v-model="form.email_address" type="email" class="w-full p-2 border rounded" />
                        <p v-if="form.errors.email_address" class="text-red-600 text-sm mt-1">{{ form.errors.email_address }}</p>
                    </div>

                </div>

                <div v-if="currentStep === 4 && !isMasterGuideClub" class="signature-section">
                    <p v-if="isMobileDevice" class="signature-choice-label">{{ t('signatureChoice') }}</p>
                    <div v-if="isMobileDevice" class="signature-mode-options" role="group" :aria-label="t('signatureChoice')">
                        <button type="button" :class="['signature-mode-button', { active: signatureMode === 'typed' }]" @click="selectSignatureMode('typed')">
                            {{ t('typeSignature') }}
                        </button>
                        <button type="button" :class="['signature-mode-button', { active: signatureMode === 'drawn' }]" @click="selectSignatureMode('drawn')">
                            {{ t('drawSignature') }}
                        </button>
                    </div>

                    <div v-if="signatureMode === 'typed'">
                        <label for="parent-signature">{{ t('signature') }}</label>
                        <input id="parent-signature" v-model="form.signature" type="text" autocomplete="name" class="w-full p-2 border rounded" />
                    </div>
                    <div v-else class="drawn-signature-panel">
                        <p class="signature-draw-hint">{{ t('drawHint') }}</p>
                        <canvas
                            ref="signatureCanvas"
                            class="signature-canvas"
                            :aria-label="t('drawSignature')"
                            @pointerdown.prevent="startSignature"
                            @pointermove.prevent="drawSignature"
                            @pointerup.prevent="finishSignature"
                            @pointercancel.prevent="finishSignature"
                            @pointerleave="finishSignature"
                        ></canvas>
                        <div class="signature-canvas-footer">
                            <span :class="hasDrawnSignature ? 'text-emerald-700' : 'text-slate-500'">
                                {{ hasDrawnSignature ? tr('Firma capturada', 'Signature captured') : tr('Firma pendiente', 'Signature pending') }}
                            </span>
                            <button type="button" class="signature-clear-button" @click="clearSignature">{{ t('clearSignature') }}</button>
                        </div>
                    </div>
                    <p v-if="form.errors.signature || form.errors.signature_data" class="text-red-600 text-sm mt-1">{{ form.errors.signature || form.errors.signature_data }}</p>
                </div>

                <div class="wizard-footer">
                    <div class="wizard-completion">
                        <span>{{ Math.round((currentStep / totalSteps) * 100) }}% {{ tr('completado', 'complete') }}</span>
                        <div class="wizard-progress-track">
                            <span class="wizard-progress-value" :style="{ width: `${(currentStep / totalSteps) * 100}%` }"></span>
                        </div>
                    </div>
                    <button v-if="currentStep > 1" type="button" @click="previousStep" class="wizard-back-button" :aria-label="tr('Paso anterior', 'Previous step')">
                        ↑
                    </button>
                    <span v-else class="wizard-button-spacer"></span>

                    <button v-if="currentStep < totalSteps" type="button" @click="nextStep" class="wizard-next-button">
                        {{ tr('Continuar', 'Continue') }} <span>↵</span>
                    </button>
                    <button v-else type="submit" :disabled="form.processing" class="wizard-next-button disabled:opacity-60">
                        {{ form.processing ? tr('Enviando...', 'Submitting...') : t('submit') }}
                    </button>
                </div>
            </form>
        </div>
    </PathfinderLayout>
</template>

<style scoped>
.enrollment-page {
    max-width: 72rem;
    margin: 0 auto;
}

.enrollment-wizard {
    min-height: min(42rem, calc(100vh - 10rem));
    padding: clamp(1.5rem, 6vw, 5rem) clamp(1.25rem, 9vw, 9rem) 7rem;
    position: relative;
}

.wizard-heading {
    display: flex;
    gap: 1rem;
    align-items: flex-start;
    margin-bottom: clamp(1.5rem, 4vw, 3.25rem);
}

.wizard-step-number {
    color: #2563eb;
    font-size: clamp(1.1rem, 2vw, 1.5rem);
    font-weight: 600;
    line-height: 1.4;
    white-space: nowrap;
}

.wizard-step-number span { margin-left: 0.2rem; }

.wizard-step-title {
    color: #111827;
    font-size: clamp(1.6rem, 3vw, 2.6rem);
    font-weight: 500;
    line-height: 1.2;
}

.enrollment-wizard :deep(label) {
    display: block;
    color: #1f2937;
    font-size: clamp(1.15rem, 2.3vw, 1.65rem);
    font-weight: 500;
    line-height: 1.35;
    margin-bottom: 0.75rem;
}

.enrollment-wizard :deep(input:not([type='checkbox'])),
.enrollment-wizard :deep(select),
.enrollment-wizard :deep(textarea) {
    width: 100%;
    min-height: 3.5rem;
    padding: 0.65rem 0.1rem;
    background: transparent;
    border: 0;
    border-bottom: 2px solid #cbd5e1;
    border-radius: 0;
    box-shadow: none;
    color: #111827;
    font-size: clamp(1.2rem, 2.5vw, 1.8rem);
    line-height: 1.3;
    transition: border-color 150ms ease;
}

.enrollment-wizard :deep(input:not([type='checkbox']):focus),
.enrollment-wizard :deep(select:focus),
.enrollment-wizard :deep(textarea:focus) {
    border-bottom-color: #2563eb;
    outline: none;
}

.enrollment-wizard :deep(input[readonly]) {
    color: #64748b;
    border-bottom-color: #e2e8f0;
}

.enrollment-wizard :deep(textarea) { min-height: 7.5rem; resize: vertical; }
.enrollment-wizard :deep(input[type='checkbox']) { width: 1.25rem; height: 1.25rem; accent-color: #2563eb; }
.enrollment-wizard :deep(.text-red-600) { margin-top: 0.5rem; font-size: 0.95rem; }

.wizard-footer {
    display: flex;
    align-items: center;
    gap: 0.65rem;
    position: absolute;
    right: clamp(1.25rem, 9vw, 9rem);
    bottom: 1.5rem;
    left: clamp(1.25rem, 9vw, 9rem);
}

.wizard-completion { width: min(13rem, 35%); margin-right: auto; }
.wizard-completion > span { display: block; color: #64748b; font-size: 0.8rem; margin-bottom: 0.35rem; }
.wizard-progress-track { height: 0.35rem; overflow: hidden; background: #e2e8f0; border-radius: 999px; }
.wizard-progress-value { display: block; height: 100%; background: #2563eb; border-radius: inherit; transition: width 220ms ease; }

.wizard-back-button, .wizard-next-button, .wizard-button-spacer {
    min-height: 3rem;
    border-radius: 0.35rem;
    font-size: 1rem;
    font-weight: 600;
}
.wizard-back-button { width: 3rem; background: #f1f5f9; color: #334155; }
.wizard-next-button { padding: 0.75rem 1.1rem; background: #2563eb; color: white; }
.wizard-next-button:hover { background: #1d4ed8; }
.wizard-next-button span { margin-left: 0.5rem; }
.wizard-button-spacer { width: 3rem; }

.signature-choice-label {
    color: #1f2937;
    font-size: 1rem;
    font-weight: 600;
    margin-bottom: 0.75rem;
}

.signature-mode-options {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 0.5rem;
    margin-bottom: 1.25rem;
}

.signature-mode-button {
    min-height: 3rem;
    padding: 0.65rem 0.75rem;
    border: 1px solid #cbd5e1;
    border-radius: 0.5rem;
    background: #fff;
    color: #334155;
    font-weight: 600;
}

.signature-mode-button.active {
    border-color: #2563eb;
    background: #eff6ff;
    color: #1d4ed8;
    box-shadow: 0 0 0 1px #2563eb;
}

.signature-draw-hint { color: #475569; font-size: 0.95rem; margin-bottom: 0.6rem; }
.signature-canvas {
    display: block;
    width: 100%;
    height: 180px;
    border: 2px dashed #94a3b8;
    border-radius: 0.6rem;
    background: #fff;
    cursor: crosshair;
    touch-action: none;
}
.signature-canvas-footer { display: flex; align-items: center; justify-content: space-between; gap: 1rem; margin-top: 0.6rem; font-size: 0.875rem; }
.signature-clear-button { color: #b91c1c; font-weight: 600; text-decoration: underline; text-underline-offset: 2px; }

@media (max-width: 640px) {
    .enrollment-page { padding: 0; }
    .enrollment-wizard { min-height: calc(100vh - 4rem); padding-bottom: 8rem; }
    .wizard-footer { bottom: 1rem; }
    .wizard-completion { width: 6rem; }
    .wizard-completion > span { font-size: 0.7rem; }
}
</style>
