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
import UpdatePasswordModal from "@/Components/ChangePassword.vue";
import {
    fetchClubsByUserId,
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
    fetchTempStaffPathfinder,
    createTempStaffPathfinder
} from '@/Services/api'
import axios from 'axios'
import { usePage } from '@inertiajs/vue3';

const page = usePage();
const subRoles = page.props.sub_roles;


// ✅ Auth & general utilities
const { user } = useAuth()
const churchId = computed(() => user.value?.church_id || null)
const userId = computed(() => user.value?.id || null)
const changePasswordUserId = ref(null)
const club_name = computed(() => user.value?.clubs[0]?.club_name || '')
const { toast, showToast } = useGeneral()
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

// ✅ Create staff eligibility map
const createStaffMap = computed(() => {
    const map = {}
    sub_roles.value.forEach(user => {
        map[user.id] = user.create_staff === true
    })
    return map
})

// ✅ Filtered lists
const userClubId = computed(() => user.value?.club_id || null)

const filteredUsers = computed(() =>
    sub_roles.value.filter(user => {
        if (activeTab.value === 'pending') return false
        if (activeTab.value === 'parents') return false
        const targetStatus = activeTab.value === 'active' ? 'active' : 'deleted'
        if (user.profile_type === 'parent') return false
        return user.status === targetStatus && (!userClubId.value || String(user.club_id) === String(userClubId.value))
    })
)

const filteredPendingUsers = computed(() =>
    pendingUsers.value.filter(u => !userClubId.value || String(u.club_id) === String(userClubId.value))
)
const filteredPendingStaff = computed(() =>
    pendingStaff.value.filter(u => !userClubId.value || String(u.club_id) === String(userClubId.value))
)

const displayedStaff = computed(() => {
    if (selectedClub.value?.club_type === 'pathfinders') {
        const baseStaff = staff.value.filter(p => p.type !== 'temp_pathfinder')
        const tempMapped = tempStaff.value.map(ts => {
            const staffRecord = staff.value.find(s =>
                s.type === 'temp_pathfinder' &&
                (String(s.id_data) === String(ts.id) || (s.user_id && s.user_id === ts.user_id))
            )
            const staffId = staffRecord?.id ?? null
            return {
                id: staffId ?? `temp-${ts.id}`,
                staff_id: staffId ?? null,
                name: ts.staff_name,
                dob: ts.staff_dob,
                staff_dob: ts.staff_dob,
                type: 'temp_pathfinder',
                address: ts.address || '—',
                cell_phone: ts.staff_phone,
                email: ts.staff_email,
                status: staffRecord?.status ?? 'active',
                assigned_classes: staffRecord?.assigned_classes ?? [],
                class_names: staffRecord?.class_names ?? [],
                assigned_class: staffRecord?.assigned_class ?? null,
                club_id: ts.club_id,
                user_id: staffRecord?.user_id ?? ts.user_id ?? null,
                id_data: ts.id,
            }
        })
        return [...baseStaff, ...tempMapped]
    }
    return staff.value
})

const filteredStaff = computed(() =>
    displayedStaff.value.filter(person => person.status === activeStaffTab.value)
)
const availableClasses = computed(() => {
    if (!selectedClub.value) return []
    const cls = clubClasses.value.filter(c => c.club_id === selectedClub.value.id || !c.club_id)
    return cls
})

const classDisplay = (person) => {
    if (person.class_names?.length) return person.class_names.join(', ')
    if (person.assigned_class) {
        const match = clubClasses.value.find(c => String(c.id) === String(person.assigned_class))
        if (match) return match.class_name
    }
    return '—'
}

const dobDisplay = (person) => {
    console.log(person);
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
        const data = await fetchClubsByUserId(user.value.id)
        clubs.value = Array.isArray(data) ? data : []
        showToast('Clubes cargados')
    } catch (error) {
        console.error('Failed to fetch clubs:', error)
        showToast('Error al cargar clubes', 'error')
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
            if (person.assigned_class) {
                assignedClassChanges.value[person.id] = person.assigned_class
            } else if (person.class_names?.length === 1) {
                const match = clubClasses.value.find(c => c.class_name === person.class_names[0])
                if (match) assignedClassChanges.value[person.id] = match.id
            }
        })
        if (selectedClub.value?.club_type === 'pathfinders') {
            await loadTempStaff(clubId)
        } else {
            tempStaff.value = []
        }
        showToast('Personal cargado')
    } catch (error) {
        console.error('Failed to fetch staff:', error)
        showToast('Error al cargar personal', 'error')
    }
}
const saveAssignedClass = async (person) => {
    const newClassId = assignedClassChanges.value[person.id]
    if (!newClassId) return
    const staffId = Number(person.staff_id ?? person.id)
    const classId = Number(newClassId)
    if (!Number.isInteger(staffId)) {
        showToast('No se pudo asignar la clase. Falta un registro de personal valido.', 'error')
        return
    }
    if (!Number.isInteger(classId)) {
        showToast('Selecciona una clase valida.', 'error')
        return
    }
    try {
        isUpdatingClass.value[person.id] = true
        await updateStaffAssignedClass(staffId, classId)
        showToast('Clase actualizada')
        await fetchStaff(person.club_id)
    } catch (err) {
        console.error('Failed to update class', err)
        showToast('Error al actualizar la clase', 'error')
    } finally {
        isUpdatingClass.value[person.id] = false
    }
}

// ✅ Modals
const openStaffForm = (user) => {
    selectedUserForStaff.value = user
    selectedUserForStaff.value.club_name = club_name.value
    createStaffModalVisible.value = true
}

// ✅ Account management
const updateStaffAccount = async (staff, status_code) => {
    const action = status_code === 301 ? 'desactivar' : 'reactivar'
    if (!confirm(`¿Seguro que deseas ${action} al personal ${staff.name}?`)) return

    try {
        await updateStaffStatus(staff.id, status_code)
        showToast(`Personal ${action === 'desactivar' ? 'desactivado' : 'reactivado'}`)
        fetchStaff(staff.club_id)
    } catch (error) {
        console.error('Failed to update staff status:', error)
        showToast(`No se pudo ${action} el personal`, 'error')
    }
}

const updateStaffUserAccount = async (user, status_code) => {
    const action = status_code === 301 ? 'desactivar' : 'reactivar'
    if (!confirm(`¿Seguro que deseas ${action} la cuenta de ${user.name}?`)) return

    try {
        await updateUserStatus(user.id, status_code)
        showToast(`Usuario ${action === 'desactivar' ? 'desactivado' : 'reactivado'}`)
        fetchStaff(user.club_id)
    } catch (error) {
        console.error('Failed to update user status:', error)
        showToast(`No se pudo ${action} el usuario`, 'error')
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
        showToast('Usuario creado correctamente')
        person.create_user = false
        fetchStaff(person.club_id,churchId.value)
    } catch (err) {
        console.error('Create user error:', err)
        showToast('No se pudo crear el usuario', 'error')
    }
}

const linkToClubUsers = async (person) => {
    if (!person.user_id) {
        showToast('No hay usuario vinculado a este personal (por correo).', 'error')
        return
    }
    try {
        await linkStaffToClubUser(person.id)
        clubUserIds.value.add(person.user_id)
        showToast('Personal vinculado al acceso del club')
    } catch (err) {
        console.error('Failed to link staff to club users', err)
        showToast('No se pudo vincular el personal', 'error')
    }
}

// ✅ Bulk actions
const handleBulkAction = async (action) => {
    if (selectedStaffIds.value.size === 0) {
        showToast('No hay personal seleccionado.')
        return
    }

    const ids = Array.from(selectedStaffIds.value)
    const isReactivate = action === 'reactivate'
    const statusCode = isReactivate ? 423 : 301
    const confirmText = isReactivate
        ? '¿Seguro que deseas reactivar el personal seleccionado?'
        : '¿Seguro que deseas desactivar el personal seleccionado?'

    if (!confirm(confirmText)) return

    try {
        for (const id of ids) {
            await updateStaffStatus(id, statusCode)
        }
        showToast(`Personal ${isReactivate ? 'reactivado' : 'desactivado'}`)
        await fetchStaff(selectedClub.value.id)
        selectedStaffIds.value.clear()
        selectAll.value = false
    } catch (error) {
        console.error('Bulk action failed:', error)
        toast.error('Actualizacion masiva fallida')
    }
}

// ✅ Download
const downloadWord = (staffId) => {
    window.open(`/staff/${staffId}/export-word`, '_blank')
}

const approvePending = async (userId) => {
    try {
        await axios.post(route('club.users.approve', userId))
        showToast('Usuario aprobado')
        fetchStaff(selectedClub.value.id, churchId.value)
    } catch (err) {
        console.error('Approve failed', err)
        showToast('No se pudo aprobar el usuario', 'error')
    }
}

const rejectPending = async (userId) => {
    try {
        await updateUserStatus(userId, 301)
        showToast('Usuario rechazado')
        fetchStaff(selectedClub.value.id, churchId.value)
    } catch (err) {
        console.error('Reject failed', err)
        showToast('No se pudo rechazar el usuario', 'error')
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
const approvePendingStaff = async (staffRow) => {
    try {
        await approveStaff(staffRow.id)
        showToast('Personal aprobado')
        fetchStaff(selectedClub.value.id, churchId.value)
    } catch (err) {
        console.error('Approve staff failed', err)
        showToast('No se pudo aprobar el personal', 'error')
    }
}

const rejectPendingStaff = async (staffRow) => {
    try {
        await rejectStaff(staffRow.id)
        showToast('Personal rechazado')
        fetchStaff(selectedClub.value.id, churchId.value)
    } catch (err) {
        console.error('Reject staff failed', err)
        showToast('No se pudo rechazar el personal', 'error')
    }
}

const loadTempStaff = async (clubId) => {
    try {
        tempStaff.value = await fetchTempStaffPathfinder(clubId)
    } catch (err) {
        console.error('Failed to load temp staff', err)
        tempStaff.value = []
    }
}

const saveTempStaff = async () => {
    try {
        tempStaffForm.value.club_id = selectedClub.value?.id || ''
        if (!tempStaffForm.value.club_id) {
            showToast('Selecciona un club primero', 'error')
            return
        }
        await createTempStaffPathfinder(tempStaffForm.value)
        showToast('Personal temporal guardado', 'success')
        await loadTempStaff(tempStaffForm.value.club_id)
        tempStaffForm.value = {
            club_id: selectedClub.value?.id || '',
            staff_name: '',
            staff_dob: '',
            staff_age: '',
            staff_email: '',
            staff_phone: '',
        }
        // Refresh entire staff view to reflect new temp staff everywhere
        await fetchStaff(selectedClub.value.id, churchId.value)
    } catch (err) {
        console.error('Failed to save temp staff', err)
        showToast('No se pudo guardar el personal temporal', 'error')
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
</script>


<template>
    <PathfinderLayout>
        <div class="p-8">
            <h1 class="text-xl font-bold mb-4">Personal</h1>
            <div class="max-w-xl mb-6">
                <label class="block mb-1 font-medium text-gray-700">Selecciona un club</label>
                <select v-model="selectedClub"
                    @change="() => { if (selectedClub) { fetchStaff(selectedClub.id, churchId) } }"
                    class="w-full p-2 border rounded">
                    <option disabled value="">-- Selecciona un club --</option>
                    <option v-for="club in clubs" :key="club.id" :value="club">
                        {{ club.club_name }} ({{ club.club_type }})
                    </option>
                </select><br><br>
                <button v-if="selectedClub && selectedClub.club_type === 'adventurers'"
                    class="bg-green-600 text-white px-4 py-2 rounded hover:bg-green-700" @click="openStaffForm(user)">
                    Crear personal</button>
                <p v-else-if="selectedClub" class="text-sm text-gray-600">
                    El modulo de personal solo esta disponible para clubes de Aventureros.
                </p>

            </div>

                <div v-if="selectedClub" class="max-w-5xl mx-auto">
                <div v-if="filteredPendingStaff.length" class="mb-6 border rounded p-4 bg-amber-50">
                    <h2 class="font-semibold text-amber-800 mb-2">Aprobaciones de personal pendientes</h2>
                    <div class="space-y-2">
                        <div v-for="person in filteredPendingStaff" :key="person.id" class="flex items-center justify-between bg-white border rounded px-3 py-2">
                            <div>
                                <div class="font-medium text-gray-900">{{ person.name || 'Sin nombre' }}</div>
                                <div class="text-sm text-gray-600">{{ person.email || 'Sin correo' }}</div>
                            </div>
                            <div class="flex gap-2">
                                <button @click="approvePendingStaff(person)" class="px-3 py-1 text-sm rounded bg-green-600 text-white hover:bg-green-700">Aprobar</button>
                                <button @click="rejectPendingStaff(person)" class="px-3 py-1 text-sm rounded bg-red-600 text-white hover:bg-red-700">Rechazar</button>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="mb-4 flex space-x-4 border-b pb-2">
                    <button @click="activeStaffTab = 'active'"
                        :class="activeStaffTab === 'active' ? 'font-bold border-b-2 border-blue-600' : 'text-gray-500'">
                        Personal activo
                    </button>
                    <button
                        v-if="staff.some(person => person.status === 'deleted') && user.profile_type === 'club_director'"
                        @click="activeStaffTab = 'deleted'"
                        :class="activeStaffTab === 'deleted' ? 'font-bold border-b-2 border-red-600' : 'text-gray-500'">
                        Personal inactivo
                    </button>
                </div>
                <div class="flex items-center justify-between mb-4">
                    <div class="flex items-center gap-4">
                        <label class="inline-flex items-center">
                            <input type="checkbox" v-model="selectAll" @change="toggleSelectAll" class="mr-2" />
                            <span>Seleccionar todo</span>
                        </label>
                        <select v-if="selectedStaffIds.size > 0"
                            @change="e => handleBulkAction(e.target.value, 'staff')"
                            class="border p-2 px-4 rounded w-60 text-sm">
                            <option value="" disabled selected>Acciones masivas</option>

                            <option :value="activeStaffTab === 'deleted' ? 'reactivate' : 'delete'">
                                {{ activeStaffTab === 'deleted' ? 'Reactivar seleccionados' : 'Desactivar seleccionados' }}
                            </option>

                            <option value="download">Descargar formularios</option>
                        </select>
                    </div>
                    <span class="text-sm text-gray-600">{{ selectedStaffIds.size }} seleccionados</span>
                </div>

                <table class="w-full border rounded overflow-hidden text-sm">
                    <thead class="bg-gray-200">
                        <tr>
                            <th class="p-2 text-left"></th>
                            <th class="p-2 text-left">Nombre</th>
                            <th class="p-2 text-left">Fecha de nacimiento</th>
                            <th class="p-2 text-left">Direccion</th>
                            <!-- <th class="p-2 text-left">Class</th> -->
                            <th class="p-2 text-left">Celular</th>
                            <th class="p-2 text-left w-16">Email</th>
                            <th class="p-2 text-left">Estado</th>
                            <th class="p-2 text-left">Acciones</th>
                            <th class="p-2 text-left">Clases asignadas</th>

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
                                        title="Ver detalles">
                                        <component :is="expandedRows.has(person.id) ? MinusIcon : PlusIcon"
                                            class="w-4 h-4 inline" />
                                    </button>

                                    <!-- Create User -->
                                <button v-if="person.create_user" @click="createUser(person)"
                                    class="text-orange-600" title="Crear usuario">
                                    <UserPlusIcon class="w-4 h-4 inline" />
                                </button>
                                <button
                                    v-else-if="person.user_id && !clubUserIds.has(person.user_id)"
                                    @click="linkToClubUsers(person)"
                                    class="text-amber-600"
                                    title="Vincular acceso al club"
                                >
                                    <UserPlusIcon class="w-4 h-4 inline" />
                                </button>

                                <!-- Download Word Form -->
                                <button
                                    v-if="person.type !== 'temp_pathfinder'"
                                    @click="downloadWord(person.id)"
                                    class="text-blue-600"
                                    title="Descargar formulario Word">
                                        <DocumentArrowDownIcon class="w-4 h-4 inline" />
                                    </button>

                                    <!-- Delete or Reactivate -->
                                    <button v-if="person.status === 'active'" @click="updateStaffAccount(person, 301)"
                                        class="text-red-600" title="Eliminar personal">
                                        <TrashIcon class="w-4 h-4 inline" />
                                    </button>
                                    <button v-else @click="() => updateStaffAccount(person, 423)" class="text-gray-600"
                                        title="Reactivar personal">
                                        <ArrowPathIcon class="w-4 h-4 inline" />
                                    </button>
                                    <button
                                        v-if="person.type !== 'temp_pathfinder'"
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
                                            <option disabled value="">Seleccionar clase</option>
                                            <option v-for="cls in availableClasses" :key="cls.id" :value="cls.id">
                                                {{ cls.class_name }}
                                            </option>
                                        </select>
                                        <button
                                            @click="() => saveAssignedClass(person)"
                                            :disabled="!assignedClassChanges[person.id] || isUpdatingClass[person.id]"
                                            class="px-2 py-1 bg-blue-600 text-white rounded text-xs hover:bg-blue-700"
                                        >
                                            {{ isUpdatingClass[person.id] ? 'Guardando...' : 'Guardar' }}
                                        </button>
                                    </div>
                                </td>
                            </tr>

                            <tr v-if="expandedRows.has(person.id)" class="bg-gray-50 border-t">
                                <td colspan="10" class="p-4 text-gray-700">
                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                        <div><strong>Ciudad/Estado/Codigo postal:</strong> {{ person.city }}, {{ person.state }} {{
                                            person.zip }}</div>
                                        <div><strong>Nombre del club:</strong> {{ person.club_name }}</div>
                                        <div><strong>Nombre de la iglesia:</strong> {{ person.church_name }}</div>

                                        <div><strong>Limitacion de salud:</strong> {{ person.has_health_limitation ? 'Si'
                                            : 'No' }}</div>
                                        <div v-if="person.has_health_limitation"><strong>Detalles de la limitacion:</strong> {{
                                            person.health_limitation_description }}</div>

                                        <!-- Experience -->
                                        <div>
                                            <strong>Experiencia:</strong>
                                            <ul class="list-disc list-inside ml-4" v-if="person.experiences?.length">
                                                <li v-for="(exp, idx) in person.experiences" :key="idx">
                                                    {{ exp.position }} en {{ exp.organization }} ({{ exp.date }})
                                                </li>
                                            </ul>
                                            <div v-else>No hay experiencia registrada.</div>
                                        </div>

                                        <!-- Awards -->
                                        <div>
                                            <strong>Premios/Instruccion:</strong>
                                            <ul class="list-disc list-inside ml-4"
                                                v-if="person.award_instruction_abilities?.length">
                                                <li v-for="(award, index) in person.award_instruction_abilities"
                                                    :key="index">
                                                    {{ award.name }} —
                                                    <span v-if="award.level === 'T'">Capaz de ensenar</span>
                                                    <span v-else-if="award.level === 'A'">Puede asistir</span>
                                                    <span v-else-if="award.level === 'I'">Interesado en aprender</span>
                                                    <span v-else>{{ award.level }}</span>
                                                </li>
                                            </ul>
                                            <div v-else>No hay premios registrados.</div>
                                        </div>

                                        <!-- Unlawful Conduct -->
                                        <div><strong>Conducta ilegal:</strong> {{ person.unlawful_sexual_conduct ===
                                            'yes' ? 'Si' : 'No' }}</div>
                                        <div v-if="person.unlawful_sexual_conduct === 'yes'">
                                            <strong>Registros de conducta:</strong>
                                            <ul class="list-disc list-inside ml-4"
                                                v-if="person.unlawful_sexual_conduct_records?.length">
                                                <li v-for="(record, idx) in person.unlawful_sexual_conduct_records"
                                                    :key="idx">
                                                    {{ record.type || 'N/A' }} — {{ record.date_place || 'Fecha/Lugar desconocidos' }}<br />
                                                    <span class="text-gray-600">Referencia: {{ record.reference || 'N/A'
                                                        }}</span>
                                                </li>
                                            </ul>
                                        </div>

                                        <div><strong>Sterling Volunteer completado:</strong> {{
                                            person.sterling_volunteer_completed ? 'Si' : 'No' }}
                                        </div>

                                        <!-- References -->
                                        <div>
                                            <strong>Referencias:</strong>
                                            <ul class="list-disc pl-5">
                                                <li v-if="person.reference_pastor">Pastor: {{ person.reference_pastor }}
                                                </li>
                                                <li v-if="person.reference_elder">Anciano: {{ person.reference_elder }}
                                                </li>
                                                <li v-if="person.reference_other">Otro: {{ person.reference_other }}
                                                </li>
                                                <li
                                                    v-if="!person.reference_pastor && !person.reference_elder && !person.reference_other">
                                                    No se proporcionaron referencias.</li>
                                            </ul>
                                        </div>

                                        <div><strong>Firmado:</strong> {{ person.applicant_signature }} el {{
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
                            Cuentas activas
                        </button>
                        <button @click="activeTab = 'parents'"
                            :class="activeTab === 'parents' ? 'font-bold border-b-2 border-green-600' : 'text-gray-500'">
                            Cuentas de padres
                        </button>
                        <button
                            v-if="sub_roles.some(user => user.status === 'deleted') && user.profile_type === 'club_director'"
                            @click="activeTab = 'deleted'"
                            :class="activeTab === 'deleted' ? 'font-bold border-b-2 border-red-600' : 'text-gray-500'">
                            Cuentas inactivas
                        </button>
                        <button v-if="filteredPendingUsers.length"
                            @click="activeTab = 'pending'"
                            :class="activeTab === 'pending' ? 'font-bold border-b-2 border-amber-600' : 'text-gray-500'">
                            Solicitudes pendientes
                        </button>
                    </div>

                    <template v-if="activeTab === 'parents'">
                        <table class="w-full text-sm border rounded overflow-hidden">
                            <thead class="bg-gray-100">
                                <tr>
                                    <th class="p-2 text-left">Padre/Madre</th>
                                    <th class="p-2 text-left">Email</th>
                                    <th class="p-2 text-left">Hijos</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-if="!parentAccounts.length">
                                    <td colspan="3" class="p-3 text-center text-gray-500">No se encontraron cuentas de padres.</td>
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
                                                    <div class="text-[11px] text-gray-600">Club: {{
                                                        child.club_name || child.club_id || '—' }}</div>
                                                    <div class="text-[11px] text-gray-600">Tipo: {{
                                                        child.member_type }}</div>
                                                    <div class="text-[11px] text-gray-600">ID de clase: {{
                                                        child.class_id || '—' }}</div>
                                                </div>
                                            </div>
                                            <div v-else class="text-gray-500 text-xs">No hay hijos vinculados.</div>
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
                                    <th class="p-2 text-left">Nombre</th>
                                    <th class="p-2 text-left">Email</th>
                                    <th class="p-2 text-left">Rol</th>
                                    <th class="p-2 text-left">Subrol</th>
                                    <th class="p-2 text-left">Iglesia</th>
                                    <th class="p-2 text-left">Estado</th>
                                    <th class="p-2 text-left">Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="user in filteredUsers" :key="user.id" class="border-t">
                                    <td class="p-2 text-xs">{{ user.name }}</td>
                                    <td class="p-2 text-xs">{{ user.email }}</td>
                                    <td class="p-2 text-xs">{{ user.profile_type }}</td>


                                    <td class="p-2 capitalize text-xs">
                                        <select id="sub_role" class="border p-1 rounded text-xs" v-model="user.sub_role">
                                            <option value="">-- Seleccionar subrol --</option>
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
                                                    Cambiar contraseña
                                                </button>

                                                <button @click="updateStaffUserAccount(user, 301)"
                                                    class="text-red-600 hover:underline" title="Eliminar registro">
                                                    <TrashIcon class="w-4 h-4 inline" />
                                                </button>

                                            <button v-if="createStaffMap[user.id] && selectedClub?.club_type === 'adventurers'"
                                                class="text-green-600 hover:underline" @click="openStaffForm(user)"
                                                title="Agregar usuario como personal">
                                                <UserPlusIcon class="w-5 h-5 text-green-600" />
                                            </button>
                                            </div>
                                        </template>
                                        <template v-else-if="user.status !== 'active'">
                                            <button @click="updateStaffUserAccount(user, 423)"
                                                class="text-blue-600 hover:underline">
                                                Reactivar cuenta
                                            </button>
                                        </template>
                                        <template v-else>
                                            <span class="text-gray-400 italic">Sin acciones</span>
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
                                    <th class="p-2 text-left">Nombre</th>
                                    <th class="p-2 text-left">Email</th>
                                    <th class="p-2 text-left">Rol</th>
                                    <th class="p-2 text-left">Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="u in filteredPendingUsers" :key="u.id" class="border-t">
                                    <td class="p-2 text-xs">{{ u.name }}</td>
                                    <td class="p-2 text-xs">{{ u.email }}</td>
                                    <td class="p-2 text-xs capitalize">{{ u.profile_type.replace('_',' ') }}</td>
                                    <td class="p-2 text-xs space-x-2">
                                        <button class="text-green-700" @click="approvePending(u.id)">Aprobar</button>
                                        <button class="text-red-600" @click="rejectPending(u.id)">Rechazar</button>
                                    </td>
                                </tr>
                                <tr v-if="filteredPendingUsers.length === 0">
                                    <td colspan="4" class="p-3 text-center text-gray-500">No hay solicitudes pendientes.</td>
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
            @updated="showToast('Contrasena actualizada correctamente')"
        />

        <CreateStaffModal :show="createStaffModalVisible" :user="selectedUserForStaff" :club="selectedClub"
            :club-classes="clubClasses" :editing-staff="staffToEdit" @close="closeModal"
            @submitted="fetchStaff(selectedClub.id)" />

    </PathfinderLayout>
</template>
