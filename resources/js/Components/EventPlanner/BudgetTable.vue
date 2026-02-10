<script setup>
const props = defineProps({
    items: {
        type: Array,
        default: () => []
    }
})

const formatCurrency = (value) => {
    const number = Number(value || 0)
    return new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' }).format(number)
}
</script>

<template>
    <div class="overflow-x-auto bg-white rounded-lg border">
        <table class="min-w-full text-sm">
            <thead class="bg-gray-50 text-gray-600">
                <tr>
                    <th class="text-left px-4 py-2">Category</th>
                    <th class="text-left px-4 py-2">Description</th>
                    <th class="text-right px-4 py-2">Qty</th>
                    <th class="text-right px-4 py-2">Unit Cost</th>
                    <th class="text-right px-4 py-2">Total</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="item in items" :key="item.id" class="border-t">
                    <td class="px-4 py-2">{{ item.category }}</td>
                    <td class="px-4 py-2">{{ item.description }}</td>
                    <td class="px-4 py-2 text-right">{{ item.qty }}</td>
                    <td class="px-4 py-2 text-right">{{ formatCurrency(item.unit_cost) }}</td>
                    <td class="px-4 py-2 text-right">{{ formatCurrency(item.total) }}</td>
                </tr>
                <tr v-if="!items.length">
                    <td colspan="5" class="px-4 py-6 text-center text-gray-500">No budget items yet.</td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
