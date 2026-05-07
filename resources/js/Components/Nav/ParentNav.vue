<script setup>
import { Link } from '@inertiajs/vue3'
import { useLocale } from '@/Composables/useLocale'
import { computed } from 'vue'
import {
    HomeIcon,
    UserGroupIcon,
    ClipboardDocumentListIcon,
    FolderOpenIcon,
    CreditCardIcon,
} from '@heroicons/vue/24/outline'

defineProps({
    isCollapsed: Boolean,
})

const { t } = useLocale()

const menuItems = computed(() => [
    { name: t('dashboard'), href: '/parent/dashboard', route: 'parent.dashboard', icon: HomeIcon },
    { name: t('application'), href: '/parent/apply', route: 'parent.apply', icon: ClipboardDocumentListIcon },
    { name: t('children'), href: '/parent/children', route: 'parent-links.index.parent', icon: UserGroupIcon },
    { name: t('investiture_folder'), href: '/parent/carpeta-investidura', route: 'parent.carpeta-investidura', icon: FolderOpenIcon },
    { name: t('payments'), href: '/parent/payments', route: 'parent.payments.index', icon: CreditCardIcon },
])
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
