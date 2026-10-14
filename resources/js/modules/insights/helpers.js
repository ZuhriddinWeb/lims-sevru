/**
 * Rahbariyat paneli — yordamchi funksiyalar (sana, son, bo'laklar).
 */

const LOCALE_TAG = { uz: 'uz-Latn-UZ', ru: 'ru-RU', en: 'en-GB' }
export const tag = (locale) => LOCALE_TAG[locale] ?? 'ru-RU'

/** Butun son: 12 345 */
export function num(v, locale = 'ru') {
  if (v === null || v === undefined || v === '') return '—'
  const n = Number(v)
  if (Number.isNaN(n)) return '—'
  return n.toLocaleString(tag(locale), { maximumFractionDigits: 1 })
}

/** Miqdor (namuna qoldig'i): 0,002 — kichik qiymatlar yo'qolmasin */
export function qty(v, locale = 'ru') {
  if (v === null || v === undefined || v === '') return '—'
  const n = Number(v)
  if (Number.isNaN(n)) return '—'
  return n.toLocaleString(tag(locale), { maximumFractionDigits: 4 })
}

/** Soat: 9,5 */
export function hours(v, locale = 'ru') {
  if (v === null || v === undefined) return '—'
  return Number(v).toLocaleString(tag(locale), { minimumFractionDigits: 0, maximumFractionDigits: 1 })
}

/** «2026-10-14 08:30:00» → Date (mahalliy vaqt) */
export function parseTs(s) {
  if (!s) return null
  const m = String(s).match(/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2})(?::(\d{2}))?)?/)
  if (!m) return null
  return new Date(+m[1], +m[2] - 1, +m[3], +(m[4] ?? 0), +(m[5] ?? 0), +(m[6] ?? 0))
}

const pad = (n) => String(n).padStart(2, '0')
export const isoDate = (d) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`
export const hm = (d) => `${pad(d.getHours())}:${pad(d.getMinutes())}`
export const dmy = (d) => `${pad(d.getDate())}.${pad(d.getMonth() + 1)}.${d.getFullYear()}`
export const dm = (d) => `${pad(d.getDate())}.${pad(d.getMonth() + 1)}`

/** Davr: «14.10.2026 08:00 — 10:30», «01.10 — 14.10.2026» */
export function rangeLabel(from, to, withTime = false) {
  const a = parseTs(from)
  const b = parseTs(to)
  if (!a || !b) return ''
  if (withTime) {
    if (isoDate(a) === isoDate(b)) return `${dmy(a)} ${hm(a)} — ${hm(b)}`
    return `${dm(a)} ${hm(a)} — ${dmy(b)} ${hm(b)}`
  }
  if (isoDate(a) === isoDate(b)) return dmy(a)
  if (a.getFullYear() === b.getFullYear()) return `${dm(a)} — ${dmy(b)}`
  return `${dmy(a)} — ${dmy(b)}`
}

/** Grafik o'qi uchun bo'lak nomi */
export function bucketLabel(key, bucket, locale = 'ru', long = false) {
  if (!key) return ''
  if (bucket === 'hour') {
    const [d, h] = key.split(' ')
    const date = parseTs(d)
    return long ? `${dm(date)}, ${h}:00–${pad((+h + 1) % 24)}:00` : `${h}:00`
  }
  if (bucket === 'month') {
    const [y, m] = key.split('-').map(Number)
    const date = new Date(y, m - 1, 1)
    return date.toLocaleDateString(tag(locale), long ? { month: 'long', year: 'numeric' } : { month: 'short' }).replace('.', '')
  }
  const date = parseTs(key)
  if (!long) return dm(date)
  const wd = date.toLocaleDateString(tag(locale), { weekday: 'short' })
  return `${dmy(date)}, ${wd}`
}

/** Hafta kuni qisqa nomi (kun × smena jadvali uchun) */
export function weekday(key, locale = 'ru') {
  const d = parseTs(key)
  return d ? d.toLocaleDateString(tag(locale), { weekday: 'narrow' }) : ''
}

/** O'qdagi «chiroyli» qadamlar: 0 … max */
export function niceTicks(max, count = 4) {
  if (!max || max <= 0) return [0, 1]
  const raw = max / count
  const mag = 10 ** Math.floor(Math.log10(raw))
  const step = [1, 2, 2.5, 5, 10].map((m) => m * mag).find((s) => s >= raw) ?? 10 * mag
  const s = step < 1 ? 1 : step
  const top = Math.ceil(max / s) * s
  const out = []
  for (let v = 0; v <= top + 1e-9; v += s) out.push(Math.round(v * 100) / 100)
  return out
}

/** Server xatosidan o'qiladigan matn */
export function apiErrorMessage(e, fallback) {
  const d = e?.response?.data
  if (d?.errors) {
    const first = Object.values(d.errors)[0]
    if (Array.isArray(first) && first[0]) return first[0]
  }
  return d?.message || fallback
}

/** O'zgarish yorlig'i: yuqoriga/pastga, yaxshi/yomon */
export function deltaTone(change, lowerIsBetter = false) {
  if (change === null || change === undefined || change === 0) return 'flat'
  const good = lowerIsBetter ? change < 0 : change > 0
  return good ? 'good' : 'bad'
}

/** Ketma-ket rang pog'onasi (bitta rang, ochdan to'qqa) — sinf nomlari to'liq yozilgan (Tailwind uchun) */
export const HEAT_STEPS = [
  'bg-surface-sunken text-ink-faint',
  'bg-assay-50 text-ink dark:bg-assay-900/40 dark:text-ink',
  'bg-assay-100 text-ink dark:bg-assay-800/60 dark:text-ink',
  'bg-assay-200 text-ink dark:bg-assay-700/70 dark:text-white',
  'bg-assay-400 text-white dark:bg-assay-500 dark:text-white',
  'bg-assay-600 text-white dark:bg-assay-300 dark:text-assay-900',
]

export function heatLevel(v, max) {
  if (!v) return 0
  if (max <= 0) return 0
  return Math.min(5, 1 + Math.floor((v / max) * 4.999))
}

/** Yosh bo'yicha (1 kungacha … 7 kundan ortiq) — o'sha ketma-ket shkala */
export const AGING = ['d1', 'd3', 'd7', 'over']
export const AGING_CLASS = {
  d1: 'bg-assay-200 dark:bg-assay-800',
  d3: 'bg-assay-400 dark:bg-assay-600',
  d7: 'bg-assay-600 dark:bg-assay-400',
  over: 'bg-assay-800 dark:bg-assay-200',
}
