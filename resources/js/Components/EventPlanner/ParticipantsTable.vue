<script setup>
import { computed, ref } from 'vue'
import axios from 'axios'
import { Link } from '@inertiajs/vue3'

const props = defineProps({
    participants: {
        type: Array,
        default: () => []
    },
    eventId: {
        type: Number,
        required: true
    },
    members: {
        type: Array,
        default: () => []
    },
    classes: {
        type: Array,
        default: () => []
    },
    staff: {
        type: Array,
        default: () => []
    },
    parents: {
        type: Array,
        default: () => []
    },
    paymentSummary: {
        type: Object,
        default: () => ({ total_received: 0, by_member_id: {}, by_staff_id: {} })
    },
    paymentConfig: {
        type: Object,
        default: () => ({ concept_id: null, amount: null, is_payable: false })
    },
    clubId: {
        type: Number,
        required: true
    }
})

const emit = defineEmits(['updated'])
const highlightIds = ref(new Set())
const selectedParticipantIds = ref([])

const refresh = async () => {
    const { data } = await axios.get(route('event-participants.index', { event: props.eventId }))
    emit('updated', data.participants || [])
}

const markHighlights = (ids) => {
    if (!ids.length) return
    highlightIds.value = new Set(ids)
    setTimeout(() => {
        highlightIds.value = new Set()
    }, 2200)
}

const addParticipant = async (payload) => {
    const { data } = await axios.post(route('event-participants.store', { event: props.eventId }), payload)
    return data?.participant?.id || null
}

const participantType = ref('club_member')
const memberSelectMode = ref('manual')
const selectedMemberIds = ref([])
const selectedClassIds = ref([])
const selectedStaffIds = ref([])
const selectedParentIds = ref([])
const customParticipant = ref({ name: '', role: 'invitee', status: 'invited', note: '' })

const existingMemberIds = computed(() => new Set(props.participants.map((p) => p.member_id).filter(Boolean)))
const existingStaffNames = computed(() => new Set(props.participants.filter((p) => p.role === 'staff').map((p) => p.participant_name)))
const existingParentNames = computed(() => new Set(props.participants.filter((p) => p.role === 'parent').map((p) => p.participant_name)))

const availableMembers = computed(() => {
    const existing = existingMemberIds.value
    return props.members.filter((member) => !existing.has(member.member_id))
})

const classNameById = computed(() => {
    const map = new Map()
    for (const clubClass of props.classes) {
        map.set(clubClass.id, clubClass.class_name)
    }
    return map
})

const memberById = computed(() => {
    const map = new Map()
    for (const member of props.members) {
        map.set(member.member_id, member)
    }
    return map
})

const parentByName = computed(() => {
    const map = new Map()
    for (const parent of props.parents) {
        if (parent.name) {
            map.set(parent.name, parent)
        }
    }
    return map
})

const paymentByMember = computed(() => {
    const map = new Map()
    const entries = props.paymentSummary?.by_member_id || {}
    for (const [id, total] of Object.entries(entries)) {
        map.set(Number(id), Number(total))
    }
    return map
})

const paymentByStaff = computed(() => {
    const map = new Map()
    const entries = props.paymentSummary?.by_staff_id || {}
    for (const [id, total] of Object.entries(entries)) {
        map.set(Number(id), Number(total))
    }
    return map
})

const staffByName = computed(() => {
    const map = new Map()
    for (const staffMember of props.staff) {
        if (staffMember?.name) {
            map.set(staffMember.name, staffMember)
        }
    }
    return map
})

const paymentStatusFor = (participant) => {
    if (!props.paymentConfig?.concept_id) {
        return { status: 'na', amount: 0, payerType: null, payerId: null }
    }

    if (participant.member_id) {
        const amount = paymentByMember.value.get(Number(participant.member_id)) || 0
        return {
            status: amount > 0 ? 'paid' : 'unpaid',
            amount,
            payerType: 'member',
            payerId: participant.member_id
        }
    }

    if (participant.role === 'parent') {
        const parent = parentByName.value.get(participant.participant_name)
        const childId = parent?.children?.[0]?.id
        if (childId) {
            const amount = paymentByMember.value.get(Number(childId)) || 0
            return {
                status: amount > 0 ? 'paid' : 'unpaid',
                amount,
                payerType: 'readonly',
                payerId: null
            }
        }
    }

    return { status: 'na', amount: 0, payerType: null, payerId: null }
}

const paymentLinkFor = (participant) => {
    const status = paymentStatusFor(participant)
    if (!props.paymentConfig?.concept_id || !status.payerType || !status.payerId || status.payerType === 'readonly') {
        return null
    }
    if (status.status === 'paid') return null
    return route('club.director.payments', {
        club_id: props.clubId,
        concept_id: props.paymentConfig.concept_id,
        amount: props.paymentConfig.amount ?? null,
        member_id: status.payerType === 'member' ? status.payerId : null,
        staff_id: status.payerType === 'staff' ? status.payerId : null,
    })
}

const restrictedClassIds = computed(() => {
    if (participantType.value !== 'club_member') return []
    if (memberSelectMode.value === 'class') return selectedClassIds.value
    if (memberSelectMode.value === 'club') {
        return props.members.map((m) => m.class_id).filter(Boolean)
    }
    return []
})

const restrictedParentIds = computed(() => {
    if (!restrictedClassIds.value.length) return []
    const classSet = new Set(restrictedClassIds.value)
    return props.parents
        .filter((parent) => (parent.children || []).some((child) => classSet.has(child.class_id)))
        .map((parent) => parent.id)
})

const restrictedStaffIds = computed(() => {
    if (!restrictedClassIds.value.length) return []
    const classSet = new Set(restrictedClassIds.value)
    return props.staff
        .filter((staffMember) => (staffMember.classes || []).some((c) => classSet.has(c.id)))
        .map((staffMember) => staffMember.id)
})

const availableParents = computed(() => {
    if (!restrictedParentIds.value.length) return props.parents
    const restricted = new Set(restrictedParentIds.value)
    return props.parents.filter((parent) => !restricted.has(parent.id))
})

const availableStaff = computed(() => {
    if (!restrictedStaffIds.value.length) return props.staff
    const restricted = new Set(restrictedStaffIds.value)
    return props.staff.filter((staffMember) => !restricted.has(staffMember.id))
})

const addMembers = async (memberIds) => {
    const addedIds = []
    for (const memberId of memberIds) {
        if (existingMemberIds.value.has(memberId)) continue
        const member = props.members.find((item) => item.member_id === memberId)
        const name = member?.applicant_name || `Member #${memberId}`
        const id = await addParticipant({
            member_id: memberId,
            participant_name: name,
            role: 'kid',
            status: 'invited',
        })
        if (id) addedIds.push(id)

        const parentId = member?.parent_id
        if (parentId) {
            const parent = props.parents.find((p) => p.id === parentId)
            const parentName = parent?.name
            if (parentName && !existingParentNames.value.has(parentName)) {
                const parentAddedId = await addParticipant({
                    participant_name: parentName,
                    role: 'parent',
                    status: 'invited',
                })
                if (parentAddedId) addedIds.push(parentAddedId)
            }
        }
    }
    return addedIds
}

const addStaffForClasses = async (classIds) => {
    for (const staffMember of props.staff) {
        const staffClasses = staffMember.classes || []
        const isMatch = staffClasses.some((item) => classIds.includes(item.id))
        if (!isMatch) continue
        const name = staffMember.name || `Staff #${staffMember.id}`
        if (existingStaffNames.value.has(name)) continue
        await axios.post(route('event-participants.store', { event: props.eventId }), {
            participant_name: name,
            role: 'staff',
            status: 'invited',
        })
    }
}

const addMembersManual = async () => {
    const addedIds = await addMembers(selectedMemberIds.value)
    selectedMemberIds.value = []
    await refresh()
    markHighlights(addedIds)
}

const addMembersByClass = async () => {
    const memberIds = props.members
        .filter((m) => selectedClassIds.value.includes(m.class_id))
        .map((m) => m.member_id)
    const addedIds = await addMembers(memberIds)
    await addStaffForClasses(selectedClassIds.value)
    selectedClassIds.value = []
    await refresh()
    markHighlights(addedIds)
}

const addMembersWholeClub = async () => {
    const memberIds = props.members.map((m) => m.member_id)
    const classIds = [...new Set(props.members.map((m) => m.class_id).filter(Boolean))]
    const addedIds = await addMembers(memberIds)
    await addStaffForClasses(classIds)
    await refresh()
    markHighlights(addedIds)
}

const addStaff = async () => {
    const addedIds = []
    for (const staffId of selectedStaffIds.value) {
        const staffMember = props.staff.find((item) => item.id === staffId)
        const name = staffMember?.name || `Staff #${staffId}`
        if (existingStaffNames.value.has(name)) continue
        const id = await addParticipant({
            participant_name: name,
            role: 'staff',
            status: 'invited',
        })
        if (id) addedIds.push(id)
    }
    selectedStaffIds.value = []
    await refresh()
    markHighlights(addedIds)
}

const addParents = async () => {
    const addedIds = []
    for (const parentId of selectedParentIds.value) {
        const parent = props.parents.find((item) => item.id === parentId)
        const name = parent?.name || `Parent #${parentId}`
        if (existingParentNames.value.has(name)) continue
        const id = await addParticipant({
            participant_name: name,
            role: 'parent',
            status: 'invited',
        })
        if (id) addedIds.push(id)
    }
    selectedParentIds.value = []
    await refresh()
    markHighlights(addedIds)
}

const addCustom = async () => {
    if (!customParticipant.value.name.trim()) return
    const id = await addParticipant({
        participant_name: customParticipant.value.name.trim(),
        role: customParticipant.value.role,
        status: customParticipant.value.status,
        emergency_contact_json: customParticipant.value.note
            ? { note: customParticipant.value.note }
            : null,
    })
    customParticipant.value = { name: '', role: 'invitee', status: 'invited', note: '' }
    await refresh()
    markHighlights(id ? [id] : [])
}

const updateStatus = async (participant, status, refreshAfter = true) => {
    await axios.put(route('event-participants.update', { eventParticipant: participant.id }), {
        member_id: participant.member_id ?? null,
        participant_name: participant.participant_name,
        role: participant.role,
        status,
        permission_received: participant.permission_received ?? false,
        medical_form_received: participant.medical_form_received ?? false,
        emergency_contact_json: participant.emergency_contact_json ?? null,
    })
    if (refreshAfter) {
        await refresh()
    }
}

const removeParticipant = async (participant) => {
    if (!confirm(`Remove ${participant.participant_name}?`)) return
    await axios.delete(route('event-participants.destroy', { eventParticipant: participant.id }))
    await refresh()
}

const confirmAll = async () => {
    if (!props.participants.length) return
    if (!confirm('Confirm all participants?')) return
    for (const participant of props.participants) {
        if (participant.status === 'confirmed') continue
        await updateStatus(participant, 'confirmed', false)
    }
    await refresh()
}

const deleteAll = async () => {
    if (!props.participants.length) return
    if (!confirm('Remove all participants? This cannot be undone.')) return
    for (const participant of props.participants) {
        await axios.delete(route('event-participants.destroy', { eventParticipant: participant.id }))
    }
    await refresh()
}

const deleteSelected = async () => {
    if (!selectedParticipantIds.value.length) return
    if (!confirm('Remove selected participants?')) return
    for (const id of selectedParticipantIds.value) {
        await axios.delete(route('event-participants.destroy', { eventParticipant: id }))
    }
    selectedParticipantIds.value = []
    await refresh()
}

const confirmSelected = async () => {
    if (!selectedParticipantIds.value.length) return
    if (!confirm('Confirm selected participants?')) return
    for (const participant of props.participants.filter((p) => selectedParticipantIds.value.includes(p.id))) {
        if (participant.status === 'confirmed') continue
        await updateStatus(participant, 'confirmed', false)
    }
    selectedParticipantIds.value = []
    await refresh()
}
</script>

<template>
    <div class="space-y-4">
        <div class="bg-white rounded-lg border p-4 space-y-4">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-3 items-end">
                <div>
                    <div class="text-xs font-semibold text-gray-600 mb-1">Participant Type</div>
                    <select v-model="participantType" class="w-full border rounded px-2 py-1 text-sm">
                        <option value="club_member">Club Member</option>
                        <option value="club_staff">Club Staff</option>
                        <option value="club_parent">Club Parent</option>
                        <option value="driver">Driver</option>
                        <option value="invitee">Invitee</option>
                        <option value="other">Other</option>
                    </select>
                </div>
                <div v-if="participantType === 'club_member'">
                    <div class="text-xs font-semibold text-gray-600 mb-1">Member Selection Mode</div>
                    <select v-model="memberSelectMode" class="w-full border rounded px-2 py-1 text-sm">
                        <option value="manual">Select Members</option>
                        <option value="class">By Class</option>
                        <option value="club">Whole Club</option>
                    </select>
                </div>
            </div>

            <div v-if="participantType === 'club_member' && memberSelectMode === 'manual'" class="space-y-2">
                <div class="text-xs font-semibold text-gray-600">Members</div>
                <select v-model="selectedMemberIds" multiple class="w-full border rounded px-2 py-1 text-sm">
                    <option v-for="member in availableMembers" :key="member.member_id" :value="member.member_id">
                        {{ member.applicant_name }} ({{ classNameById.get(member.class_id) || 'Class —' }})
                    </option>
                </select>
                <button type="button" class="px-3 py-1 rounded text-sm bg-blue-600 text-white" @click="addMembersManual">
                    Add Selected Members
                </button>
            </div>

            <div v-if="participantType === 'club_member' && memberSelectMode === 'class'" class="space-y-2">
                <div class="text-xs font-semibold text-gray-600">Classes</div>
                <select v-model="selectedClassIds" multiple class="w-full border rounded px-2 py-1 text-sm">
                    <option v-for="clubClass in classes" :key="clubClass.id" :value="clubClass.id">
                        {{ clubClass.class_name }}
                    </option>
                </select>
                <button type="button" class="px-3 py-1 rounded text-sm bg-blue-600 text-white" @click="addMembersByClass">
                    Add Members + Parents + Staff
                </button>
            </div>

            <div v-if="participantType === 'club_member' && memberSelectMode === 'club'" class="space-y-2">
                <div class="text-xs text-gray-600">Adds all members, their parents, and class staff.</div>
                <button type="button" class="px-3 py-1 rounded text-sm bg-blue-600 text-white" @click="addMembersWholeClub">
                    Add Whole Club
                </button>
            </div>

            <div v-if="participantType === 'club_staff'" class="space-y-2">
                <div class="text-xs font-semibold text-gray-600">Staff</div>
                <select v-model="selectedStaffIds" multiple class="w-full border rounded px-2 py-1 text-sm">
                    <option v-for="staffMember in availableStaff" :key="staffMember.id" :value="staffMember.id">
                        {{ staffMember.name }} ({{ staffMember.type }})
                    </option>
                </select>
                <button type="button" class="px-3 py-1 rounded text-sm bg-blue-600 text-white" @click="addStaff">
                    Add Staff
                </button>
            </div>

            <div v-if="participantType === 'club_parent'" class="space-y-2">
                <div class="text-xs font-semibold text-gray-600">Parents</div>
                <select v-model="selectedParentIds" multiple class="w-full border rounded px-2 py-1 text-sm">
                    <option v-for="parent in availableParents" :key="parent.id" :value="parent.id">
                        {{ parent.name }} ({{ parent.email || 'no email' }})
                    </option>
                </select>
                <button type="button" class="px-3 py-1 rounded text-sm bg-blue-600 text-white" @click="addParents">
                    Add Parents
                </button>
            </div>

            <div v-if="['driver','invitee','other'].includes(participantType)" class="border-t pt-3">
                <div class="text-xs font-semibold text-gray-600 mb-1">Custom Participant</div>
                <div class="grid grid-cols-1 md:grid-cols-4 gap-2">
                    <input v-model="customParticipant.name" class="border rounded px-2 py-1 text-sm" placeholder="Name" />
                    <select v-model="customParticipant.role" class="border rounded px-2 py-1 text-sm">
                        <option value="driver">Driver</option>
                        <option value="invitee">Invitee</option>
                        <option value="other">Other</option>
                        <option value="guest">Guest</option>
                    </select>
                    <select v-model="customParticipant.status" class="border rounded px-2 py-1 text-sm">
                        <option value="invited">Invited</option>
                        <option value="confirmed">Confirmed</option>
                        <option value="cancelled">Cancelled</option>
                    </select>
                    <input v-model="customParticipant.note" class="border rounded px-2 py-1 text-sm" placeholder="Note (optional)" />
                </div>
                <button type="button" class="mt-2 px-3 py-1 rounded text-sm bg-gray-200 text-gray-700" @click="addCustom">
                    Add Participant
                </button>
            </div>
        </div>

        <div class="bg-white rounded-lg border p-3 flex flex-wrap items-center gap-2 text-xs">
            <button type="button" class="px-2 py-1 rounded bg-green-600 text-white" @click="confirmAll">
                Confirm All
            </button>
            <button type="button" class="px-2 py-1 rounded bg-gray-200 text-gray-700" @click="confirmSelected" :disabled="!selectedParticipantIds.length">
                Confirm Selected
            </button>
            <button type="button" class="px-2 py-1 rounded bg-red-600 text-white" @click="deleteAll">
                Delete All
            </button>
            <button type="button" class="px-2 py-1 rounded bg-red-100 text-red-700" @click="deleteSelected" :disabled="!selectedParticipantIds.length">
                Delete Selected
            </button>
        </div>

        <div class="overflow-x-auto bg-white rounded-lg border">
        <table class="min-w-full text-sm">
            <thead class="bg-gray-50 text-gray-600">
                <tr>
                    <th class="text-left px-4 py-2">
                        <input
                            type="checkbox"
                            :checked="selectedParticipantIds.length && selectedParticipantIds.length === participants.length"
                            @change="(e) => { selectedParticipantIds = e.target.checked ? participants.map(p => p.id) : [] }"
                        />
                    </th>
                    <th class="text-left px-4 py-2">Name</th>
                    <th class="text-left px-4 py-2">Role</th>
                    <th class="text-left px-4 py-2">Status</th>
                    <th class="text-left px-4 py-2">Payment</th>
                    <th class="text-right px-4 py-2">Actions</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="participant in participants" :key="participant.id" class="border-t" :class="highlightIds.has(participant.id) ? 'participant-highlight' : ''">
                    <td class="px-4 py-2">
                        <input
                            type="checkbox"
                            :value="participant.id"
                            v-model="selectedParticipantIds"
                        />
                    </td>
                    <td class="px-4 py-2">
                        <div>{{ participant.participant_name }}</div>
                        <div v-if="participant.member_id" class="text-xs text-gray-500">
                            {{ classNameById.get(memberById.get(participant.member_id)?.class_id) || 'Class —' }}
                        </div>
                        <div v-else-if="participant.role === 'parent'" class="text-xs text-gray-500">
                            {{ (parentByName.get(participant.participant_name)?.children || [])[0]?.name || 'Child —' }}
                        </div>
                    </td>
                    <td class="px-4 py-2 capitalize">{{ participant.role }}</td>
                    <td class="px-4 py-2">
                        <select class="border rounded px-2 py-1 text-xs" :value="participant.status" @change="(e) => updateStatus(participant, e.target.value)">
                            <option value="invited">Invited</option>
                            <option value="confirmed">Confirmed</option>
                            <option value="cancelled">Cancelled</option>
                        </select>
                    </td>
                    <td class="px-4 py-2">
                        <div class="flex items-center gap-2 text-xs">
                            <span
                                class="inline-flex items-center justify-center w-2.5 h-2.5 rounded-full"
                                :class="{
                                    'bg-emerald-500': paymentStatusFor(participant).status === 'paid',
                                    'bg-red-500': paymentStatusFor(participant).status === 'unpaid',
                                    'bg-gray-300': paymentStatusFor(participant).status === 'na',
                                }"
                                :title="paymentStatusFor(participant).status === 'paid'
                                    ? 'Partial payment received'
                                    : (paymentStatusFor(participant).status === 'unpaid' ? 'No payment recorded' : 'Not applicable')"
                            />
                            <template v-if="participant.role === 'parent' && paymentStatusFor(participant).payerType === 'readonly'">
                                <span
                                    class="text-xs"
                                    :class="paymentStatusFor(participant).status === 'paid' ? 'text-emerald-600' : 'text-red-600'"
                                >
                                    {{ paymentStatusFor(participant).status === 'paid' ? 'Payment submitted' : 'Child payment' }}
                                </span>
                            </template>
                            <span
                                v-else-if="paymentStatusFor(participant).status === 'paid' && participant.member_id"
                                class="text-emerald-600 text-xs"
                            >
                                Payment submitted
                            </span>
                            <Link
                                v-else-if="paymentLinkFor(participant)"
                                :href="paymentLinkFor(participant)"
                                class="text-blue-600 hover:text-blue-700"
                            >
                                Record payment
                            </Link>
                            <span v-else class="text-gray-400">—</span>
                        </div>
                    </td>
                    <td class="px-4 py-2 text-right">
                        <button type="button" class="text-xs text-red-600" @click="removeParticipant(participant)">
                            Remove
                        </button>
                    </td>
                </tr>
                <tr v-if="!participants.length">
                    <td colspan="6" class="px-4 py-6 text-center text-gray-500">No participants yet.</td>
                </tr>
            </tbody>
        </table>
        </div>
    </div>
</template>

<style scoped>
.participant-highlight {
    animation: highlight-fade 2.2s ease-out;
    box-shadow: inset 0 0 0 2px rgba(34, 197, 94, 0.55);
    background-color: rgba(34, 197, 94, 0.08);
}

@keyframes highlight-fade {
    0% {
        background-color: rgba(34, 197, 94, 0.18);
        box-shadow: inset 0 0 0 2px rgba(34, 197, 94, 0.75);
    }
    100% {
        background-color: rgba(34, 197, 94, 0);
        box-shadow: inset 0 0 0 0 rgba(34, 197, 94, 0);
    }
}
</style>
