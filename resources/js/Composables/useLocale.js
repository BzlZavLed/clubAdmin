import { ref } from 'vue'
import { generalTranslations } from '@/i18n/general'

const STORAGE_KEY = 'club_portal_locale'
const locale = ref('es')
let initialized = false

const syncDocumentLocale = () => {
    if (typeof document !== 'undefined') {
        document.documentElement.lang = locale.value
    }
}

const initLocale = () => {
    if (initialized || typeof window === 'undefined') return
    initialized = true
    const saved = window.localStorage.getItem(STORAGE_KEY)
    if (saved === 'es' || saved === 'en') {
        locale.value = saved
    } else {
        const browserLocale = window.navigator?.language?.toLowerCase?.() || ''
        locale.value = browserLocale.startsWith('en') ? 'en' : 'es'
    }
    syncDocumentLocale()
}

const setLocale = (value) => {
    initLocale()
    const next = value === 'en' ? 'en' : 'es'
    locale.value = next
    syncDocumentLocale()
    if (typeof window !== 'undefined') {
        window.localStorage.setItem(STORAGE_KEY, next)
        window.dispatchEvent(new CustomEvent('club-portal-locale-changed', { detail: next }))
    }
}

export function useLocale() {
    initLocale()

    const tr = (esText, enText) => (locale.value === 'en' ? enText : esText)
    const t = (key, replacements = {}) => {
        const entry = generalTranslations[key]
        let value = entry?.[locale.value] || entry?.es || key

        Object.entries(replacements).forEach(([placeholder, replacement]) => {
            value = value.replaceAll(`:${placeholder}`, replacement ?? '')
        })

        return value
    }
    const toggleLocale = () => setLocale(locale.value === 'en' ? 'es' : 'en')

    return {
        locale,
        setLocale,
        toggleLocale,
        t,
        tr,
    }
}
