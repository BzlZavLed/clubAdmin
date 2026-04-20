<script setup>
import PathfinderLayout from '@/Layouts/PathfinderLayout.vue'
import InputError from '@/Components/InputError.vue'
import InputLabel from '@/Components/InputLabel.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import { computed } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { useLocale } from '@/Composables/useLocale'

const props = defineProps({
    association: { type: Object, required: true },
    union: { type: Object, required: true },
    clubs: { type: Array, default: () => [] },
    carpeta_year: { type: Object, default: null },
    club_requirement_map: { type: Array, default: () => [] },
    honor_sessions: { type: Array, default: () => [] },
})

const { tr } = useLocale()
const isCarpetas = computed(() => (props.union?.evaluation_system || 'honors') === 'carpetas')

const requirementMapByClub = computed(() =>
    Object.fromEntries((props.club_requirement_map || []).map((entry) => [String(entry.club_id), entry.class_groups || []]))
)

const sessionForm = useForm({
    club_type: 'pathfinders',
    class_name: '',
    title: '',
    session_date: '',
    location: '',
    notes: '',
    status: 'planned',
})

const submitSession = () => {
    sessionForm.post(route('association.programs.honor-sessions.store'), {
        preserveScroll: true,
        onSuccess: () => sessionForm.reset('class_name', 'title', 'session_date', 'location', 'notes'),
    })
}

const removeSession = (session) => {
    if (!confirm(tr(`Eliminar jornada "${session.title}"?`, `Delete session "${session.title}"?`))) return
    router.delete(route('association.programs.honor-sessions.destroy', session.id), {
        preserveScroll: true,
    })
}
</script>

<template>
    <PathfinderLayout>
        <template #title>
            {{ isCarpetas ? tr('Asociación: Requisitos de carpeta', 'Association: Carpeta requirements') : tr('Asociación: Planificación de clases', 'Association: Class planning') }}
        </template>

        <div class="space-y-6">
            <div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
                <h2 class="text-lg font-semibold text-gray-900">{{ association.name }}</h2>
                <p class="mt-2 text-sm text-gray-600">
                    {{ tr('Unión', 'Union') }}: {{ union.name || '—' }} |
                    {{ tr('Sistema de evaluación', 'Evaluation system') }}: {{ union.evaluation_system || 'honors' }}
                </p>
            </div>

            <div v-if="isCarpetas" class="space-y-6">
                <div class="rounded-2xl border border-blue-200 bg-blue-50 p-6 shadow-sm">
                    <h3 class="text-base font-semibold text-blue-900">
                        {{ tr('Ciclo de carpeta activo', 'Active carpeta cycle') }}
                    </h3>
                    <p class="mt-2 text-sm text-blue-800">
                        <span v-if="carpeta_year">
                            {{ tr('Año', 'Year') }} {{ carpeta_year.year }} |
                            {{ tr('Estado', 'Status') }}: {{ carpeta_year.status }}
                        </span>
                        <span v-else>
                            {{ tr('La unión todavía no ha publicado o creado un ciclo de carpeta disponible.', 'The union has not yet published or created an available carpeta cycle.') }}
                        </span>
                    </p>
                </div>

                <div class="grid gap-4 xl:grid-cols-2">
                    <article
                        v-for="club in clubs"
                        :key="club.id"
                        class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm"
                    >
                        <div class="mb-4">
                            <h3 class="text-base font-semibold text-gray-900">{{ club.club_name }}</h3>
                            <p class="mt-0.5 text-xs font-medium uppercase tracking-wide text-gray-400">{{ club.club_type }}</p>
                        </div>

                        <div class="space-y-3">
                            <div
                                v-for="group in requirementMapByClub[String(club.id)] || []"
                                :key="`${club.id}-${group.class_name}`"
                                class="rounded-xl border border-gray-100 bg-gray-50 p-4"
                            >
                                <h4 class="text-sm font-medium text-gray-800">{{ group.class_name }}</h4>
                                <ul class="mt-2 space-y-2">
                                    <li
                                        v-for="requirement in group.requirements"
                                        :key="requirement.id"
                                        class="rounded-lg bg-white p-3"
                                    >
                                        <p class="font-medium text-gray-900">{{ requirement.title }}</p>
                                        <p v-if="requirement.description" class="mt-0.5 text-sm text-gray-600">{{ requirement.description }}</p>
                                        <p class="mt-1 text-xs uppercase tracking-wide text-gray-400">
                                            {{ requirement.requirement_type }} · {{ requirement.validation_mode }}
                                        </p>
                                    </li>
                                </ul>
                            </div>

                            <p
                                v-if="!(requirementMapByClub[String(club.id)] || []).length"
                                class="rounded-xl border border-dashed border-gray-200 p-4 text-sm text-gray-400"
                            >
                                {{ tr('Sin requisitos de carpeta para este tipo de club.', 'No carpeta requirements for this club type.') }}
                            </p>
                        </div>
                    </article>
                </div>
            </div>

            <div v-else class="space-y-6">
                <div class="rounded-2xl border border-amber-200 bg-amber-50 p-6 shadow-sm">
                    <h3 class="text-base font-semibold text-amber-900">{{ tr('Planificación de clases de honores', 'Honor class planning') }}</h3>
                    <p class="mt-2 text-sm text-amber-800">
                        {{ tr('Programa jornadas de clases para que los clubes vean la oferta de la asociación y decidan en cuáles inscribirse.', 'Plan class sessions so clubs can see the association offer and decide which ones to join.') }}
                    </p>
                </div>

                <div class="grid gap-6 lg:grid-cols-[360px_minmax(0,1fr)]">
                    <div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
                        <h3 class="text-base font-semibold text-gray-900">{{ tr('Nueva jornada', 'New session') }}</h3>
                        <form class="mt-4 space-y-4" @submit.prevent="submitSession">
                            <div>
                                <InputLabel for="club_type" :value="tr('Tipo de club', 'Club type')" />
                                <select id="club_type" v-model="sessionForm.club_type" class="mt-1 block w-full rounded-md border-gray-300">
                                    <option value="adventurers">Adventurers</option>
                                    <option value="pathfinders">Pathfinders</option>
                                    <option value="master_guide">Master Guide</option>
                                </select>
                                <InputError class="mt-2" :message="sessionForm.errors.club_type" />
                            </div>
                            <div>
                                <InputLabel for="class_name" :value="tr('Clase / honor', 'Class / honor')" />
                                <input id="class_name" v-model="sessionForm.class_name" type="text" class="mt-1 block w-full rounded-md border-gray-300" />
                                <InputError class="mt-2" :message="sessionForm.errors.class_name" />
                            </div>
                            <div>
                                <InputLabel for="title" :value="tr('Título', 'Title')" />
                                <input id="title" v-model="sessionForm.title" type="text" class="mt-1 block w-full rounded-md border-gray-300" />
                                <InputError class="mt-2" :message="sessionForm.errors.title" />
                            </div>
                            <div>
                                <InputLabel for="session_date" :value="tr('Fecha', 'Date')" />
                                <input id="session_date" v-model="sessionForm.session_date" type="date" class="mt-1 block w-full rounded-md border-gray-300" />
                                <InputError class="mt-2" :message="sessionForm.errors.session_date" />
                            </div>
                            <div>
                                <InputLabel for="location" :value="tr('Lugar', 'Location')" />
                                <input id="location" v-model="sessionForm.location" type="text" class="mt-1 block w-full rounded-md border-gray-300" />
                                <InputError class="mt-2" :message="sessionForm.errors.location" />
                            </div>
                            <div>
                                <InputLabel for="notes" :value="tr('Notas', 'Notes')" />
                                <textarea id="notes" v-model="sessionForm.notes" class="mt-1 block w-full rounded-md border-gray-300"></textarea>
                                <InputError class="mt-2" :message="sessionForm.errors.notes" />
                            </div>
                            <PrimaryButton :disabled="sessionForm.processing">
                                {{ tr('Guardar jornada', 'Save session') }}
                            </PrimaryButton>
                        </form>
                    </div>

                    <div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
                        <h3 class="text-base font-semibold text-gray-900">{{ tr('Jornadas planificadas', 'Planned sessions') }}</h3>
                        <div class="mt-4 space-y-3">
                            <article
                                v-for="session in honor_sessions"
                                :key="session.id"
                                class="rounded-xl border border-gray-200 bg-gray-50 p-4"
                            >
                                <div class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
                                    <div>
                                        <h4 class="font-medium text-gray-900">{{ session.title }}</h4>
                                        <p class="text-sm text-gray-600">{{ session.class_name }} | {{ session.club_type }}</p>
                                        <p class="mt-1 text-sm text-gray-600">{{ session.session_date }} · {{ session.location || tr('Lugar por definir', 'Location TBD') }}</p>
                                        <p v-if="session.notes" class="mt-2 text-sm text-gray-600">{{ session.notes }}</p>
                                    </div>
                                    <div class="flex items-center gap-3">
                                        <span class="inline-flex rounded-full bg-white px-3 py-1 text-xs font-medium text-gray-700">
                                            {{ session.status }}
                                        </span>
                                        <button type="button" class="text-sm text-red-600 hover:underline" @click="removeSession(session)">
                                            {{ tr('Eliminar', 'Delete') }}
                                        </button>
                                    </div>
                                </div>
                            </article>

                            <p
                                v-if="!honor_sessions.length"
                                class="rounded-xl border border-dashed border-gray-300 px-4 py-4 text-sm text-gray-500"
                            >
                                {{ tr('Todavía no hay jornadas planificadas para la asociación.', 'There are no planned sessions for the association yet.') }}
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </PathfinderLayout>
</template>
