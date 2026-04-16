<script setup>
import { ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import PathfinderLayout from '@/Layouts/PathfinderLayout.vue'
import InputError from '@/Components/InputError.vue'
import InputLabel from '@/Components/InputLabel.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import { useGeneral } from '@/Composables/useGeneral'
import { useLocale } from '@/Composables/useLocale'

const props = defineProps({
    union: { type: Object, required: true },
    clubCatalogs: { type: Array, default: () => [] },
})

const { tr } = useLocale()
const { showToast } = useGeneral()

const clubTypeForm = useForm({
    name: '',
    sort_order: '',
})

const classForms = ref({})

const getClassForm = (clubCatalogId) => {
    const key = String(clubCatalogId)
    if (!classForms.value[key]) {
        classForms.value[key] = useForm({
            name: '',
            sort_order: '',
        })
    }
    return classForms.value[key]
}

const submitClubType = () => {
    clubTypeForm.post(route('union.catalog.club-types.store'), {
        preserveScroll: true,
        onSuccess: () => {
            showToast(tr('Tipo de club creado.', 'Club type created.'), 'success')
            clubTypeForm.reset()
            router.reload({ only: ['clubCatalogs'] })
        },
    })
}

const submitClass = (clubCatalogId) => {
    const form = getClassForm(clubCatalogId)
    form.post(route('union.catalog.classes.store', clubCatalogId), {
        preserveScroll: true,
        onSuccess: () => {
            showToast(tr('Clase creada.', 'Class created.'), 'success')
            form.reset()
            router.reload({ only: ['clubCatalogs'] })
        },
    })
}
</script>

<template>
    <PathfinderLayout>
        <template #title>{{ tr('Catálogo de clubes y clases', 'Club and class catalog') }}</template>

        <div class="max-w-5xl mx-auto space-y-6">
            <section class="rounded-lg border bg-white p-6 shadow-sm space-y-4">
                <div>
                    <h2 class="text-lg font-semibold text-gray-900">{{ union?.name || tr('Unión', 'Union') }}</h2>
                    <p class="mt-1 text-sm text-gray-600">
                        {{ tr('Este catálogo funciona como la guía de referencia de tipos de club y clases que existen dentro de la unión. Los clubes reales podrán usar una parte de este catálogo, no necesariamente todo.', 'This catalog works as the reference guide of club types and classes that exist inside the union. Real clubs may use only part of this catalog, not necessarily all of it.') }}
                    </p>
                </div>

                <form class="grid gap-4 md:grid-cols-[minmax(0,1fr)_180px_auto] md:items-end" @submit.prevent="submitClubType">
                    <div>
                        <InputLabel for="club_type_name" :value="tr('Tipo de club', 'Club type')" />
                        <input id="club_type_name" v-model="clubTypeForm.name" type="text" class="mt-1 block w-full rounded-md border-gray-300" required />
                        <InputError class="mt-2" :message="clubTypeForm.errors.name" />
                    </div>

                    <div>
                        <InputLabel for="club_type_order" :value="tr('Orden', 'Order')" />
                        <input id="club_type_order" v-model="clubTypeForm.sort_order" type="number" min="1" class="mt-1 block w-full rounded-md border-gray-300" />
                        <InputError class="mt-2" :message="clubTypeForm.errors.sort_order" />
                    </div>

                    <PrimaryButton :disabled="clubTypeForm.processing" class="justify-self-start bg-red-600 hover:bg-red-700 text-white px-6 py-2 rounded-md">
                        {{ tr('Agregar tipo', 'Add type') }}
                    </PrimaryButton>
                </form>
            </section>

            <section class="space-y-4">
                <article v-for="clubCatalog in props.clubCatalogs" :key="clubCatalog.id" class="rounded-lg border bg-white p-6 shadow-sm">
                    <div class="flex flex-col gap-2 md:flex-row md:items-start md:justify-between">
                        <div>
                            <h3 class="text-base font-semibold text-gray-900">{{ clubCatalog.name }}</h3>
                            <p class="mt-1 text-sm text-gray-600">
                                {{ tr('Clases de referencia', 'Reference classes') }}: {{ (clubCatalog.class_catalogs || []).length }}
                            </p>
                        </div>
                        <div class="text-sm text-gray-500">
                            {{ tr('Orden', 'Order') }}: {{ clubCatalog.sort_order }}
                        </div>
                    </div>

                    <form class="mt-4 grid gap-4 md:grid-cols-[minmax(0,1fr)_180px_auto] md:items-end" @submit.prevent="submitClass(clubCatalog.id)">
                        <div>
                            <InputLabel :for="`class_name_${clubCatalog.id}`" :value="tr('Clase', 'Class')" />
                            <input :id="`class_name_${clubCatalog.id}`" v-model="getClassForm(clubCatalog.id).name" type="text" class="mt-1 block w-full rounded-md border-gray-300" required />
                            <InputError class="mt-2" :message="getClassForm(clubCatalog.id).errors.name" />
                        </div>

                        <div>
                            <InputLabel :for="`class_order_${clubCatalog.id}`" :value="tr('Orden', 'Order')" />
                            <input :id="`class_order_${clubCatalog.id}`" v-model="getClassForm(clubCatalog.id).sort_order" type="number" min="1" class="mt-1 block w-full rounded-md border-gray-300" />
                            <InputError class="mt-2" :message="getClassForm(clubCatalog.id).errors.sort_order" />
                        </div>

                        <PrimaryButton :disabled="getClassForm(clubCatalog.id).processing" class="justify-self-start bg-gray-800 hover:bg-gray-900 text-white px-6 py-2 rounded-md">
                            {{ tr('Agregar clase', 'Add class') }}
                        </PrimaryButton>
                    </form>

                    <div class="mt-5 overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead>
                                <tr class="border-b text-left text-gray-500">
                                    <th class="pb-2 pr-4 font-medium">{{ tr('Clase', 'Class') }}</th>
                                    <th class="pb-2 pr-4 font-medium">{{ tr('Orden', 'Order') }}</th>
                                    <th class="pb-2 font-medium">{{ tr('Estado', 'Status') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-if="!(clubCatalog.class_catalogs || []).length">
                                    <td colspan="3" class="py-3 text-gray-500">{{ tr('Todavía no hay clases cargadas para este tipo de club.', 'There are no classes loaded for this club type yet.') }}</td>
                                </tr>
                                <tr v-for="classCatalog in (clubCatalog.class_catalogs || [])" :key="classCatalog.id" class="border-b last:border-b-0">
                                    <td class="py-2 pr-4 text-gray-900">{{ classCatalog.name }}</td>
                                    <td class="py-2 pr-4 text-gray-700">{{ classCatalog.sort_order }}</td>
                                    <td class="py-2 text-gray-700">{{ classCatalog.status }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </article>

                <section v-if="!props.clubCatalogs.length" class="rounded-lg border bg-white p-6 text-sm text-gray-500 shadow-sm">
                    {{ tr('Todavía no hay tipos de club definidos en este catálogo.', 'There are no club types defined in this catalog yet.') }}
                </section>
            </section>
        </div>
    </PathfinderLayout>
</template>
