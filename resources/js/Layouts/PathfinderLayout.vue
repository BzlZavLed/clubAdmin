<script setup>
import { ref , h , onMounted} from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import ClubDirectorNav from '@/Components/Nav/ClubDirectorNav.vue'
import SuperAdminNav from '@/Components/Nav/SuperAdminNav.vue'
import ClubPersonalNav from '@/Components/Nav/ClubPersonalNav.vue'
import ParentNav from '@/Components/Nav/ParentNav.vue'

const isCollapsed = ref(false)
const user = usePage().props.auth.user

const logout = () => {
    router.post(route('logout'))
}
onMounted(() => {
    const savedState = localStorage.getItem('sidebar-collapsed')
    isCollapsed.value = savedState === 'true' 
})
const getNavComponent = () => {
    if (user.profile_type === 'club_director') {
        return h(ClubDirectorNav, { isCollapsed: isCollapsed.value })
    } else if (user.profile_type === 'superadmin') {
        return h(SuperAdminNav, { isCollapsed: isCollapsed.value })
    } else if (user.profile_type === 'club_personal') {
        return h(ClubPersonalNav, { isCollapsed: isCollapsed.value })
    } else if (user.profile_type === 'parent') {
        return h(ParentNav, { isCollapsed: isCollapsed.value })
    }
    return null
}
const toggleSidebar = () => {
    isCollapsed.value = !isCollapsed.value
    localStorage.setItem('sidebar-collapsed', isCollapsed.value)
}
</script>
<template>
<div class="min-h-screen flex bg-gray-100 overflow-hidden">
    <!-- Sidebar -->
    <aside :class="[
    'fixed top-0 left-0 bottom-0 bg-white border-r border-gray-200 transition-all duration-300 shadow-md',
    isCollapsed ? 'w-20' : 'w-64'
]" class="flex flex-col z-10">
        <!-- Logo + Toggle -->
        <div class="flex items-center justify-between px-4 py-4 border-b">
            <img v-if="!isCollapsed" src="/images/logo-bg.png" alt="Pathfinder Club" class="h-10" />
            <button @click="toggleSidebar" class="text-gray-500 hover:text-red-600">
                <span v-if="isCollapsed">▶</span>
                <span v-else>◀</span>
            </button>
        </div>

        <!-- Navigation by Role -->
        <component :is="getNavComponent()" />

        <!-- Logout -->
        <div class="px-4 py-4 border-t mt-auto">
            <button @click="logout" class="w-full text-left text-sm text-red-600 hover:underline" :class="{ 'text-center': isCollapsed }">
                <span v-if="!isCollapsed">Logout</span>
                <span v-else>🚪</span>
            </button>
        </div>
    </aside>

    <!-- Main content -->
    <main :class="[isCollapsed ? 'ml-20' : 'ml-64']" class="flex-1 h-screen overflow-y-auto p-8">
        <div class="max-w-5xl mx-auto">
            <h1 class="text-2xl font-bold text-gray-800 mb-6">
                <slot name="title">Pathfinder Portal</slot>
            </h1>
            <slot />
        </div>
    </main>
</div>
</template>
