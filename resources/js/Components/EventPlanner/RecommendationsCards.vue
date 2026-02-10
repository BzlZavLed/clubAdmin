<script setup>
import { computed, ref } from 'vue'
import axios from 'axios'

const props = defineProps({
    items: {
        type: Array,
        default: () => []
    },
    eventId: {
        type: Number,
        required: true
    },
    placeOptions: {
        type: Array,
        default: () => []
    }
})

const emit = defineEmits(['updated'])
const saving = ref(false)

const cards = computed(() => (props.items || []).map(item => {
    const meta = item.meta || {}
    const placeId = meta.place_id || item.place_id
    const address = meta.address || item.address || meta.vicinity
    const name = meta.name || item.label || 'Place'

    const mapUrl = placeId
        ? `https://www.google.com/maps/place/?q=place_id:${placeId}`
        : `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(address || name)}`

    const existing = (props.placeOptions || []).find(option => option.place_id === placeId)

    return {
        name,
        address,
        rating: meta.rating ?? item.rating ?? null,
        userRatingsTotal: meta.user_ratings_total ?? item.user_ratings_total ?? null,
        phone: meta.international_phone_number ?? item.international_phone_number ?? null,
        distance: meta.distance_text ?? item.distance_text ?? null,
        eta: meta.duration_text ?? item.duration_text ?? null,
        mapUrl,
        placeId,
        existing,
    }
}))

const markTentative = async (card) => {
    if (card.existing) {
        await updateStatus(card, 'tentative')
        return
    }
    saving.value = true
    try {
        const { data } = await axios.post(route('event-place-options.store', { event: props.eventId }), {
            place_id: card.placeId,
            name: card.name,
            address: card.address,
            phone: card.phone,
            rating: card.rating,
            user_ratings_total: card.userRatingsTotal,
            status: 'tentative',
            meta_json: {},
        })
        emit('updated', [...props.placeOptions, data.place_option])
    } finally {
        saving.value = false
    }
}

const updateStatus = async (card, status) => {
    if (!card.existing) return
    saving.value = true
    try {
        const { data } = await axios.put(route('event-place-options.update', { eventPlaceOption: card.existing.id }), {
            status,
        })
        const next = props.placeOptions.map(option => option.id === data.place_option.id ? data.place_option : option)
        emit('updated', next)
    } finally {
        saving.value = false
    }
}
</script>

<template>
    <div v-if="cards.length" class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div v-for="(card, idx) in cards" :key="idx" class="bg-white border rounded-lg p-4 space-y-2">
            <div class="font-semibold text-gray-800">{{ card.name }}</div>
            <div class="text-sm text-gray-600">{{ card.address || 'Address unavailable' }}</div>
            <div class="text-xs text-gray-500">
                <span v-if="card.rating">Rating: {{ card.rating }}</span>
                <span v-if="card.userRatingsTotal"> • {{ card.userRatingsTotal }} reviews</span>
            </div>
            <div v-if="card.distance || card.eta" class="text-xs text-gray-500">
                <span v-if="card.distance">Distance: {{ card.distance }}</span>
                <span v-if="card.eta"> • ETA: {{ card.eta }}</span>
            </div>
            <div v-if="card.phone" class="text-xs text-gray-500">{{ card.phone }}</div>
            <a :href="card.mapUrl" target="_blank" rel="noopener" class="text-sm text-blue-600 hover:underline">
                View on Google Maps
            </a>
            <div class="pt-2 flex items-center gap-2">
                <button v-if="!card.existing || card.existing.status === 'rejected'" @click="markTentative(card)" :disabled="saving"
                    class="px-2 py-1 rounded text-xs bg-yellow-500 text-white">
                    Mark Tentative
                </button>
                <button v-else @click="updateStatus(card, 'confirmed')" :disabled="saving"
                    class="px-2 py-1 rounded text-xs bg-green-600 text-white">
                    Confirm
                </button>
                <button v-else @click="updateStatus(card, 'rejected')" :disabled="saving"
                    class="px-2 py-1 rounded text-xs bg-gray-300 text-gray-700">
                    Reject
                </button>
                <span v-if="card.existing" class="text-xs text-gray-500">Status: {{ card.existing.status }}</span>
            </div>
        </div>
    </div>
    <div v-else class="text-sm text-gray-500">No recommendations yet.</div>
</template>
