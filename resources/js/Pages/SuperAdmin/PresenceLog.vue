<script setup>
import { computed } from 'vue'
import { router } from '@inertiajs/vue3'
import PathfinderLayout from '@/Layouts/PathfinderLayout.vue'
import { useLocale } from '@/Composables/useLocale'

const props = defineProps({
    online_threshold_minutes: { type: Number, default: 5 },
    recent_threshold_minutes: { type: Number, default: 30 },
    generated_at: String,
    online_users: { type: Array, default: () => [] },
    recent_users: { type: Array, default: () => [] },
})

const { tr, locale } = useLocale()

const formatDateTime = (value) => {
    if (!value) return '—'
    const date = new Date(value)
    if (Number.isNaN(date.getTime())) return String(value)

    return new Intl.DateTimeFormat(locale.value === 'en' ? 'en-US' : 'es-ES', {
        year: 'numeric',
        month: 'short',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
    }).format(date)
}

const generatedAt = computed(() => formatDateTime(props.generated_at))

const roleLabel = (user) => user.role_key || user.profile_type || '—'

const scopeLabel = (user) => {
    if (user.scope_type === 'global') return 'Global'
    if (user.scope_type === 'club') return user.club_id ? `Club #${user.club_id}` : 'Club'
    if (user.scope_type === 'church') return user.church_name || (user.church_id ? `Iglesia #${user.church_id}` : 'Iglesia')
    if (user.scope_type === 'district') return user.scope_id ? `Distrito #${user.scope_id}` : 'Distrito'
    if (user.scope_type === 'association') return user.scope_id ? `${tr('Asociacion', 'Association')} #${user.scope_id}` : tr('Asociacion', 'Association')
    if (user.scope_type === 'union') return user.scope_id ? `${tr('Union', 'Union')} #${user.scope_id}` : tr('Union', 'Union')
    return '—'
}

const reload = () => {
    router.reload({
        only: ['online_users', 'recent_users', 'generated_at'],
        preserveScroll: true,
    })
}
</script>

<template>
    <PathfinderLayout>
        <template #title>{{ tr('Usuarios en línea', 'Online users') }}</template>

        <div class="mx-auto max-w-6xl space-y-4 px-3 sm:px-4 lg:px-0">
            <section class="rounded-lg border bg-white p-4 shadow-sm sm:p-5">
                <div class="flex flex-col gap-4 md:flex-row md:items-start md:justify-between">
                    <div>
                        <h2 class="text-lg font-semibold text-gray-900">{{ tr('Conexiones recientes', 'Recent connections') }}</h2>
                        <p class="mt-1 text-sm text-gray-600">
                            {{ tr('Un usuario se considera en línea si tuvo actividad en los últimos', 'A user is considered online if they had activity in the last') }}
                            {{ online_threshold_minutes }}
                            {{ tr('minutos.', 'minutes.') }}
                        </p>
                        <p class="mt-1 text-xs text-gray-500">
                            {{ tr('Actualizado:', 'Updated:') }} {{ generatedAt }}
                        </p>
                    </div>
                    <button
                        type="button"
                        class="w-full rounded bg-gray-900 px-4 py-3 text-sm font-medium text-white hover:bg-gray-800 sm:w-auto sm:py-2"
                        @click="reload"
                    >
                        {{ tr('Actualizar', 'Refresh') }}
                    </button>
                </div>

                <div class="mt-5 grid gap-3 sm:grid-cols-2">
                    <div class="rounded-lg border border-emerald-200 bg-emerald-50 p-4">
                        <div class="text-sm text-emerald-700">{{ tr('En línea ahora', 'Online now') }}</div>
                        <div class="mt-1 text-2xl font-semibold text-emerald-950">{{ online_users.length }}</div>
                    </div>
                    <div class="rounded-lg border border-blue-200 bg-blue-50 p-4">
                        <div class="text-sm text-blue-700">
                            {{ tr('Vistos en los últimos', 'Seen in the last') }} {{ recent_threshold_minutes }} {{ tr('minutos', 'minutes') }}
                        </div>
                        <div class="mt-1 text-2xl font-semibold text-blue-950">{{ recent_users.length }}</div>
                    </div>
                </div>
            </section>

            <section class="rounded-lg border bg-white p-4 shadow-sm sm:p-5">
                <h3 class="text-base font-semibold text-gray-900">{{ tr('Usuarios en línea', 'Online users') }}</h3>
                <div v-if="!online_users.length" class="mt-4 rounded border border-dashed border-gray-200 bg-gray-50 p-5 text-sm text-gray-500">
                    {{ tr('No hay usuarios activos en este momento.', 'There are no active users right now.') }}
                </div>
                <div v-else class="mt-4 space-y-3 md:hidden">
                    <article v-for="user in online_users" :key="`mobile-online-${user.id}`" class="rounded border border-gray-200 p-3">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <div class="break-words font-medium text-gray-900">{{ user.name }}</div>
                                <div class="break-all text-xs text-gray-500">{{ user.email }}</div>
                                <div class="mt-1 break-words text-xs text-gray-600">{{ roleLabel(user) }} • {{ scopeLabel(user) }}</div>
                            </div>
                            <span class="shrink-0 rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-medium text-emerald-800">
                                {{ formatDateTime(user.last_seen_at) }}
                            </span>
                        </div>
                    </article>
                </div>
                <div v-if="online_users.length" class="mt-4 hidden overflow-x-auto md:block">
                    <table class="min-w-full text-sm">
                        <thead class="bg-gray-50 text-gray-700">
                            <tr>
                                <th class="px-3 py-2 text-left">{{ tr('Usuario', 'User') }}</th>
                                <th class="px-3 py-2 text-left">{{ tr('Perfil', 'Profile') }}</th>
                                <th class="px-3 py-2 text-left">{{ tr('Alcance', 'Scope') }}</th>
                                <th class="px-3 py-2 text-left">{{ tr('Visto ultimamente el', 'Last seen at') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="user in online_users" :key="user.id" class="border-t">
                                <td class="px-3 py-2">
                                    <div class="font-medium text-gray-900">{{ user.name }}</div>
                                    <div class="text-xs text-gray-500">{{ user.email }}</div>
                                </td>
                                <td class="px-3 py-2">{{ roleLabel(user) }}</td>
                                <td class="px-3 py-2">{{ scopeLabel(user) }}</td>
                                <td class="px-3 py-2">
                                    <span class="inline-flex rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-medium text-emerald-800">
                                        {{ formatDateTime(user.last_seen_at) }}
                                    </span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="rounded-lg border bg-white p-4 shadow-sm sm:p-5">
                <h3 class="text-base font-semibold text-gray-900">{{ tr('Actividad reciente', 'Recent activity') }}</h3>
                <div v-if="!recent_users.length" class="mt-4 rounded border border-dashed border-gray-200 bg-gray-50 p-5 text-sm text-gray-500">
                    {{ tr('No hay otros usuarios vistos recientemente.', 'No other users were seen recently.') }}
                </div>
                <div v-else class="mt-4 grid gap-3 md:grid-cols-2">
                    <article v-for="user in recent_users" :key="user.id" class="rounded border border-gray-200 p-3">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <div class="break-words font-medium text-gray-900">{{ user.name }}</div>
                                <div class="break-all text-xs text-gray-500">{{ user.email }}</div>
                                <div class="mt-1 break-words text-xs text-gray-600">{{ roleLabel(user) }} • {{ scopeLabel(user) }}</div>
                            </div>
                            <div class="shrink-0 text-right text-xs text-gray-500">
                                {{ formatDateTime(user.last_seen_at) }}
                            </div>
                        </div>
                    </article>
                </div>
            </section>
        </div>
    </PathfinderLayout>
</template>
