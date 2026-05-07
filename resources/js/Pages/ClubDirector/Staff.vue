<script setup>
import { ref, computed, onMounted, watch, watchEffect } from 'vue'
import PathfinderLayout from '@/Layouts/PathfinderLayout.vue'
import CreateStaffModal from '@/Components/CreateStaffModal.vue'
import {
    PlusIcon,
    MinusIcon,
    UserPlusIcon,
    DocumentArrowDownIcon,
    TrashIcon,
    ArrowPathIcon,
    PencilIcon,
} from '@heroicons/vue/24/solid'
import { useAuth } from '@/Composables/useAuth'
import { useGeneral } from '@/Composables/useGeneral'
import { useLocale } from '@/Composables/useLocale'
import UpdatePasswordModal from "@/Components/ChangePassword.vue";
import {
    fetchStaffByClubId,
    createStaffUser,
    approveStaff,
    rejectStaff,
    updateStaffStatus,
    updateUserStatus,
    downloadStaffZip,
    fetchClubClasses,
    updateStaffAssignedClass,
    linkStaffToClubUser,
    createTempStaffPathfinder
} from '@/Services/api'
import axios from 'axios'
import { usePage } from '@inertiajs/vue3';

const page = usePage();
const subRoles = page.props.sub_roles;


// ✅ Auth & general utilities
const { user, activeClub, availableClubs } = useAuth()
const directorCanSelectClub = computed(() => !isSuperadmin.value && clubs.value.length > 1)
const churchId = computed(() => user.value?.church_id || null)
const userId = computed(() => user.value?.id || null)
const isSuperadmin = computed(() => user.value?.profile_type === 'superadmin')
const changePasswordUserId = ref(null)
const club_name = computed(() => user.value?.clubs[0]?.club_name || '')
const { toast, showToast } = useGeneral()
const { tr } = useLocale()
const showPasswordModal = ref(false)
// ✅ State
const selectedClub = ref(null)
const clubs = ref([])
const staff = ref([])
const pendingStaff = ref([])
const tempStaff = ref([])
const assignedClassChanges = ref({})
const isUpdatingClass = ref({})
const parentAccounts = ref(page.props.parent_accounts || [])
const tempStaffForm = ref({
    club_id: '',
    staff_name: '',
    staff_dob: '',
    staff_age: '',
    staff_email: '',
    staff_phone: '',
})
const clubClasses = ref([])
const sub_roles = ref([])
const pendingUsers = ref([])
const createStaffModalVisible = ref(false)
const staffToEdit = ref(null)
const selectedUserForStaff = ref(null)
const expandedRows = ref(new Set())
const selectAll = ref(false)
const selectedStaffIds = ref(new Set())
const activeTab = ref('active')
const activeStaffTab = ref('active')
const clubUserIds = ref(new Set())
const tempStaffModalVisible = ref(false)

// ✅ Create staff eligibility map
const createStaffMap = computed(() => {
    const map = {}
    sub_roles.value.forEach(user => {
        map[user.id] = user.create_staff === true
    })
    return map
})

const filteredUsers = computed(() =>
    sub_roles.value.filter(user => {
        if (activeTab.value === 'pending') return false
        if (activeTab.value === 'parents') return false
        const targetStatus = activeTab.value === 'active' ? 'active' : 'deleted'
        if (user.profile_type === 'parent') return false
        return user.status === targetStatus
    })
)

const filteredPendingUsers = computed(() =>
    pendingUsers.value
)
const filteredPendingStaff = computed(() =>
    pendingStaff.value
)

const displayedStaff = computed(() => staff.value)

const filteredStaff = computed(() =>
    displayedStaff.value.filter(person => person.status === activeStaffTab.value)
)
const availableClasses = computed(() => {
    if (!selectedClub.value) return []
    if ((selectedClub.value.evaluation_system || 'honors') === 'carpetas') {
        return clubClasses.value
    }
    return clubClasses.value.filter(c => c.club_id === selectedClub.value.id || !c.club_id)
})

const classDisplay = (person) => {
    if (person.class_names?.length) return person.class_names.join(', ')
    if ((selectedClub.value?.evaluation_system || 'honors') === 'carpetas' && person.assigned_carpeta_class_activation_id) {
        const match = clubClasses.value.find(c => String(c.id) === String(person.assigned_carpeta_class_activation_id))
        if (match) return match.class_name
    }
    if (person.assigned_class) {
        const match = clubClasses.value.find(c => String(c.id) === String(person.assigned_class))
        if (match) return match.class_name
    }
    return '—'
}

const dobDisplay = (person) => {
    if (person.dob) return String(person.dob).slice(0, 10)
    if (person.staff_dob) return String(person.staff_dob).slice(0, 10)
    return '—'
}


const openEditStaffModal = (staff) => {
    selectedUserForStaff.value = null
    staffToEdit.value = staff
    createStaffModalVisible.value = true
}

watch(sub_roles, (newVal) => {
    if (!newVal.some(user => user.status === 'deleted')) {
        activeTab.value = 'active'
    }
}, { immediate: true })

watch(staff, (newVal) => {
    if (!newVal.some(person => person.status === 'deleted')) {
        activeStaffTab.value = 'active'
    }
}, { immediate: true })

// ✅ Fetch club(s)
const fetchClubs = async () => {
    try {
        clubs.value = Array.isArray(availableClubs.value) ? availableClubs.value : []

        if (isSuperadmin.value) {
            const contextClubId = activeClub.value?.id || null
            selectedClub.value = contextClubId
                ? clubs.value.find(club => String(club.id) === String(contextClubId)) || null
                : null
        } else {
            const preferredClubId = activeClub.value?.id || user.value?.club_id || null
            selectedClub.value = clubs.value.find(club => String(club.id) === String(preferredClubId)) || clubs.value[0] || null
        }

        if (selectedClub.value?.id) {
            await fetchStaff(selectedClub.value.id, churchId.value)
        } else {
            staff.value = []
            pendingStaff.value = []
            tempStaff.value = []
            sub_roles.value = []
            pendingUsers.value = []
            clubUserIds.value = new Set()
            clubClasses.value = []
        }
        showToast(tr('Clubes cargados', 'Clubs loaded'))
    } catch (error) {
        console.error('Failed to fetch clubs:', error)
        showToast(tr('Error al cargar clubes', 'Could not load clubs'), 'error')
    }
}

// Fetch club classes
const fetchClasses = async (clubId) => {
    try {
        clubClasses.value = await fetchClubClasses(clubId)
    } catch (error) {
        console.error('Failed to fetch club classes:', error)
    }
}
// ✅ Fetch staff list
const fetchStaff = async (clubId, churchId = null) => {
    try {
        const data = await fetchStaffByClubId(clubId, churchId)
        staff.value = data.staff
        sub_roles.value = data.sub_role_users
        pendingUsers.value = data.pending_users || []
        pendingStaff.value = data.pending_staff || []
        clubUserIds.value = new Set(data.club_user_ids || [])
        await fetchClasses(clubId)
        // hydrate current class selections
        staff.value.forEach(person => {
            if ((selectedClub.value?.evaluation_system || 'honors') === 'carpetas' && person.assigned_carpeta_class_activation_id) {
                const match = clubClasses.value.find(c => String(c.id) === String(person.assigned_carpeta_class_activation_id))
                if (match) assignedClassChanges.value[person.id] = match.id
            } else if (person.assigned_class) {
                assignedClassChanges.value[person.id] = person.assigned_class
            } else if (person.class_names?.length === 1) {
                const match = clubClasses.value.find(c => c.class_name === person.class_names[0])
                if (match) assignedClassChanges.value[person.id] = match.id
            }
        })
        tempStaff.value = []
        showToast(tr('Personal cargado', 'Staff loaded'))
    } catch (error) {
        console.error('Failed to fetch staff:', error)
        showToast(tr('Error al cargar personal', 'Could not load staff'), 'error')
    }
}
const saveAssignedClass = async (person) => {
    const newClassId = assignedClassChanges.value[person.id]
    if (!newClassId) return
    const staffId = Number(person.staff_id ?? person.id)
    const classId = Number(newClassId)
    if (!Number.isInteger(staffId)) {
        showToast(tr('No se pudo asignar la clase. Falta un registro de personal valido.', 'Could not assign the class. A valid staff record is missing.'), 'error')
        return
    }
    if (!Number.isInteger(classId)) {
        showToast(tr('Selecciona una clase valida.', 'Select a valid class.'), 'error')
        return
    }
    try {
        isUpdatingClass.value[person.id] = true
        await updateStaffAssignedClass(staffId, classId)
        showToast(tr('Clase actualizada', 'Class updated'))
        await fetchStaff(person.club_id)
    } catch (err) {
        console.error('Failed to update class', err)
        showToast(tr('Error al actualizar la clase', 'Could not update the class'), 'error')
    } finally {
        isUpdatingClass.value[person.id] = false
    }
}

// ✅ Modals
const openStaffForm = (user) => {
    if (!selectedClub.value) {
        showToast(tr('Selecciona un club primero', 'Select a club first'), 'error')
        return
    }
    selectedUserForStaff.value = user
    selectedUserForStaff.value.club_name = club_name.value
    if (['pathfinders', 'temp_pathfinder'].includes(selectedClub.value?.club_type)) {
        tempStaffForm.value.club_id = selectedClub.value.id
        tempStaffForm.value.staff_email = user?.email || ''
        tempStaffForm.value.staff_name = user?.name || ''
        tempStaffForm.value.staff_dob = ''
        tempStaffForm.value.staff_age = ''
        tempStaffForm.value.staff_phone = ''
        tempStaffModalVisible.value = true
        return
    }
    createStaffModalVisible.value = true
}

// ✅ Account management
const updateStaffAccount = async (staff, status_code) => {
    const isDeactivate = status_code === 301
    const action = isDeactivate ? tr('desactivar', 'deactivate') : tr('reactivar', 'reactivate')
    if (!confirm(tr(`¿Seguro que deseas ${action} al personal ${staff.name}?`, `Are you sure you want to ${action} staff member ${staff.name}?`))) return

    try {
        await updateStaffStatus(staff.id, status_code)
        showToast(isDeactivate ? tr('Personal desactivado', 'Staff deactivated') : tr('Personal reactivado', 'Staff reactivated'))
        fetchStaff(staff.club_id)
    } catch (error) {
        console.error('Failed to update staff status:', error)
        showToast(tr(`No se pudo ${action} el personal`, `Could not ${action} the staff member`), 'error')
    }
}

const updateStaffUserAccount = async (user, status_code) => {
    const isDeactivate = status_code === 301
    const action = isDeactivate ? tr('desactivar', 'deactivate') : tr('reactivar', 'reactivate')
    if (!confirm(tr(`¿Seguro que deseas ${action} la cuenta de ${user.name}?`, `Are you sure you want to ${action} ${user.name}'s account?`))) return

    try {
        await updateUserStatus(user.id, status_code)
        showToast(isDeactivate ? tr('Usuario desactivado', 'User deactivated') : tr('Usuario reactivado', 'User reactivated'))
        fetchStaff(user.club_id)
    } catch (error) {
        console.error('Failed to update user status:', error)
        showToast(tr(`No se pudo ${action} el usuario`, `Could not ${action} the user`), 'error')
    }
}

const createUser = async (person) => {
    try {
        await createStaffUser({
            name: person.name,
            email: person.email,
            church_name: person.church_name,
            church_id: person.church_id,
            club_id: person.club_id
        })
        showToast(tr('Usuario creado correctamente', 'User created successfully'))
        person.create_user = false
        fetchStaff(person.club_id,churchId.value)
    } catch (err) {
        console.error('Create user error:', err)
        showToast(tr('No se pudo crear el usuario', 'Could not create the user'), 'error')
    }
}

const linkToClubUsers = async (person) => {
    if (!person.user_id) {
        showToast(tr('No hay usuario vinculado a este personal (por correo).', 'There is no user linked to this staff member by email.'), 'error')
        return
    }
    try {
        await linkStaffToClubUser(person.id)
        clubUserIds.value.add(person.user_id)
        showToast(tr('Personal vinculado al acceso del club', 'Staff linked to club access'))
    } catch (err) {
        console.error('Failed to link staff to club users', err)
        showToast(tr('No se pudo vincular el personal', 'Could not link the staff member'), 'error')
    }
}

// ✅ Bulk actions
const handleBulkAction = async (action) => {
    if (selectedStaffIds.value.size === 0) {
        showToast(tr('No hay personal seleccionado.', 'No staff selected.'))
        return
    }

    const ids = Array.from(selectedStaffIds.value)
    const isReactivate = action === 'reactivate'
    const statusCode = isReactivate ? 423 : 301
    const confirmText = isReactivate
        ? tr('¿Seguro que deseas reactivar el personal seleccionado?', 'Are you sure you want to reactivate the selected staff?')
        : tr('¿Seguro que deseas desactivar el personal seleccionado?', 'Are you sure you want to deactivate the selected staff?')

    if (!confirm(confirmText)) return

    try {
        for (const id of ids) {
            await updateStaffStatus(id, statusCode)
        }
        showToast(isReactivate ? tr('Personal reactivado', 'Staff reactivated') : tr('Personal desactivado', 'Staff deactivated'))
        await fetchStaff(selectedClub.value.id)
        selectedStaffIds.value.clear()
        selectAll.value = false
    } catch (error) {
        console.error('Bulk action failed:', error)
        toast.error(tr('Actualizacion masiva fallida', 'Bulk update failed'))
    }
}

// ✅ Download
const downloadWord = (staffId) => {
    window.open(`/staff/${staffId}/export-word`, '_blank')
}

const approvePending = async (userId) => {
    try {
        await axios.post(route('club.users.approve', userId))
        showToast(tr('Usuario aprobado', 'User approved'))
        fetchStaff(selectedClub.value.id, churchId.value)
    } catch (err) {
        console.error('Approve failed', err)
        showToast(tr('No se pudo aprobar el usuario', 'Could not approve the user'), 'error')
    }
}

const rejectPending = async (userId) => {
    try {
        await updateUserStatus(userId, 301)
        showToast(tr('Usuario rechazado', 'User rejected'))
        fetchStaff(selectedClub.value.id, churchId.value)
    } catch (err) {
        console.error('Reject failed', err)
        showToast(tr('No se pudo rechazar el usuario', 'Could not reject the user'), 'error')
    }
}



// ✅ Selection helpers
const toggleSelectStaff = (id) => {
    selectedStaffIds.value.has(id)
        ? selectedStaffIds.value.delete(id)
        : selectedStaffIds.value.add(id)
}

const toggleSelectAll = () => {
    selectAll.value
        ? selectedStaffIds.value = new Set(staff.value.map(m => m.id))
        : selectedStaffIds.value.clear()
}

const toggleExpanded = (id) => {
    expandedRows.value.has(id) ? expandedRows.value.delete(id) : expandedRows.value.add(id)
}

const changePassword = (user) => {
    showPasswordModal.value = true
    changePasswordUserId.value = user.id
}
const closeModal = () => {
    createStaffModalVisible.value = false
    staffToEdit.value = null
}
const closeTempStaffModal = () => {
    tempStaffModalVisible.value = false
}
const approvePendingStaff = async (staffRow) => {
    try {
        await approveStaff(staffRow.id)
        showToast(tr('Personal aprobado', 'Staff approved'))
        fetchStaff(selectedClub.value.id, churchId.value)
    } catch (err) {
        console.error('Approve staff failed', err)
        showToast(tr('No se pudo aprobar el personal', 'Could not approve the staff member'), 'error')
    }
}

const rejectPendingStaff = async (staffRow) => {
    try {
        await rejectStaff(staffRow.id)
        showToast(tr('Personal rechazado', 'Staff rejected'))
        fetchStaff(selectedClub.value.id, churchId.value)
    } catch (err) {
        console.error('Reject staff failed', err)
        showToast(tr('No se pudo rechazar el personal', 'Could not reject the staff member'), 'error')
    }
}

const saveTempStaff = async () => {
    try {
        tempStaffForm.value.club_id = selectedClub.value?.id || ''
        if (!tempStaffForm.value.club_id) {
            showToast(tr('Selecciona un club primero', 'Select a club first'), 'error')
            return
        }
        await createTempStaffPathfinder(tempStaffForm.value)
        showToast(tr('Perfil de staff creado', 'Staff profile created'), 'success')
        tempStaffForm.value = {
            club_id: selectedClub.value?.id || '',
            staff_name: '',
            staff_dob: '',
            staff_age: '',
            staff_email: '',
            staff_phone: '',
        }
        await fetchStaff(selectedClub.value.id, churchId.value)
    } catch (err) {
        console.error('Failed to save temp staff', err)
        showToast(tr('No se pudo crear el perfil de staff', 'Could not create the staff profile'), 'error')
    }
}

// Auto-fill age from DOB
watch(() => tempStaffForm.value.staff_dob, (dob) => {
    if (!dob) return
    const birth = new Date(dob)
    if (isNaN(birth.getTime())) return
    const today = new Date()
    let age = today.getFullYear() - birth.getFullYear()
    const m = today.getMonth() - birth.getMonth()
    if (m < 0 || (m === 0 && today.getDate() < birth.getDate())) {
        age--
    }
    tempStaffForm.value.staff_age = age
})

onMounted(fetchClubs)

watch(
    () => [activeClub.value?.id, availableClubs.value?.length, isSuperadmin.value],
    () => {
        fetchClubs()
    }
)
</script>


<template>
    <PathfinderLayout>
        <div class="p-8">
            <h1 class="text-xl font-bold mb-4">{{ tr('Personal', 'Staff') }}</h1>
            <div v-if="directorCanSelectClub" class="max-w-xl mb-6">
                <label class="block mb-1 font-medium text-gray-700">{{ tr('Selecciona un club', 'Select a club') }}</label>
                <select v-model="selectedClub"
                    @change="() => { if (selectedClub) { fetchStaff(selectedClub.id, churchId) } }"
                    class="w-full p-2 border rounded">
                    <option disabled value="">-- {{ tr('Selecciona un club', 'Select a club') }} --</option>
                    <option v-for="club in clubs" :key="club.id" :value="club">
                        {{ club.club_name }} ({{ club.club_type }})
                    </option>
                </select><br><br>
            </div>
            <div v-else-if="selectedClub" class="mb-6 rounded border bg-white px-4 py-3 text-sm text-gray-700">
                {{ tr('Club activo', 'Active club') }}: <strong>{{ selectedClub.club_name }}</strong>
            </div>
            <div v-else-if="isSuperadmin" class="mb-6 rounded border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
                {{ tr('Selecciona un club desde el selector global del superadmin para administrar el personal.', 'Select a club from the superadmin global selector to manage staff.') }}
            </div>
            <div v-if="selectedClub" class="mb-6 flex items-center gap-3">
                <button
                    class="bg-green-600 text-white px-4 py-2 rounded hover:bg-green-700"
                    @click="openStaffForm(user)"
                >
                    {{ tr('Crear personal', 'Create staff') }}
                </button>
            </div>

            <div v-if="selectedClub" class="max-w-5xl mx-auto">
                <div v-if="filteredPendingStaff.length" class="mb-6 border rounded p-4 bg-amber-50">
                    <h2 class="font-semibold text-amber-800 mb-2">{{ tr('Aprobaciones de personal pendientes', 'Pending Staff Approvals') }}</h2>
                    <div class="space-y-2">
                        <div v-for="person in filteredPendingStaff" :key="person.id" class="flex items-center justify-between bg-white border rounded px-3 py-2">
                            <div>
                                <div class="font-medium text-gray-900">{{ person.name || tr('Sin nombre', 'No name') }}</div>
                                <div class="text-sm text-gray-600">{{ person.email || tr('Sin correo', 'No email') }}</div>
                            </div>
                            <div class="flex gap-2">
                                <button @click="approvePendingStaff(person)" class="px-3 py-1 text-sm rounded bg-green-600 text-white hover:bg-green-700">{{ tr('Aprobar', 'Approve') }}</button>
                                <button @click="rejectPendingStaff(person)" class="px-3 py-1 text-sm rounded bg-red-600 text-white hover:bg-red-700">{{ tr('Rechazar', 'Reject') }}</button>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="mb-4 flex space-x-4 border-b pb-2">
                    <button @click="activeStaffTab = 'active'"
                        :class="activeStaffTab === 'active' ? 'font-bold border-b-2 border-blue-600' : 'text-gray-500'">
                        {{ tr('Personal activo', 'Active staff') }}
                    </button>
                    <button
                        v-if="staff.some(person => person.status === 'deleted') && user.profile_type === 'club_director'"
                        @click="activeStaffTab = 'deleted'"
                        :class="activeStaffTab === 'deleted' ? 'font-bold border-b-2 border-red-600' : 'text-gray-500'">
                        {{ tr('Personal inactivo', 'Inactive staff') }}
                    </button>
                </div>
                <div class="flex items-center justify-between mb-4">
                    <div class="flex items-center gap-4">
                        <label class="inline-flex items-center">
                            <input type="checkbox" v-model="selectAll" @change="toggleSelectAll" class="mr-2" />
                            <span>{{ tr('Seleccionar todo', 'Select all') }}</span>
                        </label>
                        <select v-if="selectedStaffIds.size > 0"
                            @change="e => handleBulkAction(e.target.value, 'staff')"
                            class="border p-2 px-4 rounded w-60 text-sm">
                            <option value="" disabled selected>{{ tr('Acciones masivas', 'Bulk actions') }}</option>

                            <option :value="activeStaffTab === 'deleted' ? 'reactivate' : 'delete'">
                                {{ activeStaffTab === 'deleted' ? tr('Reactivar seleccionados', 'Reactivate selected') : tr('Desactivar seleccionados', 'Deactivate selected') }}
                            </option>

                            <option value="download">{{ tr('Descargar formularios', 'Download forms') }}</option>
                        </select>
                    </div>
                    <span class="text-sm text-gray-600">{{ selectedStaffIds.size }} {{ tr('seleccionados', 'selected') }}</span>
                </div>

                <table class="w-full border rounded overflow-hidden text-sm">
                    <thead class="bg-gray-200">
                        <tr>
                            <th class="p-2 text-left"></th>
                            <th class="p-2 text-left">{{ tr('Nombre', 'Name') }}</th>
                            <th class="p-2 text-left">{{ tr('Fecha de nacimiento', 'Date of birth') }}</th>
                            <th class="p-2 text-left">{{ tr('Direccion', 'Address') }}</th>
                            <!-- <th class="p-2 text-left">Class</th> -->
                            <th class="p-2 text-left">{{ tr('Celular', 'Cell phone') }}</th>
                            <th class="p-2 text-left w-16">Email</th>
                            <th class="p-2 text-left">{{ tr('Estado', 'Status') }}</th>
                            <th class="p-2 text-left">{{ tr('Acciones', 'Actions') }}</th>
                            <th class="p-2 text-left">{{ tr('Clases asignadas', 'Assigned classes') }}</th>

                        </tr>
                    </thead>
                    <tbody>
                        <template v-for="person in filteredStaff" :key="person.id">
                            <tr class="border-t">
                                <td class="p-2 text-xs">
                                    <input type="checkbox" :value="person.id" :checked="selectedStaffIds.has(person.id)"
                                        @change="() => toggleSelectStaff(person.id)" />
                                </td>
                                <td class="p-2 text-xs">{{ person.name }}</td>
                                <td class="p-2 text-xs">{{ dobDisplay(person) }}</td>
                                <td class="p-2 text-xs">{{ person.address }}</td>
                                <!-- <td class="p-2">{{ person.assigned_classes?.[0]?.class_name ?? '—' }}</td> -->
                                <td class="p-2 text-xs">{{ person.cell_phone }}</td>
                                <td class="p-2 text-xs w-16 truncate">
                                    <a :href="`mailto:${person.email}`" class="text-blue-600 hover:underline block">
                                        {{ person.email }}
                                    </a>
                                </td>
                                <td class="p-2 text-xs">{{ person.status }}</td>
                                <td class="p-2 space-x-1 text-xs">
                                    <!-- Toggle Details -->
                                    <button @click="toggleExpanded(person.id)" class="text-green-600"
                                        :title="tr('Ver detalles', 'View details')">
                                        <component :is="expandedRows.has(person.id) ? MinusIcon : PlusIcon"
                                            class="w-4 h-4 inline" />
                                    </button>

                                    <!-- Create User -->
                                <button v-if="person.create_user" @click="createUser(person)"
                                    class="text-orange-600" :title="tr('Crear usuario', 'Create user')">
                                    <UserPlusIcon class="w-4 h-4 inline" />
                                </button>
                                <button
                                    v-else-if="person.user_id && !clubUserIds.has(person.user_id)"
                                    @click="linkToClubUsers(person)"
                                    class="text-amber-600"
                                    :title="tr('Vincular acceso al club', 'Link club access')"
                                >
                                    <UserPlusIcon class="w-4 h-4 inline" />
                                </button>

                                <!-- Download Word Form -->
                                <button
                                    v-if="person.type !== 'pathfinders'"
                                    @click="downloadWord(person.id)"
                                    class="text-blue-600"
                                    :title="tr('Descargar formulario Word', 'Download Word form')">
                                        <DocumentArrowDownIcon class="w-4 h-4 inline" />
                                    </button>

                                    <!-- Delete or Reactivate -->
                                    <button v-if="person.status === 'active'" @click="updateStaffAccount(person, 301)"
                                        class="text-red-600" :title="tr('Eliminar personal', 'Delete staff')">
                                        <TrashIcon class="w-4 h-4 inline" />
                                    </button>
                                    <button v-else @click="() => updateStaffAccount(person, 423)" class="text-gray-600"
                                        :title="tr('Reactivar personal', 'Reactivate staff')">
                                        <ArrowPathIcon class="w-4 h-4 inline" />
                                    </button>
                                    <button
                                        v-if="person.type !== 'pathfinders'"
                                        class="text-indigo-600 hover:underline"
                                        @click="openEditStaffModal(person)">
                                        <PencilIcon class="w-4 h-4 inline" />
                                    </button>
                                </td>
                                <td class="p-2 text-xs">
                                    {{ classDisplay(person) }}
                                    <div class="mt-1 flex items-center gap-2">
                                        <select
                                            v-model="assignedClassChanges[person.id]"
                                            class="border p-1 rounded text-xs"
                                        >
                                            <option disabled value="">{{ tr('Seleccionar clase', 'Select class') }}</option>
                                            <option v-for="cls in availableClasses" :key="cls.id" :value="cls.id">
                                                {{ cls.class_name }}
                                            </option>
                                        </select>
                                        <button
                                            @click="() => saveAssignedClass(person)"
                                            :disabled="!assignedClassChanges[person.id] || isUpdatingClass[person.id]"
                                            class="px-2 py-1 bg-blue-600 text-white rounded text-xs hover:bg-blue-700"
                                        >
                                            {{ isUpdatingClass[person.id] ? tr('Guardando...', 'Saving...') : tr('Guardar', 'Save') }}
                                        </button>
                                    </div>
                                </td>
                            </tr>

                            <tr v-if="expandedRows.has(person.id)" class="bg-gray-50 border-t">
                                <td colspan="10" class="p-4 text-gray-700">
                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                        <div><strong>{{ tr('Ciudad/Estado/Codigo postal', 'City/State/ZIP code') }}:</strong> {{ person.city }}, {{ person.state }} {{
                                            person.zip }}</div>
                                        <div><strong>{{ tr('Nombre del club', 'Club name') }}:</strong> {{ person.club_name }}</div>
                                        <div><strong>{{ tr('Nombre de la iglesia', 'Church name') }}:</strong> {{ person.church_name }}</div>

                                        <div><strong>{{ tr('Limitacion de salud', 'Health limitation') }}:</strong> {{ person.has_health_limitation ? tr('Si', 'Yes')
                                            : tr('No', 'No') }}</div>
                                        <div v-if="person.has_health_limitation"><strong>{{ tr('Detalles de la limitacion', 'Limitation details') }}:</strong> {{
                                            person.health_limitation_description }}</div>

                                        <!-- Experience -->
                                        <div>
                                            <strong>{{ tr('Experiencia', 'Experience') }}:</strong>
                                            <ul class="list-disc list-inside ml-4" v-if="person.experiences?.length">
                                                <li v-for="(exp, idx) in person.experiences" :key="idx">
                                                    {{ exp.position }} {{ tr('en', 'at') }} {{ exp.organization }} ({{ exp.date }})
                                                </li>
                                            </ul>
                                            <div v-else>{{ tr('No hay experiencia registrada.', 'No experience recorded.') }}</div>
                                        </div>

                                        <!-- Awards -->
                                        <div>
                                            <strong>{{ tr('Premios/Instruccion', 'Awards/Instruction') }}:</strong>
                                            <ul class="list-disc list-inside ml-4"
                                                v-if="person.award_instruction_abilities?.length">
                                                <li v-for="(award, index) in person.award_instruction_abilities"
                                                    :key="index">
                                                    {{ award.name }} —
                                                    <span v-if="award.level === 'T'">{{ tr('Capaz de ensenar', 'Able to teach') }}</span>
                                                    <span v-else-if="award.level === 'A'">{{ tr('Puede asistir', 'Can assist') }}</span>
                                                    <span v-else-if="award.level === 'I'">{{ tr('Interesado en aprender', 'Interested in learning') }}</span>
                                                    <span v-else>{{ award.level }}</span>
                                                </li>
                                            </ul>
                                            <div v-else>{{ tr('No hay premios registrados.', 'No awards recorded.') }}</div>
                                        </div>

                                        <!-- Unlawful Conduct -->
                                        <div><strong>{{ tr('Conducta ilegal', 'Unlawful conduct') }}:</strong> {{ person.unlawful_sexual_conduct ===
                                            'yes' ? tr('Si', 'Yes') : tr('No', 'No') }}</div>
                                        <div v-if="person.unlawful_sexual_conduct === 'yes'">
                                            <strong>{{ tr('Registros de conducta', 'Conduct records') }}:</strong>
                                            <ul class="list-disc list-inside ml-4"
                                                v-if="person.unlawful_sexual_conduct_records?.length">
                                                <li v-for="(record, idx) in person.unlawful_sexual_conduct_records"
                                                    :key="idx">
                                                    {{ record.type || 'N/A' }} — {{ record.date_place || tr('Fecha/Lugar desconocidos', 'Unknown date/place') }}<br />
                                                    <span class="text-gray-600">{{ tr('Referencia', 'Reference') }}: {{ record.reference || 'N/A'
                                                        }}</span>
                                                </li>
                                            </ul>
                                        </div>

                                        <div><strong>{{ tr('Sterling Volunteer completado', 'Sterling Volunteer completed') }}:</strong> {{
                                            person.sterling_volunteer_completed ? tr('Si', 'Yes') : tr('No', 'No') }}
                                        </div>

                                        <!-- References -->
                                        <div>
                                            <strong>{{ tr('Referencias', 'References') }}:</strong>
                                            <ul class="list-disc pl-5">
                                                <li v-if="person.reference_pastor">{{ tr('Pastor', 'Pastor') }}: {{ person.reference_pastor }}
                                                </li>
                                                <li v-if="person.reference_elder">{{ tr('Anciano', 'Elder') }}: {{ person.reference_elder }}
                                                </li>
                                                <li v-if="person.reference_other">{{ tr('Otro', 'Other') }}: {{ person.reference_other }}
                                                </li>
                                                <li
                                                    v-if="!person.reference_pastor && !person.reference_elder && !person.reference_other">
                                                    {{ tr('No se proporcionaron referencias.', 'No references were provided.') }}</li>
                                            </ul>
                                        </div>

                                        <div><strong>{{ tr('Firmado', 'Signed') }}:</strong> {{ person.applicant_signature }} {{ tr('el', 'on') }} {{
                                            person.application_signed_date.slice(0,
                                                10) }}</div>
                                    </div>
                                </td>
                            </tr>

                        </template>
                    </tbody>
                </table>
                <div class="mt-12 max-w-5xl mx-auto">
                    <div class="mb-4 flex space-x-4 border-b pb-2">
                        <button @click="activeTab = 'active'"
                            :class="activeTab === 'active' ? 'font-bold border-b-2 border-blue-600' : 'text-gray-500'">
                            {{ tr('Cuentas activas', 'Active accounts') }}
                        </button>
                        <button @click="activeTab = 'parents'"
                            :class="activeTab === 'parents' ? 'font-bold border-b-2 border-green-600' : 'text-gray-500'">
                            {{ tr('Cuentas de padres', 'Parent accounts') }}
                        </button>
                        <button
                            v-if="sub_roles.some(user => user.status === 'deleted') && user.profile_type === 'club_director'"
                            @click="activeTab = 'deleted'"
                            :class="activeTab === 'deleted' ? 'font-bold border-b-2 border-red-600' : 'text-gray-500'">
                            {{ tr('Cuentas inactivas', 'Inactive accounts') }}
                        </button>
                        <button v-if="filteredPendingUsers.length"
                            @click="activeTab = 'pending'"
                            :class="activeTab === 'pending' ? 'font-bold border-b-2 border-amber-600' : 'text-gray-500'">
                            {{ tr('Solicitudes pendientes', 'Pending requests') }}
                        </button>
                    </div>

                    <template v-if="activeTab === 'parents'">
                        <table class="w-full text-sm border rounded overflow-hidden">
                            <thead class="bg-gray-100">
                                <tr>
                                    <th class="p-2 text-left">{{ tr('Padre/Madre', 'Parent') }}</th>
                                    <th class="p-2 text-left">Email</th>
                                    <th class="p-2 text-left">{{ tr('Hijos', 'Children') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-if="!parentAccounts.length">
                                    <td colspan="3" class="p-3 text-center text-gray-500">{{ tr('No se encontraron cuentas de padres.', 'No parent accounts were found.') }}</td>
                                </tr>
                                <template v-for="parent in parentAccounts" :key="parent.id">
                                    <tr class="border-t">
                                        <td class="p-2 text-xs">{{ parent.name }}</td>
                                        <td class="p-2 text-xs">{{ parent.email }}</td>
                                        <td class="p-2 text-xs">
                                            <div v-if="parent.children?.length" class="space-y-1">
                                                <div v-for="child in parent.children" :key="child.id"
                                                    class="border rounded p-2 bg-gray-50">
                                                    <div class="font-semibold text-xs">{{ child.name || '—' }}</div>
                                                    <div class="text-[11px] text-gray-600">{{ tr('Club', 'Club') }}: {{
                                                        child.club_name || child.club_id || '—' }}</div>
                                                    <div class="text-[11px] text-gray-600">{{ tr('Tipo', 'Type') }}: {{
                                                        child.member_type }}</div>
                                                    <div class="text-[11px] text-gray-600">{{ tr('ID de clase', 'Class ID') }}: {{
                                                        child.class_id || '—' }}</div>
                                                </div>
                                            </div>
                                            <div v-else class="text-gray-500 text-xs">{{ tr('No hay hijos vinculados.', 'No linked children.') }}</div>
                                        </td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </template>
                    <template v-else-if="activeTab !== 'pending'">
                        <table class="w-full text-sm border rounded overflow-hidden">
                            <thead class="bg-gray-100">
                                <tr>
                                    <th class="p-2 text-left">{{ tr('Nombre', 'Name') }}</th>
                                    <th class="p-2 text-left">Email</th>
                                    <th class="p-2 text-left">{{ tr('Club', 'Club') }}</th>
                                    <th class="p-2 text-left">{{ tr('Rol', 'Role') }}</th>
                                    <th class="p-2 text-left">{{ tr('Subrol', 'Subrole') }}</th>
                                    <th class="p-2 text-left">{{ tr('Iglesia', 'Church') }}</th>
                                    <th class="p-2 text-left">{{ tr('Estado', 'Status') }}</th>
                                    <th class="p-2 text-left">{{ tr('Acciones', 'Actions') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="user in filteredUsers" :key="user.id" class="border-t">
                                    <td class="p-2 text-xs">{{ user.name }}</td>
                                    <td class="p-2 text-xs">{{ user.email }}</td>
                                    <td class="p-2 text-xs">{{ user.club_name || user.club_id || '—' }}</td>
                                    <td class="p-2 text-xs">{{ user.profile_type }}</td>


                                    <td class="p-2 capitalize text-xs">
                                        <select id="sub_role" class="border p-1 rounded text-xs" v-model="user.sub_role">
                                            <option value="">-- {{ tr('Seleccionar subrol', 'Select subrole') }} --</option>
                                            <option v-for="role in subRoles" :key="role.id" :value="role.key">
                                                {{ role.label }}
                                            </option>
                                        </select>
                                    </td>


                                    <!-- <td class="p-2 capitalize text-xs">{{ user.sub_role }}</td> -->
                                    <td class="p-2 text-xs">{{ user.church_name }}</td>
                                    <td class="p-2 text-xs">{{ user.status }}</td>
                                    <td class="p-2 text-xs">
                                        <template v-if="user.status === 'active'">
                                            <div class="flex items-center space-x-2">
                                                <button @click="changePassword(user)"class="px-2 py-1 bg-blue-600 text-white rounded text-xs hover:bg-blue-700">
                                                    {{ tr('Cambiar contraseña', 'Change password') }}
                                                </button>

                                                <button @click="updateStaffUserAccount(user, 301)"
                                                    class="text-red-600 hover:underline" :title="tr('Eliminar registro', 'Delete record')">
                                                    <TrashIcon class="w-4 h-4 inline" />
                                                </button>

                                            <button v-if="createStaffMap[user.id]"
                                                class="text-green-600 hover:underline" @click="openStaffForm(user)"
                                                :title="tr('Agregar usuario como personal', 'Add user as staff')">
                                                <UserPlusIcon class="w-5 h-5 text-green-600" />
                                            </button>
                                            </div>
                                        </template>
                                        <template v-else-if="user.status !== 'active'">
                                            <button @click="updateStaffUserAccount(user, 423)"
                                                class="text-blue-600 hover:underline">
                                                {{ tr('Reactivar cuenta', 'Reactivate account') }}
                                            </button>
                                        </template>
                                        <template v-else>
                                            <span class="text-gray-400 italic">{{ tr('Sin acciones', 'No actions') }}</span>
                                        </template>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </template>
                    <template v-else>
                        <table class="w-full text-sm border rounded overflow-hidden">
                            <thead class="bg-gray-100">
                                <tr>
                                    <th class="p-2 text-left">{{ tr('Nombre', 'Name') }}</th>
                                    <th class="p-2 text-left">Email</th>
                                    <th class="p-2 text-left">{{ tr('Rol', 'Role') }}</th>
                                    <th class="p-2 text-left">{{ tr('Acciones', 'Actions') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="u in filteredPendingUsers" :key="u.id" class="border-t">
                                    <td class="p-2 text-xs">{{ u.name }}</td>
                                    <td class="p-2 text-xs">{{ u.email }}</td>
                                    <td class="p-2 text-xs capitalize">{{ u.profile_type.replace('_',' ') }}</td>
                                    <td class="p-2 text-xs space-x-2">
                                        <button class="text-green-700" @click="approvePending(u.id)">{{ tr('Aprobar', 'Approve') }}</button>
                                        <button class="text-red-600" @click="rejectPending(u.id)">{{ tr('Rechazar', 'Reject') }}</button>
                                    </td>
                                </tr>
                                <tr v-if="filteredPendingUsers.length === 0">
                                    <td colspan="4" class="p-3 text-center text-gray-500">{{ tr('No hay solicitudes pendientes.', 'There are no pending requests.') }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </template>
                </div>

            </div>
        </div>
        <UpdatePasswordModal
            v-if="showPasswordModal && changePasswordUserId"
            :show="showPasswordModal"
            :user-id="changePasswordUserId"
            @close="showPasswordModal = false"
            @updated="showToast(tr('Contrasena actualizada correctamente', 'Password updated successfully'))"
        />

        <CreateStaffModal :show="createStaffModalVisible" :user="selectedUserForStaff" :club="selectedClub"
            :club-classes="clubClasses" :editing-staff="staffToEdit" @close="closeModal"
            @submitted="fetchStaff(selectedClub.id)" />

        <div v-if="tempStaffModalVisible" class="fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-50">
            <div class="w-full max-w-lg rounded-lg bg-white p-6 shadow-xl">
                <div class="mb-4 flex items-center justify-between">
                    <h2 class="text-lg font-bold">{{ tr('Crear perfil de staff', 'Create Staff Profile') }}</h2>
                    <button @click="closeTempStaffModal" class="text-xl font-bold text-red-500 hover:text-red-700">
                        &times;
                    </button>
                </div>
                <form @submit.prevent="saveTempStaff" class="space-y-4">
                    <div>
                        <label class="mb-1 block text-sm font-medium text-gray-700">{{ tr('Nombre', 'Name') }}</label>
                        <input v-model="tempStaffForm.staff_name" type="text" class="w-full rounded border p-2" required />
                    </div>
                    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                        <div>
                            <label class="mb-1 block text-sm font-medium text-gray-700">{{ tr('Fecha de nacimiento', 'Date of birth') }}</label>
                            <input v-model="tempStaffForm.staff_dob" type="date" class="w-full rounded border p-2" />
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium text-gray-700">{{ tr('Edad', 'Age') }}</label>
                            <input v-model="tempStaffForm.staff_age" type="number" min="0" class="w-full rounded border p-2" />
                        </div>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-gray-700">Email</label>
                        <input v-model="tempStaffForm.staff_email" type="email" class="w-full rounded border p-2" />
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-gray-700">{{ tr('Teléfono', 'Phone') }}</label>
                        <input v-model="tempStaffForm.staff_phone" type="text" class="w-full rounded border p-2" />
                    </div>
                    <div class="flex justify-end gap-2">
                        <button type="button" @click="closeTempStaffModal" class="rounded border px-4 py-2 text-gray-700">
                            {{ tr('Cancelar', 'Cancel') }}
                        </button>
                        <button type="submit" class="rounded bg-green-600 px-4 py-2 text-white hover:bg-green-700">
                            {{ tr('Guardar', 'Save') }}
                        </button>
                    </div>
                </form>
            </div>
        </div>

    </PathfinderLayout>
</template>
