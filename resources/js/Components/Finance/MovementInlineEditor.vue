<script setup>
import { computed, ref, watch } from 'vue'
import { PencilSquareIcon } from '@heroicons/vue/24/outline'
import { updateFinanceEngineMovementDisplayConcept } from '@/Services/api'
import { useGeneral } from '@/Composables/useGeneral'
import { useLocale } from '@/Composables/useLocale'

const props = defineProps({
    movement: { type: Object, required: true },
    clubId: { type: [Number, String], default: null },
    disabled: { type: Boolean, default: false },
    compact: { type: Boolean, default: false },
    buttonClass: { type: String, default: '' },
    panelClass: { type: String, default: '' },
    inputFocusClass: { type: String, default: 'focus:border-red-500 focus:ring-red-500' },
})

const emit = defineEmits(['updated'])

const { showToast } = useGeneral()
const { tr } = useLocale()

const editing = ref(false)
const busy = ref(false)
const error = ref('')
const form = ref({
    concept: '',
    notes: '',
})

const movementKey = computed(() => props.movement?.movement_id || `${props.movement?.domain || 'movement'}:${props.movement?.id || ''}`)
const movementTarget = computed(() => {
    const [type, id] = String(props.movement?.movement_id || '').split(':')

    return type && id ? { type, id } : null
})
const movementDisplayConcept = computed(() => (
    props.movement?.display_concept
    || props.movement?.concept
    || props.movement?.reference
    || props.movement?.kind
    || tr('Movimiento', 'Movement')
))
const defaultButtonClass = computed(() => props.compact
    ? 'inline-flex items-center gap-1 rounded-md border border-gray-200 bg-white px-2 py-1 text-xs font-semibold text-gray-600 hover:bg-gray-50 disabled:opacity-60'
    : 'inline-flex min-h-8 items-center gap-1 rounded-lg border border-gray-200 bg-white px-2 py-1 text-xs font-semibold text-gray-600 hover:bg-gray-50 disabled:opacity-60')
const defaultPanelClass = computed(() => props.compact
    ? 'mt-2 space-y-2 rounded-lg border border-gray-200 bg-gray-50 p-3'
    : 'mt-3 space-y-2 rounded-xl border border-gray-200 bg-gray-50 p-3')

const resetForm = () => {
    form.value = {
        concept: movementDisplayConcept.value,
        notes: props.movement?.notes || '',
    }
    error.value = ''
}

watch(movementKey, resetForm, { immediate: true })

const startEditing = () => {
    if (props.disabled) return
    resetForm()
    editing.value = true
}

const cancelEditing = () => {
    resetForm()
    editing.value = false
}

const normalizeErrors = (requestError) => {
    const errors = requestError?.response?.data?.errors || {}

    return Object.fromEntries(Object.entries(errors).map(([key, value]) => [key, Array.isArray(value) ? value[0] : value]))
}

const save = async () => {
    if (!movementTarget.value || !props.clubId || props.disabled) return

    busy.value = true
    error.value = ''

    try {
        const response = await updateFinanceEngineMovementDisplayConcept(movementTarget.value.type, movementTarget.value.id, {
            club_id: props.clubId,
            display_concept: form.value.concept || '',
            notes: form.value.notes || '',
        })
        const data = response?.data || {}

        editing.value = false
        form.value = {
            concept: data.display_concept || data.original_concept || props.movement?.concept || '',
            notes: data.notes || '',
        }
        emit('updated', {
            movementKey: data.movement_key || props.movement?.movement_id,
            data,
        })
        showToast(response?.message || tr('Movimiento actualizado.', 'Movement updated.'), 'success')
    } catch (requestError) {
        const errors = normalizeErrors(requestError)
        error.value = requestError?.response?.data?.message
            || errors.display_concept
            || errors.notes
            || tr('No se pudo actualizar el movimiento.', 'Could not update movement.')
    } finally {
        busy.value = false
    }
}
</script>

<template>
    <button
        v-if="!editing"
        type="button"
        :class="[defaultButtonClass, buttonClass]"
        :disabled="disabled || !movementTarget"
        @click="startEditing"
    >
        <PencilSquareIcon class="h-3.5 w-3.5" />
        {{ tr('Editar', 'Edit') }}
    </button>

    <div v-else :class="[defaultPanelClass, panelClass]">
        <input
            v-model="form.concept"
            type="text"
            maxlength="500"
            class="w-full rounded-lg border-gray-300 text-sm shadow-sm"
            :class="inputFocusClass"
            :aria-label="tr('Concepto visible', 'Display concept')"
        >
        <textarea
            v-model="form.notes"
            rows="2"
            maxlength="2000"
            class="w-full rounded-lg border-gray-300 text-sm shadow-sm"
            :class="inputFocusClass"
            :aria-label="tr('Notas', 'Notes')"
            :placeholder="tr('Notas del movimiento', 'Movement notes')"
        ></textarea>
        <div class="flex flex-wrap items-center gap-2">
            <button
                type="button"
                class="rounded-lg bg-red-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-red-700 disabled:opacity-60"
                :disabled="busy"
                @click="save"
            >
                {{ busy ? tr('Guardando...', 'Saving...') : tr('Guardar', 'Save') }}
            </button>
            <button
                type="button"
                class="rounded-lg border border-gray-200 bg-white px-3 py-1.5 text-xs font-semibold text-gray-700 hover:bg-gray-50 disabled:opacity-60"
                :disabled="busy"
                @click="cancelEditing"
            >
                {{ tr('Cancelar', 'Cancel') }}
            </button>
        </div>
        <p v-if="error" class="text-xs text-rose-600">
            {{ error }}
        </p>
    </div>
</template>
