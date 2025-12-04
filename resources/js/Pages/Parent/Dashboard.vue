<script setup>
import { ref, onMounted } from 'vue'
import PathfinderLayout from '@/Layouts/PathfinderLayout.vue'
import WorkplanCalendar from '@/Components/WorkplanCalendar.vue'
import { fetchParentWorkplan } from '@/Services/api'
import { useGeneral } from '@/Composables/useGeneral'

const props = defineProps({
    auth_user: Object
})

const { showToast } = useGeneral()

const clubs = ref([])
const selectedClubId = ref(null)
const workplan = ref(null)
const events = ref([])
const memberships = ref([])

const load = async (clubId = null) => {
    try {
        const { clubs: c, selected_club_id, workplan: wp, memberships: m } = await fetchParentWorkplan(clubId)
        clubs.value = c || []
        selectedClubId.value = selected_club_id || null
        workplan.value = wp
        events.value = wp?.events || []
        memberships.value = m || []
    } catch (e) {
        console.error(e)
        showToast('Failed to load workplan', 'error')
    }
}

const changeClub = async () => {
    if (!selectedClubId.value) return
    await load(selectedClubId.value)
}

onMounted(() => {
    load()
})
</script>

<template>
    <PathfinderLayout>
        <template #title>Parent Dashboard</template>

        <div class="space-y-4">
            <div class="bg-white border rounded shadow-sm p-4">
                <h2 class="text-xl font-semibold text-gray-800">Welcome, {{ props.auth_user?.name }}</h2>
                <p class="text-gray-600 text-sm mt-1">View workplans for your children’s clubs.</p>
            </div>

            <div class="bg-white border rounded shadow-sm p-4 space-y-3">
                <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
                    <div class="space-y-1">
                        <h3 class="text-lg font-semibold text-gray-800">Club Workplan</h3>
                        <p class="text-sm text-gray-600">Select a club to view its schedule.</p>
                    </div>
                    <div class="flex items-center gap-2">
                        <label class="text-sm text-gray-700">Club</label>
                        <select v-model="selectedClubId" class="border rounded px-3 py-1 text-sm" @change="changeClub">
                            <option value="">Select a club</option>
                            <option v-for="club in clubs" :key="club.id" :value="club.id">{{ club.club_name }}</option>
                        </select>
                    </div>
                </div>

                <div v-if="workplan">
                    <WorkplanCalendar
                        :events="events"
                        :is-read-only="true"
                        :can-add="false"
                        :initial-date="workplan?.start_date || new Date().toISOString().slice(0,10)"
                    />
                </div>
                <div v-else class="text-sm text-gray-600">No workplan found for your clubs.</div>
            </div>
        </div>
    </PathfinderLayout>
</template>
