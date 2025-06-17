<script setup>
import PathfinderLayout from '@/Layouts/PathfinderLayout.vue'
import { computed, ref, onMounted } from 'vue'
import { useForm, usePage } from '@inertiajs/vue3'
import axios from 'axios'

const isEditing = ref(false)
const editingClubId = ref(null)
const clubs = ref([])

const page = usePage()
const props = defineProps(['auth_user'])
const hasClub = ref(false)
const clubIds = computed(() => page.props?.auth?.user_club_ids ?? [])
const user = computed(() => page.props?.auth?.user ?? [])
const flash = computed(() => page.props.flash || {})
const fetchClubs = () => {
    const club_id = user.value?.club_id 
    hasClub.value = club_id !== null && club_id !== undefined
    if(hasClub){
        clubs.value = user.value?.clubs;
    }
}

const clubForm = useForm({
    church_id: user.value.church_id,
    club_name: '',
    church_name: user.value.church_name,
    director_name: props.auth_user.name,
    creation_date: '',
    pastor_name: '',
    conference_name: '',
    conference_region: '',
    club_type: '',
})

const submitClub = () => {
    clubForm.post('/club', {
        preserveScroll: true,
        onSuccess: () => {
            alert('Club created successfully!')

        },
        onError: (errors) => {
            console.error(errors)
        }
    })
}

const editClub = (club) => {
    isEditing.value = true
    editingClubId.value = club.id

    clubForm.reset()

    clubForm.club_name = club.club_name
    clubForm.church_name = club.church_name
    clubForm.director_name = club.director_name
    clubForm.creation_date = club.creation_date
    clubForm.pastor_name = club.pastor_name
    clubForm.conference_name = club.conference_name
    clubForm.conference_region = club.conference_region
    clubForm.club_type = club.club_type
}
const updateClub = () => {
    clubForm.put(route('club.update'), {
        preserveScroll: true,
        onSuccess: () => {
            alert('Club updated successfully!')
            isEditing.value = false
            editingClubId.value = null
            fetchClubs();
        },
        onError: (errors) => {
            console.error(errors)
        }
    })
}
const deleteClub = async (clubId) => {
    console.log(clubId);
    if (!confirm('Are you sure you want to delete this club?')) return;

    try {
        await axios.delete('/club', {
            data: { id: clubId }
        })

        alert('Club deleted successfully!')
        fetchClubs() // Reload updated club list
    } catch (error) {
        console.error('Failed to delete club:', error)
    }
}
onMounted(fetchClubs)
</script>

<template>
<PathfinderLayout>
    <template #title>My Club</template>

    <div v-if="!hasClub || isEditing" class="space-y-6">
        <p class="text-gray-700">
            {{ isEditing ? 'Edit your club below:' : 'No club assigned. Please create your club below.' }}
        </p>

        <form class="space-y-4" @submit.prevent="isEditing ? updateClub() : submitClub()">
            <p v-if="clubForm.errors.club_name" class="text-sm text-red-600 mt-1">
                {{ clubForm.errors.club_name }}
            </p>

            <p v-if="flash.success" class="text-green-600 font-semibold mb-4">
                {{ flash.success }}
            </p>

            <div>
                <label class="block text-sm font-medium text-gray-700">Club Name</label>
                <input v-model="clubForm.club_name" type="text" class="w-full mt-1 p-2 border rounded" />
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700">Church Name</label>
                <input v-model="clubForm.church_name" type="text" class="w-full mt-1 p-2 border rounded" />
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700">Director Name</label>
                <input v-model="clubForm.director_name" type="text" class="w-full mt-1 p-2 border rounded" readonly />
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700">Date of Creation</label>
                <input v-model="clubForm.creation_date" type="date" class="w-full mt-1 p-2 border rounded" />
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700">Pastor Name</label>
                <input v-model="clubForm.pastor_name" type="text" class="w-full mt-1 p-2 border rounded" />
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700">Conference Name</label>
                <input v-model="clubForm.conference_name" type="text" class="w-full mt-1 p-2 border rounded" />
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700">Conference Region</label>
                <input v-model="clubForm.conference_region" type="text" class="w-full mt-1 p-2 border rounded" />
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700">Club Type</label>
                <select v-model="clubForm.club_type" class="w-full mt-1 p-2 border rounded">
                    <option value="">Select Type</option>
                    <option value="adventurers">Adventurers</option>
                    <option value="pathfinders">Pathfinders</option>
                    <option value="master_guide">Master Guide</option>
                </select>
            </div>

            <div class="flex items-center space-x-4">
                <button type="submit" class="bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded">
                    {{ isEditing ? 'Update Club' : 'Save Club' }}
                </button>

                <button v-if="isEditing" type="button" @click="() => {
    isEditing = false;
    editingClubId = null
}" class="text-sm text-gray-600 hover:underline">
                    Cancel Edit
                </button>
            </div>
        </form>
    </div>

    <div v-else class="space-y-4 text-gray-700">
        <p class="font-medium text-lg">Your Clubs</p>
        <table class="min-w-full border rounded text-sm">
            <thead class="bg-gray-100">
                <tr>
                    <th class="p-2 text-left">Name</th>
                    <th class="p-2 text-left">Church</th>
                    <th class="p-2 text-left">Type</th>
                    <th class="p-2 text-left">Created</th>
                    <th class="p-2 text-left">Actions</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="club in clubs" :key="club.id" class="border-t">
                    <td class="p-2">{{ club.club_name }}</td>
                    <td class="p-2">{{ club.church_name }}</td>
                    <td class="p-2 capitalize">{{ club.club_type }}</td>
                    <td class="p-2">{{ club.creation_date }}</td>
                    <td class="p-2 space-x-2">
                        <button @click="editClub(club)" class="text-blue-600 hover:underline">Edit</button>
                        <button type="button" @click="deleteClub(club.id)" class="text-red-600 hover:underline">
                            Delete
                        </button>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</PathfinderLayout>
</template>
