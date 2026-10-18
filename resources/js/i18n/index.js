import { createI18n } from 'vue-i18n'
import en from './en.js'
import ar from './ar.js'

// Read saved language from localStorage (default: 'en')
const savedLocale = localStorage.getItem('fv_locale') || 'en'

const i18n = createI18n({
    legacy: false,          // Use Composition API mode
    locale: savedLocale,
    fallbackLocale: 'en',
    messages: { en, ar },
})

export default i18n

// Helper: call this when switching language
export function setLocale(locale) {
    i18n.global.locale.value = locale
    localStorage.setItem('fv_locale', locale)

    // Set HTML dir and lang attributes for RTL support
    document.documentElement.setAttribute('dir',  locale === 'ar' ? 'rtl' : 'ltr')
    document.documentElement.setAttribute('lang', locale)
}
