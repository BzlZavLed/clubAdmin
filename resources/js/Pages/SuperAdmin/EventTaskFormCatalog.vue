<script setup>
import PathfinderLayout from '@/Layouts/PathfinderLayout.vue'
import { router, useForm } from '@inertiajs/vue3'
import { computed, reactive, ref, watch } from 'vue'

const props = defineProps({
    schemas: { type: Array, default: () => [] },
    fixedHandlers: { type: Array, default: () => [] },
    formOptions: {
        type: Object,
        default: () => ({ global: [], templates: [] }),
    },
    templates: { type: Object, required: true },
    tasks: { type: Object, required: true },
    clubs: { type: Array, default: () => [] },
    eventTypes: { type: Array, default: () => [] },
    taskKeys: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({ search: '', club_id: '', event_type: '' }) },
})

const filters = reactive({
    search: props.filters?.search || '',
    club_id: props.filters?.club_id || '',
    event_type: props.filters?.event_type || '',
})

let filterTimer = null
watch(
    () => [filters.search, filters.club_id, filters.event_type],
    () => {
        clearTimeout(filterTimer)
        filterTimer = setTimeout(() => {
            router.get(route('superadmin.event-task-forms.index'), {
                search: filters.search || undefined,
                club_id: filters.club_id || undefined,
                event_type: filters.event_type || undefined,
            }, {
                preserveState: true,
                preserveScroll: true,
                replace: true,
            })
        }, 250)
    }
)

const editingType = ref('')
const editingRecord = ref(null)
const jsonText = ref('')
const jsonError = ref('')
const showRawJson = ref(false)
const selectedExistingForm = ref('')
const showAllTemplateOptions = ref(false)

const schemaForm = useForm({
    key: '',
    name: '',
    description: '',
    schema_json: {},
})

const fieldTypes = ['text', 'textarea', 'number', 'date', 'time', 'select', 'checkbox', 'image']
const schemaMode = ref('single')
const schemaFields = ref([])
const templateUsesCustomSchema = ref(false)
const taskUsesCustomSchema = ref(false)

const blankSchemaField = () => ({
    key: '',
    label: '',
    type: 'text',
    required: false,
    help: '',
    optionsText: '',
})

const templateForm = useForm({
    event_type: '',
    title: '',
    description: '',
    task_key: '',
    form_schema_json: null,
    is_active: true,
})

const taskForm = useForm({
    task_key: '',
    custom_form_schema: null,
    clear_custom_form: false,
})

const modalTitle = computed(() => {
    if (editingType.value === 'schema_create') return 'Crear formulario global'
    if (editingType.value === 'schema') return 'Editar formulario global'
    if (editingType.value === 'template') return 'Editar plantilla de tarea'
    if (editingType.value === 'task') return 'Editar formulario activo'
    return ''
})

const shouldShowSchemaBuilder = computed(() => {
    if (editingType.value === 'schema_create' || editingType.value === 'schema') return true
    if (editingType.value === 'template') return templateUsesCustomSchema.value
    if (editingType.value === 'task') return taskUsesCustomSchema.value
    return false
})

const currentSchemaJsonLabel = computed(() => {
    if (editingType.value === 'schema_create' || editingType.value === 'schema') return 'schema_json'
    if (editingType.value === 'template') return 'form_schema_json'
    if (editingType.value === 'task') return 'custom_form_schema'
    return 'schema_json'
})

const globalFormOptions = computed(() => props.formOptions?.global || [])
const templateFormOptions = computed(() => props.formOptions?.templates || [])
const allExistingFormOptions = computed(() => [
    ...globalFormOptions.value,
    ...templateFormOptions.value,
])

const stopWords = new Set(['a', 'an', 'and', 'are', 'as', 'de', 'del', 'el', 'en', 'for', 'la', 'las', 'los', 'of', 'para', 'the', 'to', 'y'])

const normalizeSearchText = (value) => String(value || '')
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, ' ')
    .trim()

const titleTokens = (value) => normalizeSearchText(value)
    .split(' ')
    .filter((token) => token.length > 2 && !stopWords.has(token))

const templateMatchScore = (option) => {
    if (editingType.value !== 'task' || !editingRecord.value) {
        return { score: 0, reasons: [] }
    }

    const task = editingRecord.value
    const taskTitle = normalizeSearchText(task.title || '')
    const optionTitle = normalizeSearchText(option.title || option.label || '')
    const taskKey = normalizeSearchText(taskForm.task_key || task.task_key || '')
    const optionKey = normalizeSearchText(option.task_key || '')
    const taskEventType = normalizeSearchText(task.event?.event_type || '')
    const optionEventType = normalizeSearchText(option.event_type || '')
    const taskClubId = Number(task.club?.id || 0)
    const optionClubId = Number(option.club_id || 0)
    const reasons = []
    let score = 0

    if (taskKey && optionKey && taskKey === optionKey) {
        score += 70
        reasons.push('mismo task_key')
    }

    if (taskTitle && optionTitle && taskTitle === optionTitle) {
        score += 100
        reasons.push('mismo nombre')
    } else if (taskTitle && optionTitle && (taskTitle.includes(optionTitle) || optionTitle.includes(taskTitle))) {
        score += 55
        reasons.push('nombre parecido')
    }

    const taskTokens = titleTokens(taskTitle)
    const optionTokens = titleTokens(optionTitle)
    if (taskTokens.length && optionTokens.length) {
        const optionTokenSet = new Set(optionTokens)
        const commonCount = taskTokens.filter((token) => optionTokenSet.has(token)).length
        const overlap = commonCount / Math.max(Math.min(taskTokens.length, optionTokens.length), 1)
        if (overlap >= 0.5) {
            score += Math.round(overlap * 40)
            reasons.push('palabras parecidas')
        }
    }

    if (taskEventType && optionEventType && taskEventType === optionEventType) {
        score += 10
        reasons.push('mismo tipo de evento')
    }

    if (taskClubId && optionClubId && taskClubId === optionClubId) {
        score += 8
        reasons.push('mismo club')
    }

    return { score, reasons: [...new Set(reasons)] }
}

const scoredTemplateFormOptions = computed(() => templateFormOptions.value
    .map((option) => {
        const match = templateMatchScore(option)
        return {
            ...option,
            match_score: match.score,
            match_reasons: match.reasons,
        }
    })
    .sort((a, b) => b.match_score - a.match_score || String(a.label || '').localeCompare(String(b.label || ''))))

const suggestedTemplateFormOptions = computed(() => scoredTemplateFormOptions.value
    .filter((option) => option.match_score >= 25)
    .slice(0, 12))

const hasTemplateSuggestions = computed(() => suggestedTemplateFormOptions.value.length > 0)
const visibleTemplateFormOptions = computed(() => {
    if (!hasTemplateSuggestions.value || showAllTemplateOptions.value) {
        return scoredTemplateFormOptions.value
    }

    return suggestedTemplateFormOptions.value
})

const templateOptgroupLabel = computed(() => {
    if (!hasTemplateSuggestions.value) return 'Plantillas guardadas'
    return showAllTemplateOptions.value ? 'Todas las plantillas guardadas' : 'Plantillas sugeridas'
})

const templateOptionLabel = (option) => {
    const reasons = Array.isArray(option.match_reasons) && option.match_reasons.length
        ? ` · ${option.match_reasons.join(', ')}`
        : ''

    return `${option.label} / ${option.detail}${reasons}`
}

const prettyJson = (value, fallback = { mode: 'single', fields: [] }) => JSON.stringify(value || fallback, null, 2)

const parseJson = (allowBlank = false) => {
    jsonError.value = ''
    const raw = String(jsonText.value || '').trim()
    if (!raw && allowBlank) return null

    try {
        const parsed = JSON.parse(raw)
        if (!parsed || typeof parsed !== 'object' || Array.isArray(parsed)) {
            jsonError.value = 'El JSON debe ser un objeto.'
            return undefined
        }
        if (!Array.isArray(parsed.fields)) {
            jsonError.value = 'El JSON debe incluir fields como arreglo.'
            return undefined
        }
        return parsed
    } catch (error) {
        jsonError.value = error?.message || 'JSON inválido.'
        return undefined
    }
}

const closeEditor = () => {
    editingType.value = ''
    editingRecord.value = null
    jsonText.value = ''
    jsonError.value = ''
    showRawJson.value = false
    selectedExistingForm.value = ''
    showAllTemplateOptions.value = false
    schemaMode.value = 'single'
    schemaFields.value = []
    templateUsesCustomSchema.value = false
    taskUsesCustomSchema.value = false
    schemaForm.reset()
    templateForm.reset()
    taskForm.reset()
}

const toSnake = (value) => String(value || '')
    .trim()
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '_')
    .replace(/^_+|_+$/g, '')

const builderFieldsFromSchema = (schema) => {
    const fields = Array.isArray(schema?.fields) ? schema.fields : []
    return fields.length
        ? fields.map((field) => ({
            key: field.key || '',
            label: field.label || '',
            type: field.type || 'text',
            required: !!field.required,
            help: field.help || '',
            optionsText: Array.isArray(field.options) ? field.options.join(', ') : '',
        }))
        : [blankSchemaField()]
}

const openCreateSchema = () => {
    editingType.value = 'schema_create'
    editingRecord.value = null
    schemaForm.key = ''
    schemaForm.name = ''
    schemaForm.description = ''
    schemaForm.schema_json = { mode: 'single', fields: [] }
    schemaMode.value = 'single'
    schemaFields.value = [blankSchemaField()]
    jsonText.value = ''
    jsonError.value = ''
    showRawJson.value = false
    selectedExistingForm.value = ''
    showAllTemplateOptions.value = false
}

const editSchema = (schema) => {
    editingType.value = 'schema'
    editingRecord.value = schema
    schemaForm.key = schema.key || ''
    schemaForm.name = schema.name || ''
    schemaForm.description = schema.description || ''
    schemaForm.schema_json = schema.schema_json || { mode: 'single', fields: [] }
    schemaMode.value = schema.schema_json?.mode === 'registry' ? 'registry' : 'single'
    schemaFields.value = builderFieldsFromSchema(schema.schema_json)
    jsonText.value = prettyJson(schema.schema_json)
    jsonError.value = ''
    showRawJson.value = false
    selectedExistingForm.value = ''
    showAllTemplateOptions.value = false
}

const editTemplate = (template) => {
    editingType.value = 'template'
    editingRecord.value = template
    templateForm.event_type = template.event_type || ''
    templateForm.title = template.title || ''
    templateForm.description = template.description || ''
    templateForm.task_key = template.task_key || ''
    templateForm.form_schema_json = template.form_schema_json || null
    templateForm.is_active = !!template.is_active
    templateUsesCustomSchema.value = !!template.form_schema_json
    schemaMode.value = template.form_schema_json?.mode === 'registry' ? 'registry' : 'single'
    schemaFields.value = builderFieldsFromSchema(template.form_schema_json)
    jsonText.value = template.form_schema_json ? prettyJson(template.form_schema_json) : ''
    jsonError.value = ''
    showRawJson.value = false
    selectedExistingForm.value = ''
    showAllTemplateOptions.value = false
}

const editTask = (task) => {
    editingType.value = 'task'
    editingRecord.value = task
    taskForm.task_key = task.task_key || ''
    taskForm.custom_form_schema = task.custom_form_schema || null
    taskForm.clear_custom_form = false
    taskUsesCustomSchema.value = !!task.custom_form_schema
    schemaMode.value = task.custom_form_schema?.mode === 'registry' ? 'registry' : 'single'
    schemaFields.value = builderFieldsFromSchema(task.custom_form_schema)
    jsonText.value = task.custom_form_schema ? prettyJson(task.custom_form_schema) : ''
    jsonError.value = ''
    showRawJson.value = false
    selectedExistingForm.value = ''
    showAllTemplateOptions.value = false
}

const addSchemaField = () => {
    schemaFields.value = [...schemaFields.value, blankSchemaField()]
}

const moveSchemaField = (index, direction) => {
    const target = index + direction
    if (target < 0 || target >= schemaFields.value.length) return
    const next = [...schemaFields.value]
    const [field] = next.splice(index, 1)
    next.splice(target, 0, field)
    schemaFields.value = next
}

const removeSchemaField = (index) => {
    schemaFields.value = schemaFields.value.filter((_, fieldIndex) => fieldIndex !== index)
    if (!schemaFields.value.length) {
        schemaFields.value = [blankSchemaField()]
    }
}

const schemaFromBuilder = () => {
    jsonError.value = ''
    const normalized = schemaFields.value
        .map((field) => {
            const label = String(field.label || '').trim()
            const key = toSnake(field.key || label)
            const help = String(field.help || '').trim()
            const options = field.type === 'select'
                ? String(field.optionsText || '')
                    .split(',')
                    .map((option) => option.trim())
                    .filter(Boolean)
                : []

            return {
                key,
                label,
                type: field.type || 'text',
                required: !!field.required,
                ...(help ? { help } : {}),
                ...(field.type === 'select' && options.length ? { options } : {}),
            }
        })
        .filter((field) => field.key && field.label)

    if (!normalized.length) {
        jsonError.value = 'Agrega al menos un campo valido.'
        return null
    }

    const keys = new Set()
    for (const field of normalized) {
        if (keys.has(field.key)) {
            jsonError.value = `Key duplicado: ${field.key}`
            return null
        }
        keys.add(field.key)
    }

    return {
        mode: schemaMode.value === 'registry' ? 'registry' : 'single',
        fields: normalized,
    }
}

const syncJsonFromBuilder = () => {
    const schema = schemaFromBuilder()
    if (!schema) return
    jsonText.value = prettyJson(schema)
    jsonError.value = ''
}

const loadBuilderFromJson = () => {
    const parsed = parseJson(false)
    if (!parsed) return
    schemaMode.value = parsed.mode === 'registry' ? 'registry' : 'single'
    schemaFields.value = builderFieldsFromSchema(parsed)
    jsonText.value = prettyJson(parsed)
    jsonError.value = ''
}

const applySelectedExistingForm = () => {
    jsonError.value = ''
    const option = allExistingFormOptions.value.find((entry) => entry.id === selectedExistingForm.value)
    if (!option) return

    if (option.source === 'global') {
        taskForm.task_key = option.task_key || ''
        taskUsesCustomSchema.value = false
        taskForm.clear_custom_form = true
        taskForm.custom_form_schema = null
        schemaMode.value = option.schema_json?.mode === 'registry' ? 'registry' : 'single'
        schemaFields.value = builderFieldsFromSchema(option.schema_json)
        jsonText.value = option.schema_json ? prettyJson(option.schema_json) : ''
        return
    }

    taskForm.task_key = option.task_key || taskForm.task_key || ''
    taskUsesCustomSchema.value = true
    taskForm.clear_custom_form = false
    taskForm.custom_form_schema = option.schema_json || null
    schemaMode.value = option.schema_json?.mode === 'registry' ? 'registry' : 'single'
    schemaFields.value = builderFieldsFromSchema(option.schema_json)
    jsonText.value = option.schema_json ? prettyJson(option.schema_json) : ''
}

const submitEditor = () => {
    if (editingType.value === 'schema_create') {
        const schema = schemaFromBuilder()
        if (!schema) return
        schemaForm.key = toSnake(schemaForm.key)
        schemaForm.schema_json = schema
        schemaForm.post(route('superadmin.event-task-forms.schemas.store'), {
            preserveScroll: true,
            onSuccess: closeEditor,
        })
        return
    }

    if (editingType.value === 'schema') {
        const schema = schemaFromBuilder()
        if (!schema) return
        schemaForm.schema_json = schema
        schemaForm.put(route('superadmin.event-task-forms.schemas.update', editingRecord.value.id), {
            preserveScroll: true,
            onSuccess: closeEditor,
        })
        return
    }

    if (editingType.value === 'template') {
        if (templateUsesCustomSchema.value) {
            const schema = schemaFromBuilder()
            if (!schema) return
            templateForm.form_schema_json = schema
        } else {
            templateForm.form_schema_json = null
        }
        templateForm.put(route('superadmin.event-task-forms.templates.update', editingRecord.value.id), {
            preserveScroll: true,
            onSuccess: closeEditor,
        })
        return
    }

    if (editingType.value === 'task') {
        if (taskUsesCustomSchema.value) {
            const schema = schemaFromBuilder()
            if (!schema) return
            taskForm.custom_form_schema = schema
            taskForm.clear_custom_form = false
        } else {
            taskForm.custom_form_schema = null
            taskForm.clear_custom_form = true
        }
        taskForm.put(route('superadmin.event-task-forms.tasks.update', editingRecord.value.id), {
            preserveScroll: true,
            onSuccess: closeEditor,
        })
    }
}

const handlerClass = (tone) => ({
    green: 'border-emerald-200 bg-emerald-50 text-emerald-700',
    blue: 'border-blue-200 bg-blue-50 text-blue-700',
    amber: 'border-amber-200 bg-amber-50 text-amber-700',
    red: 'border-rose-200 bg-rose-50 text-rose-700',
}[tone] || 'border-gray-200 bg-gray-50 text-gray-700')

const visitLink = (link) => {
    if (!link?.url) return
    router.visit(link.url, {
        preserveScroll: true,
        preserveState: true,
    })
}
</script>

<template>
    <PathfinderLayout>
        <template #title>Formularios de tareas</template>

        <div class="space-y-5">
            <div class="rounded-lg border bg-white p-4 shadow-sm">
                <div class="grid gap-3 lg:grid-cols-[1fr_220px_220px]">
                    <div>
                        <label class="mb-1 block text-xs font-medium text-gray-600">Buscar</label>
                        <input
                            v-model="filters.search"
                            type="search"
                            class="w-full rounded border px-3 py-2 text-sm"
                            placeholder="Tarea, evento, key o descripción"
                        >
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-gray-600">Club</label>
                        <select v-model="filters.club_id" class="w-full rounded border px-3 py-2 text-sm">
                            <option value="">Todos</option>
                            <option v-for="club in clubs" :key="club.id" :value="club.id">
                                {{ club.club_name }}{{ club.status !== 'active' ? ' (inactivo)' : '' }}
                            </option>
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-gray-600">Tipo de evento</label>
                        <select v-model="filters.event_type" class="w-full rounded border px-3 py-2 text-sm">
                            <option value="">Todos</option>
                            <option v-for="type in eventTypes" :key="type" :value="type">{{ type }}</option>
                        </select>
                    </div>
                </div>
            </div>

            <section class="rounded-lg border border-blue-100 bg-blue-50 p-4 text-sm text-blue-900">
                <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                    <div>
                        <h2 class="text-sm font-semibold text-blue-950">Uso rápido</h2>
                        <p class="mt-1 max-w-3xl text-xs text-blue-800">
                            Usa esta pantalla para revisar qué acción abrirá cada tarea de evento. Los handlers fijos son redirects o módulos del sistema; los formularios globales son schemas reutilizables que abren el modal de formulario.
                        </p>
                    </div>
                    <div class="inline-flex w-fit rounded-full border border-blue-200 bg-white px-3 py-1 text-xs font-semibold text-blue-700">
                        Custom form tiene prioridad
                    </div>
                </div>

                <div class="mt-4 grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                    <div class="rounded border border-blue-100 bg-white/70 p-3">
                        <div class="font-semibold text-blue-950">Handlers fijos</div>
                        <p class="mt-1 text-xs text-blue-800">
                            Participantes, documentos y transporte abren pantallas existentes. No se editan como JSON.
                        </p>
                    </div>
                    <div class="rounded border border-blue-100 bg-white/70 p-3">
                        <div class="font-semibold text-blue-950">Formularios globales</div>
                        <p class="mt-1 text-xs text-blue-800">
                            Edita schemas reutilizables por <span class="font-mono">task_key</span>, como emergency_contacts o camp_reservation.
                        </p>
                    </div>
                    <div class="rounded border border-blue-100 bg-white/70 p-3">
                        <div class="font-semibold text-blue-950">Plantillas guardadas</div>
                        <p class="mt-1 text-xs text-blue-800">
                            Cambia el catálogo que se copia a nuevos eventos según club y tipo de evento.
                        </p>
                    </div>
                    <div class="rounded border border-blue-100 bg-white/70 p-3">
                        <div class="font-semibold text-blue-950">Tareas de eventos</div>
                        <p class="mt-1 text-xs text-blue-800">
                            Revisa el handler activo de una tarea real. Si aparece Documents tab pero debe abrir formulario, agrega o corrige el schema custom.
                        </p>
                    </div>
                </div>

                <ol class="mt-4 grid gap-2 text-xs text-blue-800 md:grid-cols-3">
                    <li><span class="font-semibold text-blue-950">1.</span> Filtra por club, tipo de evento o título de tarea.</li>
                    <li><span class="font-semibold text-blue-950">2.</span> Abre Editar en la fila que necesitas corregir.</li>
                    <li><span class="font-semibold text-blue-950">3.</span> Usa handlers fijos para módulos existentes; usa formularios globales o custom cuando necesitas capturar datos nuevos.</li>
                </ol>
            </section>

            <section class="rounded-lg border bg-white shadow-sm">
                <div class="border-b px-4 py-3">
                    <h2 class="text-sm font-semibold text-gray-900">Handlers fijos</h2>
                    <p class="text-xs text-gray-500">Acciones del sistema que no usan schema_json. Sirven para tareas que deben abrir una pantalla existente.</p>
                </div>
                <div class="grid gap-3 p-4 md:grid-cols-3">
                    <div
                        v-for="handler in fixedHandlers"
                        :key="handler.handler"
                        class="rounded border border-gray-200 bg-gray-50 p-3"
                    >
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <div class="text-sm font-semibold text-gray-900">{{ handler.target }}</div>
                                <div class="mt-1 font-mono text-[11px] text-gray-500">{{ handler.label }}</div>
                            </div>
                            <span class="rounded-full border border-blue-200 bg-blue-50 px-2 py-0.5 text-[11px] font-semibold text-blue-700">Fijo</span>
                        </div>
                        <p class="mt-2 text-xs text-gray-600">{{ handler.description }}</p>
                        <div class="mt-3 space-y-2 text-xs text-gray-600">
                            <div>
                                <span class="font-semibold text-gray-800">Task keys:</span>
                                <span class="font-mono">{{ handler.task_keys?.length ? handler.task_keys.join(', ') : 'keywords' }}</span>
                            </div>
                            <div>
                                <span class="font-semibold text-gray-800">Keywords:</span>
                                <span>{{ handler.keywords?.join(', ') }}</span>
                            </div>
                            <div class="text-gray-500">{{ handler.priority }}</div>
                        </div>
                    </div>
                </div>
            </section>

            <section class="rounded-lg border bg-white shadow-sm">
                <div class="flex items-center justify-between border-b px-4 py-3">
                    <div>
                        <h2 class="text-sm font-semibold text-gray-900">Formularios globales reutilizables</h2>
                        <p class="text-xs text-gray-500">Schemas reutilizables por task_key. No son redirects a tabs existentes.</p>
                    </div>
                    <button
                        type="button"
                        class="rounded bg-blue-600 px-3 py-2 text-xs font-medium text-white hover:bg-blue-700"
                        @click="openCreateSchema"
                    >
                        Nuevo formulario global
                    </button>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-gray-50 text-left text-gray-600">
                            <tr>
                                <th class="px-4 py-3 font-medium">Key</th>
                                <th class="px-4 py-3 font-medium">Nombre</th>
                                <th class="px-4 py-3 font-medium">Campos</th>
                                <th class="px-4 py-3 font-medium">Actualizado</th>
                                <th class="px-4 py-3 font-medium"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="schema in schemas" :key="schema.id" class="border-t">
                                <td class="px-4 py-3">
                                    <div class="font-mono text-xs text-gray-700">{{ schema.key }}</div>
                                    <span
                                        v-if="schema.is_shadowed_by_fixed_handler"
                                        class="mt-1 inline-flex rounded-full border border-amber-200 bg-amber-50 px-2 py-0.5 text-[11px] font-semibold text-amber-700"
                                    >
                                        Lo cubre {{ schema.fixed_handler?.target }}
                                    </span>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="font-medium text-gray-900">{{ schema.name }}</div>
                                    <div class="max-w-xl text-xs text-gray-500">{{ schema.description || '—' }}</div>
                                    <div v-if="schema.is_shadowed_by_fixed_handler" class="mt-1 max-w-xl text-xs text-amber-700">
                                        Esta key abre un handler fijo, por eso no aparece en "Asignar formulario existente".
                                    </div>
                                </td>
                                <td class="px-4 py-3 text-gray-700">{{ schema.field_count }} / {{ schema.mode || 'single' }}</td>
                                <td class="px-4 py-3 text-gray-500">{{ schema.updated_at || '—' }}</td>
                                <td class="px-4 py-3 text-right">
                                    <button type="button" class="rounded border px-3 py-1 text-xs font-medium text-gray-700 hover:bg-gray-50" @click="editSchema(schema)">
                                        Editar
                                    </button>
                                </td>
                            </tr>
                            <tr v-if="!schemas.length">
                                <td colspan="5" class="px-4 py-8 text-center text-gray-500">No hay formularios globales creados.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="rounded-lg border bg-white shadow-sm">
                <div class="flex items-center justify-between border-b px-4 py-3">
                    <div>
                        <h2 class="text-sm font-semibold text-gray-900">Plantillas guardadas</h2>
                        <p class="text-xs text-gray-500">Catálogo que se reutiliza cuando se crean tareas para eventos.</p>
                    </div>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-gray-50 text-left text-gray-600">
                            <tr>
                                <th class="px-4 py-3 font-medium">Tarea</th>
                                <th class="px-4 py-3 font-medium">Club / evento</th>
                                <th class="px-4 py-3 font-medium">Form</th>
                                <th class="px-4 py-3 font-medium">Estado</th>
                                <th class="px-4 py-3 font-medium"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="template in templates.data" :key="template.id" class="border-t align-top">
                                <td class="px-4 py-3">
                                    <div class="font-medium text-gray-900">{{ template.title }}</div>
                                    <div class="max-w-lg text-xs text-gray-500">{{ template.description || '—' }}</div>
                                    <div v-if="template.task_key" class="mt-1 font-mono text-[11px] text-gray-500">{{ template.task_key }}</div>
                                </td>
                                <td class="px-4 py-3 text-gray-700">
                                    <div>{{ template.club_name || `Club #${template.club_id}` }}</div>
                                    <div class="text-xs text-gray-500">{{ template.event_type }}</div>
                                </td>
                                <td class="px-4 py-3 text-gray-700">
                                    <div>{{ template.field_count }} campos</div>
                                    <div class="text-xs text-gray-500">{{ template.form_mode || 'sin schema custom' }}</div>
                                </td>
                                <td class="px-4 py-3">
                                    <span class="inline-flex rounded-full border px-2 py-0.5 text-xs font-semibold" :class="template.is_active ? handlerClass('green') : handlerClass('red')">
                                        {{ template.is_active ? 'Activa' : 'Inactiva' }}
                                    </span>
                                    <div class="mt-1 text-xs text-gray-500">{{ template.is_custom ? 'Custom' : 'Inferida' }}</div>
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <button type="button" class="rounded border px-3 py-1 text-xs font-medium text-gray-700 hover:bg-gray-50" @click="editTemplate(template)">
                                        Editar
                                    </button>
                                </td>
                            </tr>
                            <tr v-if="!templates.data.length">
                                <td colspan="5" class="px-4 py-8 text-center text-gray-500">No hay plantillas con estos filtros.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div v-if="templates.links?.length" class="flex flex-wrap gap-2 border-t px-4 py-3">
                    <button
                        v-for="link in templates.links"
                        :key="`templates-${link.label}`"
                        type="button"
                        class="rounded border px-3 py-1 text-sm"
                        :class="link.active ? 'border-blue-600 bg-blue-600 text-white' : 'bg-white text-gray-700'"
                        :disabled="!link.url"
                        @click="visitLink(link)"
                        v-html="link.label"
                    />
                </div>
            </section>

            <section class="rounded-lg border bg-white shadow-sm">
                <div class="flex items-center justify-between border-b px-4 py-3">
                    <div>
                        <h2 class="text-sm font-semibold text-gray-900">Tareas de eventos</h2>
                        <p class="text-xs text-gray-500">Handler activo para cada tarea real. Custom form tiene prioridad sobre handlers fijos.</p>
                    </div>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-gray-50 text-left text-gray-600">
                            <tr>
                                <th class="px-4 py-3 font-medium">Tarea</th>
                                <th class="px-4 py-3 font-medium">Evento</th>
                                <th class="px-4 py-3 font-medium">Activo</th>
                                <th class="px-4 py-3 font-medium">Schema custom</th>
                                <th class="px-4 py-3 font-medium"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="task in tasks.data" :key="task.id" class="border-t align-top">
                                <td class="px-4 py-3">
                                    <div class="font-medium text-gray-900">{{ task.title }}</div>
                                    <div class="max-w-lg text-xs text-gray-500">{{ task.description || '—' }}</div>
                                    <div class="mt-1 flex flex-wrap gap-1">
                                        <span v-if="task.task_key" class="rounded bg-gray-100 px-2 py-0.5 font-mono text-[11px] text-gray-600">{{ task.task_key }}</span>
                                        <span class="rounded bg-gray-100 px-2 py-0.5 text-[11px] text-gray-600">{{ task.responsibility_level }}</span>
                                    </div>
                                </td>
                                <td class="px-4 py-3 text-gray-700">
                                    <div>{{ task.event?.title || '—' }}</div>
                                    <div class="text-xs text-gray-500">{{ task.club?.club_name || '—' }} / {{ task.event?.event_type || '—' }}</div>
                                    <div class="text-xs text-gray-500">{{ task.event?.scope_type || 'club' }} / {{ task.event?.status || '—' }}</div>
                                </td>
                                <td class="px-4 py-3">
                                    <span class="inline-flex rounded-full border px-2 py-0.5 text-xs font-semibold" :class="handlerClass(task.active_handler_tone)">
                                        {{ task.active_handler_label }}
                                    </span>
                                    <div class="mt-1 text-xs text-gray-500">{{ task.assignments_count }} asignaciones</div>
                                </td>
                                <td class="px-4 py-3 text-gray-700">
                                    <div>{{ task.custom_field_count }} campos</div>
                                    <div class="text-xs text-gray-500">{{ task.custom_form_mode || 'sin custom form' }}</div>
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <button type="button" class="rounded border px-3 py-1 text-xs font-medium text-gray-700 hover:bg-gray-50" @click="editTask(task)">
                                        Editar
                                    </button>
                                </td>
                            </tr>
                            <tr v-if="!tasks.data.length">
                                <td colspan="5" class="px-4 py-8 text-center text-gray-500">No hay tareas con estos filtros.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div v-if="tasks.links?.length" class="flex flex-wrap gap-2 border-t px-4 py-3">
                    <button
                        v-for="link in tasks.links"
                        :key="`tasks-${link.label}`"
                        type="button"
                        class="rounded border px-3 py-1 text-sm"
                        :class="link.active ? 'border-blue-600 bg-blue-600 text-white' : 'bg-white text-gray-700'"
                        :disabled="!link.url"
                        @click="visitLink(link)"
                        v-html="link.label"
                    />
                </div>
            </section>
        </div>

        <div v-if="editingType" class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black/40 px-4 py-8">
            <div class="w-full max-w-4xl rounded-lg bg-white shadow-xl">
                <div class="flex items-center justify-between border-b px-5 py-4">
                    <div>
                        <h2 class="text-base font-semibold text-gray-900">{{ modalTitle }}</h2>
                        <p class="text-xs text-gray-500">{{ editingRecord?.title || editingRecord?.name || editingRecord?.key }}</p>
                    </div>
                    <button type="button" class="rounded px-2 py-1 text-sm text-gray-500 hover:bg-gray-100" @click="closeEditor">Cerrar</button>
                </div>

                <div class="space-y-4 px-5 py-4">
                    <template v-if="editingType === 'schema' || editingType === 'schema_create'">
                        <div class="grid gap-3 md:grid-cols-2">
                            <div>
                                <label class="mb-1 block text-xs font-medium text-gray-600">Nombre</label>
                                <input v-model="schemaForm.name" class="w-full rounded border px-3 py-2 text-sm">
                                <p v-if="schemaForm.errors.name" class="mt-1 text-xs text-red-600">{{ schemaForm.errors.name }}</p>
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-medium text-gray-600">Key</label>
                                <input
                                    v-if="editingType === 'schema_create'"
                                    v-model="schemaForm.key"
                                    class="w-full rounded border px-3 py-2 font-mono text-sm"
                                    placeholder="medical_forms"
                                >
                                <input v-else :value="editingRecord?.key" disabled class="w-full rounded border bg-gray-50 px-3 py-2 font-mono text-sm text-gray-500">
                                <p v-if="editingType === 'schema_create'" class="mt-1 text-xs text-gray-500">
                                    No uses keys reservadas por handlers fijos como permission_slips, transportation_plan o finalize_attendee_list.
                                </p>
                                <p v-if="schemaForm.errors.key" class="mt-1 text-xs text-red-600">{{ schemaForm.errors.key }}</p>
                            </div>
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-gray-600">Descripción</label>
                            <textarea v-model="schemaForm.description" rows="2" class="w-full rounded border px-3 py-2 text-sm"></textarea>
                            <p v-if="schemaForm.errors.description" class="mt-1 text-xs text-red-600">{{ schemaForm.errors.description }}</p>
                        </div>
                    </template>

                    <template v-else-if="editingType === 'template'">
                        <div class="grid gap-3 md:grid-cols-2">
                            <div>
                                <label class="mb-1 block text-xs font-medium text-gray-600">Título</label>
                                <input v-model="templateForm.title" class="w-full rounded border px-3 py-2 text-sm">
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-medium text-gray-600">Event type</label>
                                <input v-model="templateForm.event_type" class="w-full rounded border px-3 py-2 text-sm">
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-medium text-gray-600">Task key</label>
                                <input v-model="templateForm.task_key" list="task-key-options" class="w-full rounded border px-3 py-2 text-sm" placeholder="custom or built-in key">
                            </div>
                            <label class="flex items-end gap-2 pb-2 text-sm text-gray-700">
                                <input v-model="templateForm.is_active" type="checkbox" class="rounded border-gray-300">
                                Activa para nuevos eventos
                            </label>
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-gray-600">Descripción</label>
                            <textarea v-model="templateForm.description" rows="2" class="w-full rounded border px-3 py-2 text-sm"></textarea>
                        </div>
                        <label class="flex items-start gap-2 rounded border border-blue-100 bg-blue-50 p-3 text-sm text-blue-900">
                            <input v-model="templateUsesCustomSchema" type="checkbox" class="mt-0.5 rounded border-gray-300">
                            <span>
                                <span class="block font-semibold">Usar formulario custom en esta plantilla</span>
                                <span class="block text-xs text-blue-700">Si esta activo, nuevos eventos copiaran este schema a la tarea. Si esta apagado, la tarea dependera de su task_key o handler.</span>
                            </span>
                        </label>
                    </template>

                    <template v-else>
                        <div class="rounded border border-emerald-100 bg-emerald-50 p-3">
                            <div class="flex flex-col gap-3 lg:flex-row lg:items-end">
                                <div class="flex-1">
                                    <label class="mb-1 block text-xs font-medium text-emerald-900">Asignar formulario existente</label>
                                    <select v-model="selectedExistingForm" class="w-full rounded border px-3 py-2 text-sm">
                                        <option value="">Seleccionar formulario guardado...</option>
                                        <optgroup label="Formularios globales">
                                            <option v-for="option in globalFormOptions" :key="option.id" :value="option.id">
                                                {{ option.label }} / {{ option.detail }}
                                            </option>
                                        </optgroup>
                                        <optgroup :label="templateOptgroupLabel">
                                            <option v-for="option in visibleTemplateFormOptions" :key="option.id" :value="option.id">
                                                {{ templateOptionLabel(option) }}
                                            </option>
                                        </optgroup>
                                    </select>
                                    <div class="mt-2 flex flex-wrap items-center gap-3 text-xs text-emerald-800">
                                        <span v-if="hasTemplateSuggestions">
                                            {{ suggestedTemplateFormOptions.length }} plantillas sugeridas por nombre, task_key o contexto.
                                        </span>
                                        <span v-else>
                                            No se encontraron plantillas parecidas; se muestran todas.
                                        </span>
                                        <label v-if="hasTemplateSuggestions && templateFormOptions.length > suggestedTemplateFormOptions.length" class="inline-flex items-center gap-1">
                                            <input v-model="showAllTemplateOptions" type="checkbox" class="rounded border-emerald-300">
                                            Mostrar todas
                                        </label>
                                    </div>
                                </div>
                                <button
                                    type="button"
                                    class="rounded bg-emerald-600 px-3 py-2 text-xs font-medium text-white disabled:opacity-50"
                                    :disabled="!selectedExistingForm"
                                    @click="applySelectedExistingForm"
                                >
                                    Asignar
                                </button>
                            </div>
                            <p class="mt-2 text-xs text-emerald-800">
                                Formularios globales asignan el task_key y quitan el custom schema. Los handlers fijos no aparecen aqui porque abren tabs o modales del sistema. Plantillas guardadas copian su schema como formulario custom de esta tarea.
                            </p>
                        </div>

                        <div class="grid gap-3 md:grid-cols-2">
                            <div>
                                <label class="mb-1 block text-xs font-medium text-gray-600">Task key</label>
                                <input v-model="taskForm.task_key" list="task-key-options" class="w-full rounded border px-3 py-2 text-sm" placeholder="blank for none">
                            </div>
                        </div>
                        <label class="flex items-start gap-2 rounded border border-blue-100 bg-blue-50 p-3 text-sm text-blue-900">
                            <input v-model="taskUsesCustomSchema" type="checkbox" class="mt-0.5 rounded border-gray-300">
                            <span>
                                <span class="block font-semibold">Usar formulario custom en esta tarea</span>
                                <span class="block text-xs text-blue-700">Si esta apagado, al guardar se quitara el schema custom y la tarea usara el task_key o handler activo.</span>
                            </span>
                        </label>
                    </template>

                    <datalist id="task-key-options">
                        <option v-for="key in taskKeys" :key="key" :value="key" />
                    </datalist>

                    <div v-if="shouldShowSchemaBuilder" class="rounded border border-gray-200 bg-gray-50 p-3">
                        <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                            <div>
                                <div class="text-sm font-semibold text-gray-900">Constructor de campos</div>
                                <p class="text-xs text-gray-500">Define el modo y los campos del formulario sin editar JSON.</p>
                            </div>
                            <div class="inline-flex rounded border bg-white p-1">
                                <button
                                    type="button"
                                    class="rounded px-3 py-1 text-xs font-medium"
                                    :class="schemaMode === 'single' ? 'bg-blue-600 text-white' : 'text-gray-600 hover:bg-gray-50'"
                                    @click="schemaMode = 'single'"
                                >
                                    Un solo registro
                                </button>
                                <button
                                    type="button"
                                    class="rounded px-3 py-1 text-xs font-medium"
                                    :class="schemaMode === 'registry' ? 'bg-blue-600 text-white' : 'text-gray-600 hover:bg-gray-50'"
                                    @click="schemaMode = 'registry'"
                                >
                                    Lista repetible
                                </button>
                            </div>
                        </div>

                        <div class="mt-3 space-y-3">
                            <div
                                v-for="(field, index) in schemaFields"
                                :key="index"
                                class="rounded border bg-white p-3"
                            >
                                <div class="grid gap-3 md:grid-cols-[1fr_1fr_150px_auto]">
                                    <div>
                                        <label class="mb-1 block text-xs font-medium text-gray-600">Etiqueta</label>
                                        <input v-model="field.label" class="w-full rounded border px-3 py-2 text-sm" placeholder="Medical forms uploaded">
                                    </div>
                                    <div>
                                        <label class="mb-1 block text-xs font-medium text-gray-600">Key</label>
                                        <input v-model="field.key" class="w-full rounded border px-3 py-2 font-mono text-sm" placeholder="medical_forms_uploaded">
                                    </div>
                                    <div>
                                        <label class="mb-1 block text-xs font-medium text-gray-600">Tipo</label>
                                        <select v-model="field.type" class="w-full rounded border px-3 py-2 text-sm">
                                            <option v-for="type in fieldTypes" :key="type" :value="type">{{ type }}</option>
                                        </select>
                                    </div>
                                    <label class="flex items-end gap-2 pb-2 text-sm text-gray-700">
                                        <input v-model="field.required" type="checkbox" class="rounded border-gray-300">
                                        Requerido
                                    </label>
                                </div>

                                <div class="mt-3 grid gap-3 md:grid-cols-2">
                                    <div>
                                        <label class="mb-1 block text-xs font-medium text-gray-600">Ayuda</label>
                                        <input v-model="field.help" class="w-full rounded border px-3 py-2 text-sm" placeholder="Texto breve para el usuario">
                                    </div>
                                    <div v-if="field.type === 'select'">
                                        <label class="mb-1 block text-xs font-medium text-gray-600">Opciones</label>
                                        <input v-model="field.optionsText" class="w-full rounded border px-3 py-2 text-sm" placeholder="Si, No, Pendiente">
                                    </div>
                                </div>

                                <div class="mt-3 flex flex-wrap justify-end gap-2">
                                    <button type="button" class="rounded border px-2 py-1 text-xs text-gray-600 hover:bg-gray-50" @click="moveSchemaField(index, -1)">
                                        Subir
                                    </button>
                                    <button type="button" class="rounded border px-2 py-1 text-xs text-gray-600 hover:bg-gray-50" @click="moveSchemaField(index, 1)">
                                        Bajar
                                    </button>
                                    <button type="button" class="rounded border border-rose-200 px-2 py-1 text-xs text-rose-700 hover:bg-rose-50" @click="removeSchemaField(index)">
                                        Quitar
                                    </button>
                                </div>
                            </div>
                        </div>

                        <div class="mt-3 flex justify-between gap-3">
                            <button type="button" class="rounded border px-3 py-2 text-xs font-medium text-gray-700 hover:bg-white" @click="addSchemaField">
                                Agregar campo
                            </button>
                            <p class="text-xs text-gray-500">El key se normaliza a snake_case al guardar.</p>
                        </div>
                    </div>

                    <details v-if="shouldShowSchemaBuilder" class="rounded border border-gray-200 bg-white p-3" :open="showRawJson" @toggle="showRawJson = $event.target.open">
                        <summary class="cursor-pointer text-sm font-semibold text-gray-700">JSON avanzado</summary>
                        <div class="mt-3 space-y-3">
                            <div class="flex flex-wrap gap-2">
                                <button type="button" class="rounded border px-3 py-1 text-xs text-gray-700 hover:bg-gray-50" @click="syncJsonFromBuilder">
                                    Generar JSON desde constructor
                                </button>
                                <button type="button" class="rounded border px-3 py-1 text-xs text-gray-700 hover:bg-gray-50" @click="loadBuilderFromJson">
                                    Aplicar JSON al constructor
                                </button>
                            </div>
                            <label class="block text-xs font-medium text-gray-600">{{ currentSchemaJsonLabel }}</label>
                            <textarea
                                v-model="jsonText"
                                rows="12"
                                class="w-full rounded border px-3 py-2 font-mono text-xs"
                                placeholder='{"mode":"single","fields":[{"key":"field_key","label":"Field label","type":"text"}]}'
                            ></textarea>
                            <p v-if="jsonError" class="text-sm text-red-600">{{ jsonError }}</p>
                            <p v-else class="text-xs text-gray-500">Los cambios directos en JSON deben aplicarse al constructor antes de guardar.</p>
                        </div>
                    </details>
                </div>

                <div class="flex items-center justify-end gap-2 border-t px-5 py-4">
                    <button type="button" class="rounded border px-4 py-2 text-sm text-gray-700 hover:bg-gray-50" @click="closeEditor">
                        Cancelar
                    </button>
                    <button
                        type="button"
                        class="rounded bg-blue-600 px-4 py-2 text-sm font-medium text-white disabled:opacity-60"
                        :disabled="schemaForm.processing || templateForm.processing || taskForm.processing"
                        @click="submitEditor"
                    >
                        Guardar
                    </button>
                </div>
            </div>
        </div>
    </PathfinderLayout>
</template>
