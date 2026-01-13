<script setup>
import PathfinderLayout from '@/Layouts/PathfinderLayout.vue'
</script>

<template>
  <PathfinderLayout>
    <template #title>Super Admin Dashboard</template>

    <div class="space-y-4 text-gray-800">
      <div class="bg-white border rounded-lg shadow-sm p-4">
        <p class="text-lg font-semibold">Welcome, Super Admin</p>
        <p class="text-sm text-gray-600">Manage churches and invite codes from the Create Church page.</p>
      </div>
    </div>
  </PathfinderLayout>
</template>
