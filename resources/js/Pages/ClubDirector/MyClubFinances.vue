<script setup>
import { useForm } from '@inertiajs/vue3'
import PathfinderLayout from '@/Layouts/PathfinderLayout.vue'
import { useAuth } from '@/Composables/useAuth'
import { useGeneral } from '@/Composables/useGeneral'
import { useLocale } from '@/Composables/useLocale'
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
const { tr } = useLocale()
const isSuperadmin = computed(() => user.value?.profile_type === 'superadmin')
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
const accountsPage = ref(1)
const accountsPageSize = ref(10)
const conceptsPage = ref(1)
const conceptsPageSize = ref(10)

const totalAccountsPages = computed(() => Math.max(1, Math.ceil(accounts.value.length / accountsPageSize.value)))
const accountsStartIdx = computed(() => (accountsPage.value - 1) * accountsPageSize.value)
const accountsEndIdx = computed(() => Math.min(accountsStartIdx.value + accountsPageSize.value, accounts.value.length))
const pagedAccounts = computed(() => accounts.value.slice(accountsStartIdx.value, accountsEndIdx.value))

const totalConceptsPages = computed(() => Math.max(1, Math.ceil(paymentConcepts.value.length / conceptsPageSize.value)))
const conceptsStartIdx = computed(() => (conceptsPage.value - 1) * conceptsPageSize.value)
const conceptsEndIdx = computed(() => Math.min(conceptsStartIdx.value + conceptsPageSize.value, paymentConcepts.value.length))
const pagedConcepts = computed(() => paymentConcepts.value.slice(conceptsStartIdx.value, conceptsEndIdx.value))

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
    reusable: false,
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
    { value: 'club_wide', label: tr('Todo el club', 'Whole club') },
    { value: 'class', label: tr('Clase especifica', 'Specific class') },
    { value: 'member', label: tr('Miembro especifico', 'Specific member') },
    { value: 'staff_wide', label: tr('Todo el personal', 'All staff') },
    { value: 'staff', label: tr('Personal especifico', 'Specific staff') }
]

const payToOptions = computed(() => {
    return accounts.value.map(a => ({ value: a.pay_to, label: a.label }))
})

const typeOptions = [
    { value: 'mandatory', label: tr('Obligatorio', 'Required') },
    { value: 'optional', label: tr('Opcional', 'Optional') }
]

const statusOptions = [
    { value: 'active', label: tr('Activo', 'Active') },
    { value: 'inactive', label: tr('Inactivo', 'Inactive') }
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
    if (!sc) return tr('Sin alcance', 'No scope')
    switch (sc.scope_type) {
        case 'club_wide':
            return `${tr('Todo el club', 'Whole club')} (${sc.club?.club_name ?? sc.club_id})`
        case 'staff_wide':
            return `${tr('Todo el personal', 'All staff')} (${sc.club?.club_name ?? sc.club_id})`
        case 'class':
            return `${tr('Clase', 'Class')}: ${sc.class?.class_name ?? sc.class_id}`
        case 'member':
            return `${tr('Miembro', 'Member')}: ${sc.member?.applicant_name ?? sc.member_id}`
        case 'staff':
            return `${tr('Personal', 'Staff')}: ${sc.staff?.name ?? sc.staff_id}`
        default:
            return tr('Alcance desconocido', 'Unknown scope')
    }
}

//API CALLS CREATE PAYMENT CONCEPTS
async function loadPaymentConcepts() {
    paymentConcepts.value = []
    if (!conceptClubId.value) return
    const { data } = await listPaymentConceptsByClub(conceptClubId.value)
    paymentConcepts.value = Array.isArray(data?.data) ? data.data : []
    conceptsPage.value = 1
}

//DELETE PAYMENT CONCEPT
async function deleteConcept(id) {
    if (!conceptClubId.value) return
    if (!confirm(tr('¿Eliminar este concepto?', 'Delete this concept?'))) return
    try {
        await deletePaymentConcept(conceptClubId.value, id)
        showToast(tr('Concepto eliminado', 'Concept deleted'), 'success')
        await loadPaymentConcepts()
    } catch (e) {
        console.error(e)
        showToast(tr('No se pudo eliminar el concepto', 'Could not delete the concept'), 'error')
    }
}

//UPDATE PAYMENT CONCEPT
    const isEditingConcept = ref(false)
    const editingConceptId = ref(null)

    const saveBtnLabel = computed(() =>
    isEditingConcept.value ? tr('Guardar cambios', 'Save changes') : tr('Guardar concepto', 'Save concept')
    )

function resetConceptForm(keepClub = true) {
    pcForm.reset()
    pcForm.type = 'mandatory'
    pcForm.reusable = false
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
    pcForm.amount = pc.amount ?? null
    pcForm.reusable = Boolean(pc.reusable)
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
    if (!pcForm.club_id) return showToast(tr('Selecciona el club del concepto', 'Select the concept club'), 'error')
    if (pcForm.scopes.length === 0) return showToast(tr('Agrega al menos un alcance', 'Add at least one scope'), 'error')

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
        showToast(tr('Concepto de pago actualizado', 'Payment concept updated'), 'success')
        } else {
        // CREATE
        await createPaymentConcept(conceptClubId.value, payload)
        showToast(tr('Concepto de pago creado', 'Payment concept created'), 'success')
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
        showToast(msg || tr('No se pudo guardar el concepto', 'Could not save the concept'), 'error')
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
            showToast(tr('Crea personal primero, no se encontro ninguno', 'Create staff first; none were found'),'error')
            return
        }
        showToast(tr('Personal cargado', 'Staff loaded'),'success');
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
            showToast(tr('Miembros cargados', 'Members loaded'), 'success')
        } else {
            members.value = []
            showToast(tr('No se encontraron miembros para este club.', 'No members were found for this club.'), 'info')
        }
    } catch (error) {
        console.error('Failed to fetch members:', error)
        showToast(tr('Error al obtener miembros', 'Could not load members'), 'error')
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
        accountsPage.value = 1
    } catch (error) {
        console.error('Failed to fetch accounts:', error)
        accounts.value = []
    }
}

const saveAccount = async () => {
    if (!conceptClubId.value) return
    if (!accountForm.pay_to) return showToast(tr('Ingresa la clave de la cuenta', 'Enter the account key'), 'error')
    savingAccount.value = true
    try {
        await createAccount(conceptClubId.value, {
            pay_to: accountForm.pay_to,
            label: accountForm.label || accountForm.pay_to,
        })
        accountForm.reset()
        await loadAccounts(conceptClubId.value)
        showToast(tr('Cuenta creada', 'Account created'), 'success')
    } catch (e) {
        console.error(e)
        showToast(e?.response?.data?.message || tr('No se pudo crear la cuenta', 'Could not create the account'), 'error')
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
    if (!editingAccountLabel.value) return showToast(tr('Ingresa un nombre', 'Enter a name'), 'error')
    savingAccount.value = true
    try {
        await updateAccount(conceptClubId.value, acc.id, { label: editingAccountLabel.value })
        await loadAccounts(conceptClubId.value)
        showToast(tr('Cuenta actualizada', 'Account updated'), 'success')
        cancelEditAccount()
    } catch (e) {
        console.error(e)
        showToast(e?.response?.data?.message || tr('No se pudo actualizar la cuenta', 'Could not update the account'), 'error')
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
        showToast(tr('Cuenta eliminada', 'Account deleted'), 'success')
    } catch (e) {
        console.error(e)
        showToast(e?.response?.data?.message || tr('No se pudo eliminar la cuenta', 'Could not delete the account'), 'error')
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
        showToast(tr('Clubes cargados correctamente', 'Clubs loaded successfully'))
    } catch (error) {
        console.error('Failed to fetch clubs:', error)
        showToast(tr('Error al cargar clubes', 'Could not load clubs'), 'error')
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

watch(
    () => accounts.value.length,
    () => {
        if (accountsPage.value > totalAccountsPages.value) {
            accountsPage.value = totalAccountsPages.value
        }
        if (accountsPage.value < 1) accountsPage.value = 1
    }
)

watch(
    () => paymentConcepts.value.length,
    () => {
        if (conceptsPage.value > totalConceptsPages.value) {
            conceptsPage.value = totalConceptsPages.value
        }
        if (conceptsPage.value < 1) conceptsPage.value = 1
    }
)

const goAccountsPage = (next) => {
    accountsPage.value = Math.min(Math.max(1, next), totalAccountsPages.value)
}

const goConceptsPage = (next) => {
    conceptsPage.value = Math.min(Math.max(1, next), totalConceptsPages.value)
}



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
        <template #title>{{ tr('Finanzas del club', 'Club Finances') }}</template>
        <section class="border rounded mb-4">
            <div class="bg-gray-100 px-4 py-2 font-semibold">{{ tr('Cuentas (pay_to)', 'Accounts (pay_to)') }}</div>
            <div class="p-4 space-y-4">
                <div class="grid md:grid-cols-3 gap-3">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">{{ tr('Clave (pay_to)', 'Key (pay_to)') }}</label>
                        <input v-model="accountForm.pay_to" type="text" class="w-full mt-1 p-2 border rounded"
                            :placeholder="tr('ej. club_budget', 'ex. club_budget')" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">{{ tr('Etiqueta', 'Label') }}</label>
                        <input v-model="accountForm.label" type="text" class="w-full mt-1 p-2 border rounded"
                            :placeholder="tr('Presupuesto del club', 'Club budget')" />
                    </div>
                    <div class="flex items-end">
                        <button @click="saveAccount" :disabled="savingAccount || !conceptClubId"
                            class="inline-flex items-center rounded bg-blue-600 px-4 py-2 text-sm font-medium text-white disabled:opacity-60">
                            {{ savingAccount ? tr('Guardando...', 'Saving...') : tr('Crear cuenta', 'Create account') }}
                        </button>
                    </div>
                </div>

                <div v-if="accounts.length" class="mt-2 flex items-center justify-between text-xs text-gray-600">
                    <div>{{ tr('Mostrando', 'Showing') }} {{ accounts.length ? accountsStartIdx + 1 : 0 }}-{{ accountsEndIdx }} {{ tr('de', 'of') }} {{ accounts.length }}</div>
                    <div>10 {{ tr('por pagina', 'per page') }}</div>
                </div>

                <div class="space-y-3 md:hidden">
                    <div v-for="acc in pagedAccounts" :key="acc.id" class="rounded-xl border border-gray-200 bg-white p-3 shadow-sm">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <div class="text-sm font-semibold text-gray-900">{{ acc.label }}</div>
                                <div class="text-xs text-gray-600">{{ acc.pay_to }}</div>
                            </div>
                            <div class="text-right">
                                <div class="text-xs text-gray-500">{{ tr('Saldo', 'Balance') }}</div>
                                <div class="font-semibold text-gray-900">{{ Number(acc.balance || 0).toFixed(2) }}</div>
                            </div>
                        </div>
                        <div class="mt-2">
                            <div v-if="editingAccountId === acc.id" class="flex items-center gap-2">
                                <input v-model="editingAccountLabel" type="text" class="w-full p-1 border rounded" />
                            </div>
                        </div>
                        <div class="mt-3 flex flex-wrap items-center gap-3 text-xs">
                            <button v-if="editingAccountId !== acc.id" @click="startEditAccount(acc)"
                                class="text-blue-700 hover:underline">{{ tr('Editar', 'Edit') }}</button>
                            <button v-else @click="updateAccountLabel(acc)"
                                class="text-emerald-700 hover:underline">{{ tr('Guardar', 'Save') }}</button>
                            <button v-if="editingAccountId === acc.id" @click="cancelEditAccount"
                                class="text-gray-600 hover:underline">{{ tr('Cancelar', 'Cancel') }}</button>
                            <button @click="removeAccount(acc)"
                                class="text-red-600 hover:underline">{{ tr('Eliminar', 'Delete') }}</button>
                        </div>
                    </div>
                    <div v-if="!accounts.length" class="text-sm text-gray-500">{{ tr('No hay cuentas para este club.', 'There are no accounts for this club.') }}</div>
                </div>

                <div class="hidden md:block overflow-x-auto">
                    <table class="min-w-full text-sm text-gray-700">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-3 py-2 text-left font-semibold">pay_to</th>
                                <th class="px-3 py-2 text-left font-semibold">{{ tr('Etiqueta', 'Label') }}</th>
                                <th class="px-3 py-2 text-left font-semibold">{{ tr('Saldo', 'Balance') }}</th>
                                <th class="px-3 py-2 text-left font-semibold">{{ tr('Acciones', 'Actions') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="acc in pagedAccounts" :key="acc.id" class="border-t">
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
                                            class="text-xs text-blue-700 hover:underline">{{ tr('Editar', 'Edit') }}</button>
                                        <button v-else @click="updateAccountLabel(acc)"
                                            class="text-xs text-emerald-700 hover:underline">{{ tr('Guardar', 'Save') }}</button>
                                        <button v-if="editingAccountId === acc.id" @click="cancelEditAccount"
                                            class="text-xs text-gray-600 hover:underline">{{ tr('Cancelar', 'Cancel') }}</button>
                                        <button @click="removeAccount(acc)"
                                            class="text-xs text-red-600 hover:underline">{{ tr('Eliminar', 'Delete') }}</button>
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="!accounts.length">
                                <td class="px-3 py-3 text-sm text-gray-500" colspan="4">{{ tr('No hay cuentas para este club.', 'There are no accounts for this club.') }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div v-if="accounts.length > accountsPageSize" class="mt-3 flex items-center justify-between">
                    <button class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-50 disabled:opacity-50"
                        :disabled="accountsPage <= 1" @click="goAccountsPage(accountsPage - 1)">
                        {{ tr('Anterior', 'Previous') }}
                    </button>
                    <div class="text-xs text-gray-600">{{ tr('Pagina', 'Page') }} {{ accountsPage }} {{ tr('de', 'of') }} {{ totalAccountsPages }}</div>
                    <button class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-50 disabled:opacity-50"
                        :disabled="accountsPage >= totalAccountsPages" @click="goAccountsPage(accountsPage + 1)">
                        {{ tr('Siguiente', 'Next') }}
                    </button>
                </div>
            </div>
        </section>
        <details class="border rounded">
            <summary class="bg-gray-100 px-4 py-2 font-semibold cursor-pointer">
                {{ tr('Conceptos de pago', 'Payment Concepts') }}
            </summary>

            <div class="p-4 space-y-6">
                <!-- Form -->
                <div class="space-y-4">
                    <h3 class="text-lg font-bold">{{ tr('Crear concepto de pago', 'Create Payment Concept') }}</h3>

                    <!-- Choose club -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">{{ tr('Aplica al club', 'Applies to club') }}</label>
                        <select v-if="isSuperadmin" v-model="conceptClubId" class="w-full mt-1 p-2 border rounded" :disabled="isEditingConcept">
                            <option value="">{{ tr('Selecciona un club', 'Select a club') }}</option>
                            <option v-for="club in clubs" :key="club.id" :value="club.id">{{ club.club_name }}</option>
                        </select>
                        <div v-else class="w-full mt-1 rounded border border-gray-200 bg-gray-50 px-3 py-2 text-sm text-gray-700">
                            {{ conceptClubName || '—' }}
                        </div>
                    </div>

                    <div class="grid md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">{{ tr('Concepto', 'Concept') }}</label>
                            <input v-model="pcForm.concept" type="text" class="w-full mt-1 p-2 border rounded"
                                :placeholder="tr('Ej. cuota de inscripcion', 'Ex. enrollment fee')" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">{{ tr('Monto', 'Amount') }}</label>
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
                            <label class="block text-sm font-medium text-gray-700 mb-1">{{ tr('Pago esperado para', 'Payment expected by') }}</label>
                            <input v-model="pcForm.payment_expected_by" type="date"
                                class="w-full mt-1 p-2 border rounded" />
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">{{ tr('Tipo', 'Type') }}</label>
                            <select v-model="pcForm.type" class="w-full mt-1 p-2 border rounded">
                                <option v-for="o in typeOptions" :key="o.value" :value="o.value">{{ o.label }}
                                </option>
                            </select>
                        </div>

                        <div class="md:col-span-2 rounded border border-gray-200 bg-gray-50 px-3 py-3">
                            <label class="inline-flex items-start gap-3">
                                <input
                                    id="pc-reusable"
                                    v-model="pcForm.reusable"
                                    type="checkbox"
                                    class="mt-0.5 rounded border-gray-300 text-blue-600 focus:ring-blue-500"
                                />
                                <span>
                                    <span class="block text-sm font-medium text-gray-700">{{ tr('Reusar', 'Reusable') }}</span>
                                    <span class="block text-xs text-gray-500">
                                        {{ tr('Si esta activo, el concepto puede cobrarse varias veces al mismo pagador y cada cobro debe ser por el importe completo.', 'If active, the concept can be charged multiple times to the same payer and each charge must be for the full amount.') }}
                                    </span>
                                </span>
                            </label>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">{{ tr('Estado', 'Status') }}</label>
                            <select v-model="pcForm.status" class="w-full mt-1 p-2 border rounded">
                                <option v-for="o in statusOptions" :key="o.value" :value="o.value">{{ o.label }}
                                </option>
                            </select>
                        </div>

                        <div class="md:col-span-2">
                            <label class="block text-sm font-medium text-gray-700 mb-1">{{ tr('Pagar a', 'Pay to') }}</label>
                            <select v-model="pcForm.pay_to" class="w-full mt-1 p-2 border rounded">
                                <option v-for="o in payToOptions" :key="o.value" :value="o.value">{{ o.label }}
                                </option>
                            </select>
                            <p class="text-xs text-gray-500 mt-1">{{ tr('Creado por', 'Created by') }}: {{ user.name }}</p>
                        </div>
                    </div>

                    <!-- Conditional payee -->
                    <!-- Conditional payee -->
                    <div v-if="pcForm.pay_to === 'reimbursement_to'" class="grid md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">{{ tr('Reembolsar a (tipo)', 'Reimburse to (type)') }}</label>
                            <select v-model="pcForm.payee_type" class="w-full mt-1 p-2 border rounded">
                                <option :value="null">{{ tr('Seleccionar...', 'Select...') }}</option>
                                <option value="StaffAdventurer">{{ tr('Personal', 'Staff') }}</option>
                                <option value="MemberAdventurer">{{ tr('Miembro', 'Member') }}</option>
                                <option value="User">{{ tr('Director/Usuario', 'Director/User') }}</option>
                            </select>
                            <p class="text-xs text-gray-500 mt-1" v-if="!conceptClubId">{{ tr('Selecciona un club arriba para cargar personal/miembros', 'Select a club above to load staff/members') }}</p>
                        </div>

                        <!-- Staff dropdown -->
        <div v-if="pcForm.payee_type === 'StaffAdventurer'">
            <label class="block text-sm font-medium text-gray-700 mb-1">{{ tr('Seleccionar personal', 'Select staff') }}</label>
            <select v-model="pcForm.payee_id" :disabled="!conceptClubId || staffList.length === 0"
                class="w-full mt-1 p-2 border rounded">
                <option :value="null">{{ tr('Seleccionar personal', 'Select staff') }}</option>
                <option v-for="s in staffList" :key="s.staff_id || s.id" :value="s.staff_id || s.id">
                    {{ s.name }}
                </option>
            </select>
            <p class="text-xs text-gray-500 mt-1" v-if="conceptClubId && staffList.length === 0">
                {{ tr('No se encontro personal para este club.', 'No staff was found for this club.') }}
            </p>
                        </div>

                        <!-- Member dropdown -->
                        <div v-else-if="pcForm.payee_type === 'MemberAdventurer'">
            <label class="block text-sm font-medium text-gray-700 mb-1">{{ tr('Seleccionar miembro', 'Select member') }}</label>
            <select v-model="pcForm.payee_id" :disabled="!conceptClubId || members.length === 0"
                class="w-full mt-1 p-2 border rounded">
                <option :value="null">{{ tr('Seleccionar miembro', 'Select member') }}</option>
                <option v-for="m in members" :key="m.member_id || m.id" :value="m.member_id || m.id">
                    {{ m.applicant_name }}
                </option>
            </select>
            <p class="text-xs text-gray-500 mt-1" v-if="conceptClubId && members.length === 0">
                {{ tr('No se encontraron miembros para este club.', 'No members were found for this club.') }}
            </p>
                        </div>
                        <div v-else-if="pcForm.payee_type === 'User'">
            <label class="block text-sm font-medium text-gray-700 mb-1">{{ tr('Seleccionar director/usuario', 'Select director/user') }}</label>
            <select v-model="pcForm.payee_id" :disabled="!conceptClubId || conceptUsers.length === 0"
                class="w-full mt-1 p-2 border rounded">
                <option :value="null">{{ tr('Seleccionar usuario', 'Select user') }}</option>
                <option v-for="u in conceptUsers" :key="u.id" :value="u.id">
                    {{ u.name }}<span v-if="u.email"> ({{ u.email }})</span>
                </option>
            </select>
            <p class="text-xs text-gray-500 mt-1" v-if="conceptClubId && conceptUsers.length === 0">
                {{ tr('No se encontraron usuarios para este club.', 'No users were found for this club.') }}
            </p>
                        </div>
                    </div>

                    <!-- Scope -->
                    <div class="mt-6">
                        <h4 class="font-semibold mb-2">{{ tr('Alcance', 'Scope') }}</h4>
                        <div class="border rounded p-3" v-if="pcForm.scopes.length">
                            <div class="grid md:grid-cols-3 gap-3">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">{{ tr('Tipo de alcance', 'Scope type') }}</label>
                                    <select v-model="pcForm.scopes[0].scope_type" @change="onScopeTypeChange(pcForm.scopes[0])"
                                        class="w-full p-2 border rounded">
                                        <option v-for="o in scopeTypeOptions" :key="o.value" :value="o.value">{{
                                            o.label }}</option>
                                    </select>
                                </div>

                                <div v-if="pcForm.scopes[0].scope_type === 'club_wide' || pcForm.scopes[0].scope_type === 'staff_wide'">
                                    <label class="block text-sm font-medium text-gray-700 mb-1">{{ tr('Club', 'Club') }}</label>
                                    <select v-if="isSuperadmin" v-model="pcForm.scopes[0].club_id" class="w-full p-2 border rounded">
                                        <option :value="null">{{ tr('Seleccionar club', 'Select club') }}</option>
                                        <option v-for="c in clubs" :key="c.id" :value="c.id">{{ c.club_name }}
                                        </option>
                                    </select>
                                    <div v-else class="w-full rounded border border-gray-200 bg-gray-50 px-3 py-2 text-sm text-gray-700">
                                        {{ conceptClubName || '—' }}
                                    </div>
                                </div>

                                <div v-if="pcForm.scopes[0].scope_type === 'class'">
                                    <label class="block text-sm font-medium text-gray-700 mb-1">{{ tr('Clase', 'Class') }}</label>
                                    <select v-model="pcForm.scopes[0].class_id" class="w-full p-2 border rounded">
                                        <option :value="null">{{ tr('Seleccionar clase', 'Select class') }}</option>
                                        <option v-for="c in conceptClasses" :key="c.id" :value="c.id">{{
                                            c.class_name }}</option>
                                    </select>
                                </div>

                                <div v-if="pcForm.scopes[0].scope_type === 'member'">
                                    <label class="block text-sm font-medium text-gray-700 mb-1">{{ tr('Miembro', 'Member') }}</label>
                                    <select v-model="pcForm.scopes[0].member_id" class="w-full p-2 border rounded">
                                        <option :value="null">{{ tr('Seleccionar miembro', 'Select member') }}</option>
                                        <option v-for="m in conceptMembers" :key="m.id" :value="m.id">{{
                                            m.applicant_name }}</option>
                                    </select>
                                </div>

                                <div v-if="pcForm.scopes[0].scope_type === 'staff'">
                                    <label class="block text-sm font-medium text-gray-700 mb-1">{{ tr('Personal', 'Staff') }}</label>
                                    <select v-model="pcForm.scopes[0].staff_id" class="w-full p-2 border rounded">
                                        <option :value="null">{{ tr('Seleccionar personal', 'Select staff') }}</option>
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
                            {{ tr('Cancelar', 'Cancel') }}
                        </button>
                    </div>
                </div>

                <!-- List -->
                <div class="pt-6">
                    <div class="flex items-center justify-between mb-2">
                        <h3 class="text-lg font-bold">{{ tr('Conceptos de pago existentes', 'Existing Payment Concepts') }}</h3>
                        <button type="button" @click="loadPaymentConcepts"
                            class="px-3 py-1 bg-gray-700 text-white rounded text-sm hover:bg-gray-800">
                            {{ tr('Actualizar', 'Refresh') }}
                        </button>
                    </div>

                    <div v-if="paymentConcepts.length === 0" class="text-sm text-gray-500">
                        {{ tr('No hay conceptos de pago creados.', 'No payment concepts have been created.') }}
                    </div>

                    <div v-else>
                        <div v-if="paymentConcepts.length" class="mb-2 flex items-center justify-between text-xs text-gray-600">
                            <div>{{ tr('Mostrando', 'Showing') }} {{ paymentConcepts.length ? conceptsStartIdx + 1 : 0 }}-{{ conceptsEndIdx }} {{ tr('de', 'of') }} {{ paymentConcepts.length }}</div>
                            <div>10 {{ tr('por pagina', 'per page') }}</div>
                        </div>
                        <div class="space-y-3 md:hidden">
                            <div v-for="pc in pagedConcepts" :key="pc.id" class="rounded-xl border border-gray-200 bg-white p-3 shadow-sm">
                                <div class="flex items-start justify-between gap-3">
                                    <div>
                                        <div class="text-sm font-semibold text-gray-900">{{ pc.concept }}</div>
                                        <div class="text-xs text-gray-600">{{ pc.club?.club_name ?? conceptClubName }}</div>
                                    </div>
                                    <div class="text-right text-sm font-semibold text-gray-900">
                                        {{ new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' }).format(pc.amount ?? 0) }}
                                    </div>
                                </div>
                                <div class="mt-2 text-xs text-gray-600">
                                    <div><span class="font-medium text-gray-700">{{ tr('Vence', 'Due') }}:</span> {{ formatISODate(pc.payment_expected_by) }}</div>
                                    <div><span class="font-medium text-gray-700">{{ tr('Tipo', 'Type') }}:</span> {{ pc.type }}</div>
                                    <div><span class="font-medium text-gray-700">{{ tr('Reusar', 'Reusable') }}:</span> {{ pc.reusable ? tr('Si', 'Yes') : tr('No', 'No') }}</div>
                                    <div><span class="font-medium text-gray-700">{{ tr('Pagar a', 'Pay to') }}:</span> {{ pc.pay_to }}</div>
                                    <div><span class="font-medium text-gray-700">{{ tr('Estado', 'Status') }}:</span> {{ pc.status }}</div>
                                    <div>
                                        <span class="font-medium text-gray-700">{{ tr('Alcances', 'Scopes') }}:</span>
                                        <span v-if="scopeOf(pc)">{{ scopeLabel(scopeOf(pc)) }}</span>
                                        <span v-else class="text-gray-500 italic">{{ tr('Sin alcance', 'No scope') }}</span>
                                    </div>
                                </div>
                                <div class="mt-3 flex items-center gap-2">
                                    <button
                                        type="button"
                                        class="p-1 rounded hover:bg-blue-50 focus:outline-none focus:ring-2 focus:ring-blue-400"
                                        @click.prevent="editConcept(pc)"
                                        :aria-label="tr('Editar', 'Edit')"
                                        :title="tr('Editar', 'Edit')"
                                    >
                                        <PencilSquareIcon class="h-5 w-5 text-blue-600" />
                                        <span class="sr-only">{{ tr('Editar', 'Edit') }}</span>
                                    </button>

                                    <button
                                        type="button"
                                        class="p-1 rounded hover:bg-red-50 focus:outline-none focus:ring-2 focus:ring-red-400"
                                        @click="deleteConcept(pc.id)"
                                        :aria-label="tr('Eliminar', 'Delete')"
                                        :title="tr('Eliminar', 'Delete')"
                                    >
                                        <TrashIcon class="h-5 w-5 text-red-600" />
                                        <span class="sr-only">{{ tr('Eliminar', 'Delete') }}</span>
                                    </button>
                                </div>
                            </div>
                        </div>

                        <div class="hidden md:block overflow-x-auto">
                            <table class="min-w-full border rounded text-sm">
                            <thead class="bg-gray-100">
                                <tr>
                                    <th class="p-2 text-left">{{ tr('Concepto', 'Concept') }}</th>
                                    <th class="p-2 text-left">{{ tr('Monto', 'Amount') }}</th>
                                    <th class="p-2 text-left">{{ tr('Club', 'Club') }}</th>
                                    <th class="p-2 text-left">{{ tr('Vence', 'Due') }}</th>
                                    <th class="p-2 text-left">{{ tr('Tipo', 'Type') }}</th>
                                    <th class="p-2 text-left">{{ tr('Reusar', 'Reusable') }}</th>
                                    <th class="p-2 text-left">{{ tr('Pagar a', 'Pay to') }}</th>
                                    <th class="p-2 text-left">{{ tr('Estado', 'Status') }}</th>
                                    <th class="p-2 text-left">{{ tr('Alcances', 'Scopes') }}</th>
                                    <th class="p-2 text-left">{{ tr('Acciones', 'Actions') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="pc in pagedConcepts" :key="pc.id" class="border-t">
                                    <td class="p-2">{{ pc.concept }}</td>
                                    <td class="p-2">
                                        {{ new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' })
                                            .format(pc.amount ?? 0) }}
                                    </td>
                                    <td class="p-2">{{ pc.club?.club_name ?? conceptClubName }}</td>
                                    <td class="p-2">{{ formatISODate(pc.payment_expected_by) }}</td>
                                    <td class="p-2 capitalize">{{ pc.type }}</td>
                                    <td class="p-2">{{ pc.reusable ? tr('Si', 'Yes') : tr('No', 'No') }}</td>
                                    <td class="p-2 capitalize">{{ pc.pay_to }}</td>
                                    <td class="p-2 capitalize">{{ pc.status }}</td>
                                    <td class="p-2">
                                        <span v-if="scopeOf(pc)">{{ scopeLabel(scopeOf(pc)) }}</span>
                                        <span v-else class="text-gray-500 italic">{{ tr('Sin alcance', 'No scope') }}</span>
                                    </td>
                                    <td>
                                        <button
                                            type="button"
                                            class="p-1 rounded hover:bg-blue-50 focus:outline-none focus:ring-2 focus:ring-blue-400"
                                            @click.prevent="editConcept(pc)"
                                            :aria-label="tr('Editar', 'Edit')"
                                            :title="tr('Editar', 'Edit')"
                                        >
                                            <PencilSquareIcon class="h-5 w-5 text-blue-600" />
                                            <span class="sr-only">{{ tr('Editar', 'Edit') }}</span>
                                        </button>

                                        <button
                                            type="button"
                                            class="p-1 rounded hover:bg-red-50 focus:outline-none focus:ring-2 focus:ring-red-400"
                                            @click="deleteConcept(pc.id)"
                                            :aria-label="tr('Eliminar', 'Delete')"
                                            :title="tr('Eliminar', 'Delete')"
                                        >
                                            <TrashIcon class="h-5 w-5 text-red-600" />
                                            <span class="sr-only">{{ tr('Eliminar', 'Delete') }}</span>
                                        </button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                        </div>
                        <div v-if="paymentConcepts.length > conceptsPageSize" class="mt-3 flex items-center justify-between">
                            <button class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-50 disabled:opacity-50"
                                :disabled="conceptsPage <= 1" @click="goConceptsPage(conceptsPage - 1)">
                                {{ tr('Anterior', 'Previous') }}
                            </button>
                            <div class="text-xs text-gray-600">{{ tr('Pagina', 'Page') }} {{ conceptsPage }} {{ tr('de', 'of') }} {{ totalConceptsPages }}</div>
                            <button class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-50 disabled:opacity-50"
                                :disabled="conceptsPage >= totalConceptsPages" @click="goConceptsPage(conceptsPage + 1)">
                                {{ tr('Siguiente', 'Next') }}
                            </button>
                        </div>
                    </div>
                </div>

            </div>
        </details>

    </PathfinderLayout>
</template>
