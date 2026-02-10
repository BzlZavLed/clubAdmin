<script setup>
import { ref, computed, onMounted } from 'vue'
import axios from 'axios'
import PathfinderLayout from '@/Layouts/PathfinderLayout.vue'
import PlanSectionsAccordion from '@/Components/EventPlanner/PlanSectionsAccordion.vue'
import RecommendationsCards from '@/Components/EventPlanner/RecommendationsCards.vue'
import TasksTable from '@/Components/EventPlanner/TasksTable.vue'
import BudgetTable from '@/Components/EventPlanner/BudgetTable.vue'
import ParticipantsTable from '@/Components/EventPlanner/ParticipantsTable.vue'
import DocumentsUploader from '@/Components/EventPlanner/DocumentsUploader.vue'
import PlannerChat from '@/Components/EventPlanner/PlannerChat.vue'

const props = defineProps({
    event: Object,
    eventPlan: Object,
    tasks: Array,
    budgetItems: Array,
    participants: Array,
    documents: Array,
    placeOptions: Array,
})

const activeTab = ref('tasks')

const eventState = ref(props.event)
const planState = ref(props.eventPlan)
const tasksState = ref(props.tasks || [])
const budgetState = ref(props.budgetItems || [])
const participantsState = ref(props.participants || [])
const documentsState = ref(props.documents || [])
const placeOptionsState = ref(props.placeOptions || [])

const recommendations = computed(() => {
    const sections = planState.value?.plan_json?.sections || []
    const section = sections.find((item) => item.name === 'Recommendations')
    return section?.items || []
})

const selectedPlaceOption = computed(() => {
    const options = placeOptionsState.value || []
    return options.find((option) => option.status === 'confirmed')
        || options.find((option) => option.status === 'tentative')
        || null
})

const showRecommendations = ref(true)

const selectedPlaceDetails = computed(() => {
    if (!selectedPlaceOption.value) return null
    const option = selectedPlaceOption.value
    const item = recommendations.value.find((entry) => entry.place_id === option.place_id)
    return {
        ...option,
        details: item || null,
    }
})

const outlineSections = computed(() => {
    const sections = (planState.value?.plan_json?.sections || [])
        .filter((section) => (section.name || '') !== 'Recommendations')
    return sections
})

const checklistTasks = computed(() => (tasksState.value || []).filter((task) => {
    const meta = task.checklist_json || {}
    return meta?.source === 'event_checklist'
}))

const syncMissingItems = async (tasks = checklistTasks.value) => {
    const remaining = tasks
        .filter((task) => task.status !== 'done')
        .map((task) => task.title)
    const payload = {
        plan_json: planState.value?.plan_json || { sections: [] },
        missing_items_json: remaining,
    }

    const { data } = await axios.patch(route('event-plans.update', { event: eventState.value.id }), payload)
    planState.value = data.eventPlan
}

const toggleChecklistTask = async (task) => {
    const nextStatus = task.status === 'done' ? 'todo' : 'done'
    const { data } = await axios.put(route('event-tasks.update', { eventTask: task.id }), {
        title: task.title,
        description: task.description,
        assigned_to_user_id: task.assigned_to_user_id,
        due_at: task.due_at,
        status: nextStatus,
        checklist_json: task.checklist_json,
    })
    tasksState.value = tasksState.value.map((item) => item.id === task.id ? data.task : item)
    await syncMissingItems()
}

const newChecklistItem = ref('')

const addChecklistItem = async () => {
    const label = newChecklistItem.value.trim()
    if (!label) return
    const { data } = await axios.post(route('event-tasks.store', { event: eventState.value.id }), {
        title: label,
        status: 'todo',
        checklist_json: { source: 'event_checklist' },
    })
    tasksState.value = [...tasksState.value, data.task]
    newChecklistItem.value = ''
    await syncMissingItems()
}

const removeChecklistTask = async (task) => {
    await axios.delete(route('event-tasks.destroy', { eventTask: task.id }))
    tasksState.value = tasksState.value.filter((item) => item.id !== task.id)
    await syncMissingItems()
}

const missingCount = computed(() => {
    if (checklistTasks.value.length) {
        return checklistTasks.value.filter((task) => task.status !== 'done').length
    }
    return planState.value?.missing_items_json?.length || 0
})

const seededChecklist = ref(false)

onMounted(async () => {
    if (seededChecklist.value) return
    const missing = planState.value?.missing_items_json || []
    if (!missing.length || checklistTasks.value.length) return

    seededChecklist.value = true
    for (const item of missing) {
        const label = typeof item === 'string' ? item : (item.label ?? '')
        if (!label) continue
        const { data } = await axios.post(route('event-tasks.store', { event: eventState.value.id }), {
            title: label,
            status: 'todo',
            checklist_json: { source: 'event_checklist' },
        })
        tasksState.value = [...tasksState.value, data.task]
    }
    await syncMissingItems()
})
const completeness = computed(() => {
    const sections = (planState.value?.plan_json?.sections || [])
        .filter((section) => (section.name || '') !== 'Recommendations')
    const placeOptions = placeOptionsState.value || []
    const hasConfirmedPlace = placeOptions.some((option) => option.status === 'confirmed')
    const hasTentativePlace = placeOptions.some((option) => option.status === 'tentative')
    const baseSignals = sections.length
        + (tasksState.value?.length || 0)
        + (budgetState.value?.length || 0)
        + (participantsState.value?.length || 0)
        + missingCount.value
    const placeWeight = hasConfirmedPlace ? 2 : (hasTentativePlace ? 1 : 0)
    const totalSignals = baseSignals + placeWeight

    if (baseSignals === 0) {
        const bonus = hasConfirmedPlace ? 25 : (hasTentativePlace ? 15 : 0)
        return bonus
    }
    const score = (totalSignals - missingCount.value) / totalSignals
    const bonus = hasConfirmedPlace ? 25 : (hasTentativePlace ? 15 : 0)
    return Math.max(0, Math.min(100, Math.round(score * 100 + bonus)))
})

const handlePlannerUpdate = (payload) => {
    eventState.value = payload.event
    planState.value = payload.eventPlan
    tasksState.value = payload.tasks || []
    budgetState.value = payload.budget_items || []
    participantsState.value = payload.participants || []
    documentsState.value = payload.documents || []
}

const updateDocuments = (docs) => {
    documentsState.value = docs
}

const handlePlaceOptionsUpdate = (updated) => {
    placeOptionsState.value = updated
    showRecommendations.value = !selectedPlaceOption.value
}
</script>

<template>
    <PathfinderLayout>
        <template #title>{{ eventState.title }}</template>

        <div class="space-y-6">
            <div class="bg-white rounded-lg border p-4">
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div>
                        <div class="text-xs text-gray-500">Event Type</div>
                        <div class="font-semibold text-gray-800">{{ eventState.event_type }}</div>
                    </div>
                    <div>
                        <div class="text-xs text-gray-500">Date</div>
                        <div class="font-semibold text-gray-800">{{ new Date(eventState.start_at).toLocaleString() }}</div>
                    </div>
                    <div>
                        <div class="text-xs text-gray-500">Status</div>
                        <div class="font-semibold text-gray-800 capitalize">{{ eventState.status }}</div>
                    </div>
                </div>
                <div class="mt-4">
                    <div class="flex items-center justify-between text-sm">
                        <span>Completeness</span>
                        <span>{{ completeness }}%</span>
                    </div>
                    <div class="h-2 bg-gray-200 rounded-full mt-1">
                        <div class="h-2 bg-green-500 rounded-full" :style="{ width: completeness + '%' }"></div>
                    </div>
                    <div class="text-xs text-gray-500 mt-1 relative inline-flex items-center gap-1 group">
                        <span>Missing items: {{ missingCount }}</span>
                        <div v-if="missingCount" class="hidden group-hover:block absolute z-10 left-0 top-full mt-2 w-64 rounded-md border bg-white p-3 shadow-lg text-xs text-gray-700">
                            <div class="font-semibold text-gray-800 mb-2">Missing items</div>
                            <div class="space-y-1">
                                <div v-for="task in checklistTasks.filter((entry) => entry.status !== 'done')" :key="task.id">
                                    • {{ task.title }}
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <div class="lg:col-span-2 space-y-6">
                    <div class="bg-white rounded-lg border p-4">
                        <div class="flex items-center justify-between mb-3">
                            <h2 class="text-lg font-semibold text-gray-800">Recommended Places</h2>
                            <button
                                v-if="selectedPlaceOption && !showRecommendations"
                                type="button"
                                class="text-sm text-blue-600 hover:text-blue-700"
                                @click="showRecommendations = true"
                            >
                                Find another place
                            </button>
                        </div>

                        <div v-if="selectedPlaceOption && !showRecommendations" class="rounded-md border bg-gray-50 p-4">
                            <div class="text-xs uppercase tracking-wide text-gray-500 mb-2">Preselected Place ({{ selectedPlaceOption.status }})</div>
                            <div class="text-lg font-semibold text-gray-800">
                                {{ selectedPlaceDetails?.details?.name || selectedPlaceOption.name }}
                            </div>
                            <div class="text-sm text-gray-600">
                                {{ selectedPlaceDetails?.details?.address || selectedPlaceOption.address || 'Address unavailable' }}
                            </div>
                            <div class="mt-2 text-sm text-gray-600 flex flex-wrap gap-3">
                                <span v-if="selectedPlaceDetails?.details?.rating || selectedPlaceOption.rating">
                                    Rating: {{ selectedPlaceDetails?.details?.rating ?? selectedPlaceOption.rating }} ★
                                </span>
                                <span v-if="selectedPlaceDetails?.details?.user_ratings_total || selectedPlaceOption.user_ratings_total">
                                    Reviews: {{ selectedPlaceDetails?.details?.user_ratings_total ?? selectedPlaceOption.user_ratings_total }}
                                </span>
                                <span v-if="selectedPlaceDetails?.details?.phone || selectedPlaceOption.phone">
                                    Phone: {{ selectedPlaceDetails?.details?.phone ?? selectedPlaceOption.phone }}
                                </span>
                            </div>
                            <div class="mt-3 flex flex-wrap gap-3 text-sm">
                                <a
                                    v-if="selectedPlaceDetails?.details?.maps_url"
                                    :href="selectedPlaceDetails.details.maps_url"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="text-blue-600 hover:text-blue-700"
                                >
                                    View on Google Maps
                                </a>
                                <button type="button" class="text-gray-600 hover:text-gray-800" @click="showRecommendations = true">
                                    Change selection
                                </button>
                            </div>
                        </div>

                        <div v-else>
                            <RecommendationsCards
                                :items="recommendations"
                                :place-options="placeOptionsState"
                                :event-id="eventState.id"
                                @updated="handlePlaceOptionsUpdate"
                            />
                        </div>
                    </div>
                    <div class="bg-white rounded-lg border p-4">
                        <div class="flex gap-3 mb-4">
                            <button @click="activeTab = 'tasks'" :class="activeTab === 'tasks' ? 'bg-blue-600 text-white' : 'bg-gray-100 text-gray-600'" class="px-3 py-1 rounded text-sm">Tasks</button>
                            <button @click="activeTab = 'budget'" :class="activeTab === 'budget' ? 'bg-blue-600 text-white' : 'bg-gray-100 text-gray-600'" class="px-3 py-1 rounded text-sm">Budget</button>
                            <button @click="activeTab = 'participants'" :class="activeTab === 'participants' ? 'bg-blue-600 text-white' : 'bg-gray-100 text-gray-600'" class="px-3 py-1 rounded text-sm">Participants</button>
                            <button @click="activeTab = 'documents'" :class="activeTab === 'documents' ? 'bg-blue-600 text-white' : 'bg-gray-100 text-gray-600'" class="px-3 py-1 rounded text-sm">Documents</button>
                        </div>

                        <div v-if="activeTab === 'tasks'">
                            <div class="mb-4 rounded-lg border bg-gray-50 p-4">
                                <div class="flex items-center justify-between mb-2">
                                    <h3 class="text-sm font-semibold text-gray-800">Event Checklist</h3>
                                    <span class="text-xs text-gray-500">{{ missingCount }} remaining</span>
                                </div>
                                <div class="space-y-2">
                                    <div v-if="!checklistTasks.length" class="text-xs text-gray-500">
                                        No checklist items yet.
                                    </div>
                                    <label v-for="task in checklistTasks" :key="task.id" class="flex items-center justify-between gap-2 text-sm text-gray-700">
                                        <span class="flex items-center gap-2">
                                            <input type="checkbox" class="rounded border-gray-300 text-blue-600" :checked="task.status === 'done'" @change="toggleChecklistTask(task)" />
                                            <span :class="task.status === 'done' ? 'line-through text-gray-400' : ''">{{ task.title }}</span>
                                        </span>
                                        <button type="button" class="text-xs text-gray-400 hover:text-red-500" @click="removeChecklistTask(task)">
                                            Remove
                                        </button>
                                    </label>
                                </div>
                                <div class="mt-3 flex items-center gap-2">
                                    <input v-model="newChecklistItem" type="text" class="flex-1 rounded border border-gray-300 px-2 py-1 text-sm" placeholder="Add checklist item" />
                                    <button type="button" class="px-3 py-1 rounded text-sm bg-blue-600 text-white" @click="addChecklistItem">Add</button>
                                </div>
                            </div>
                            <TasksTable :tasks="tasksState" />
                        </div>
                        <div v-else-if="activeTab === 'budget'">
                            <BudgetTable :items="budgetState" />
                        </div>
                        <div v-else-if="activeTab === 'participants'">
                            <ParticipantsTable :participants="participantsState" />
                        </div>
                        <div v-else>
                            <DocumentsUploader :event-id="eventState.id" :documents="documentsState" @updated="updateDocuments" />
                        </div>
                    </div>

                    <div class="bg-white rounded-lg border p-4">
                        <div class="flex items-center justify-between mb-3">
                            <h2 class="text-lg font-semibold text-gray-800">Plan Outline</h2>
                            <button type="button" class="text-sm text-blue-600 hover:text-blue-700">
                                Export PDF
                            </button>
                        </div>
                        <p class="text-sm text-gray-500 mb-3">
                            This section will summarize task outcomes and export a printable report.
                        </p>
                        <PlanSectionsAccordion :sections="outlineSections" />
                    </div>
                </div>

                <div>
                    <PlannerChat :event-id="eventState.id" :messages="planState?.conversation_json || []" @update="handlePlannerUpdate" />
                </div>
            </div>
        </div>
    </PathfinderLayout>
</template>
