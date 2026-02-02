<script setup>
import { useForm } from '@inertiajs/vue3'
import PathfinderLayout from '@/Layouts/PathfinderLayout.vue'
import { useAuth } from '@/Composables/useAuth'
import { useGeneral } from '@/Composables/useGeneral'
import { computed, ref, watch, onMounted } from 'vue'
import { PencilSquareIcon, TrashIcon } from '@heroicons/vue/24/outline'

import {
    fetchMembersByClub,
    fetchClubsByChurchId,
    listPaymentConceptsByClub,
    createPaymentConcept,
    deletePaymentConcept,
    updatePaymentConcept,
    fetchAccountsByClub,
    createAccount,
    updateAccount,
    deleteAccount
} from '@/Services/api'

// 🧠 Auth state
const { user } = useAuth()
const { showToast } = useGeneral()
const clubs = ref([])
const hasClub = ref(false)


//PAYMENT CONCEPTS
const conceptClubId = ref('')
const conceptMembers = ref([]) 
const conceptStaff = computed(() => {
    if (!conceptClubId.value) return []
    const club = clubs.value.find(c => c.id === conceptClubId.value)
    return club?.staff_adventurers ?? []
})
const conceptUsers = computed(() => {
    if (!conceptClubId.value) return []
    const club = clubs.value.find(c => c.id === conceptClubId.value)
    return club?.users ?? []
})
const conceptClasses = computed(() => {
    if (!conceptClubId.value) return []
    const club = clubs.value.find(c => c.id === conceptClubId.value)
    return (club?.club_classes ?? []).slice().sort((a, b) => a.class_order - b.class_order)
})

const paymentConcepts = ref([]) // table data
const accounts = ref([])

const accountForm = useForm({
    pay_to: '',
    label: '',
})
const savingAccount = ref(false)
const editingAccountId = ref(null)
const editingAccountLabel = ref('')

// Form (useForm for nice error handling later)
const pcForm = useForm({
    concept: '',
    payment_expected_by: '', // yyyy-mm-dd
    amount: null,          // <--- add
    type: 'mandatory',       // mandatory|optional
    pay_to: 'club_budget',   // church_budget|club_budget|conference|reimbursement_to
    payee_type: null,        // 'App\\Models\\MemberAdventurer' | 'App\\Models\\StaffAdventurer' | null
    payee_id: null,
    status: 'active',        // active|inactive
    club_id: null,           // the club to which this concept belongs
    // Multi-scope:
    // Each item: { scope_type: 'club_wide'|'class'|'member'|'staff_wide'|'staff', club_id?, class_id?, member_id?, staff_id? }
    scopes: []
})

// Small helpers for labels
const scopeTypeOptions = [
    { value: 'club_wide', label: 'Todo el club' },
    { value: 'class', label: 'Clase especifica' },
    { value: 'member', label: 'Miembro especifico' },
    { value: 'staff_wide', label: 'Todo el personal' },
    { value: 'staff', label: 'Personal especifico' }
]

const payToOptions = computed(() => {
    return accounts.value.map(a => ({ value: a.pay_to, label: a.label }))
})

const typeOptions = [
    { value: 'mandatory', label: 'Obligatorio' },
    { value: 'optional', label: 'Opcional' }
]

const statusOptions = [
    { value: 'active', label: 'Activo' },
    { value: 'inactive', label: 'Inactivo' }
]

// derive current club name (for sanity)
const conceptClubName = computed(() => {
    const club = clubs.value.find(c => c.id === conceptClubId.value)
    return club?.club_name ?? ''
})
//ISO Date formatter
const formatISODate = (val) => {
    if (!val) return '—'
    const [y, m, d] = String(val).slice(0, 10).split('-').map(Number)
    const dt = new Date(y, m - 1, d) // local date (no UTC)
    return new Intl.DateTimeFormat(undefined, { year: 'numeric', month: 'short', day: '2-digit' }).format(dt)
}

// scope builder actions
const defaultScope = () => ({ scope_type: 'club_wide', club_id: conceptClubId.value || null, class_id: null, member_id: null, staff_id: null })

function ensureSingleScope() {
    const baseScope = defaultScope()
    if (pcForm.scopes.length === 0) {
        pcForm.scopes = [baseScope]
    } else {
        pcForm.scopes = [{ ...baseScope, ...pcForm.scopes[0] }]
    }
}

function removeScope(idx) {
    pcForm.scopes.splice(idx, 1)
}

function onScopeTypeChange(scope) {
    // Clean fields not used by the selected type
    scope.club_id = null
    scope.class_id = null
    scope.member_id = null
    scope.staff_id = null

    if (scope.scope_type === 'club_wide' || scope.scope_type === 'staff_wide') {
        scope.club_id = conceptClubId.value || null
    }
}

function scopeOf(pc) {
    return pc?.scopes?.[0] ?? null
}

function scopeLabel(sc) {
    if (!sc) return 'Sin alcance'
    switch (sc.scope_type) {
        case 'club_wide':
            return `Todo el club (${sc.club?.club_name ?? sc.club_id})`
        case 'staff_wide':
            return `Todo el personal (${sc.club?.club_name ?? sc.club_id})`
        case 'class':
            return `Clase: ${sc.class?.class_name ?? sc.class_id}`
        case 'member':
            return `Miembro: ${sc.member?.applicant_name ?? sc.member_id}`
        case 'staff':
            return `Personal: ${sc.staff?.name ?? sc.staff_id}`
        default:
            return 'Alcance desconocido'
    }
}

//API CALLS CREATE PAYMENT CONCEPTS
async function loadPaymentConcepts() {
    paymentConcepts.value = []
    if (!conceptClubId.value) return
    const { data } = await listPaymentConceptsByClub(conceptClubId.value)
    paymentConcepts.value = Array.isArray(data?.data) ? data.data : []
}

//DELETE PAYMENT CONCEPT
async function deleteConcept(id) {
    if (!conceptClubId.value) return
    if (!confirm('¿Eliminar este concepto?')) return
    try {
        await deletePaymentConcept(conceptClubId.value, id)
        showToast('Concepto eliminado', 'success')
        await loadPaymentConcepts()
    } catch (e) {
        console.error(e)
        showToast('No se pudo eliminar el concepto', 'error')
    }
}

//UPDATE PAYMENT CONCEPT
    const isEditingConcept = ref(false)
    const editingConceptId = ref(null)

    const saveBtnLabel = computed(() =>
    isEditingConcept.value ? 'Guardar cambios' : 'Guardar concepto'
    )

function resetConceptForm(keepClub = true) {
    pcForm.reset()
    pcForm.type = 'mandatory'
    pcForm.pay_to = payToOptions.value[0]?.value ?? null
    pcForm.status = 'active'
    pcForm.scopes = []
    if (keepClub) pcForm.club_id = conceptClubId.value || null
    isEditingConcept.value = false
    editingConceptId.value = null
    ensureSingleScope()
    }

    // map FQCN -> short name used in the UI selector
    const toUiPayeeType = (t) => {
    if (!t) return null
    if (t.endsWith('Staff') || t.endsWith('StaffAdventurer')) return 'StaffAdventurer'
    if (t.endsWith('Member') || t.endsWith('MemberAdventurer')) return 'MemberAdventurer'
    if (t.endsWith('User')) return 'User'
    return null
    }

    // Start editing
    async function editConcept(pc) {
    // lock to that concept's club
    conceptClubId.value = pc.club_id
    pcForm.club_id = pc.club_id

    // if reimbursement, load lists first so dropdowns have options
    if (pc.pay_to === 'reimbursement_to') {
        await Promise.all([fetchStaff(pc.club_id), fetchMembers(pc.club_id)])
    }

    pcForm.concept = pc.concept
    pcForm.payment_expected_by = (pc.payment_expected_by || '').slice(0, 10) // YYYY-MM-DD
    pcForm.type = pc.type
    pcForm.pay_to = pc.pay_to
    pcForm.status = pc.status

    pcForm.payee_type = toUiPayeeType(pc.payee_type)
    pcForm.payee_id = pc.payee_id ?? null

    // normalize scopes into { scope_type, *_id }
    pcForm.scopes = (pc.scopes || []).slice(0, 1).map(s => ({
        scope_type: s.scope_type,
        club_id:   s.club_id   ?? s.club?.id   ?? null,
        class_id:  s.class_id  ?? s.class?.id  ?? null,
        member_id: s.member_id ?? s.member?.id ?? null,
        staff_id:  s.staff_id  ?? s.staff?.id  ?? null,
    }))
    ensureSingleScope()

    isEditingConcept.value = true
    editingConceptId.value = pc.id
    }

    // Save (create or update)
    async function savePaymentConcept() {
    if (!pcForm.club_id) return showToast('Selecciona el club del concepto', 'error')
    if (pcForm.scopes.length === 0) return showToast('Agrega al menos un alcance', 'error')

    if (pcForm.pay_to !== 'reimbursement_to') {
        pcForm.payee_type = null
        pcForm.payee_id = null
    }

    const payload = typeof pcForm.data === 'function' ? pcForm.data() : JSON.parse(JSON.stringify(pcForm))
    payload.scopes = (payload.scopes || []).slice(0, 1).map(s => ({
        scope_type: s.scope_type,
        club_id:   (s.scope_type === 'club_wide' || s.scope_type === 'staff_wide')
                    ? (s.club_id ?? conceptClubId.value) : (s.club_id ?? null),
        class_id:  s.class_id ?? null,
        member_id: s.member_id ?? null,
        staff_id:  s.staff_id ?? null,
    }))

    try {
        if (isEditingConcept.value && editingConceptId.value) {
        // UPDATE
        await updatePaymentConcept(conceptClubId.value, editingConceptId.value, payload)
        showToast('Concepto de pago actualizado', 'success')
        } else {
        // CREATE
        await createPaymentConcept(conceptClubId.value, payload)
        showToast('Concepto de pago creado', 'success')
        }
        resetConceptForm(true)
        await loadPaymentConcepts()
    } catch (e) {
        console.error(e)
        const msg = e?.response?.data?.message
        const errs = e?.response?.data?.errors
        if (errs && typeof errs === 'object') {
            Object.entries(errs).forEach(([field, messages]) => {
                pcForm.setError(field, Array.isArray(messages) ? messages[0] : messages)
            })
        }
        showToast(msg || 'No se pudo guardar el concepto', 'error')
    }
    }

    function cancelEditConcept() {
    resetConceptForm(true)
    }
////END API CALLS
const staffList = ref([])

const fetchStaff = async (clubId) => {
    try {
        const response = await axios.get(`/clubs/${clubId}/staff`)
        staffList.value = response.data.staff
        if(staffList.value.length === 0) {
            showToast('Crea personal primero, no se encontro ninguno','error')
            return
        }
        showToast('Personal cargado','success');
    } catch (error) {
        console.error('Failed to fetch staff:', error)
    }
};
const members = ref([])

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
        showToast('Error al obtener miembros', 'error')
    }
};

const loadAccounts = async (clubId) => {
    if (!clubId) {
        accounts.value = []
        return
    }
    try {
        const res = await fetchAccountsByClub(clubId)
        accounts.value = Array.isArray(res?.data) ? res.data : []
    } catch (error) {
        console.error('Failed to fetch accounts:', error)
        accounts.value = []
    }
}

const saveAccount = async () => {
    if (!conceptClubId.value) return
    if (!accountForm.pay_to) return showToast('Ingresa la clave de la cuenta', 'error')
    savingAccount.value = true
    try {
        await createAccount(conceptClubId.value, {
            pay_to: accountForm.pay_to,
            label: accountForm.label || accountForm.pay_to,
        })
        accountForm.reset()
        await loadAccounts(conceptClubId.value)
        showToast('Cuenta creada', 'success')
    } catch (e) {
        console.error(e)
        showToast(e?.response?.data?.message || 'No se pudo crear la cuenta', 'error')
    } finally {
        savingAccount.value = false
    }
}

const startEditAccount = (acc) => {
    editingAccountId.value = acc.id
    editingAccountLabel.value = acc.label
}

const cancelEditAccount = () => {
    editingAccountId.value = null
    editingAccountLabel.value = ''
}

const updateAccountLabel = async (acc) => {
    if (!conceptClubId.value) return
    if (!editingAccountLabel.value) return showToast('Ingresa un nombre', 'error')
    savingAccount.value = true
    try {
        await updateAccount(conceptClubId.value, acc.id, { label: editingAccountLabel.value })
        await loadAccounts(conceptClubId.value)
        showToast('Cuenta actualizada', 'success')
        cancelEditAccount()
    } catch (e) {
        console.error(e)
        showToast(e?.response?.data?.message || 'No se pudo actualizar la cuenta', 'error')
    } finally {
        savingAccount.value = false
    }
}

const removeAccount = async (acc) => {
    if (!conceptClubId.value) return
    savingAccount.value = true
    try {
        await deleteAccount(conceptClubId.value, acc.id)
        await loadAccounts(conceptClubId.value)
        showToast('Cuenta eliminada', 'success')
    } catch (e) {
        console.error(e)
        showToast(e?.response?.data?.message || 'No se pudo eliminar la cuenta', 'error')
    } finally {
        savingAccount.value = false
    }
}

const fetchClubs = async () => {
    try {
        const data = await fetchClubsByChurchId(user.value.church_id)
        const targetClubId = user.value?.club_id ? String(user.value.club_id) : null
        const filtered = targetClubId ? data.filter(c => String(c.id) === targetClubId) : data
        clubs.value = [...filtered]
        hasClub.value = filtered.length > 0
        if (!conceptClubId.value && filtered.length) {
            conceptClubId.value = filtered[0].id
            pcForm.club_id = filtered[0].id
        }
        showToast('Clubes cargados correctamente')
    } catch (error) {
        console.error('Failed to fetch clubs:', error)
        showToast('Error al cargar clubes', 'error')
    }
}

// Fetch members whenever the concept club changes
watch(conceptClubId, async (id) => {
    pcForm.club_id = id || null
    ensureSingleScope()
    if (!id) {
        conceptMembers.value = []
        accounts.value = []
        return
    }
    try {
        const data = await fetchMembersByClub(id)
        conceptMembers.value = Array.isArray(data) ? data : []
    } catch (e) {
        conceptMembers.value = []
    }
    await loadAccounts(id)
})

watch(conceptClubId, async (id) => {
    pcForm.club_id = id || null
    ensureSingleScope()
    await loadPaymentConcepts()
})

// When the concept club changes, refresh lists if reimbursement mode is on
watch([conceptClubId, () => pcForm.pay_to], async ([clubId, payTo]) => {
    if (!clubId) { staffList.value = []; members.value = []; return }
    if (payTo === 'reimbursement_to') {
        await Promise.all([fetchStaff(clubId), fetchMembers(clubId)])
    }
})

// Clear the selected payee when changing type (prevents stale ids)
watch(() => pcForm.payee_type, () => { pcForm.payee_id = null })

// Also clear payee entirely when switching away from reimbursement
watch(() => pcForm.pay_to, (val) => {
    if (val !== 'reimbursement_to') { pcForm.payee_type = null; pcForm.payee_id = null }
})

watch(accounts, (list) => {
    if (!pcForm.pay_to && Array.isArray(list) && list.length) {
        pcForm.pay_to = list[0].pay_to
    }
})



onMounted(async () => {
    await fetchClubs()
    // Optional: auto-select first club
    if (!conceptClubId.value && clubs.value.length) {
        conceptClubId.value = clubs.value[0].id
    }
    await loadPaymentConcepts()
    ensureSingleScope()
})

</script>


<template>
    <PathfinderLayout>
        <template #title>Finanzas del club</template>
        <section class="border rounded mb-4">
            <div class="bg-gray-100 px-4 py-2 font-semibold">Cuentas (pay_to)</div>
            <div class="p-4 space-y-4">
                <div class="grid md:grid-cols-3 gap-3">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Clave (pay_to)</label>
                        <input v-model="accountForm.pay_to" type="text" class="w-full mt-1 p-2 border rounded"
                            placeholder="ej. club_budget" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Etiqueta</label>
                        <input v-model="accountForm.label" type="text" class="w-full mt-1 p-2 border rounded"
                            placeholder="Presupuesto del club" />
                    </div>
                    <div class="flex items-end">
                        <button @click="saveAccount" :disabled="savingAccount || !conceptClubId"
                            class="inline-flex items-center rounded bg-blue-600 px-4 py-2 text-sm font-medium text-white disabled:opacity-60">
                            {{ savingAccount ? 'Guardando…' : 'Crear cuenta' }}
                        </button>
                    </div>
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm text-gray-700">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-3 py-2 text-left font-semibold">pay_to</th>
                                <th class="px-3 py-2 text-left font-semibold">Etiqueta</th>
                                <th class="px-3 py-2 text-left font-semibold">Saldo</th>
                                <th class="px-3 py-2 text-left font-semibold">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="acc in accounts" :key="acc.id" class="border-t">
                                <td class="px-3 py-2">{{ acc.pay_to }}</td>
                                <td class="px-3 py-2">
                                    <div v-if="editingAccountId === acc.id" class="flex items-center gap-2">
                                        <input v-model="editingAccountLabel" type="text" class="w-full p-1 border rounded" />
                                    </div>
                                    <div v-else>{{ acc.label }}</div>
                                </td>
                                <td class="px-3 py-2">{{ Number(acc.balance || 0).toFixed(2) }}</td>
                                <td class="px-3 py-2">
                                    <div class="flex items-center gap-2">
                                        <button v-if="editingAccountId !== acc.id" @click="startEditAccount(acc)"
                                            class="text-xs text-blue-700 hover:underline">Editar</button>
                                        <button v-else @click="updateAccountLabel(acc)"
                                            class="text-xs text-emerald-700 hover:underline">Guardar</button>
                                        <button v-if="editingAccountId === acc.id" @click="cancelEditAccount"
                                            class="text-xs text-gray-600 hover:underline">Cancelar</button>
                                        <button @click="removeAccount(acc)"
                                            class="text-xs text-red-600 hover:underline">Eliminar</button>
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="!accounts.length">
                                <td class="px-3 py-3 text-sm text-gray-500" colspan="4">No hay cuentas para este club.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
        <details class="border rounded">
            <summary class="bg-gray-100 px-4 py-2 font-semibold cursor-pointer">
                Conceptos de pago
            </summary>

            <div class="p-4 space-y-6">
                <!-- Form -->
                <div class="space-y-4">
                    <h3 class="text-lg font-bold">Crear concepto de pago</h3>

                    <!-- Choose club -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Aplica al club</label>
                        <select v-model="conceptClubId" class="w-full mt-1 p-2 border rounded" :disabled="isEditingConcept">
                            <option value="">Selecciona un club</option>
                            <option v-for="club in clubs" :key="club.id" :value="club.id">{{ club.club_name }}</option>
                        </select>
                    </div>

                    <div class="grid md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Concepto</label>
                            <input v-model="pcForm.concept" type="text" class="w-full mt-1 p-2 border rounded"
                                placeholder="Ej. cuota de inscripcion" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Monto</label>
                            <input
                                v-model.number="pcForm.amount"
                                type="number"
                                step="0.01"
                                min="0"
                                placeholder="0.00"
                                class="w-full mt-1 p-2 border rounded"
                            />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Pago esperado para</label>
                            <input v-model="pcForm.payment_expected_by" type="date"
                                class="w-full mt-1 p-2 border rounded" />
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Tipo</label>
                            <select v-model="pcForm.type" class="w-full mt-1 p-2 border rounded">
                                <option v-for="o in typeOptions" :key="o.value" :value="o.value">{{ o.label }}
                                </option>
                            </select>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Estado</label>
                            <select v-model="pcForm.status" class="w-full mt-1 p-2 border rounded">
                                <option v-for="o in statusOptions" :key="o.value" :value="o.value">{{ o.label }}
                                </option>
                            </select>
                        </div>

                        <div class="md:col-span-2">
                            <label class="block text-sm font-medium text-gray-700 mb-1">Pagar a</label>
                            <select v-model="pcForm.pay_to" class="w-full mt-1 p-2 border rounded">
                                <option v-for="o in payToOptions" :key="o.value" :value="o.value">{{ o.label }}
                                </option>
                            </select>
                            <p class="text-xs text-gray-500 mt-1">Creado por: {{ user.name }}</p>
                        </div>
                    </div>

                    <!-- Conditional payee -->
                    <!-- Conditional payee -->
                    <div v-if="pcForm.pay_to === 'reimbursement_to'" class="grid md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Reembolsar a (tipo)</label>
                            <select v-model="pcForm.payee_type" class="w-full mt-1 p-2 border rounded">
                                <option :value="null">Seleccionar…</option>
                                <option value="StaffAdventurer">Personal</option>
                                <option value="MemberAdventurer">Miembro</option>
                                <option value="User">Director/Usuario</option>
                            </select>
                            <p class="text-xs text-gray-500 mt-1" v-if="!conceptClubId">Selecciona un club arriba para cargar
                                personal/miembros</p>
                        </div>

                        <!-- Staff dropdown -->
        <div v-if="pcForm.payee_type === 'StaffAdventurer'">
            <label class="block text-sm font-medium text-gray-700 mb-1">Seleccionar personal</label>
            <select v-model="pcForm.payee_id" :disabled="!conceptClubId || staffList.length === 0"
                class="w-full mt-1 p-2 border rounded">
                <option :value="null">Seleccionar personal</option>
                <option v-for="s in staffList" :key="s.staff_id || s.id" :value="s.staff_id || s.id">
                    {{ s.name }}
                </option>
            </select>
            <p class="text-xs text-gray-500 mt-1" v-if="conceptClubId && staffList.length === 0">
                No se encontro personal para este club.
            </p>
                        </div>

                        <!-- Member dropdown -->
                        <div v-else-if="pcForm.payee_type === 'MemberAdventurer'">
            <label class="block text-sm font-medium text-gray-700 mb-1">Seleccionar miembro</label>
            <select v-model="pcForm.payee_id" :disabled="!conceptClubId || members.length === 0"
                class="w-full mt-1 p-2 border rounded">
                <option :value="null">Seleccionar miembro</option>
                <option v-for="m in members" :key="m.member_id || m.id" :value="m.member_id || m.id">
                    {{ m.applicant_name }}
                </option>
            </select>
            <p class="text-xs text-gray-500 mt-1" v-if="conceptClubId && members.length === 0">
                No se encontraron miembros para este club.
            </p>
                        </div>
                        <div v-else-if="pcForm.payee_type === 'User'">
            <label class="block text-sm font-medium text-gray-700 mb-1">Seleccionar director/usuario</label>
            <select v-model="pcForm.payee_id" :disabled="!conceptClubId || conceptUsers.length === 0"
                class="w-full mt-1 p-2 border rounded">
                <option :value="null">Seleccionar usuario</option>
                <option v-for="u in conceptUsers" :key="u.id" :value="u.id">
                    {{ u.name }}<span v-if="u.email"> ({{ u.email }})</span>
                </option>
            </select>
            <p class="text-xs text-gray-500 mt-1" v-if="conceptClubId && conceptUsers.length === 0">
                No se encontraron usuarios para este club.
            </p>
                        </div>
                    </div>

                    <!-- Scope -->
                    <div class="mt-6">
                        <h4 class="font-semibold mb-2">Alcance</h4>
                        <div class="border rounded p-3" v-if="pcForm.scopes.length">
                            <div class="grid md:grid-cols-3 gap-3">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Tipo de alcance</label>
                                    <select v-model="pcForm.scopes[0].scope_type" @change="onScopeTypeChange(pcForm.scopes[0])"
                                        class="w-full p-2 border rounded">
                                        <option v-for="o in scopeTypeOptions" :key="o.value" :value="o.value">{{
                                            o.label }}</option>
                                    </select>
                                </div>

                                <div v-if="pcForm.scopes[0].scope_type === 'club_wide' || pcForm.scopes[0].scope_type === 'staff_wide'">
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Club</label>
                                    <select v-model="pcForm.scopes[0].club_id" class="w-full p-2 border rounded">
                                        <option :value="null">Seleccionar club</option>
                                        <option v-for="c in clubs" :key="c.id" :value="c.id">{{ c.club_name }}
                                        </option>
                                    </select>
                                </div>

                                <div v-if="pcForm.scopes[0].scope_type === 'class'">
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Clase</label>
                                    <select v-model="pcForm.scopes[0].class_id" class="w-full p-2 border rounded">
                                        <option :value="null">Seleccionar clase</option>
                                        <option v-for="c in conceptClasses" :key="c.id" :value="c.id">{{
                                            c.class_name }}</option>
                                    </select>
                                </div>

                                <div v-if="pcForm.scopes[0].scope_type === 'member'">
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Miembro</label>
                                    <select v-model="pcForm.scopes[0].member_id" class="w-full p-2 border rounded">
                                        <option :value="null">Seleccionar miembro</option>
                                        <option v-for="m in conceptMembers" :key="m.id" :value="m.id">{{
                                            m.applicant_name }}</option>
                                    </select>
                                </div>

                                <div v-if="pcForm.scopes[0].scope_type === 'staff'">
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Personal</label>
                                    <select v-model="pcForm.scopes[0].staff_id" class="w-full p-2 border rounded">
                                        <option :value="null">Seleccionar personal</option>
                                        <option v-for="st in conceptStaff" :key="st.id" :value="st.id">{{ st.name }}
                                        </option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="flex items-center gap-3 mt-4">
                        <button type="button" @click="savePaymentConcept"
                                class="px-4 py-2 bg-green-600 text-white rounded hover:bg-green-700">
                            {{ saveBtnLabel }}
                        </button>
                        <button v-if="isEditingConcept" type="button" @click="cancelEditConcept"
                                class="text-sm text-gray-600 hover:underline">
                            Cancelar
                        </button>
                    </div>
                </div>

                <!-- List -->
                <div class="pt-6">
                    <div class="flex items-center justify-between mb-2">
                        <h3 class="text-lg font-bold">Conceptos de pago existentes</h3>
                        <button type="button" @click="loadPaymentConcepts"
                            class="px-3 py-1 bg-gray-700 text-white rounded text-sm hover:bg-gray-800">
                            Actualizar
                        </button>
                    </div>

                    <div v-if="paymentConcepts.length === 0" class="text-sm text-gray-500">
                        No hay conceptos de pago creados.
                    </div>

                    <div v-else class="overflow-x-auto">
                        <table class="min-w-full border rounded text-sm">
                            <thead class="bg-gray-100">
                                <tr>
                                    <th class="p-2 text-left">Concepto</th>
                                    <th class="p-2 text-left">Monto</th>
                                    <th class="p-2 text-left">Club</th>
                                    <th class="p-2 text-left">Vence</th>
                                    <th class="p-2 text-left">Tipo</th>
                                    <th class="p-2 text-left">Pagar a</th>
                                    <th class="p-2 text-left">Estado</th>
                                    <th class="p-2 text-left">Alcances</th>
                                    <th class="p-2 text-left">Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="pc in paymentConcepts" :key="pc.id" class="border-t">
                                    <td class="p-2">{{ pc.concept }}</td>
                                    <td class="p-2">
                                        {{ new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' })
                                            .format(pc.amount ?? 0) }}
                                    </td>
                                    <td class="p-2">{{ pc.club?.club_name ?? conceptClubName }}</td>
                                    <td class="p-2">{{ formatISODate(pc.payment_expected_by) }}</td>
                                    <td class="p-2 capitalize">{{ pc.type }}</td>
                                    <td class="p-2 capitalize">{{ pc.pay_to }}</td>
                                    <td class="p-2 capitalize">{{ pc.status }}</td>
                                    <td class="p-2">
                                        <span v-if="scopeOf(pc)">{{ scopeLabel(scopeOf(pc)) }}</span>
                                        <span v-else class="text-gray-500 italic">Sin alcance</span>
                                    </td>
                                    <td>
                                        <button
                                            type="button"
                                            class="p-1 rounded hover:bg-blue-50 focus:outline-none focus:ring-2 focus:ring-blue-400"
                                            @click.prevent="editConcept(pc)"
                                            aria-label="Editar"
                                            title="Editar"
                                        >
                                            <PencilSquareIcon class="h-5 w-5 text-blue-600" />
                                            <span class="sr-only">Editar</span>
                                        </button>

                                        <button
                                            type="button"
                                            class="p-1 rounded hover:bg-red-50 focus:outline-none focus:ring-2 focus:ring-red-400"
                                            @click="deleteConcept(pc.id)"
                                            aria-label="Eliminar"
                                            title="Eliminar"
                                        >
                                            <TrashIcon class="h-5 w-5 text-red-600" />
                                            <span class="sr-only">Eliminar</span>
                                        </button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

            </div>
        </details>
    </PathfinderLayout>
</template>
