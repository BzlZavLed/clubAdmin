<script setup>
import PathfinderLayout from '@/Layouts/PathfinderLayout.vue'
import InputError from '@/Components/InputError.vue'
import InputLabel from '@/Components/InputLabel.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import PasswordInput from '@/Components/PasswordInput.vue'
import { router, useForm } from '@inertiajs/vue3'
import { computed, ref, watch } from 'vue'
import { useLocale } from '@/Composables/useLocale'
import axios from 'axios'

const props = defineProps({
    churches: { type: Array, default: () => [] },
    directors: { type: Array, default: () => [] },
    clubs: { type: Array, default: () => [] },
})

const editingClubId = ref(null)
const { tr } = useLocale()
const deletionClub = ref(null)
const deletionStage = ref(0)
const deletionBusy = ref(false)
const deletionError = ref('')
const deletionSummary = ref(null)
const cleanForm = ref({ current_password: '', confirmation: '' })
const deleteForm = ref({ current_password: '', confirmation: '' })

const form = useForm({
    club_name: '',
    church_id: '',
    district_id: '',
    director_user_id: '',
    status: 'inactive',
    creation_date: '',
    pastor_name: '',
    conference_name: '',
    conference_region: '',
    club_type: 'pathfinders',
    evaluation_system: 'honors',
})

const isEditing = computed(() => editingClubId.value !== null)

const resetForm = () => {
    editingClubId.value = null
    form.reset()
    form.status = 'inactive'
    form.club_type = 'pathfinders'
    form.evaluation_system = 'honors'
}

const editClub = (club) => {
    editingClubId.value = club.id
    form.club_name = club.club_name || ''
    form.church_id = club.church_id || ''
    form.district_id = club.district_id || selectedChurch.value?.district_id || ''
    form.director_user_id = club.user_id || ''
    form.status = club.status || 'inactive'
    form.creation_date = club.creation_date || ''
    form.pastor_name = club.pastor_name || ''
    form.conference_name = club.conference_name || ''
    form.conference_region = club.conference_region || ''
    form.club_type = club.club_type || 'pathfinders'
    form.evaluation_system = club.evaluation_system || 'honors'
}

const selectedChurch = computed(() =>
    props.churches.find((item) => Number(item.id) === Number(form.church_id)) || null
)

const inheritedEvaluationSystemLabel = computed(() => {
    const system = selectedChurch.value?.evaluation_system || form.evaluation_system || 'honors'
    return system === 'carpetas'
        ? tr('Carpetas', 'Carpetas')
        : tr('Honores / requisitos', 'Honors / requirements')
})

watch(
    () => form.church_id,
    () => {
        const church = selectedChurch.value
        if (!church) {
            form.district_id = ''
            form.pastor_name = ''
            form.conference_name = ''
            return
        }

        form.district_id = church.district_id || ''
        form.pastor_name = church.pastor_name || ''
        form.conference_name = church.association_name || church.conference || ''
        form.evaluation_system = church.evaluation_system || 'honors'
    }
)

watch(
    () => form.status,
    (status) => {
        if (status === 'inactive') {
            form.director_user_id = ''
        }
    }
)

watch(
    () => form.director_user_id,
    (directorUserId) => {
        if (directorUserId && form.status !== 'active') {
            form.status = 'active'
        }
    }
)

const submit = () => {
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            resetForm()
            router.reload({ only: ['clubs', 'directors'] })
        },
    }

    if (isEditing.value) {
        form.put(route('superadmin.clubs.update', editingClubId.value), options)
        return
    }

    form.post(route('superadmin.clubs.store'), options)
}

const churchNameById = (churchId) => {
    const church = props.churches.find((item) => Number(item.id) === Number(churchId))
    return church?.church_name || '-'
}

const directorLabelById = (directorId) => {
    const director = props.directors.find((item) => Number(item.id) === Number(directorId))
    return director ? `${director.name} (${director.email})` : '-'
}

const deactivateClub = (club) => {
    if (!confirm(tr(`Desactivar club "${club.club_name}"?`, `Deactivate club "${club.club_name}"?`))) return
    router.put(
        route('superadmin.clubs.deactivate', club.id),
        {},
        {
            preserveScroll: true,
            onSuccess: () => router.reload({ only: ['clubs'] }),
        }
    )
}

const openClubDeletion = (club) => {
    deletionClub.value = club
    deletionStage.value = 0
    deletionError.value = ''
    deletionSummary.value = null
    cleanForm.value = { current_password: '', confirmation: '' }
    deleteForm.value = { current_password: '', confirmation: '' }
}

const closeClubDeletion = () => {
    if (deletionBusy.value) return
    deletionClub.value = null
    deletionError.value = ''
    if (deletionSummary.value) router.reload({ only: ['clubs', 'directors'] })
}

const errorMessage = (error) => {
    const errors = error.response?.data?.errors
    return errors ? Object.values(errors).flat()[0] : (error.response?.data?.message || tr('No se pudo completar la operación.', 'The operation could not be completed.'))
}

const archiveFilename = (headers) => {
    const disposition = headers?.['content-disposition'] || ''
    const encoded = disposition.match(/filename\*=UTF-8''([^;]+)/i)
    const plain = disposition.match(/filename="?([^";]+)"?/i)
    return decodeURIComponent(encoded?.[1] || plain?.[1] || `club-${deletionClub.value.id}-financial-archive.zip`)
}

const downloadFinancialArchive = async () => {
    deletionBusy.value = true
    deletionError.value = ''
    try {
        const response = await axios.get(route('superadmin.clubs.financial-archive', deletionClub.value.id), { responseType: 'blob' })
        const url = URL.createObjectURL(response.data)
        const link = document.createElement('a')
        link.href = url
        link.download = archiveFilename(response.headers)
        document.body.appendChild(link)
        link.click()
        link.remove()
        URL.revokeObjectURL(url)
        deletionStage.value = 1
    } catch (error) {
        deletionError.value = error.response?.data instanceof Blob
            ? tr('No se pudo generar el archivo financiero.', 'The financial archive could not be generated.')
            : errorMessage(error)
    } finally {
        deletionBusy.value = false
    }
}

const cleanClubData = async () => {
    deletionBusy.value = true
    deletionError.value = ''
    try {
        const { data } = await axios.delete(route('superadmin.clubs.data.clean', deletionClub.value.id), { data: cleanForm.value })
        deletionSummary.value = data.summary
        cleanForm.value = { current_password: '', confirmation: '' }
        deletionStage.value = 2
    } catch (error) {
        deletionError.value = errorMessage(error)
    } finally {
        deletionBusy.value = false
    }
}

const permanentlyDeleteClub = async () => {
    deletionBusy.value = true
    deletionError.value = ''
    try {
        const { data } = await axios.delete(route('superadmin.clubs.delete', deletionClub.value.id), { data: deleteForm.value })
        window.location.assign(data.redirect_url)
    } catch (error) {
        deletionError.value = errorMessage(error)
    } finally {
        deletionBusy.value = false
    }
}
</script>

<template>
    <PathfinderLayout>
        <template #title>{{ tr('Superadmin: Clubes', 'Superadmin: Clubs') }}</template>

        <div class="mx-auto max-w-6xl space-y-4 px-3 sm:px-4 lg:px-0">
            <div class="rounded-lg border bg-white p-4 shadow-sm sm:p-6">
                <h2 class="text-lg font-semibold">{{ isEditing ? tr('Editar club', 'Edit club') : tr('Crear club', 'Create club') }}</h2>

                <form @submit.prevent="submit" class="mt-4 space-y-4">
                    <div>
                        <InputLabel for="church_id" :value="tr('Iglesia', 'Church')" />
                        <select id="church_id" v-model="form.church_id" class="mt-1 block w-full rounded-md border-gray-300 p-3 text-base sm:p-2 sm:text-sm" required>
                            <option disabled value="">{{ tr('Selecciona una iglesia', 'Select a church') }}</option>
                            <option v-for="church in props.churches" :key="church.id" :value="church.id">
                                {{ church.church_name }}{{ church.union_name ? ` - ${church.union_name}` : '' }}
                            </option>
                        </select>
                        <p v-if="selectedChurch?.district_id" class="mt-1 text-xs text-gray-500">
                            {{ tr('Distrito detectado:', 'Detected district:') }} {{ selectedChurch.district_id ?? '—' }}
                        </p>
                        <p v-else-if="selectedChurch" class="mt-1 text-xs" :class="selectedChurch.evaluation_system === 'carpetas' ? 'text-amber-700' : 'text-gray-500'">
                            {{
                                selectedChurch.evaluation_system === 'carpetas'
                                    ? tr('Esta iglesia no tiene distrito asignado. Carpetas requiere distrito.', 'This church has no district assigned. Carpetas requires a district.')
                                    : tr('Esta iglesia no tiene distrito asignado. Honores permite asignar director sin distrito.', 'This church has no district assigned. Honors allows assigning a director without a district.')
                            }}
                        </p>
                        <InputError class="mt-2" :message="form.errors.church_id" />
                        <InputError class="mt-2" :message="form.errors.district_id" />
                    </div>

                    <div>
                        <InputLabel for="director_user_id" :value="tr('Director (usuario)', 'Director (user)')" />
                        <select id="director_user_id" v-model="form.director_user_id" class="mt-1 block w-full rounded-md border-gray-300 p-3 text-base disabled:bg-gray-100 sm:p-2 sm:text-sm" :disabled="form.status === 'inactive'">
                            <option value="">{{ tr('Asignar despues', 'Assign later') }}</option>
                            <option v-for="director in props.directors" :key="director.id" :value="director.id">
                                {{ director.name }} ({{ director.email }})
                            </option>
                        </select>
                        <InputError class="mt-2" :message="form.errors.director_user_id" />
                        <p class="mt-1 text-xs text-gray-500">
                            {{ tr('Un club activo requiere director. Si lo marcas inactivo, el director se limpia al guardar.', 'An active club requires a director. If you mark it inactive, the director is cleared on save.') }}
                        </p>
                    </div>

                    <div>
                        <InputLabel for="status" :value="tr('Estado', 'Status')" />
                        <select id="status" v-model="form.status" class="mt-1 block w-full rounded-md border-gray-300 p-3 text-base sm:p-2 sm:text-sm" required>
                            <option value="inactive">{{ tr('Inactivo', 'Inactive') }}</option>
                            <option value="active">{{ tr('Activo', 'Active') }}</option>
                        </select>
                        <InputError class="mt-2" :message="form.errors.status" />
                    </div>

                    <div>
                        <InputLabel for="club_name" :value="tr('Nombre del club', 'Club name')" />
                        <input id="club_name" v-model="form.club_name" type="text" class="mt-1 block w-full rounded-md border-gray-300 p-3 text-base sm:p-2 sm:text-sm" required />
                        <InputError class="mt-2" :message="form.errors.club_name" />
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <InputLabel for="club_type" :value="tr('Tipo', 'Type')" />
                            <select id="club_type" v-model="form.club_type" class="mt-1 block w-full rounded-md border-gray-300 p-3 text-base sm:p-2 sm:text-sm" required>
                                <option value="adventurers">Adventurers</option>
                                <option value="pathfinders">Pathfinders</option>
                                <option value="master_guide">Master Guide</option>
                            </select>
                            <InputError class="mt-2" :message="form.errors.club_type" />
                        </div>
                        <div>
                            <InputLabel for="creation_date" :value="tr('Fecha de creacion', 'Creation date')" />
                            <input id="creation_date" v-model="form.creation_date" type="date" class="mt-1 block w-full rounded-md border-gray-300 p-3 text-base sm:p-2 sm:text-sm" />
                            <InputError class="mt-2" :message="form.errors.creation_date" />
                        </div>
                    </div>

                    <div class="rounded-md border border-blue-100 bg-blue-50 px-4 py-3 text-sm text-blue-900">
                        <div class="font-medium">{{ tr('Sistema de evaluación heredado', 'Inherited evaluation system') }}</div>
                        <div class="mt-1">{{ inheritedEvaluationSystemLabel }}</div>
                        <div v-if="selectedChurch?.union_name" class="mt-1 text-xs text-blue-700">
                            {{ tr('Definido por la unión asociada a la iglesia seleccionada.', 'Defined by the union linked to the selected church.') }}
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div>
                            <InputLabel for="pastor_name" :value="tr('Pastor', 'Pastor')" />
                            <input id="pastor_name" v-model="form.pastor_name" type="text" class="mt-1 block w-full rounded-md border-gray-300 p-3 text-base sm:p-2 sm:text-sm" />
                            <InputError class="mt-2" :message="form.errors.pastor_name" />
                        </div>
                        <div>
                            <InputLabel for="conference_name" :value="tr('Conferencia', 'Conference')" />
                            <input id="conference_name" v-model="form.conference_name" type="text" class="mt-1 block w-full rounded-md border-gray-300 p-3 text-base sm:p-2 sm:text-sm" />
                            <InputError class="mt-2" :message="form.errors.conference_name" />
                        </div>
                        <div>
                            <InputLabel for="conference_region" :value="tr('Region', 'Region')" />
                            <input id="conference_region" v-model="form.conference_region" type="text" class="mt-1 block w-full rounded-md border-gray-300 p-3 text-base sm:p-2 sm:text-sm" />
                            <InputError class="mt-2" :message="form.errors.conference_region" />
                        </div>
                    </div>

                    <div class="flex flex-col gap-2 sm:flex-row">
                        <PrimaryButton :disabled="form.processing" class="w-full justify-center rounded-md bg-red-600 px-6 py-3 text-white hover:bg-red-700 sm:w-auto sm:py-2">
                            {{ isEditing ? tr('Guardar cambios', 'Save changes') : tr('Crear club', 'Create club') }}
                        </PrimaryButton>
                        <button v-if="isEditing" type="button" @click="resetForm" class="w-full rounded border border-gray-300 px-4 py-3 text-gray-700 sm:w-auto sm:py-2">
                            {{ tr('Cancelar', 'Cancel') }}
                        </button>
                    </div>
                </form>
            </div>

            <div class="rounded-lg border bg-white p-4 shadow-sm sm:p-6">
                <h2 class="text-lg font-semibold mb-3">{{ tr('Clubes existentes', 'Existing clubs') }}</h2>
                <div v-if="props.clubs.length === 0" class="rounded border border-dashed border-gray-200 bg-gray-50 p-4 text-sm text-gray-500">
                    {{ tr('No hay clubes.', 'There are no clubs.') }}
                </div>

                <div v-else class="space-y-3 md:hidden">
                    <article v-for="club in props.clubs" :key="`mobile-${club.id}`" class="rounded-lg border border-gray-200 p-4">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <h3 class="break-words font-semibold text-gray-900">{{ club.club_name }}</h3>
                                <p class="mt-1 break-words text-sm text-gray-600">{{ churchNameById(club.church_id) }}</p>
                                <p class="mt-1 break-words text-xs text-gray-500">{{ directorLabelById(club.user_id) }}</p>
                            </div>
                            <span class="shrink-0 rounded-full bg-gray-100 px-2 py-1 text-xs font-medium text-gray-700">
                                {{ club.status || tr('activo', 'active') }}
                            </span>
                        </div>

                        <dl class="mt-3 grid grid-cols-2 gap-2 text-sm">
                            <div class="rounded bg-gray-50 p-2">
                                <dt class="text-xs text-gray-500">{{ tr('Tipo', 'Type') }}</dt>
                                <dd class="break-words font-medium text-gray-900">{{ club.club_type }}</dd>
                            </div>
                            <div class="rounded bg-gray-50 p-2">
                                <dt class="text-xs text-gray-500">{{ tr('Sistema', 'System') }}</dt>
                                <dd class="break-words font-medium text-gray-900">{{ club.evaluation_system || 'honors' }}</dd>
                            </div>
                        </dl>

                        <div class="mt-4 grid grid-cols-1 gap-2 sm:grid-cols-3">
                            <button type="button" class="rounded border border-blue-200 px-3 py-2 text-sm font-medium text-blue-700" @click="editClub(club)">{{ tr('Editar', 'Edit') }}</button>
                            <button type="button" class="rounded border border-amber-200 px-3 py-2 text-sm font-medium text-amber-700" @click="deactivateClub(club)">{{ tr('Desactivar', 'Deactivate') }}</button>
                            <button type="button" class="rounded border border-red-300 bg-red-50 px-3 py-2 text-sm font-bold text-red-800" @click="openClubDeletion(club)">{{ tr('Limpieza total', 'Full cleanup') }}</button>
                        </div>
                    </article>
                </div>

                <div v-if="props.clubs.length" class="hidden overflow-x-auto md:block">
                    <table class="min-w-full text-sm">
                        <thead class="bg-gray-50 text-gray-700">
                            <tr>
                                <th class="text-left px-3 py-2">{{ tr('Club', 'Club') }}</th>
                                <th class="text-left px-3 py-2">{{ tr('Iglesia', 'Church') }}</th>
                                <th class="text-left px-3 py-2">{{ tr('Director', 'Director') }}</th>
                                <th class="text-left px-3 py-2">{{ tr('Tipo', 'Type') }}</th>
                                <th class="text-left px-3 py-2">{{ tr('Sistema', 'System') }}</th>
                                <th class="text-left px-3 py-2">{{ tr('Estado', 'Status') }}</th>
                                <th class="text-right px-3 py-2">{{ tr('Acciones', 'Actions') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="club in props.clubs" :key="club.id" class="border-t">
                                <td class="px-3 py-2">{{ club.club_name }}</td>
                                <td class="px-3 py-2">{{ churchNameById(club.church_id) }}</td>
                                <td class="px-3 py-2">{{ directorLabelById(club.user_id) }}</td>
                                <td class="px-3 py-2">{{ club.club_type }}</td>
                                <td class="px-3 py-2">{{ club.evaluation_system || 'honors' }}</td>
                                <td class="px-3 py-2">{{ club.status || tr('activo', 'active') }}</td>
                                <td class="px-3 py-2 text-right space-x-2">
                                    <button type="button" class="text-blue-600 hover:underline" @click="editClub(club)">{{ tr('Editar', 'Edit') }}</button>
                                    <button type="button" class="text-amber-600 hover:underline" @click="deactivateClub(club)">{{ tr('Desactivar', 'Deactivate') }}</button>
                                    <button type="button" class="font-semibold text-red-700 hover:underline" @click="openClubDeletion(club)">{{ tr('Limpieza total', 'Full cleanup') }}</button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div v-if="deletionClub" class="fixed inset-0 z-50 flex items-center justify-center bg-black/70 p-4" role="dialog" aria-modal="true">
            <div class="max-h-[92vh] w-full max-w-2xl overflow-y-auto rounded-xl bg-white shadow-2xl">
                <div class="border-b border-red-200 bg-red-50 px-6 py-4">
                    <h2 class="text-xl font-bold text-red-950">{{ tr('Eliminación irreversible del club', 'Irreversible club deletion') }}</h2>
                    <p class="mt-1 text-sm font-medium text-red-800">{{ deletionClub.club_name }} · {{ deletionClub.club_type }}</p>
                </div>

                <div v-if="deletionStage === 0" class="space-y-5 p-6">
                    <div class="rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-amber-950">
                        <p class="font-bold">{{ tr('Paso obligatorio 1 de 3: conservar las finanzas', 'Required step 1 of 3: preserve finances') }}</p>
                        <p class="mt-2">{{ tr('Antes de permitir cualquier eliminación, el sistema generará un ZIP descargable con el libro contable normalizado y todos los registros financieros originales.', 'Before any deletion is allowed, the system will generate a downloadable ZIP containing the normalized ledger and every original financial record.') }}</p>
                    </div>
                    <p class="text-sm text-gray-700">{{ tr('Guarda el archivo en un lugar seguro. La autorización para continuar dura 30 minutos.', 'Store the archive securely. Authorization to continue lasts 30 minutes.') }}</p>
                    <p v-if="deletionError" class="rounded-lg bg-red-100 px-3 py-2 text-sm font-medium text-red-800">{{ deletionError }}</p>
                    <div class="flex justify-end gap-3">
                        <button type="button" class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-semibold" @click="closeClubDeletion">{{ tr('Cancelar', 'Cancel') }}</button>
                        <button type="button" :disabled="deletionBusy" class="rounded-lg bg-amber-600 px-4 py-2 text-sm font-bold text-white hover:bg-amber-700 disabled:opacity-50" @click="downloadFinancialArchive">
                            {{ deletionBusy ? tr('Generando archivo...', 'Generating archive...') : tr('Generar y descargar archivo financiero', 'Generate and download financial archive') }}
                        </button>
                    </div>
                </div>

                <form v-else-if="deletionStage === 1" class="space-y-5 p-6" @submit.prevent="cleanClubData">
                    <div class="rounded-lg border border-red-300 bg-red-50 p-4 text-sm text-red-950">
                        <p class="font-bold">{{ tr('Paso 2 de 3: borrar todos los datos del club', 'Step 2 of 3: erase all club data') }}</p>
                        <p class="mt-2">{{ tr('Esto borrará permanentemente contabilidad, pagos, recibos, gastos, campañas, miembros, expedientes, notas, firmas, archivos, personal, usuarios exclusivos, eventos, planes, reportes y datos de inscripción.', 'This permanently erases accounting, payments, receipts, expenses, fundraisers, members, records, notes, signatures, files, staff, exclusive users, events, plans, reports, and registration data.') }}</p>
                        <p class="mt-2 font-semibold">{{ tr('Las cuentas que todavía tienen relaciones con otros clubes se conservarán, pero se eliminará su relación con este club.', 'Accounts that still have relationships with other clubs are preserved, but their relationship with this club is removed.') }}</p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-800">{{ tr('Contraseña actual de superadmin', 'Current superadmin password') }}</label>
                        <PasswordInput v-model="cleanForm.current_password" autocomplete="current-password" required input-class="rounded-lg" />
                    </div>
                    <div>
                        <label for="clean-club-confirmation" class="block text-sm font-medium text-gray-800">
                            {{ tr(`Escribe DELETE CLUB DATA ${deletionClub.club_name}`, `Type DELETE CLUB DATA ${deletionClub.club_name}`) }}
                        </label>
                        <input id="clean-club-confirmation" v-model="cleanForm.confirmation" type="text" autocomplete="off" required class="mt-1 block w-full rounded-lg border-gray-300 font-mono shadow-sm focus:border-red-600 focus:ring-red-600" />
                    </div>
                    <p v-if="deletionError" class="rounded-lg bg-red-100 px-3 py-2 text-sm font-medium text-red-800">{{ deletionError }}</p>
                    <div class="flex justify-end gap-3">
                        <button type="button" class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-semibold" @click="closeClubDeletion">{{ tr('Cancelar', 'Cancel') }}</button>
                        <button type="submit" :disabled="deletionBusy || cleanForm.confirmation !== `DELETE CLUB DATA ${deletionClub.club_name}`" class="rounded-lg bg-red-700 px-4 py-2 text-sm font-bold text-white hover:bg-red-800 disabled:cursor-not-allowed disabled:opacity-40">
                            {{ deletionBusy ? tr('Borrando datos...', 'Erasing data...') : tr('Borrar todos los datos para siempre', 'Erase all data forever') }}
                        </button>
                    </div>
                </form>

                <form v-else class="space-y-5 p-6" @submit.prevent="permanentlyDeleteClub">
                    <div class="rounded-lg border border-emerald-300 bg-emerald-50 p-4 text-sm text-emerald-950">
                        <p class="font-bold">{{ tr('Los datos del club fueron eliminados.', 'The club data was erased.') }}</p>
                        <p class="mt-2">{{ tr(`Miembros: ${deletionSummary?.members_deleted || 0}; personal: ${deletionSummary?.staff_deleted || 0}; usuarios exclusivos: ${deletionSummary?.users_deleted || 0}; usuarios conservados por tener otros clubes: ${deletionSummary?.cross_club_users_preserved || 0}.`, `Members: ${deletionSummary?.members_deleted || 0}; staff: ${deletionSummary?.staff_deleted || 0}; exclusive users: ${deletionSummary?.users_deleted || 0}; users preserved because they have other clubs: ${deletionSummary?.cross_club_users_preserved || 0}.`) }}</p>
                    </div>
                    <div class="rounded-lg border border-red-300 bg-red-50 p-4 text-sm text-red-950">
                        <p class="font-bold">{{ tr('Paso final 3 de 3: eliminar el registro vacío del club', 'Final step 3 of 3: delete the empty club record') }}</p>
                        <p class="mt-2">{{ tr('Puedes cerrar esta ventana y conservar el club vacío e inactivo, o eliminarlo ahora. La eliminación no se puede revertir.', 'You may close this window and retain the empty inactive club, or delete it now. Deletion cannot be reversed.') }}</p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-800">{{ tr('Contraseña actual de superadmin', 'Current superadmin password') }}</label>
                        <PasswordInput v-model="deleteForm.current_password" autocomplete="current-password" required input-class="rounded-lg" />
                    </div>
                    <div>
                        <label for="delete-club-confirmation" class="block text-sm font-medium text-gray-800">{{ tr(`Escribe DELETE CLUB ${deletionClub.club_name}`, `Type DELETE CLUB ${deletionClub.club_name}`) }}</label>
                        <input id="delete-club-confirmation" v-model="deleteForm.confirmation" type="text" autocomplete="off" required class="mt-1 block w-full rounded-lg border-gray-300 font-mono shadow-sm focus:border-red-700 focus:ring-red-700" />
                    </div>
                    <p v-if="deletionError" class="rounded-lg bg-red-100 px-3 py-2 text-sm font-medium text-red-800">{{ deletionError }}</p>
                    <div class="flex flex-col-reverse justify-end gap-3 sm:flex-row">
                        <button type="button" class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-semibold" @click="closeClubDeletion">{{ tr('Conservar club vacío', 'Keep empty club') }}</button>
                        <button type="submit" :disabled="deletionBusy || deleteForm.confirmation !== `DELETE CLUB ${deletionClub.club_name}`" class="rounded-lg bg-red-950 px-4 py-2 text-sm font-bold text-white hover:bg-black disabled:cursor-not-allowed disabled:opacity-40">
                            {{ deletionBusy ? tr('Eliminando club...', 'Deleting club...') : tr('Eliminar club definitivamente', 'Permanently delete club') }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </PathfinderLayout>
</template>
