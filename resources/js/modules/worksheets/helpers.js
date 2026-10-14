/**
 * Ishchi varaqlar moduli uchun umumiy yordamchilar (Vue'siz).
 */

export const STATUSES = ['draft', 'printed', 'in_progress', 'completed', 'approved']

export const STATUS_TONE = {
  draft: 'bg-surface-sunken text-ink-muted dark:bg-white/5',
  printed: 'bg-aqua-50 text-aqua-700 dark:bg-aqua-800/35 dark:text-aqua-200',
  in_progress: 'bg-warn-light text-warn-dark dark:bg-warn-dark/25 dark:text-warn-light',
  completed: 'bg-assay-50 text-assay-700 dark:bg-assay-800/30 dark:text-assay-200',
  approved: 'bg-ok-light text-ok-dark dark:bg-ok-dark/25 dark:text-ok-light',
}

export const STATUS_DOT = {
  draft: 'bg-ink-faint', printed: 'bg-aqua-500', in_progress: 'bg-warn',
  completed: 'bg-assay-500', approved: 'bg-ok',
}

/** Bosqichlar uchun ajratib turuvchi ranglar (jadval sarlavhasi) */
export const STAGE_TONES = [
  'border-t-assay-500', 'border-t-aqua-500', 'border-t-ok', 'border-t-warn',
  'border-t-crit', 'border-t-assay-300', 'border-t-aqua-300', 'border-t-ink-faint',
]

/** Interfeys tilidagi ustun nomi */
export function colLabel(col, locale) {
  if (!col) return ''
  if (locale === 'ru') return col.label_ru || col.label_uz || col.key
  return col.label_uz || col.label_ru || col.key
}

/** Chop etish tilidagi nom: uz | uz_cyrl | ru */
export function printLabel(item, printLocale) {
  if (!item) return ''
  if (printLocale === 'uz_cyrl') return item.label_uz_cyrl || item.label_uz || item.key
  if (printLocale === 'ru') return item.label_ru || item.label_uz || item.key
  return item.label_uz || item.key
}

export function printName(tpl, printLocale) {
  if (!tpl) return ''
  if (printLocale === 'uz_cyrl') return tpl.name_uz_cyrl || tpl.name_uz
  if (printLocale === 'ru') return tpl.name_ru || tpl.name_uz
  return tpl.name_uz
}

export function templateName(tpl, locale) {
  if (!tpl) return ''
  if (locale === 'ru') return tpl.name_ru || tpl.name_uz
  return tpl.name_uz || tpl.name_ru
}

export function useComma(locale) {
  return locale === 'ru' || locale === 'uz' || locale === 'uz_cyrl'
}

/** Hisoblangan qiymatni ko'rsatish: xona soni bo'yicha, kerak bo'lsa vergul bilan */
export function fmt(value, decimals, locale) {
  if (value === null || value === undefined || value === '' || Number.isNaN(value)) return ''
  const n = Number(value)
  if (!Number.isFinite(n)) return ''
  let s = decimals === null || decimals === undefined || decimals === '' ? String(n) : n.toFixed(Number(decimals))
  if (useComma(locale)) s = s.replace('.', ',')
  return s
}

/** Saqlangan kiritma ("0.093") → ko'rsatish ("0,093") */
export function displayInput(raw, locale) {
  if (raw === null || raw === undefined) return ''
  const s = String(raw)
  return useComma(locale) && /^[+-]?\d*\.\d+$/.test(s) ? s.replace('.', ',') : s
}

export function apiErrorMessage(e, fallback) {
  const d = e?.response?.data
  if (d?.errors) {
    const first = Object.values(d.errors).flat()
    if (first.length) return first.slice(0, 3).join(' ')
  }
  return d?.message || fallback
}

/** Ishchi varaqdagi bosqichlar bo'yicha ustun guruhlari (thead uchun) */
export function stageBands(columns, stages, locale) {
  const bands = []
  for (const c of columns) {
    const key = c.stage || ''
    const last = bands[bands.length - 1]
    if (last && last.key === key) {
      last.span++
    } else {
      const st = stages.find((s) => s.key === key)
      const idx = stages.findIndex((s) => s.key === key)
      bands.push({
        key,
        span: 1,
        label: st ? (locale === 'ru' ? st.label_ru || st.label_uz : st.label_uz) : '',
        tone: idx >= 0 ? STAGE_TONES[idx % STAGE_TONES.length] : 'border-t-transparent',
      })
    }
  }
  return bands
}

export function todayISO() {
  const d = new Date()
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

/* ------------------------------------------------------------ partiya varaq */

export const MAX_PARALLELS = 6

/**
 * Taxminiy A4 sahifa soni (chop etishda qator balandligi qatorlar soniga qarab kichrayadi):
 * landshaft — 1-sahifaga ~14 qator, keyingilariga ~26; portret — ~24 / ~36.
 */
export function pageEstimate(rows, orientation = 'landscape', spare = 2) {
  const n = Math.max(0, Number(rows) || 0) + spare
  const [first, next] = orientation === 'portrait' ? [24, 36] : [14, 26]
  return n <= first ? 1 : 1 + Math.ceil((n - first) / next)
}

/**
 * Varaq a'zolari (probalar) — varaqdagi tartibda.
 * SQL Server id'larni satr ("5") qilib qaytarishi mumkin — shu yerda songa keltiriladi,
 * shunda qatorlardagi sample_id (son) bilan solishtirish to'g'ri ishlaydi.
 */
export function sortedMembers(ws) {
  return [...(ws?.members ?? [])]
    .map((m) => ({ ...m, sample_id: Number(m.sample_id), position: Number(m.position) || 0 }))
    .sort((a, b) => (a.position - b.position) || (Number(a.id) - Number(b.id)))
}

/** Qatorda haqiqiy o'lchov bormi (standart qiymatlardan tashqari) */
export function rowHasMeasurement(inputs, defaults) {
  return Object.entries(inputs ?? {}).some(([k, v]) => {
    if (v === null || v === undefined || String(v).trim() === '') return false
    const d = defaults?.[k]
    if (d === null || d === undefined || d === '') return true
    return String(v).replace(',', '.') !== String(d).replace(',', '.')
  })
}

/** Proba miqdori: 4 xonagacha, ortiqcha nollarsiz (0,3015 · 2,7); bo'sh bo'lsa — «—» */
export function formatQty(value, locale) {
  if (value === null || value === undefined || value === '' || Number.isNaN(Number(value))) return '—'
  const s = String(Math.round(Number(value) * 10000) / 10000)
  return useComma(locale) ? s.replace('.', ',') : s
}
