import { onBeforeUnmount, onMounted, ref } from 'vue'
import api from '@/lib/api'
import { localized } from '@/i18n'
import { formatNumber } from '@/lib/format'

export const OUT_STATUSES = ['sending', 'sent', 'accepted', 'completed', 'rejected', 'cancelled']
export const IN_STATUSES = ['sent', 'accepted', 'completed', 'rejected', 'cancelled']
export const SYNC_INTERVAL_MS = 20000

const TONE = {
  neutral: 'bg-surface-sunken text-ink-muted',
  info: 'bg-aqua-50 text-aqua-700 dark:bg-aqua-800/30 dark:text-aqua-200',
  action: 'bg-assay-50 text-assay-700 dark:bg-assay-800/30 dark:text-assay-200',
  ok: 'bg-ok-light text-ok-dark dark:bg-ok-dark/30 dark:text-ok-light',
  crit: 'bg-crit-light text-crit-dark dark:bg-crit-dark/30 dark:text-crit-light',
  muted: 'bg-surface-sunken text-ink-faint',
}
const DOT = {
  neutral: 'bg-ink-faint', info: 'bg-aqua-500', action: 'bg-assay-500', ok: 'bg-ok', crit: 'bg-crit', muted: 'bg-ink-faint/60',
}

/** Holat rangi — yo'nalishga qarab: kim harakat qilishi kerak bo'lsa, oltin rang */
export function statusTone(direction, status) {
  const map = direction === 'in'
    ? { sending: 'neutral', sent: 'action', accepted: 'info', completed: 'ok', rejected: 'crit', cancelled: 'muted' }
    : { sending: 'neutral', sent: 'info', accepted: 'info', completed: 'action', rejected: 'crit', cancelled: 'muted' }
  const k = map[status] ?? 'neutral'
  return { badge: TONE[k], dot: DOT[k] }
}

export function peerTone(status) {
  const k = { active: 'ok', pending_in: 'action', pending_out: 'info', rejected: 'crit', disabled: 'muted' }[status] ?? 'neutral'
  return { badge: TONE[k], dot: DOT[k] }
}

export function apiErrorMessage(e, fallback) {
  const d = e?.response?.data
  if (d?.errors) {
    const first = Object.values(d.errors).flat()
    if (first.length) return first.slice(0, 3).join(' ')
  }
  return d?.message || fallback
}

export function elementName(el, locale) {
  return localized(el, 'name', locale) || el?.symbol || ''
}

export function fmtValue(v, digits = 6) {
  return formatNumber(v, digits)
}

/** «2026-10-12» → bugungi kundan o'tganmi */
export function isPast(dateStr) {
  if (!dateStr) return false
  const d = new Date(dateStr + 'T23:59:59')
  return d < new Date()
}

/**
 * Sahifa ochiq turganda fonda sinxronlash (Task Scheduler bo'lmasa ham xabarlar ketadi).
 * onSynced — biror xabar yuborilgan bo'lsa yoki har aylanishda chaqiriladi (ro'yxatni yangilash uchun).
 */
export function useInterlabSync(onSynced) {
  const queue = ref({ pending: 0, failed: 0 })
  const lastSyncAt = ref(null)
  const syncing = ref(false)
  let timer = null

  async function run(now = false) {
    if (syncing.value) return null
    syncing.value = true
    try {
      const { data } = await api.post('/interlab/sync', { now })
      queue.value = data.queue ?? queue.value
      lastSyncAt.value = data.last_sync_at ?? lastSyncAt.value
      if (onSynced) await onSynced(data)
      return data
    } catch {
      return null
    } finally {
      syncing.value = false
    }
  }

  function tick() {
    if (document.visibilityState === 'visible') run(false)
  }

  onMounted(() => {
    run(false)
    timer = setInterval(tick, SYNC_INTERVAL_MS)
  })
  onBeforeUnmount(() => clearInterval(timer))

  return { queue, lastSyncAt, syncing, syncNow: () => run(true) }
}
