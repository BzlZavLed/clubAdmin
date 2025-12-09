<script setup>
import InputError from "@/Components/InputError.vue";
import InputLabel from "@/Components/InputLabel.vue";
import PrimaryButton from "@/Components/PrimaryButton.vue";
import TextInput from "@/Components/TextInput.vue";
import PathfinderLayout from "@/Layouts/AuthLayout.vue";
import { Head, Link, useForm } from "@inertiajs/vue3";
import { defineProps,computed, watch } from "vue";

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
        ? [{ value: 'club_personal', label: 'Club Personal' }]
        : [
            { value: 'club_director', label: 'Club Director' },
            { value: 'club_personal', label: 'Club Personal' },
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

    <Head title="Register" />

    <template #title>Join the Pathfinder Portal</template>

    <form @submit.prevent="submit" class="space-y-6">
        <div>
            <InputLabel for="church_name" value="Church" />
            <select id="church_id" v-model="form.church_id" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:ring-red-600 focus:border-red-600" required>
                <option disabled value="">Select a church</option>
                <option v-for="church in churches" :key="church.id" :value="church.id">
                    {{ church.church_name }}
                </option>
            </select>
            <InputError class="mt-2" :message="form.errors.church_id" />
        </div>

        <div>
            <InputLabel for="name" value="Name" />
            <TextInput id="name" type="text" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:ring-red-600 focus:border-red-600" v-model="form.name" required autofocus autocomplete="name" />
            <InputError class="mt-2" :message="form.errors.name" />
        </div>

        <div>
            <InputLabel for="email" value="Email" />
            <TextInput id="email" type="email" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:ring-red-600 focus:border-red-600" v-model="form.email" required autocomplete="username" />
            <InputError class="mt-2" :message="form.errors.email" />
        </div>

        <div>
            <InputLabel for="password" value="Password" />
            <TextInput id="password" type="password" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:ring-red-600 focus:border-red-600" v-model="form.password" required autocomplete="new-password" />
            <InputError class="mt-2" :message="form.errors.password" />
        </div>

        <div>
            <InputLabel for="password_confirmation" value="Confirm Password" />
            <TextInput id="password_confirmation" type="password" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:ring-red-600 focus:border-red-600" v-model="form.password_confirmation" required autocomplete="new-password" />
            <InputError class="mt-2" :message="form.errors.password_confirmation" />
        </div>

        <div>
            <label for="profile_type" class="block text-sm font-medium text-gray-700">Profile Type</label>
            <select v-model="form.profile_type" id="profile_type" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:ring-yellow-500 focus:border-yellow-500" required>
                <option value="">Select Role</option>
                <option v-for="opt in profileTypeOptions" :key="opt.value" :value="opt.value">
                    {{ opt.label }}
                </option>
            </select>
            <InputError class="mt-2" :message="form.errors.profile_type" />
            <p v-if="profileTypeOptions.length === 1 && profileTypeOptions[0].value === 'club_personal'" class="text-xs text-gray-600 mt-1">
                A club director already exists for this club. Only staff can register.
            </p>
        </div>

        <div v-if="form.profile_type === 'club_personal'">
            <label for="sub_role" class="block text-sm font-medium text-gray-700">Sub Role</label>
            <select v-model="form.sub_role" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:ring-yellow-500 focus:border-yellow-500">
                <option value="">Select Sub Role</option>
                <option v-for="role in subRoles" :key="role.id" :value="role.key">
                    {{ role.label }}
                </option>
            </select>
            <InputError class="mt-2" :message="form.errors.sub_role" />
        </div>

        <div>
            <InputLabel for="club_id" value="Club" />
            <select id="club_id" v-model="form.club_id" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:ring-red-600 focus:border-red-600" required>
                <option disabled value="">Select a club</option>
                <option v-for="club in filteredClubs" :key="club.id" :value="club.id">
                    {{ club.club_name }}
                </option>
                <option value="new">Create new club (same church)</option>
            </select>
            <InputError class="mt-2" :message="form.errors.club_id" />
        </div>

        <div>
            <InputLabel for="invite_code" value="Church invite code" />
            <TextInput id="invite_code" type="text" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:ring-red-600 focus:border-red-600" v-model="form.invite_code" required />
            <InputError class="mt-2" :message="form.errors.invite_code" />
        </div>

        <div class="flex items-center justify-between pt-2">
            <Link :href="route('church.form')" class="text-sm text-yellow-600 hover:underline" v-if="churches.length == 0">
                Create new church
            </Link>

            <Link :href="route('login')" class="text-sm text-yellow-600 hover:underline">
                Already registered?
            </Link>

            <PrimaryButton class="bg-red-600 hover:bg-red-700 text-white px-6 py-2 rounded-md" :class="{ 'opacity-25': form.processing }" :disabled="form.processing">
                Register
            </PrimaryButton>
        </div>
    </form>
</PathfinderLayout>
</template>
