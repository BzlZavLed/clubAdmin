<script setup>
import { ref } from 'vue'
import { useLocale } from '@/Composables/useLocale'

const props = defineProps({
    sections: {
        type: Array,
        default: () => []
    }
})

const openIndex = ref(null)
const { tr } = useLocale()

const toggle = (idx) => {
    openIndex.value = openIndex.value === idx ? null : idx
}
</script>

<template>
    <div class="space-y-3">
        <div v-for="(section, idx) in sections" :key="section.name || idx" class="border rounded-lg bg-white">
            <button class="w-full flex items-center justify-between px-4 py-3 text-left"
                @click="toggle(idx)">
                <div>
                    <div class="font-semibold text-gray-800">{{ section.name || tr('Sección', 'Section') }}</div>
                    <div v-if="section.summary" class="text-sm text-gray-500">{{ section.summary }}</div>
                </div>
                <span class="text-sm text-gray-400">{{ openIndex === idx ? '−' : '+' }}</span>
            </button>
            <div v-if="openIndex === idx" class="px-4 pb-4 text-sm text-gray-700">
                <ul v-if="Array.isArray(section.items) && section.items.length" class="list-disc pl-5 space-y-3">
                    <li v-for="(item, itemIdx) in section.items" :key="itemIdx">
                        <span class="font-medium">{{ item.label }}</span>
                        <span v-if="item.detail"> — {{ item.detail }}</span>
                        <div v-if="item.image_url" class="mt-2">
                            <img
                                :src="item.image_url"
                                :alt="item.label || 'Plan image'"
                                class="max-h-64 rounded border border-gray-200 bg-white"
                            />
                        </div>
                        <div v-if="Array.isArray(item.image_urls) && item.image_urls.length" class="mt-2 flex flex-wrap gap-2">
                            <img
                                v-for="(imageUrl, imageIdx) in item.image_urls"
                                :key="`${itemIdx}-${imageIdx}`"
                                :src="imageUrl"
                                :alt="item.label || 'Plan image'"
                                class="max-h-40 rounded border border-gray-200 bg-white"
                            />
                        </div>
                    </li>
                </ul>
                <div v-else class="text-gray-500">{{ tr('Sin detalles aún.', 'No details yet.') }}</div>
            </div>
        </div>
    </div>
</template>
