<script setup>
import { Link, usePage } from '@inertiajs/vue3'
import { useLocale } from '@/Composables/useLocale'
import {
    HomeIcon,
    UserGroupIcon,
    ClipboardDocumentListIcon,
    FolderOpenIcon,
} from '@heroicons/vue/24/outline'

defineProps({
    isCollapsed: Boolean,
})

const { tr } = useLocale()

const menuItems = [
    { name: tr('Panel', 'Dashboard'), href: '/parent/dashboard', route: 'parent.dashboard', icon: HomeIcon },
    { name: tr('Solicitud', 'Application'), href: '/parent/apply', route: 'parent.apply', icon: ClipboardDocumentListIcon },
    { name: tr('Hijos', 'Children'), href: '/parent/children', route: 'parent-links.index.parent', icon: UserGroupIcon },
    { name: tr('Carpeta de investidura', 'Investiture folder'), href: '/parent/carpeta-investidura', route: 'parent.carpeta-investidura', icon: FolderOpenIcon },
]
</script>

<template>
    <nav class="flex-1 px-2 py-4 space-y-1">
        <Link
            v-for="item in menuItems"
            :key="item.name"
            :href="item.href"
            class="flex items-center space-x-2 px-3 py-2 rounded hover:bg-gray-100"
            :class="route().current(item.route) ? 'bg-yellow-100 text-red-700 font-semibold' : 'text-gray-700'"
        >
            <component :is="item.icon" class="w-5 h-5" />
            <span v-if="!isCollapsed" class="text-sm truncate">{{ item.name }}</span>
        </Link>
    </nav>
</template>
