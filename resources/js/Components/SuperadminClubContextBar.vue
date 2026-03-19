<script setup>
import axios from 'axios'
import { computed, ref, watch } from 'vue'
import { router, usePage } from '@inertiajs/vue3'

const props = defineProps({
    compact: {
        type: Boolean,
        default: false,
    },
    collapsed: {
        type: Boolean,
        default: false,
    },
})

const page = usePage()
const user = computed(() => page.props.auth?.user ?? null)
const superadminContext = computed(() => page.props.auth?.superadmin_context ?? null)
const userContext = computed(() => page.props.auth?.club_context ?? null)
const availableClubs = computed(() => page.props.auth?.available_clubs ?? [])
const activeClub = computed(() => page.props.auth?.active_club ?? null)
const isSuperadmin = computed(() => user.value?.profile_type === 'superadmin')
const context = computed(() => isSuperadmin.value ? superadminContext.value : userContext.value)
const clubs = computed(() => context.value?.available_clubs ?? availableClubs.value ?? [])
const selectedClubId = ref(context.value?.club_id ? String(context.value.club_id) : (activeClub.value?.id ? String(activeClub.value.id) : ''))
const saving = ref(false)
const error = ref('')

watch(
    () => [context.value?.club_id, activeClub.value?.id],
    (clubId) => {
        const resolvedClubId = Array.isArray(clubId) ? clubId[0] || clubId[1] : clubId
        selectedClubId.value = resolvedClubId ? String(resolvedClubId) : ''
    }
)

const selectedClub = computed(() =>
    clubs.value.find((club) => String(club.id) === String(selectedClubId.value)) ?? null
)

const isVisible = computed(() => {
    const path = page.url || ''
    const isClubFacingPage = path.startsWith('/club-director') || path.startsWith('/club-personal') || path.startsWith('/director/children')
    if (!isClubFacingPage) return false

    if (isSuperadmin.value) return true

    return clubs.value.length > 1
})

const saveClubContext = async () => {
    saving.value = true
    error.value = ''

    try {
        if (isSuperadmin.value) {
            await axios.post(route('superadmin.context.set'), {
                club_id: selectedClubId.value ? Number(selectedClubId.value) : null,
            })
        } else {
            await axios.post(route('club.select'), {
                club_id: selectedClubId.value ? Number(selectedClubId.value) : null,
                user_id: user.value?.id,
            })
        }

        router.reload({
            only: ['auth'],
            preserveScroll: true,
            onSuccess: () => {
                window.location.reload()
            },
        })
    } catch (err) {
        error.value = err?.response?.data?.message || 'No se pudo cambiar el club activo.'
    } finally {
        saving.value = false
    }
}
</script>

<template>
    <div
        v-if="isVisible"
        :class="props.compact ? 'mx-3 mb-3 rounded-lg border border-blue-200 bg-blue-50 px-3 py-3 shadow-sm' : 'mb-6 rounded-lg border border-blue-200 bg-blue-50 px-4 py-3 shadow-sm'"
    >
        <template v-if="props.compact">
            <div v-if="!props.collapsed" class="space-y-3">
                <div class="min-w-0">
                    <p class="text-xs font-semibold uppercase tracking-wide text-blue-900">
                        {{ isSuperadmin ? 'Contexto' : 'Club activo' }}
                    </p>
                    <p class="mt-1 text-xs text-blue-800">
                        {{ selectedClub?.club_name || 'Selecciona un club' }}
                    </p>
                </div>

                <div class="space-y-2">
                    <select
                        v-model="selectedClubId"
                        class="w-full rounded border border-blue-300 bg-white px-2 py-2 text-sm text-gray-800"
                    >
                        <option value="">Selecciona un club</option>
                        <option v-for="club in clubs" :key="club.id" :value="String(club.id)">
                            {{ club.club_name }}
                        </option>
                    </select>

                    <button
                        type="button"
                        class="w-full rounded bg-blue-600 px-3 py-2 text-sm font-medium text-white disabled:cursor-not-allowed disabled:opacity-60"
                        :disabled="saving"
                        @click="saveClubContext"
                    >
                        {{ saving ? 'Guardando...' : 'Cambiar' }}
                    </button>
                </div>

                <p v-if="error" class="text-xs text-red-600">{{ error }}</p>
            </div>
        </template>

        <template v-else>
            <div class="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
                <div class="min-w-0">
                    <p class="text-sm font-semibold text-blue-900">
                        {{ isSuperadmin ? 'Contexto de superadministrador' : 'Club activo' }}
                    </p>
                    <p class="text-xs text-blue-800">
                        {{ isSuperadmin ? 'Trabajas en vistas de club usando un solo club activo.' : 'Este club se mantendra activo en todas las vistas del club durante tu sesion.' }}
                        <span v-if="selectedClub">
                            Iglesia: {{ selectedClub.church_name || 'Sin iglesia' }}
                        </span>
                    </p>
                </div>

                <div class="flex flex-col gap-2 sm:flex-row sm:items-end">
                    <div class="min-w-[280px]">
                        <label class="mb-1 block text-xs font-medium text-blue-900">Club activo</label>
                        <select
                            v-model="selectedClubId"
                            class="w-full rounded border border-blue-300 bg-white px-3 py-2 text-sm text-gray-800"
                        >
                            <option value="">Selecciona un club</option>
                            <option v-for="club in clubs" :key="club.id" :value="String(club.id)">
                                {{ club.club_name }} ({{ club.club_type }})
                            </option>
                        </select>
                    </div>

                    <button
                        type="button"
                        class="rounded bg-blue-600 px-4 py-2 text-sm font-medium text-white disabled:cursor-not-allowed disabled:opacity-60"
                        :disabled="saving"
                        @click="saveClubContext"
                    >
                        {{ saving ? 'Guardando...' : 'Cambiar club' }}
                    </button>
                </div>
            </div>

            <p v-if="error" class="mt-2 text-xs text-red-600">{{ error }}</p>
        </template>
    </div>
</template>
