<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import axios from 'axios'
import { XMarkIcon } from '@heroicons/vue/24/outline'
import PathfinderLayout from '@/Layouts/PathfinderLayout.vue'
import { useGeneral } from '@/Composables/useGeneral'
import { useLocale } from '@/Composables/useLocale'
import {
    fetchClubBankInfo,
    fetchMyChurchAdminCatalog,
    removeClubLogo,
    saveMyChurchAdminConfig,
    updateClubBankInfo,
    updateClubContact,
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
    },
    selected_club: {
        type: Object,
        default: null
    },
    enrollment_session: {
        type: Object,
        default: null
    }
})

const { showToast } = useGeneral()
const { tr } = useLocale()
const canSelectClub = computed(() => props.auth_user?.profile_type === 'superadmin')
const selectedClubId = ref(props.selected_club_id || props.auth_user?.club_id || (props.clubs?.[0]?.id ?? ''))
const selectedChurchId = ref(props.clubs?.find((club) => String(club.id) === String(selectedClubId.value))?.church_id || '')
const availableChurches = computed(() => {
    const seen = new Set()
    return props.clubs.filter((club) => {
        if (!club.church_id || seen.has(String(club.church_id))) return false
        seen.add(String(club.church_id))
        return true
    })
})
const clubsForSelectedChurch = computed(() => props.clubs.filter((club) => String(club.church_id) === String(selectedChurchId.value)))
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
const hasUploadedLogo = ref(Boolean(props.selected_club?.logo_path))
const logoUploading = ref(false)
const logoInput = ref(null)
const clubEmail = ref(props.selected_club?.club_email || '')
const contactSaving = ref(false)
const bankInfoRows = ref([])
const bankInfoForms = ref({})
const bankInfoLoading = ref(false)
const bankInfoSavingPayTo = ref(null)
const enrollmentSession = ref(props.enrollment_session || null)
const enrollmentRefreshing = ref(false)
const enrollmentActionId = ref(null)
const secureEnrollmentLinkBusy = ref(false)
const secureQrCopying = ref(false)
const enrollmentPanel = ref(null)
let enrollmentPollingTimer = null

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

watch(selectedChurchId, (churchId) => {
    if (!canSelectClub.value || !churchId) return
    const firstClub = clubsForSelectedChurch.value[0]
    if (firstClub && String(firstClub.id) !== String(selectedClubId.value)) {
        selectedClubId.value = firstClub.id
    }
})

watch(() => props.club_logo_url, (value) => {
    logoUrl.value = value || null
})

watch(() => props.selected_club, (value) => {
    clubEmail.value = value?.club_email || ''
    hasUploadedLogo.value = Boolean(value?.logo_path)
})

watch(() => props.enrollment_session, (value) => {
    enrollmentSession.value = value || null
})

async function refreshEnrollmentSession() {
    if (!hasClubSelected.value) return
    enrollmentRefreshing.value = true
    try {
        const { data } = await axios.get(route('club.settings.enrollment-session'), {
            params: { club_id: selectedClubId.value },
        })
        enrollmentSession.value = data.data
    } catch (error) {
        console.error('Could not refresh enrollment session', error)
    } finally {
        enrollmentRefreshing.value = false
    }
}

async function updateEnrollmentParent(parent, action) {
    if (!hasClubSelected.value || !parent?.id) return
    enrollmentActionId.value = parent.id
    try {
        const routeName = action === 'approve'
            ? 'club.settings.enrollment.parents.approve'
            : 'club.settings.enrollment.parents.reject'
        const { data } = await axios.post(route(routeName, parent.id), {
            club_id: selectedClubId.value,
        })
        enrollmentSession.value = data.data
        showToast(action === 'approve'
            ? tr('Solicitud aprobada', 'Request approved')
            : tr('Solicitud rechazada', 'Request rejected'))
    } catch (error) {
        console.error('Could not update enrollment request', error)
        showToast(error?.response?.data?.message || tr('No se pudo actualizar la solicitud', 'Could not update the request'), 'error')
    } finally {
        enrollmentActionId.value = null
    }
}

async function copyEnrollmentUrl() {
    if (!enrollmentSession.value?.registration_url) return
    try {
        await navigator.clipboard.writeText(enrollmentSession.value.registration_url)
        showToast(tr('Enlace copiado', 'Link copied'))
    } catch (error) {
        showToast(tr('No se pudo copiar el enlace', 'Could not copy the link'), 'error')
    }
}

async function regenerateSecureEnrollmentLink() {
    if (!hasClubSelected.value) return
    secureEnrollmentLinkBusy.value = true
    try {
        const { data } = await axios.post(route('club.settings.enrollment.secure-link.regenerate'), {
            club_id: selectedClubId.value,
        })
        enrollmentSession.value = {
            ...(enrollmentSession.value || {}),
            secure_parent_enrollment: data.data,
        }
        showToast(tr('Enlace seguro creado', 'Secure link created'))
    } catch (error) {
        showToast(error?.response?.data?.message || tr('No se pudo crear el enlace seguro', 'Could not create the secure link'), 'error')
    } finally {
        secureEnrollmentLinkBusy.value = false
    }
}

async function copySecureEnrollmentUrl() {
    const url = enrollmentSession.value?.secure_parent_enrollment?.url
    if (!url) return
    try {
        await navigator.clipboard.writeText(url)
        showToast(tr('Enlace seguro copiado', 'Secure link copied'))
    } catch (error) {
        showToast(tr('No se pudo copiar el enlace', 'Could not copy the link'), 'error')
    }
}

async function copySecureEnrollmentQr() {
    const qrUrl = enrollmentSession.value?.secure_parent_enrollment?.qr_url
    if (!qrUrl) return
    secureQrCopying.value = true
    try {
        if (!navigator.clipboard?.write || typeof ClipboardItem === 'undefined') {
            throw new Error('image-clipboard-unavailable')
        }
        const response = await fetch(qrUrl, { credentials: 'same-origin' })
        if (!response.ok) throw new Error('qr-fetch-failed')
        const blob = await response.blob()
        await navigator.clipboard.write([new ClipboardItem({ 'image/png': blob })])
        showToast(tr('Imagen QR copiada', 'QR image copied'))
    } catch (error) {
        showToast(tr('Este navegador no permitió copiar la imagen. Usa Descargar QR.', 'This browser did not allow copying the image. Use Download QR.'), 'warning')
    } finally {
        secureQrCopying.value = false
    }
}

async function revokeSecureEnrollmentLink() {
    if (!hasClubSelected.value || !enrollmentSession.value?.secure_parent_enrollment) return
    secureEnrollmentLinkBusy.value = true
    try {
        await axios.delete(route('club.settings.enrollment.secure-link.revoke'), {
            data: { club_id: selectedClubId.value },
        })
        enrollmentSession.value = {
            ...enrollmentSession.value,
            secure_parent_enrollment: null,
        }
        showToast(tr('Enlace seguro desactivado', 'Secure link deactivated'))
    } catch (error) {
        showToast(error?.response?.data?.message || tr('No se pudo desactivar el enlace', 'Could not deactivate the link'), 'error')
    } finally {
        secureEnrollmentLinkBusy.value = false
    }
}

function openEnrollmentFullscreen() {
    enrollmentPanel.value?.requestFullscreen?.()
}

async function saveContact() {
    if (!hasClubSelected.value) return
    contactSaving.value = true
    try {
        const data = await updateClubContact({
            club_id: selectedClubId.value,
            club_email: clubEmail.value || null,
        })
        clubEmail.value = data.club?.club_email || ''
        showToast(tr('Correo del club guardado', 'Club email saved'))
    } catch (error) {
        console.error(error)
        const message = error?.response?.data?.message || tr('No se pudo guardar el correo del club', 'Could not save the club email')
        showToast(message, 'error')
    } finally {
        contactSaving.value = false
    }
}

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
        showToast(error?.response?.data?.message || tr('No se pudieron cargar los datos de depósito', 'Could not load deposit information'), 'error')
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
        showToast(tr('Datos de depósito guardados', 'Deposit information saved'))
    } catch (error) {
        console.error(error)
        showToast(error?.response?.data?.message || tr('No se pudieron guardar los datos de depósito', 'Could not save deposit information'), 'error')
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
        hasUploadedLogo.value = true
        showToast(tr('Logo del club actualizado', 'Club logo updated'))
    } catch (error) {
        console.error(error)
        const message = error?.response?.data?.message || tr('No se pudo subir el logo', 'Could not upload the logo')
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
        const data = await removeClubLogo(selectedClubId.value)
        logoUrl.value = data.logo_url
        hasUploadedLogo.value = false
        showToast(tr('Logo removido', 'Logo removed'))
    } catch (error) {
        console.error(error)
        const message = error?.response?.data?.message || tr('No se pudo remover el logo', 'Could not remove the logo')
        showToast(message, 'error')
    } finally {
        logoUploading.value = false
    }
}

async function fetchCatalog() {
    if (!hasClubSelected.value) return
    if (!inviteCode.value) {
        showToast(tr('Ingresa un codigo de invitacion primero', 'Enter an invitation code first'), 'warning')
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
        showToast(tr('Catalogo obtenido', 'Catalog retrieved'))
    } catch (error) {
        console.error(error)
        const message = error?.response?.data?.message || tr('No se pudo obtener el catalogo', 'Could not retrieve the catalog')
        showToast(message, 'error')
    } finally {
        catalogLoading.value = false
    }
}

async function saveConfig() {
    if (!hasClubSelected.value) return
    if (!inviteCode.value) {
        showToast(tr('El codigo de invitacion es requerido', 'The invitation code is required'), 'warning')
        return
    }
    if (!catalog.value) {
        showToast(tr('Obtiene el catalogo antes de guardar', 'Retrieve the catalog before saving'), 'warning')
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
        showToast(tr('Configuracion guardada', 'Configuration saved'))
    } catch (error) {
        console.error(error)
        const message = error?.response?.data?.message || tr('No se pudo guardar la configuracion', 'Could not save the configuration')
        showToast(message, 'error')
    } finally {
        saving.value = false
    }
}

onMounted(() => {
    loadBankInfo()
})

onBeforeUnmount(() => {
    if (enrollmentPollingTimer) window.clearInterval(enrollmentPollingTimer)
})
</script>

<template>
    <PathfinderLayout>
        <template #title>{{ tr('Configuracion', 'Settings') }}</template>

        <div class="space-y-6">
            <div v-if="canSelectClub" class="flex flex-col gap-3 rounded-lg border bg-white p-4 shadow-sm sm:flex-row sm:items-end">
                <div class="flex-1">
                    <label class="mb-1 block text-sm font-medium text-gray-700">{{ tr('Iglesia', 'Church') }}</label>
                    <select v-model="selectedChurchId" class="w-full rounded border px-3 py-2 text-sm">
                        <option v-for="church in availableChurches" :key="church.church_id" :value="church.church_id">{{ church.church_name || `${tr('Iglesia', 'Church')} #${church.church_id}` }}</option>
                    </select>
                </div>
                <div class="flex-1">
                    <label class="mb-1 block text-sm font-medium text-gray-700">{{ tr('Club', 'Club') }}</label>
                    <select v-model="selectedClubId" class="w-full rounded border px-3 py-2 text-sm">
                        <option v-for="club in clubsForSelectedChurch" :key="club.id" :value="club.id">{{ club.club_name }}</option>
                    </select>
                </div>
            </div>
            <div class="flex flex-col gap-3 rounded-lg border bg-white p-5 shadow-sm sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h2 class="text-lg font-semibold text-gray-800">{{ tr('Sesión de inscripciones', 'Enrollment session') }}</h2>
                    <p class="text-sm text-gray-600">{{ tr('Abre la pantalla de proyección con el QR, código de iglesia y solicitudes en vivo.', 'Open the projection screen with the QR code, church code, and live requests.') }}</p>
                </div>
                <div class="flex flex-col gap-2 sm:flex-row">
                    <Link :href="route('club.settings.enrollment.display', { club_id: selectedClubId })" class="rounded bg-slate-900 px-4 py-2 text-center text-sm font-medium text-white hover:bg-slate-800">
                        {{ tr('Abrir sesión', 'Open session') }}
                    </Link>
                    <button
                        type="button"
                        class="rounded bg-emerald-700 px-4 py-2 text-center text-sm font-medium text-white hover:bg-emerald-800 disabled:opacity-60"
                        :disabled="secureEnrollmentLinkBusy || !hasClubSelected"
                        @click="regenerateSecureEnrollmentLink"
                    >
                        {{ enrollmentSession?.secure_parent_enrollment ? tr('Regenerar enlace seguro', 'Regenerate secure link') : tr('Crear enlace seguro', 'Create secure link') }}
                    </button>
                </div>
            </div>
            <div v-if="enrollmentSession?.secure_parent_enrollment" class="rounded-lg border border-emerald-200 bg-emerald-50 p-5 shadow-sm">
                <div class="grid gap-5 lg:grid-cols-[220px_minmax(0,1fr)]">
                    <div class="rounded-xl border border-emerald-200 bg-white p-3">
                        <img
                            :src="enrollmentSession.secure_parent_enrollment.qr_url"
                            :alt="tr('QR del enlace seguro de inscripción', 'Secure enrollment link QR code')"
                            class="aspect-square w-full object-contain"
                        />
                        <p class="mt-2 text-center text-xs text-gray-600">{{ tr('Listo para proyectar o publicar', 'Ready to project or publish') }}</p>
                    </div>
                    <div class="min-w-0">
                        <h3 class="font-semibold text-emerald-950">{{ tr('Inscripción autónoma por enlace seguro', 'Self-service enrollment through secure link') }}</h3>
                        <p class="mt-1 text-sm text-emerald-900">
                            {{ tr('Este enlace fija el club, omite el código de invitación y permite acceso inmediato. Las cuentas y miembros creados aparecerán en el panel del director para confirmación.', 'This link fixes the club, skips the invitation code, and permits immediate access. Accounts and members created through it will appear on the director dashboard for confirmation.') }}
                        </p>
                        <p class="mt-3 break-all rounded border border-emerald-200 bg-white p-3 font-mono text-xs text-gray-700">{{ enrollmentSession.secure_parent_enrollment.url }}</p>
                        <div class="mt-4 flex flex-wrap gap-2">
                            <button type="button" class="rounded bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800" @click="copySecureEnrollmentUrl">
                                {{ tr('Copiar enlace', 'Copy link') }}
                            </button>
                            <button type="button" class="rounded bg-blue-700 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-800 disabled:opacity-60" :disabled="secureQrCopying" @click="copySecureEnrollmentQr">
                                {{ secureQrCopying ? tr('Copiando...', 'Copying...') : tr('Copiar imagen QR', 'Copy QR image') }}
                            </button>
                            <a :href="enrollmentSession.secure_parent_enrollment.qr_download_url" class="rounded border border-blue-300 bg-white px-4 py-2 text-sm font-semibold text-blue-700 hover:bg-blue-50">
                                {{ tr('Descargar QR', 'Download QR') }}
                            </a>
                            <button type="button" class="rounded border border-red-300 bg-white px-4 py-2 text-sm font-semibold text-red-700 hover:bg-red-50" :disabled="secureEnrollmentLinkBusy" @click="revokeSecureEnrollmentLink">
                                {{ tr('Desactivar', 'Deactivate') }}
                            </button>
                        </div>
                    </div>
                </div>
            </div>
            <template v-if="false">
            <section ref="enrollmentPanel" class="bg-slate-950 p-5 text-white shadow-sm sm:p-8">
                <div class="mx-auto max-w-5xl space-y-6">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                        <div>
                            <h2 class="text-2xl font-bold">{{ tr('Sesión de inscripciones', 'Enrollment session') }}</h2>
                            <p class="mt-1 text-sm text-slate-300">{{ tr('Proyecta esta pantalla; las solicitudes y las inscripciones se actualizan automáticamente.', 'Project this screen; requests and enrollments update automatically.') }}</p>
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="text-xs text-emerald-300">{{ enrollmentRefreshing ? tr('Actualizando...', 'Refreshing...') : tr('En vivo · cada 5 segundos', 'Live · every 5 seconds') }}</span>
                            <button type="button" class="rounded border border-slate-500 px-3 py-1.5 text-sm hover:bg-slate-800" @click="openEnrollmentFullscreen">
                                {{ tr('Pantalla completa', 'Full screen') }}
                            </button>
                        </div>
                    </div>

                    <div v-if="!enrollmentSession" class="rounded border border-dashed border-slate-600 p-5 text-slate-300">
                        {{ tr('Selecciona un club para iniciar la sesión de inscripciones.', 'Select a club to start the enrollment session.') }}
                    </div>

                    <template v-else>
                        <div class="grid items-center gap-6 rounded-xl bg-white p-5 text-slate-900 md:grid-cols-[minmax(0,1fr)_300px] md:p-7">
                            <div>
                                <p class="text-sm font-medium uppercase tracking-wide text-slate-500">{{ enrollmentSession.club?.club_name }}</p>
                                <h3 class="mt-2 text-2xl font-bold">{{ tr('Escanea para registrar a un padre o madre', 'Scan to register a parent') }}</h3>
                                <p class="mt-3 break-all text-sm text-slate-600">{{ enrollmentSession.registration_url }}</p>
                                <button type="button" class="mt-4 rounded bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700" @click="copyEnrollmentUrl">
                                    {{ tr('Copiar enlace', 'Copy link') }}
                                </button>
                            </div>
                            <img :src="enrollmentSession.qr_url" :alt="tr('Código QR de inscripción', 'Enrollment QR code')" class="mx-auto w-full max-w-[280px] rounded bg-white p-2" />
                        </div>

                        <div class="rounded-xl border border-amber-300 bg-amber-50 p-5 text-center text-slate-900">
                            <p class="text-sm font-semibold uppercase tracking-wide text-amber-800">{{ tr('Código de invitación de la iglesia', 'Church invitation code') }}</p>
                            <p class="mt-2 break-all font-mono text-3xl font-bold tracking-[0.2em] sm:text-5xl">{{ enrollmentSession.church_invite_code }}</p>
                        </div>

                        <div class="rounded-xl bg-white p-5 text-slate-900 md:p-6">
                            <div class="flex items-center justify-between gap-3">
                                <div>
                                    <h3 class="text-lg font-bold">{{ tr('Solicitudes de padres', 'Parent requests') }}</h3>
                                    <p class="text-sm text-slate-500">{{ tr('Aprueba o rechaza cada cuenta al llegar.', 'Approve or reject each account as it arrives.') }}</p>
                                </div>
                                <span class="rounded-full bg-amber-100 px-3 py-1 text-sm font-semibold text-amber-800">{{ enrollmentSession.pending_parents?.length || 0 }}</span>
                            </div>
                            <div class="mt-4 overflow-x-auto">
                                <table class="min-w-full text-sm">
                                    <thead class="border-b text-left text-slate-500">
                                        <tr><th class="px-2 py-2">{{ tr('Padre/Madre', 'Parent') }}</th><th class="px-2 py-2">{{ tr('Correo', 'Email') }}</th><th class="px-2 py-2 text-right">{{ tr('Acciones', 'Actions') }}</th></tr>
                                    </thead>
                                    <tbody>
                                        <tr v-for="parent in enrollmentSession.pending_parents" :key="parent.id" class="border-b last:border-0">
                                            <td class="px-2 py-3 font-medium">{{ parent.name }}</td>
                                            <td class="px-2 py-3 text-slate-600">{{ parent.email }}</td>
                                            <td class="px-2 py-3 text-right whitespace-nowrap">
                                                <button type="button" class="mr-2 rounded bg-emerald-600 px-3 py-1.5 font-medium text-white disabled:opacity-60" :disabled="enrollmentActionId === parent.id" @click="updateEnrollmentParent(parent, 'approve')">{{ tr('Aprobar', 'Approve') }}</button>
                                                <button type="button" class="rounded border border-red-300 px-3 py-1.5 font-medium text-red-700 disabled:opacity-60" :disabled="enrollmentActionId === parent.id" @click="updateEnrollmentParent(parent, 'reject')">{{ tr('Rechazar', 'Reject') }}</button>
                                            </td>
                                        </tr>
                                        <tr v-if="!enrollmentSession.pending_parents?.length"><td colspan="3" class="px-2 py-5 text-center text-slate-500">{{ tr('Aún no hay solicitudes pendientes.', 'There are no pending requests yet.') }}</td></tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <div class="rounded-xl bg-white p-5 text-slate-900 md:p-6">
                            <h3 class="text-lg font-bold">{{ tr('Inscripciones completadas', 'Completed enrollments') }}</h3>
                            <p class="text-sm text-slate-500">{{ tr('Cada padre aprobado aparece con los hijos registrados en este club.', 'Each approved parent appears with children registered in this club.') }}</p>
                            <div v-if="!enrollmentSession.enrolled_parents?.length" class="mt-4 rounded border border-dashed p-4 text-sm text-slate-500">{{ tr('Aún no hay hijos registrados.', 'There are no registered children yet.') }}</div>
                            <div v-else class="mt-4 space-y-3">
                                <div v-for="parent in enrollmentSession.enrolled_parents" :key="parent.id" class="rounded border border-slate-200 p-4">
                                    <div class="font-semibold">{{ parent.name }} <span class="ml-2 font-normal text-slate-500">{{ parent.email }}</span></div>
                                    <div class="mt-3 space-y-2 border-l-2 border-blue-200 pl-4">
                                        <div v-for="child in parent.children" :key="child.id" class="text-sm">
                                            <span class="font-medium">{{ child.name }}</span>
                                            <span class="text-slate-500"> · {{ child.club_name || enrollmentSession.club?.club_name }} · {{ child.class_name || tr('Sin clase asignada', 'No class assigned') }}</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </template>
                </div>
            </section>
            </template>

            <div class="bg-white shadow-sm rounded-lg p-5 border space-y-4">
                <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-4">
                    <div>
                        <h2 class="text-lg font-semibold text-gray-800">{{ tr('Logo del club', 'Club Logo') }}</h2>
                        <p class="text-sm text-gray-600">
                            {{ tr('Este logo se usará en recibos, reportes financieros y carpetas PDF del club. Si no subes una imagen, se genera un avatar con las iniciales del club.', 'This logo will be used on receipts, financial reports, and club PDF folders. If you do not upload an image, an avatar is generated from the club initials.') }}
                        </p>
                    </div>
                    <div class="w-full sm:w-auto">
                        <div v-if="logoUrl" class="flex items-start gap-3">
                            <img :src="logoUrl" :alt="tr('Logo del club', 'Club logo')" class="h-20 w-20 rounded border object-contain bg-white p-2" />
                            <button
                                v-if="hasUploadedLogo"
                                type="button"
                                class="inline-flex h-9 w-9 items-center justify-center rounded-full border border-red-200 text-red-700 transition hover:bg-red-50 disabled:opacity-60"
                                :disabled="logoUploading || !hasClubSelected"
                                :aria-label="tr('Remover logo', 'Remove logo')"
                                :title="tr('Remover logo', 'Remove logo')"
                                @click="deleteLogo"
                            >
                                <XMarkIcon class="h-5 w-5" />
                            </button>
                        </div>
                        <div v-else class="h-20 w-20 rounded border border-dashed bg-gray-50 text-xs text-gray-500 flex items-center justify-center text-center p-2">
                            {{ tr('Sin logo', 'No logo') }}
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
                    <span class="text-xs text-gray-500">{{ tr('PNG, JPG o WEBP. Máximo 4MB.', 'PNG, JPG, or WEBP. Maximum 4MB.') }}</span>
                </div>
            </div>

            <div class="bg-white shadow-sm rounded-lg p-5 border space-y-4">
                <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3">
                    <div>
                        <h2 class="text-lg font-semibold text-gray-800">{{ tr('Correo de pagos del club', 'Club Payment Email') }}</h2>
                        <p class="text-sm text-gray-600">{{ tr('Los comprobantes enviados por padres se remiten a este correo para revisión del club.', 'Payment proofs submitted by parents are forwarded to this email for club review.') }}</p>
                    </div>
                    <button
                        type="button"
                        class="rounded bg-blue-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-60"
                        :disabled="contactSaving || !hasClubSelected"
                        @click="saveContact"
                    >
                        {{ contactSaving ? tr('Guardando...', 'Saving...') : tr('Guardar', 'Save') }}
                    </button>
                </div>

                <div>
                    <label class="block text-sm text-gray-700 mb-1">{{ tr('Correo receptor de comprobantes', 'Payment proof recipient email') }}</label>
                    <input
                        v-model="clubEmail"
                        type="email"
                        class="w-full rounded border px-3 py-2 text-sm"
                        placeholder="tesoreria@club.org"
                        :disabled="!hasClubSelected"
                    />
                </div>
            </div>

            <div class="bg-white shadow-sm rounded-lg p-5 border space-y-4">
                <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3">
                    <div>
                        <h2 class="text-lg font-semibold text-gray-800">{{ tr('Datos de depósito', 'Deposit Information') }}</h2>
                        <p class="text-sm text-gray-600">{{ tr('Información bancaria publicada para pagos y transferencias del club.', 'Banking information published for club payments and transfers.') }}</p>
                    </div>
                    <button
                        type="button"
                        class="rounded border border-gray-300 px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-50 disabled:opacity-60"
                        :disabled="bankInfoLoading || !hasClubSelected"
                        @click="loadBankInfo"
                    >
                        {{ bankInfoLoading ? tr('Cargando...', 'Loading...') : tr('Actualizar', 'Refresh') }}
                    </button>
                </div>

                <div v-if="!bankInfoRows.length" class="rounded border border-dashed border-gray-200 bg-gray-50 p-4 text-sm text-gray-500">
                    {{ tr('No hay cuentas disponibles para configurar.', 'No accounts are available to configure.') }}
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
                                {{ bankInfoSavingPayTo === row.pay_to ? tr('Guardando...', 'Saving...') : tr('Guardar', 'Save') }}
                            </button>
                        </div>

                        <div class="mt-4 grid gap-3 md:grid-cols-2">
                            <div>
                                <label class="block text-sm text-gray-700 mb-1">{{ tr('Etiqueta pública', 'Public label') }}</label>
                                <input v-model="bankInfoForms[row.pay_to].label" type="text" class="w-full rounded border px-3 py-2 text-sm" />
                            </div>
                            <div>
                                <label class="block text-sm text-gray-700 mb-1">{{ tr('Banco', 'Bank') }}</label>
                                <input v-model="bankInfoForms[row.pay_to].bank_name" type="text" class="w-full rounded border px-3 py-2 text-sm" />
                            </div>
                            <div>
                                <label class="block text-sm text-gray-700 mb-1">{{ tr('Titular', 'Account holder') }}</label>
                                <input v-model="bankInfoForms[row.pay_to].account_holder" type="text" class="w-full rounded border px-3 py-2 text-sm" />
                            </div>
                            <div>
                                <label class="block text-sm text-gray-700 mb-1">{{ tr('Tipo de cuenta', 'Account type') }}</label>
                                <input v-model="bankInfoForms[row.pay_to].account_type" type="text" class="w-full rounded border px-3 py-2 text-sm" />
                            </div>
                            <div>
                                <label class="block text-sm text-gray-700 mb-1">{{ tr('Número de cuenta', 'Account number') }}</label>
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
                                <label class="block text-sm text-gray-700 mb-1">{{ tr('Zelle teléfono', 'Zelle phone') }}</label>
                                <input v-model="bankInfoForms[row.pay_to].zelle_phone" type="text" class="w-full rounded border px-3 py-2 text-sm" />
                            </div>
                            <div class="md:col-span-2">
                                <label class="block text-sm text-gray-700 mb-1">{{ tr('Instrucciones', 'Instructions') }}</label>
                                <textarea v-model="bankInfoForms[row.pay_to].deposit_instructions" rows="3" class="w-full rounded border px-3 py-2 text-sm"></textarea>
                            </div>
                        </div>

                        <div class="mt-3 grid gap-2 text-sm md:grid-cols-2">
                            <label class="inline-flex items-center gap-2 rounded border border-gray-200 px-3 py-2">
                                <input v-model="bankInfoForms[row.pay_to].is_active" type="checkbox" class="rounded border-gray-300 text-blue-600 focus:ring-blue-500" />
                                <span>{{ tr('Activo', 'Active') }}</span>
                            </label>
                            <label class="inline-flex items-center gap-2 rounded border border-gray-200 px-3 py-2">
                                <input v-model="bankInfoForms[row.pay_to].requires_receipt_upload" type="checkbox" class="rounded border-gray-300 text-blue-600 focus:ring-blue-500" />
                                <span>{{ tr('Comprobante', 'Receipt') }}</span>
                            </label>
                        </div>
                    </div>
                </div>
            </div>

            <div class="bg-white shadow-sm rounded-lg p-5 border space-y-4">
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                    <div>
                        <h2 class="text-lg font-semibold text-gray-800">{{ tr('Integracion con mychurchadmin.net', 'mychurchadmin.net Integration') }}</h2>
                        <p class="text-sm text-gray-600">{{ tr('Usa un codigo de invitacion para obtener el catalogo y guardarlo para tu club.', 'Use an invitation code to retrieve the catalog and save it for your club.') }}</p>
                    </div>
                    <div class="text-sm text-gray-700">
                        {{ tr('Club activo:', 'Active club:') }} <strong>{{ clubs.find(club => String(club.id) === String(selectedClubId))?.club_name || props.auth_user?.club_name || '—' }}</strong>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                    <div class="md:col-span-2">
                        <label class="block text-sm text-gray-700 mb-1">{{ tr('Codigo de invitacion', 'Invitation code') }}</label>
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
                            {{ catalogLoading ? tr('Obteniendo...', 'Retrieving...') : tr('Obtener', 'Retrieve') }}
                        </button>
                        <button
                            class="px-4 py-2 bg-emerald-600 text-white rounded text-sm disabled:opacity-60"
                            :disabled="saving || !hasClubSelected"
                            @click="saveConfig"
                            type="button"
                        >
                            {{ saving ? tr('Guardando...', 'Saving...') : tr('Guardar configuracion', 'Save configuration') }}
                        </button>
                    </div>
                </div>
            </div>

            <div v-if="catalog" class="bg-white shadow-sm rounded-lg p-5 border space-y-6">
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                    <div>
                        <h3 class="font-semibold text-gray-800">{{ tr('Detalles del catalogo', 'Catalog Details') }}</h3>
                        <p class="text-sm text-gray-600">{{ tr('Revisa la iglesia, departamentos y objetivos.', 'Review the church, departments, and objectives.') }}</p>
                    </div>
                    <div class="text-sm text-gray-600">
                        {{ tr('Estado:', 'Status:') }} <span class="font-semibold">{{ catalog.status || '—' }}</span>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="border rounded p-3 bg-gray-50">
                        <h4 class="text-sm font-semibold text-gray-800 mb-2">{{ tr('Iglesia', 'Church') }}</h4>
                        <div class="text-sm text-gray-700 space-y-1">
                            <div><span class="font-medium">{{ tr('Nombre:', 'Name:') }}</span> {{ catalog.church?.name || '—' }}</div>
                            <div><span class="font-medium">Slug:</span> {{ catalog.church_slug || catalog.church?.slug || '—' }}</div>
                            <div><span class="font-medium">ID:</span> {{ catalog.church?.id || '—' }}</div>
                        </div>
                    </div>
                    <div class="border rounded p-3 bg-gray-50">
                        <h4 class="text-sm font-semibold text-gray-800 mb-2">{{ tr('Resumen', 'Summary') }}</h4>
                        <div class="text-sm text-gray-700 space-y-1">
                            <div><span class="font-medium">{{ tr('Departamentos:', 'Departments:') }}</span> {{ catalog.departments?.length || 0 }}</div>
                            <div><span class="font-medium">{{ tr('Objetivos:', 'Objectives:') }}</span> {{ catalog.objectives?.length || 0 }}</div>
                        </div>
                    </div>
                </div>

                <div>
                    <h4 class="text-sm font-semibold text-gray-800 mb-2">{{ tr('Departamentos', 'Departments') }}</h4>
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead class="text-left text-gray-500">
                                <tr>
                                    <th class="py-2 pr-4">ID</th>
                                    <th class="py-2 pr-4">{{ tr('Nombre', 'Name') }}</th>
                                    <th class="py-2 pr-4">{{ tr('Usuario', 'User') }}</th>
                                    <th class="py-2 pr-4">Color</th>
                                    <th class="py-2 pr-4">{{ tr('Es club', 'Is club') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="dept in catalog.departments || []" :key="`dept-${dept.id}`" class="border-t">
                                    <td class="py-2 pr-4">{{ dept.id }}</td>
                                    <td class="py-2 pr-4">{{ dept.name }}</td>
                                    <td class="py-2 pr-4">{{ dept.user_name }}</td>
                                    <td class="py-2 pr-4">{{ dept.color }}</td>
                                    <td class="py-2 pr-4">{{ dept.is_club ? tr('Si', 'Yes') : tr('No', 'No') }}</td>
                                </tr>
                                <tr v-if="(catalog.departments || []).length === 0">
                                    <td colspan="5" class="py-3 text-center text-gray-500">{{ tr('No hay departamentos disponibles.', 'No departments available.') }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div>
                    <h4 class="text-sm font-semibold text-gray-800 mb-2">{{ tr('Objetivos', 'Objectives') }}</h4>
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead class="text-left text-gray-500">
                                <tr>
                                    <th class="py-2 pr-4">ID</th>
                                    <th class="py-2 pr-4">{{ tr('Departamento', 'Department') }}</th>
                                    <th class="py-2 pr-4">{{ tr('Nombre', 'Name') }}</th>
                                    <th class="py-2 pr-4">{{ tr('Descripcion', 'Description') }}</th>
                                    <th class="py-2 pr-4">{{ tr('Metricas', 'Metrics') }}</th>
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
                                    <td colspan="5" class="py-3 text-center text-gray-500">{{ tr('No hay objetivos disponibles.', 'No objectives available.') }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div v-else class="bg-white shadow-sm rounded-lg p-5 border text-sm text-gray-600">
                {{ tr('Obtiene un catalogo para ver los detalles de integracion.', 'Retrieve a catalog to see integration details.') }}
            </div>
        </div>
    </PathfinderLayout>
</template>
