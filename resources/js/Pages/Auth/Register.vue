<script setup>
import InputError from "@/Components/InputError.vue";
import InputLabel from "@/Components/InputLabel.vue";
import PrimaryButton from "@/Components/PrimaryButton.vue";
import TextInput from "@/Components/TextInput.vue";
import PathfinderLayout from "@/Layouts/AuthLayout.vue";
import { useLocale } from "@/Composables/useLocale";
import { Head, Link, useForm } from "@inertiajs/vue3";
import { defineProps, computed, watch } from "vue";

const props = defineProps({
    churches: Array,
    clubs: Array,
    subRoles: Array,
});

const form = useForm({
    name: "",
    email: "",
    password: "",
    password_confirmation: "",
    profile_type: "",
    sub_role: "",
    church_id: '',
    church_name: '',
    club_id: '',
    invite_code: ''
});
const { tr } = useLocale();

const submit = () => {
    form.post(route("register"), {
        onFinish: () => form.reset("password", "password_confirmation"),
    });
};

const filteredClubs = computed(() => {
    if (!form.church_id) return []
    return props.clubs.filter(c => Number(c.church_id) === Number(form.church_id))
})

const profileTypeOptions = computed(() => {
    const hasDirector = props.clubs.some(c => Number(c.id) === Number(form.club_id) && c.director_exists)
    return hasDirector
        ? [{ value: 'club_personal', label: tr('Personal del club', 'Club staff') }]
        : [
            { value: 'club_director', label: tr('Director de club', 'Club director') },
            { value: 'club_personal', label: tr('Personal del club', 'Club staff') },
        ]
})

watch(
    () => form.church_id,
    (newId) => {
        const selected = props.churches.find(church => church.id === Number(newId))
        form.church_name = selected ? selected.church_name : ''
        const opts = profileTypeOptions.value
        if (opts.length === 1) {
            form.profile_type = opts[0].value
        } else if (!opts.find(o => o.value === form.profile_type)) {
            form.profile_type = ''
        }
        form.club_id = ''
    },
    { immediate: true }
)
</script>

<template>
    <PathfinderLayout>

        <Head :title="tr('Registro', 'Registration')" />

        <template #title>{{ tr('Únete al Portal de Conquistadores', 'Join the Pathfinder Portal') }}</template>

        <form @submit.prevent="submit" class="space-y-6">
            <div>
                <InputLabel for="church_name" :value="tr('Iglesia', 'Church')" />
                <select id="church_id" v-model="form.church_id"
                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:ring-red-600 focus:border-red-600"
                    required>
                    <option disabled value="">{{ tr('Selecciona una iglesia', 'Select a church') }}</option>
                    <option v-for="church in churches" :key="church.id" :value="church.id">
                        {{ church.church_name }}
                    </option>
                </select>
                <InputError class="mt-2" :message="form.errors.church_id" />
            </div>
            <div>
                <InputLabel for="club_id" :value="tr('Club', 'Club')" />
                <select id="club_id" v-model="form.club_id"
                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:ring-red-600 focus:border-red-600"
                    required>
                    <option disabled value="">{{ tr('Selecciona un club', 'Select a club') }}</option>
                    <option v-for="club in filteredClubs" :key="club.id" :value="club.id">
                        {{ club.club_name }}
                    </option>
                    <option value="new">{{ tr('Crear nuevo club (misma iglesia)', 'Create new club (same church)') }}</option>
                </select>
                <InputError class="mt-2" :message="form.errors.club_id" />
            </div>
            <div>
                <label for="profile_type" class="block text-sm font-medium text-gray-700">{{ tr('Tipo de perfil', 'Profile type') }}</label>
                <select v-model="form.profile_type" id="profile_type"
                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:ring-yellow-500 focus:border-yellow-500"
                    required>
                    <option value="">{{ tr('Selecciona un rol', 'Select a role') }}</option>
                    <option v-for="opt in profileTypeOptions" :key="opt.value" :value="opt.value">
                        {{ opt.label }}
                    </option>
                </select>
                <InputError class="mt-2" :message="form.errors.profile_type" />
                <p v-if="profileTypeOptions.length === 1 && profileTypeOptions[0].value === 'club_personal'"
                    class="text-xs text-gray-600 mt-1">
                    {{ tr('Ya existe un director para este club. Solo el personal puede registrarse.', 'This club already has a director. Only staff can register.') }}
                </p>
            </div>

            <div v-if="form.profile_type === 'club_personal'">
                <label for="sub_role" class="block text-sm font-medium text-gray-700">{{ tr('Subrol', 'Sub-role') }}</label>
                <select v-model="form.sub_role"
                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:ring-yellow-500 focus:border-yellow-500">
                    <option value="">{{ tr('Selecciona un subrol', 'Select a sub-role') }}</option>
                    <option v-for="role in subRoles" :key="role.id" :value="role.key">
                        {{ role.label }}
                    </option>
                </select>
                <InputError class="mt-2" :message="form.errors.sub_role" />
            </div>



            <div>
                <InputLabel for="invite_code" :value="tr('Código de invitación de la iglesia', 'Church invitation code')" />
                <TextInput id="invite_code" type="text"
                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:ring-red-600 focus:border-red-600"
                    v-model="form.invite_code" required />
                <InputError class="mt-2" :message="form.errors.invite_code" />
            </div>
            <div>
                <InputLabel for="name" :value="tr('Nombre', 'Name')" />
                <TextInput id="name" type="text"
                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:ring-red-600 focus:border-red-600"
                    v-model="form.name" required autofocus autocomplete="name" />
                <InputError class="mt-2" :message="form.errors.name" />
            </div>

            <div>
                <InputLabel for="email" :value="tr('Correo electrónico', 'Email')" />
                <TextInput id="email" type="email"
                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:ring-red-600 focus:border-red-600"
                    v-model="form.email" required autocomplete="username" />
                <InputError class="mt-2" :message="form.errors.email" />
            </div>

            <div>
                <InputLabel for="password" :value="tr('Contraseña', 'Password')" />
                <TextInput id="password" type="password"
                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:ring-red-600 focus:border-red-600"
                    v-model="form.password" required autocomplete="new-password" />
                <InputError class="mt-2" :message="form.errors.password" />
            </div>

            <div>
                <InputLabel for="password_confirmation" :value="tr('Confirmar contraseña', 'Confirm password')" />
                <TextInput id="password_confirmation" type="password"
                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:ring-red-600 focus:border-red-600"
                    v-model="form.password_confirmation" required autocomplete="new-password" />
                <InputError class="mt-2" :message="form.errors.password_confirmation" />
            </div>




            <div class="flex items-center justify-between pt-2">
                <span v-if="churches.length == 0" class="text-sm text-gray-500">
                    {{ tr('No hay iglesias registradas. Contacta a un superadmin.', 'There are no registered churches. Contact a superadmin.') }}
                </span>

                <Link :href="route('login')" class="text-sm text-yellow-600 hover:underline">
                {{ tr('¿Ya estás registrado?', 'Already registered?') }}
                </Link>

                <PrimaryButton class="bg-red-600 hover:bg-red-700 text-white px-6 py-2 rounded-md"
                    :class="{ 'opacity-25': form.processing }" :disabled="form.processing">
                    {{ tr('Registrarse', 'Register') }}
                </PrimaryButton>
            </div>
        </form>
    </PathfinderLayout>
</template>
