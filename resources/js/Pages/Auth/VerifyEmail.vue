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
});

const form = useForm({});
const { tr } = useLocale();

const submit = () => {
    form.post(route('verification.send'));
};

const verificationLinkSent = computed(
    () => props.status === 'verification-link-sent',
);
</script>

<template>
    <GuestLayout>
        <Head :title="tr('Verificación de correo', 'Email verification')" />

        <div class="mb-4 text-sm text-gray-600">
            {{ tr('¡Gracias por registrarte! Antes de comenzar, verifica tu correo electrónico haciendo clic en el enlace que te enviamos. Si no recibiste el correo, con gusto enviaremos otro.', 'Thanks for registering. Before getting started, verify your email by clicking the link we sent you. If you did not receive it, we can send another one.') }}
        </div>

        <div
            class="mb-4 text-sm font-medium text-green-600"
            v-if="verificationLinkSent"
        >
            {{ tr('Se ha enviado un nuevo enlace de verificación al correo que proporcionaste durante el registro.', 'A new verification link has been sent to the email you provided during registration.') }}
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
