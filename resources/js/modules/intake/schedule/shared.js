/**
 * [Paket 36] Grafik bo'yicha namunalar — umumiy yordamchilar (Vue'siz).
 */
export const RUN_TONE = {
  planned: 'bg-aqua-50 text-aqua-700 dark:bg-aqua-800/30 dark:text-aqua-200',
  late: 'bg-warn-light text-warn-dark dark:bg-warn-dark/25 dark:text-warn-light',
  received: 'bg-ok-light text-ok-dark dark:bg-ok-dark/25 dark:text-ok-light',
  partial: 'bg-warn-light text-warn-dark dark:bg-warn-dark/25 dark:text-warn-light',
  missed: 'bg-crit-light text-crit-dark dark:bg-crit-dark/25 dark:text-crit-light',
  skipped: 'bg-surface-sunken text-ink-faint line-through dark:bg-white/5',
}

/** YYYY-MM-DD (mahalliy vaqt) */
export function isoDate(d = new Date()) {
  const z = (n) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${z(d.getMonth() + 1)}-${z(d.getDate())}`
}

export function shiftDate(iso, days) {
  const [y, m, d] = iso.split('-').map(Number)
  return isoDate(new Date(y, m - 1, d + days))
}

export function niceDate(iso, locale = 'uz') {
  if (!iso) return ''
  const [y, m, d] = iso.split('-').map(Number)
  const loc = locale === 'ru' ? 'ru-RU' : locale === 'en' ? 'en-GB' : 'uz-UZ'
  try {
    return new Date(y, m - 1, d).toLocaleDateString(loc, { weekday: 'short', day: '2-digit', month: '2-digit', year: 'numeric' })
  } catch {
    return `${String(d).padStart(2, '0')}.${String(m).padStart(2, '0')}.${y}`
  }
}

/** Hafta kunlari qisqa matni: «Har kuni», «Ish kunlari» yoki «Du, Ch, Ju» */
export function daysText(days, t) {
  const d = [...(days ?? [])].map(Number).sort()
  const k = d.join(',')
  if (k === '1,2,3,4,5,6,7') return t('sched.everyDay')
  if (k === '1,2,3,4,5') return t('sched.workDays')
  if (k === '1,2,3,4,5,6') return t('sched.workDays6')
  return d.map((n) => t(`sched.dow.${n}`)).join(', ')
}
