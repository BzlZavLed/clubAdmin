<script setup>
import { computed, ref, watch } from 'vue'
import { useForm } from '@inertiajs/vue3'

const props = defineProps(['show', 'clubs', 'selectedClub', 'editingMember'])
const emit = defineEmits(['close', 'submitted'])

const selectedClub = ref(props.selectedClub)
const showError = ref(false)

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
    mark_insurance_paid: false,
    mark_enrollment_paid: false,
})
const sameAsHomeAddress = ref(false)
const insuranceAmount = computed(() => Number(selectedClub.value?.insurance_payment_amount || 0))
const enrollmentAmount = computed(() => Number(selectedClub.value?.enrollment_payment_amount || 0))
const canMarkInsurancePaid = computed(() =>
    (selectedClub.value?.evaluation_system || 'honors') === 'carpetas' && insuranceAmount.value > 0
)
const canMarkEnrollmentPaid = computed(() => enrollmentAmount.value > 0)

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
const fillClubFields = () => {
    if (selectedClub.value) {
        form.club_id = selectedClub.value.id
        form.club_name = selectedClub.value.club_name
        form.director_name = selectedClub.value.director_name
        form.church_name = selectedClub.value.church_name
    }
}

const resetToDefaults = () => {
    form.reset()
    form.clearErrors()
    showError.value = false
    sameAsHomeAddress.value = false
    fillClubFields()
    form.mark_insurance_paid = false
    form.mark_enrollment_paid = false
}

const populateForEdit = (member) => {
    if (!member) {
        resetToDefaults()
        return
    }

    fillClubFields()
    form.applicant_name = member.applicant_name || ''
    form.birthdate = member.birthdate ? String(member.birthdate).slice(0, 10) : ''
    form.age = member.age ?? ''
    form.grade = member.grade || ''
    form.mailing_address = member.mailing_address || ''
    form.cell_number = member.cell_number || ''
    form.emergency_contact = member.emergency_contact || ''
    form.investiture_classes = Array.isArray(member.investiture_classes) ? [...member.investiture_classes] : []
    form.allergies = member.allergies || ''
    form.physical_restrictions = member.physical_restrictions || ''
    form.health_history = member.health_history || ''
    form.parent_name = member.parent_name || ''
    form.parent_cell = member.parent_cell || ''
    form.home_address = member.home_address || ''
    form.email_address = member.email_address || ''
    form.signature = member.signature || ''
    form.mark_insurance_paid = Boolean(member.insurance_paid)
    form.mark_enrollment_paid = Boolean(member.enrollment_paid)
}

watch(() => props.selectedClub, () => {
    selectedClub.value = props.selectedClub
    fillClubFields()
}, { immediate: true })

watch(() => props.show, (isOpen) => {
    if (!isOpen) return
    populateForEdit(props.editingMember)
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

const onSubmit = () => {
    const requestOptions = {
        preserveScroll: true,
        onSuccess: () => {
            emit('submitted')
            emit('close')
        },
        onError: (errors) => {
            console.error(errors)
            showError.value = errors
        }
    }

    if (props.editingMember?.id) {
        form.put(`/members/${props.editingMember.id}`, requestOptions)
        return
    }

    form.post('/members', requestOptions)
}

function formatPhoneNumber(value) {
    const digits = value.replace(/\D/g, '').substring(0, 10)
    const parts = []

    if (digits.length > 0) parts.push('(' + digits.substring(0, 3))
    if (digits.length >= 4) parts.push(') ' + digits.substring(3, 6))
    if (digits.length >= 7) parts.push(' ' + digits.substring(6, 10))

    return parts.join('')
}

function onCellNumberInput(event) {
    form.cell_number = formatPhoneNumber(event.target.value)
}

function onParentCellNumberInput(event) {
    form.parent_cell = formatPhoneNumber(event.target.value)
}
</script>

<template>
<div v-if="show" class="fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-50">
    <div class="bg-white rounded-lg shadow-xl w-full max-w-3xl p-6 max-h-[90vh] overflow-y-auto">
        <div class="flex justify-between items-center mb-4">
            <h2 class="text-xl font-bold">{{ editingMember ? 'Editar Miembro Aventurero' : 'Register New Adventurer Member' }}</h2>
            <button @click="$emit('close')" class="text-red-500 hover:text-red-700 text-lg font-bold">&times;</button>
        </div>

        <form @submit.prevent="onSubmit" class="space-y-4">
            <div>
                <label class="block mb-1">Selected Club</label>
                <input
                    :value="selectedClub ? `${selectedClub.club_name} (${selectedClub.club_type})` : ''"
                    type="text"
                    class="w-full p-2 border rounded bg-gray-50"
                    readonly
                />
            </div>

            <div><label>Applicant Name</label><input v-model="form.applicant_name" type="text" class="w-full p-2 border rounded" /></div>

            <div class="flex gap-4">
                <div><label>Birthdate</label><input v-model="form.birthdate" type="date" class="p-2 border rounded w-full" /></div>
                <div><label>Age</label><input v-model="form.age" type="number" class="p-2 border rounded w-full" readonly /></div>
                <div><label>Grade</label><input v-model="form.grade" type="text" class="p-2 border rounded w-full" /></div>
            </div>

            <div><label>Cell Number</label><input :value="form.cell_number" @input="onCellNumberInput" type="text" class="w-full p-2 border rounded" placeholder="(123) 456 7890" />
            </div>
            <div><label>Emergency Contact</label><input v-model="form.emergency_contact" type="text" class="w-full p-2 border rounded" /></div>

            <div>
                <label class="block mb-1">Investiture Class</label>
                <div class="flex flex-wrap gap-2">
                    <label v-for="level in ['Little Lambs', 'Eager Beavers', 'Busy Bee', 'Sunbeam', 'Builder', 'Helping Hand']" :key="level" class="inline-flex items-center">
                        <input type="checkbox" :value="level" v-model="form.investiture_classes" class="mr-2" />
                        {{ level }}
                    </label>
                </div>
            </div>

            <div><label>Allergies</label><textarea v-model="form.allergies" class="w-full p-2 border rounded" /></div>
            <div><label>Physical Restrictions</label><textarea v-model="form.physical_restrictions" class="w-full p-2 border rounded" /></div>
            <div><label>Health History</label><textarea v-model="form.health_history" class="w-full p-2 border rounded" /></div>

            <div><label>Parent Name</label><input v-model="form.parent_name" type="text" class="w-full p-2 border rounded" /></div>
            <div><label>Parent Cell</label><input :value="form.parent_cell" @input="onParentCellNumberInput" type="text" class="w-full p-2 border rounded" placeholder="(123) 456 7890" /></div>
            <div><label>Home Address</label><input v-model="form.home_address" type="text" class="w-full p-2 border rounded" /></div>
            <div><label>Mailing Address</label><input v-model="form.mailing_address" type="text" class="w-full p-2 border rounded" /></div>
            <div class="flex items-center mb-2">
                <input id="same-address" type="checkbox" v-model="sameAsHomeAddress" class="mr-2" />
                <label for="same-address">Same as home address</label>
            </div>
            <div><label>Email Address</label><input v-model="form.email_address" type="email" class="w-full p-2 border rounded" /></div>
            <div><label>Signature (typed)</label><input v-model="form.signature" type="text" class="w-full p-2 border rounded" /></div>
            <div v-if="canMarkInsurancePaid || canMarkEnrollmentPaid" class="rounded border border-emerald-200 bg-emerald-50 p-4">
                <h3 class="mb-2 text-sm font-semibold text-emerald-900">Pagos al registrar</h3>
                <label
                    v-if="canMarkInsurancePaid"
                    class="mb-2 flex items-start gap-2 rounded border px-3 py-2 text-sm transition"
                    :class="form.mark_insurance_paid ? 'border-emerald-400 bg-emerald-100 text-emerald-950' : 'border-transparent text-emerald-900'"
                >
                    <input
                        v-model="form.mark_insurance_paid"
                        type="checkbox"
                        class="mt-1 h-4 w-4 accent-emerald-600 disabled:cursor-not-allowed disabled:opacity-100"
                        :disabled="form.mark_insurance_paid"
                    />
                    <span class="font-medium">{{ editingMember ? 'Seguro pagado' : 'Marcar seguro como pagado' }} (${{ insuranceAmount.toFixed(2) }})</span>
                </label>
                <label
                    v-if="canMarkEnrollmentPaid"
                    class="flex items-start gap-2 rounded border px-3 py-2 text-sm transition"
                    :class="form.mark_enrollment_paid ? 'border-emerald-400 bg-emerald-100 text-emerald-950' : 'border-transparent text-emerald-900'"
                >
                    <input
                        v-model="form.mark_enrollment_paid"
                        type="checkbox"
                        class="mt-1 h-4 w-4 accent-emerald-600 disabled:cursor-not-allowed disabled:opacity-100"
                        :disabled="form.mark_enrollment_paid"
                    />
                    <span class="font-medium">{{ editingMember ? 'Inscripción pagada' : 'Marcar inscripción como pagada' }} (${{ enrollmentAmount.toFixed(2) }})</span>
                </label>
            </div>
            <div v-if="showError && Object.keys(showError).length" class="mb-4 text-red-700 bg-red-100 p-3 rounded">
                <ul class="list-disc list-inside">
                    <li v-for="(message, field) in showError" :key="field">{{ message }}</li>
                </ul>
            </div>

            <div class="flex justify-end">
                <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">{{ editingMember ? 'Guardar cambios' : 'Submit' }}</button>
            </div>
        </form>
    </div>
</div>
</template>
