import { ref } from 'vue'

const STORAGE_KEY = 'club_portal_locale'
const locale = ref('es')
let initialized = false

const initLocale = () => {
    if (initialized || typeof window === 'undefined') return
    initialized = true
    const saved = window.localStorage.getItem(STORAGE_KEY)
    if (saved === 'es' || saved === 'en') {
        locale.value = saved
    }
}

const setLocale = (value) => {
    initLocale()
    const next = value === 'en' ? 'en' : 'es'
    locale.value = next
    if (typeof window !== 'undefined') {
        window.localStorage.setItem(STORAGE_KEY, next)
        window.dispatchEvent(new CustomEvent('club-portal-locale-changed', { detail: next }))
    }
}

export function useLocale() {
    initLocale()

    const tr = (esText, enText) => (locale.value === 'en' ? enText : esText)
    const toggleLocale = () => setLocale(locale.value === 'en' ? 'es' : 'en')

    return {
        locale,
        setLocale,
        toggleLocale,
        tr,
    }
}

