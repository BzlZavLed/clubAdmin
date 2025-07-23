<script setup>
import PathfinderLayout from "@/Layouts/PathfinderLayout.vue";
import { computed, reactive, ref, onMounted, watch } from 'vue';
import { fetchAssignedMembersByStaff } from '@/Services/api';
import { useAuth } from '@/Composables/useAuth';
import { usePage } from '@inertiajs/vue3';

const { user } = useAuth();
const userId = computed(() => user.value?.id || null);
const assignedMembers = ref([]);
const assignedClass = ref(null);
const attendanceData = ref([]);
const page = usePage();
const staff = computed(() => page.props.staff || null);

const meritsLabels = ['Asistencia', 'Puntualidad', 'Uniforme', 'Conductor', 'Cuota', 'Extras'];
const demeritLabels = ['Tarea', 'Conducta', 'Otros'];

const form = reactive({
    unit_name: '',
    counselor: user.value?.name || '',
    captain: '',
    month: new Date().toLocaleString('default', { month: 'long' }),
    year: new Date().getFullYear(),
    church: user.value?.church_name || '',
    district: user.value?.conference_name || '',
    date: new Date().toISOString().split('T')[0],
});

const subtotal = (scores) => scores.reduce((sum, v) => sum + (parseFloat(v) || 0), 0);
const total = (data) => subtotal(data.scores) - subtotal(data.lastThree);

watch(assignedMembers, (members) => {
    attendanceData.value = members.map(m => ({
        member_id: m.id,
        scores: Array(6).fill(null),
        lastThree: Array(3).fill(null)
    }));
});

watch(assignedClass, (val) => {
    if (val && val.name) form.unit_name = val.name;
});

const loadAssignedMembers = async (staffId) => {
    const res = await fetchAssignedMembersByStaff(staffId);
    assignedMembers.value = res.members;
    assignedClass.value = res.class;
};

onMounted(() => {
    if (userId.value) loadAssignedMembers(staff.value.id);
});
</script>
<template>
    <PathfinderLayout>
        <template #title>Assistance Report</template>
        <div class="max-w-4xl mx-auto bg-white p-4 shadow rounded text-sm">
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-4">
                <div>
                    <label class="font-semibold">Nombre de la Clase:</label>
                    <input v-model="form.unit_name" type="text" class="w-full border rounded p-1" />
                </div>
                <div>
                    <label class="font-semibold">Consejero:</label>
                    <input v-model="form.counselor" type="text" class="w-full border rounded p-1" />
                </div>
                
                <div class="flex gap-2">
                    <div class="flex-1">
                        <label class="font-semibold">Mes:</label>
                        <input v-model="form.month" type="text" class="w-full border rounded p-1" />
                    </div>
                    <div class="flex-1">
                        <label class="font-semibold">Año:</label>
                        <input v-model="form.year" type="text" class="w-full border rounded p-1" />
                    </div>
                </div>
                <div>
                    <label class="font-semibold">Iglesia:</label>
                    <input v-model="form.church" type="text" class="w-full border rounded p-1" />
                </div>
                <div>
                    <label class="font-semibold">Distrito:</label>
                    <input v-model="form.district" type="text" class="w-full border rounded p-1" />
                </div>
                <div>
                    <label class="font-semibold">Fecha:</label>
                    <input v-model="form.date" type="date" class="w-full border rounded p-1" />
                </div>
            </div>

            <!-- Accordion for members -->
            <div class="space-y-2">
                <details v-for="(member, i) in assignedMembers" :key="member.id || i"
                    class="border rounded overflow-hidden">
                    <summary class="bg-gray-100 px-4 py-2 cursor-pointer text-sm font-semibold">
                        {{ i + 1 }}. {{ member.applicant_name }}
                    </summary>
                    <div class="p-4 space-y-2 text-xs">
                        <div class="grid grid-cols-2 gap-2">
                            <div v-for="(label, index) in meritsLabels" :key="index">
                                <label>{{ label }}</label>
                                <input type="number" v-model.number="attendanceData[i].scores[index]"
                                    class="w-full border rounded p-1" />
                            </div>
                            <div class="col-span-2 font-semibold text-right">
                                Subtotal: {{ subtotal(attendanceData[i].scores) }}
                            </div>
                        </div>
                        <hr />
                        <div class="grid grid-cols-2 gap-2">
                            <div v-for="(label, index) in demeritLabels" :key="index">
                                <label>{{ label }}</label>
                                <input type="number" v-model.number="attendanceData[i].lastThree[index]"
                                    class="w-full border rounded p-1" />
                            </div>
                            <div class="col-span-2 font-semibold text-right">
                                Gran Total: {{ total(attendanceData[i]) }}
                            </div>
                        </div>
                    </div>
                </details>
            </div>
        </div>
    </PathfinderLayout>
</template>

