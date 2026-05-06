<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import { XMarkIcon } from '@heroicons/vue/24/outline'
import PathfinderLayout from '@/Layouts/PathfinderLayout.vue'
import { useGeneral } from '@/Composables/useGeneral'
import {
    fetchClubBankInfo,
    fetchMyChurchAdminCatalog,
    removeClubLogo,
    saveMyChurchAdminConfig,
    updateClubBankInfo,
    uploadClubLogo
} from '@/Services/api'

const props = defineProps({
    auth_user: Object,
    clubs: {
        type: Array,
        default: () => []
    },
    selected_club_id: {
        type: [String, Number, null],
        default: null
    },
    integration_config: {
        type: Object,
        default: null
    },
    club_logo_url: {
        type: String,
        default: null
    }
})

const { showToast } = useGeneral()
const canSelectClub = computed(() => props.auth_user?.profile_type === 'superadmin')
const selectedClubId = ref(props.selected_club_id || props.auth_user?.club_id || (props.clubs?.[0]?.id ?? ''))
const inviteCode = ref(props.integration_config?.invite_code || '')
const catalog = ref(
    props.integration_config
        ? {
            status: props.integration_config.status,
            church: {
                id: props.integration_config.church_id,
                name: props.integration_config.church_name,
                slug: props.integration_config.church_slug,
            },
            church_slug: props.integration_config.church_slug,
            departments: props.integration_config.departments || [],
            objectives: props.integration_config.objectives || [],
        }
        : null
)
const catalogLoading = ref(false)
const saving = ref(false)
const logoUrl = ref(props.club_logo_url || null)
const logoUploading = ref(false)
const logoInput = ref(null)
const bankInfoRows = ref([])
const bankInfoForms = ref({})
const bankInfoLoading = ref(false)
const bankInfoSavingPayTo = ref(null)

const hasClubSelected = computed(() => Boolean(selectedClubId.value))

const bankInfoDefaults = {
    label: '',
    bank_name: '',
    account_holder: '',
    account_type: '',
    account_number: '',
    routing_number: '',
    zelle_email: '',
    zelle_phone: '',
    deposit_instructions: '',
    is_active: true,
    accepts_parent_deposits: true,
    accepts_event_deposits: false,
    requires_receipt_upload: true,
}

watch(selectedClubId, (val) => {
    if (!val) return
    router.get(route('club.settings'), { club_id: val }, { replace: true })
})

watch(() => props.club_logo_url, (value) => {
    logoUrl.value = value || null
})

async function loadBankInfo() {
    if (!hasClubSelected.value) {
        bankInfoRows.value = []
        bankInfoForms.value = {}
        return
    }

    bankInfoLoading.value = true
    try {
        const response = await fetchClubBankInfo(selectedClubId.value)
        const rows = Array.isArray(response?.data) ? response.data : []
        bankInfoRows.value = rows
        bankInfoForms.value = rows.reduce((forms, row) => {
            forms[row.pay_to] = {
                ...bankInfoDefaults,
                label: row.label || '',
                ...(row.bank_info || {}),
            }
            return forms
        }, {})
    } catch (error) {
        console.error(error)
        showToast(error?.response?.data?.message || 'No se pudieron cargar los datos de depósito', 'error')
    } finally {
        bankInfoLoading.value = false
    }
}

async function saveBankInfo(row) {
    if (!hasClubSelected.value || !row?.pay_to) return
    bankInfoSavingPayTo.value = row.pay_to
    try {
        await updateClubBankInfo(selectedClubId.value, row.pay_to, {
            ...(bankInfoForms.value[row.pay_to] || {}),
            accepts_parent_deposits: true,
            accepts_event_deposits: false,
        })
        await loadBankInfo()
        showToast('Datos de depósito guardados')
    } catch (error) {
        console.error(error)
        showToast(error?.response?.data?.message || 'No se pudieron guardar los datos de depósito', 'error')
    } finally {
        bankInfoSavingPayTo.value = null
    }
}

async function handleLogoSelected(event) {
    const file = event.target.files?.[0]
    if (!file || !hasClubSelected.value) return

    logoUploading.value = true
    try {
        const data = await uploadClubLogo({ clubId: selectedClubId.value, file })
        logoUrl.value = data.logo_url
        showToast('Logo del club actualizado')
    } catch (error) {
        console.error(error)
        const message = error?.response?.data?.message || 'No se pudo subir el logo'
        showToast(message, 'error')
    } finally {
        logoUploading.value = false
        if (logoInput.value) logoInput.value.value = ''
    }
}

async function deleteLogo() {
    if (!hasClubSelected.value) return
    logoUploading.value = true
    try {
        await removeClubLogo(selectedClubId.value)
        logoUrl.value = null
        showToast('Logo removido')
    } catch (error) {
        console.error(error)
        const message = error?.response?.data?.message || 'No se pudo remover el logo'
        showToast(message, 'error')
    } finally {
        logoUploading.value = false
    }
}

async function fetchCatalog() {
    if (!hasClubSelected.value) return
    if (!inviteCode.value) {
        showToast('Ingresa un codigo de invitacion primero', 'warning')
        return
    }
    catalogLoading.value = true
    try {
        const data = await fetchMyChurchAdminCatalog({
            invite_code: inviteCode.value,
            club_id: selectedClubId.value,
        })
        console.log(data);
        catalog.value = data
        showToast('Catalogo obtenido')
    } catch (error) {
        console.error(error)
        const message = error?.response?.data?.message || 'No se pudo obtener el catalogo'
        showToast(message, 'error')
    } finally {
        catalogLoading.value = false
    }
}

async function saveConfig() {
    if (!hasClubSelected.value) return
    if (!inviteCode.value) {
        showToast('El codigo de invitacion es requerido', 'warning')
        return
    }
    if (!catalog.value) {
        showToast('Obtiene el catalogo antes de guardar', 'warning')
        return
    }
    saving.value = true
    try {
        const data = await saveMyChurchAdminConfig({
            invite_code: inviteCode.value,
            club_id: selectedClubId.value,
            catalog: catalog.value,
        })
        catalog.value = {
            status: data.config.status,
            church: {
                id: data.config.church_id,
                name: data.config.church_name,
                slug: data.config.church_slug,
            },
            church_slug: data.config.church_slug,
            departments: data.config.departments || [],
            objectives: data.config.objectives || [],
        }
        showToast('Configuracion guardada')
    } catch (error) {
        console.error(error)
        const message = error?.response?.data?.message || 'No se pudo guardar la configuracion'
        showToast(message, 'error')
    } finally {
        saving.value = false
    }
}

onMounted(() => {
    loadBankInfo()
})
</script>

<template>
    <PathfinderLayout>
        <template #title>Configuracion</template>

        <div class="space-y-6">
            <div class="bg-white shadow-sm rounded-lg p-5 border space-y-4">
                <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-4">
                    <div>
                        <h2 class="text-lg font-semibold text-gray-800">Logo del club</h2>
                        <p class="text-sm text-gray-600">
                            Este logo se usará en recibos, reportes financieros y carpetas PDF del club.
                            Si varios clubes pertenecen a la misma iglesia, pueden usar el mismo archivo de logo.
                        </p>
                    </div>
                    <div class="w-full sm:w-auto">
                        <div v-if="logoUrl" class="flex items-start gap-3">
                            <img :src="logoUrl" alt="Logo del club" class="h-20 w-20 rounded border object-contain bg-white p-2" />
                            <button
                                type="button"
                                class="inline-flex h-9 w-9 items-center justify-center rounded-full border border-red-200 text-red-700 transition hover:bg-red-50 disabled:opacity-60"
                                :disabled="logoUploading || !hasClubSelected"
                                aria-label="Remover logo"
                                title="Remover logo"
                                @click="deleteLogo"
                            >
                                <XMarkIcon class="h-5 w-5" />
                            </button>
                        </div>
                        <div v-else class="h-20 w-20 rounded border border-dashed bg-gray-50 text-xs text-gray-500 flex items-center justify-center text-center p-2">
                            Sin logo
                        </div>
                    </div>
                </div>

                <div class="flex flex-col sm:flex-row gap-3 sm:items-center">
                    <input
                        ref="logoInput"
                        type="file"
                        accept="image/png,image/jpeg,image/webp"
                        class="block w-full text-sm text-gray-700 file:mr-3 file:rounded file:border-0 file:bg-blue-50 file:px-3 file:py-2 file:text-blue-700"
                        :disabled="logoUploading || !hasClubSelected"
                        @change="handleLogoSelected"
                    />
                    <span class="text-xs text-gray-500">PNG, JPG o WEBP. Máximo 4MB.</span>
                </div>
            </div>

            <div class="bg-white shadow-sm rounded-lg p-5 border space-y-4">
                <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3">
                    <div>
                        <h2 class="text-lg font-semibold text-gray-800">Datos de depósito</h2>
                        <p class="text-sm text-gray-600">Información bancaria publicada para pagos y transferencias del club.</p>
                    </div>
                    <button
                        type="button"
                        class="rounded border border-gray-300 px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-50 disabled:opacity-60"
                        :disabled="bankInfoLoading || !hasClubSelected"
                        @click="loadBankInfo"
                    >
                        {{ bankInfoLoading ? 'Cargando...' : 'Actualizar' }}
                    </button>
                </div>

                <div v-if="!bankInfoRows.length" class="rounded border border-dashed border-gray-200 bg-gray-50 p-4 text-sm text-gray-500">
                    No hay cuentas disponibles para configurar.
                </div>

                <div v-else class="space-y-4">
                    <div v-for="row in bankInfoRows" :key="row.pay_to" class="rounded-lg border border-gray-200 p-4">
                        <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                            <div>
                                <div class="font-semibold text-gray-900">{{ row.label }}</div>
                                <div class="text-xs text-gray-500">{{ row.pay_to }}</div>
                            </div>
                            <button
                                type="button"
                                class="rounded bg-blue-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-60"
                                :disabled="bankInfoSavingPayTo === row.pay_to"
                                @click="saveBankInfo(row)"
                            >
                                {{ bankInfoSavingPayTo === row.pay_to ? 'Guardando...' : 'Guardar' }}
                            </button>
                        </div>

                        <div class="mt-4 grid gap-3 md:grid-cols-2">
                            <div>
                                <label class="block text-sm text-gray-700 mb-1">Etiqueta pública</label>
                                <input v-model="bankInfoForms[row.pay_to].label" type="text" class="w-full rounded border px-3 py-2 text-sm" />
                            </div>
                            <div>
                                <label class="block text-sm text-gray-700 mb-1">Banco</label>
                                <input v-model="bankInfoForms[row.pay_to].bank_name" type="text" class="w-full rounded border px-3 py-2 text-sm" />
                            </div>
                            <div>
                                <label class="block text-sm text-gray-700 mb-1">Titular</label>
                                <input v-model="bankInfoForms[row.pay_to].account_holder" type="text" class="w-full rounded border px-3 py-2 text-sm" />
                            </div>
                            <div>
                                <label class="block text-sm text-gray-700 mb-1">Tipo de cuenta</label>
                                <input v-model="bankInfoForms[row.pay_to].account_type" type="text" class="w-full rounded border px-3 py-2 text-sm" />
                            </div>
                            <div>
                                <label class="block text-sm text-gray-700 mb-1">Número de cuenta</label>
                                <input v-model="bankInfoForms[row.pay_to].account_number" type="text" class="w-full rounded border px-3 py-2 text-sm" />
                            </div>
                            <div>
                                <label class="block text-sm text-gray-700 mb-1">Routing / ABA</label>
                                <input v-model="bankInfoForms[row.pay_to].routing_number" type="text" class="w-full rounded border px-3 py-2 text-sm" />
                            </div>
                            <div>
                                <label class="block text-sm text-gray-700 mb-1">Zelle email</label>
                                <input v-model="bankInfoForms[row.pay_to].zelle_email" type="email" class="w-full rounded border px-3 py-2 text-sm" />
                            </div>
                            <div>
                                <label class="block text-sm text-gray-700 mb-1">Zelle teléfono</label>
                                <input v-model="bankInfoForms[row.pay_to].zelle_phone" type="text" class="w-full rounded border px-3 py-2 text-sm" />
                            </div>
                            <div class="md:col-span-2">
                                <label class="block text-sm text-gray-700 mb-1">Instrucciones</label>
                                <textarea v-model="bankInfoForms[row.pay_to].deposit_instructions" rows="3" class="w-full rounded border px-3 py-2 text-sm"></textarea>
                            </div>
                        </div>

                        <div class="mt-3 grid gap-2 text-sm md:grid-cols-2">
                            <label class="inline-flex items-center gap-2 rounded border border-gray-200 px-3 py-2">
                                <input v-model="bankInfoForms[row.pay_to].is_active" type="checkbox" class="rounded border-gray-300 text-blue-600 focus:ring-blue-500" />
                                <span>Activo</span>
                            </label>
                            <label class="inline-flex items-center gap-2 rounded border border-gray-200 px-3 py-2">
                                <input v-model="bankInfoForms[row.pay_to].requires_receipt_upload" type="checkbox" class="rounded border-gray-300 text-blue-600 focus:ring-blue-500" />
                                <span>Comprobante</span>
                            </label>
                        </div>
                    </div>
                </div>
            </div>

            <div class="bg-white shadow-sm rounded-lg p-5 border space-y-4">
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                    <div>
                        <h2 class="text-lg font-semibold text-gray-800">Integracion con mychurchadmin.net</h2>
                        <p class="text-sm text-gray-600">Usa un codigo de invitacion para obtener el catalogo y guardarlo para tu club.</p>
                    </div>
                    <div v-if="canSelectClub" class="flex items-center gap-2">
                        <label class="text-sm text-gray-700">Club</label>
                        <select v-model="selectedClubId" class="border rounded px-3 py-1 text-sm">
                            <option value="">Selecciona un club</option>
                            <option v-for="club in clubs" :key="club.id" :value="club.id">{{ club.club_name }}</option>
                        </select>
                    </div>
                    <div v-else class="text-sm text-gray-700">
                        Club activo: <strong>{{ clubs.find(club => String(club.id) === String(selectedClubId))?.club_name || props.auth_user?.club_name || '—' }}</strong>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                    <div class="md:col-span-2">
                        <label class="block text-sm text-gray-700 mb-1">Codigo de invitacion</label>
                        <input
                            v-model="inviteCode"
                            type="text"
                            class="w-full border rounded px-3 py-2 text-sm"
                            placeholder="ABC123"
                        />
                    </div>
                    <div class="flex items-end gap-2">
                        <button
                            class="px-4 py-2 bg-blue-600 text-white rounded text-sm disabled:opacity-60"
                            :disabled="catalogLoading || !hasClubSelected"
                            @click="fetchCatalog"
                            type="button"
                        >
                            {{ catalogLoading ? 'Obteniendo...' : 'Obtener' }}
                        </button>
                        <button
                            class="px-4 py-2 bg-emerald-600 text-white rounded text-sm disabled:opacity-60"
                            :disabled="saving || !hasClubSelected"
                            @click="saveConfig"
                            type="button"
                        >
                            {{ saving ? 'Guardando...' : 'Guardar configuracion' }}
                        </button>
                    </div>
                </div>
            </div>

            <div v-if="catalog" class="bg-white shadow-sm rounded-lg p-5 border space-y-6">
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                    <div>
                        <h3 class="font-semibold text-gray-800">Detalles del catalogo</h3>
                        <p class="text-sm text-gray-600">Revisa la iglesia, departamentos y objetivos.</p>
                    </div>
                    <div class="text-sm text-gray-600">
                        Estado: <span class="font-semibold">{{ catalog.status || '—' }}</span>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="border rounded p-3 bg-gray-50">
                        <h4 class="text-sm font-semibold text-gray-800 mb-2">Iglesia</h4>
                        <div class="text-sm text-gray-700 space-y-1">
                            <div><span class="font-medium">Nombre:</span> {{ catalog.church?.name || '—' }}</div>
                            <div><span class="font-medium">Slug:</span> {{ catalog.church_slug || catalog.church?.slug || '—' }}</div>
                            <div><span class="font-medium">ID:</span> {{ catalog.church?.id || '—' }}</div>
                        </div>
                    </div>
                    <div class="border rounded p-3 bg-gray-50">
                        <h4 class="text-sm font-semibold text-gray-800 mb-2">Resumen</h4>
                        <div class="text-sm text-gray-700 space-y-1">
                            <div><span class="font-medium">Departamentos:</span> {{ catalog.departments?.length || 0 }}</div>
                            <div><span class="font-medium">Objetivos:</span> {{ catalog.objectives?.length || 0 }}</div>
                        </div>
                    </div>
                </div>

                <div>
                    <h4 class="text-sm font-semibold text-gray-800 mb-2">Departamentos</h4>
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead class="text-left text-gray-500">
                                <tr>
                                    <th class="py-2 pr-4">ID</th>
                                    <th class="py-2 pr-4">Nombre</th>
                                    <th class="py-2 pr-4">Usuario</th>
                                    <th class="py-2 pr-4">Color</th>
                                    <th class="py-2 pr-4">Es club</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="dept in catalog.departments || []" :key="`dept-${dept.id}`" class="border-t">
                                    <td class="py-2 pr-4">{{ dept.id }}</td>
                                    <td class="py-2 pr-4">{{ dept.name }}</td>
                                    <td class="py-2 pr-4">{{ dept.user_name }}</td>
                                    <td class="py-2 pr-4">{{ dept.color }}</td>
                                    <td class="py-2 pr-4">{{ dept.is_club ? 'Si' : 'No' }}</td>
                                </tr>
                                <tr v-if="(catalog.departments || []).length === 0">
                                    <td colspan="5" class="py-3 text-center text-gray-500">No hay departamentos disponibles.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div>
                    <h4 class="text-sm font-semibold text-gray-800 mb-2">Objetivos</h4>
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead class="text-left text-gray-500">
                                <tr>
                                    <th class="py-2 pr-4">ID</th>
                                    <th class="py-2 pr-4">Departamento</th>
                                    <th class="py-2 pr-4">Nombre</th>
                                    <th class="py-2 pr-4">Descripcion</th>
                                    <th class="py-2 pr-4">Metricas</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="obj in catalog.objectives || []" :key="`obj-${obj.id}`" class="border-t">
                                    <td class="py-2 pr-4">{{ obj.id }}</td>
                                    <td class="py-2 pr-4">{{ obj.department_id }}</td>
                                    <td class="py-2 pr-4">{{ obj.name }}</td>
                                    <td class="py-2 pr-4">{{ obj.description }}</td>
                                    <td class="py-2 pr-4">{{ obj.evaluation_metrics }}</td>
                                </tr>
                                <tr v-if="(catalog.objectives || []).length === 0">
                                    <td colspan="5" class="py-3 text-center text-gray-500">No hay objetivos disponibles.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div v-else class="bg-white shadow-sm rounded-lg p-5 border text-sm text-gray-600">
                Obtiene un catalogo para ver los detalles de integracion.
            </div>
        </div>
    </PathfinderLayout>
</template>
