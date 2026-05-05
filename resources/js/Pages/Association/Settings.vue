<script setup>
import PathfinderLayout from '@/Layouts/PathfinderLayout.vue'
import InputLabel from '@/Components/InputLabel.vue'
import InputError from '@/Components/InputError.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'
import { useForm, usePage } from '@inertiajs/vue3'
import { useLocale } from '@/Composables/useLocale'

const props = defineProps({
    association: { type: Object, required: true },
})

const { tr } = useLocale()
const page = usePage()

const form = useForm({
    insurance_payment_amount: props.association.insurance_payment_amount ?? '',
})

const syncForm = useForm({})

const submit = () => {
    form.patch(route('association.settings.update'), { preserveScroll: true })
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
