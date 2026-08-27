<script setup>
import { Link } from '@inertiajs/vue3'

const props = defineProps({
    mode: {
        type: String,
        default: 'login',
    },
    modelValue: {
        type: Boolean,
        default: false,
    },
    error: {
        type: String,
        default: '',
    },
})

const emit = defineEmits(['update:modelValue'])
</script>

<template>
    <div class="rounded-lg border border-slate-200 bg-slate-50 p-4 text-xs leading-5 text-slate-700">
        <template v-if="props.mode === 'consent'">
            <label class="flex items-start gap-3">
                <input
                    type="checkbox"
                    name="privacy_consent"
                    :checked="props.modelValue"
                    required
                    class="mt-1 rounded border-slate-300 text-blue-600 focus:ring-blue-500"
                    @change="emit('update:modelValue', $event.target.checked)"
                />
                <span>
                    <span class="block font-semibold text-slate-900">Consentimiento de privacidad / Privacy consent</span>
                    <span class="mt-1 block">
                        He leído el
                        <Link :href="route('privacy')" target="_blank" class="font-semibold text-blue-700 underline">Aviso de Privacidad</Link>
                        y autorizo el tratamiento de los datos que decida proporcionar para administrar la cuenta, inscripciones, seguridad, comunicaciones, pagos y actividades del club. Entiendo que ciertos datos son necesarios para prestar el servicio y que puedo solicitar acceso, corrección o eliminación, sujeto a las excepciones legales y obligaciones de conservación aplicables. Si proporciono datos de un menor, confirmo que soy su padre, madre o tutor legal, o que tengo autorización para hacerlo.
                    </span>
                    <span class="mt-2 block border-t border-slate-200 pt-2">
                        I have read the
                        <Link :href="route('privacy')" target="_blank" class="font-semibold text-blue-700 underline">Privacy Notice</Link>
                        and authorize the processing of information I choose to provide for account administration, enrollment, safety, communications, payments, and club activities. I understand that some information is required to provide the service and that I may request access, correction, or deletion, subject to applicable legal exceptions and retention obligations. If I provide information about a minor, I confirm that I am the minor's parent or legal guardian, or otherwise authorized to provide it.
                    </span>
                </span>
            </label>
            <p v-if="props.error" class="mt-2 font-medium text-red-600">{{ props.error }}</p>
        </template>

        <template v-else>
            <p>
                Al iniciar sesión, reconoces que el portal procesa datos personales según el
                <Link :href="route('privacy')" target="_blank" class="font-semibold text-blue-700 underline">Aviso de Privacidad</Link>.
                Puedes solicitar acceso, corrección o eliminación, sujeto a las excepciones aplicables.
            </p>
            <p class="mt-2 border-t border-slate-200 pt-2">
                By logging in, you acknowledge that the portal processes personal information as described in the
                <Link :href="route('privacy')" target="_blank" class="font-semibold text-blue-700 underline">Privacy Notice</Link>.
                You may request access, correction, or deletion, subject to applicable exceptions.
            </p>
        </template>
    </div>
</template>
