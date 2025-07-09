<script setup>
import { ref, computed, watch } from 'vue'
import { useForm, usePage } from '@inertiajs/vue3'
import { formatPhoneNumber, forceLogout } from '@/Helpers/general'

const page = usePage()
const auth_user = ref(page.props.auth_user || {})
const clubs = ref(page.props.clubs || [])
const sameAsHomeAddress = ref(false)

const showSuccess = ref(false)
const selectedClubId = ref(null)

const matchingClubs = computed(() =>
    clubs.value.filter(club => club.church_name === auth_user.value.church_name)
)

const form = useForm({
    club_id: '',
    club_name: '',
    director_name: '',
    church_name: '',

    applicant_name: '',
    birthdate: '',
    age: '',
    grade: '',
    mailing_address: '',
    cell_number: '',
    emergency_contact: '',

    investiture_classes: [],
    allergies: '',
    physical_restrictions: '',
    health_history: '',

    parent_name: '',
    parent_cell: '',
    home_address: '',
    email_address: '',
    signature: '',
})


watch(sameAsHomeAddress, (checked) => {
    if (checked) {
        form.mailing_address = form.home_address
    }
})

watch(() => form.home_address, (newVal) => {
    if (sameAsHomeAddress.value) {
        form.mailing_address = newVal
    }
})

watch(auth_user, (user) => {
    if (user) {
        form.parent_name = user.name || ''
        form.email_address = user.email || ''
        form.church_name = user.church_name || ''
        form.signature = user.name || ''
    }
}, { immediate: true })

watch(() => form.club_id, (id) => {
    const selected = matchingClubs.value.find(club => club.id === id)
    if (selected) {
        form.club_name = selected.club_name
        form.director_name = selected.director_name
        form.church_name = selected.church_name
    } else {
        form.club_name = ''
        form.director_name = ''
        form.church_name = ''
    }
})

watch(() => form.birthdate, (newDate) => {
    if (newDate) {
        const today = new Date()
        const birth = new Date(newDate)
        let age = today.getFullYear() - birth.getFullYear()
        const m = today.getMonth() - birth.getMonth()

        if (m < 0 || (m === 0 && today.getDate() < birth.getDate())) {
            age--
        }

        form.age = age
    } else {
        form.age = ''
    }
})

const submit = () => {
    form.post('/parent/apply', {
        preserveScroll: true,
        onSuccess: () => {
            showSuccess.value = true
            form.reset()
        },
        onError: (e) => {
            console.error('Validation failed', e)
        }
    })
}

function onCellNumberInput(event) {
    form.cell_number = formatPhoneNumber(event.target.value)
}

function onParentCellNumberInput(event) {
    form.parent_cell = formatPhoneNumber(event.target.value)
}
</script>
<template>
    <div class="p-6 max-w-3xl mx-auto">
        <h1 class="text-2xl font-bold mb-6">Adventurer Member Registration</h1>

        <div v-if="showSuccess" class="mb-4 text-green-700 bg-green-100 p-3 rounded">
            Member registered successfully!
        </div>

        <form @submit.prevent="submit" class="space-y-4">
            <!-- Club Selection -->
            <div>
                <label>Club Name</label>
                <select v-model="form.club_id" class="w-full p-2 border rounded">
                    <option disabled value="">-- Choose a club --</option>
                    <option v-for="club in matchingClubs" :key="club.id" :value="club.id">
                        {{ club.club_name }}
                    </option>
                </select>
            </div>
            <div>
                <label>Church Name</label>
                <input v-model="form.church_name" type="text" class="w-full p-2 border rounded" readonly />
            </div>
            <div>
                <label>Director Name</label>
                <input v-model="form.director_name" type="text" class="w-full p-2 border rounded" readonly />
            </div>
            <div>
                <label>Applicant Name</label>
                <input v-model="form.applicant_name" type="text" class="w-full p-2 border rounded" required />
            </div>

            <div class="flex gap-4">
                <div>
                    <label>Birthdate</label>
                    <input v-model="form.birthdate" type="date" class="w-full p-2 border rounded" required />
                </div>
                <div>
                    <label>Age</label>
                    <input v-model="form.age" type="number" class="w-full p-2 border rounded" />
                </div>
                <div>
                    <label>Grade</label>
                    <input v-model="form.grade" type="text" class="w-full p-2 border rounded" />
                </div>
            </div>
            <div>
                <label>Cell Number</label>
                <input :value="form.cell_number" @input="onCellNumberInput" type="text"
                    class="w-full p-2 border rounded" placeholder="(123) 456 7890" />
            </div>

            <div>
                <label>Emergency Contact</label>
                <input v-model="form.emergency_contact" type="text" class="w-full p-2 border rounded" />
            </div>

            <div>
                <label class="block mb-1">Investiture Class</label>
                <div class="flex flex-wrap gap-2">
                    <label
                        v-for="level in ['Little Lambs', 'Eager Beavers', 'Busy Bee', 'Sunbeam', 'Builder', 'Helping Hand']"
                        :key="level" class="inline-flex items-center">
                        <input type="checkbox" :value="level" v-model="form.investiture_classes" class="mr-2" />
                        {{ level }}
                    </label>
                </div>
            </div>

            <div>
                <label>Allergies</label>
                <textarea v-model="form.allergies" class="w-full p-2 border rounded"></textarea>
            </div>

            <div>
                <label>Physical Restrictions</label>
                <textarea v-model="form.physical_restrictions" class="w-full p-2 border rounded"></textarea>
            </div>

            <div>
                <label>Health History</label>
                <textarea v-model="form.health_history" class="w-full p-2 border rounded"></textarea>
            </div>

            <div>
                <label>Parent Name</label>
                <input v-model="form.parent_name" type="text" class="w-full p-2 border rounded" required />
            </div>

            <div>
                <label>Parent Cell</label>
                <input :value="form.parent_cell" @input="onParentCellNumberInput" type="text"
                    class="w-full p-2 border rounded" placeholder="(123) 456 7890" />
            </div>

            <div>
                <label>Home Address</label>
                <input v-model="form.home_address" type="text" class="w-full p-2 border rounded" />
            </div>
            <div>
                <label>Mailing Address</label>
                <input v-model="form.mailing_address" type="text" class="w-full p-2 border rounded" />
            </div>
            <div class="flex items-center mb-2">
                <input id="same-address" type="checkbox" v-model="sameAsHomeAddress" class="mr-2" />
                <label for="same-address">Same as home address</label>
            </div>
            <div>
                <label>Email Address</label>
                <input v-model="form.email_address" type="email" class="w-full p-2 border rounded" />
            </div>

            <div>
                <label>Signature (Typed)</label>
                <input v-model="form.signature" type="text" class="w-full p-2 border rounded" />
            </div>

            <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">
                Submit Registration
            </button>&nbsp;&nbsp;
            <button type="button" class="bg-red-600 text-white px-4 py-2 rounded hover:bg-red-700" @click="forceLogout">
                Logout
            </button>
        </form>
    </div>
</template>
