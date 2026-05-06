<script setup>
import PathfinderLayout from '@/Layouts/PathfinderLayout.vue'
import InputLabel from '@/Components/InputLabel.vue'
import InputError from '@/Components/InputError.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'
import { useForm, usePage } from '@inertiajs/vue3'
import { useLocale } from '@/Composables/useLocale'
import { useGeneral } from '@/Composables/useGeneral'
import { computed, ref } from 'vue'

const props = defineProps({
    association: { type: Object, required: true },
})

const { tr } = useLocale()
const { showToast } = useGeneral()
const page = usePage()

const bankInfoDefaults = {
    label: 'Presupuesto de asociación',
    bank_name: '',
    account_holder: '',
    account_type: '',
    account_number: '',
    routing_number: '',
    zelle_email: '',
    zelle_phone: '',
    deposit_instructions: '',
    is_active: true,
    accepts_event_deposits: true,
    requires_receipt_upload: true,
}

const form = useForm({
    insurance_payment_amount: props.association.insurance_payment_amount ?? '',
    bank_info: {
        ...bankInfoDefaults,
        ...(props.association.bank_info || {}),
    },
})

const syncForm = useForm({})
const bankInfoSaved = ref(Boolean(props.association.bank_info?.id))
const editingBankInfo = ref(!bankInfoSaved.value)

const hasBankInfoValues = (info) => {
    return ['bank_name', 'account_holder', 'account_number', 'routing_number', 'zelle_email', 'zelle_phone']
        .some((key) => String(info?.[key] || '').trim() !== '')
}

const bankInfoRows = computed(() => [
    [tr('Etiqueta', 'Label'), form.bank_info.label],
    [tr('Banco', 'Bank'), form.bank_info.bank_name],
    [tr('Titular', 'Account holder'), form.bank_info.account_holder],
    [tr('Tipo de cuenta', 'Account type'), form.bank_info.account_type],
    [tr('Número de cuenta', 'Account number'), form.bank_info.account_number],
    [tr('Routing / ABA', 'Routing / ABA'), form.bank_info.routing_number],
    ['Zelle email', form.bank_info.zelle_email],
    [tr('Zelle teléfono', 'Zelle phone'), form.bank_info.zelle_phone],
])

const firstErrorMessage = (errors) => {
    const first = Object.values(errors || {})[0]
    return Array.isArray(first) ? first[0] : first
}

const submit = () => {
    form.patch(route('association.settings.update'), {
        preserveScroll: true,
        onSuccess: (page) => {
            bankInfoSaved.value = Boolean(page.props.association?.bank_info?.id || hasBankInfoValues(form.bank_info))
            editingBankInfo.value = !bankInfoSaved.value
            showToast(page.props.flash?.success || tr('Configuración guardada.', 'Settings saved.'), 'success')
        },
        onError: (errors) => {
            showToast(firstErrorMessage(errors) || tr('No se pudo guardar la configuración.', 'Settings could not be saved.'), 'error')
        },
    })
}

const syncConcept = () => {
    syncForm.post(route('association.settings.sync-concept'), { preserveScroll: true })
}
</script>

<template>
    <PathfinderLayout>
        <template #title>{{ tr('Configuración', 'Settings') }}</template>

        <div class="space-y-6 max-w-2xl">

            <!-- Header -->
            <div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
                <h2 class="text-lg font-semibold text-gray-900">{{ association.name }}</h2>
                <p class="mt-1 text-sm text-gray-500">{{ tr('Parámetros globales de la asociación.', 'Global association parameters.') }}</p>
            </div>

            <!-- Success flash -->
            <div v-if="page.props.flash?.success" class="rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">
                {{ page.props.flash.success }}
            </div>

            <!-- Insurance settings -->
            <div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
                <h3 class="mb-4 text-sm font-semibold text-gray-700 uppercase tracking-wide">
                    {{ tr('Seguro de membresía', 'Membership insurance') }}
                </h3>
                <form @submit.prevent="submit" class="space-y-4">
                    <div class="max-w-xs">
                        <InputLabel :value="tr('Monto de pago por miembro (USD)', 'Payment amount per member (USD)')" />
                        <div class="relative mt-1">
                            <span class="pointer-events-none absolute inset-y-0 left-3 flex items-center text-gray-500 text-sm">$</span>
                            <input
                                v-model="form.insurance_payment_amount"
                                type="number"
                                min="0"
                                max="9999.99"
                                step="0.01"
                                class="block w-full rounded-md border-gray-300 pl-7 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500"
                                :placeholder="tr('Ej. 15.00', 'E.g. 15.00')"
                            />
                        </div>
                        <InputError class="mt-1" :message="form.errors.insurance_payment_amount" />
                        <p class="mt-1 text-xs text-gray-400">
                            {{ tr('Este monto se mostrará a todos los clubes al registrar el pago de seguro de cada miembro.', 'This amount will be shown to all clubs when recording each member\'s insurance payment.') }}
                        </p>
                    </div>

                    <div class="border-t border-gray-200 pt-5">
                        <h4 class="text-sm font-semibold text-gray-800">
                            {{ tr('Cuenta para depósitos de eventos', 'Event deposit account') }}
                        </h4>
                        <p class="mt-1 text-xs text-gray-500">
                            {{ tr('Estos datos se muestran a los clubes cuando registran depósitos de eventos hacia la asociación.', 'These details are shown to clubs when they register event deposits to the association.') }}
                        </p>

                        <div v-if="bankInfoSaved && !editingBankInfo" class="mt-4 rounded-xl border border-gray-200 bg-gray-50 p-4">
                            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                <div>
                                    <div class="text-sm font-semibold text-gray-900">
                                        {{ form.bank_info.label || tr('Cuenta de asociación', 'Association account') }}
                                    </div>
                                    <div class="mt-1 flex flex-wrap gap-2 text-xs">
                                        <span class="rounded-full px-2 py-0.5 font-medium" :class="form.bank_info.is_active ? 'bg-emerald-100 text-emerald-700' : 'bg-gray-200 text-gray-600'">
                                            {{ form.bank_info.is_active ? tr('Activa', 'Active') : tr('Inactiva', 'Inactive') }}
                                        </span>
                                        <span v-if="form.bank_info.accepts_event_deposits" class="rounded-full bg-blue-100 px-2 py-0.5 font-medium text-blue-700">
                                            {{ tr('Recibe eventos', 'Receives events') }}
                                        </span>
                                        <span v-if="form.bank_info.requires_receipt_upload" class="rounded-full bg-amber-100 px-2 py-0.5 font-medium text-amber-700">
                                            {{ tr('Pide comprobante', 'Requires proof') }}
                                        </span>
                                    </div>
                                </div>
                                <SecondaryButton type="button" @click="editingBankInfo = true">
                                    {{ tr('Editar cuenta', 'Edit account') }}
                                </SecondaryButton>
                            </div>

                            <dl class="mt-4 grid gap-3 text-sm md:grid-cols-2">
                                <div v-for="[label, value] in bankInfoRows" :key="label" class="rounded-lg bg-white px-3 py-2">
                                    <dt class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ label }}</dt>
                                    <dd class="mt-1 break-words text-gray-900">{{ value || '—' }}</dd>
                                </div>
                                <div class="rounded-lg bg-white px-3 py-2 md:col-span-2">
                                    <dt class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ tr('Instrucciones', 'Instructions') }}</dt>
                                    <dd class="mt-1 whitespace-pre-line text-gray-900">{{ form.bank_info.deposit_instructions || '—' }}</dd>
                                </div>
                            </dl>
                        </div>

                        <div v-else class="mt-4 grid gap-4 md:grid-cols-2">
                            <div>
                                <InputLabel :value="tr('Etiqueta', 'Label')" />
                                <input v-model="form.bank_info.label" type="text" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" />
                                <InputError class="mt-1" :message="form.errors['bank_info.label']" />
                            </div>
                            <div>
                                <InputLabel :value="tr('Banco', 'Bank')" />
                                <input v-model="form.bank_info.bank_name" type="text" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" />
                                <InputError class="mt-1" :message="form.errors['bank_info.bank_name']" />
                            </div>
                            <div>
                                <InputLabel :value="tr('Titular', 'Account holder')" />
                                <input v-model="form.bank_info.account_holder" type="text" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" />
                                <InputError class="mt-1" :message="form.errors['bank_info.account_holder']" />
                            </div>
                            <div>
                                <InputLabel :value="tr('Tipo de cuenta', 'Account type')" />
                                <input v-model="form.bank_info.account_type" type="text" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" />
                                <InputError class="mt-1" :message="form.errors['bank_info.account_type']" />
                            </div>
                            <div>
                                <InputLabel :value="tr('Número de cuenta', 'Account number')" />
                                <input v-model="form.bank_info.account_number" type="text" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" />
                                <InputError class="mt-1" :message="form.errors['bank_info.account_number']" />
                            </div>
                            <div>
                                <InputLabel :value="tr('Routing / ABA', 'Routing / ABA')" />
                                <input v-model="form.bank_info.routing_number" type="text" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" />
                                <InputError class="mt-1" :message="form.errors['bank_info.routing_number']" />
                            </div>
                            <div>
                                <InputLabel value="Zelle email" />
                                <input v-model="form.bank_info.zelle_email" type="email" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" />
                                <InputError class="mt-1" :message="form.errors['bank_info.zelle_email']" />
                            </div>
                            <div>
                                <InputLabel value="Zelle teléfono" />
                                <input v-model="form.bank_info.zelle_phone" type="text" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" />
                                <InputError class="mt-1" :message="form.errors['bank_info.zelle_phone']" />
                            </div>
                            <div class="md:col-span-2">
                                <InputLabel :value="tr('Instrucciones', 'Instructions')" />
                                <textarea v-model="form.bank_info.deposit_instructions" rows="3" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500"></textarea>
                                <InputError class="mt-1" :message="form.errors['bank_info.deposit_instructions']" />
	                            </div>
	                        </div>

                        <div v-if="!bankInfoSaved || editingBankInfo" class="mt-4 grid gap-3 text-sm md:grid-cols-3">
	                            <label class="inline-flex items-center gap-2 rounded-lg border border-gray-200 px-3 py-2">
	                                <input v-model="form.bank_info.is_active" type="checkbox" class="rounded border-gray-300 text-blue-600 focus:ring-blue-500" />
	                                <span>{{ tr('Activo', 'Active') }}</span>
                            </label>
                            <label class="inline-flex items-center gap-2 rounded-lg border border-gray-200 px-3 py-2">
                                <input v-model="form.bank_info.accepts_event_deposits" type="checkbox" class="rounded border-gray-300 text-blue-600 focus:ring-blue-500" />
                                <span>{{ tr('Recibe eventos', 'Receives events') }}</span>
                            </label>
                            <label class="inline-flex items-center gap-2 rounded-lg border border-gray-200 px-3 py-2">
                                <input v-model="form.bank_info.requires_receipt_upload" type="checkbox" class="rounded border-gray-300 text-blue-600 focus:ring-blue-500" />
	                                <span>{{ tr('Pide comprobante', 'Requires proof') }}</span>
	                            </label>
	                        </div>
                    </div>

                    <div class="flex flex-wrap items-center gap-3">
                        <PrimaryButton type="submit" :disabled="form.processing">
                            {{ tr('Guardar cambios', 'Save changes') }}
                        </PrimaryButton>
                        <SecondaryButton
                            type="button"
                            :disabled="syncForm.processing"
                            @click="syncConcept"
                        >
                            {{ tr('Sincronizar concepto', 'Sync concept') }}
                        </SecondaryButton>
                    </div>
                </form>
            </div>

        </div>
    </PathfinderLayout>
</template>
