<script setup>
import { computed } from 'vue';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import { useLocale } from '@/Composables/useLocale';
import { Head, Link, useForm } from '@inertiajs/vue3';

const props = defineProps({
    status: {
        type: String,
    },
    email: String,
    is_parent: Boolean,
});

const form = useForm({});
const { tr } = useLocale();

const submit = () => {
    form.post(route('verification.send'));
};

const verificationLinkSent = computed(
    () => props.status === 'verification-link-sent',
);
const verificationDeliveryFailed = computed(
    () => props.status === 'verification-delivery-failed',
);
</script>

<template>
    <GuestLayout>
        <Head :title="tr('Verificación de correo', 'Email verification')" />

        <div class="mb-4 text-sm text-gray-600">
            {{ tr('¡Gracias por registrarte! Antes de comenzar, verifica tu correo electrónico haciendo clic en el enlace que te enviamos. Si no recibiste el correo, con gusto enviaremos otro.', 'Thanks for registering. Before getting started, verify your email by clicking the link we sent you. If you did not receive it, we can send another one.') }}
        </div>

        <div v-if="is_parent && email" class="mb-4 rounded-md bg-gray-50 px-3 py-2 text-sm font-medium text-gray-800">
            {{ email }}
        </div>

        <div
            class="mb-4 text-sm font-medium text-green-600"
            v-if="verificationLinkSent"
        >
            {{ tr('Se ha enviado un nuevo enlace de verificación al correo que proporcionaste durante el registro.', 'A new verification link has been sent to the email you provided during registration.') }}
        </div>

        <div v-if="verificationDeliveryFailed || form.errors.email" class="mb-4 rounded-md border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
            {{ form.errors.email || tr('No se pudo enviar el correo de verificación. Intenta de nuevo o solicita al director del club que active tu cuenta.', 'The verification email could not be sent. Try again or ask the club director to activate your account.') }}
        </div>

        <div v-if="is_parent" class="mb-4 text-sm text-gray-600">
            {{ tr('Permanecerás en esta pantalla hasta confirmar el correo. Como alternativa, el director del club puede activar la cuenta.', 'You will remain on this screen until the email is confirmed. As a fallback, the club director can activate the account.') }}
        </div>

        <form @submit.prevent="submit">
            <div class="mt-4 flex items-center justify-between">
                <PrimaryButton
                    :class="{ 'opacity-25': form.processing }"
                    :disabled="form.processing"
                >
                    {{ tr('Reenviar correo de verificación', 'Resend verification email') }}
                </PrimaryButton>

                <Link
                    :href="route('logout')"
                    method="post"
                    as="button"
                    class="rounded-md text-sm text-gray-600 underline hover:text-gray-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2"
                    >{{ tr('Cerrar sesión', 'Log out') }}</Link
                >
            </div>
        </form>
    </GuestLayout>
</template>
