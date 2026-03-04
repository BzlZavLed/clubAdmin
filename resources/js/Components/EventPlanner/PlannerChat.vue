<script setup>
import { ref, watch, nextTick, onMounted } from 'vue'
import axios from 'axios'

const props = defineProps({
    eventId: { type: Number, required: true },
    messages: { type: Array, default: () => [] },
    autoCreateBudgetItem: { type: Boolean, default: false },
    serpApiUsage: { type: Object, default: () => ({ month: null, limit: 250, used: 0, remaining: 250 }) },
})

const emit = defineEmits(['update'])

const localMessages = ref([...props.messages])
const input = ref('')
const sending = ref(false)
const error = ref(null)
const messagesRef = ref(null)
const inputRef = ref(null)

const placeMapLink = (place) => {
    if (place?.place_id) {
        return `https://www.google.com/maps/place/?q=place_id:${place.place_id}`
    }
    const query = place?.address || place?.vicinity || place?.name
    if (!query) return null
    return `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(query)}`
}

const RENTAL_SEGMENT_KEYS = [
    'Estimated ',
    'Per vehicle per day:',
    'Fleet cost per day',
    'Total rental for',
    'Estimated gas per vehicle',
    'Estimated gas ',
    'Source (live web quotes):',
    'Live quote samples:',
    'Fallback source (pricing):',
    'Provider sources:',
]

const isRentalEstimateMessage = (content) => {
    if (!content || typeof content !== 'string') return false
    return content.includes('Per vehicle per day:') && content.includes('Total rental for')
}

const parseLinkedItems = (rawValue) => {
    const value = (rawValue || '').replace(/\.$/, '').trim()
    if (!value) return []

    return value.split(/\s*;\s*/).map((item) => {
        const match = item.match(/^(.*)\((https?:\/\/[^)]+)\)\s*$/)
        if (match) {
            return {
                label: match[1].trim(),
                url: match[2].trim(),
            }
        }

        return {
            label: item.trim(),
            url: null,
        }
    }).filter((item) => item.label)
}

const parseRentalEstimateMessage = (content) => {
    if (!isRentalEstimateMessage(content)) return null

    const found = []
    RENTAL_SEGMENT_KEYS.forEach((key) => {
        const idx = content.indexOf(key)
        if (idx >= 0) {
            found.push({ key, idx })
        }
    })

    found.sort((a, b) => a.idx - b.idx)
    if (!found.length) return null

    const segments = found.map((entry, i) => {
        const start = entry.idx
        const end = i < found.length - 1 ? found[i + 1].idx : content.length
        return content.slice(start, end).trim()
    })

    const getByPrefix = (prefix) => segments.find((s) => s.startsWith(prefix)) || null
    const getValue = (segment) => {
        if (!segment) return null
        const pos = segment.indexOf(':')
        if (pos < 0) return segment.replace(/\.$/, '').trim()
        return segment.slice(pos + 1).replace(/\.$/, '').trim()
    }

    const intro = getByPrefix('Estimated ')
    const perVehicle = getByPrefix('Per vehicle per day:')
    const fleetPerDay = getByPrefix('Fleet cost per day')
    const totalRental = getByPrefix('Total rental for')
    const gas = getByPrefix('Estimated gas per vehicle') || getByPrefix('Estimated gas ')
    const liveSource = getByPrefix('Source (live web quotes):')
    const liveSamples = getByPrefix('Live quote samples:')
    const fallback = getByPrefix('Fallback source (pricing):')
    const providers = getByPrefix('Provider sources:')

    return {
        intro: intro ? intro.replace(/\.$/, '').trim() : null,
        costRows: [
            perVehicle ? { label: 'Per Vehicle / Day', value: getValue(perVehicle) } : null,
            fleetPerDay ? { label: 'Fleet / Day', value: getValue(fleetPerDay) } : null,
            totalRental ? { label: 'Total Rental', value: getValue(totalRental) } : null,
            gas ? { label: 'Gas', value: getValue(gas) } : null,
        ].filter(Boolean),
        sourceRows: [
            liveSource ? { label: 'Live Source', value: getValue(liveSource) } : null,
            fallback ? { label: 'Fallback Source', value: getValue(fallback) } : null,
        ].filter(Boolean),
        liveSampleItems: parseLinkedItems(getValue(liveSamples)),
        providerItems: parseLinkedItems(getValue(providers)),
    }
}

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
            create_budget_item: props.autoCreateBudgetItem,
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
        <div class="rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-900">
            <div class="font-semibold">SerpAPI Monthly Usage</div>
            <div class="mt-1">
                Used {{ Number(props.serpApiUsage?.used || 0) }} / {{ Number(props.serpApiUsage?.limit || 0) }}
                <span class="mx-1">•</span>
                Remaining {{ Number(props.serpApiUsage?.remaining || 0) }}
                <span v-if="props.serpApiUsage?.month" class="mx-1">•</span>
                <span v-if="props.serpApiUsage?.month">Month {{ props.serpApiUsage.month }}</span>
            </div>
        </div>

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
                <li><span class="font-medium">find_rental_agencies</span> — Find nearby car/van/bus rental agencies.</li>
                <li><span class="font-medium">estimate_rental_costs</span> — Estimate rental costs per day and total.</li>
            </ul>
        </details>
        <div ref="messagesRef" class="space-y-3 max-h-80 overflow-y-auto">
            <div v-for="(msg, idx) in localMessages" :key="idx" class="flex" :class="msg.role === 'user' ? 'justify-end' : 'justify-start'">
                <div :class="msg.role === 'user' ? 'bg-blue-600 text-white' : 'bg-gray-100 text-gray-800'"
                    class="px-3 py-2 rounded-lg max-w-[80%] text-sm whitespace-pre-wrap">
                    <div class="text-xs opacity-70 mb-1">{{ msg.role }}</div>
                    <template v-if="msg.role === 'assistant' && parseRentalEstimateMessage(msg.content)">
                        <div class="space-y-2 break-words">
                            <div class="font-medium text-sm">{{ parseRentalEstimateMessage(msg.content).intro }}</div>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                                <div
                                    v-for="(row, rowIdx) in parseRentalEstimateMessage(msg.content).costRows"
                                    :key="`cost-${rowIdx}`"
                                    class="rounded border bg-white px-2 py-1"
                                >
                                    <div class="text-[11px] uppercase tracking-wide text-gray-500">{{ row.label }}</div>
                                    <div class="text-xs text-gray-800">{{ row.value }}</div>
                                </div>
                            </div>

                            <div v-if="parseRentalEstimateMessage(msg.content).sourceRows.length" class="space-y-1">
                                <div
                                    v-for="(row, rowIdx) in parseRentalEstimateMessage(msg.content).sourceRows"
                                    :key="`source-${rowIdx}`"
                                    class="text-xs text-gray-700 break-words"
                                >
                                    <span class="font-semibold">{{ row.label }}:</span> {{ row.value }}
                                </div>
                            </div>

                            <div v-if="parseRentalEstimateMessage(msg.content).liveSampleItems.length" class="space-y-1">
                                <div class="text-[11px] uppercase tracking-wide text-gray-500">Live Quote Samples</div>
                                <ul class="space-y-1">
                                    <li
                                        v-for="(item, itemIdx) in parseRentalEstimateMessage(msg.content).liveSampleItems"
                                        :key="`sample-${itemIdx}`"
                                        class="text-xs text-gray-700 break-all"
                                    >
                                        <a v-if="item.url" :href="item.url" target="_blank" rel="noopener noreferrer" class="text-blue-600 underline">{{ item.label }}</a>
                                        <span v-else>{{ item.label }}</span>
                                    </li>
                                </ul>
                            </div>

                            <div v-if="parseRentalEstimateMessage(msg.content).providerItems.length" class="space-y-1">
                                <div class="text-[11px] uppercase tracking-wide text-gray-500">Rental Providers</div>
                                <ul class="space-y-1">
                                    <li
                                        v-for="(item, itemIdx) in parseRentalEstimateMessage(msg.content).providerItems"
                                        :key="`provider-${itemIdx}`"
                                        class="text-xs text-gray-700 break-all"
                                    >
                                        <a v-if="item.url" :href="item.url" target="_blank" rel="noopener noreferrer" class="text-blue-600 underline">{{ item.label }}</a>
                                        <span v-else>{{ item.label }}</span>
                                    </li>
                                </ul>
                            </div>
                        </div>
                    </template>
                    <template v-else>
                        {{ msg.content }}
                    </template>
                    <div v-if="msg.places?.length" class="mt-2 space-y-2">
                        <div v-for="place in msg.places" :key="place.place_id || place.name" class="border rounded-md bg-white p-2 text-xs text-gray-700">
                            <div class="font-semibold text-gray-900">{{ place.name }}</div>
                            <div v-if="place.address || place.vicinity" class="text-gray-600">{{ place.address || place.vicinity }}</div>
                            <div v-if="place.rating" class="text-gray-600">Rating: {{ place.rating }}<span v-if="place.user_ratings_total"> ({{ place.user_ratings_total }} reviews)</span></div>
                            <div v-if="place.international_phone_number || place.formatted_phone_number" class="text-gray-600">Phone: {{ place.international_phone_number || place.formatted_phone_number }}</div>
                            <div v-if="place.distance_text || place.duration_text" class="text-gray-600">
                                <span v-if="place.distance_text">Distance: {{ place.distance_text }}</span>
                                <span v-if="place.distance_text && place.duration_text"> • </span>
                                <span v-if="place.duration_text">ETA: {{ place.duration_text }}</span>
                            </div>
                            <a v-if="placeMapLink(place)" :href="placeMapLink(place)" target="_blank" rel="noopener noreferrer" class="inline-block mt-1 text-xs text-blue-600 underline">Open in Maps</a>
                        </div>
                    </div>
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
