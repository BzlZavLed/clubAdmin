<script setup>
import { computed, ref, watch } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { useGeneral } from '@/Composables/useGeneral'
import { useLocale } from '@/Composables/useLocale'
import {
    fetchMasterGuideMemberSchema,
    updateMasterGuideMemberSchema,
} from '@/Services/api'

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

const { showToast } = useGeneral()
const { tr } = useLocale()

const showError = ref(false)
const showFieldBuilder = ref(false)
const schemaFields = ref([])
const savedSchemaFields = ref([])
const schemaLoading = ref(false)
const schemaSaving = ref(false)
const jsonError = ref('')

const fieldTypes = ['text', 'textarea', 'number', 'date', 'time', 'select', 'checkbox', 'email', 'phone']

const form = useForm({
    club_id: '',
    applicant_name: '',
    phone: '',
    address: '',
    email: '',
    emergency_contact_name: '',
    emergency_contact_phone: '',
    emergency_contact_email: '',
    program_year: 1,
    custom_fields_json: {},
    mark_insurance_paid: false,
    mark_enrollment_paid: false,
    is_sda: true,
    baptism_date: '',
})

const insuranceAmount = computed(() => Number(props.selectedClub?.insurance_payment_amount || 0))
const enrollmentAmount = computed(() => Number(props.selectedClub?.enrollment_payment_amount || 0))
const canMarkInsurancePaid = computed(() =>
    (props.selectedClub?.evaluation_system || 'honors') === 'carpetas' && insuranceAmount.value > 0
)
const canMarkEnrollmentPaid = computed(() => enrollmentAmount.value > 0)

const selectedClubLabel = computed(() => {
    if (!props.selectedClub) return tr('Sin club seleccionado', 'No club selected')
    return `${props.selectedClub.club_name} (${tr('Guia Mayor', 'Master Guide')})`
})

const blankSchemaField = () => ({
    key: '',
    label: '',
    type: 'text',
    required: false,
    help: '',
    optionsText: '',
})

const toSnake = (value) => String(value || '')
    .trim()
    .toLowerCase()
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .replace(/[^a-z0-9]+/g, '_')
    .replace(/^_+|_+$/g, '')

const builderFieldsFromSchema = (schema) => {
    const fields = Array.isArray(schema?.fields) ? schema.fields : []
    return fields.length
        ? fields.map((field) => ({
            key: field.key || '',
            label: field.label || '',
            type: field.type || 'text',
            required: !!field.required,
            help: field.help || '',
            optionsText: Array.isArray(field.options) ? field.options.join(', ') : '',
        }))
        : []
}

const cloneSchemaFields = (fields) => fields.map(field => ({ ...field }))

const normalizedSchemaFromFields = (fields, collectErrors = true) => {
    if (collectErrors) {
        jsonError.value = ''
    }
    const normalized = fields
        .map((field) => {
            const label = String(field.label || '').trim()
            const key = toSnake(field.key || label)
            const help = String(field.help || '').trim()
            const options = field.type === 'select'
                ? String(field.optionsText || '')
                    .split(',')
                    .map(option => option.trim())
                    .filter(Boolean)
                : []

            return {
                key,
                label,
                type: field.type || 'text',
                required: !!field.required,
                ...(help ? { help } : {}),
                ...(field.type === 'select' && options.length ? { options } : {}),
            }
        })
        .filter(field => field.key && field.label)

    const keys = new Set()
    for (const field of normalized) {
        if (keys.has(field.key)) {
            if (collectErrors) {
                jsonError.value = tr(`Key duplicado: ${field.key}`, `Duplicate key: ${field.key}`)
            }
            return null
        }
        keys.add(field.key)
    }

    return { mode: 'single', fields: normalized }
}

const normalizedSchema = (collectErrors = true) => normalizedSchemaFromFields(schemaFields.value, collectErrors)

const loadSchema = async () => {
    if (!props.selectedClub?.id) return

    schemaLoading.value = true
    try {
        const data = await fetchMasterGuideMemberSchema(props.selectedClub.id)
        const fields = builderFieldsFromSchema(data?.schema_json)
        savedSchemaFields.value = cloneSchemaFields(fields)
        schemaFields.value = cloneSchemaFields(fields)
    } catch (error) {
        console.error('Failed to load Master Guide member schema', error)
        showToast(tr('No se pudieron cargar los campos adicionales', 'Could not load extra fields'), 'error')
    } finally {
        schemaLoading.value = false
    }
}

const saveSchema = async () => {
    if (!props.selectedClub?.id) return
    const schema = normalizedSchema()
    if (!schema) return

    schemaSaving.value = true
    try {
        const data = await updateMasterGuideMemberSchema(props.selectedClub.id, schema)
        const fields = builderFieldsFromSchema(data?.schema_json)
        savedSchemaFields.value = cloneSchemaFields(fields)
        schemaFields.value = cloneSchemaFields(fields)
        ensureCustomFieldKeys()
        showToast(tr('Campos guardados para este club', 'Fields saved for this club'), 'success')
    } catch (error) {
        console.error('Failed to save Master Guide member schema', error)
        showToast(tr('No se pudieron guardar los campos', 'Could not save fields'), 'error')
    } finally {
        schemaSaving.value = false
    }
}

const addSchemaField = () => {
    schemaFields.value = [...schemaFields.value, blankSchemaField()]
    showFieldBuilder.value = true
}

const removeSchemaField = (index) => {
    schemaFields.value = schemaFields.value.filter((_, fieldIndex) => fieldIndex !== index)
}

const moveSchemaField = (index, direction) => {
    const target = index + direction
    if (target < 0 || target >= schemaFields.value.length) return
    const next = [...schemaFields.value]
    const [field] = next.splice(index, 1)
    next.splice(target, 0, field)
    schemaFields.value = next
}

const visibleFields = computed(() => {
    const schema = normalizedSchemaFromFields(savedSchemaFields.value, false)
    return schema?.fields || []
})

const ensureCustomFieldKeys = () => {
    const current = { ...(form.custom_fields_json || {}) }
    const next = {}
    visibleFields.value.forEach((field) => {
        next[field.key] = field.key in current
            ? current[field.key]
            : (field.type === 'checkbox' ? false : '')
    })
    form.custom_fields_json = next
}

const fillClubFields = () => {
    if (!props.selectedClub) return
    form.club_id = props.selectedClub.id
}

const resetForm = () => {
    form.reset()
    form.clearErrors()
    showError.value = false
    fillClubFields()
    form.program_year = 1
    form.custom_fields_json = {}
    form.emergency_contact_name = ''
    form.emergency_contact_phone = ''
    form.emergency_contact_email = ''
    form.mark_insurance_paid = false
    form.mark_enrollment_paid = false
    form.is_sda = true
    form.baptism_date = ''
    ensureCustomFieldKeys()
}

const populateForEdit = (member) => {
    if (!member) {
        resetForm()
        return
    }

    fillClubFields()
    form.applicant_name = member.applicant_name || ''
    form.phone = member.phone || member.cell_number || ''
    form.address = member.address || member.home_address || member.mailing_address || ''
    form.email = member.email || member.email_address || ''
    form.emergency_contact_name = member.emergency_contact_name || member.emergency_contact || ''
    form.emergency_contact_phone = member.emergency_contact_phone || ''
    form.emergency_contact_email = member.emergency_contact_email || ''
    form.program_year = Number(member.program_year || 1)
    form.custom_fields_json = { ...(member.custom_fields_json || {}) }
    form.mark_insurance_paid = Boolean(member.insurance_paid)
    form.mark_enrollment_paid = Boolean(member.enrollment_paid)
    form.is_sda = member.is_sda !== false
    form.baptism_date = member.baptism_date ? String(member.baptism_date).slice(0, 10) : ''
    showError.value = false
    form.clearErrors()
    ensureCustomFieldKeys()
}

watch(() => props.show, async (isOpen) => {
    if (!isOpen) return
    fillClubFields()
    await loadSchema()
    populateForEdit(props.editingMember)
})

watch(() => props.selectedClub, () => {
    fillClubFields()
})

watch(() => form.is_sda, (isSda) => {
    if (!isSda) {
        form.baptism_date = ''
    }
})

const inputTypeFor = (field) => {
    if (field.type === 'number') return 'number'
    if (field.type === 'date') return 'date'
    if (field.type === 'time') return 'time'
    if (field.type === 'email') return 'email'
    if (field.type === 'phone') return 'tel'
    return 'text'
}

const onSubmit = () => {
    ensureCustomFieldKeys()

    const requestOptions = {
        preserveScroll: true,
        onSuccess: () => {
            emit('submitted')
            emit('close')
        },
        onError: (errors) => {
            console.error(errors)
            showError.value = errors
        },
    }

    if (props.editingMember?.id) {
        form.put(`/members/${props.editingMember.id}`, requestOptions)
        return
    }

    form.post('/members', requestOptions)
}
</script>

<template>
    <div v-if="show" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
        <div class="max-h-[92vh] w-full max-w-5xl overflow-y-auto rounded-xl bg-white p-4 shadow-xl sm:p-6">
            <div class="mb-4 flex items-start justify-between gap-4">
                <div>
                    <h2 class="text-xl font-bold text-gray-900">
                        {{ editingMember ? tr('Editar miembro Guia Mayor', 'Edit Master Guide member') : tr('Registrar miembro Guia Mayor', 'Register Master Guide member') }}
                    </h2>
                    <p class="mt-1 text-sm text-gray-500">{{ selectedClubLabel }}</p>
                </div>
                <button type="button" class="rounded border px-3 py-1 text-lg font-bold text-gray-600 hover:bg-gray-50" @click="$emit('close')">
                    &times;
                </button>
            </div>

            <form @submit.prevent="onSubmit" class="space-y-5">
                <section class="rounded border border-gray-200 bg-gray-50 p-4">
                    <h3 class="mb-3 text-sm font-semibold uppercase text-gray-700">{{ tr('Datos base', 'Base data') }}</h3>
                    <div class="grid gap-4 md:grid-cols-2">
                        <div>
                            <label class="mb-1 block text-sm font-medium text-gray-700">{{ tr('Nombre', 'Name') }}</label>
                            <input v-model="form.applicant_name" type="text" class="w-full rounded border p-2 text-sm" required />
                            <p v-if="form.errors.applicant_name" class="mt-1 text-xs text-red-600">{{ form.errors.applicant_name }}</p>
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium text-gray-700">{{ tr('Año del programa', 'Program year') }}</label>
                            <select v-model.number="form.program_year" class="w-full rounded border p-2 text-sm" required>
                                <option :value="1">{{ tr('Año 1', 'Year 1') }}</option>
                                <option :value="2">{{ tr('Año 2', 'Year 2') }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium text-gray-700">{{ tr('Telefono', 'Phone') }}</label>
                            <input v-model="form.phone" type="tel" class="w-full rounded border p-2 text-sm" />
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium text-gray-700">Email</label>
                            <input v-model="form.email" type="email" class="w-full rounded border p-2 text-sm" />
                            <p v-if="form.errors.email" class="mt-1 text-xs text-red-600">{{ form.errors.email }}</p>
                        </div>
                        <div class="md:col-span-2">
                            <label class="mb-1 block text-sm font-medium text-gray-700">{{ tr('Direccion', 'Address') }}</label>
                            <input v-model="form.address" type="text" class="w-full rounded border p-2 text-sm" />
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium text-gray-700">{{ tr('Nombre del contacto de emergencia', 'Emergency contact name') }}</label>
                            <input v-model="form.emergency_contact_name" type="text" class="w-full rounded border p-2 text-sm" />
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium text-gray-700">{{ tr('Telefono de emergencia', 'Emergency phone') }}</label>
                            <input v-model="form.emergency_contact_phone" type="tel" class="w-full rounded border p-2 text-sm" />
                        </div>
                        <div class="md:col-span-2">
                            <label class="mb-1 block text-sm font-medium text-gray-700">{{ tr('Correo del contacto de emergencia', 'Emergency contact email') }}</label>
                            <input v-model="form.emergency_contact_email" type="email" class="w-full rounded border p-2 text-sm" />
                            <p v-if="form.errors.emergency_contact_email" class="mt-1 text-xs text-red-600">{{ form.errors.emergency_contact_email }}</p>
                        </div>
                    </div>
                </section>

                <section class="rounded border border-blue-100 bg-blue-50 p-4">
                    <h3 class="mb-3 text-sm font-semibold uppercase text-blue-900">{{ tr('Estado espiritual', 'Spiritual status') }}</h3>
                    <label class="flex items-start gap-3 rounded border bg-white px-3 py-2 text-sm text-gray-700">
                        <input v-model="form.is_sda" type="checkbox" class="mt-1 h-4 w-4 accent-blue-600" />
                        <span>
                            <span class="block font-medium text-gray-900">{{ tr('Miembro bautizado de la Iglesia Adventista del Septimo Dia', 'Baptized Seventh-day Adventist member') }}</span>
                            <span class="text-gray-500">{{ tr('Si no esta marcado, aparecera en el modulo distrital de cuidado pastoral.', 'If unchecked, the member appears in district pastoral care.') }}</span>
                        </span>
                    </label>
                    <div v-if="form.is_sda" class="mt-3">
                        <label class="mb-1 block text-sm font-medium text-gray-700">{{ tr('Fecha de bautismo (opcional)', 'Baptism date (optional)') }}</label>
                        <input v-model="form.baptism_date" type="date" class="w-full rounded border p-2 text-sm" />
                    </div>
                </section>

                <section class="rounded border border-gray-200 p-4">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <h3 class="text-sm font-semibold uppercase text-gray-700">{{ tr('Campos adicionales', 'Extra fields') }}</h3>
                            <p class="text-xs text-gray-500">{{ tr('Estos campos se guardan como plantilla para este club Guia Mayor.', 'These fields are saved as this Master Guide club template.') }}</p>
                        </div>
                        <button type="button" class="rounded border px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50" @click="showFieldBuilder = !showFieldBuilder">
                            {{ showFieldBuilder ? tr('Ocultar constructor', 'Hide builder') : tr('Editar campos', 'Edit fields') }}
                        </button>
                    </div>

                    <div v-if="schemaLoading" class="mt-3 text-sm text-gray-500">{{ tr('Cargando campos...', 'Loading fields...') }}</div>

                    <div v-if="visibleFields.length" class="mt-4 grid gap-4 md:grid-cols-2">
                        <div v-for="field in visibleFields" :key="field.key" :class="field.type === 'textarea' ? 'md:col-span-2' : ''">
                            <label class="mb-1 block text-sm font-medium text-gray-700">
                                {{ field.label }}
                                <span v-if="field.required" class="text-red-600">*</span>
                            </label>
                            <textarea
                                v-if="field.type === 'textarea'"
                                v-model="form.custom_fields_json[field.key]"
                                rows="3"
                                class="w-full rounded border p-2 text-sm"
                                :required="field.required"
                            />
                            <select
                                v-else-if="field.type === 'select'"
                                v-model="form.custom_fields_json[field.key]"
                                class="w-full rounded border p-2 text-sm"
                                :required="field.required"
                            >
                                <option value="">{{ tr('Seleccionar', 'Select') }}</option>
                                <option v-for="option in field.options || []" :key="option" :value="option">{{ option }}</option>
                            </select>
                            <label v-else-if="field.type === 'checkbox'" class="inline-flex items-center gap-2 rounded border px-3 py-2 text-sm">
                                <input v-model="form.custom_fields_json[field.key]" type="checkbox" />
                                {{ tr('Si', 'Yes') }}
                            </label>
                            <input
                                v-else
                                v-model="form.custom_fields_json[field.key]"
                                :type="inputTypeFor(field)"
                                class="w-full rounded border p-2 text-sm"
                                :required="field.required"
                            />
                            <p v-if="field.help" class="mt-1 text-xs text-gray-500">{{ field.help }}</p>
                        </div>
                    </div>
                    <div v-else class="mt-4 rounded border border-dashed p-4 text-sm text-gray-500">
                        {{ tr('No hay campos adicionales configurados.', 'No extra fields configured.') }}
                    </div>

                    <div v-if="showFieldBuilder" class="mt-4 rounded border bg-gray-50 p-3">
                        <div class="space-y-3">
                            <div v-for="(field, index) in schemaFields" :key="index" class="rounded border bg-white p-3">
                                <div class="grid gap-3 md:grid-cols-[1fr_1fr_140px_auto]">
                                    <div>
                                        <label class="mb-1 block text-xs font-medium text-gray-600">{{ tr('Etiqueta', 'Label') }}</label>
                                        <input v-model="field.label" class="w-full rounded border px-3 py-2 text-sm" :placeholder="tr('Talla de uniforme', 'Uniform size')" />
                                    </div>
                                    <div>
                                        <label class="mb-1 block text-xs font-medium text-gray-600">Key</label>
                                        <input v-model="field.key" class="w-full rounded border px-3 py-2 font-mono text-sm" :placeholder="tr('talla_uniforme', 'uniform_size')" />
                                    </div>
                                    <div>
                                        <label class="mb-1 block text-xs font-medium text-gray-600">{{ tr('Tipo', 'Type') }}</label>
                                        <select v-model="field.type" class="w-full rounded border px-3 py-2 text-sm">
                                            <option v-for="type in fieldTypes" :key="type" :value="type">{{ type }}</option>
                                        </select>
                                    </div>
                                    <label class="flex items-end gap-2 pb-2 text-sm text-gray-700">
                                        <input v-model="field.required" type="checkbox" />
                                        {{ tr('Requerido', 'Required') }}
                                    </label>
                                </div>
                                <div class="mt-3 grid gap-3 md:grid-cols-2">
                                    <div>
                                        <label class="mb-1 block text-xs font-medium text-gray-600">{{ tr('Ayuda', 'Help') }}</label>
                                        <input v-model="field.help" class="w-full rounded border px-3 py-2 text-sm" />
                                    </div>
                                    <div v-if="field.type === 'select'">
                                        <label class="mb-1 block text-xs font-medium text-gray-600">{{ tr('Opciones', 'Options') }}</label>
                                        <input v-model="field.optionsText" class="w-full rounded border px-3 py-2 text-sm" :placeholder="tr('Si, No, Pendiente', 'Yes, No, Pending')" />
                                    </div>
                                </div>
                                <div class="mt-3 flex flex-wrap justify-end gap-2">
                                    <button type="button" class="rounded border px-2 py-1 text-xs text-gray-600 hover:bg-gray-50" @click="moveSchemaField(index, -1)">
                                        {{ tr('Subir', 'Move up') }}
                                    </button>
                                    <button type="button" class="rounded border px-2 py-1 text-xs text-gray-600 hover:bg-gray-50" @click="moveSchemaField(index, 1)">
                                        {{ tr('Bajar', 'Move down') }}
                                    </button>
                                    <button type="button" class="rounded border border-rose-200 px-2 py-1 text-xs text-rose-700 hover:bg-rose-50" @click="removeSchemaField(index)">
                                        {{ tr('Quitar', 'Remove') }}
                                    </button>
                                </div>
                            </div>
                        </div>
                        <p v-if="jsonError" class="mt-2 text-sm text-red-600">{{ jsonError }}</p>
                        <div class="mt-3 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                            <button type="button" class="rounded border px-3 py-2 text-sm font-medium text-gray-700 hover:bg-white" @click="addSchemaField">
                                {{ tr('Agregar campo', 'Add field') }}
                            </button>
                            <button
                                type="button"
                                class="rounded bg-gray-800 px-3 py-2 text-sm font-medium text-white hover:bg-gray-900 disabled:cursor-not-allowed disabled:opacity-50"
                                :disabled="schemaSaving"
                                @click="saveSchema"
                            >
                                {{ schemaSaving ? tr('Guardando...', 'Saving...') : tr('Guardar plantilla de campos', 'Save field template') }}
                            </button>
                        </div>
                    </div>
                </section>

                <div v-if="canMarkInsurancePaid || canMarkEnrollmentPaid" class="rounded border border-emerald-200 bg-emerald-50 p-4">
                    <h3 class="mb-2 text-sm font-semibold text-emerald-900">{{ tr('Pagos al registrar', 'Payments during registration') }}</h3>
                    <label
                        v-if="canMarkEnrollmentPaid"
                        class="mb-2 flex items-start gap-2 rounded border px-3 py-2 text-sm transition"
                        :class="form.mark_enrollment_paid ? 'border-emerald-400 bg-emerald-100 text-emerald-950' : 'border-transparent text-emerald-900'"
                    >
                        <input v-model="form.mark_enrollment_paid" type="checkbox" class="mt-1 h-4 w-4 accent-emerald-600" :disabled="form.mark_enrollment_paid" />
                        <span class="font-medium">{{ editingMember ? tr('Inscripcion pagada', 'Enrollment paid') : tr('Marcar inscripcion como pagada', 'Mark enrollment as paid') }} (${{ enrollmentAmount.toFixed(2) }})</span>
                    </label>
                    <label
                        v-if="canMarkInsurancePaid"
                        class="flex items-start gap-2 rounded border px-3 py-2 text-sm transition"
                        :class="form.mark_insurance_paid ? 'border-emerald-400 bg-emerald-100 text-emerald-950' : 'border-transparent text-emerald-900'"
                    >
                        <input v-model="form.mark_insurance_paid" type="checkbox" class="mt-1 h-4 w-4 accent-emerald-600" :disabled="form.mark_insurance_paid" />
                        <span class="font-medium">{{ editingMember ? tr('Seguro pagado', 'Insurance paid') : tr('Marcar seguro como pagado', 'Mark insurance as paid') }} (${{ insuranceAmount.toFixed(2) }})</span>
                    </label>
                </div>

                <div v-if="showError && Object.keys(showError).length" class="rounded bg-red-100 p-3 text-sm text-red-700">
                    <ul class="list-inside list-disc">
                        <li v-for="(message, field) in showError" :key="field">{{ message }}</li>
                    </ul>
                </div>

                <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                    <button type="button" class="rounded border px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50" @click="$emit('close')">
                        {{ tr('Cancelar', 'Cancel') }}
                    </button>
                    <button type="submit" class="rounded bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">
                        {{ editingMember ? tr('Guardar cambios', 'Save changes') : tr('Registrar miembro', 'Register member') }}
                    </button>
                </div>
            </form>
        </div>
    </div>
</template>
