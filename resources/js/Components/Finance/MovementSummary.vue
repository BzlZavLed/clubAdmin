<script setup>
import { computed } from 'vue'
import { useLocale } from '@/Composables/useLocale'

const props = defineProps({
    movement: { type: Object, required: true },
    showReference: { type: Boolean, default: true },
    showNotes: { type: Boolean, default: true },
    showOriginalConcept: { type: Boolean, default: true },
    notesClass: { type: String, default: 'mt-1 text-xs text-gray-500' },
    titleClass: { type: String, default: 'font-semibold text-gray-900' },
})

const { tr } = useLocale()

const title = computed(() => props.movement?.display_concept
    || props.movement?.concept
    || props.movement?.reference
    || props.movement?.kind
    || tr('Movimiento', 'Movement'))
const originalConcept = computed(() => props.movement?.original_concept || props.movement?.concept || null)
</script>

<template>
    <div class="min-w-0">
        <p class="break-words" :class="titleClass">{{ title }}</p>
        <p v-if="showReference && movement.reference" class="mt-1 break-words text-xs text-gray-500">
            {{ movement.reference }}
        </p>
        <p v-if="showOriginalConcept && movement.concept_override && originalConcept" class="mt-1 break-words text-xs text-gray-500">
            {{ tr('Original', 'Original') }}: {{ originalConcept }}
        </p>
        <p v-if="showNotes && movement.notes" class="break-words" :class="notesClass">
            {{ tr('Notas', 'Notes') }}: {{ movement.notes }}
        </p>
    </div>
</template>
