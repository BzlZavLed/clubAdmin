<script setup>
import { ref } from 'vue'
import Checkbox from '@/Components/Checkbox.vue'
import InputError from '@/Components/InputError.vue'
import InputLabel from '@/Components/InputLabel.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import TextInput from '@/Components/TextInput.vue'
import PathfinderLayout from '@/Layouts/AuthLayout.vue'
import { useLocale } from '@/Composables/useLocale'
import { Head, Link, useForm } from '@inertiajs/vue3'
import { EyeIcon, EyeSlashIcon } from '@heroicons/vue/24/outline'

defineProps({
    canResetPassword: {
        type: Boolean,
    },
    status: {
        type: String,
    },
})

const form = useForm({
    email: '',
    password: '',
    remember: false,
})
const { tr } = useLocale()
const showPassword = ref(false)

const submit = () => {
    form.post(route('login'), {
        onFinish: () => form.reset('password'),
    })
}
</script>

<template>
    <PathfinderLayout>

        <Head :title="tr('Iniciar sesión', 'Log in')" />

        <template #title>{{ tr('Iniciar sesión en el Portal de Clubes', 'Log in to the Club Portal') }}</template>

        <div v-if="status" class="mb-4 text-sm font-medium text-green-600">
            {{ status }}
        </div>

        <form @submit.prevent="submit" class="space-y-6">
            <div>
                <InputLabel for="email" :value="tr('Correo electrónico', 'Email')" />
                <TextInput id="email" type="email"
                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:ring-red-600 focus:border-red-600"
                    v-model="form.email" required autofocus autocomplete="username" />
                <InputError class="mt-2" :message="form.errors.email" />
            </div>

            <div>
                <InputLabel for="password" :value="tr('Contraseña', 'Password')" />
                <div class="relative mt-1">
                    <TextInput
                        id="password"
                        :type="showPassword ? 'text' : 'password'"
                        class="block w-full rounded-md border-gray-300 pr-11 shadow-sm focus:ring-red-600 focus:border-red-600"
                        v-model="form.password"
                        required
                        autocomplete="current-password"
                    />
                    <button
                        type="button"
                        class="absolute inset-y-0 right-0 flex w-11 items-center justify-center text-gray-500 hover:text-gray-700 focus:outline-none focus:ring-2 focus:ring-red-200"
                        :aria-label="showPassword ? tr('Ocultar contraseña', 'Hide password') : tr('Mostrar contraseña', 'Show password')"
                        :aria-pressed="showPassword"
                        @click="showPassword = !showPassword"
                    >
                        <EyeSlashIcon v-if="showPassword" class="h-5 w-5" />
                        <EyeIcon v-else class="h-5 w-5" />
                    </button>
                </div>
                <InputError class="mt-2" :message="form.errors.password" />
            </div>

            <div class="block">
                <label class="flex items-center">
                    <Checkbox name="remember" v-model:checked="form.remember" />
                    <span class="ms-2 text-sm text-gray-600">{{ tr('Recordarme', 'Remember me') }}</span>
                </label>
            </div>

            <div class="flex items-center justify-between pt-2">
                <Link v-if="canResetPassword" :href="route('password.request')"
                    class="text-sm text-yellow-600 hover:underline">
                {{ tr('¿Olvidaste tu contraseña?', 'Forgot your password?') }}
                </Link>

                <PrimaryButton class="bg-red-600 hover:bg-red-700 text-white px-6 py-2 rounded-md"
                    :class="{ 'opacity-25': form.processing }" :disabled="form.processing">
                    {{ tr('Iniciar sesión', 'Log in') }}
                </PrimaryButton>
            </div>
        </form>

        <div class="mt-6 text-sm text-gray-700 space-y-1">
            <div>
                <Link href="/register" class="text-blue-600 hover:underline">{{ tr('Crear una cuenta', 'Create an account') }}</Link>
                <span class="text-gray-500"> {{ tr('(director, personal o padre/madre)', '(director, staff, or parent)') }}</span>
            </div>
        </div>
    </PathfinderLayout>
</template>
