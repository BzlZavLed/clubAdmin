<script setup>
import { onMounted, ref } from 'vue'
import { EyeIcon, EyeSlashIcon } from '@heroicons/vue/24/outline'
import { useLocale } from '@/Composables/useLocale'

defineOptions({ inheritAttrs: false })

defineProps({
    inputClass: {
        type: [String, Array, Object],
        default: '',
    },
    wrapperClass: {
        type: [String, Array, Object],
        default: 'relative mt-1',
    },
})

const model = defineModel({
    type: String,
    required: true,
})

const input = ref(null)
const isVisible = ref(false)
const { tr } = useLocale()

onMounted(() => {
    if (input.value?.hasAttribute('autofocus')) {
        input.value.focus()
    }
})

defineExpose({ focus: () => input.value?.focus() })
</script>

<template>
    <div :class="wrapperClass">
        <input
            v-bind="$attrs"
            ref="input"
            v-model="model"
            :type="isVisible ? 'text' : 'password'"
            :class="[
                'block w-full rounded-md border-gray-300 pr-11 shadow-sm focus:border-indigo-500 focus:ring-indigo-500',
                inputClass,
            ]"
        />
        <button
            type="button"
            class="absolute inset-y-0 right-0 flex w-11 items-center justify-center rounded-r-md text-gray-500 hover:text-gray-700 focus:outline-none focus:ring-2 focus:ring-inset focus:ring-red-500"
            :aria-label="isVisible ? tr('Ocultar contraseña', 'Hide password') : tr('Mostrar contraseña', 'Show password')"
            :aria-pressed="isVisible"
            @click="isVisible = !isVisible"
        >
            <EyeSlashIcon v-if="isVisible" class="h-5 w-5" aria-hidden="true" />
            <EyeIcon v-else class="h-5 w-5" aria-hidden="true" />
        </button>
    </div>
</template>
