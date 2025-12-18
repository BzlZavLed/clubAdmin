<script setup>
import { ref, computed, watch } from 'vue'
import { useForm, usePage, router } from '@inertiajs/vue3'
import { formatPhoneNumber, forceLogout } from '@/Helpers/general'
import PathfinderLayout from '@/Layouts/PathfinderLayout.vue'
import { useGeneral } from '@/Composables/useGeneral'

const page = usePage()
const auth_user = ref(page.props.auth_user || {})
const clubs = ref(page.props.clubs || [])
const sameAsHomeAddress = ref(false)
const lang = ref('en')

const showSuccess = ref(false)
const selectedClubId = ref(null)
const { showToast } = useGeneral()

const matchingClubs = computed(() =>
    clubs.value.filter(club => club.church_name === auth_user.value.church_name)
)
const selectedClub = computed(() =>
    matchingClubs.value.find(club => club.id === form.club_id) || null
)
const isPathfinderClub = computed(() => selectedClub.value?.club_type === 'pathfinders')

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

    investiture_classes: [],
    allergies: '',
    physical_restrictions: '',
    health_history: '',

    parent_name: '',
    parent_cell: '',
    home_address: '',
    email_address: '',
    signature: '',
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
    form.post('/parent/apply', {
        preserveScroll: true,
        onSuccess: () => {
            form.reset()
            showSuccess.value = true
            showToast('Member registered successfully!', 'success')
        },
        onError: (e) => {
            const firstError = Object.values(form.errors)[0]
            if (firstError) {
                showToast(firstError, 'error')
            }
        }
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
    }
}
const t = (key) => labels[lang.value]?.[key] || key
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

            <form @submit.prevent="submit" class="space-y-4 bg-white border rounded shadow-sm p-4 sm:p-6">
                <div class="flex justify-end">
                    <label class="text-sm text-gray-700 mr-2">Language / Idioma:</label>
                    <select v-model="lang" class="border rounded p-1 text-sm">
                        <option value="en">English</option>
                        <option value="es">Español</option>
                    </select>
                </div>
                <!-- Club Selection -->
                <div>
                    <label>{{ t('clubName') }}</label>
                    <select v-model="form.club_id" class="w-full p-2 border rounded">
                        <option disabled value="">-- Choose a club --</option>
                        <option v-for="club in matchingClubs" :key="club.id" :value="club.id">
                            {{ club.club_name }}
                        </option>
                    </select>
                    <p v-if="form.errors.club_id" class="text-red-600 text-sm mt-1">{{ form.errors.club_id }}</p>
                </div>
                <div>
                    <label>{{ t('churchName') }}</label>
                    <input v-model="form.church_name" type="text" class="w-full p-2 border rounded" readonly />
                </div>
                <div>
                    <label>{{ t('directorName') }}</label>
                    <input v-model="form.director_name" type="text" class="w-full p-2 border rounded" readonly />
                </div>
                <div v-if="isPathfinderClub" class="space-y-4">
                    <div>
                        <label>{{ t('pathfinderName') }}</label>
                        <input v-model="form.applicant_name" type="text" class="w-full p-2 border rounded" required />
                        <p v-if="form.errors.applicant_name" class="text-red-600 text-sm mt-1">{{ form.errors.applicant_name }}</p>
                    </div>
                    <div class="flex flex-col sm:flex-row gap-4">
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
                    <div>
                        <label>{{ t('email') }}</label>
                        <input v-model="form.email_address" type="email" class="w-full p-2 border rounded" required />
                        <p v-if="form.errors.email_address" class="text-red-600 text-sm mt-1">{{ form.errors.email_address }}</p>
                    </div>
                    <div>
                        <label>{{ t('parentName') }}</label>
                        <input v-model="form.parent_name" type="text" class="w-full p-2 border rounded" required />
                        <p v-if="form.errors.parent_name" class="text-red-600 text-sm mt-1">{{ form.errors.parent_name }}</p>
                    </div>
                    <div>
                        <label>{{ t('parentPhone') }}</label>
                        <input :value="form.parent_cell" @input="onParentCellNumberInput" type="text"
                            class="w-full p-2 border rounded" placeholder="(123) 456 7890" />
                        <p v-if="form.errors.parent_cell" class="text-red-600 text-sm mt-1">{{ form.errors.parent_cell }}</p>
                    </div>
                </div>

                <div v-else class="space-y-4">
                    <div>
                        <label>Applicant Name</label>
                        <input v-model="form.applicant_name" type="text" class="w-full p-2 border rounded" required />
                        <p v-if="form.errors.applicant_name" class="text-red-600 text-sm mt-1">{{ form.errors.applicant_name }}</p>
                    </div>

                    <div class="flex flex-col sm:flex-row gap-4">
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
                    <div>
                        <label>Cell Number</label>
                        <input :value="form.cell_number" @input="onCellNumberInput" type="text"
                            class="w-full p-2 border rounded" placeholder="(123) 456 7890" />
                        <p v-if="form.errors.cell_number" class="text-red-600 text-sm mt-1">{{ form.errors.cell_number }}</p>
                    </div>

                    <div>
                        <label>Emergency Contact</label>
                        <input v-model="form.emergency_contact" type="text" class="w-full p-2 border rounded" />
                    </div>

                    <div>
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

                    <div>
                        <label>Allergies</label>
                        <textarea v-model="form.allergies" class="w-full p-2 border rounded"></textarea>
                        <p v-if="form.errors.allergies" class="text-red-600 text-sm mt-1">{{ form.errors.allergies }}</p>
                    </div>

                    <div>
                        <label>Physical Restrictions</label>
                        <textarea v-model="form.physical_restrictions" class="w-full p-2 border rounded"></textarea>
                        <p v-if="form.errors.physical_restrictions" class="text-red-600 text-sm mt-1">{{ form.errors.physical_restrictions }}</p>
                    </div>

                    <div>
                        <label>Health History</label>
                        <textarea v-model="form.health_history" class="w-full p-2 border rounded"></textarea>
                        <p v-if="form.errors.health_history" class="text-red-600 text-sm mt-1">{{ form.errors.health_history }}</p>
                    </div>

                    <div>
                        <label>Parent Name</label>
                        <input v-model="form.parent_name" type="text" class="w-full p-2 border rounded" required />
                        <p v-if="form.errors.parent_name" class="text-red-600 text-sm mt-1">{{ form.errors.parent_name }}</p>
                    </div>

                    <div>
                        <label>Parent Cell</label>
                        <input :value="form.parent_cell" @input="onParentCellNumberInput" type="text"
                            class="w-full p-2 border rounded" placeholder="(123) 456 7890" />
                        <p v-if="form.errors.parent_cell" class="text-red-600 text-sm mt-1">{{ form.errors.parent_cell }}</p>
                    </div>

                    <div>
                        <label>Home Address</label>
                        <input v-model="form.home_address" type="text" class="w-full p-2 border rounded" />
                        <p v-if="form.errors.home_address" class="text-red-600 text-sm mt-1">{{ form.errors.home_address }}</p>
                    </div>
                    <div>
                        <label>Mailing Address</label>
                        <input v-model="form.mailing_address" type="text" class="w-full p-2 border rounded" />
                        <p v-if="form.errors.mailing_address" class="text-red-600 text-sm mt-1">{{ form.errors.mailing_address }}</p>
                    </div>
                    <div class="flex items-center mb-2">
                        <input id="same-address" type="checkbox" v-model="sameAsHomeAddress" class="mr-2" />
                        <label for="same-address">Same as home address</label>
                    </div>
                    <div>
                        <label>Email Address</label>
                        <input v-model="form.email_address" type="email" class="w-full p-2 border rounded" />
                        <p v-if="form.errors.email_address" class="text-red-600 text-sm mt-1">{{ form.errors.email_address }}</p>
                    </div>

                    <div>
                        <label>Signature (Typed)</label>
                        <input v-model="form.signature" type="text" class="w-full p-2 border rounded" />
                        <p v-if="form.errors.signature" class="text-red-600 text-sm mt-1">{{ form.errors.signature }}</p>
                    </div>
                </div>

                <div class="flex flex-wrap gap-2">
                    <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">
                        Submit Registration
                    </button>
                </div>
            </form>
        </div>
    </PathfinderLayout>
</template>
