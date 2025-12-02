<script setup>
import { ref, onMounted } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { defineProps, defineEmits } from 'vue'
import axios from 'axios'
import { useToast } from 'vue-toastification'
import { watch } from 'vue'

const props = defineProps({
    clubs: Array,
    user: Object,
    classToEdit: Object,
    visible: Boolean


})

const form = useForm({
    class_order: '',
    class_name: '',
    assigned_staff_id: '',
    club_id: '',
    user_id: ''
})
watch(() => props.classToEdit, (cls) => {
    form.reset()

    if (cls) {
        form.class_order = cls.class_order
        form.class_name = cls.class_name
        form.assigned_staff_id = cls.assigned_staff_id
        form.club_id = cls.club_id
    }
}, { immediate: true })


const emit = defineEmits(['update:visible', 'created'])

const close = () => {
    emit('update:visible', false)
}



const toast = useToast()

const clubList = ref(props.clubs ?? [])
const staffList = ref([])
const usersClub = ref([])
watch(() => props.clubs, (val) => {
    clubList.value = val || []
    if (!form.club_id && clubList.value.length) {
        form.club_id = clubList.value[0].id
    }
}, { immediate: true })
const fetchStaff = async (clubId) => {
    try {
        const response = await axios.get(`/clubs/${clubId}/staff`)
        console.log('Staff response:', response.data)
        staffList.value = response.data.staff
        if(staffList.value.length === 0) {
            toast.error('Create staff first, none found')
            emit('update:visible', false)
            return
        }
        usersClub.value = response.data.sub_role_users
        toast.success('Staff loaded')
    } catch (error) {
        console.error('Failed to fetch staff:', error)
    }
}

watch(() => form.club_id, (clubId) => {
    if (clubId) {
        fetchStaff(clubId)
    } else {
        staffList.value = []
        usersClub.value = []
    }
}, { immediate: true })
watch(
    () => form.assigned_staff_id,
    (newStaffId) => {
        if (!newStaffId) return

        const selectedStaff = staffList.value.find(staff => staff.id === newStaffId)
        if (!selectedStaff) return

        const selectedEmail = selectedStaff.email

        const matchedUser = usersClub.value.find(user => user.email === selectedEmail)

        if (matchedUser) {
        console.log("Matching user ID:", matchedUser.id)
        form.user_id = matchedUser.id
        } else {
        console.warn("No user found in usersClub with that email.")
        }
    },
    { immediate: false }
)
const submit = () => {
    const isEditing = !!props.classToEdit?.id

    const action = isEditing ?
        form.put(route('club-classes.update', props.classToEdit.id), {
            preserveScroll: true,
            onSuccess: () => {
                toast.success('Class updated successfully!')
                emit('created')
                emit('update:visible', false)
            },
            onError: (errors) => {
                toast.error('There were errors updating the class.')
                console.error(errors)
            }
        }) :
        form.post(route('club-classes.store'), {
            preserveScroll: true,
            onSuccess: () => {
                toast.success('Class created successfully!')
                emit('created')
                emit('update:visible', false)
            },
            onError: (errors) => {
                toast.error('There were errors saving the class.')
                console.error(errors)
            }
        })
}

onMounted(() => {
    if (form.club_id) {
        fetchStaff(form.club_id)
    }
})
</script>

<template>
<div class="fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-50">
    <div class="bg-white rounded-lg shadow-lg w-full max-w-lg p-6">
        <h2 class="text-xl font-semibold mb-4">
            {{ props.classToEdit ? 'Edit Class' : 'Create New Class' }}
        </h2>
        <form @submit.prevent="submit">
            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700">Class Order</label>
                <input v-model="form.class_order" type="number" class="w-full mt-1 p-2 border rounded" />
                <p v-if="form.errors.class_order" class="text-red-500 text-sm">{{ form.errors.class_order }}</p>
            </div>

            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700">Class Name</label>
                <input v-model="form.class_name" type="text" class="w-full mt-1 p-2 border rounded" />
                <p v-if="form.errors.class_name" class="text-red-500 text-sm">{{ form.errors.class_name }}</p>
            </div>

            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700">Assign Staff</label>
                <select v-model="form.assigned_staff_id" class="w-full mt-1 p-2 border rounded">
                    <option value="">-- Select Staff --</option>
                    <option v-for="staff in staffList" :key="staff.id" :value="staff.id">
                        {{ staff.name }}
                    </option>
                </select>
                <p v-if="form.errors.assigned_staff_id" class="text-red-500 text-sm">{{ form.errors.assigned_staff_id }}</p>
            </div>

            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700">Select Club</label>
                <select v-model="form.club_id" class="w-full mt-1 p-2 border rounded">
                    <option value="">-- Select Club --</option>
                    <option v-for="club in clubList" :key="club.id" :value="club.id">
                        {{ club.club_name }}
                    </option>
                </select>
                <p v-if="form.errors.club_id" class="text-red-500 text-sm">{{ form.errors.club_id }}</p>
            </div>

            <div class="flex justify-end space-x-2 mt-6">
                <button type="button" @click="close" class="px-4 py-2 bg-gray-300 rounded hover:bg-gray-400">
                    Cancel
                </button>
                <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700">
                    {{ props.classToEdit ? 'Update' : 'Create' }}
                </button>
            </div>
        </form>
    </div>
</div>
</template>
