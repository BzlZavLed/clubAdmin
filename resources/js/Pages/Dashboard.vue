<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, useForm } from '@inertiajs/vue3';

const form = useForm({
    current_password: '',
    password: '',
    password_confirmation: '',
});

const updatePassword = () => {
    form.put(route('password.update'), {
        preserveScroll: true,
        onSuccess: () => form.reset('current_password', 'password', 'password_confirmation'),
        onError: () => form.reset('password', 'password_confirmation'),
    });
};
</script>

<template>
    <Head title="Panel" />

    <AuthenticatedLayout>
        <template #header>
            <h2
                class="text-xl font-semibold leading-tight text-gray-800"
            >
                Panel
            </h2>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">
                <div
                    class="overflow-hidden bg-white shadow-sm sm:rounded-lg"
                >
                    <div class="p-6 text-gray-900">
                        <p class="mb-6">¡Has iniciado sesión!</p>

                        <div class="max-w-xl">
                            <h3 class="text-lg font-semibold text-gray-900">Actualizar contraseña</h3>
                            <p class="mt-1 text-sm text-gray-600">
                                Si recibiste una contraseña temporal, cámbiala aquí.
                            </p>

                            <form class="mt-4 space-y-4" @submit.prevent="updatePassword">
                                <div>
                                    <InputLabel for="current_password" value="Contraseña actual" />
                                    <TextInput
                                        id="current_password"
                                        v-model="form.current_password"
                                        type="password"
                                        class="mt-1 block w-full"
                                        required
                                        autocomplete="current-password"
                                    />
                                    <InputError class="mt-2" :message="form.errors.current_password" />
                                </div>

                                <div>
                                    <InputLabel for="password" value="Nueva contraseña" />
                                    <TextInput
                                        id="password"
                                        v-model="form.password"
                                        type="password"
                                        class="mt-1 block w-full"
                                        required
                                        autocomplete="new-password"
                                    />
                                    <InputError class="mt-2" :message="form.errors.password" />
                                </div>

                                <div>
                                    <InputLabel for="password_confirmation" value="Confirmar nueva contraseña" />
                                    <TextInput
                                        id="password_confirmation"
                                        v-model="form.password_confirmation"
                                        type="password"
                                        class="mt-1 block w-full"
                                        required
                                        autocomplete="new-password"
                                    />
                                    <InputError class="mt-2" :message="form.errors.password_confirmation" />
                                </div>

                                <div class="flex items-center gap-3">
                                    <PrimaryButton :disabled="form.processing">
                                        Guardar contraseña
                                    </PrimaryButton>
                                    <p v-if="form.recentlySuccessful" class="text-sm text-green-700">
                                        Contraseña actualizada.
                                    </p>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
