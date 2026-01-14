<script setup>
import PathfinderLayout from '@/Layouts/PathfinderLayout.vue'
import { ref, computed, onMounted, nextTick } from 'vue'
import { useForm } from '@inertiajs/vue3'
import MemberRegistrationModal from '@/Components/MemberRegistrationModal.vue'
import DeleteMemberModal from '@/Components/DeleteMemberModal.vue'
import { 
    PlusIcon,
    MinusIcon,
    UserPlusIcon,
    DocumentArrowDownIcon,
    TrashIcon,
    ArrowPathIcon 
} from '@heroicons/vue/24/solid'
import { useAuth } from '@/Composables/useAuth'
import { useGeneral } from '@/Composables/useGeneral'
import { formatDate } from '@/Helpers/general'
import {
    fetchClubsByIds,
    fetchMembersByClub,
    fetchClubClasses,
    assignMemberToClass,
    undoClassAssignment,
    deleteMemberById,
    bulkDeleteMembers,
    downloadMemberZip,
    fetchTempMembersPathfinder,
    createTempMemberPathfinder,
} from '@/Services/api'

// ✅ Auth context
const { user, userClubIds } = useAuth()
const { toast, showToast } = useGeneral()

// State
const clubs = ref([])
const selectedClub = ref(null)
const members = ref([])
const clubClasses = ref([])
const expandedRows = ref(new Set())
const showRegistrationForm = ref(false)
const registrationFormSection = ref(null)
const showDeleteModal = ref(false)
const deletingMember = ref(null)
const selectedMemberIds = ref(new Set())
const selectAll = ref(false)
const selectedTab = ref('members')
const tempMembers = ref([])
const tempMemberForm = ref({
    club_id: '',
    nombre: '',
    dob: '',
    phone: '',
    email: '',
    father_name: '',
    father_phone: '',
})

const activeTabClass = 'border-b-2 border-blue-600 text-blue-600 font-semibold pb-2'
const inactiveTabClass = 'text-gray-500 hover:text-gray-700 pb-2'

// Member registration form
const memberForm = useForm({
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
    signature: ''
})

// Fetch clubs
const fetchClubs = async () => {
    try {
        clubs.value = await fetchClubsByIds(user.value.clubs.map(club => club.id))
    } catch (error) {
        console.error('Failed to fetch clubs:', error)
        showToast('Error al cargar clubes', 'error')
    }
}

// Fetch members
const fetchMembers = async (clubId) => {
    try {
        const data = await fetchMembersByClub(clubId)
        if (Array.isArray(data) && data.length > 0) {
            members.value = data
            showToast('Miembros cargados', 'success')
        } else {
            members.value = []
            alert('No se encontraron miembros para este club.')
        }
    } catch (error) {
        console.error('Failed to fetch members:', error)
        showToast('Error al cargar miembros', 'error')
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

// On club selection
const onClubChange = async () => {
    if (selectedClub.value) {
        memberForm.club_id = selectedClub.value.id
        memberForm.club_name = selectedClub.value.club_name
        memberForm.director_name = selectedClub.value.director_name
        memberForm.church_name = selectedClub.value.church_name

        await fetchMembers(selectedClub.value.id)
        await fetchClasses(selectedClub.value.id)
        if (selectedClub.value.club_type === 'pathfinders') {
            await loadTempMembers(selectedClub.value.id)
        } else {
            tempMembers.value = []
        }


    }
}

const loadTempMembers = async (clubId) => {
    try {
        tempMembers.value = await fetchTempMembersPathfinder(clubId)
    } catch (err) {
        console.error('Failed to load temp members', err)
        tempMembers.value = []
    }
}

const saveTempMember = async () => {
    try {
        tempMemberForm.value.club_id = selectedClub.value?.id || ''
        if (!tempMemberForm.value.club_id) {
            showToast('Selecciona un club primero', 'error')
            return
        }
        await createTempMemberPathfinder(tempMemberForm.value)
        showToast('Miembro temporal guardado', 'success')
        await loadTempMembers(tempMemberForm.value.club_id)
        tempMemberForm.value = {
            club_id: selectedClub.value?.id || '',
            nombre: '',
            dob: '',
            phone: '',
            email: '',
            father_name: '',
            father_phone: '',
        }
    } catch (err) {
        console.error('Failed to save temp member', err)
        showToast('No se pudo guardar el miembro temporal', 'error')
    }
}

// Delete member
const deleteMember = (member) => {
    deletingMember.value = member
    showDeleteModal.value = true
}

const handleMemberDelete = async ({ id, notes }) => {
    try {
        await deleteMemberById(id, notes)
        await fetchMembers(selectedClub.value.id)
        showToast('Miembro eliminado correctamente.', 'success')
        showDeleteModal.value = false
        deletingMember.value = null
    } catch (err) {
        console.error('Failed to delete:', err)
        showToast('Error al eliminar el miembro.', 'error')
    }
}

// Bulk delete or download
const handleBulkAction = async (action, type = null) => {
    if (selectedMemberIds.value.size === 0) {
        alert('No hay miembros seleccionados.')
        return
    }

    const ids = Array.from(selectedMemberIds.value)

    if (action === 'delete') {
        const confirmed = window.confirm('¿Seguro que deseas eliminar los miembros seleccionados?')
        if (!confirmed) return

        try {
            await bulkDeleteMembers(ids)
            await fetchMembers(selectedClub.value.id)
            selectedMemberIds.value.clear()
            selectAll.value = false
            showToast('Miembros seleccionados eliminados.', 'success')
        } catch (error) {
            console.error('Bulk deletion failed:', error)
            showToast('Error al eliminar miembros seleccionados.', 'error')
        }
    }

    if (action === 'download') {
        try {
            await downloadMemberZip(ids, type)
        } catch (err) {
            console.error(`Failed to download ${type} ZIP:`, err)
        }
    }
}

// Class assignment
const assignToClass = async (member) => {
    if (!member.assigned_class) return
    try {
        const memberId = member.member_id || member.id
        await assignMemberToClass({ memberId, classId: member.assigned_class })
        showToast(`${member.applicant_name} asignado a la clase`, 'success')
        await fetchMembers(selectedClub.value.id)
    } catch (error) {
        console.error('Assignment failed:', error)
        showToast(`No se pudo asignar a ${member.applicant_name}`, 'error')
    }
}

const undoAssignment = async (member) => {
    try {
        const memberId = member.member_id || member.id
        var resp = await undoClassAssignment(memberId)
        showToast(`Se deshizo la ultima asignacion de ${member.applicant_name}`, 'success')
        await fetchMembers(selectedClub.value.id)
    } catch (error) {
        console.error('Undo failed:', error)
        showToast(`No se pudo deshacer la asignacion de ${member.applicant_name}`, 'error')
    }
}

// Row UI actions
const toggleExpanded = (id) => {
    expandedRows.value.has(id) ? expandedRows.value.delete(id) : expandedRows.value.add(id)
}

const toggleSelectAll = () => {
    selectAll.value
        ? (selectedMemberIds.value = new Set(members.value.map(m => m.id)))
        : selectedMemberIds.value.clear()
}

const toggleSelectMember = (id) => {
    selectedMemberIds.value.has(id)
        ? selectedMemberIds.value.delete(id)
        : selectedMemberIds.value.add(id)
}

// Misc
const downloadWord = (memberId) => {
    window.open(`/members/${memberId}/export-word`, '_blank')
}

const toggleRegistrationForm = async () => {
    showRegistrationForm.value = !showRegistrationForm.value
    if (showRegistrationForm.value) {
        await nextTick()
        registrationFormSection.value?.scrollIntoView({ behavior: 'smooth', block: 'start' })
    }
}

// Computed filters
const displayAge = (age) => {
    if (age === null || age === undefined) return '—'
    const n = Number(age)
    if (Number.isNaN(n) || n < 0) return '—'
    return Math.floor(n)
}

const unassignedMembers = computed(() =>
    members.value.filter(member =>
        !member.class_assignments ||
        member.class_assignments.length === 0 ||
        member.class_assignments.every(assignment => assignment.active === false || assignment.active === 0)
    )
)
const membersInClass = (classId) => {
    return members.value.filter(member =>
        Array.isArray(member.class_assignments) &&
        member.class_assignments.some(
            a => a.active && a.club_class_id === classId
        )
    )
}

const classOptionsExcluding = (currentClassOrder) => {
    const filtered = clubClasses.value.filter(c => c.class_order > currentClassOrder);
    if (filtered.length === 0) {
        return [{ id: '', class_name: 'Sin clases disponibles' }];
    }
    return filtered;
};

onMounted(fetchClubs)
</script>



<style scoped>
.fade-enter-active,
.fade-leave-active {
    transition: opacity 0.5s;
}

.fade-enter-from,
.fade-leave-to {
    opacity: 0;
}
</style>
<template>
    <PathfinderLayout>
        <div class="p-8">
            <h1 class="text-xl font-bold mb-4">Miembros</h1>

            <!-- Tabs -->
            <div class="mb-4 border-b">
                <nav class="-mb-px flex space-x-6">
                    <button :class="selectedTab === 'members' ? activeTabClass : inactiveTabClass"
                        @click="selectedTab = 'members'">
                        Miembros
                    </button>
                    <button :class="selectedTab === 'classes' ? activeTabClass : inactiveTabClass"
                        @click="selectedTab = 'classes'">
                        Resumen de clases
                    </button>
                </nav>
            </div>

            <!-- Club Selector -->
            <div class="max-w-xl mb-6">
                <label class="block mb-1 font-medium text-gray-700">Selecciona un club</label>
                <select v-model="selectedClub" @change="onClubChange" class="w-full p-2 border rounded">
                    <option disabled value="">-- Selecciona un club --</option>
                    <option v-for="club in clubs" :key="club.id" :value="club">
                        {{ club.club_name }} ({{ club.club_type }})
                    </option>
                </select>
            </div>

            <!-- Pathfinder temp members -->
            <div v-if="selectedTab === 'members' && selectedClub && selectedClub.club_type === 'pathfinders'" class="mb-8 border rounded p-4 bg-amber-50">
                <h2 class="font-semibold text-amber-800 mb-3">Miembros temporales de Conquistadores</h2>
                <div class="grid md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Nombre</label>
                        <input v-model="tempMemberForm.nombre" type="text" class="w-full border rounded p-2" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Fecha de nacimiento</label>
                        <input v-model="tempMemberForm.dob" type="date" class="w-full border rounded p-2" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Telefono</label>
                        <input v-model="tempMemberForm.phone" type="text" class="w-full border rounded p-2" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Correo del padre/madre</label>
                        <input v-model="tempMemberForm.email" type="email" class="w-full border rounded p-2" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Nombre del padre/madre</label>
                        <input v-model="tempMemberForm.father_name" type="text" class="w-full border rounded p-2" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Telefono del padre/madre</label>
                        <input v-model="tempMemberForm.father_phone" type="text" class="w-full border rounded p-2" />
                    </div>
                </div>
                <div class="mt-3">
                    <button @click="saveTempMember" class="px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700">Guardar miembro temporal</button>
                </div>

                <div class="mt-4 overflow-x-auto">
                    <table class="min-w-full text-sm border">
                        <thead class="bg-amber-100">
                            <tr>
                                <th class="p-2 text-left">Nombre</th>
                                <th class="p-2 text-left">DOB</th>
                                <th class="p-2 text-left">Telefono</th>
                                <th class="p-2 text-left">Email</th>
                                <th class="p-2 text-left">Padre/madre</th>
                                <th class="p-2 text-left">Telefono padre/madre</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="!tempMembers.length">
                                <td colspan="6" class="p-3 text-center text-gray-500">No hay miembros temporales</td>
                            </tr>
                            <tr v-for="tm in tempMembers" :key="tm.id" class="border-t">
                                <td class="p-2">{{ tm.nombre }}</td>
                                <td class="p-2">{{ tm.dob || '—' }}</td>
                                <td class="p-2">{{ tm.phone || '—' }}</td>
                                <td class="p-2">{{ tm.email || '—' }}</td>
                                <td class="p-2">{{ tm.father_name || '—' }}</td>
                                <td class="p-2">{{ tm.father_phone || '—' }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Tab 1: Members Table -->
            <div v-if="selectedTab === 'members' && selectedClub">
                <div class="flex items-center justify-between mb-4">
                    <div class="flex items-center gap-4">
                        <label class="inline-flex items-center">
                            <input type="checkbox" v-model="selectAll" @change="toggleSelectAll" class="mr-2" />
                            <span>Seleccionar todo</span>
                        </label>
                        <select v-if="selectedMemberIds.size > 0"
                            @change="e => handleBulkAction(e.target.value, 'member')"
                            class="border p-2 px-4 rounded w-60 text-sm">
                            <option value="" disabled selected>Acciones masivas</option>
                            <option value="delete">Eliminar seleccionados</option>
                            <option value="download">Descargar formularios</option>
                        </select>
                    </div>
                    <span class="text-sm text-gray-600">{{ selectedMemberIds.size }} seleccionados</span>
                </div>
                <table class="w-full text-sm border rounded overflow-hidden">
                    <thead class="bg-gray-200">
                        <tr>
                            <th class="p-2 text-left"></th>
                            <th class="p-2 text-left">Nombre</th>
                            <th class="p-2 text-left">Direccion</th>
                            <th class="p-2 text-left">Ultima completada</th>
                            <th class="p-2 text-left">Celular del padre</th>
                            <th class="p-2 text-left">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template v-for="member in members" :key="member.id">
                            <!-- Main Row -->
                            <tr class="border-t">
                                <td class="p-2">
                                    <input type="checkbox" :value="member.id"
                                        :checked="selectedMemberIds.has(member.id)"
                                        @change="() => toggleSelectMember(member.id)" />
                                </td>
                                <td class="p-2 font-semibold">{{ member.applicant_name }}</td>
                                <td class="p-2">{{ member.home_address }}</td>
                                <td class="p-2">
                                    <span v-if="Array.isArray(member.investiture_classes)">
                                        {{ member.investiture_classes.join(', ') }}
                                    </span>
                                </td>
                                <td class="p-2">{{ member.parent_cell }}</td>
                                <td class="p-2">
                                    <button class="text-green-600 hover:underline" @click="toggleExpanded(member.id)">
                                        <component
                                        :is="expandedRows.has(member.id) ? MinusIcon : PlusIcon"
                                        class="w-4 h-4 inline"
                                        />
                                    </button> &nbsp;&nbsp;
                                    <button class="text-red-600 hover:underline"
                                        @click="deleteMember(member)">
                                        <TrashIcon class="w-4 h-4 inline" />
                                    </button>
                                    &nbsp;&nbsp;
                                    <button class="text-blue-600 hover:underline"
                                        @click="downloadWord(member.id)">  
                                        <DocumentArrowDownIcon class="w-4 h-4 inline" />
                                    </button>

                                    
                                </td>
                            </tr>

                            <!-- Expandable Child Row -->
                            <tr v-if="expandedRows.has(member.id)" class="bg-gray-50 border-t">
                                <td colspan="6" class="p-4">
                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-gray-700">
                                        <div><strong>Fecha de nacimiento:</strong> {{ member.birthdate ? formatDate(member.birthdate) : '—' }}</div>
                                        <div><strong>Edad:</strong> {{ member.age ?? '—' }}</div>
                                        <div><strong>Grado:</strong> {{ member.grade ?? '—' }}</div>
                                        <div><strong>Direccion postal:</strong> {{ member.mailing_address }}</div>
                                        <div><strong>Numero celular:</strong> {{ member.cell_number }}</div>
                                        <div><strong>Contacto de emergencia:</strong> {{ member.emergency_contact }}</div>
                                        <div><strong>Alergias:</strong> {{ member.allergies }}</div>
                                        <div><strong>Restricciones fisicas:</strong> {{ member.physical_restrictions }}
                                        </div>
                                        <div><strong>Historial de salud:</strong> {{ member.health_history }}</div>
                                        <div><strong>Nombre del padre/madre:</strong> {{ member.parent_name }}</div>
                                        <div><strong>Correo electronico:</strong> {{ member.email_address }}</div>
                                        <div><strong>Firma:</strong> {{ member.signature }}</div>
                                    </div>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
                <div class="mt-6 text-center">
                    <button @click="toggleRegistrationForm"
                        class="bg-green-600 text-white px-4 py-2 rounded hover:bg-green-700">
                        {{ showRegistrationForm ? 'Ocultar formulario' : 'Registrar nuevo miembro' }}
                    </button>
                </div>
            </div>

            <!-- Tab 2: Class Overview -->
            <div v-if="selectedTab === 'classes' && selectedClub">
                <h2 class="text-lg font-semibold mb-4">Resumen de clases</h2>
                <div v-if="clubClasses.length === 0" class="text-gray-600">
                    No se encontraron clases para este club.
                </div>
                <div v-else class="space-y-6">
                    <h2 class="text-lg font-semibold mb-4">Miembros sin asignar</h2>
                    <div v-if="unassignedMembers.length === 0" class="text-gray-600">
                        No hay miembros para asignar
                    </div>
                    <div v-else class="border rounded p-4 bg-gray-100">
                        <table class="w-full border text-sm">
                            <thead class="bg-gray-200">
                                <tr>
                                    <th class="p-2">Nombre</th>
                                    <th class="p-2">Edad</th>
                                    <th class="p-2">Asignar a clase</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="member in unassignedMembers" :key="member.id">
                                    <td class="p-2 text-center">{{ member.applicant_name }}</td>
                                    <td class="p-2 text-center">{{ displayAge(member.age) }}</td>
                                    <td class="p-2 text-center">
                                        <select v-model="member.assigned_class" class="border p-2 rounded">
                                            <option value="" disabled selected>Seleccionar clase</option>
                                            <option v-for="targetClass in clubClasses"
                                                :key="targetClass.id" :value="targetClass.id">
                                                {{ targetClass.class_name }} - {{ targetClass.class_order }}
                                            </option>
                                        </select>
                                        &nbsp;&nbsp;
                                        <button @click="() => assignToClass(member)"
                                            :disabled="!member.assigned_class"
                                            class="px-2 py-1 bg-blue-600 text-white text-xs rounded hover:bg-blue-700">
                                            Asignar
                                        </button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <div v-for="clubClass in clubClasses" :key="clubClass.id" class="border rounded p-4 bg-gray-50">
                        <h3 class="text-md font-bold">
                            {{ clubClass.class_name }} (Orden: {{ clubClass.class_order }})
                        </h3>
                        <p class="text-sm text-gray-700 mb-2" v-if="selectedClub.club_type === 'adventurers'">
                            Personal asignado: {{ clubClass.assigned_staff?.name }}
                        </p>
                        <div v-if="membersInClass(clubClass.id).length === 0" class="text-gray-600">
                            No hay miembros asignados a esta clase.
                        </div>

                        <table v-else class="w-full border text-sm">
                            <thead class="bg-gray-100">
                                <tr>
                                    <th class="p-2">Nombre</th>
                                    <th class="p-2">Edad</th>
                                    <th class="p-2">Mover a clase</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="member in membersInClass(clubClass.id)" :key="member.id">
                                    <td class="p-2 text-center">{{ member.applicant_name }}</td>
                                    <td class="p-2 text-center">{{ displayAge(member.age) }}</td>
                                    <td class="p-2 text-center">
                                        <select v-model="member.assigned_class" class="border p-1 rounded">
                                            <option v-for="targetClass in classOptionsExcluding(clubClass.class_order)"
                                                :key="targetClass.id" :value="targetClass.id">
                                                {{ targetClass.class_name }}
                                            </option>
                                        </select>
                                        &nbsp;&nbsp;
                                        <button @click="() => assignToClass(member)"
                                            :disabled="!member.assigned_class"
                                            class="px-2 py-1 bg-blue-600 text-white text-xs rounded hover:bg-blue-700">
                                            Asignar
                                        </button>
                                        <button @click="() => undoAssignment(member)"
                                            class="ml-2 px-2 py-1 bg-red-500 text-white text-xs rounded hover:bg-red-600">
                                            Deshacer ultimo
                                        </button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>


            <!-- MODALS -->
            <MemberRegistrationModal :show="showRegistrationForm" :clubs="clubs" :selectedClub="selectedClub"
                @close="showRegistrationForm = false" @submitted="fetchMembers(selectedClub.id)" />
            <DeleteMemberModal :show="showDeleteModal" :memberId="deletingMember?.id"
                :memberName="deletingMember?.applicant_name" @cancel="showDeleteModal = false"
                @confirm="handleMemberDelete" />
        </div>
    </PathfinderLayout>
</template>
