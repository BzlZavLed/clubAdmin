<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import axios from 'axios'
import {
    ArrowPathIcon,
    CheckCircleIcon,
    ClockIcon,
} from '@heroicons/vue/24/outline'
import { useLocale } from '@/Composables/useLocale'

const props = defineProps({
    event: { type: Object, required: true },
    data_url: { type: String, required: true },
})

const { tr } = useLocale()

const loading = ref(false)
const finishingId = ref(null)
const error = ref('')
const kitchenData = ref({
    event: props.event,
    pending_orders: [],
    finished_orders: [],
    summary: { pending_count: 0, finished_count: 0 },
})
let refreshTimer = null
const REFRESH_INTERVAL_MS = 3000

const pendingOrders = computed(() => kitchenData.value.pending_orders || [])
const finishedOrders = computed(() => kitchenData.value.finished_orders || [])
const currentOrder = computed(() => pendingOrders.value[0] || null)
const queuedOrders = computed(() => pendingOrders.value.slice(1))

const formatMoney = (value) => {
    const amount = Number(value || 0)

    return `${amount < 0 ? '-' : ''}$${Math.abs(amount).toFixed(2)}`
}

const formatTime = (value) => {
    if (!value) return '—'

    return new Intl.DateTimeFormat(undefined, {
        hour: 'numeric',
        minute: '2-digit',
    }).format(new Date(value))
}

const orderLabel = (order) => `#${String(order?.id || '').padStart(4, '0')}`

const applyData = (payload) => {
    kitchenData.value = payload?.data || payload || kitchenData.value
}

const loadOrders = async ({ silent = false } = {}) => {
    if (!silent) loading.value = true
    error.value = ''

    try {
        const response = await axios.get(props.data_url, {
            headers: { Accept: 'application/json' },
        })
        applyData(response.data)
    } catch (err) {
        error.value = tr('No se pudieron cargar las ordenes.', 'Could not load orders.')
        console.error(err)
    } finally {
        if (!silent) loading.value = false
    }
}

const finishOrder = async (order) => {
    if (!order?.finish_url) return

    finishingId.value = order.id
    error.value = ''

    try {
        const response = await axios.post(order.finish_url, {}, {
            headers: { Accept: 'application/json' },
        })
        applyData(response.data)
    } catch (err) {
        error.value = tr('No se pudo marcar la orden como lista.', 'Could not mark the order as finished.')
        console.error(err)
    } finally {
        finishingId.value = null
    }
}

onMounted(() => {
    loadOrders()
    refreshTimer = window.setInterval(() => loadOrders({ silent: true }), REFRESH_INTERVAL_MS)
})

onBeforeUnmount(() => {
    if (refreshTimer) window.clearInterval(refreshTimer)
})
</script>

<template>
    <main class="min-h-screen bg-gray-100 text-gray-950">
        <header class="border-b border-gray-200 bg-white px-4 py-4 shadow-sm">
            <div class="mx-auto flex max-w-7xl flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <p class="text-sm font-semibold uppercase tracking-wide text-emerald-700">{{ tr('Cocina fundraiser', 'Fundraiser kitchen') }}</p>
                    <h1 class="text-2xl font-bold">{{ kitchenData.event?.name || event.name }}</h1>
                    <p class="mt-1 text-sm text-gray-500">
                        {{ pendingOrders.length }} {{ tr('pendientes', 'pending') }} · {{ finishedOrders.length }} {{ tr('listas recientes', 'recent finished') }}
                    </p>
                </div>
                <button
                    type="button"
                    class="inline-flex items-center justify-center gap-2 rounded-lg border border-gray-200 bg-white px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 disabled:opacity-60"
                    :disabled="loading"
                    @click="loadOrders"
                >
                    <ArrowPathIcon class="h-4 w-4" :class="{ 'animate-spin': loading }" />
                    {{ tr('Actualizar', 'Refresh') }}
                </button>
            </div>
        </header>

        <div class="mx-auto grid max-w-7xl gap-4 px-4 py-5 xl:grid-cols-[minmax(0,1fr)_380px]">
            <section class="rounded-lg border border-gray-200 bg-white shadow-sm">
                <div class="border-b border-gray-200 px-5 py-4">
                    <div class="flex items-center gap-2">
                        <ClockIcon class="h-5 w-5 text-emerald-700" />
                        <h2 class="text-lg font-semibold">{{ tr('Preparar ahora', 'Prepare now') }}</h2>
                    </div>
                </div>

                <div v-if="error" class="m-5 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">
                    {{ error }}
                </div>

                <div v-if="currentOrder" class="p-5">
                    <div class="flex flex-col gap-3 border-b border-gray-100 pb-4 sm:flex-row sm:items-start sm:justify-between">
                        <div>
                            <div class="text-sm font-semibold text-gray-500">{{ orderLabel(currentOrder) }} · {{ formatTime(currentOrder.created_at) }}</div>
                            <div class="mt-1 text-3xl font-bold">{{ currentOrder.customer_name || tr('Venta general', 'General sale') }}</div>
                            <div class="mt-1 text-sm text-gray-500">{{ formatMoney(currentOrder.total_amount) }}</div>
                        </div>
                        <button
                            type="button"
                            class="inline-flex items-center justify-center gap-2 rounded-lg bg-emerald-600 px-5 py-3 text-base font-semibold text-white hover:bg-emerald-700 disabled:opacity-60"
                            :disabled="finishingId === currentOrder.id"
                            @click="finishOrder(currentOrder)"
                        >
                            <CheckCircleIcon class="h-5 w-5" />
                            {{ finishingId === currentOrder.id ? tr('Marcando...', 'Marking...') : tr('Marcar listo', 'Mark finished') }}
                        </button>
                    </div>

                    <div class="mt-5 divide-y divide-gray-100 rounded-lg border border-gray-200">
                        <div
                            v-for="item in currentOrder.items"
                            :key="item.id"
                            class="grid grid-cols-[76px_minmax(0,1fr)_92px] items-center gap-3 px-4 py-3"
                        >
                            <div class="rounded-lg bg-emerald-50 px-3 py-2 text-center text-2xl font-bold text-emerald-800">
                                {{ item.quantity }}x
                            </div>
                            <div class="text-xl font-semibold">{{ item.item_name }}</div>
                            <div class="text-right text-sm font-semibold text-gray-500">{{ formatMoney(item.line_total) }}</div>
                        </div>
                    </div>
                </div>

                <div v-else class="px-5 py-16 text-center">
                    <CheckCircleIcon class="mx-auto h-12 w-12 text-emerald-600" />
                    <h2 class="mt-3 text-xl font-semibold">{{ tr('No hay ordenes pendientes.', 'No pending orders.') }}</h2>
                    <p class="mt-1 text-sm text-gray-500">{{ tr('La pantalla se actualiza automaticamente.', 'This screen refreshes automatically.') }}</p>
                </div>
            </section>

            <aside class="space-y-4">
                <section class="rounded-lg border border-gray-200 bg-white shadow-sm">
                    <div class="border-b border-gray-200 px-4 py-3">
                        <h2 class="font-semibold">{{ tr('En cola', 'Queue') }}</h2>
                    </div>
                    <div class="divide-y divide-gray-100">
                        <div v-for="order in queuedOrders" :key="order.id" class="px-4 py-3">
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <div class="font-semibold">{{ orderLabel(order) }}</div>
                                    <div class="text-sm text-gray-500">{{ order.customer_name || tr('Venta general', 'General sale') }} · {{ formatTime(order.created_at) }}</div>
                                </div>
                                <div class="text-sm font-semibold text-gray-900">{{ order.items.reduce((total, item) => total + Number(item.quantity || 0), 0) }}</div>
                            </div>
                            <div class="mt-2 text-sm text-gray-600">
                                {{ order.items.map((item) => `${item.quantity}x ${item.item_name}`).join(', ') }}
                            </div>
                        </div>
                        <div v-if="queuedOrders.length === 0" class="px-4 py-6 text-center text-sm text-gray-500">
                            {{ tr('Sin ordenes en espera.', 'No queued orders.') }}
                        </div>
                    </div>
                </section>

                <section class="rounded-lg border border-gray-200 bg-white shadow-sm">
                    <div class="border-b border-gray-200 px-4 py-3">
                        <h2 class="font-semibold">{{ tr('Listas recientes', 'Recently finished') }}</h2>
                    </div>
                    <div class="divide-y divide-gray-100">
                        <div v-for="order in finishedOrders" :key="order.id" class="px-4 py-3">
                            <div class="flex items-center justify-between gap-3">
                                <div>
                                    <div class="font-semibold">{{ orderLabel(order) }}</div>
                                    <div class="text-sm text-gray-500">{{ order.customer_name || tr('Venta general', 'General sale') }}</div>
                                </div>
                                <div class="text-xs font-semibold text-emerald-700">{{ formatTime(order.kitchen_completed_at) }}</div>
                            </div>
                        </div>
                        <div v-if="finishedOrders.length === 0" class="px-4 py-6 text-center text-sm text-gray-500">
                            {{ tr('Aun no hay ordenes listas.', 'No finished orders yet.') }}
                        </div>
                    </div>
                </section>
            </aside>
        </div>
    </main>
</template>
