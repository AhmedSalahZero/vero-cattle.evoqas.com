/**
 * useLocale — Global locale & RTL composable
 * ─────────────────────────────────────────────
 * Single source of truth for language state across the entire app.
 *
 * Priority order for initial locale:
 *   1. localStorage        — the most recent choice the user made
 *                            (toggle writes here instantly)
 *   2. auth.user.language  — DB preference on first-ever visit
 *   3. 'en'                — hard fallback
 *
 * HOW THE TOGGLE WORKS:
 *   AuthenticatedLayout calls setLocale(l) from this composable.
 *   Because locale is a singleton ref shared by ALL components,
 *   every component that called useLocale() reacts instantly —
 *   no page reload, no Inertia re-fetch needed.
 *
 * Usage:
 *   const { locale, isRtl, t, n, setLocale, toggle } = useLocale()
 */

import { ref, computed, watch } from 'vue'
import { usePage } from '@inertiajs/vue3'

// ── Helpers ───────────────────────────────────────────────────────
const STORAGE_KEY = 'vero_locale'

function isValidLocale(lang) {
    return lang === 'en' || lang === 'ar'
}

function applyLocaleToDocument(lang) {
    document.documentElement.setAttribute('lang', lang)
    document.documentElement.setAttribute('dir', lang === 'ar' ? 'rtl' : 'ltr')
}

function resolveInitialLocale() {
    // 1. localStorage — most recent user choice, written by toggle instantly
    const stored = localStorage.getItem(STORAGE_KEY)
    if (isValidLocale(stored)) return stored

    // 2. auth.user.language from Inertia shared props — DB preference
    try {
        const page = usePage()
        const userLang = page.props?.auth?.user?.language
        if (isValidLocale(userLang)) return userLang
    } catch {
        // usePage() not ready at module load — safe to ignore,
        // localStorage already handles persistence after first toggle
    }

    // 3. Hard fallback
    return 'en'
}

// ── Singleton ─────────────────────────────────────────────────────
// ONE shared ref. Every component that calls useLocale() gets this
// exact same ref. When it changes, all of them re-render instantly.
const locale = ref(resolveInitialLocale())

// Apply to <html> immediately on module load
applyLocaleToDocument(locale.value)

// Keep localStorage + <html> in sync on every change
watch(locale, (lang) => {
    localStorage.setItem(STORAGE_KEY, lang)
    applyLocaleToDocument(lang)
})

// ── Export ────────────────────────────────────────────────────────
export function useLocale() {

    const isRtl = computed(() => locale.value === 'ar')

    /** setLocale — switch to a specific language explicitly */
    const setLocale = (lang) => {
        if (!isValidLocale(lang)) return
        locale.value = lang
    }

    /** toggle — flip between en ↔ ar */
    const toggle = () => {
        locale.value = locale.value === 'en' ? 'ar' : 'en'
    }

    /** t — inline fallback string (not yet wired to vue-i18n) */
    const t = (obj, fallback = '') => {
    if (!obj) return fallback
    if (typeof obj === 'object') {
        return locale.value === 'ar' ? (obj.ar || obj.en || fallback) : (obj.en || obj.ar || fallback)
    }
    return fallback
    }

    /**
     * n — locale-aware bilingual field picker
     * n(obj)           → obj.name_en  or  obj.name_ar
     * n(obj, 'address')→ obj.address_en or obj.address_ar
     */
    const n = (obj, field = 'name') => {
        if (!obj) return '—'
        const enKey = `${field}_en`
        const arKey = `${field}_ar`
        if (locale.value === 'ar') {
            return obj[arKey] || obj[enKey] || '—'
        }
        return obj[enKey] || obj[arKey] || '—'
    }

    const today = new Date().toISOString().split('T')[0]

    return { locale, isRtl, setLocale, toggle, t, n, today }
}