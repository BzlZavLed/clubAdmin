<script setup>
import { ref, h, onMounted, onBeforeUnmount, computed } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import ClubDirectorNav from '@/Components/Nav/ClubDirectorNav.vue'
import SuperAdminNav from '@/Components/Nav/SuperAdminNav.vue'
import ClubPersonalNav from '@/Components/Nav/ClubPersonalNav.vue'
import ParentNav from '@/Components/Nav/ParentNav.vue'

const isCollapsed = ref(false)
const isMobileOpen = ref(false)
const isMobile = ref(false)
const user = usePage().props.auth.user

const logout = () => {
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
    if (user.profile_type === 'club_director') {
        return h(ClubDirectorNav, { isCollapsed: navCollapsed.value })
    } else if (user.profile_type === 'superadmin') {
        return h(SuperAdminNav, { isCollapsed: navCollapsed.value })
    } else if (user.profile_type === 'club_personal') {
        return h(ClubPersonalNav, { isCollapsed: navCollapsed.value })
    } else if (user.profile_type === 'parent') {
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
const mainOffsetClass = computed(() => {
    if (isMobile.value) return 'ml-0'
    return isCollapsed.value ? 'ml-20' : 'ml-64'
})
</script>
<template>
<div class="min-h-screen flex bg-gray-100 overflow-hidden">
    <!-- Sidebar -->
    <aside :class="[
    'fixed top-0 left-0 bottom-0 bg-white border-r border-gray-200 transition-all duration-300 shadow-md z-30',
    sidebarWidthClass,
    sidebarTransformClass
]" class="flex flex-col">
        <!-- Logo + Toggle -->
        <div class="flex items-center justify-between px-4 py-4 border-b">
            <img v-if="!navCollapsed" src="/images/logo-bg.png" alt="Pathfinder Club" class="h-10" />
            <button v-if="!isMobile" @click="toggleSidebar" class="text-gray-500 hover:text-red-600">
                <span v-if="isCollapsed">▶</span>
                <span v-else>◀</span>
            </button>
            <button v-else @click="closeMobileSidebar" class="text-gray-500 hover:text-red-600" aria-label="Cerrar menu">
                ✕
            </button>
        </div>

        <!-- Navigation by Role -->
        <component :is="getNavComponent()" />

        <!-- Logout -->
        <div class="px-4 py-4 border-t mt-auto">
            <button @click="logout" class="w-full text-left text-sm text-red-600 hover:underline" :class="{ 'text-center': navCollapsed }">
                <span v-if="!navCollapsed">Cerrar sesión</span>
                <span v-else>🚪</span>
            </button>
        </div>
    </aside>

    <div v-if="isMobile && isMobileOpen" class="fixed inset-0 bg-black/40 z-20" @click="closeMobileSidebar"></div>

    <!-- Main content -->
    <main :class="[mainOffsetClass]" class="flex-1 h-screen overflow-y-auto p-4 sm:p-6 md:p-8">
        <div class="max-w-5xl mx-auto">
            <div class="mb-4 flex items-center gap-3 md:hidden">
                <button @click="openMobileSidebar"
                    class="inline-flex items-center justify-center rounded-md border border-gray-200 bg-white px-3 py-2 text-sm text-gray-700 shadow-sm">
                    ☰
                </button>
                <div class="text-sm font-semibold text-gray-800">Menu</div>
            </div>
            <h1 class="text-2xl font-bold text-gray-800 mb-6">
                <slot name="title">Pathfinder Portal</slot>
            </h1>
            <slot />
        </div>
    </main>
</div>
</template>
