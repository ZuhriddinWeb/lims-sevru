import { createI18n } from 'vue-i18n'
import ru from './locales/ru'
import uz from './locales/uz'
import en from './locales/en'

export const SUPPORTED_LOCALES = [
  { code: 'ru', label: 'Русский', short: 'RU' },
  { code: 'uz', label: "O‘zbekcha", short: 'UZ' },
  { code: 'en', label: 'English',  short: 'EN' },
]

const saved = localStorage.getItem('lims.locale')

export const i18n = createI18n({
  legacy: false,
  globalInjection: true,
  locale: saved && ['ru', 'uz', 'en'].includes(saved) ? saved : 'ru',
  fallbackLocale: 'ru',
  messages: { ru, uz, en },
  numberFormats: {
    ru: { decimal: { style: 'decimal', maximumFractionDigits: 4 } },
    uz: { decimal: { style: 'decimal', maximumFractionDigits: 4 } },
    en: { decimal: { style: 'decimal', maximumFractionDigits: 4 } },
  },
})

export function setI18nLocale(locale) {
  i18n.global.locale.value = locale
  document.documentElement.lang = locale
  localStorage.setItem('lims.locale', locale)
}

// Ko'p tilli model maydonini joriy tilga mos tanlash: name_ru / name_uz / name_en
export function localized(entity, base = 'name', locale = i18n.global.locale.value) {
  if (!entity) return ''
  return entity[`${base}_${locale}`] || entity[`${base}_ru`] || entity[base] || ''
}
