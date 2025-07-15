<template>
    <div class="p-4 max-w-lg mx-auto">
        <form @submit.prevent="submitChurch">
            <div class="mb-4">
                <label class="block mb-1">Church Name *</label>
                <input v-model="form.church_name" type="text" class="border p-2 w-full" required />
            </div>
            <div class="mb-4">
                <label class="block mb-1">Address</label>
                <input v-model="form.address" type="text" class="border p-2 w-full" />
            </div>
            <div class="mb-4">
                <label class="block mb-1">Ethnicity</label>
                <input v-model="form.ethnicity" type="text" class="border p-2 w-full" />
            </div>
            <div class="mb-4">
                <label class="block mb-1">Phone Number</label>
                <input v-model="form.phone_number" type="text" class="border p-2 w-full" />
            </div>
            <div class="mb-4">
                <label class="block mb-1">Email</label>
                <input v-model="form.email" type="email" class="border p-2 w-full" />
            </div>
            <div class="mb-4">
                <label class="block mb-1">Pastor name</label>
                <input v-model="form.pastor_name" type="text" class="border p-2 w-full" />
            </div>
            <div class="mb-4">
                <label class="block mb-1">Pastor email</label>
                <input v-model="form.pastor_email" type="email" class="border p-2 w-full" />
            </div>
            <div class="mb-4">
                <label class="block mb-1">Conference</label>
                <input v-model="form.conference" type="text" class="border p-2 w-full" />
            </div>
            <Link :href="route('register')" class="text-sm text-yellow-600 hover:underline">
            Register staff
            </Link><br>

            <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded">Submit</button>

        </form>
    </div>
</template>
<script setup>
import { reactive } from 'vue'
import axios from 'axios'
import { Link } from '@inertiajs/vue3'

const form = reactive({
    church_name: '',
    address: '',
    ethnicity: '',
    phone_number: '',
    email: '',
    pastor_name: '',
    pastor_email: '',
    conference: ''
})

const submitChurch = async () => {
    try {
        await axios.post('/churches', form)
        alert('Church created successfully!')

        // Reset form fields
        form.church_name = ''
        form.address = ''
        form.ethnicity = ''
        form.phone_number = ''
        form.email = ''
        form.pastor_name = ''
        form.pastor_email = ''
        form.conference = ''
    } catch (err) {
        alert('Error creating church. Please check the form.')
        console.error(err)
    }
}
</script>
