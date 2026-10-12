<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import {
  AlertTriangle, ArrowLeft, Ban, CheckCircle2, ChevronDown, Clock, EyeOff, FileCheck2, Hourglass, Pencil,
  RefreshCw, RotateCcw, Save, Send, X, XCircle,
} from 'lucide-vue-next'
import api from '@/lib/api'
import BaseModal from '@/components/ui/BaseModal.vue'
import { useUiStore } from '@/stores/ui'
import { localized } from '@/i18n'
import { formatDate, formatDateTime } from '@/lib/format'
import IlStatusBadge from '../components/IlStatusBadge.vue'
import { apiErrorMessage, elementName, fmtValue, useInterlabSync } from '../helpers'
import '../i18n'

const { t, locale } = useI18n()
const route = useRoute()
const router = useRouter()
const ui = useUiStore()

const r = ref(null)
const loading = ref(true)
const busy = ref(false)
const groups = ref([])
const showHistory = ref(false)
const showMessages = ref(false)

// Tekshiruvchi: natija kiritish
const editing = ref(false)
const rows = ref([])
const resultNote = ref('')
let snapshot = ''

// Modallar
const modal = reactive({ cancel: false, reject: false, accept: false, send: false })
const reason = ref('')
const groupId = ref('')

const isOut = computed(() => r.value?.direction === 'out')
const peerName = computed(() => r.value?.peer?.name ?? '—')
const latest = computed(() => (r.value?.revisions ?? []).at(-1) ?? null)
const older = computed(() => (r.value?.revisions ?? []).slice(0, -1).reverse())
const dirty = computed(() => editing.value && JSON.stringify([rows.value, resultNote.value]) !== snapshot)
const hasTolerance = computed(() => r.value?.tolerance_pct !== null && r.value?.tolerance_pct !== undefined)

async function load(quiet = false) {
  if (!quiet) loading.value = true
  try {
    const { data } = await api.get(`/interlab/referrals/${route.params.id}`)
    applyData(data, quiet)
  } catch (e) {
    if (!quiet) {
      ui.error(apiErrorMessage(e, t('il.loadError')))
      router.replace({ name: 'interlab' })
    }
  } finally {
    loading.value = false
  }
}

function applyData(data, keepEditing = false) {
  r.value = data
  if (keepEditing && dirty.value) return
  const canEdit = data.can?.edit_results
  if (canEdit && (data.status === 'accepted' || data.drafts.length)) startEditing(data)
  else editing.value = false
}

/** Qoralama bo'lsa — undan, bo'lmasa oxirgi yuborilgan natijadan to'ldiradi */
function startEditing(data = r.value) {
  const drafts = Object.fromEntries((data.drafts ?? []).map((d) => [d.symbol.toUpperCase(), d]))
  const last = Object.fromEntries(((data.revisions ?? []).at(-1)?.rows ?? []).map((d) => [d.symbol.toUpperCase(), d]))
  rows.value = data.elements.map((el) => {
    const src = drafts[el.symbol.toUpperCase()] ?? last[el.symbol.toUpperCase()] ?? {}
    return {
      symbol: el.symbol,
      value_text: src.value_text ?? '',
      unit: src.unit ?? el.unit ?? '',
      method: src.method ?? '',
      notes: src.notes ?? '',
    }
  })
  resultNote.value = data.result_note ?? ''
  snapshot = JSON.stringify([rows.value, resultNote.value])
  editing.value = true
}

const { syncing, syncNow } = useInterlabSync(async () => {
  if (!busy.value) await load(true)
})

onMounted(async () => {
  await load()
  try {
    const { data } = await api.get('/laboratory-groups', { params: { all: 1 } })
    groups.value = Array.isArray(data) ? data : (data.data ?? [])
  } catch { /* ixtiyoriy */ }
})
watch(() => route.params.id, (v, o) => { if (v && v !== o) load() })

async function act(fn, okMessage) {
  busy.value = true
  try {
    const { data } = await fn()
    applyData(data)
    if (okMessage) ui.success(okMessage)
    return true
  } catch (e) {
    ui.error(apiErrorMessage(e, t('il.saveError')))
    return false
  } finally {
    busy.value = false
  }
}

function openModal(name) {
  reason.value = ''
  groupId.value = ''
  modal[name] = true
}

async function doCancel() {
  if (await act(() => api.post(`/interlab/referrals/${r.value.id}/cancel`, { reason: reason.value }), t('il.cancelled'))) modal.cancel = false
}
async function doReject() {
  if (await act(() => api.post(`/interlab/referrals/${r.value.id}/reject`, { reason: reason.value }), t('il.rejected'))) modal.reject = false
}
async function doAccept() {
  if (await act(() => api.post(`/interlab/referrals/${r.value.id}/accept`, { group_id: groupId.value || null }), t('il.accepted'))) modal.accept = false
}
async function doReviewed() {
  await act(() => api.post(`/interlab/referrals/${r.value.id}/reviewed`), t('il.markReviewed'))
}
async function saveDraft(silent = false) {
  busy.value = true
  try {
    const { data } = await api.put(`/interlab/referrals/${r.value.id}/results`, { results: rows.value, result_note: resultNote.value || null })
    r.value = data
    snapshot = JSON.stringify([rows.value, resultNote.value])
    if (!silent) ui.success(t('il.draftSaved'))
    return true
  } catch (e) {
    ui.error(apiErrorMessage(e, t('il.saveError')))
    return false
  } finally {
    busy.value = false
  }
}
async function doSend() {
  if (!(await saveDraft(true))) return
  if (await act(() => api.post(`/interlab/referrals/${r.value.id}/results/send`, { reason: reason.value || null }), t('il.resultsSent'))) {
    modal.send = false
  }
}
function cancelEditing() {
  editing.value = false
  rows.value = []
}
async function retry(m) {
  busy.value = true
  try {
    await api.post(`/interlab/messages/${m.id}/retry`)
    await load(true)
    ui.success(t('il.retried'))
  } catch (e) {
    ui.error(apiErrorMessage(e, t('il.saveError')))
  } finally {
    busy.value = false
  }
}
async function manualSync() {
  await syncNow()
  await load(true)
}

const timeline = computed(() => {
  const x = r.value
  if (!x) return []
  const ev = []
  const add = (at, key, extra = '', tone = 'neutral') => at && ev.push({ at, label: t(`il.ev.${key}`), extra, tone })
  add(x.sent_at, 'sent', x.sender_name ?? '')
  add(x.delivered_at, 'delivered')
  add(x.accepted_at, 'accepted', x.accepted_by_name ?? '', 'info')
  add(x.rejected_at, 'rejected', x.reject_reason ?? '', 'crit')
  for (const rev of x.revisions ?? []) {
    add(rev.at, 'completed', `${t('il.revision', { n: rev.revision })} · ${rev.performer ?? ''}`, 'ok')
  }
  add(x.reviewed_at, 'reviewed', x.reviewed_by_name ?? '', 'ok')
  add(x.cancelled_at, 'cancelled', x.cancel_reason ?? '', 'crit')
  return ev.sort((a, b) => new Date(a.at) - new Date(b.at))
})

const TONE_DOT = { neutral: 'bg-ink-faint', info: 'bg-aqua-500', ok: 'bg-ok', crit: 'bg-crit' }
const MSG_TONE = {
  pending: 'text-warn-dark dark:text-warn-light', sending: 'text-warn-dark dark:text-warn-light', sent: 'text-ok',
  failed: 'text-crit', processed: 'text-ok', rejected: 'text-crit',
}
const elementOf = (symbol) => r.value?.elements.find((e) => e.symbol === symbol) ?? { symbol }
</script>

<template>
  <div v-if="loading && !r" class="space-y-4">
    <div class="h-10 w-72 rounded bg-surface-sunken animate-pulse" />
    <div class="h-64 panel animate-pulse" />
  </div>

  <div v-else-if="r">
    <!-- Sarlavha -->
    <div class="flex flex-wrap items-start justify-between gap-4 mb-5">
      <div class="flex items-start gap-3 min-w-0">
        <button class="btn-ghost btn-icon mt-0.5" :title="t('il.back')" @click="router.push({ name: 'interlab', query: { tab: r.direction } })">
          <ArrowLeft :size="18" />
        </button>
        <div class="min-w-0">
          <div class="flex flex-wrap items-center gap-2.5">
            <h1 class="font-mono text-[22px] font-semibold text-ink leading-tight">{{ r.number }}</h1>
            <IlStatusBadge :status="r.status" :direction="r.direction" />
          </div>
          <p class="mt-1 text-[13.5px] text-ink-muted flex items-center gap-1.5">
            <span class="h-1.5 w-1.5 rounded-full" :class="r.peer?.online ? 'bg-ok' : 'bg-ink-faint'" />
            {{ isOut ? t('il.outgoingTo', { lab: peerName }) : t('il.incomingFrom', { lab: peerName }) }}
          </p>
        </div>
      </div>

      <div class="flex flex-wrap items-center gap-2">
        <template v-if="isOut">
          <button v-if="r.can.cancel" class="btn-outline" :disabled="busy" @click="openModal('cancel')">
            <Ban :size="16" /> {{ t('il.cancel') }}
          </button>
          <button v-if="r.can.review" class="btn-primary" :disabled="busy" @click="doReviewed">
            <FileCheck2 :size="16" /> {{ t('il.markReviewed') }}
          </button>
        </template>
        <template v-else>
          <button v-if="r.can.reject" class="btn-outline" :disabled="busy" @click="openModal('reject')">
            <XCircle :size="16" /> {{ t('il.reject') }}
          </button>
          <button v-if="r.can.accept" class="btn-primary" :disabled="busy" @click="openModal('accept')">
            <CheckCircle2 :size="16" /> {{ t('il.accept') }}
          </button>
        </template>
      </div>
    </div>

    <!-- Sinxronlash ogohlantirishlari -->
    <div v-if="r.sync.pending" class="mb-4 rounded-md border border-warn/30 bg-warn-light/60 dark:bg-warn-dark/20 px-4 py-2.5 flex flex-wrap items-center justify-between gap-3 text-[13.5px]">
      <span class="flex items-center gap-2 text-warn-dark dark:text-warn-light"><Clock :size="16" /> {{ t('il.pendingBanner', { n: r.sync.pending }) }}</span>
      <button class="btn-outline btn-sm" :disabled="syncing" @click="manualSync">
        <RefreshCw :size="14" :class="syncing ? 'animate-spin' : ''" /> {{ t('il.syncNow') }}
      </button>
    </div>
    <div v-if="r.sync.failed" class="mb-4 rounded-md border border-crit/30 bg-crit-light/60 dark:bg-crit-dark/20 px-4 py-2.5 flex flex-wrap items-center justify-between gap-3 text-[13.5px]">
      <span class="flex items-center gap-2 text-crit-dark dark:text-crit-light"><AlertTriangle :size="16" /> {{ t('il.failedBanner', { n: r.sync.failed }) }}</span>
      <button class="btn-outline btn-sm" @click="showMessages = true">{{ t('il.messages') }}</button>
    </div>
    <div v-if="r.status === 'rejected'" class="mb-4 rounded-md border border-crit/30 bg-crit-light/50 dark:bg-crit-dark/20 px-4 py-2.5 text-[13.5px]">
      <span class="font-medium text-crit-dark dark:text-crit-light">{{ t('il.rejectedReason') }}:</span>
      <span class="text-ink"> {{ r.reject_reason || '—' }}</span>
    </div>
    <div v-if="r.status === 'cancelled'" class="mb-4 rounded-md border border-line bg-surface-sunken px-4 py-2.5 text-[13.5px]">
      <span class="font-medium text-ink-muted">{{ t('il.cancelReason') }}:</span>
      <span class="text-ink"> {{ r.cancel_reason || '—' }}</span>
    </div>

    <div class="grid lg:grid-cols-3 gap-4">
      <!-- ====================== Natijalar ====================== -->
      <section class="panel lg:col-span-2 overflow-hidden self-start">
        <!-- JO'NATUVCHI: solishtirish -->
        <template v-if="isOut">
          <div class="px-4 py-3 border-b border-line flex flex-wrap items-center justify-between gap-2">
            <h2 class="text-[15px] font-semibold text-ink">{{ latest ? t('il.comparison') : t('il.results') }}</h2>
            <span v-if="latest" class="rounded-full bg-surface-sunken px-2 py-0.5 text-[12px] text-ink-muted">{{ t('il.revision', { n: latest.revision }) }}</span>
          </div>

          <div v-if="!latest" class="py-12 flex flex-col items-center text-center px-6">
            <div class="h-12 w-12 rounded-full bg-surface-sunken grid place-items-center text-ink-faint mb-3"><Hourglass :size="22" /></div>
            <p class="text-[15px] font-medium text-ink">{{ t('il.waitingResult') }}</p>
            <p class="mt-1 text-[13px] text-ink-muted">{{ t(`il.st.out.${r.status}`) }} · {{ peerName }}</p>
          </div>

          <template v-else>
            <div class="overflow-x-auto">
              <table class="w-full text-[13.5px]">
                <thead>
                  <tr class="border-b border-line bg-surface-sunken/60 text-left text-[12px] uppercase tracking-wide text-ink-muted">
                    <th class="px-4 py-2.5 font-medium">{{ t('il.element') }}</th>
                    <th class="px-4 py-2.5 font-medium text-right">{{ t('il.ours') }}</th>
                    <th class="px-4 py-2.5 font-medium text-right">{{ t('il.theirs', { lab: r.peer?.code || peerName }) }}</th>
                    <th class="px-4 py-2.5 font-medium text-right">{{ t('il.diff') }}</th>
                    <th class="px-4 py-2.5 font-medium text-right" :title="t('il.diffPctHint')">{{ t('il.diffPct') }}</th>
                    <th v-if="hasTolerance" class="px-4 py-2.5 font-medium">{{ t('il.verdict') }} (±{{ fmtValue(r.tolerance_pct, 3) }}%)</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-line">
                  <tr v-for="c in r.comparison" :key="c.symbol">
                    <td class="px-4 py-2.5">
                      <span class="font-semibold text-ink">{{ c.symbol }}</span>
                      <span class="text-ink-muted"> · {{ elementName(c, locale) }}</span>
                      <span v-if="c.their_method" class="block text-[12px] text-ink-faint">{{ t('il.method') }}: {{ c.their_method }}</span>
                    </td>
                    <td class="px-4 py-2.5 text-right tnum">
                      <template v-if="c.own_value !== null">{{ fmtValue(c.own_value) }} <span class="text-ink-faint text-[12px]">{{ c.own_unit }}</span></template>
                      <span v-else class="text-[12px] text-ink-faint">{{ t('il.noOwn') }}</span>
                    </td>
                    <td class="px-4 py-2.5 text-right tnum font-medium text-ink">
                      <template v-if="c.their_value !== null">{{ fmtValue(c.their_value) }}</template>
                      <template v-else>{{ c.their_text ?? '—' }}</template>
                      <span class="text-ink-faint text-[12px] font-normal"> {{ c.their_unit }}</span>
                      <span v-if="c.their_notes" class="block text-[11.5px] text-ink-faint font-normal">{{ c.their_notes }}</span>
                    </td>
                    <td class="px-4 py-2.5 text-right tnum text-ink-muted">{{ c.diff !== null ? (c.diff > 0 ? '+' : '') + fmtValue(c.diff) : '—' }}</td>
                    <td class="px-4 py-2.5 text-right tnum" :class="c.within === false ? 'text-crit font-semibold' : 'text-ink'">
                      {{ c.rel_pct !== null ? fmtValue(c.rel_pct, 2) : '—' }}
                      <span v-if="c.unit_mismatch" class="block text-[11px] text-warn-dark dark:text-warn-light font-normal">{{ t('il.unitMismatch', { a: c.own_unit, b: c.their_unit }) }}</span>
                    </td>
                    <td v-if="hasTolerance" class="px-4 py-2.5">
                      <span v-if="c.within === true" class="inline-flex items-center gap-1 rounded-full bg-ok-light text-ok-dark dark:bg-ok-dark/30 dark:text-ok-light px-2 py-0.5 text-[12px] font-medium"><CheckCircle2 :size="12" /> {{ t('il.within') }}</span>
                      <span v-else-if="c.within === false" class="inline-flex items-center gap-1 rounded-full bg-crit-light text-crit-dark dark:bg-crit-dark/30 dark:text-crit-light px-2 py-0.5 text-[12px] font-medium"><XCircle :size="12" /> {{ t('il.outside') }}</span>
                      <span v-else class="text-ink-faint">—</span>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
            <div class="px-4 py-3 border-t border-line text-[13px] space-y-1.5">
              <p class="text-ink-muted">{{ t('il.performer') }}: <span class="text-ink">{{ latest.performer || '—' }}</span> · {{ formatDateTime(latest.at) }}</p>
              <p v-if="r.revision_reason" class="text-ink-muted">{{ t('il.revisionReason') }}: <span class="text-warn-dark dark:text-warn-light">{{ r.revision_reason }}</span></p>
              <p v-if="r.result_note" class="text-ink-muted">{{ t('il.resultNote') }}: <span class="text-ink">{{ r.result_note }}</span></p>
              <p v-if="r.reviewed_at" class="text-ok flex items-center gap-1.5"><FileCheck2 :size="14" /> {{ t('il.reviewed', { who: r.reviewed_by_name || '' }) }} · {{ formatDateTime(r.reviewed_at) }}</p>
            </div>
            <div v-if="older.length" class="border-t border-line">
              <button class="w-full px-4 py-2.5 flex items-center justify-between text-[13px] text-ink-muted hover:bg-surface-sunken/50" @click="showHistory = !showHistory">
                <span class="flex items-center gap-1.5"><RotateCcw :size="14" /> {{ t('il.history') }} ({{ older.length }})</span>
                <ChevronDown :size="15" :class="showHistory ? 'rotate-180' : ''" class="transition-transform" />
              </button>
              <div v-if="showHistory" class="px-4 pb-3 space-y-3">
                <div v-for="rev in older" :key="rev.revision" class="rounded-md border border-line">
                  <p class="px-3 py-1.5 text-[12px] text-ink-muted bg-surface-sunken/50 border-b border-line">
                    {{ t('il.revision', { n: rev.revision }) }} · {{ rev.performer }} · {{ formatDateTime(rev.at) }}
                  </p>
                  <div class="px-3 py-1.5 flex flex-wrap gap-x-5 gap-y-1 text-[13px]">
                    <span v-for="x in rev.rows" :key="x.symbol"><b class="text-ink">{{ x.symbol }}</b> <span class="tnum text-ink-muted line-through decoration-ink-faint/60">{{ x.value_text }}</span> <span class="text-ink-faint text-[12px]">{{ x.unit }}</span></span>
                  </div>
                </div>
              </div>
            </div>
          </template>
        </template>

        <!-- TEKSHIRUVCHI -->
        <template v-else>
          <div class="px-4 py-3 border-b border-line flex flex-wrap items-center justify-between gap-2">
            <h2 class="text-[15px] font-semibold text-ink">{{ editing ? t('il.enterResults') : (latest ? t('il.sentResult') : t('il.results')) }}</h2>
            <span v-if="editing && r.result_revision > 0" class="rounded-full bg-warn-light text-warn-dark dark:bg-warn-dark/30 dark:text-warn-light px-2 py-0.5 text-[12px]">{{ t('il.revision', { n: r.result_revision + 1 }) }}</span>
            <span v-else-if="latest" class="rounded-full bg-surface-sunken px-2 py-0.5 text-[12px] text-ink-muted">{{ t('il.revision', { n: latest.revision }) }}</span>
          </div>

          <div v-if="r.status === 'sent'" class="p-4">
            <div class="rounded-md border border-assay-300 bg-assay-50/60 dark:bg-assay-800/20 px-4 py-3 text-[13.5px] text-ink">{{ t('il.acceptHint') }}</div>
            <ul class="mt-3 flex flex-wrap gap-2">
              <li v-for="el in r.elements" :key="el.symbol" class="rounded-md border border-line px-2.5 py-1.5 text-[13px]">
                <b class="text-ink">{{ el.symbol }}</b> <span class="text-ink-muted">{{ elementName(el, locale) }}</span> <span class="text-ink-faint text-[12px]">{{ el.unit }}</span>
              </li>
            </ul>
          </div>

          <template v-else-if="editing">
            <div class="overflow-x-auto">
              <table class="w-full text-[13.5px]">
                <thead>
                  <tr class="border-b border-line bg-surface-sunken/60 text-left text-[12px] uppercase tracking-wide text-ink-muted">
                    <th class="px-4 py-2.5 font-medium">{{ t('il.element') }}</th>
                    <th class="px-2 py-2.5 font-medium w-36">{{ t('il.value') }}</th>
                    <th class="px-2 py-2.5 font-medium w-24">{{ t('il.unit') }}</th>
                    <th class="px-2 py-2.5 font-medium">{{ t('il.method') }}</th>
                    <th class="px-2 py-2.5 font-medium">{{ t('il.notes') }}</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-line">
                  <tr v-for="row in rows" :key="row.symbol">
                    <td class="px-4 py-2">
                      <span class="font-semibold text-ink">{{ row.symbol }}</span>
                      <span class="block text-[12px] text-ink-muted">{{ elementName(elementOf(row.symbol), locale) }}</span>
                    </td>
                    <td class="px-2 py-2"><input v-model="row.value_text" class="field-input h-9 text-right tnum font-medium" :placeholder="t('il.valueHint')" maxlength="64"></td>
                    <td class="px-2 py-2"><input v-model="row.unit" class="field-input h-9" maxlength="32"></td>
                    <td class="px-2 py-2"><input v-model="row.method" class="field-input h-9" maxlength="255"></td>
                    <td class="px-2 py-2"><input v-model="row.notes" class="field-input h-9" maxlength="500"></td>
                  </tr>
                </tbody>
              </table>
            </div>
            <div class="px-4 py-3 border-t border-line space-y-3">
              <div>
                <label class="field-label">{{ t('il.overallNote') }}</label>
                <textarea v-model="resultNote" rows="2" class="field-input" maxlength="2000" />
              </div>
              <div class="flex flex-wrap items-center justify-between gap-3">
                <p class="text-[12px] text-ink-faint max-w-md">{{ t('il.draftHint') }}<span v-if="dirty" class="text-warn-dark dark:text-warn-light"> · {{ t('il.unsaved') }}</span></p>
                <div class="flex flex-wrap gap-2">
                  <button v-if="r.result_revision > 0" class="btn-ghost" :disabled="busy" @click="cancelEditing"><X :size="16" /> {{ t('il.cancelEdit') }}</button>
                  <button class="btn-outline" :disabled="busy" @click="saveDraft()"><Save :size="16" /> {{ t('il.saveDraft') }}</button>
                  <button class="btn-primary" :disabled="busy" @click="openModal('send')"><Send :size="16" /> {{ t('il.sendResults') }}</button>
                </div>
              </div>
            </div>
          </template>

          <template v-else-if="latest">
            <div class="overflow-x-auto">
              <table class="w-full text-[13.5px]">
                <thead>
                  <tr class="border-b border-line bg-surface-sunken/60 text-left text-[12px] uppercase tracking-wide text-ink-muted">
                    <th class="px-4 py-2.5 font-medium">{{ t('il.element') }}</th>
                    <th class="px-4 py-2.5 font-medium text-right">{{ t('il.value') }}</th>
                    <th class="px-4 py-2.5 font-medium">{{ t('il.method') }}</th>
                    <th class="px-4 py-2.5 font-medium">{{ t('il.notes') }}</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-line">
                  <tr v-for="x in latest.rows" :key="x.symbol">
                    <td class="px-4 py-2.5"><b class="text-ink">{{ x.symbol }}</b> <span class="text-ink-muted">· {{ elementName(elementOf(x.symbol), locale) }}</span></td>
                    <td class="px-4 py-2.5 text-right tnum font-medium text-ink">{{ x.value_text }} <span class="text-ink-faint text-[12px] font-normal">{{ x.unit }}</span></td>
                    <td class="px-4 py-2.5 text-ink-muted">{{ x.method || '—' }}</td>
                    <td class="px-4 py-2.5 text-ink-muted">{{ x.notes || '—' }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
            <div class="px-4 py-3 border-t border-line flex flex-wrap items-center justify-between gap-3 text-[13px]">
              <div class="space-y-1">
                <p class="text-ink-muted">{{ t('il.performer') }}: <span class="text-ink">{{ latest.performer }}</span> · {{ formatDateTime(latest.at) }}</p>
                <p v-if="r.result_note" class="text-ink-muted">{{ t('il.overallNote') }}: <span class="text-ink">{{ r.result_note }}</span></p>
                <p v-if="r.reviewed_at" class="text-ok flex items-center gap-1.5"><FileCheck2 :size="14" /> {{ t('il.reviewedBySender', { who: r.reviewed_by_name || '' }) }}</p>
              </div>
              <button v-if="r.can.edit_results" class="btn-outline" @click="startEditing()"><Pencil :size="15" /> {{ t('il.revise') }}</button>
            </div>
          </template>

          <div v-else class="py-10 text-center text-[13.5px] text-ink-faint">—</div>
        </template>
      </section>

      <!-- ====================== Yon panel ====================== -->
      <aside class="space-y-4">
        <section class="panel p-4 text-[13.5px]">
          <h2 class="text-[15px] font-semibold text-ink mb-3">{{ t('il.info') }}</h2>
          <dl class="space-y-2.5">
            <div>
              <dt class="text-[12px] text-ink-muted">{{ t('il.sampleInfo') }}</dt>
              <dd class="text-ink">
                <template v-if="r.sample_code">
                  <span class="font-mono">{{ r.sample_code }}</span> · № {{ r.sample_number }}
                  <router-link v-if="isOut && r.sample_id" :to="{ name: 'sample-detail', params: { id: r.sample_id } }" class="block text-[12px] text-assay-600 hover:underline">{{ t('il.openSample') }}</router-link>
                </template>
                <span v-else class="inline-flex items-center gap-1 text-ink-faint"><EyeOff :size="13" /> {{ t('il.hiddenCode') }}</span>
                <span v-if="isOut && r.hide_code" class="block text-[12px] text-ink-faint">{{ t('il.hideCode') }}</span>
              </dd>
            </div>
            <div v-if="r.sample_info?.state_of_matter || r.sample_info?.registered_at" class="grid grid-cols-2 gap-2">
              <div v-if="r.sample_info?.state_of_matter">
                <dt class="text-[12px] text-ink-muted">{{ t('il.stateOfMatter') }}</dt>
                <dd class="text-ink">{{ r.sample_info.state_of_matter }}</dd>
              </div>
              <div v-if="r.sample_info?.registered_at">
                <dt class="text-[12px] text-ink-muted">{{ t('il.registeredAt') }}</dt>
                <dd class="text-ink tnum">{{ formatDate(r.sample_info.registered_at) }}</dd>
              </div>
            </div>
            <div>
              <dt class="text-[12px] text-ink-muted">{{ t('il.sender') }}</dt>
              <dd class="text-ink">{{ r.sender_name || '—' }}<span v-if="r.sender_position" class="text-ink-muted">, {{ r.sender_position }}</span>
                <span class="block text-[12px] text-ink-muted">{{ r.sender_lab }} · {{ formatDateTime(r.sent_at) }}</span>
              </dd>
            </div>
            <div class="grid grid-cols-2 gap-2">
              <div>
                <dt class="text-[12px] text-ink-muted">{{ t('il.deadline') }}</dt>
                <dd class="tnum" :class="r.overdue ? 'text-crit font-medium' : 'text-ink'">{{ r.deadline ? formatDate(r.deadline) : '—' }}</dd>
              </div>
              <div v-if="isOut">
                <dt class="text-[12px] text-ink-muted">{{ t('il.tolerance') }}</dt>
                <dd class="text-ink tnum">{{ hasTolerance ? '±' + fmtValue(r.tolerance_pct, 3) : '—' }}</dd>
              </div>
              <div v-else>
                <dt class="text-[12px] text-ink-muted">{{ t('il.group') }}</dt>
                <dd class="text-ink">{{ r.group ? localized(r.group, 'name') : '—' }}</dd>
              </div>
            </div>
            <div v-if="r.description">
              <dt class="text-[12px] text-ink-muted">{{ t('il.description') }}</dt>
              <dd class="text-ink whitespace-pre-line">{{ r.description }}</dd>
            </div>
            <div v-if="r.note">
              <dt class="text-[12px] text-ink-muted">{{ t('il.note') }}</dt>
              <dd class="text-ink whitespace-pre-line">{{ r.note }}</dd>
            </div>
          </dl>
        </section>

        <section class="panel p-4">
          <h2 class="text-[15px] font-semibold text-ink mb-3">{{ t('il.timeline') }}</h2>
          <ol class="relative ml-1.5 border-l border-line space-y-3">
            <li v-for="(e, i) in timeline" :key="i" class="pl-4 relative">
              <span class="absolute -left-[5px] top-1.5 h-2.5 w-2.5 rounded-full ring-2 ring-surface" :class="TONE_DOT[e.tone]" />
              <p class="text-[13.5px] text-ink">{{ e.label }}</p>
              <p class="text-[12px] text-ink-muted">{{ formatDateTime(e.at) }}<template v-if="e.extra"> · {{ e.extra }}</template></p>
            </li>
          </ol>
        </section>

        <section class="panel overflow-hidden">
          <button class="w-full px-4 py-3 flex items-center justify-between text-[14px] font-medium text-ink hover:bg-surface-sunken/50" @click="showMessages = !showMessages">
            <span>{{ t('il.messages') }} <span class="text-ink-faint font-normal">({{ r.messages.length }})</span></span>
            <ChevronDown :size="16" :class="showMessages ? 'rotate-180' : ''" class="transition-transform text-ink-muted" />
          </button>
          <ul v-if="showMessages" class="border-t border-line divide-y divide-line">
            <li v-for="m in r.messages" :key="m.id" class="px-4 py-2.5 text-[13px]">
              <div class="flex items-center justify-between gap-2">
                <span class="text-ink">{{ t(`il.msgType.${m.type.replace('.', '_')}`) }} <span class="text-[11.5px] text-ink-faint">· {{ m.direction === 'out' ? t('il.outMsg') : t('il.inMsg') }}</span></span>
                <span class="text-[12px] font-medium" :class="MSG_TONE[m.status]">{{ t(`il.msgStatus.${m.status}`) }}</span>
              </div>
              <p class="text-[11.5px] text-ink-muted">
                {{ formatDateTime(m.sent_at || m.processed_at || m.created_at) }}
                <template v-if="m.direction === 'out' && m.attempts > 1"> · {{ t('il.attempts', { n: m.attempts }) }}</template>
                <template v-if="m.status === 'pending' && m.next_attempt_at"> · {{ t('il.nextAttempt', { t: formatDateTime(m.next_attempt_at) }) }}</template>
              </p>
              <p v-if="m.last_error && m.status !== 'sent'" class="text-[11.5px] text-crit break-words">{{ m.last_error }}</p>
              <button
                v-if="m.direction === 'out' && ['failed', 'pending'].includes(m.status) && r.can.retry"
                class="btn-ghost btn-sm mt-1 -ml-2" :disabled="busy" @click="retry(m)"
              ><RotateCcw :size="13" /> {{ t('il.retry') }}</button>
            </li>
          </ul>
        </section>
      </aside>
    </div>

    <!-- ====================== Modallar ====================== -->
    <BaseModal v-model="modal.cancel" :title="t('il.cancelTitle')" :subtitle="r.number" size="sm">
      <label class="field-label">{{ t('il.reason') }}</label>
      <textarea v-model="reason" rows="3" class="field-input" maxlength="1000" />
      <template #footer>
        <button class="btn-ghost" @click="modal.cancel = false">{{ t('common.close') }}</button>
        <button class="btn-danger" :disabled="busy || reason.trim().length < 3" @click="doCancel"><Ban :size="16" /> {{ t('il.cancel') }}</button>
      </template>
    </BaseModal>

    <BaseModal v-model="modal.reject" :title="t('il.rejectTitle')" :subtitle="r.number" size="sm">
      <label class="field-label">{{ t('il.reason') }}</label>
      <textarea v-model="reason" rows="3" class="field-input" maxlength="1000" />
      <template #footer>
        <button class="btn-ghost" @click="modal.reject = false">{{ t('common.close') }}</button>
        <button class="btn-danger" :disabled="busy || reason.trim().length < 3" @click="doReject"><XCircle :size="16" /> {{ t('il.reject') }}</button>
      </template>
    </BaseModal>

    <BaseModal v-model="modal.accept" :title="t('il.acceptTitle')" :subtitle="r.number" size="sm">
      <label class="field-label">{{ t('il.assignGroup') }}</label>
      <select v-model="groupId" class="field-input">
        <option value="">{{ t('il.myGroup') }}</option>
        <option v-for="g in groups" :key="g.id" :value="g.id">{{ localized(g, 'name') }}</option>
      </select>
      <template #footer>
        <button class="btn-ghost" @click="modal.accept = false">{{ t('common.close') }}</button>
        <button class="btn-primary" :disabled="busy" @click="doAccept"><CheckCircle2 :size="16" /> {{ t('il.accept') }}</button>
      </template>
    </BaseModal>

    <BaseModal v-model="modal.send" :title="t('il.sendResultsTitle')" :subtitle="r.number" size="sm">
      <p class="text-[13.5px] text-ink-muted">{{ t('il.sendResultsBody', { lab: peerName }) }}</p>
      <template v-if="r.result_revision > 0">
        <p class="mt-3 text-[13px] text-warn-dark dark:text-warn-light">{{ t('il.reviseHint') }}</p>
        <label class="field-label mt-2">{{ t('il.revisionReason') }}</label>
        <textarea v-model="reason" rows="2" class="field-input" maxlength="2000" />
      </template>
      <template #footer>
        <button class="btn-ghost" @click="modal.send = false">{{ t('common.close') }}</button>
        <button class="btn-primary" :disabled="busy || (r.result_revision > 0 && reason.trim().length < 3)" @click="doSend"><Send :size="16" /> {{ t('il.sendResults') }}</button>
      </template>
    </BaseModal>
  </div>
</template>
