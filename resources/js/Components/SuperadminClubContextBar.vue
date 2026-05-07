<script setup>
import axios from 'axios'
import { computed, ref, watch } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import { useLocale } from '@/Composables/useLocale'

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
const { t } = useLocale()
const user = computed(() => page.props.auth?.user ?? null)
const superadminContext = computed(() => page.props.auth?.superadmin_context ?? null)
const userContext = computed(() => page.props.auth?.club_context ?? null)
const availableClubs = computed(() => page.props.auth?.available_clubs ?? [])
const activeClub = computed(() => page.props.auth?.active_club ?? null)
const isSuperadmin = computed(() => user.value?.profile_type === 'superadmin')
const context = computed(() => isSuperadmin.value ? superadminContext.value : userContext.value)

const selectedUnionId = ref(superadminContext.value?.union_id ? String(superadminContext.value.union_id) : '')
const selectedAssociationId = ref(superadminContext.value?.association_id ? String(superadminContext.value.association_id) : '')
const selectedDistrictId = ref(superadminContext.value?.district_id ? String(superadminContext.value.district_id) : '')
const selectedClubId = ref(
    isSuperadmin.value
        ? (superadminContext.value?.club_id ? String(superadminContext.value.club_id) : '')
        : (context.value?.club_id ? String(context.value.club_id) : (activeClub.value?.id ? String(activeClub.value.id) : ''))
)
const saving = ref(false)
const error = ref('')

const unions = computed(() => superadminContext.value?.available_unions ?? [])
const associations = computed(() => superadminContext.value?.available_associations ?? [])
const districts = computed(() => superadminContext.value?.available_districts ?? [])
const clubs = computed(() => {
    if (isSuperadmin.value) {
        return superadminContext.value?.available_clubs ?? []
    }

    return context.value?.available_clubs ?? availableClubs.value ?? []
})

const filteredAssociations = computed(() => {
    if (!selectedUnionId.value) return []
    return associations.value.filter((association) => String(association.union_id) === String(selectedUnionId.value))
})

const filteredDistricts = computed(() => {
    if (!selectedAssociationId.value) return []
    return districts.value.filter((district) => String(district.association_id) === String(selectedAssociationId.value))
})

const filteredClubs = computed(() => {
    if (isSuperadmin.value) {
        if (!selectedDistrictId.value) return []
        return clubs.value.filter((club) => String(club.district_id) === String(selectedDistrictId.value))
    }

    return clubs.value
})

watch(
    () => superadminContext.value,
    (value) => {
        if (!isSuperadmin.value || !value) return
        selectedUnionId.value = value.union_id ? String(value.union_id) : ''
        selectedAssociationId.value = value.association_id ? String(value.association_id) : ''
        selectedDistrictId.value = value.district_id ? String(value.district_id) : ''
        selectedClubId.value = value.club_id ? String(value.club_id) : ''
    },
    { deep: true }
)

watch(selectedUnionId, () => {
    if (!isSuperadmin.value) return
    const exists = filteredAssociations.value.some((association) => String(association.id) === String(selectedAssociationId.value))
    if (!exists) {
        selectedAssociationId.value = ''
        selectedDistrictId.value = ''
        selectedClubId.value = ''
    }
})

watch(selectedAssociationId, () => {
    if (!isSuperadmin.value) return
    const exists = filteredDistricts.value.some((district) => String(district.id) === String(selectedDistrictId.value))
    if (!exists) {
        selectedDistrictId.value = ''
        selectedClubId.value = ''
    }
})

watch(selectedDistrictId, () => {
    if (!isSuperadmin.value) return
    const exists = filteredClubs.value.some((club) => String(club.id) === String(selectedClubId.value))
    if (!exists) {
        selectedClubId.value = ''
    }
})

const selectedClub = computed(() =>
    filteredClubs.value.find((club) => String(club.id) === String(selectedClubId.value)) ?? null
)

const selectedLabel = computed(() => {
    if (!isSuperadmin.value) {
        return selectedClub.value?.club_name || t('select_club')
    }

    return (
        selectedClub.value?.club_name
        || filteredDistricts.value.find((district) => String(district.id) === String(selectedDistrictId.value))?.name
        || filteredAssociations.value.find((association) => String(association.id) === String(selectedAssociationId.value))?.name
        || unions.value.find((union) => String(union.id) === String(selectedUnionId.value))?.name
        || t('superadmin')
    )
})

const isVisible = computed(() => {
    if (isSuperadmin.value) return true

    const path = page.url || ''
    const isClubFacingPage = path.startsWith('/club-director') || path.startsWith('/club-personal') || path.startsWith('/director/children')
    if (!isClubFacingPage) return false

    return clubs.value.length > 1
})

const saveContext = async () => {
    saving.value = true
    error.value = ''

    try {
        if (isSuperadmin.value) {
            const { data } = await axios.post(route('superadmin.context.set'), {
                union_id: selectedUnionId.value ? Number(selectedUnionId.value) : null,
                association_id: selectedAssociationId.value ? Number(selectedAssociationId.value) : null,
                district_id: selectedDistrictId.value ? Number(selectedDistrictId.value) : null,
                club_id: selectedClubId.value ? Number(selectedClubId.value) : null,
            })

            window.location.href = data?.context?.dashboard_url || route('superadmin.dashboard')
            return
        }

        await axios.post(route('club.select'), {
            club_id: selectedClubId.value ? Number(selectedClubId.value) : null,
            user_id: user.value?.id,
        })

        router.reload({
            only: ['auth'],
            preserveScroll: true,
            onSuccess: () => {
                window.location.reload()
            },
        })
    } catch (err) {
        error.value = err?.response?.data?.message || t('unable_change_active_context')
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
                        {{ isSuperadmin ? t('context') : t('active_club') }}
                    </p>
                    <p class="mt-1 text-xs text-blue-800 truncate">
                        {{ selectedLabel }}
                    </p>
                    <p v-if="isSuperadmin && superadminContext?.role" class="mt-1 text-[11px] text-blue-700">
                        {{ superadminContext.role }}
                    </p>
                </div>

                <div v-if="isSuperadmin" class="space-y-2">
                    <select v-model="selectedUnionId" class="w-full rounded border border-blue-300 bg-white px-2 py-2 text-sm text-gray-800">
                        <option value="">{{ t('union') }}</option>
                        <option v-for="union in unions" :key="union.id" :value="String(union.id)">
                            {{ union.name }}
                        </option>
                    </select>

                    <select v-model="selectedAssociationId" class="w-full rounded border border-blue-300 bg-white px-2 py-2 text-sm text-gray-800" :disabled="!selectedUnionId">
                        <option value="">{{ t('association') }}</option>
                        <option v-for="association in filteredAssociations" :key="association.id" :value="String(association.id)">
                            {{ association.name }}
                        </option>
                    </select>

                    <select v-model="selectedDistrictId" class="w-full rounded border border-blue-300 bg-white px-2 py-2 text-sm text-gray-800" :disabled="!selectedAssociationId">
                        <option value="">{{ t('district') }}</option>
                        <option v-for="district in filteredDistricts" :key="district.id" :value="String(district.id)">
                            {{ district.name }}
                        </option>
                    </select>

                    <select v-model="selectedClubId" class="w-full rounded border border-blue-300 bg-white px-2 py-2 text-sm text-gray-800" :disabled="!selectedDistrictId">
                        <option value="">{{ t('club') }}</option>
                        <option v-for="club in filteredClubs" :key="club.id" :value="String(club.id)">
                            {{ club.club_name }}
                        </option>
                    </select>
                </div>

                <div v-else class="space-y-2">
                    <select
                        v-model="selectedClubId"
                        class="w-full rounded border border-blue-300 bg-white px-2 py-2 text-sm text-gray-800"
                    >
                        <option value="">{{ t('select_club') }}</option>
                        <option v-for="club in filteredClubs" :key="club.id" :value="String(club.id)">
                            {{ club.club_name }}
                        </option>
                    </select>
                </div>

                <button
                    type="button"
                    class="w-full rounded bg-blue-600 px-3 py-2 text-sm font-medium text-white disabled:cursor-not-allowed disabled:opacity-60"
                    :disabled="saving"
                    @click="saveContext"
                >
                    {{ saving ? t('saving') : (isSuperadmin ? t('enter') : t('change')) }}
                </button>

                <p v-if="error" class="text-xs text-red-600">{{ error }}</p>
            </div>
        </template>

        <template v-else>
            <div class="flex flex-col gap-3">
                <div class="min-w-0">
                    <p class="text-sm font-semibold text-blue-900">
                        {{ isSuperadmin ? t('superadmin_context') : t('active_club') }}
                    </p>
                    <p class="text-xs text-blue-800">
                        <span>{{ selectedLabel }}</span>
                        <span v-if="isSuperadmin && superadminContext?.role"> | {{ superadminContext.role }}</span>
                    </p>
                </div>

                <div v-if="isSuperadmin" class="grid grid-cols-1 md:grid-cols-4 gap-2">
                    <select v-model="selectedUnionId" class="w-full rounded border border-blue-300 bg-white px-3 py-2 text-sm text-gray-800">
                        <option value="">{{ t('union') }}</option>
                        <option v-for="union in unions" :key="union.id" :value="String(union.id)">
                            {{ union.name }}
                        </option>
                    </select>

                    <select v-model="selectedAssociationId" class="w-full rounded border border-blue-300 bg-white px-3 py-2 text-sm text-gray-800" :disabled="!selectedUnionId">
                        <option value="">{{ t('association') }}</option>
                        <option v-for="association in filteredAssociations" :key="association.id" :value="String(association.id)">
                            {{ association.name }}
                        </option>
                    </select>

                    <select v-model="selectedDistrictId" class="w-full rounded border border-blue-300 bg-white px-3 py-2 text-sm text-gray-800" :disabled="!selectedAssociationId">
                        <option value="">{{ t('district') }}</option>
                        <option v-for="district in filteredDistricts" :key="district.id" :value="String(district.id)">
                            {{ district.name }}
                        </option>
                    </select>

                    <select v-model="selectedClubId" class="w-full rounded border border-blue-300 bg-white px-3 py-2 text-sm text-gray-800" :disabled="!selectedDistrictId">
                        <option value="">{{ t('club') }}</option>
                        <option v-for="club in filteredClubs" :key="club.id" :value="String(club.id)">
                            {{ club.club_name }}
                        </option>
                    </select>
                </div>

                <div v-else class="flex flex-col gap-2 sm:flex-row sm:items-end">
                    <div class="min-w-[280px]">
                        <label class="mb-1 block text-xs font-medium text-blue-900">{{ t('active_club') }}</label>
                        <select
                            v-model="selectedClubId"
                            class="w-full rounded border border-blue-300 bg-white px-3 py-2 text-sm text-gray-800"
                        >
                            <option value="">{{ t('select_club') }}</option>
                            <option v-for="club in filteredClubs" :key="club.id" :value="String(club.id)">
                                {{ club.club_name }} ({{ club.club_type }})
                            </option>
                        </select>
                    </div>
                </div>

                <div>
                    <button
                        type="button"
                        class="rounded bg-blue-600 px-4 py-2 text-sm font-medium text-white disabled:cursor-not-allowed disabled:opacity-60"
                        :disabled="saving"
                        @click="saveContext"
                    >
                        {{ saving ? t('saving') : (isSuperadmin ? t('save_enter') : t('change_club')) }}
                    </button>
                </div>
            </div>

            <p v-if="error" class="mt-2 text-xs text-red-600">{{ error }}</p>
        </template>
    </div>
</template>
