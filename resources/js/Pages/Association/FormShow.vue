<script setup>
import PathfinderLayout from '@/Layouts/PathfinderLayout.vue'
import { Link } from '@inertiajs/vue3'
import { ArrowDownTrayIcon, ArrowLeftIcon, PrinterIcon } from '@heroicons/vue/24/outline'
import { useLocale } from '@/Composables/useLocale'

const props = defineProps({
    form: { type: Object, required: true },
})

const { tr, locale } = useLocale()
const localized = item => locale.value === 'en' ? item.en : item.es
const formTitle = () => locale.value === 'en' ? props.form.title_en : props.form.title_es
const signatureRole = signature => locale.value === 'en' ? signature.role_en : signature.role_es

const dateValue = (value, includeTime = false) => {
    if (!value) return '—'
    const parsed = new Date(value)
    if (Number.isNaN(parsed.getTime())) return value

    return new Intl.DateTimeFormat(locale.value === 'en' ? 'en-US' : 'es-US', includeTime
        ? { dateStyle: 'medium', timeStyle: 'short' }
        : { dateStyle: 'medium', timeZone: 'UTC' }
    ).format(parsed)
}

const displayValue = field => {
    const value = field.value
    if (value === null || value === undefined || value === '') return '—'
    if (field.format === 'boolean') return value ? tr('Sí', 'Yes') : tr('No', 'No')
    if (field.format === 'date') return dateValue(value)
    if (field.format === 'datetime') return dateValue(value, true)
    if (field.format === 'percentage') return `${value}%`
    if (field.format === 'list') {
        if (Array.isArray(value)) {
            return value.map(item => typeof item === 'object' ? Object.values(item).filter(Boolean).join(' — ') : item).join('\n') || '—'
        }
        return String(value)
    }
    return String(value)
}
</script>

<template>
    <PathfinderLayout>
        <template #title>{{ tr('Vista de forma', 'Form preview') }}</template>

        <div class="mx-auto max-w-5xl space-y-5">
            <div class="flex flex-col gap-3 print:hidden sm:flex-row sm:items-center sm:justify-between">
                <Link :href="route('association.forms')" class="inline-flex items-center gap-2 text-sm font-semibold text-gray-600 hover:text-gray-900">
                    <ArrowLeftIcon class="h-4 w-4" />
                    {{ tr('Volver a formas', 'Back to forms') }}
                </Link>
                <div class="flex gap-2">
                    <button type="button" class="inline-flex items-center gap-2 rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 shadow-sm hover:bg-gray-50" @click="window.print()">
                        <PrinterIcon class="h-4 w-4" />{{ tr('Imprimir', 'Print') }}
                    </button>
                    <a v-if="form.download_url" :href="form.download_url" class="inline-flex items-center gap-2 rounded-lg bg-red-700 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-red-800">
                        <ArrowDownTrayIcon class="h-4 w-4" />{{ tr('Descargar original', 'Download original') }}
                    </a>
                </div>
            </div>

            <article class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm print:border-0 print:shadow-none">
                <header class="border-b-4 border-red-700 bg-gray-950 px-6 py-7 text-white sm:px-10">
                    <p class="text-xs font-semibold uppercase tracking-[0.2em] text-yellow-400">{{ tr('Asociación · Solo lectura', 'Association · Read only') }}</p>
                    <h1 class="mt-2 text-2xl font-bold sm:text-3xl">{{ formTitle() }}</h1>
                    <div class="mt-4 grid gap-1 text-sm text-gray-300 sm:grid-cols-2">
                        <p><span class="font-semibold text-white">{{ tr('Club', 'Club') }}:</span> {{ form.club.name }}</p>
                        <p><span class="font-semibold text-white">{{ tr('Entregado', 'Submitted') }}:</span> {{ dateValue(form.submitted_at, true) }}</p>
                        <p v-if="form.club.church_name"><span class="font-semibold text-white">{{ tr('Iglesia', 'Church') }}:</span> {{ form.club.church_name }}</p>
                        <p v-if="form.club.district_name"><span class="font-semibold text-white">{{ tr('Distrito', 'District') }}:</span> {{ form.club.district_name }}</p>
                    </div>
                </header>

                <div class="space-y-8 p-6 sm:p-10">
                    <section v-for="(section, sectionIndex) in form.sections" :key="sectionIndex">
                        <h2 class="border-b border-gray-300 pb-2 text-sm font-bold uppercase tracking-wide text-red-800">
                            {{ locale === 'en' ? section.title_en : section.title_es }}
                        </h2>
                        <dl class="mt-4 grid gap-x-8 gap-y-5 sm:grid-cols-2">
                            <div
                                v-for="field in section.fields"
                                :key="field.key"
                                :class="field.format === 'longtext' || field.format === 'list' ? 'sm:col-span-2' : ''"
                            >
                                <dt class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ localized(field) }}</dt>
                                <dd class="mt-1 whitespace-pre-wrap break-words text-sm font-medium text-gray-900">{{ displayValue(field) }}</dd>
                            </div>
                        </dl>
                    </section>

                    <section v-if="form.signatures?.length">
                        <h2 class="border-b border-gray-300 pb-2 text-sm font-bold uppercase tracking-wide text-red-800">
                            {{ tr('Firmas', 'Signatures') }}
                        </h2>
                        <div class="mt-4 grid gap-4 sm:grid-cols-2">
                            <div v-for="signature in form.signatures" :key="signature.id" class="rounded-xl border border-gray-200 bg-gray-50 p-4">
                                <div class="flex items-start justify-between gap-3">
                                    <div>
                                        <p class="text-xs font-bold uppercase tracking-wide text-gray-500">{{ signatureRole(signature) }}</p>
                                        <p class="mt-1 font-semibold text-gray-900">{{ signature.signer_name || tr('Pendiente de firma', 'Awaiting signature') }}</p>
                                        <p v-if="signature.signer_email" class="mt-0.5 text-xs text-gray-500">{{ signature.signer_email }}</p>
                                    </div>
                                    <span
                                        class="rounded-full px-2 py-1 text-xs font-semibold"
                                        :class="signature.signed_at ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-800'"
                                    >
                                        {{ signature.signed_at ? tr('Firmado', 'Signed') : tr('Pendiente', 'Pending') }}
                                    </span>
                                </div>

                                <div v-if="signature.signature_url || signature.signature_text" class="mt-4 flex min-h-24 items-center justify-center rounded-lg border border-gray-200 bg-white p-3">
                                    <img
                                        v-if="signature.signature_url"
                                        :src="signature.signature_url"
                                        :alt="`${tr('Firma de', 'Signature of')} ${signature.signer_name || signatureRole(signature)}`"
                                        class="max-h-24 max-w-full object-contain"
                                    />
                                    <p v-else class="font-serif text-2xl italic text-gray-800">{{ signature.signature_text }}</p>
                                </div>
                                <p v-if="signature.signed_at" class="mt-3 text-xs text-gray-500">
                                    {{ tr('Firmado el', 'Signed on') }} {{ dateValue(signature.signed_at, true) }}
                                </p>
                            </div>
                        </div>
                    </section>
                </div>
            </article>
        </div>
    </PathfinderLayout>
</template>

<style scoped>
@media print {
    article {
        break-inside: avoid;
    }
}
</style>
