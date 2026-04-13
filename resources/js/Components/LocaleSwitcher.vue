<script setup>
import { useLocale } from '@/Composables/useLocale'

const { locale, setLocale, tr } = useLocale()

defineProps({
    compact: {
        type: Boolean,
        default: false,
    },
})
</script>

<template>
    <div class="rounded-lg border border-gray-200 bg-gray-50 px-3 py-3">
        <label class="block text-[11px] font-semibold uppercase tracking-wide text-gray-500 mb-1">
            {{ tr('Idioma', 'Language') }}
        </label>
        <select
            :value="locale"
            @change="setLocale($event.target.value)"
            class="w-full rounded border border-gray-300 bg-white px-2 py-1.5 text-sm text-gray-700"
            :class="compact ? 'text-xs py-1' : ''"
        >
            <option value="es">Español</option>
            <option value="en">English</option>
        </select>
    </div>
</template>
