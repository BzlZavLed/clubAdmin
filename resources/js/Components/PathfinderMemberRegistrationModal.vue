<script setup>
import { computed, ref, watch } from 'vue'
import { useForm } from '@inertiajs/vue3'

const props = defineProps({
    show: Boolean,
    selectedClub: {
        type: Object,
        default: null,
    },
    editingMember: {
        type: Object,
        default: null,
    },
})

const emit = defineEmits(['close', 'submitted'])

const showError = ref(false)
const pickupAuthorizedText = ref('')

const form = useForm({
    club_id: '',
    club_name: '',
    director_name: '',
    church_name: '',
    applicant_name: '',
    birthdate: '',
    grade: '',
    mailing_address: '',
    city: '',
    state: '',
    zip: '',
    school: '',
    cell_number: '',
    email_address: '',
    father_guardian_name: '',
    father_guardian_email: '',
    father_guardian_phone: '',
    mother_guardian_name: '',
    mother_guardian_email: '',
    mother_guardian_phone: '',
    pickup_authorized_people: [],
    consent_acknowledged: false,
    photo_release: false,
    health_history: '',
    disabilities: '',
    medication_allergies: '',
    food_allergies: '',
    dietary_considerations: '',
    physical_restrictions: '',
    immunization_notes: '',
    current_medications: '',
    physician_name: '',
    physician_phone: '',
    emergency_contact_name: '',
    emergency_contact_phone: '',
    insurance_provider: '',
    insurance_number: '',
    parent_guardian_signature: '',
    signed_at: '',
    mark_insurance_paid: false,
    mark_enrollment_paid: false,
})

const insuranceAmount = computed(() => Number(props.selectedClub?.insurance_payment_amount || 0))
const enrollmentAmount = computed(() => Number(props.selectedClub?.enrollment_payment_amount || 0))
const canMarkInsurancePaid = computed(() =>
    (props.selectedClub?.evaluation_system || 'honors') === 'carpetas' && insuranceAmount.value > 0
)
const canMarkEnrollmentPaid = computed(() => enrollmentAmount.value > 0)

const selectedClubLabel = computed(() => {
    if (!props.selectedClub) return 'Sin club seleccionado'
    return `${props.selectedClub.club_name} (${props.selectedClub.club_type})`
})

const fillClubFields = () => {
    if (!props.selectedClub) return
    form.club_id = props.selectedClub.id
    form.club_name = props.selectedClub.club_name || ''
    form.director_name = props.selectedClub.director_name || ''
    form.church_name = props.selectedClub.church_name || ''
}

const resetForm = () => {
    form.reset()
    form.clearErrors()
    pickupAuthorizedText.value = ''
    showError.value = false
    fillClubFields()
    form.mark_insurance_paid = false
    form.mark_enrollment_paid = false
}

const populateForEdit = (member) => {
    if (!member) {
        resetForm()
        return
    }

    fillClubFields()
    form.applicant_name = member.applicant_name || ''
    form.birthdate = member.birthdate ? String(member.birthdate).slice(0, 10) : ''
    form.grade = member.grade || ''
    form.mailing_address = member.mailing_address || ''
    form.city = member.city || ''
    form.state = member.state || ''
    form.zip = member.zip || ''
    form.school = member.school || ''
    form.cell_number = member.cell_number || ''
    form.email_address = member.email_address || ''
    form.father_guardian_name = member.father_guardian_name || member.parent_name || ''
    form.father_guardian_email = member.father_guardian_email || ''
    form.father_guardian_phone = member.father_guardian_phone || member.parent_cell || ''
    form.mother_guardian_name = member.mother_guardian_name || ''
    form.mother_guardian_email = member.mother_guardian_email || ''
    form.mother_guardian_phone = member.mother_guardian_phone || ''
    pickupAuthorizedText.value = Array.isArray(member.pickup_authorized_people) ? member.pickup_authorized_people.join('\n') : ''
    form.consent_acknowledged = Boolean(member.consent_acknowledged)
    form.photo_release = Boolean(member.photo_release)
    form.health_history = member.health_history || ''
    form.disabilities = member.disabilities || ''
    form.medication_allergies = member.medication_allergies || ''
    form.food_allergies = member.food_allergies || ''
    form.dietary_considerations = member.dietary_considerations || ''
    form.physical_restrictions = member.physical_restrictions || ''
    form.immunization_notes = member.immunization_notes || ''
    form.current_medications = member.current_medications || ''
    form.physician_name = member.physician_name || ''
    form.physician_phone = member.physician_phone || ''
    form.emergency_contact_name = member.emergency_contact_name || member.emergency_contact || ''
    form.emergency_contact_phone = member.emergency_contact_phone || ''
    form.insurance_provider = member.insurance_provider || ''
    form.insurance_number = member.insurance_number || ''
    form.parent_guardian_signature = member.signature || ''
    form.signed_at = member.signed_at ? String(member.signed_at).slice(0, 10) : ''
    form.mark_insurance_paid = Boolean(member.insurance_paid)
    form.mark_enrollment_paid = Boolean(member.enrollment_paid)
    showError.value = false
    form.clearErrors()
}

watch(() => props.selectedClub, () => {
    fillClubFields()
}, { immediate: true })

watch(() => props.show, (isOpen) => {
    if (isOpen) {
        populateForEdit(props.editingMember)
    }
})

function formatPhoneNumber(value) {
    const digits = String(value || '').replace(/\D/g, '').substring(0, 10)
    if (!digits) return ''

    if (digits.length < 4) return `(${digits}`
    if (digits.length < 7) return `(${digits.slice(0, 3)}) ${digits.slice(3)}`
    return `(${digits.slice(0, 3)}) ${digits.slice(3, 6)} ${digits.slice(6, 10)}`
}

const onPhoneInput = (field) => (event) => {
    form[field] = formatPhoneNumber(event.target.value)
}

const onSubmit = () => {
    const pickupAuthorizedPeople = pickupAuthorizedText.value
        .split('\n')
        .map(line => line.trim())
        .filter(Boolean)

    const payload = {
        club_id: form.club_id,
        club_name: form.club_name,
        director_name: form.director_name,
        church_name: form.church_name,
        applicant_name: form.applicant_name,
        birthdate: form.birthdate,
        grade: form.grade,
        mailing_address: form.mailing_address,
        city: form.city,
        state: form.state,
        zip: form.zip,
        school: form.school,
        cell_number: form.cell_number,
        email_address: form.email_address,
        father_guardian_name: form.father_guardian_name,
        father_guardian_email: form.father_guardian_email,
        father_guardian_phone: form.father_guardian_phone,
        mother_guardian_name: form.mother_guardian_name,
        mother_guardian_email: form.mother_guardian_email,
        mother_guardian_phone: form.mother_guardian_phone,
        pickup_authorized_people: pickupAuthorizedPeople,
        consent_acknowledged: form.consent_acknowledged,
        photo_release: form.photo_release,
        health_history: form.health_history,
        disabilities: form.disabilities,
        medication_allergies: form.medication_allergies,
        food_allergies: form.food_allergies,
        dietary_considerations: form.dietary_considerations,
        physical_restrictions: form.physical_restrictions,
        immunization_notes: form.immunization_notes,
        current_medications: form.current_medications,
        physician_name: form.physician_name,
        physician_phone: form.physician_phone,
        emergency_contact_name: form.emergency_contact_name,
        emergency_contact_phone: form.emergency_contact_phone,
        insurance_provider: form.insurance_provider,
        insurance_number: form.insurance_number,
        parent_guardian_signature: form.parent_guardian_signature,
        signed_at: form.signed_at,
        mark_insurance_paid: form.mark_insurance_paid,
        mark_enrollment_paid: form.mark_enrollment_paid,
    }

    console.log('Submitting Pathfinder member payload', payload)

    const requestOptions = {
        preserveScroll: true,
        onSuccess: () => {
            form.transform(data => data)
            emit('submitted')
            emit('close')
        },
        onError: (errors) => {
            form.transform(data => data)
            console.error(errors)
            showError.value = errors
        },
    }

    const request = form.transform(() => payload)

    if (props.editingMember?.id) {
        request.put(`/members/${props.editingMember.id}`, requestOptions)
        return
    }

    request.post('/members', requestOptions)
}
</script>

<template>
    <div v-if="show" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
        <div class="w-full max-w-5xl max-h-[92vh] overflow-y-auto rounded-xl bg-white p-6 shadow-xl">
            <div class="mb-5 flex items-center justify-between">
                <div>
                    <h2 class="text-xl font-bold text-gray-900">{{ editingMember ? 'Editar Miembro de Conquistadores' : 'Registrar Nuevo Miembro de Conquistadores' }}</h2>
                    <p class="text-sm text-gray-600">{{ selectedClubLabel }}</p>
                </div>
                <button type="button" class="text-lg font-bold text-red-500 hover:text-red-700" @click="$emit('close')">&times;</button>
            </div>

            <form @submit.prevent="onSubmit" class="space-y-6">
                <section class="rounded-lg border border-gray-200 p-4">
                    <h3 class="mb-3 text-sm font-semibold uppercase tracking-wide text-gray-800">Informacion General</h3>
                    <div class="grid gap-4 md:grid-cols-2">
                        <div>
                            <label class="mb-1 block text-sm font-medium text-gray-700">Nombre del club</label>
                            <input v-model="form.club_name" type="text" class="w-full rounded border p-2 bg-gray-50" readonly />
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium text-gray-700">Director del club</label>
                            <input v-model="form.director_name" type="text" class="w-full rounded border p-2 bg-gray-50" readonly />
                        </div>
                    </div>
                </section>

                <section class="rounded-lg border border-gray-200 p-4">
                    <h3 class="mb-3 text-sm font-semibold uppercase tracking-wide text-gray-800">Solicitante</h3>
                    <div class="grid gap-4 md:grid-cols-2">
                        <div>
                            <label class="mb-1 block text-sm font-medium text-gray-700">Nombre</label>
                            <input v-model="form.applicant_name" type="text" class="w-full rounded border p-2" />
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium text-gray-700">Fecha de nacimiento</label>
                            <input v-model="form.birthdate" type="date" class="w-full rounded border p-2" />
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium text-gray-700">Direccion</label>
                            <input v-model="form.mailing_address" type="text" class="w-full rounded border p-2" />
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium text-gray-700">Ciudad</label>
                            <input v-model="form.city" type="text" class="w-full rounded border p-2" />
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium text-gray-700">Estado</label>
                            <input v-model="form.state" type="text" class="w-full rounded border p-2" />
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium text-gray-700">Zip</label>
                            <input v-model="form.zip" type="text" class="w-full rounded border p-2" />
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium text-gray-700">Escuela</label>
                            <input v-model="form.school" type="text" class="w-full rounded border p-2" />
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium text-gray-700">Grado</label>
                            <input v-model="form.grade" type="text" class="w-full rounded border p-2" />
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium text-gray-700">Email</label>
                            <input v-model="form.email_address" type="email" class="w-full rounded border p-2" />
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium text-gray-700">Telefono</label>
                            <input v-model="form.cell_number" @input="onPhoneInput('cell_number')" type="text" class="w-full rounded border p-2" placeholder="(123) 456 7890" />
                        </div>
                    </div>
                </section>

                <section class="rounded-lg border border-gray-200 p-4">
                    <h3 class="mb-3 text-sm font-semibold uppercase tracking-wide text-gray-800">Padres o Guardianes</h3>
                    <div class="grid gap-4 md:grid-cols-2">
                        <div>
                            <label class="mb-1 block text-sm font-medium text-gray-700">Padre/Guardian</label>
                            <input v-model="form.father_guardian_name" type="text" class="w-full rounded border p-2" />
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium text-gray-700">Email Padre/Guardian</label>
                            <input v-model="form.father_guardian_email" type="email" class="w-full rounded border p-2" />
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium text-gray-700">Telefono Padre/Guardian</label>
                            <input v-model="form.father_guardian_phone" @input="onPhoneInput('father_guardian_phone')" type="text" class="w-full rounded border p-2" placeholder="(123) 456 7890" />
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium text-gray-700">Madre/Guardian</label>
                            <input v-model="form.mother_guardian_name" type="text" class="w-full rounded border p-2" />
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium text-gray-700">Email Madre/Guardian</label>
                            <input v-model="form.mother_guardian_email" type="email" class="w-full rounded border p-2" />
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium text-gray-700">Telefono Madre/Guardian</label>
                            <input v-model="form.mother_guardian_phone" @input="onPhoneInput('mother_guardian_phone')" type="text" class="w-full rounded border p-2" placeholder="(123) 456 7890" />
                        </div>
                    </div>
                    <div class="mt-4">
                        <label class="mb-1 block text-sm font-medium text-gray-700">Personas autorizadas para recoger al menor</label>
                        <textarea v-model="pickupAuthorizedText" rows="3" class="w-full rounded border p-2" placeholder="Una persona por linea"></textarea>
                    </div>
                </section>

                <section class="rounded-lg border border-gray-200 p-4">
                    <h3 class="mb-3 text-sm font-semibold uppercase tracking-wide text-gray-800">Salud y Emergencia</h3>
                    <div class="grid gap-4 md:grid-cols-2">
                        <div>
                            <label class="mb-1 block text-sm font-medium text-gray-700">Historial de salud</label>
                            <textarea v-model="form.health_history" rows="3" class="w-full rounded border p-2"></textarea>
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium text-gray-700">Discapacidades</label>
                            <textarea v-model="form.disabilities" rows="3" class="w-full rounded border p-2"></textarea>
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium text-gray-700">Alergias a medicamentos</label>
                            <textarea v-model="form.medication_allergies" rows="3" class="w-full rounded border p-2"></textarea>
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium text-gray-700">Alergias a alimentos</label>
                            <textarea v-model="form.food_allergies" rows="3" class="w-full rounded border p-2"></textarea>
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium text-gray-700">Consideraciones dieteticas</label>
                            <textarea v-model="form.dietary_considerations" rows="3" class="w-full rounded border p-2"></textarea>
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium text-gray-700">Restricciones fisicas</label>
                            <textarea v-model="form.physical_restrictions" rows="3" class="w-full rounded border p-2"></textarea>
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium text-gray-700">Vacunas / shot records</label>
                            <textarea v-model="form.immunization_notes" rows="3" class="w-full rounded border p-2"></textarea>
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium text-gray-700">Medicamentos actuales</label>
                            <textarea v-model="form.current_medications" rows="3" class="w-full rounded border p-2"></textarea>
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium text-gray-700">Medico primario</label>
                            <input v-model="form.physician_name" type="text" class="w-full rounded border p-2" />
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium text-gray-700">Telefono del medico</label>
                            <input v-model="form.physician_phone" @input="onPhoneInput('physician_phone')" type="text" class="w-full rounded border p-2" placeholder="(123) 456 7890" />
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium text-gray-700">Contacto de emergencia</label>
                            <input v-model="form.emergency_contact_name" type="text" class="w-full rounded border p-2" />
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium text-gray-700">Telefono de emergencia</label>
                            <input v-model="form.emergency_contact_phone" @input="onPhoneInput('emergency_contact_phone')" type="text" class="w-full rounded border p-2" placeholder="(123) 456 7890" />
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium text-gray-700">Seguro medico</label>
                            <input v-model="form.insurance_provider" type="text" class="w-full rounded border p-2" />
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium text-gray-700">Numero de poliza</label>
                            <input v-model="form.insurance_number" type="text" class="w-full rounded border p-2" />
                        </div>
                    </div>
                </section>

                <section class="rounded-lg border border-gray-200 p-4">
                    <h3 class="mb-3 text-sm font-semibold uppercase tracking-wide text-gray-800">Consentimientos</h3>
                    <div class="space-y-3">
                        <label class="flex items-start gap-3">
                            <input v-model="form.consent_acknowledged" type="checkbox" class="mt-1" />
                            <span class="text-sm text-gray-700">Confirmo que el padre o guardian leyo y acepta el acuerdo del formulario de Conquistadores.</span>
                        </label>
                        <label class="flex items-start gap-3">
                            <input v-model="form.photo_release" type="checkbox" class="mt-1" />
                            <span class="text-sm text-gray-700">Autorizo el uso de fotos o video para publicaciones y material promocional.</span>
                        </label>
                    </div>
                    <div class="mt-4 grid gap-4 md:grid-cols-2">
                        <div>
                            <label class="mb-1 block text-sm font-medium text-gray-700">Firma del padre o guardian</label>
                            <input v-model="form.parent_guardian_signature" type="text" class="w-full rounded border p-2" />
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium text-gray-700">Fecha</label>
                            <input v-model="form.signed_at" type="date" class="w-full rounded border p-2" />
                        </div>
                    </div>
                </section>

                <section v-if="canMarkInsurancePaid || canMarkEnrollmentPaid" class="rounded-lg border border-emerald-200 bg-emerald-50 p-4">
                    <h3 class="mb-3 text-sm font-semibold uppercase tracking-wide text-emerald-900">Pagos al registrar</h3>
                    <div class="space-y-3">
                        <label
                            v-if="canMarkInsurancePaid"
                            class="flex items-start gap-3 rounded border px-3 py-2 transition"
                            :class="form.mark_insurance_paid ? 'border-emerald-400 bg-emerald-100 text-emerald-950' : 'border-transparent'"
                        >
                            <input
                                v-model="form.mark_insurance_paid"
                                type="checkbox"
                                class="mt-1 h-4 w-4 accent-emerald-600 disabled:cursor-not-allowed disabled:opacity-100"
                                :disabled="form.mark_insurance_paid"
                            />
                            <span class="text-sm font-medium">{{ editingMember ? 'Seguro pagado' : 'Marcar seguro como pagado' }} (${{ insuranceAmount.toFixed(2) }})</span>
                        </label>
                        <label
                            v-if="canMarkEnrollmentPaid"
                            class="flex items-start gap-3 rounded border px-3 py-2 transition"
                            :class="form.mark_enrollment_paid ? 'border-emerald-400 bg-emerald-100 text-emerald-950' : 'border-transparent'"
                        >
                            <input
                                v-model="form.mark_enrollment_paid"
                                type="checkbox"
                                class="mt-1 h-4 w-4 accent-emerald-600 disabled:cursor-not-allowed disabled:opacity-100"
                                :disabled="form.mark_enrollment_paid"
                            />
                            <span class="text-sm font-medium">{{ editingMember ? 'Inscripción pagada' : 'Marcar inscripción como pagada' }} (${{ enrollmentAmount.toFixed(2) }})</span>
                        </label>
                    </div>
                </section>

                <div v-if="showError && Object.keys(showError).length" class="rounded bg-red-100 p-3 text-red-700">
                    <ul class="list-disc list-inside">
                        <li v-for="(message, field) in showError" :key="field">{{ message }}</li>
                    </ul>
                </div>

                <div class="flex justify-end gap-3">
                    <button type="button" class="rounded border px-4 py-2 text-gray-700 hover:bg-gray-50" @click="$emit('close')">Cancelar</button>
                    <button type="submit" class="rounded bg-blue-600 px-4 py-2 text-white hover:bg-blue-700">{{ editingMember ? 'Guardar cambios' : 'Guardar miembro' }}</button>
                </div>
            </form>
        </div>
    </div>
</template>
