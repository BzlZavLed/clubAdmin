<script setup>
import { ref } from 'vue'
import PathfinderLayout from '@/Layouts/PathfinderLayout.vue'
import { useForm } from '@inertiajs/vue3'
import { useGeneral } from '@/Composables/useGeneral'
import { PencilIcon, EyeIcon } from '@heroicons/vue/24/outline'

const props = defineProps({
    children: {
        type: Array,
        default: () => []
    }
})

const { showToast } = useGeneral()

const editModalOpen = ref(false)
const editingChild = ref(null)
const expanded = ref(new Set())

const form = useForm({
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

const openEdit = (child) => {
    editingChild.value = child
    form.reset()
    form.applicant_name = child.applicant_name || ''
    form.birthdate = child.birthdate ? String(child.birthdate).slice(0, 10) : ''
    form.age = child.age || ''
    form.grade = child.grade || ''
    form.mailing_address = child.mailing_address || ''
    form.cell_number = child.cell_number || ''
    form.emergency_contact = child.emergency_contact || ''
    form.investiture_classes = Array.isArray(child.investiture_classes) ? child.investiture_classes : (child.investiture_classes ? [child.investiture_classes] : [])
    form.allergies = child.allergies || ''
    form.physical_restrictions = child.physical_restrictions || ''
    form.health_history = child.health_history || ''
    form.parent_name = child.parent_name || ''
    form.parent_cell = child.parent_cell || ''
    form.home_address = child.home_address || ''
    form.email_address = child.email_address || ''
    form.signature = child.signature || ''
    editModalOpen.value = true
}

const toggleExpand = (id) => {
    const next = new Set(expanded.value)
    if (next.has(id)) {
        next.delete(id)
    } else {
        next.add(id)
    }
    expanded.value = next
}

const submit = () => {
    if (!editingChild.value) return
    form.put(route('parent.children.update', editingChild.value.id), {
        preserveScroll: true,
        onSuccess: () => {
            showToast('Child updated', 'success')
            editModalOpen.value = false
        },
        onError: () => {
            const firstError = Object.values(form.errors)[0]
            if (firstError) showToast(firstError, 'error')
        }
    })
}
</script>

<template>
    <PathfinderLayout>
        <template #title>My Children</template>

        <div class="space-y-4">
            <div class="bg-white border rounded shadow-sm p-4">
                <h2 class="text-xl font-semibold text-gray-800">Children registered</h2>
                <p class="text-sm text-gray-600">View and update your children’s application data.</p>
            </div>

            <div class="bg-white border rounded shadow-sm p-4">
                <div class="overflow-x-auto">
                    <div class="hidden sm:block">
                        <table class="min-w-full text-sm">
                            <thead>
                                <tr class="text-left text-gray-500">
                                    <th class="py-2 pr-4">Name</th>
                                    <th class="py-2 pr-4">Club</th>
                                    <th class="py-2 pr-4">Grade</th>
                                    <th class="py-2 pr-4">Birthdate</th>
                                    <th class="py-2 pr-4"></th>
                                </tr>
                            </thead>
                            <tbody>
                                <template v-for="child in children" :key="child.id">
                                    <tr class="border-t">
                                        <td class="py-2 pr-4">{{ child.applicant_name }}</td>
                                        <td class="py-2 pr-4">{{ child.club_name || '—' }}</td>
                                        <td class="py-2 pr-4">{{ child.grade || '—' }}</td>
                                        <td class="py-2 pr-4">{{ child.birthdate ? String(child.birthdate).slice(0, 10) : '—' }}</td>
                                        <td class="py-2 pr-4 flex items-center gap-2">
                                            <button class="text-blue-600 text-sm inline-flex items-center gap-1" @click="toggleExpand(child.id)" :title="expanded.has(child.id) ? 'Hide details' : 'View details'">
                                                <EyeIcon class="w-4 h-4" />
                                            </button>
                                            <button class="text-blue-600 text-sm inline-flex items-center gap-1" @click="openEdit(child)" title="Edit">
                                                <PencilIcon class="w-4 h-4" />
                                            </button>
                                        </td>
                                    </tr>
                                    <tr v-if="expanded.has(child.id)">
                                        <td colspan="5" class="bg-gray-50 border-t px-4 py-3 text-sm text-gray-700">
                                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                                <div><span class="font-semibold">Birthdate:</span> {{ child.birthdate ? String(child.birthdate).slice(0, 10) : '—' }}</div>
                                                <div><span class="font-semibold">Age:</span> {{ child.age || '—' }}</div>
                                                <div><span class="font-semibold">Cell:</span> {{ child.cell_number || '—' }}</div>
                                                <div><span class="font-semibold">Emergency Contact:</span> {{ child.emergency_contact || '—' }}</div>
                                                <div class="sm:col-span-2"><span class="font-semibold">Mailing Address:</span> {{ child.mailing_address || '—' }}</div>
                                                <div class="sm:col-span-2"><span class="font-semibold">Allergies:</span> {{ child.allergies || '—' }}</div>
                                                <div class="sm:col-span-2"><span class="font-semibold">Physical Restrictions:</span> {{ child.physical_restrictions || '—' }}</div>
                                                <div class="sm:col-span-2"><span class="font-semibold">Health History:</span> {{ child.health_history || '—' }}</div>
                                                <div><span class="font-semibold">Parent Name:</span> {{ child.parent_name || '—' }}</div>
                                                <div><span class="font-semibold">Parent Cell:</span> {{ child.parent_cell || '—' }}</div>
                                                <div class="sm:col-span-2"><span class="font-semibold">Email:</span> {{ child.email_address || '—' }}</div>
                                                <div class="sm:col-span-2"><span class="font-semibold">Signature:</span> {{ child.signature || '—' }}</div>
                                            </div>
                                        </td>
                                    </tr>
                                </template>
                                <tr v-if="children.length === 0">
                                    <td colspan="5" class="py-4 text-center text-gray-500">No children found.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- Mobile cards -->
                    <div class="sm:hidden space-y-3">
                        <div v-for="child in children" :key="child.id" class="border rounded-lg p-3 shadow-sm bg-white">
                            <div class="flex items-center justify-between">
                                <div>
                                    <div class="font-semibold text-gray-900">{{ child.applicant_name }}</div>
                                    <div class="text-xs text-gray-600">{{ child.club_name || '—' }}</div>
                                </div>
                                <div class="flex gap-2">
                                    <button class="text-blue-600 text-sm inline-flex items-center gap-1" @click="toggleExpand(child.id)" :title="expanded.has(child.id) ? 'Hide details' : 'View details'">
                                        <EyeIcon class="w-4 h-4" />
                                    </button>
                                    <button class="text-blue-600 text-sm inline-flex items-center gap-1" @click="openEdit(child)" title="Edit">
                                        <PencilIcon class="w-4 h-4" />
                                    </button>
                                </div>
                            </div>
                            <div class="text-xs text-gray-700 mt-1">Grade: {{ child.grade || '—' }}</div>
                            <div class="text-xs text-gray-700">Birthdate: {{ child.birthdate ? String(child.birthdate).slice(0, 10) : '—' }}</div>
                            <div v-if="expanded.has(child.id)" class="mt-2 space-y-1 text-xs text-gray-700">
                                <div><span class="font-semibold">Age:</span> {{ child.age || '—' }}</div>
                                <div><span class="font-semibold">Cell:</span> {{ child.cell_number || '—' }}</div>
                                <div><span class="font-semibold">Emergency Contact:</span> {{ child.emergency_contact || '—' }}</div>
                                <div><span class="font-semibold">Mailing Address:</span> {{ child.mailing_address || '—' }}</div>
                                <div><span class="font-semibold">Allergies:</span> {{ child.allergies || '—' }}</div>
                                <div><span class="font-semibold">Physical Restrictions:</span> {{ child.physical_restrictions || '—' }}</div>
                                <div><span class="font-semibold">Health History:</span> {{ child.health_history || '—' }}</div>
                                <div><span class="font-semibold">Parent Name:</span> {{ child.parent_name || '—' }}</div>
                                <div><span class="font-semibold">Parent Cell:</span> {{ child.parent_cell || '—' }}</div>
                                <div><span class="font-semibold">Email:</span> {{ child.email_address || '—' }}</div>
                                <div><span class="font-semibold">Signature:</span> {{ child.signature || '—' }}</div>
                            </div>
                        </div>
                        <div v-if="children.length === 0" class="text-center text-gray-500 text-sm">No children found.</div>
                    </div>
                </div>
            </div>

            <!-- Edit modal -->
            <div v-if="editModalOpen" class="fixed inset-0 bg-black/40 flex items-center justify-center z-50">
                <div class="bg-white rounded-lg shadow-lg w-full max-w-4xl p-6 space-y-4 overflow-y-auto max-h-[90vh]">
                    <div class="flex items-center justify-between">
                        <h4 class="text-lg font-semibold">Edit Child</h4>
                        <button class="text-gray-500" @click="editModalOpen = false">✕</button>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div>
                            <label class="text-sm text-gray-700">Name</label>
                            <input v-model="form.applicant_name" class="w-full border rounded px-3 py-2 text-sm" />
                            <p v-if="form.errors.applicant_name" class="text-red-600 text-xs mt-1">{{ form.errors.applicant_name }}</p>
                        </div>
                        <div>
                            <label class="text-sm text-gray-700">Birthdate</label>
                            <input type="date" v-model="form.birthdate" class="w-full border rounded px-3 py-2 text-sm" />
                            <p v-if="form.errors.birthdate" class="text-red-600 text-xs mt-1">{{ form.errors.birthdate }}</p>
                        </div>
                        <div>
                            <label class="text-sm text-gray-700">Age</label>
                            <input type="number" v-model="form.age" class="w-full border rounded px-3 py-2 text-sm" />
                        </div>
                        <div>
                            <label class="text-sm text-gray-700">Grade</label>
                            <input v-model="form.grade" class="w-full border rounded px-3 py-2 text-sm" />
                            <p v-if="form.errors.grade" class="text-red-600 text-xs mt-1">{{ form.errors.grade }}</p>
                        </div>
                        <div class="sm:col-span-2">
                            <label class="text-sm text-gray-700">Mailing Address</label>
                            <input v-model="form.mailing_address" class="w-full border rounded px-3 py-2 text-sm" />
                            <p v-if="form.errors.mailing_address" class="text-red-600 text-xs mt-1">{{ form.errors.mailing_address }}</p>
                        </div>
                        <div>
                            <label class="text-sm text-gray-700">Cell Number</label>
                            <input v-model="form.cell_number" class="w-full border rounded px-3 py-2 text-sm" />
                            <p v-if="form.errors.cell_number" class="text-red-600 text-xs mt-1">{{ form.errors.cell_number }}</p>
                        </div>
                        <div>
                            <label class="text-sm text-gray-700">Emergency Contact</label>
                            <input v-model="form.emergency_contact" class="w-full border rounded px-3 py-2 text-sm" />
                            <p v-if="form.errors.emergency_contact" class="text-red-600 text-xs mt-1">{{ form.errors.emergency_contact }}</p>
                        </div>
                        <div class="sm:col-span-2">
                            <label class="text-sm text-gray-700">Investiture Classes</label>
                            <div class="flex flex-wrap gap-2 mt-1">
                                <label
                                    v-for="level in ['Little Lambs', 'Eager Beavers', 'Busy Bee', 'Sunbeam', 'Builder', 'Helping Hand']"
                                    :key="level" class="inline-flex items-center text-xs">
                                    <input type="checkbox" :value="level" v-model="form.investiture_classes" class="mr-2" />
                                    {{ level }}
                                </label>
                            </div>
                        </div>
                        <div class="sm:col-span-2">
                            <label class="text-sm text-gray-700">Allergies</label>
                            <textarea v-model="form.allergies" class="w-full border rounded px-3 py-2 text-sm"></textarea>
                        </div>
                        <div class="sm:col-span-2">
                            <label class="text-sm text-gray-700">Physical Restrictions</label>
                            <textarea v-model="form.physical_restrictions" class="w-full border rounded px-3 py-2 text-sm"></textarea>
                        </div>
                        <div class="sm:col-span-2">
                            <label class="text-sm text-gray-700">Health History</label>
                            <textarea v-model="form.health_history" class="w-full border rounded px-3 py-2 text-sm"></textarea>
                        </div>
                        <div>
                            <label class="text-sm text-gray-700">Parent Name</label>
                            <input v-model="form.parent_name" class="w-full border rounded px-3 py-2 text-sm" />
                            <p v-if="form.errors.parent_name" class="text-red-600 text-xs mt-1">{{ form.errors.parent_name }}</p>
                        </div>
                        <div>
                            <label class="text-sm text-gray-700">Parent Cell</label>
                            <input v-model="form.parent_cell" class="w-full border rounded px-3 py-2 text-sm" />
                            <p v-if="form.errors.parent_cell" class="text-red-600 text-xs mt-1">{{ form.errors.parent_cell }}</p>
                        </div>
                        <div class="sm:col-span-2">
                            <label class="text-sm text-gray-700">Home Address</label>
                            <input v-model="form.home_address" class="w-full border rounded px-3 py-2 text-sm" />
                            <p v-if="form.errors.home_address" class="text-red-600 text-xs mt-1">{{ form.errors.home_address }}</p>
                        </div>
                        <div>
                            <label class="text-sm text-gray-700">Email Address</label>
                            <input type="email" v-model="form.email_address" class="w-full border rounded px-3 py-2 text-sm" />
                            <p v-if="form.errors.email_address" class="text-red-600 text-xs mt-1">{{ form.errors.email_address }}</p>
                        </div>
                        <div>
                            <label class="text-sm text-gray-700">Signature (Typed)</label>
                            <input v-model="form.signature" class="w-full border rounded px-3 py-2 text-sm" />
                            <p v-if="form.errors.signature" class="text-red-600 text-xs mt-1">{{ form.errors.signature }}</p>
                        </div>
                    </div>

                    <div class="flex justify-end gap-2">
                        <button class="px-4 py-2 border rounded" @click="editModalOpen = false" type="button">Cancel</button>
                        <button class="px-4 py-2 bg-blue-600 text-white rounded" @click="submit" type="button">Save</button>
                    </div>
                </div>
            </div>
        </div>
    </PathfinderLayout>
</template>
