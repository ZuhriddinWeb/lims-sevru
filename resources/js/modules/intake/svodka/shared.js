/**
 * Buyurtmachi svodkasi — umumiy yordamchilar (Vue'siz). [Paket 29]
 */
import api from '@/lib/api'
import { formatDate } from '@/lib/format'

export const LAYOUTS = ['shift', 'day', 'list']

const pad = (n) => String(n).padStart(2, '0')

/** Date → «2026-10-27» (mahalliy vaqt) */
export function toIso(d) {
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`
}

export function todayIso() {
  return toIso(new Date())
}

export function addDays(iso, n) {
  const d = new Date(`${iso}T00:00:00`)
  d.setDate(d.getDate() + n)
  return toIso(d)
}

export function dayLabel(iso) {
  return iso ? formatDate(`${iso}T00:00:00`) : '—'
}

/** Hafta kuni joriy tilda: «dushanba» (brauzerning til ma'lumotiga bog'liq emas) */
export function weekday(iso, t) {
  if (!iso) return ''
  const d = new Date(`${iso}T00:00:00`)
  return Number.isNaN(d.getTime()) ? '' : t(`svodka.wd.${d.getDay()}`)
}

/** «I-navbatchilik 20:00–08:00» */
export function shiftText(settings, shift, withHours = true) {
  if (!settings || !shift) return ''
  const label = settings[`shift${shift}_label`] || ''
  const hours = settings[`shift${shift}_hours`] || ''
  return withHours && hours ? `${label} ${hours}` : label
}

export function apiError(e, fallback) {
  const d = e?.response?.data
  if (d?.errors) {
    const first = Object.values(d.errors).flat()[0]
    if (first) return first
  }
  return d?.message || fallback
}

/** Excel: blob sifatida olib, fayl nomini serverdan */
export async function downloadSvodka(params) {
  let res
  try {
    res = await api.get('/portal/svodka/export', { params, responseType: 'blob' })
  } catch (e) {
    let message = ''
    const blob = e?.response?.data
    if (blob instanceof Blob) {
      try {
        message = JSON.parse(await blob.text())?.message || ''
      } catch { /* JSON emas */ }
    }
    const err = new Error(message || 'export failed')
    err.status = e?.response?.status
    err.serverMessage = message
    throw err
  }
  const cd = res.headers?.['content-disposition'] || ''
  const m = /filename\*?=(?:UTF-8'')?"?([^";]+)"?/i.exec(cd)
  const name = m ? decodeURIComponent(m[1]) : 'jamlanma_hisobot.xlsx'
  const url = URL.createObjectURL(res.data)
  const a = document.createElement('a')
  a.href = url
  a.download = name
  document.body.appendChild(a)
  a.click()
  a.remove()
  setTimeout(() => URL.revokeObjectURL(url), 4000)
  return name
}

/** Kunlik svodka: davr holati (navbatchilik yoki butun sutka) */
export function periodState(p) {
  if (!p) return 'empty'
  if (p.release) return p.waiting > 0 ? 'waiting' : 'released'
  return p.samples > 0 ? 'unreleased' : 'empty'
}

/** Sutka rejimida ikki navbatchilik bitta chiqarish bilan — bitta holatga birlashtiriladi */
export function dayPeriods(day) {
  if (!day) return []
  const p1 = day.periods?.[1]
  const p2 = day.periods?.[2]
  if (day.settings?.release_mode === 'shift') {
    return [{ key: 1, shift: 1, ...p1 }, { key: 2, shift: 2, ...p2 }]
  }
  return [{
    key: 'day', shift: null,
    release: p1?.release || p2?.release || null,
    samples: (p1?.samples || 0) + (p2?.samples || 0),
    released: (p1?.released || 0) + (p2?.released || 0),
    waiting: (p1?.waiting || 0) + (p2?.waiting || 0),
  }]
}

export const STATE_TONE = {
  released: 'bg-ok-light text-ok-dark dark:bg-ok-dark/25 dark:text-ok-light',
  waiting: 'bg-warn-light text-warn-dark dark:bg-warn-dark/25 dark:text-warn-light',
  unreleased: 'bg-aqua-50 text-aqua-700 dark:bg-aqua-800/30 dark:text-aqua-200',
  empty: 'bg-surface-sunken text-ink-muted',
}

/** Laboratoriya boshlig'i ro'yxatidagi davr holati */
export function releaseState(p) {
  if (p.release) return p.changed > 0 ? 'changed' : 'released'
  if (!p.samples) return 'empty'
  if (!p.ended) return 'running'
  return p.pending > 0 ? 'incomplete' : 'ready'
}

export const RELEASE_TONE = {
  released: 'bg-ok-light text-ok-dark dark:bg-ok-dark/25 dark:text-ok-light',
  changed: 'bg-warn-light text-warn-dark dark:bg-warn-dark/25 dark:text-warn-light',
  ready: 'bg-aqua-50 text-aqua-700 dark:bg-aqua-800/30 dark:text-aqua-200',
  incomplete: 'bg-surface-sunken text-ink-muted',
  running: 'bg-surface-sunken text-ink-muted',
  empty: 'bg-surface-sunken text-ink-faint',
}
