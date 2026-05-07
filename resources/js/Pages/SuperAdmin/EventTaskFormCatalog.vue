<script setup>
import PathfinderLayout from '@/Layouts/PathfinderLayout.vue'
import { router, useForm } from '@inertiajs/vue3'
import { computed, reactive, ref, watch } from 'vue'
import { useLocale } from '@/Composables/useLocale'

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

const { tr } = useLocale()

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
    if (editingType.value === 'schema_create') return tr('Crear formulario global', 'Create global form')
    if (editingType.value === 'schema') return tr('Editar formulario global', 'Edit global form')
    if (editingType.value === 'template') return tr('Editar plantilla de tarea', 'Edit task template')
    if (editingType.value === 'task') return tr('Editar formulario activo', 'Edit active form')
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
        reasons.push(tr('mismo task_key', 'same task_key'))
    }

    if (taskTitle && optionTitle && taskTitle === optionTitle) {
        score += 100
        reasons.push(tr('mismo nombre', 'same name'))
    } else if (taskTitle && optionTitle && (taskTitle.includes(optionTitle) || optionTitle.includes(taskTitle))) {
        score += 55
        reasons.push(tr('nombre parecido', 'similar name'))
    }

    const taskTokens = titleTokens(taskTitle)
    const optionTokens = titleTokens(optionTitle)
    if (taskTokens.length && optionTokens.length) {
        const optionTokenSet = new Set(optionTokens)
        const commonCount = taskTokens.filter((token) => optionTokenSet.has(token)).length
        const overlap = commonCount / Math.max(Math.min(taskTokens.length, optionTokens.length), 1)
        if (overlap >= 0.5) {
            score += Math.round(overlap * 40)
            reasons.push(tr('palabras parecidas', 'similar words'))
        }
    }

    if (taskEventType && optionEventType && taskEventType === optionEventType) {
        score += 10
        reasons.push(tr('mismo tipo de evento', 'same event type'))
    }

    if (taskClubId && optionClubId && taskClubId === optionClubId) {
        score += 8
        reasons.push(tr('mismo club', 'same club'))
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
    if (!hasTemplateSuggestions.value) return tr('Plantillas guardadas', 'Saved templates')
    return showAllTemplateOptions.value ? tr('Todas las plantillas guardadas', 'All saved templates') : tr('Plantillas sugeridas', 'Suggested templates')
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
            jsonError.value = tr('El JSON debe ser un objeto.', 'JSON must be an object.')
            return undefined
        }
        if (!Array.isArray(parsed.fields)) {
            jsonError.value = tr('El JSON debe incluir fields como arreglo.', 'JSON must include fields as an array.')
            return undefined
        }
        return parsed
    } catch (error) {
        jsonError.value = error?.message || tr('JSON inválido.', 'Invalid JSON.')
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
        jsonError.value = tr('Agrega al menos un campo valido.', 'Add at least one valid field.')
        return null
    }

    const keys = new Set()
    for (const field of normalized) {
        if (keys.has(field.key)) {
            jsonError.value = tr(`Key duplicado: ${field.key}`, `Duplicate key: ${field.key}`)
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
        <template #title>{{ tr('Formularios de tareas', 'Task Forms') }}</template>

        <div class="space-y-5">
            <div class="rounded-lg border bg-white p-4 shadow-sm">
                <div class="grid gap-3 lg:grid-cols-[1fr_220px_220px]">
                    <div>
                        <label class="mb-1 block text-xs font-medium text-gray-600">{{ tr('Buscar', 'Search') }}</label>
                        <input
                            v-model="filters.search"
                            type="search"
                            class="w-full rounded border px-3 py-2 text-sm"
                            :placeholder="tr('Tarea, evento, key o descripción', 'Task, event, key, or description')"
                        >
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-gray-600">{{ tr('Club', 'Club') }}</label>
                        <select v-model="filters.club_id" class="w-full rounded border px-3 py-2 text-sm">
                            <option value="">{{ tr('Todos', 'All') }}</option>
                            <option v-for="club in clubs" :key="club.id" :value="club.id">
                                {{ club.club_name }}{{ club.status !== 'active' ? ` (${tr('inactivo', 'inactive')})` : '' }}
                            </option>
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-gray-600">{{ tr('Tipo de evento', 'Event type') }}</label>
                        <select v-model="filters.event_type" class="w-full rounded border px-3 py-2 text-sm">
                            <option value="">{{ tr('Todos', 'All') }}</option>
                            <option v-for="type in eventTypes" :key="type" :value="type">{{ type }}</option>
                        </select>
                    </div>
                </div>
            </div>

            <section class="rounded-lg border border-blue-100 bg-blue-50 p-4 text-sm text-blue-900">
                <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                    <div>
                        <h2 class="text-sm font-semibold text-blue-950">{{ tr('Uso rápido', 'Quick Use') }}</h2>
                        <p class="mt-1 max-w-3xl text-xs text-blue-800">
                            {{ tr('Usa esta pantalla para revisar qué acción abrirá cada tarea de evento. Los handlers fijos son redirects o módulos del sistema; los formularios globales son schemas reutilizables que abren el modal de formulario.', 'Use this screen to review which action each event task will open. Fixed handlers are redirects or system modules; global forms are reusable schemas that open the form modal.') }}
                        </p>
                    </div>
                    <div class="inline-flex w-fit rounded-full border border-blue-200 bg-white px-3 py-1 text-xs font-semibold text-blue-700">
                        {{ tr('Custom form tiene prioridad', 'Custom form has priority') }}
                    </div>
                </div>

                <div class="mt-4 grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                    <div class="rounded border border-blue-100 bg-white/70 p-3">
                        <div class="font-semibold text-blue-950">{{ tr('Handlers fijos', 'Fixed handlers') }}</div>
                        <p class="mt-1 text-xs text-blue-800">
                            {{ tr('Participantes, documentos y transporte abren pantallas existentes. No se editan como JSON.', 'Participants, documents, and transportation open existing screens. They are not edited as JSON.') }}
                        </p>
                    </div>
                    <div class="rounded border border-blue-100 bg-white/70 p-3">
                        <div class="font-semibold text-blue-950">{{ tr('Formularios globales', 'Global forms') }}</div>
                        <p class="mt-1 text-xs text-blue-800">
                            {{ tr('Edita schemas reutilizables por', 'Edit reusable schemas by') }} <span class="font-mono">task_key</span>, {{ tr('como emergency_contacts o camp_reservation.', 'such as emergency_contacts or camp_reservation.') }}
                        </p>
                    </div>
                    <div class="rounded border border-blue-100 bg-white/70 p-3">
                        <div class="font-semibold text-blue-950">{{ tr('Plantillas guardadas', 'Saved templates') }}</div>
                        <p class="mt-1 text-xs text-blue-800">
                            {{ tr('Cambia el catálogo que se copia a nuevos eventos según club y tipo de evento.', 'Change the catalog copied into new events by club and event type.') }}
                        </p>
                    </div>
                    <div class="rounded border border-blue-100 bg-white/70 p-3">
                        <div class="font-semibold text-blue-950">{{ tr('Tareas de eventos', 'Event tasks') }}</div>
                        <p class="mt-1 text-xs text-blue-800">
                            {{ tr('Revisa el handler activo de una tarea real. Si aparece Documents tab pero debe abrir formulario, agrega o corrige el schema custom.', 'Review the active handler for a real task. If Documents tab appears but it should open a form, add or correct the custom schema.') }}
                        </p>
                    </div>
                </div>

                <ol class="mt-4 grid gap-2 text-xs text-blue-800 md:grid-cols-3">
                    <li><span class="font-semibold text-blue-950">1.</span> {{ tr('Filtra por club, tipo de evento o título de tarea.', 'Filter by club, event type, or task title.') }}</li>
                    <li><span class="font-semibold text-blue-950">2.</span> {{ tr('Abre Editar en la fila que necesitas corregir.', 'Open Edit on the row you need to correct.') }}</li>
                    <li><span class="font-semibold text-blue-950">3.</span> {{ tr('Usa handlers fijos para módulos existentes; usa formularios globales o custom cuando necesitas capturar datos nuevos.', 'Use fixed handlers for existing modules; use global or custom forms when you need to capture new data.') }}</li>
                </ol>
            </section>

            <section class="rounded-lg border bg-white shadow-sm">
                <div class="border-b px-4 py-3">
                    <h2 class="text-sm font-semibold text-gray-900">{{ tr('Handlers fijos', 'Fixed handlers') }}</h2>
                    <p class="text-xs text-gray-500">{{ tr('Acciones del sistema que no usan schema_json. Sirven para tareas que deben abrir una pantalla existente.', 'System actions that do not use schema_json. They are used for tasks that must open an existing screen.') }}</p>
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
                            <span class="rounded-full border border-blue-200 bg-blue-50 px-2 py-0.5 text-[11px] font-semibold text-blue-700">{{ tr('Fijo', 'Fixed') }}</span>
                        </div>
                        <p class="mt-2 text-xs text-gray-600">{{ handler.description }}</p>
                        <div class="mt-3 space-y-2 text-xs text-gray-600">
                            <div>
                                <span class="font-semibold text-gray-800">{{ tr('Task keys:', 'Task keys:') }}</span>
                                <span class="font-mono">{{ handler.task_keys?.length ? handler.task_keys.join(', ') : 'keywords' }}</span>
                            </div>
                            <div>
                                <span class="font-semibold text-gray-800">{{ tr('Keywords:', 'Keywords:') }}</span>
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
                        <h2 class="text-sm font-semibold text-gray-900">{{ tr('Formularios globales reutilizables', 'Reusable global forms') }}</h2>
                        <p class="text-xs text-gray-500">{{ tr('Schemas reutilizables por task_key. No son redirects a tabs existentes.', 'Reusable schemas by task_key. They are not redirects to existing tabs.') }}</p>
                    </div>
                    <button
                        type="button"
                        class="rounded bg-blue-600 px-3 py-2 text-xs font-medium text-white hover:bg-blue-700"
                        @click="openCreateSchema"
                    >
                        {{ tr('Nuevo formulario global', 'New global form') }}
                    </button>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-gray-50 text-left text-gray-600">
                            <tr>
                                <th class="px-4 py-3 font-medium">{{ tr('Key', 'Key') }}</th>
                                <th class="px-4 py-3 font-medium">{{ tr('Nombre', 'Name') }}</th>
                                <th class="px-4 py-3 font-medium">{{ tr('Campos', 'Fields') }}</th>
                                <th class="px-4 py-3 font-medium">{{ tr('Actualizado', 'Updated') }}</th>
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
                                        {{ tr('Lo cubre', 'Covered by') }} {{ schema.fixed_handler?.target }}
                                    </span>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="font-medium text-gray-900">{{ schema.name }}</div>
                                    <div class="max-w-xl text-xs text-gray-500">{{ schema.description || '—' }}</div>
                                    <div v-if="schema.is_shadowed_by_fixed_handler" class="mt-1 max-w-xl text-xs text-amber-700">
                                        {{ tr('Esta key abre un handler fijo, por eso no aparece en "Asignar formulario existente".', 'This key opens a fixed handler, so it does not appear in "Assign existing form".') }}
                                    </div>
                                </td>
                                <td class="px-4 py-3 text-gray-700">{{ schema.field_count }} / {{ schema.mode || 'single' }}</td>
                                <td class="px-4 py-3 text-gray-500">{{ schema.updated_at || '—' }}</td>
                                <td class="px-4 py-3 text-right">
                                    <button type="button" class="rounded border px-3 py-1 text-xs font-medium text-gray-700 hover:bg-gray-50" @click="editSchema(schema)">
                                        {{ tr('Editar', 'Edit') }}
                                    </button>
                                </td>
                            </tr>
                            <tr v-if="!schemas.length">
                                <td colspan="5" class="px-4 py-8 text-center text-gray-500">{{ tr('No hay formularios globales creados.', 'No global forms have been created.') }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="rounded-lg border bg-white shadow-sm">
                <div class="flex items-center justify-between border-b px-4 py-3">
                    <div>
                        <h2 class="text-sm font-semibold text-gray-900">{{ tr('Plantillas guardadas', 'Saved templates') }}</h2>
                        <p class="text-xs text-gray-500">{{ tr('Catálogo que se reutiliza cuando se crean tareas para eventos.', 'Catalog reused when event tasks are created.') }}</p>
                    </div>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-gray-50 text-left text-gray-600">
                            <tr>
                                <th class="px-4 py-3 font-medium">{{ tr('Tarea', 'Task') }}</th>
                                <th class="px-4 py-3 font-medium">{{ tr('Club / evento', 'Club / event') }}</th>
                                <th class="px-4 py-3 font-medium">{{ tr('Form', 'Form') }}</th>
                                <th class="px-4 py-3 font-medium">{{ tr('Estado', 'Status') }}</th>
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
                                    <div>{{ template.field_count }} {{ tr('campos', 'fields') }}</div>
                                    <div class="text-xs text-gray-500">{{ template.form_mode || tr('sin schema custom', 'no custom schema') }}</div>
                                </td>
                                <td class="px-4 py-3">
                                    <span class="inline-flex rounded-full border px-2 py-0.5 text-xs font-semibold" :class="template.is_active ? handlerClass('green') : handlerClass('red')">
                                        {{ template.is_active ? tr('Activa', 'Active') : tr('Inactiva', 'Inactive') }}
                                    </span>
                                    <div class="mt-1 text-xs text-gray-500">{{ template.is_custom ? tr('Custom', 'Custom') : tr('Inferida', 'Inferred') }}</div>
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <button type="button" class="rounded border px-3 py-1 text-xs font-medium text-gray-700 hover:bg-gray-50" @click="editTemplate(template)">
                                        {{ tr('Editar', 'Edit') }}
                                    </button>
                                </td>
                            </tr>
                            <tr v-if="!templates.data.length">
                                <td colspan="5" class="px-4 py-8 text-center text-gray-500">{{ tr('No hay plantillas con estos filtros.', 'No templates match these filters.') }}</td>
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
                        <h2 class="text-sm font-semibold text-gray-900">{{ tr('Tareas de eventos', 'Event tasks') }}</h2>
                        <p class="text-xs text-gray-500">{{ tr('Handler activo para cada tarea real. Custom form tiene prioridad sobre handlers fijos.', 'Active handler for each real task. Custom form has priority over fixed handlers.') }}</p>
                    </div>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-gray-50 text-left text-gray-600">
                            <tr>
                                <th class="px-4 py-3 font-medium">{{ tr('Tarea', 'Task') }}</th>
                                <th class="px-4 py-3 font-medium">{{ tr('Evento', 'Event') }}</th>
                                <th class="px-4 py-3 font-medium">{{ tr('Activo', 'Active') }}</th>
                                <th class="px-4 py-3 font-medium">{{ tr('Schema custom', 'Custom schema') }}</th>
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
                                    <div class="mt-1 text-xs text-gray-500">{{ task.assignments_count }} {{ tr('asignaciones', 'assignments') }}</div>
                                </td>
                                <td class="px-4 py-3 text-gray-700">
                                    <div>{{ task.custom_field_count }} {{ tr('campos', 'fields') }}</div>
                                    <div class="text-xs text-gray-500">{{ task.custom_form_mode || tr('sin custom form', 'no custom form') }}</div>
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <button type="button" class="rounded border px-3 py-1 text-xs font-medium text-gray-700 hover:bg-gray-50" @click="editTask(task)">
                                        {{ tr('Editar', 'Edit') }}
                                    </button>
                                </td>
                            </tr>
                            <tr v-if="!tasks.data.length">
                                <td colspan="5" class="px-4 py-8 text-center text-gray-500">{{ tr('No hay tareas con estos filtros.', 'No tasks match these filters.') }}</td>
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
                    <button type="button" class="rounded px-2 py-1 text-sm text-gray-500 hover:bg-gray-100" @click="closeEditor">{{ tr('Cerrar', 'Close') }}</button>
                </div>

                <div class="space-y-4 px-5 py-4">
                    <template v-if="editingType === 'schema' || editingType === 'schema_create'">
                        <div class="grid gap-3 md:grid-cols-2">
                            <div>
                                <label class="mb-1 block text-xs font-medium text-gray-600">{{ tr('Nombre', 'Name') }}</label>
                                <input v-model="schemaForm.name" class="w-full rounded border px-3 py-2 text-sm">
                                <p v-if="schemaForm.errors.name" class="mt-1 text-xs text-red-600">{{ schemaForm.errors.name }}</p>
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-medium text-gray-600">{{ tr('Key', 'Key') }}</label>
                                <input
                                    v-if="editingType === 'schema_create'"
                                    v-model="schemaForm.key"
                                    class="w-full rounded border px-3 py-2 font-mono text-sm"
                                    placeholder="medical_forms"
                                >
                                <input v-else :value="editingRecord?.key" disabled class="w-full rounded border bg-gray-50 px-3 py-2 font-mono text-sm text-gray-500">
                                <p v-if="editingType === 'schema_create'" class="mt-1 text-xs text-gray-500">
                                    {{ tr('No uses keys reservadas por handlers fijos como permission_slips, transportation_plan o finalize_attendee_list.', 'Do not use keys reserved by fixed handlers such as permission_slips, transportation_plan, or finalize_attendee_list.') }}
                                </p>
                                <p v-if="schemaForm.errors.key" class="mt-1 text-xs text-red-600">{{ schemaForm.errors.key }}</p>
                            </div>
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-gray-600">{{ tr('Descripción', 'Description') }}</label>
                            <textarea v-model="schemaForm.description" rows="2" class="w-full rounded border px-3 py-2 text-sm"></textarea>
                            <p v-if="schemaForm.errors.description" class="mt-1 text-xs text-red-600">{{ schemaForm.errors.description }}</p>
                        </div>
                    </template>

                    <template v-else-if="editingType === 'template'">
                        <div class="grid gap-3 md:grid-cols-2">
                            <div>
                                <label class="mb-1 block text-xs font-medium text-gray-600">{{ tr('Título', 'Title') }}</label>
                                <input v-model="templateForm.title" class="w-full rounded border px-3 py-2 text-sm">
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-medium text-gray-600">{{ tr('Event type', 'Event type') }}</label>
                                <input v-model="templateForm.event_type" class="w-full rounded border px-3 py-2 text-sm">
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-medium text-gray-600">{{ tr('Task key', 'Task key') }}</label>
                                <input v-model="templateForm.task_key" list="task-key-options" class="w-full rounded border px-3 py-2 text-sm" :placeholder="tr('custom or built-in key', 'custom or built-in key')">
                            </div>
                            <label class="flex items-end gap-2 pb-2 text-sm text-gray-700">
                                <input v-model="templateForm.is_active" type="checkbox" class="rounded border-gray-300">
                                {{ tr('Activa para nuevos eventos', 'Active for new events') }}
                            </label>
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-gray-600">{{ tr('Descripción', 'Description') }}</label>
                            <textarea v-model="templateForm.description" rows="2" class="w-full rounded border px-3 py-2 text-sm"></textarea>
                        </div>
                        <label class="flex items-start gap-2 rounded border border-blue-100 bg-blue-50 p-3 text-sm text-blue-900">
                            <input v-model="templateUsesCustomSchema" type="checkbox" class="mt-0.5 rounded border-gray-300">
                            <span>
                                <span class="block font-semibold">{{ tr('Usar formulario custom en esta plantilla', 'Use custom form in this template') }}</span>
                                <span class="block text-xs text-blue-700">{{ tr('Si esta activo, nuevos eventos copiaran este schema a la tarea. Si esta apagado, la tarea dependera de su task_key o handler.', 'If enabled, new events will copy this schema to the task. If disabled, the task will depend on its task_key or handler.') }}</span>
                            </span>
                        </label>
                    </template>

                    <template v-else>
                        <div class="rounded border border-emerald-100 bg-emerald-50 p-3">
                            <div class="flex flex-col gap-3 lg:flex-row lg:items-end">
                                <div class="flex-1">
                                    <label class="mb-1 block text-xs font-medium text-emerald-900">{{ tr('Asignar formulario existente', 'Assign existing form') }}</label>
                                    <select v-model="selectedExistingForm" class="w-full rounded border px-3 py-2 text-sm">
                                        <option value="">{{ tr('Seleccionar formulario guardado...', 'Select saved form...') }}</option>
                                        <optgroup :label="tr('Formularios globales', 'Global forms')">
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
                                            {{ suggestedTemplateFormOptions.length }} {{ tr('plantillas sugeridas por nombre, task_key o contexto.', 'templates suggested by name, task_key, or context.') }}
                                        </span>
                                        <span v-else>
                                            {{ tr('No se encontraron plantillas parecidas; se muestran todas.', 'No similar templates were found; showing all.') }}
                                        </span>
                                        <label v-if="hasTemplateSuggestions && templateFormOptions.length > suggestedTemplateFormOptions.length" class="inline-flex items-center gap-1">
                                            <input v-model="showAllTemplateOptions" type="checkbox" class="rounded border-emerald-300">
                                            {{ tr('Mostrar todas', 'Show all') }}
                                        </label>
                                    </div>
                                </div>
                                <button
                                    type="button"
                                    class="rounded bg-emerald-600 px-3 py-2 text-xs font-medium text-white disabled:opacity-50"
                                    :disabled="!selectedExistingForm"
                                    @click="applySelectedExistingForm"
                                >
                                    {{ tr('Asignar', 'Assign') }}
                                </button>
                            </div>
                            <p class="mt-2 text-xs text-emerald-800">
                                {{ tr('Formularios globales asignan el task_key y quitan el custom schema. Los handlers fijos no aparecen aqui porque abren tabs o modales del sistema. Plantillas guardadas copian su schema como formulario custom de esta tarea.', 'Global forms assign the task_key and remove the custom schema. Fixed handlers do not appear here because they open system tabs or modals. Saved templates copy their schema as this task custom form.') }}
                            </p>
                        </div>

                        <div class="grid gap-3 md:grid-cols-2">
                            <div>
                                <label class="mb-1 block text-xs font-medium text-gray-600">{{ tr('Task key', 'Task key') }}</label>
                                <input v-model="taskForm.task_key" list="task-key-options" class="w-full rounded border px-3 py-2 text-sm" :placeholder="tr('blank for none', 'blank for none')">
                            </div>
                        </div>
                        <label class="flex items-start gap-2 rounded border border-blue-100 bg-blue-50 p-3 text-sm text-blue-900">
                            <input v-model="taskUsesCustomSchema" type="checkbox" class="mt-0.5 rounded border-gray-300">
                            <span>
                                <span class="block font-semibold">{{ tr('Usar formulario custom en esta tarea', 'Use custom form in this task') }}</span>
                                <span class="block text-xs text-blue-700">{{ tr('Si esta apagado, al guardar se quitara el schema custom y la tarea usara el task_key o handler activo.', 'If disabled, saving will remove the custom schema and the task will use the task_key or active handler.') }}</span>
                            </span>
                        </label>
                    </template>

                    <datalist id="task-key-options">
                        <option v-for="key in taskKeys" :key="key" :value="key" />
                    </datalist>

                    <div v-if="shouldShowSchemaBuilder" class="rounded border border-gray-200 bg-gray-50 p-3">
                        <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                            <div>
                                <div class="text-sm font-semibold text-gray-900">{{ tr('Constructor de campos', 'Field builder') }}</div>
                                <p class="text-xs text-gray-500">{{ tr('Define el modo y los campos del formulario sin editar JSON.', 'Define the form mode and fields without editing JSON.') }}</p>
                            </div>
                            <div class="inline-flex rounded border bg-white p-1">
                                <button
                                    type="button"
                                    class="rounded px-3 py-1 text-xs font-medium"
                                    :class="schemaMode === 'single' ? 'bg-blue-600 text-white' : 'text-gray-600 hover:bg-gray-50'"
                                    @click="schemaMode = 'single'"
                                >
                                    {{ tr('Un solo registro', 'Single record') }}
                                </button>
                                <button
                                    type="button"
                                    class="rounded px-3 py-1 text-xs font-medium"
                                    :class="schemaMode === 'registry' ? 'bg-blue-600 text-white' : 'text-gray-600 hover:bg-gray-50'"
                                    @click="schemaMode = 'registry'"
                                >
                                    {{ tr('Lista repetible', 'Repeatable list') }}
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
                                        <label class="mb-1 block text-xs font-medium text-gray-600">{{ tr('Etiqueta', 'Label') }}</label>
                                        <input v-model="field.label" class="w-full rounded border px-3 py-2 text-sm" :placeholder="tr('Medical forms uploaded', 'Medical forms uploaded')">
                                    </div>
                                    <div>
                                        <label class="mb-1 block text-xs font-medium text-gray-600">{{ tr('Key', 'Key') }}</label>
                                        <input v-model="field.key" class="w-full rounded border px-3 py-2 font-mono text-sm" :placeholder="tr('medical_forms_uploaded', 'medical_forms_uploaded')">
                                    </div>
                                    <div>
                                        <label class="mb-1 block text-xs font-medium text-gray-600">{{ tr('Tipo', 'Type') }}</label>
                                        <select v-model="field.type" class="w-full rounded border px-3 py-2 text-sm">
                                            <option v-for="type in fieldTypes" :key="type" :value="type">{{ type }}</option>
                                        </select>
                                    </div>
                                    <label class="flex items-end gap-2 pb-2 text-sm text-gray-700">
                                        <input v-model="field.required" type="checkbox" class="rounded border-gray-300">
                                        {{ tr('Requerido', 'Required') }}
                                    </label>
                                </div>

                                <div class="mt-3 grid gap-3 md:grid-cols-2">
                                    <div>
                                        <label class="mb-1 block text-xs font-medium text-gray-600">{{ tr('Ayuda', 'Help') }}</label>
                                        <input v-model="field.help" class="w-full rounded border px-3 py-2 text-sm" :placeholder="tr('Texto breve para el usuario', 'Short text for the user')">
                                    </div>
                                    <div v-if="field.type === 'select'">
                                        <label class="mb-1 block text-xs font-medium text-gray-600">{{ tr('Opciones', 'Options') }}</label>
                                        <input v-model="field.optionsText" class="w-full rounded border px-3 py-2 text-sm" :placeholder="tr('Si, No, Pendiente', 'Yes, No, Pending')">
                                    </div>
                                </div>

                                <div class="mt-3 flex flex-wrap justify-end gap-2">
                                    <button type="button" class="rounded border px-2 py-1 text-xs text-gray-600 hover:bg-gray-50" @click="moveSchemaField(index, -1)">
                                        {{ tr('Subir', 'Move up') }}
                                    </button>
                                    <button type="button" class="rounded border px-2 py-1 text-xs text-gray-600 hover:bg-gray-50" @click="moveSchemaField(index, 1)">
                                        {{ tr('Bajar', 'Move down') }}
                                    </button>
                                    <button type="button" class="rounded border border-rose-200 px-2 py-1 text-xs text-rose-700 hover:bg-rose-50" @click="removeSchemaField(index)">
                                        {{ tr('Quitar', 'Remove') }}
                                    </button>
                                </div>
                            </div>
                        </div>

                        <div class="mt-3 flex justify-between gap-3">
                            <button type="button" class="rounded border px-3 py-2 text-xs font-medium text-gray-700 hover:bg-white" @click="addSchemaField">
                                {{ tr('Agregar campo', 'Add field') }}
                            </button>
                            <p class="text-xs text-gray-500">{{ tr('El key se normaliza a snake_case al guardar.', 'The key is normalized to snake_case when saved.') }}</p>
                        </div>
                    </div>

                    <details v-if="shouldShowSchemaBuilder" class="rounded border border-gray-200 bg-white p-3" :open="showRawJson" @toggle="showRawJson = $event.target.open">
                        <summary class="cursor-pointer text-sm font-semibold text-gray-700">{{ tr('JSON avanzado', 'Advanced JSON') }}</summary>
                        <div class="mt-3 space-y-3">
                            <div class="flex flex-wrap gap-2">
                                <button type="button" class="rounded border px-3 py-1 text-xs text-gray-700 hover:bg-gray-50" @click="syncJsonFromBuilder">
                                    {{ tr('Generar JSON desde constructor', 'Generate JSON from builder') }}
                                </button>
                                <button type="button" class="rounded border px-3 py-1 text-xs text-gray-700 hover:bg-gray-50" @click="loadBuilderFromJson">
                                    {{ tr('Aplicar JSON al constructor', 'Apply JSON to builder') }}
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
                            <p v-else class="text-xs text-gray-500">{{ tr('Los cambios directos en JSON deben aplicarse al constructor antes de guardar.', 'Direct JSON changes must be applied to the builder before saving.') }}</p>
                        </div>
                    </details>
                </div>

                <div class="flex items-center justify-end gap-2 border-t px-5 py-4">
                    <button type="button" class="rounded border px-4 py-2 text-sm text-gray-700 hover:bg-gray-50" @click="closeEditor">
                        {{ tr('Cancelar', 'Cancel') }}
                    </button>
                    <button
                        type="button"
                        class="rounded bg-blue-600 px-4 py-2 text-sm font-medium text-white disabled:opacity-60"
                        :disabled="schemaForm.processing || templateForm.processing || taskForm.processing"
                        @click="submitEditor"
                    >
                        {{ tr('Guardar', 'Save') }}
                    </button>
                </div>
            </div>
        </div>
    </PathfinderLayout>
</template>
