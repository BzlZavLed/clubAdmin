<script setup>
import { ref, watch, nextTick, onMounted } from 'vue'
import axios from 'axios'

const props = defineProps({
    eventId: { type: Number, required: true },
    messages: { type: Array, default: () => [] }
})

const emit = defineEmits(['update'])

const localMessages = ref([...props.messages])
const input = ref('')
const sending = ref(false)
const error = ref(null)
const messagesRef = ref(null)
const inputRef = ref(null)

watch(
    () => props.messages,
    (value) => {
        localMessages.value = [...value]
    }
)

watch(
    () => localMessages.value.length,
    async () => {
        await nextTick()
        if (messagesRef.value) {
            messagesRef.value.scrollTop = messagesRef.value.scrollHeight
        }
    }
)

onMounted(() => {
    if (inputRef.value) {
        inputRef.value.focus()
    }
    if (messagesRef.value) {
        messagesRef.value.scrollTop = messagesRef.value.scrollHeight
    }
})

const send = async () => {
    if (!input.value.trim()) return
    error.value = null
    sending.value = true

    const userMessage = {
        role: 'user',
        content: input.value,
        at: new Date().toISOString(),
    }
    localMessages.value = [...localMessages.value, userMessage]

    try {
        const { data } = await axios.post(route('planner.message', { event: props.eventId }), {
            message: input.value,
        })
        emit('update', data)
        localMessages.value = data.eventPlan?.conversation_json || localMessages.value
        input.value = ''
    } catch (err) {
        error.value = err?.response?.data?.message || 'Unable to reach the planner right now.'
    } finally {
        sending.value = false
    }
}
</script>

<template>
    <div class="bg-white rounded-lg border p-4 space-y-4">
        <details class="bg-gray-50 border rounded-md p-3 text-xs text-gray-700">
            <summary class="font-semibold text-gray-800 cursor-pointer">Available tools</summary>
            <ul class="list-disc pl-4 space-y-1 mt-2">
                <li><span class="font-medium">update_event_spine</span> — Update title, dates, location, status.</li>
                <li><span class="font-medium">update_plan_section</span> — Add or revise a section of the plan outline.</li>
                <li><span class="font-medium">create_tasks</span> — Create tasks with due dates and assignees.</li>
                <li><span class="font-medium">create_budget_items</span> — Add budget line items and costs.</li>
                <li><span class="font-medium">set_missing_items</span> — Track missing plan requirements.</li>
                <li><span class="font-medium">add_participants</span> — Add participants and roles.</li>
                <li><span class="font-medium">find_recommended_places</span> — Find recommended places near the church address.</li>
            </ul>
        </details>
        <div ref="messagesRef" class="space-y-3 max-h-80 overflow-y-auto">
            <div v-for="(msg, idx) in localMessages" :key="idx" class="flex" :class="msg.role === 'user' ? 'justify-end' : 'justify-start'">
                <div :class="msg.role === 'user' ? 'bg-blue-600 text-white' : 'bg-gray-100 text-gray-800'"
                    class="px-3 py-2 rounded-lg max-w-[80%] text-sm whitespace-pre-wrap">
                    <div class="text-xs opacity-70 mb-1">{{ msg.role }}</div>
                    {{ msg.content }}
                </div>
            </div>
            <div v-if="!localMessages.length" class="text-sm text-gray-500">Start the conversation with the planner.</div>
        </div>

        <div class="border-t pt-3 space-y-2">
            <textarea ref="inputRef" v-model="input" rows="3" class="w-full border rounded px-3 py-2 text-sm"
                placeholder="Ask the planner to build tasks, budgets, or fill in missing items..."></textarea>
            <div class="flex items-center justify-between">
                <div class="text-xs text-red-600" v-if="error">{{ error }}</div>
                <button @click="send" :disabled="sending" class="px-4 py-2 bg-green-600 text-white rounded text-sm">
                    {{ sending ? 'Sending...' : 'Send' }}
                </button>
            </div>
        </div>
    </div>
</template>
