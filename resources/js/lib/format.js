import { i18n } from '@/i18n'

const LOCALE_MAP = { ru: 'ru-RU', uz: 'uz-UZ', en: 'en-GB' }

function intl() {
  return LOCALE_MAP[i18n.global.locale.value] || 'ru-RU'
}

export function formatDate(value) {
  if (!value) return '—'
  const d = new Date(value)
  if (isNaN(d)) return '—'
  return d.toLocaleDateString(intl(), { day: '2-digit', month: '2-digit', year: 'numeric' })
}

export function formatDateTime(value) {
  if (!value) return '—'
  const d = new Date(value)
  if (isNaN(d)) return '—'
  return d.toLocaleString(intl(), {
    day: '2-digit', month: '2-digit', year: 'numeric',
    hour: '2-digit', minute: '2-digit',
  })
}

export function formatNumber(value, digits = 4) {
  if (value === null || value === undefined || value === '') return '—'
  const n = Number(value)
  if (isNaN(n)) return '—'
  return n.toLocaleString(intl(), { maximumFractionDigits: digits })
}

export function formatMonth(ym) {
  if (!ym) return '—'
  const [y, m] = ym.split('-')
  const d = new Date(Number(y), Number(m) - 1, 1)
  return d.toLocaleDateString(intl(), { month: 'long', year: 'numeric' })
}

// Oxirgi 12 oy ro'yxati — filtr uchun
export function monthOptions(count = 12) {
  const out = []
  const now = new Date()
  for (let i = 0; i < count; i++) {
    const d = new Date(now.getFullYear(), now.getMonth() - i, 1)
    const value = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`
    out.push({ value, label: formatMonth(value) })
  }
  return out
}

export function todayISO() {
  return new Date().toISOString().slice(0, 10)
}
