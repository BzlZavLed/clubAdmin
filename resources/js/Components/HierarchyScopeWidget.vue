<script setup>
import { computed } from 'vue'
import { usePage } from '@inertiajs/vue3'

const page = usePage()
const user = computed(() => page.props.auth?.user ?? null)
const widget = computed(() => user.value?.hierarchy_widget ?? null)

const hasDistricts = computed(() => (widget.value?.districts?.length ?? 0) > 0)
const hasAssociations = computed(() => (widget.value?.associations?.length ?? 0) > 0)

const levelLabel = computed(() => {
    return {
        district: 'Distrito',
        association: 'Asociación',
        union: 'Unión',
    }[widget.value?.level] || 'Jerarquía'
})
</script>

<template>
    <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
        <div class="flex flex-col gap-3 md:flex-row md:items-end md:justify-between">
            <div>
                <h3 class="text-lg font-semibold text-slate-900">{{ levelLabel }}: {{ widget?.title || 'Sin alcance' }}</h3>
                <p class="mt-1 text-sm text-slate-600">
                    Vista resumida de la estructura accesible para el usuario autenticado.
                </p>
            </div>

            <div class="grid grid-cols-2 gap-3 md:grid-cols-4">
                <div v-if="widget?.summary?.associations !== undefined" class="rounded-xl bg-slate-50 px-4 py-3 text-sm">
                    <div class="text-slate-500">Asociaciones</div>
                    <div class="text-lg font-semibold text-slate-900">{{ widget.summary.associations }}</div>
                </div>
                <div class="rounded-xl bg-slate-50 px-4 py-3 text-sm">
                    <div class="text-slate-500">Distritos</div>
                    <div class="text-lg font-semibold text-slate-900">{{ widget?.summary?.districts ?? 0 }}</div>
                </div>
                <div class="rounded-xl bg-slate-50 px-4 py-3 text-sm">
                    <div class="text-slate-500">Iglesias</div>
                    <div class="text-lg font-semibold text-slate-900">{{ widget?.summary?.churches ?? 0 }}</div>
                </div>
                <div class="rounded-xl bg-slate-50 px-4 py-3 text-sm">
                    <div class="text-slate-500">Clubes</div>
                    <div class="text-lg font-semibold text-slate-900">{{ widget?.summary?.clubs ?? 0 }}</div>
                </div>
            </div>
        </div>

        <div class="mt-4 grid gap-3 text-sm text-slate-700 md:grid-cols-2" v-if="widget">
            <div><span class="font-medium">Unión:</span> {{ widget.union_name || '—' }}</div>
            <div v-if="widget.level !== 'union'"><span class="font-medium">Asociación:</span> {{ widget.association_name || '—' }}</div>
        </div>

        <div v-if="hasDistricts" class="mt-6 space-y-4">
            <div
                v-for="district in widget.districts"
                :key="`district-${district.id}`"
                class="rounded-2xl border border-slate-200 bg-slate-50/70 p-4"
            >
                <div class="flex flex-col gap-2 md:flex-row md:items-start md:justify-between">
                    <div>
                        <h4 class="text-base font-semibold text-slate-900">{{ district.name }}</h4>
                        <p class="text-sm text-slate-600">
                            {{ district.association_name || 'Sin asociación' }} / {{ district.union_name || 'Sin unión' }}
                        </p>
                    </div>
                    <div class="flex gap-2 text-xs font-medium text-slate-700">
                        <span class="rounded-full bg-white px-3 py-1">Iglesias: {{ district.churches_count }}</span>
                        <span class="rounded-full bg-white px-3 py-1">Clubes: {{ district.clubs_count }}</span>
                    </div>
                </div>

                <div class="mt-4 grid gap-3 lg:grid-cols-2">
                    <div
                        v-for="church in district.churches"
                        :key="`church-${church.id}`"
                        class="rounded-xl border border-slate-200 bg-white p-4"
                    >
                        <div class="flex items-center justify-between gap-3">
                            <h5 class="font-medium text-slate-900">{{ church.name }}</h5>
                            <span class="rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-medium text-emerald-700">
                                {{ church.clubs_count }} club{{ church.clubs_count === 1 ? '' : 'es' }}
                            </span>
                        </div>

                        <ul class="mt-3 space-y-2" v-if="church.clubs.length">
                            <li
                                v-for="club in church.clubs"
                                :key="`club-${club.id}`"
                                class="flex items-center justify-between rounded-lg bg-slate-50 px-3 py-2"
                            >
                                <span class="text-slate-800">{{ club.name }}</span>
                                <span class="text-xs uppercase tracking-wide text-slate-500">{{ club.type }}</span>
                            </li>
                        </ul>
                        <p v-else class="mt-3 text-sm text-slate-500">No hay clubes registrados para esta iglesia.</p>
                    </div>
                </div>
            </div>
        </div>

        <div v-else-if="hasAssociations" class="mt-6 space-y-4">
            <div
                v-for="association in widget.associations"
                :key="`association-${association.id}`"
                class="rounded-2xl border border-slate-200 bg-slate-50/70 p-4"
            >
                <div class="flex flex-col gap-2 md:flex-row md:items-start md:justify-between">
                    <div>
                        <h4 class="text-base font-semibold text-slate-900">{{ association.name }}</h4>
                        <p class="text-sm text-slate-600">{{ association.districts_count }} distritos dentro de esta asociación</p>
                    </div>
                    <div class="flex gap-2 text-xs font-medium text-slate-700">
                        <span class="rounded-full bg-white px-3 py-1">Iglesias: {{ association.churches_count }}</span>
                        <span class="rounded-full bg-white px-3 py-1">Clubes: {{ association.clubs_count }}</span>
                    </div>
                </div>

                <div class="mt-4 space-y-3">
                    <div
                        v-for="district in association.districts"
                        :key="`association-district-${district.id}`"
                        class="rounded-xl border border-slate-200 bg-white p-4"
                    >
                        <div class="flex items-center justify-between gap-3">
                            <h5 class="font-medium text-slate-900">{{ district.name }}</h5>
                            <span class="text-sm text-slate-600">
                                {{ district.churches_count }} iglesias / {{ district.clubs_count }} clubes
                            </span>
                        </div>
                        <div class="mt-3 flex flex-wrap gap-2">
                            <span
                                v-for="church in district.churches"
                                :key="`association-church-${church.id}`"
                                class="rounded-full bg-slate-50 px-3 py-1 text-xs text-slate-700"
                            >
                                {{ church.name }} ({{ church.clubs_count }})
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div v-else class="mt-6 rounded-xl border border-dashed border-slate-300 px-4 py-6 text-sm text-slate-500">
            No hay estructura jerárquica disponible para este usuario.
        </div>
    </div>
</template>
