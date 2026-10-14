/**
 * Buyurtmachi / proba miqdori moduli uchun umumiy yordamchilar (Vue'siz).
 */

export const UNITS = ['g', 'kg', 'mg', 'ml', 'l']
export const MOVEMENT_KINDS = ['other', 'return', 'dispose', 'correction']
export const STEPS = ['registered', 'assigned', 'analysis', 'review', 'done']

/** Qoldiq holati ranglari */
export const STATE_TONE = {
  ok: 'bg-ok-light text-ok-dark dark:bg-ok-dark/25 dark:text-ok-light',
  low: 'bg-warn-light text-warn-dark dark:bg-warn-dark/25 dark:text-warn-light',
  empty: 'bg-crit-light text-crit-dark dark:bg-crit-dark/25 dark:text-crit-light',
  negative: 'bg-crit text-white',
  unknown: 'bg-surface-sunken text-ink-muted dark:bg-white/5',
}

export function useComma(locale) {
  return locale === 'ru' || locale === 'uz'
}

/** Miqdor: 4 xonagacha, ortiqcha nollarsiz; bo'sh — «—» */
export function formatQty(value, locale) {
  if (value === null || value === undefined || value === '' || Number.isNaN(Number(value))) return '—'
  const s = String(Math.round(Number(value) * 10000) / 10000)
  return useComma(locale) ? s.replace('.', ',') : s
}

/** Tahlil natijasi: ustundagi xona soni bo'yicha (bo'lmasa — o'zicha) */
export function formatValue(value, decimals, locale) {
  if (value === null || value === undefined || value === '') return ''
  const n = Number(String(value).replace(',', '.'))
  if (Number.isNaN(n)) return String(value)
  const s = decimals === null || decimals === undefined || decimals === '' ? String(n) : n.toFixed(Number(decimals))
  return useComma(locale) ? s.replace('.', ',') : s
}

/** Ishorali farq: +0,12 / −0,05 */
export function formatSigned(value, decimals, locale) {
  if (value === null || value === undefined) return '—'
  const s = formatValue(Math.abs(value), decimals, locale)
  return (value > 0 ? '+' : value < 0 ? '−' : '') + s
}

/** Birlik nomi joriy tilda: g → «г» (ru) */
export function unitLabel(unit, t) {
  if (!unit) return ''
  const key = `intake.units.${unit}`
  const v = t(key)
  return v === key ? unit : v
}

/** Ustun nomi interfeys tilida */
export function label(item, locale) {
  if (!item) return ''
  if (locale === 'ru') return item.label_ru || item.label_uz || item.key
  return item.label_uz || item.label_ru || item.key
}

export function templateName(tpl, locale) {
  if (!tpl) return ''
  if (locale === 'ru') return tpl.name_ru || tpl.name_uz || tpl.code
  return tpl.name_uz || tpl.name_ru || tpl.code
}

export function apiErrorMessage(e, fallback) {
  const d = e?.response?.data
  if (d?.errors) {
    const first = Object.values(d.errors).flat()[0]
    if (first) return first
  }
  return d?.message || fallback
}

/* ---------- Davr (sana oralig'i) ---------- */
const pad2 = (n) => String(n).padStart(2, '0')
export const isoDate = (d) => `${d.getFullYear()}-${pad2(d.getMonth() + 1)}-${pad2(d.getDate())}`

/** Davr uchun [dan, gacha] (YYYY-MM-DD, mahalliy sana): today | week (dushanbadan) | month | year; boshqasi — ['', ''] */
export function presetRange(preset, now = new Date()) {
  const today = isoDate(now)
  if (preset === 'today') return [today, today]
  if (preset === 'week') {
    const monday = new Date(now.getFullYear(), now.getMonth(), now.getDate() - ((now.getDay() + 6) % 7))
    return [isoDate(monday), today]
  }
  if (preset === 'month') return [`${now.getFullYear()}-${pad2(now.getMonth() + 1)}-01`, today]
  if (preset === 'year') return [`${now.getFullYear()}-01-01`, today]
  return ['', '']
}
