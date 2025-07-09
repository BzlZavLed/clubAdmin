<script setup>
import Checkbox from '@/Components/Checkbox.vue'
import InputError from '@/Components/InputError.vue'
import InputLabel from '@/Components/InputLabel.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import TextInput from '@/Components/TextInput.vue'
import PathfinderLayout from '@/Layouts/AuthLayout.vue'
import { Head, Link, useForm } from '@inertiajs/vue3'

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

const submit = () => {
    form.post(route('login'), {
        onFinish: () => form.reset('password'),
    })
}
</script>

<template>
    <PathfinderLayout>

        <Head title="Log in" />

        <template #title>Sign In to Pathfinder Portal</template>

        <div v-if="status" class="mb-4 text-sm font-medium text-green-600">
            {{ status }}
        </div>

        <form @submit.prevent="submit" class="space-y-6">
            <div>
                <InputLabel for="email" value="Email" />
                <TextInput id="email" type="email"
                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:ring-red-600 focus:border-red-600"
                    v-model="form.email" required autofocus autocomplete="username" />
                <InputError class="mt-2" :message="form.errors.email" />
            </div>

            <div>
                <InputLabel for="password" value="Password" />
                <TextInput id="password" type="password"
                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:ring-red-600 focus:border-red-600"
                    v-model="form.password" required autocomplete="current-password" />
                <InputError class="mt-2" :message="form.errors.password" />
            </div>

            <div class="block">
                <label class="flex items-center">
                    <Checkbox name="remember" :checked="form.remember" @change="form.remember = $event.target.checked" />
                    <span class="ms-2 text-sm text-gray-600">Remember me</span>
                </label>
            </div>

            <div class="flex items-center justify-between pt-2">
                <Link v-if="canResetPassword" :href="route('password.request')"
                    class="text-sm text-yellow-600 hover:underline">
                Forgot your password?
                </Link>

                <PrimaryButton class="bg-red-600 hover:bg-red-700 text-white px-6 py-2 rounded-md"
                    :class="{ 'opacity-25': form.processing }" :disabled="form.processing">
                    Log in
                </PrimaryButton>
            </div>
        </form>
    </PathfinderLayout>
</template>
