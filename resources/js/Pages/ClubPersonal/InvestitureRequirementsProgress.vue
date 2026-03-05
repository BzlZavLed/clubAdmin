<script setup>
import PathfinderLayout from '@/Layouts/PathfinderLayout.vue'
import { computed, reactive } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import { useGeneral } from '@/Composables/useGeneral'
import { useLocale } from '@/Composables/useLocale'
import { addInvestitureRequirementCompletion } from '@/Services/api'

const page = usePage()
const { showToast, showError } = useGeneral()
const { tr } = useLocale()

const assignedClass = computed(() => page.props.assigned_class || null)
const staff = computed(() => page.props.staff || null)
const club = computed(() => page.props.club || null)
const membersCount = computed(() => Number(page.props.members_count || 0))
const members = computed(() => Array.isArray(page.props.members) ? page.props.members : [])
const requirements = computed(() => Array.isArray(page.props.requirements) ? page.props.requirements : [])
const completionDrafts = reactive({})

const getDraft = (requirementId) => {
    const key = String(requirementId)
    if (!completionDrafts[key]) {
        completionDrafts[key] = {
            member_id: '',
            class_plan_id: '',
            saving: false,
        }
    }
    return completionDrafts[key]
}

const saveCompletion = async (requirement) => {
    const draft = getDraft(requirement.id)
    if (!draft.member_id || !draft.class_plan_id) {
        showToast(tr('Selecciona miembro y actividad para registrar el cumplimiento.', 'Select member and linked activity to register completion.'), 'warning')
        return
    }

    draft.saving = true
    try {
        await addInvestitureRequirementCompletion({
            requirement_id: requirement.id,
            member_id: Number(draft.member_id),
            class_plan_id: Number(draft.class_plan_id),
        })
        showToast(tr('Cumplimiento registrado correctamente.', 'Requirement completion recorded successfully.'), 'success')
        draft.member_id = ''
        draft.class_plan_id = ''
        router.reload({ preserveScroll: true })
    } catch (error) {
        showError(error, tr('No se pudo registrar el cumplimiento.', 'Could not record completion.'))
    } finally {
        draft.saving = false
    }
}

const formatDate = (value) => {
    if (!value) return tr('Sin fecha', 'No date')
    const date = new Date(value)
    if (Number.isNaN(date.getTime())) return value
    return date.toLocaleDateString(undefined, { year: 'numeric', month: '2-digit', day: '2-digit' })
}
</script>

<template>
    <PathfinderLayout>
        <template #title>{{ tr('Requisitos de investidura por clase', 'Class Investiture Requirements') }}</template>

        <div class="space-y-4">
            <section class="bg-white rounded-lg shadow p-4 border border-gray-100">
                <div class="flex justify-end mb-3">
                    <a
                        :href="route('club.personal.investiture-requirements.pdf')"
                        class="inline-flex items-center rounded-md bg-red-600 px-3 py-2 text-sm font-medium text-white hover:bg-red-700"
                    >
                        {{ tr('Exportar PDF', 'Export PDF') }}
                    </a>
                </div>
                <div class="grid gap-2 sm:grid-cols-2 text-sm">
                    <div><span class="font-semibold">{{ tr('Clase', 'Class') }}:</span> {{ assignedClass?.name || '—' }}</div>
                    <div><span class="font-semibold">{{ tr('Orden', 'Order') }}:</span> {{ assignedClass?.order ?? '—' }}</div>
                    <div><span class="font-semibold">Staff:</span> {{ staff?.name || '—' }}</div>
                    <div><span class="font-semibold">Club:</span> {{ club?.club_name || '—' }}</div>
                    <div><span class="font-semibold">{{ tr('Miembros en clase', 'Class members') }}:</span> {{ membersCount }}</div>
                    <div><span class="font-semibold">{{ tr('Total requisitos', 'Total requirements') }}:</span> {{ requirements.length }}</div>
                </div>
            </section>

            <section v-if="!requirements.length" class="bg-white rounded-lg shadow p-4 border border-gray-100 text-sm text-gray-600">
                {{ tr('No hay requisitos de investidura configurados para esta clase.', 'No investiture requirements are configured for this class.') }}
            </section>

            <section
                v-for="requirement in requirements"
                :key="requirement.id"
                class="bg-white rounded-lg shadow border border-gray-100 overflow-hidden"
            >
                <div class="px-4 py-3 border-b bg-gray-50">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <h2 class="font-semibold text-gray-900">
                                {{ requirement.sort_order ? `${requirement.sort_order}. ` : '' }}{{ requirement.title }}
                            </h2>
                            <p v-if="requirement.description" class="text-sm text-gray-600 mt-1">{{ requirement.description }}</p>
                        </div>
                        <div class="text-xs text-right">
                            <div class="font-semibold text-green-700">{{ requirement.completed_count || 0 }} {{ tr('completados', 'completed') }}</div>
                            <div class="text-gray-500">{{ tr('de', 'of') }} {{ membersCount }} {{ tr('miembros', 'members') }}</div>
                        </div>
                    </div>
                </div>

                <div class="p-4">
                    <div class="mb-4 p-3 border rounded bg-blue-50">
                        <p class="text-sm font-semibold text-gray-800 mb-2">{{ tr('Registrar cumplimiento manual', 'Register manual completion') }}</p>
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-2">
                            <select
                                v-model="getDraft(requirement.id).member_id"
                                class="border rounded px-2 py-2 text-sm bg-white"
                            >
                                <option value="">{{ tr('Selecciona miembro', 'Select member') }}</option>
                                <option v-for="member in members" :key="`member-opt-${member.id}`" :value="member.id">
                                    {{ member.name }}
                                </option>
                            </select>

                            <select
                                v-model="getDraft(requirement.id).class_plan_id"
                                class="border rounded px-2 py-2 text-sm bg-white"
                            >
                                <option value="">{{ tr('Selecciona actividad vinculada', 'Select linked activity') }}</option>
                                <option
                                    v-for="activity in (requirement.activities || [])"
                                    :key="`activity-opt-${activity.id}`"
                                    :value="activity.id"
                                    :disabled="!activity.has_report"
                                >
                                    {{ activity.title }} - {{ formatDate(activity.meeting_date) }}{{ activity.has_report ? '' : ' (Sin reporte de asistencia)' }}
                                </option>
                            </select>

                            <button
                                type="button"
                                class="px-3 py-2 rounded text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 disabled:opacity-60"
                                :disabled="getDraft(requirement.id).saving || !(requirement.activities || []).length"
                                @click="saveCompletion(requirement)"
                            >
                                {{ getDraft(requirement.id).saving ? tr('Guardando...', 'Saving...') : tr('Agregar cumplimiento', 'Add completion') }}
                            </button>
                        </div>
                        <p v-if="!(requirement.activities || []).length" class="text-xs text-gray-500 mt-2">
                            {{ tr('No hay actividades con reporte de asistencia disponible para este requisito.', 'There are no activities with attendance report available for this requirement.') }}
                        </p>
                    </div>

                    <div v-if="!(requirement.completions || []).length" class="text-sm text-gray-500">
                        {{ tr('Aún no hay miembros con este requisito cumplido.', 'There are no members with this requirement completed yet.') }}
                    </div>
                    <ul v-else class="space-y-2">
                        <li
                            v-for="entry in requirement.completions"
                            :key="`${requirement.id}-${entry.member_id}`"
                            class="text-sm border border-gray-100 rounded px-3 py-2 bg-white"
                        >
                            <span class="font-medium text-gray-900">{{ entry.member_name }}</span>
                            <span class="text-gray-600"> - {{ tr('requisito cumplido el', 'requirement completed on') }} {{ formatDate(entry.date) }}</span>
                            <span v-if="entry.activity_title" class="text-gray-500"> ({{ entry.activity_title }})</span>
                        </li>
                    </ul>
                </div>
            </section>
        </div>
    </PathfinderLayout>
</template>
