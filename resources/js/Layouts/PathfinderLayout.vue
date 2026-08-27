<script setup>
import { ref, h, onMounted, onBeforeUnmount, computed } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import ClubDirectorNav from '@/Components/Nav/ClubDirectorNav.vue'
import SuperAdminNav from '@/Components/Nav/SuperAdminNav.vue'
import ClubPersonalNav from '@/Components/Nav/ClubPersonalNav.vue'
import ParentNav from '@/Components/Nav/ParentNav.vue'
import UnionNav from '@/Components/Nav/UnionNav.vue'
import SuperadminClubContextBar from '@/Components/SuperadminClubContextBar.vue'
import LocaleSwitcher from '@/Components/LocaleSwitcher.vue'
import { useLocale } from '@/Composables/useLocale'

const isCollapsed = ref(false)
const isMobileOpen = ref(false)
const isMobile = ref(false)
const page = usePage()
const { t, tr } = useLocale()
const user = computed(() => page.props.auth?.user ?? null)
const isSuperadminParentPreview = computed(() => Boolean(page.props.is_superadmin_parent_preview))
const effectiveRole = computed(() => {
    if (isSuperadminParentPreview.value) return 'parent'

    return page.props.auth?.effective_role || user.value?.effective_role || user.value?.role_key || user.value?.profile_type || null
})
const primaryDirectorClub = computed(() => page.props.auth?.primary_director_club ?? null)
const activeClub = computed(() => page.props.auth?.active_club ?? null)
const isParentWatermark = computed(() => effectiveRole.value === 'parent')
const isStaffWatermark = computed(() => effectiveRole.value === 'club_personal')
const clubWatermarkUrl = computed(() => {
    if (isParentWatermark.value) return null

    return activeClub.value?.logo_url || primaryDirectorClub.value?.logo_url || null
})
const clubWatermarkName = computed(() => activeClub.value?.club_name || primaryDirectorClub.value?.club_name || '')
const watermarkLabel = computed(() => {
    if (isParentWatermark.value) return tr('Portal de Padres', 'Parent Portal')
    if (isStaffWatermark.value) return 'Staff'

    return ''
})
const hasPortalWatermark = computed(() => isParentWatermark.value || Boolean(clubWatermarkUrl.value))
const sidebarClubLabel = computed(() => primaryDirectorClub.value?.club_name || activeClub.value?.club_name || null)
const sidebarClubCaption = computed(() => primaryDirectorClub.value?.club_name ? t('primary_director') : t('active_club'))
const sessionDisplayName = computed(() => {
    if (isSuperadminParentPreview.value && page.props.parent_setup?.parent_name) {
        return page.props.parent_setup.parent_name
    }

    return user.value?.name
})

const logout = () => {
    if (!user.value) return
    router.post(route('logout'))
}
const updateIsMobile = () => {
    isMobile.value = window.innerWidth < 768
    if (!isMobile.value) {
        isMobileOpen.value = false
    }
}

onMounted(() => {
    const savedState = localStorage.getItem('sidebar-collapsed')
    isCollapsed.value = savedState === 'true'
    updateIsMobile()
    window.addEventListener('resize', updateIsMobile)
})

onBeforeUnmount(() => {
    window.removeEventListener('resize', updateIsMobile)
})
const navCollapsed = computed(() => (isMobile.value ? false : isCollapsed.value))
const getNavComponent = () => {
    const profileType = effectiveRole.value

    if (profileType === 'club_director') {
        return h(ClubDirectorNav, { isCollapsed: navCollapsed.value })
    } else if (profileType === 'treasurer') {
        return h(ClubDirectorNav, { isCollapsed: navCollapsed.value, financeOnly: true })
    } else if (profileType === 'superadmin') {
        return h(SuperAdminNav, { isCollapsed: navCollapsed.value })
    } else if (profileType === 'club_personal') {
        return h(ClubPersonalNav, { isCollapsed: navCollapsed.value })
    } else if (['union_youth_director', 'union_manager', 'association_youth_director', 'district_pastor', 'district_secretary'].includes(profileType)) {
        return h(UnionNav, { isCollapsed: navCollapsed.value })
    } else if (profileType === 'parent') {
        return h(ParentNav, { isCollapsed: navCollapsed.value })
    }
    return null
}
const toggleSidebar = () => {
    isCollapsed.value = !isCollapsed.value
    localStorage.setItem('sidebar-collapsed', isCollapsed.value)
}
const openMobileSidebar = () => {
    isMobileOpen.value = true
}
const closeMobileSidebar = () => {
    isMobileOpen.value = false
}
const sidebarWidthClass = computed(() => {
    if (isMobile.value) return 'w-72'
    return isCollapsed.value ? 'w-20' : 'w-64'
})
const sidebarTransformClass = computed(() => {
    if (!isMobile.value) return 'translate-x-0'
    return isMobileOpen.value ? 'translate-x-0' : '-translate-x-full'
})
const sidebarTransitionClass = computed(() => {
    return isMobile.value ? 'transition-transform' : 'transition-all'
})
const mainOffsetClass = computed(() => {
    if (isMobile.value) return 'ml-0'
    return isCollapsed.value ? 'ml-20' : 'ml-64'
})
</script>
<template>
<div class="min-h-screen flex bg-gray-100 overflow-hidden">
    <!-- Sidebar -->
    <aside :class="[
    'fixed inset-y-0 left-0 bg-white border-r border-gray-200 duration-300 shadow-md z-40 overflow-y-auto overscroll-contain',
    sidebarWidthClass,
    sidebarTransformClass,
    sidebarTransitionClass
]" class="flex flex-col">
        <!-- Logo + Toggle -->
        <div class="flex items-center justify-between px-4 py-4 border-b">
            <img v-if="!navCollapsed" src="/images/logo-bg.png" alt="Pathfinder Club" class="h-10" />
            <button v-if="!isMobile" @click="toggleSidebar" class="text-gray-500 hover:text-red-600">
                <span v-if="isCollapsed">▶</span>
                <span v-else>◀</span>
            </button>
            <button v-else @click="closeMobileSidebar" class="text-gray-500 hover:text-red-600" :aria-label="t('close_menu')">
                ✕
            </button>
        </div>

        <!-- Navigation by Role -->
        <component :is="getNavComponent()" />

        <div
            v-if="user && !navCollapsed && isParentWatermark"
            class="mx-3 mb-3 whitespace-nowrap rounded-md bg-slate-900 px-3 py-2 text-center text-sm font-semibold text-white"
        >
            {{ watermarkLabel }}
        </div>

        <div v-if="user && !navCollapsed" class="mx-3 mb-3 rounded-lg border border-gray-200 bg-gray-50 px-3 py-3">
            <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ t('session') }}</p>
            <p class="mt-1 text-sm font-semibold text-gray-900 truncate">{{ sessionDisplayName }}</p>
            <p v-if="sidebarClubLabel" class="mt-2 text-xs text-gray-500">{{ sidebarClubCaption }}</p>
            <p v-if="sidebarClubLabel" class="text-sm text-gray-800 truncate">{{ sidebarClubLabel }}</p>
        </div>

        <div v-if="user && !navCollapsed" class="mx-3 mb-3">
            <LocaleSwitcher />
        </div>

        <SuperadminClubContextBar v-if="!isSuperadminParentPreview" :compact="true" :collapsed="navCollapsed" />

        <!-- Logout -->
        <div class="px-4 py-4 border-t mt-auto">
            <button @click="logout" class="w-full text-left text-sm text-red-600 hover:underline" :class="{ 'text-center': navCollapsed }">
                <span v-if="!navCollapsed">{{ t('logout') }}</span>
                <span v-else>🚪</span>
            </button>
        </div>
    </aside>

    <div v-if="isMobile && isMobileOpen" class="fixed inset-0 bg-black/40 z-30" @click="closeMobileSidebar"></div>

    <!-- Main content -->
    <main :class="[mainOffsetClass]" class="relative flex-1 min-h-screen overflow-hidden md:h-screen">
        <div
            v-if="hasPortalWatermark"
            class="pointer-events-none fixed z-0 flex select-none opacity-[0.055]"
            :class="isParentWatermark
                ? 'bottom-4 right-4 h-auto w-auto items-end justify-end sm:bottom-6 sm:right-6'
                : 'bottom-[-5rem] right-[-4rem] h-[25rem] w-[25rem] items-center justify-center sm:bottom-[-7rem] sm:right-[-5rem] sm:h-[34rem] sm:w-[34rem] lg:bottom-[-9rem] lg:right-[-6rem] lg:h-[42rem] lg:w-[42rem]'"
            aria-hidden="true"
            data-testid="club-watermark"
        >
            <div
                v-if="isParentWatermark"
                class="whitespace-nowrap text-right text-3xl font-semibold leading-tight text-gray-800 sm:text-4xl lg:text-5xl"
            >
                {{ watermarkLabel }}
            </div>
            <div v-else-if="isStaffWatermark" class="flex h-full w-full flex-col items-center justify-center gap-2">
                <img
                    :src="clubWatermarkUrl"
                    :alt="clubWatermarkName"
                    class="max-h-[72%] max-w-[85%] object-contain grayscale"
                    draggable="false"
                />
                <div class="text-5xl font-black uppercase tracking-[0.2em] text-slate-900 sm:text-7xl">
                    {{ watermarkLabel }}
                </div>
            </div>
            <img
                v-else
                :src="clubWatermarkUrl"
                :alt="clubWatermarkName"
                class="max-h-full max-w-full object-contain grayscale"
                draggable="false"
            />
        </div>

        <div class="relative z-10 h-full overflow-y-auto p-4 sm:p-6 md:p-8">
        <div class="max-w-5xl mx-auto">
            <div class="mb-4 flex items-center gap-3 md:hidden">
                <button @click="openMobileSidebar"
                    class="inline-flex items-center justify-center rounded-md border border-gray-200 bg-white px-3 py-2 text-sm text-gray-700 shadow-sm">
                    ☰
                </button>
                <div class="text-sm font-semibold text-gray-800">{{ t('menu') }}</div>
            </div>
            <div v-if="user" class="mb-4 md:hidden">
                <LocaleSwitcher :compact="true" />
            </div>
            <h1 class="text-2xl font-bold text-gray-800 mb-6">
                <slot name="title">{{ t('pathfinder_portal') }}</slot>
            </h1>
            <slot />
        </div>
        </div>
    </main>
</div>
</template>
