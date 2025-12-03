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
    updatePaymentConcept
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
const conceptClasses = computed(() => {
    if (!conceptClubId.value) return []
    const club = clubs.value.find(c => c.id === conceptClubId.value)
    return (club?.club_classes ?? []).slice().sort((a, b) => a.class_order - b.class_order)
})

const paymentConcepts = ref([]) // table data

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
    { value: 'club_wide', label: 'Club wide' },
    { value: 'class', label: 'Specific class' },
    { value: 'member', label: 'Specific member' },
    { value: 'staff_wide', label: 'Staff wide' },
    { value: 'staff', label: 'Specific staff' }
]

const payToOptions = [
    { value: 'church_budget', label: 'Church budget' },
    { value: 'club_budget', label: 'Club budget' },
    { value: 'conference', label: 'Conference' },
    { value: 'reimbursement_to', label: 'Reimbursement to…' }
]

const typeOptions = [
    { value: 'mandatory', label: 'Mandatory' },
    { value: 'optional', label: 'Optional' }
]

const statusOptions = [
    { value: 'active', label: 'Active' },
    { value: 'inactive', label: 'Inactive' }
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
    if (!sc) return 'No scope'
    switch (sc.scope_type) {
        case 'club_wide':
            return `Club wide (${sc.club?.club_name ?? sc.club_id})`
        case 'staff_wide':
            return `Staff wide (${sc.club?.club_name ?? sc.club_id})`
        case 'class':
            return `Class: ${sc.class?.class_name ?? sc.class_id}`
        case 'member':
            return `Member: ${sc.member?.applicant_name ?? sc.member_id}`
        case 'staff':
            return `Staff: ${sc.staff?.name ?? sc.staff_id}`
        default:
            return 'Unknown scope'
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
    if (!confirm('Delete this concept?')) return
    try {
        await deletePaymentConcept(conceptClubId.value, id)
        showToast('Concept deleted', 'success')
        await loadPaymentConcepts()
    } catch (e) {
        console.error(e)
        showToast('Failed to delete concept', 'error')
    }
}

//UPDATE PAYMENT CONCEPT
    const isEditingConcept = ref(false)
    const editingConceptId = ref(null)

    const saveBtnLabel = computed(() =>
    isEditingConcept.value ? 'Save Changes' : 'Save Concept'
    )

    function resetConceptForm(keepClub = true) {
    pcForm.reset()
    pcForm.type = 'mandatory'
    pcForm.pay_to = 'club_budget'
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
    if (!pcForm.club_id) return showToast('Please choose the concept’s club', 'error')
    if (pcForm.scopes.length === 0) return showToast('Please add at least one scope', 'error')

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
        showToast('Payment concept updated', 'success')
        } else {
        // CREATE
        await createPaymentConcept(conceptClubId.value, payload)
        showToast('Payment concept created', 'success')
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
        showToast(msg || 'Failed to save concept', 'error')
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
            showToast('Create staff first, none found','error')
            return
        }
        showToast('Staff loaded','success');
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
            showToast('Members loaded', 'success')
        } else {
            members.value = []
            alert('No members found for this club.')
        }
    } catch (error) {
        console.error('Failed to fetch members:', error)
        showToast('Error fetching members', 'error')
    }
};

const fetchClubs = async () => {
    try {
        const data = await fetchClubsByChurchId(user.value.church_id)
        clubs.value = [...data]
        hasClub.value = data.length > 0
        showToast('Clubs fetched successfully!')
    } catch (error) {
        console.error('Failed to fetch clubs:', error)
        showToast('Error loading clubs', 'error')
    }
}

// Fetch members whenever the concept club changes
watch(conceptClubId, async (id) => {
    pcForm.club_id = id || null
    ensureSingleScope()
    if (!id) {
        conceptMembers.value = []
        return
    }
    try {
        const data = await fetchMembersByClub(id)
        conceptMembers.value = Array.isArray(data) ? data : []
    } catch (e) {
        conceptMembers.value = []
    }
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



onMounted(async () => {
    await fetchClubs()
    // Optional: auto-select first club
    if (!conceptClubId.value && clubs.value.length) {
        conceptClubId.value = clubs.value[0].id
    } else {
        await loadPaymentConcepts()
    }
    ensureSingleScope()
})

</script>


<template>
    <PathfinderLayout>
        <template #title>My Club Finances</template>
        <details class="border rounded">
            <summary class="bg-gray-100 px-4 py-2 font-semibold cursor-pointer">
                Payment Concepts
            </summary>

            <div class="p-4 space-y-6">
                <!-- Form -->
                <div class="space-y-4">
                    <h3 class="text-lg font-bold">Create Payment Concept</h3>

                    <!-- Choose club -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Applies to Club</label>
                        <select v-model="conceptClubId" class="w-full mt-1 p-2 border rounded" :disabled="isEditingConcept">
                            <option value="">Select a club</option>
                            <option v-for="club in clubs" :key="club.id" :value="club.id">{{ club.club_name }}</option>
                        </select>
                    </div>

                    <div class="grid md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Concept</label>
                            <input v-model="pcForm.concept" type="text" class="w-full mt-1 p-2 border rounded"
                                placeholder="e.g., Registration Fee" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Amount</label>
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
                            <label class="block text-sm font-medium text-gray-700 mb-1">Payment Expected By</label>
                            <input v-model="pcForm.payment_expected_by" type="date"
                                class="w-full mt-1 p-2 border rounded" />
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Type</label>
                            <select v-model="pcForm.type" class="w-full mt-1 p-2 border rounded">
                                <option v-for="o in typeOptions" :key="o.value" :value="o.value">{{ o.label }}
                                </option>
                            </select>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Status</label>
                            <select v-model="pcForm.status" class="w-full mt-1 p-2 border rounded">
                                <option v-for="o in statusOptions" :key="o.value" :value="o.value">{{ o.label }}
                                </option>
                            </select>
                        </div>

                        <div class="md:col-span-2">
                            <label class="block text-sm font-medium text-gray-700 mb-1">Pay To</label>
                            <select v-model="pcForm.pay_to" class="w-full mt-1 p-2 border rounded">
                                <option v-for="o in payToOptions" :key="o.value" :value="o.value">{{ o.label }}
                                </option>
                            </select>
                            <p class="text-xs text-gray-500 mt-1">Created by: {{ user.name }}</p>
                        </div>
                    </div>

                    <!-- Conditional payee -->
                    <!-- Conditional payee -->
                    <div v-if="pcForm.pay_to === 'reimbursement_to'" class="grid md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Reimburse To (Type)</label>
                            <select v-model="pcForm.payee_type" class="w-full mt-1 p-2 border rounded">
                                <option :value="null">Select…</option>
                                <option value="StaffAdventurer">Staff</option>
                                <option value="MemberAdventurer">Member</option>
                            </select>
                            <p class="text-xs text-gray-500 mt-1" v-if="!conceptClubId">Select a club above to load
                                staff/members</p>
                        </div>

                        <!-- Staff dropdown -->
        <div v-if="pcForm.payee_type === 'StaffAdventurer'">
            <label class="block text-sm font-medium text-gray-700 mb-1">Select Staff</label>
            <select v-model="pcForm.payee_id" :disabled="!conceptClubId || staffList.length === 0"
                class="w-full mt-1 p-2 border rounded">
                <option :value="null">Select staff</option>
                <option v-for="s in staffList" :key="s.staff_id || s.id" :value="s.staff_id || s.id">
                    {{ s.name }}
                </option>
            </select>
            <p class="text-xs text-gray-500 mt-1" v-if="conceptClubId && staffList.length === 0">
                No staff found for this club.
            </p>
                        </div>

                        <!-- Member dropdown -->
        <div v-else-if="pcForm.payee_type === 'MemberAdventurer'">
            <label class="block text-sm font-medium text-gray-700 mb-1">Select Member</label>
            <select v-model="pcForm.payee_id" :disabled="!conceptClubId || members.length === 0"
                class="w-full mt-1 p-2 border rounded">
                <option :value="null">Select member</option>
                <option v-for="m in members" :key="m.member_id || m.id" :value="m.member_id || m.id">
                    {{ m.applicant_name }}
                </option>
            </select>
            <p class="text-xs text-gray-500 mt-1" v-if="conceptClubId && members.length === 0">
                No members found for this club.
            </p>
                        </div>
                    </div>

                    <!-- Scope -->
                    <div class="mt-6">
                        <h4 class="font-semibold mb-2">Scope</h4>
                        <div class="border rounded p-3" v-if="pcForm.scopes.length">
                            <div class="grid md:grid-cols-3 gap-3">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Scope Type</label>
                                    <select v-model="pcForm.scopes[0].scope_type" @change="onScopeTypeChange(pcForm.scopes[0])"
                                        class="w-full p-2 border rounded">
                                        <option v-for="o in scopeTypeOptions" :key="o.value" :value="o.value">{{
                                            o.label }}</option>
                                    </select>
                                </div>

                                <div v-if="pcForm.scopes[0].scope_type === 'club_wide' || pcForm.scopes[0].scope_type === 'staff_wide'">
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Club</label>
                                    <select v-model="pcForm.scopes[0].club_id" class="w-full p-2 border rounded">
                                        <option :value="null">Select club</option>
                                        <option v-for="c in clubs" :key="c.id" :value="c.id">{{ c.club_name }}
                                        </option>
                                    </select>
                                </div>

                                <div v-if="pcForm.scopes[0].scope_type === 'class'">
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Class</label>
                                    <select v-model="pcForm.scopes[0].class_id" class="w-full p-2 border rounded">
                                        <option :value="null">Select class</option>
                                        <option v-for="c in conceptClasses" :key="c.id" :value="c.id">{{
                                            c.class_name }}</option>
                                    </select>
                                </div>

                                <div v-if="pcForm.scopes[0].scope_type === 'member'">
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Member</label>
                                    <select v-model="pcForm.scopes[0].member_id" class="w-full p-2 border rounded">
                                        <option :value="null">Select member</option>
                                        <option v-for="m in conceptMembers" :key="m.id" :value="m.id">{{
                                            m.applicant_name }}</option>
                                    </select>
                                </div>

                                <div v-if="pcForm.scopes[0].scope_type === 'staff'">
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Staff</label>
                                    <select v-model="pcForm.scopes[0].staff_id" class="w-full p-2 border rounded">
                                        <option :value="null">Select staff</option>
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
                            Cancel
                        </button>
                    </div>
                </div>

                <!-- List -->
                <div class="pt-6">
                    <div class="flex items-center justify-between mb-2">
                        <h3 class="text-lg font-bold">Existing Payment Concepts</h3>
                        <button type="button" @click="loadPaymentConcepts"
                            class="px-3 py-1 bg-gray-700 text-white rounded text-sm hover:bg-gray-800">
                            Refresh
                        </button>
                    </div>

                    <div v-if="paymentConcepts.length === 0" class="text-sm text-gray-500">
                        No payment concepts created.
                    </div>

                    <div v-else class="overflow-x-auto">
                        <table class="min-w-full border rounded text-sm">
                            <thead class="bg-gray-100">
                                <tr>
                                    <th class="p-2 text-left">Concept</th>
                                    <th class="p-2 text-left">Amount</th>
                                    <th class="p-2 text-left">Club</th>
                                    <th class="p-2 text-left">Due</th>
                                    <th class="p-2 text-left">Type</th>
                                    <th class="p-2 text-left">Pay To</th>
                                    <th class="p-2 text-left">Status</th>
                                    <th class="p-2 text-left">Scopes</th>
                                    <th class="p-2 text-left">Actions</th>
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
                                        <span v-else class="text-gray-500 italic">No scope</span>
                                    </td>
                                    <td>
                                        <button
                                            type="button"
                                            class="p-1 rounded hover:bg-blue-50 focus:outline-none focus:ring-2 focus:ring-blue-400"
                                            @click.prevent="editConcept(pc)"
                                            aria-label="Edit"
                                            title="Edit"
                                        >
                                            <PencilSquareIcon class="h-5 w-5 text-blue-600" />
                                            <span class="sr-only">Edit</span>
                                        </button>

                                        <button
                                            type="button"
                                            class="p-1 rounded hover:bg-red-50 focus:outline-none focus:ring-2 focus:ring-red-400"
                                            @click="deleteConcept(pc.id)"
                                            aria-label="Delete"
                                            title="Delete"
                                        >
                                            <TrashIcon class="h-5 w-5 text-red-600" />
                                            <span class="sr-only">Delete</span>
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
