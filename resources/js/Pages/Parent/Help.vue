<script setup>
import { Head } from '@inertiajs/vue3'
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import {
    ArrowsPointingInIcon,
    ArrowsPointingOutIcon,
    ChevronDownIcon,
    ChevronUpIcon,
} from '@heroicons/vue/24/outline'
import PathfinderLayout from '@/Layouts/PathfinderLayout.vue'
import { useLocale } from '@/Composables/useLocale'

const props = defineProps({
    account_guide_url: { type: String, required: true },
    payments_guide_url: { type: String, required: true },
})

const { tr } = useLocale()
const expandedGuide = ref(null)
const fullscreenGuide = ref(null)
let previousBodyOverflow = ''

const guides = computed(() => [
    {
        id: 'account-and-children',
        url: props.account_guide_url,
        titleEs: 'Cómo crear una cuenta y registrar a tus hijos',
        titleEn: 'How to create an account and register your children',
    },
    {
        id: 'payments',
        url: props.payments_guide_url,
        titleEs: 'Cómo usar la vista de Pagos',
        titleEn: 'How to use the Payments view',
    },
])

const viewerStyle = computed(() => fullscreenGuide.value
    ? { height: 'calc(100vh - 10rem)', minHeight: '0' }
    : { height: 'calc(100vh - 13rem)', minHeight: '44rem' })

const toggleExpanded = (id) => {
    expandedGuide.value = expandedGuide.value === id ? null : id
    if (expandedGuide.value !== id && fullscreenGuide.value === id) fullscreenGuide.value = null
}

const toggleFullscreen = (id) => {
    fullscreenGuide.value = fullscreenGuide.value === id ? null : id
}

const closeFullscreenWithEscape = (event) => {
    if (event.key === 'Escape' && fullscreenGuide.value) fullscreenGuide.value = null
}

watch(fullscreenGuide, (active) => {
    if (active) {
        previousBodyOverflow = document.body.style.overflow
        document.body.style.overflow = 'hidden'
    } else {
        document.body.style.overflow = previousBodyOverflow
    }
})

onMounted(() => window.addEventListener('keydown', closeFullscreenWithEscape))
onBeforeUnmount(() => {
    window.removeEventListener('keydown', closeFullscreenWithEscape)
    document.body.style.overflow = previousBodyOverflow
})
</script>

<template>
    <Head :title="tr('Ayuda', 'Help')" />

    <PathfinderLayout>
        <template #title>{{ tr('Ayuda', 'Help') }}</template>

        <div class="space-y-4">
            <Teleport
                v-for="guide in guides"
                :key="guide.id"
                to="body"
                :disabled="fullscreenGuide !== guide.id"
            >
            <section
                class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm"
                :class="fullscreenGuide === guide.id ? 'fixed inset-0 z-50 rounded-none border-0' : ''"
            >
                <header class="border-gray-200 px-4 py-4 sm:px-6" :class="expandedGuide === guide.id ? 'border-b' : ''">
                    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <h2 class="text-lg font-semibold text-gray-900 sm:text-xl">
                                {{ guide.titleEs }}
                            </h2>
                            <p class="mt-1 text-sm text-gray-600 sm:text-base">
                                {{ guide.titleEn }}
                            </p>
                        </div>

                        <div class="flex flex-wrap items-center gap-2">
                            <a
                                v-if="expandedGuide === guide.id"
                                :href="guide.url"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="inline-flex shrink-0 items-center justify-center rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-800 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-slate-500 focus:ring-offset-2"
                            >
                                {{ tr('Abrir PDF', 'Open PDF') }}
                            </a>

                            <button
                                v-if="expandedGuide === guide.id"
                                type="button"
                                class="inline-flex shrink-0 items-center justify-center gap-2 rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-800 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-slate-500 focus:ring-offset-2"
                                @click="toggleFullscreen(guide.id)"
                            >
                                <ArrowsPointingInIcon v-if="fullscreenGuide === guide.id" class="h-5 w-5" />
                                <ArrowsPointingOutIcon v-else class="h-5 w-5" />
                                {{ fullscreenGuide === guide.id ? tr('Salir de pantalla completa', 'Exit full screen') : tr('Pantalla completa', 'Full screen') }}
                            </button>

                            <button
                                type="button"
                                class="inline-flex shrink-0 items-center justify-center gap-2 rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-700 focus:outline-none focus:ring-2 focus:ring-slate-500 focus:ring-offset-2"
                                :aria-expanded="expandedGuide === guide.id"
                                :aria-controls="`parent-guide-${guide.id}`"
                                @click="toggleExpanded(guide.id)"
                            >
                                <ChevronUpIcon v-if="expandedGuide === guide.id" class="h-5 w-5" />
                                <ChevronDownIcon v-else class="h-5 w-5" />
                                {{ expandedGuide === guide.id ? tr('Ocultar guía', 'Hide guide') : tr('Mostrar guía', 'Show guide') }}
                            </button>
                        </div>
                    </div>
                </header>

                <div v-if="expandedGuide === guide.id" :id="`parent-guide-${guide.id}`" class="bg-gray-100 p-2 sm:p-4">
                    <iframe
                        :src="guide.url"
                        :title="`${guide.titleEs} / ${guide.titleEn}`"
                        class="w-full rounded-lg border border-gray-300 bg-white"
                        :style="viewerStyle"
                    />
                    <p class="mt-3 text-center text-sm text-gray-600">
                        {{ tr('Si el documento no aparece, usa el botón “Abrir PDF”.', 'If the document does not appear, use the “Open PDF” button.') }}
                    </p>
                </div>
            </section>
            </Teleport>
        </div>
    </PathfinderLayout>
</template>
