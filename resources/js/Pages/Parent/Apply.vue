<script setup>
import { ref, computed, watch } from 'vue'
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

const matchingClubs = computed(() =>
    clubs.value.filter(club => club.church_name === auth_user.value.church_name)
)
const selectedClub = computed(() =>
    matchingClubs.value.find(club => club.id === form.club_id) || null
)
const isPathfinderClub = computed(() => selectedClub.value?.club_type === 'pathfinders')
const isMasterGuideClub = computed(() => selectedClub.value?.club_type === 'master_guide')
const totalSteps = computed(() => isMasterGuideClub.value || isPathfinderClub.value ? 3 : 4)

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
    club_id: '',
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
    program_year: 1,
})


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
})

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
        : { ...form.data() }

    form.transform(() => payload).post('/parent/apply', {
        preserveScroll: true,
        onSuccess: () => {
            form.transform(data => data)
            form.reset()
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

        <div class="p-4 sm:p-6 max-w-4xl">
            <div v-if="showSuccess" class="mb-4 text-green-700 bg-green-100 p-3 rounded">
                Member registered successfully!
            </div>
            <div v-if="Object.keys(form.errors).length" class="mb-4 text-red-700 bg-red-100 p-3 rounded text-sm">
                Please fix the highlighted fields.
            </div>

            <form novalidate @submit.prevent="submit" class="space-y-5 bg-white border rounded shadow-sm p-4 sm:p-6">
                <div class="flex items-center justify-between gap-3 border-b pb-4">
                    <div>
                        <p class="text-sm font-semibold text-blue-700">{{ tr('Paso', 'Step') }} {{ currentStep }} {{ tr('de', 'of') }} {{ totalSteps }}</p>
                        <p class="text-sm text-gray-600">{{ currentStep === 1 ? tr('Selecciona el club', 'Select the club') : (currentStep === 2 ? tr('Datos del menor', 'Child details') : (currentStep === totalSteps ? tr('Revisión y envío', 'Review and submit') : tr('Información de contacto', 'Contact information'))) }}</p>
                    </div>
                    <div class="flex gap-1">
                        <span v-for="step in totalSteps" :key="step" class="h-2 w-7 rounded-full" :class="step <= currentStep ? 'bg-blue-600' : 'bg-gray-200'"></span>
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

                    <div v-if="currentStep === 4">
                        <label>Signature (Typed)</label>
                        <input v-model="form.signature" type="text" class="w-full p-2 border rounded" />
                        <p v-if="form.errors.signature" class="text-red-600 text-sm mt-1">{{ form.errors.signature }}</p>
                    </div>
                </div>

                <div class="flex items-center justify-between gap-3 border-t pt-5">
                    <button v-if="currentStep > 1" type="button" @click="previousStep" class="min-h-12 rounded border border-gray-300 px-5 py-3 text-base font-medium text-gray-700">
                        {{ tr('Anterior', 'Back') }}
                    </button>
                    <span v-else></span>

                    <button v-if="currentStep < totalSteps" type="button" @click="nextStep" class="min-h-12 rounded bg-blue-600 px-5 py-3 text-base font-semibold text-white hover:bg-blue-700">
                        {{ tr('Continuar', 'Continue') }}
                    </button>
                    <button v-else type="submit" :disabled="form.processing" class="min-h-12 rounded bg-blue-600 px-5 py-3 text-base font-semibold text-white hover:bg-blue-700 disabled:opacity-60">
                        {{ form.processing ? tr('Enviando...', 'Submitting...') : t('submit') }}
                    </button>
                </div>
            </form>
        </div>
    </PathfinderLayout>
</template>

<style scoped>
@media (max-width: 640px) {
    input:not([type='checkbox']), select, textarea {
        min-height: 3rem;
        padding: 0.75rem;
        font-size: 1rem;
    }

    textarea {
        min-height: 6rem;
    }
}
</style>
