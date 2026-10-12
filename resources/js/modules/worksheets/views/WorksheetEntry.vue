<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import { onBeforeRouteLeave, useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import {
  AlertTriangle, ArrowLeft, CheckCircle2, Columns2, Eye, EyeOff, Lock, Plus, Printer, RotateCcw,
  RotateCw, Save, ShieldCheck, Trash2, UserPlus, X, ZoomIn, ZoomOut,
} from 'lucide-vue-next'
import api from '@/lib/api'
import BaseModal from '@/components/ui/BaseModal.vue'
import ConfirmDialog from '@/components/ui/ConfirmDialog.vue'
import { useAuthStore } from '@/stores/auth'
import { useUiStore } from '@/stores/ui'
import { formatDateTime } from '@/lib/format'
import { localized } from '@/i18n'
import WsStatusBadge from '../components/WsStatusBadge.vue'
import ScanPanel from '../components/ScanPanel.vue'
import CandidatePicker from '../components/CandidatePicker.vue'
import { calculate } from '../engine'
import { invalidateSampleWorksheets } from '../sampleWorksheets'
import {
  apiErrorMessage, colLabel, displayInput, fmt, rowHasMeasurement, sortedMembers, stageBands, templateName,
} from '../helpers'
import '../i18n'

const route = useRoute()
const router = useRouter()
const { t, locale } = useI18n()
const auth = useAuthStore()
const ui = useUiStore()

const ws = ref(null)
const loading = ref(true)
const saving = ref(false)
const staff = ref([])
const serverErrors = ref({})

const form = reactive({ worksheet_date: '', header_values: {}, notes: '', rows: [], signoffs: {} })
const savedSnapshot = ref('')

/* ------------------------------------------------------------ yuklash */
function hydrate(data) {
  ws.value = data
  form.worksheet_date = data.worksheet_date ?? ''
  form.notes = data.notes ?? ''
  form.header_values = {}
  for (const p of data.template_snapshot?.params ?? []) {
    form.header_values[p.key] = displayInput(data.header_values?.[p.key] ?? '', locale.value)
  }
  form.rows = (data.rows ?? []).map((r) => {
    const inputs = {}
    for (const [k, v] of Object.entries(r.inputs ?? {})) inputs[k] = displayInput(v, locale.value)
    return {
      id: r.id ?? null,
      sample_id: Number(r.sample_id ?? data.sample_id),
      inputs,
      excluded: !!r.excluded,
      note: r.note ?? '',
      is_extra: !!r.is_extra,
      adder: r.adder?.name ?? '',
    }
  })
  const map = {}
  for (const s of data.signoffs ?? []) {
    map[`${s.stage}#${s.slot}`] = {
      performer_id: s.performer_id ?? '',
      performer_name: s.performer_name ?? '',
      performed_at: s.performed_at ?? '',
      other: !s.performer_id && !!s.performer_name,
    }
  }
  // Barcha bosqich/o'rin uchun bo'sh yozuv tayyorlab qo'yamiz (render paytida yaratilmasin)
  for (const st of data.template_snapshot?.stages ?? []) {
    for (let slot = 1; slot <= Math.max(0, st.signers ?? 1); slot++) {
      const k = `${st.key}#${slot}`
      if (!map[k]) map[k] = { performer_id: '', performer_name: '', performed_at: '', other: false }
    }
  }
  form.signoffs = map
  serverErrors.value = {}
  savedSnapshot.value = JSON.stringify(form)
}

async function load() {
  loading.value = true
  try {
    const { data } = await api.get(`/worksheets/${route.params.id}`)
    hydrate(data)
  } catch (e) {
    ui.error(apiErrorMessage(e, t('ws.loadError')))
    router.replace({ name: 'worksheets' })
  } finally {
    loading.value = false
  }
}

onMounted(async () => {
  await load()
  try {
    const { data } = await api.get('/worksheets/staff')
    staff.value = data
  } catch { /* ixtiyoriy */ }
})

/* ------------------------------------------------------------ ta'rif */
const def = computed(() => ws.value?.template_snapshot ?? { columns: [], params: [], stages: [] })
const rowCols = computed(() => def.value.columns.filter((c) => (c.scope ?? 'row') === 'row'))
const sumCols = computed(() => def.value.columns.filter((c) => c.scope === 'summary'))
const totCols = computed(() => def.value.columns.filter((c) => c.scope === 'total'))
const stages = computed(() => def.value.stages ?? [])
const bands = computed(() => stageBands(rowCols.value, stages.value, locale.value))
const hasBands = computed(() => bands.value.some((b) => b.key))
const editableCols = computed(() => rowCols.value.filter((c) => c.type !== 'computed'))

const locked = computed(() => ws.value?.status === 'approved')
const canEdit = computed(() => !locked.value && auth.can('worksheets.update'))
const dirty = computed(() => !loading.value && JSON.stringify(form) !== savedSnapshot.value)

/* ------------------------------------------------------- partiya: probalar */
const members = computed(() => sortedMembers(ws.value))
const multi = computed(() => members.value.length > 1)
const memberBySample = computed(() => Object.fromEntries(members.value.map((m) => [m.sample_id, m])))
const isRetest = (sampleId) => memberBySample.value[sampleId]?.status === 'retest'

// Har bir proba va uning qatorlari (form.rows indekslari) — varaqdagi tartibda
const groups = computed(() => members.value.map((m) => ({
  member: m,
  sample: m.sample,
  rows: form.rows.map((r, i) => (Number(r.sample_id) === m.sample_id ? i : -1)).filter((i) => i >= 0),
})))

/* --------------------------------------------------------- jonli hisob */
const calc = computed(() => calculate(
  def.value,
  form.header_values,
  form.rows.map((r) => ({ values: r.inputs, excluded: r.excluded, group: r.sample_id })),
))
const groupCalc = (sampleId) => calc.value.groups.find((g) => String(g.key) === String(sampleId)) ?? { summary: {}, tolerance: {}, rows: [] }

// Faol (takroriy tahlilda bo'lmagan) probalardagi ±% buzilishlari
const toleranceFails = computed(() => calc.value.groups
  .filter((g) => !isRetest(Number(g.key)))
  .flatMap((g) => Object.values(g.tolerance).filter((x) => x.ok === false).map((x) => ({ ...x, sample: g.key }))))

const sampleElementIds = (sampleId) =>
  new Set((memberBySample.value[sampleId]?.sample?.sample_elements ?? []).map((se) => Number(se.element_id)))

function cellError(i, key) {
  return calc.value.rows[i]?.invalid?.[key] || serverErrors.value[`rows.${i}.inputs.${key}`]?.[0] || null
}

/* -------------------------------------------------------- qatorlar */
function defaultInputs() {
  const out = {}
  for (const c of rowCols.value) {
    if (c.type !== 'computed' && c.default_value !== null && c.default_value !== undefined && c.default_value !== '') {
      out[c.key] = displayInput(c.default_value, locale.value)
    }
  }
  return out
}
const hasData = (row) => rowHasMeasurement(row.inputs, defaultInputs())

function addParallel(sampleId) {
  const g = groups.value.find((x) => x.member.sample_id === Number(sampleId))
  const at = g && g.rows.length ? g.rows[g.rows.length - 1] + 1 : form.rows.length
  const extra = !!ws.value?.printed_at || (g?.rows ?? []).some((i) => form.rows[i].id && hasData(form.rows[i]))
  form.rows.splice(at, 0, {
    id: null, sample_id: Number(sampleId), inputs: defaultInputs(), excluded: false, note: '', is_extra: extra, adder: '',
  })
}

function canRemoveRow(i) {
  const r = form.rows[i]
  const g = groups.value.find((x) => x.member.sample_id === Number(r.sample_id))
  return (g?.rows.length ?? 0) > 1 && (!r.id || !hasData(r))
}

function removeRow(i) {
  if (!canRemoveRow(i)) {
    ui.info(t('ws.cannotRemoveMeasured'))
    return
  }
  form.rows.splice(i, 1)
}

// Hisobdan chiqarish: o'lchovli qator — sabab so'raladi
const excludeOpen = ref(false)
const excludeIndex = ref(-1)
const excludeReason = ref('')
function toggleExclude(i) {
  const r = form.rows[i]
  if (r.excluded) {
    r.excluded = false
    return
  }
  if (!hasData(r)) {
    r.excluded = true
    return
  }
  excludeIndex.value = i
  excludeReason.value = r.note || ''
  excludeOpen.value = true
}
function confirmExclude() {
  const r = form.rows[excludeIndex.value]
  if (r && excludeReason.value.trim().length >= 3) {
    r.excluded = true
    r.note = excludeReason.value.trim()
  }
  excludeOpen.value = false
}

// Enter / Shift+Enter / ↑ / ↓ — qog'ozdagidek ustun bo'ylab pastga kiritish
function onCellKey(e, r, c) {
  let dr = 0
  if (e.key === 'Enter') dr = e.shiftKey ? -1 : 1
  else if (e.key === 'ArrowDown') dr = 1
  else if (e.key === 'ArrowUp') dr = -1
  if (!dr) return
  e.preventDefault()
  const target = document.querySelector(`[data-ws-cell="${r + dr}:${c}"]`)
  if (target) {
    target.focus()
    target.select?.()
  } else if (dr > 0 && e.key === 'Enter') {
    const next = document.querySelector(`[data-ws-cell="0:${c + 1}"]`)
    next?.focus()
    next?.select?.()
  }
}

/* -------------------------------------------------------- imzolar */
const EMPTY_SIGNOFF = Object.freeze({ performer_id: '', performer_name: '', performed_at: '', other: false })
function signoff(stage, slot) {
  return form.signoffs[`${stage}#${slot}`] ?? EMPTY_SIGNOFF
}

function onPerformerChange(s, value) {
  if (value === '__other') {
    s.other = true
    s.performer_id = ''
  } else {
    s.other = false
    s.performer_id = value
    s.performer_name = ''
    if (value && !s.performed_at) s.performed_at = form.worksheet_date
  }
}

/* -------------------------------------------------------- saqlash */
function payload() {
  return {
    worksheet_date: form.worksheet_date,
    header_values: form.header_values,
    notes: form.notes || null,
    rows: form.rows.map((r) => ({
      id: r.id, sample_id: r.sample_id, inputs: r.inputs, excluded: r.excluded, note: r.note || null,
    })),
    signoffs: Object.entries(form.signoffs)
      .map(([k, s]) => {
        const [stage, slot] = k.split('#')
        return {
          stage,
          slot: Number(slot),
          performer_id: s.other ? null : (s.performer_id || null),
          performer_name: s.other ? (s.performer_name || null) : null,
          performed_at: s.performed_at || null,
        }
      })
      .filter((s) => s.performer_id || s.performer_name || s.performed_at),
  }
}

async function save({ silent = false } = {}) {
  if (!canEdit.value) return false
  saving.value = true
  try {
    const { data } = await api.put(`/worksheets/${ws.value.id}`, payload())
    hydrate(data)
    if (!silent) ui.success(t('ws.saved'))
    return true
  } catch (e) {
    serverErrors.value = e?.response?.data?.errors ?? {}
    ui.error(apiErrorMessage(e, t('ws.saveError')))
    return false
  } finally {
    saving.value = false
  }
}

// Probalar bilan amal (qo'shish, olib tashlash, takroriy tahlil) — avval saqlanmagan o'zgarishlar saqlanadi
async function withSaved(fn) {
  if (dirty.value && !(await save({ silent: true }))) return
  saving.value = true
  try {
    const { data } = await fn()
    hydrate(data)
    members.value.forEach((m) => invalidateSampleWorksheets(m.sample_id))
    return true
  } catch (e) {
    ui.error(apiErrorMessage(e, t('ws.saveError')))
    return false
  } finally {
    saving.value = false
  }
}

async function complete() {
  if (dirty.value && !(await save({ silent: true }))) return
  saving.value = true
  try {
    const { data } = await api.post(`/worksheets/${ws.value.id}/complete`)
    hydrate(data)
    ui.success(t('ws.completed'))
  } catch (e) {
    ui.error(apiErrorMessage(e, t('ws.saveError')))
  } finally {
    saving.value = false
  }
}

function goPrint() {
  const go = () => router.push({ name: 'worksheet-print', params: { id: ws.value.id } })
  if (dirty.value && canEdit.value) save({ silent: true }).then((ok) => ok && go())
  else go()
}

/* ------------------------------------------- partiyaga proba qo'shish */
const addOpen = ref(false)
const addSelected = ref([])
async function addSamples() {
  if (!addSelected.value.length) return
  const ok = await withSaved(() => api.post(`/worksheets/${ws.value.id}/samples`, {
    samples: addSelected.value.map((s) => ({ id: s.id, parallels: s.parallels })),
  }))
  if (ok) {
    addSelected.value.forEach((s) => invalidateSampleWorksheets(s.id))
    addOpen.value = false
    addSelected.value = []
    ui.success(t('ws.saved'))
  }
}

const removeTarget = ref(null)
async function removeSample() {
  const m = removeTarget.value
  removeTarget.value = null
  if (!m) return
  const ok = await withSaved(() => api.delete(`/worksheets/${ws.value.id}/samples/${m.sample_id}`))
  if (ok) invalidateSampleWorksheets(m.sample_id)
}
const canRemoveSample = (g) => multi.value && !g.rows.some((i) => form.rows[i].id && hasData(form.rows[i]))

/* ---------------------------------------------------- takroriy tahlil */
const retestTarget = ref(null)
const retestReason = ref('')
function openRetest(m) {
  retestTarget.value = m
  retestReason.value = ''
}
async function markRetest() {
  const m = retestTarget.value
  if (!m || retestReason.value.trim().length < 3) return
  const ok = await withSaved(() => api.post(`/worksheets/${ws.value.id}/samples/${m.sample_id}/retest`, { reason: retestReason.value.trim() }))
  if (ok) retestTarget.value = null
}
async function clearRetest(m) {
  await withSaved(() => api.delete(`/worksheets/${ws.value.id}/samples/${m.sample_id}/retest`))
}

/* ------------------------------------------------------ tasdiqlash */
const approveOpen = ref(false)
const pushResults = ref(true)
const acceptWarnings = ref(false)
const approveBusy = ref(false)

function openApprove() {
  pushResults.value = true
  acceptWarnings.value = false
  approveOpen.value = true
}

async function approve() {
  approveBusy.value = true
  try {
    const { data } = await api.post(`/worksheets/${ws.value.id}/approve`, {
      push_results: pushResults.value,
      accept_warnings: acceptWarnings.value,
    })
    hydrate(data)
    members.value.forEach((m) => invalidateSampleWorksheets(m.sample_id))
    approveOpen.value = false
    const rep = data.push_report ?? { pushed: [], skipped: [] }
    ui.success(t('ws.approved') + (rep.pushed.length ? ` · ${t('ws.pushReport', { list: rep.pushed.join('; ') })}` : ''))
    if (rep.skipped.length) ui.info(t('ws.pushSkipped', { list: rep.skipped.join('; ') }))
  } catch (e) {
    ui.error(apiErrorMessage(e, t('ws.saveError')))
  } finally {
    approveBusy.value = false
  }
}

const reopenOpen = ref(false)
const reopenReason = ref('')
async function reopen() {
  approveBusy.value = true
  try {
    const { data } = await api.post(`/worksheets/${ws.value.id}/reopen`, { reason: reopenReason.value })
    hydrate(data)
    reopenOpen.value = false
    reopenReason.value = ''
    ui.success(t('ws.reopened'))
  } catch (e) {
    ui.error(apiErrorMessage(e, t('ws.saveError')))
  } finally {
    approveBusy.value = false
  }
}

const deleteOpen = ref(false)
async function destroy() {
  try {
    await api.delete(`/worksheets/${ws.value.id}`)
    members.value.forEach((m) => invalidateSampleWorksheets(m.sample_id))
    savedSnapshot.value = JSON.stringify(form)
    ui.success(t('ws.deleted'))
    router.push({ name: 'worksheets' })
  } catch (e) {
    ui.error(apiErrorMessage(e, t('ws.saveError')))
  } finally {
    deleteOpen.value = false
  }
}

/* --------------------------------------------- skan bilan solishtirish */
const compare = ref(false)
const scanPanel = ref(null)
const activeScan = ref(null)
const activeUrl = ref('')
const zoom = ref(1)
const rotate = ref(0)

const imageScans = computed(() => (ws.value?.scans ?? []).filter((s) => (s.mime || '').startsWith('image/')))

async function selectScan(scan) {
  activeScan.value = scan
  zoom.value = 1
  rotate.value = 0
  compare.value = true
  await nextTick()
  activeUrl.value = scanPanel.value ? await scanPanel.value.loadUrl(scan) : ''
}

function toggleCompare() {
  if (compare.value) {
    compare.value = false
  } else if (imageScans.value.length) {
    selectScan(activeScan.value && imageScans.value.some((s) => s.id === activeScan.value.id) ? activeScan.value : imageScans.value[0])
  }
}

async function reloadScans() {
  const { data } = await api.get(`/worksheets/${ws.value.id}`)
  ws.value = { ...ws.value, scans: data.scans }
}

/* ----------------------------------------- saqlanmagan o'zgarishlar */
function beforeUnload(e) {
  if (dirty.value) {
    e.preventDefault()
    e.returnValue = ''
  }
}
window.addEventListener('beforeunload', beforeUnload)
onBeforeUnmount(() => window.removeEventListener('beforeunload', beforeUnload))
onBeforeRouteLeave(() => {
  if (dirty.value && !window.confirm(t('ws.leaveConfirm'))) return false
  return true
})

// Ctrl+S — saqlash
function onKey(e) {
  if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's') {
    e.preventDefault()
    if (dirty.value) save()
  }
}
window.addEventListener('keydown', onKey)
onBeforeUnmount(() => window.removeEventListener('keydown', onKey))

watch(() => route.params.id, (id, old) => { if (id && id !== old) load() })

const performerOptions = computed(() => staff.value)
const firstTolerance = (sampleId) => Object.values(groupCalc(sampleId).tolerance)[0] ?? null
</script>

<template>
  <div v-if="loading" class="space-y-4">
    <div class="h-16 panel animate-pulse" />
    <div class="h-72 panel animate-pulse" />
  </div>

  <div v-else-if="ws">
    <!-- ======================= Sarlavha ======================= -->
    <div class="flex flex-wrap items-start justify-between gap-3 mb-4">
      <div class="flex items-start gap-3 min-w-0">
        <button class="btn-ghost btn-icon mt-0.5" :title="t('ws.back')" @click="router.push({ name: 'worksheets' })">
          <ArrowLeft :size="19" />
        </button>
        <div class="min-w-0">
          <div class="flex flex-wrap items-center gap-2.5">
            <h1 class="text-[22px] font-semibold tracking-[-.01em] text-ink leading-tight">
              {{ t('ws.number') }} <span class="font-mono">{{ ws.number }}</span>
            </h1>
            <WsStatusBadge :status="ws.status" />
            <span v-if="dirty" class="inline-flex items-center gap-1 text-[12px] text-warn-dark dark:text-warn-light">
              <span class="h-1.5 w-1.5 rounded-full bg-warn" /> {{ t('ws.unsaved') }}
            </span>
          </div>
          <p class="mt-1 text-[13.5px] text-ink-muted">
            {{ templateName(def, locale) }}
            <span class="font-mono text-ink-faint">· {{ def.code }}</span>
            <template v-if="multi"> · {{ t('ws.samplesN', { n: members.length }) }}</template>
            <template v-else>
              · {{ t('ws.sample') }} <span class="font-mono text-ink">{{ members[0]?.sample?.code ?? ws.sample?.code }}</span>
              (№ {{ members[0]?.sample?.sample_number ?? ws.sample?.sample_number }})
            </template>
          </p>
        </div>
      </div>

      <div class="flex flex-wrap items-center gap-2">
        <button v-if="imageScans.length" class="btn-outline" :class="compare && 'border-assay-500 text-assay-700 dark:text-assay-300'" @click="toggleCompare">
          <Columns2 :size="16" /> {{ compare ? t('ws.compareOff') : t('ws.compare') }}
        </button>
        <button class="btn-outline" @click="goPrint">
          <Printer :size="16" /> {{ t('ws.print') }}
        </button>
        <template v-if="canEdit">
          <button class="btn-outline" :disabled="saving || !dirty" @click="save()">
            <Save :size="16" /> {{ t('ws.save') }}
          </button>
          <button v-if="ws.status !== 'completed'" class="btn-primary" :disabled="saving" @click="complete">
            <CheckCircle2 :size="16" /> {{ t('ws.complete') }}
          </button>
        </template>
        <button
          v-if="ws.status === 'completed' && auth.can('worksheets.approve')"
          class="btn-primary" :disabled="dirty" @click="openApprove"
        >
          <ShieldCheck :size="16" /> {{ t('ws.approve') }}
        </button>
        <button v-if="locked && auth.can('worksheets.approve')" class="btn-outline" @click="reopenOpen = true">
          <RotateCcw :size="16" /> {{ t('ws.reopen') }}
        </button>
        <button v-if="!locked && auth.can('worksheets.delete')" class="btn-ghost btn-icon text-crit" :title="t('ws.delete')" @click="deleteOpen = true">
          <Trash2 :size="17" />
        </button>
      </div>
    </div>

    <div v-if="locked" class="mb-4 flex items-center gap-3 rounded-panel border border-ok/30 bg-ok-light dark:bg-ok-dark/20 px-4 py-3 text-[13.5px] text-ok-dark dark:text-ok-light">
      <Lock :size="17" class="shrink-0" />
      <span>
        {{ t('ws.lockedBanner', { who: ws.approver?.name ?? '—', when: formatDateTime(ws.approved_at) }) }}
        <template v-if="ws.results_pushed_at"> · {{ t('ws.resultsPushed') }}</template>
      </span>
    </div>

    <div :class="compare ? 'grid gap-4 xl:grid-cols-[minmax(0,5fr)_minmax(0,7fr)]' : ''">
      <!-- ================= Skan ko'rinishi (solishtirish) ================= -->
      <aside v-if="compare" class="panel p-3 self-start xl:sticky xl:top-20">
        <div class="flex items-center justify-between gap-2 mb-2">
          <select
            class="field-input h-8 text-[13px] w-auto max-w-[60%]"
            :value="activeScan?.id"
            @change="selectScan(imageScans.find((s) => s.id === Number($event.target.value)))"
          >
            <option v-for="s in imageScans" :key="s.id" :value="s.id">{{ s.original_name }}</option>
          </select>
          <div class="flex items-center gap-1">
            <button class="btn-ghost btn-icon h-8 w-8" @click="zoom = Math.max(0.5, zoom - 0.25)"><ZoomOut :size="16" /></button>
            <span class="text-[12px] text-ink-muted tnum w-10 text-center">{{ Math.round(zoom * 100) }}%</span>
            <button class="btn-ghost btn-icon h-8 w-8" @click="zoom = Math.min(4, zoom + 0.25)"><ZoomIn :size="16" /></button>
            <button class="btn-ghost btn-icon h-8 w-8" @click="rotate = (rotate + 90) % 360"><RotateCw :size="16" /></button>
            <button class="btn-ghost btn-icon h-8 w-8" @click="compare = false"><X :size="16" /></button>
          </div>
        </div>
        <div class="overflow-auto rounded-md bg-surface-sunken border border-line max-h-[calc(100vh-11rem)]">
          <img
            v-if="activeUrl" :src="activeUrl" alt=""
            class="max-w-none origin-top-left transition-transform"
            :style="{ width: `${zoom * 100}%`, transform: `rotate(${rotate}deg)` }"
          >
        </div>
      </aside>

      <div class="space-y-4 min-w-0">
        <!-- ================= Ma'lumotlar + sarlavha parametrlari ================= -->
        <section class="panel p-4">
          <div class="grid gap-x-6 gap-y-3 sm:grid-cols-2 lg:grid-cols-4 text-[13.5px]">
            <div>
              <p class="text-ink-muted text-[12px]">{{ multi ? t('ws.samples') : t('ws.sample') }}</p>
              <template v-if="multi">
                <p class="text-ink font-medium">{{ t('ws.samplesN', { n: members.length }) }}</p>
                <p class="text-[12px] text-ink-faint truncate" :title="members.map((m) => m.sample?.code).join(', ')">
                  {{ members.map((m) => m.sample?.sample_number).join(', ') }}
                </p>
              </template>
              <template v-else>
                <p class="text-ink font-medium"><span class="font-mono">{{ members[0]?.sample?.code }}</span> · № {{ members[0]?.sample?.sample_number }}</p>
                <p class="text-[12px] text-ink-faint">
                  {{ (members[0]?.sample?.sample_elements ?? []).map((se) => se.element?.symbol).filter(Boolean).join(', ') }}
                </p>
              </template>
            </div>
            <div>
              <p class="text-ink-muted text-[12px]">{{ t('ws.template') }}</p>
              <p class="text-ink font-medium font-mono">{{ def.code }}<span v-if="def.version" class="text-ink-faint"> · v{{ def.version }}</span></p>
              <p v-if="!multi" class="text-[12px] text-ink-faint">{{ t('ws.group') }}: {{ localized(members[0]?.sample?.assigned_group, 'name') || '—' }}</p>
            </div>
            <div>
              <label class="text-ink-muted text-[12px] block mb-0.5">{{ t('ws.date') }}</label>
              <input v-model="form.worksheet_date" type="date" class="field-input h-9" :disabled="!canEdit">
            </div>
            <div>
              <p class="text-ink-muted text-[12px]">{{ t('ws.createdBy') }}</p>
              <p class="text-ink">{{ ws.creator?.name ?? '—' }}</p>
              <p class="text-[12px] text-ink-faint">{{ t('ws.printedAt') }}: {{ ws.printed_at ? formatDateTime(ws.printed_at) : '—' }}</p>
            </div>
          </div>

          <div v-if="def.params?.length" class="mt-4 pt-4 border-t border-line">
            <p class="text-[12px] font-medium uppercase tracking-wide text-ink-muted mb-2">{{ t('ws.header') }}</p>
            <div class="flex flex-wrap gap-4">
              <label v-for="p in def.params" :key="p.key" class="flex items-center gap-2 text-[13.5px]">
                <span class="text-ink">{{ colLabel(p, locale) }}</span>
                <input
                  v-model="form.header_values[p.key]" inputmode="decimal"
                  class="field-input h-9 w-28 text-right tnum" :disabled="!canEdit"
                  :class="serverErrors[`header_values.${p.key}`] && 'field-input-error'"
                >
                <span v-if="p.unit" class="text-ink-muted">{{ p.unit }}</span>
              </label>
            </div>
          </div>
        </section>

        <!-- ================= Asosiy jadval (probalar bo'yicha guruhlangan) ================= -->
        <section class="panel overflow-hidden">
          <div class="flex flex-wrap items-center justify-between gap-2 px-4 pt-3.5 pb-2.5">
            <h2 class="text-[15px] font-semibold text-ink">{{ t('ws.grid') }}</h2>
            <p class="text-[12px] text-ink-faint">{{ t('ws.gridHint') }}</p>
          </div>
          <div class="overflow-x-auto">
            <table class="w-full text-[13px] border-collapse">
              <thead>
                <tr v-if="hasBands" class="text-[11px] uppercase tracking-wide text-ink-muted">
                  <th class="w-14" />
                  <th
                    v-for="(b, bi) in bands" :key="bi" :colspan="b.span"
                    class="px-2 pt-2 pb-1 font-medium text-left border-t-[3px] align-bottom" :class="b.tone"
                  >{{ b.label }}</th>
                  <th class="w-24" />
                </tr>
                <tr class="bg-surface-sunken/70 border-y border-line text-[12px] text-ink-muted">
                  <th class="px-3 py-2 text-left font-medium w-14">{{ t('ws.row') }}</th>
                  <th
                    v-for="c in rowCols" :key="c.key"
                    class="px-2 py-2 font-medium align-bottom leading-snug min-w-[96px]"
                    :class="c.type === 'text' ? 'text-left' : 'text-right'"
                  >
                    <span class="block">{{ colLabel(c, locale) }}</span>
                    <span v-if="c.type === 'computed'" class="block text-[10.5px] font-normal text-assay-600 dark:text-assay-300">ƒ {{ c.formula }}</span>
                  </th>
                  <th class="w-24" />
                </tr>
              </thead>
              <tbody v-for="g in groups" :key="g.member.sample_id" class="divide-y divide-line border-b-2 border-line">
                <!-- Proba sarlavhasi (partiya varaqda) -->
                <tr v-if="multi" class="bg-surface-sunken/40">
                  <td :colspan="rowCols.length + 2" class="px-3 py-1.5">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                      <div class="flex flex-wrap items-center gap-2 min-w-0 text-[13px]">
                        <span class="font-mono font-semibold text-ink">{{ g.sample?.code }}</span>
                        <span class="text-ink">№ {{ g.sample?.sample_number }}</span>
                        <span class="text-[12px] text-ink-faint">
                          {{ (g.sample?.sample_elements ?? []).map((se) => se.element?.symbol).filter(Boolean).join(', ') }}
                        </span>
                        <span
                          v-if="g.member.status === 'retest'"
                          class="rounded-full bg-warn-light text-warn-dark dark:bg-warn-dark/25 dark:text-warn-light px-2 py-0.5 text-[11px] font-medium"
                          :title="t('ws.retestBy', { who: g.member.retest_user?.name ?? '—', reason: g.member.retest_reason ?? '' })"
                        >
                          <RotateCcw :size="11" class="inline -mt-0.5" /> {{ t('ws.retest') }}: {{ g.member.retest_reason }}
                        </span>
                      </div>
                      <div v-if="canEdit" class="flex items-center gap-1 shrink-0">
                        <button class="btn-ghost btn-sm" :title="t('ws.addParallel')" @click="addParallel(g.member.sample_id)">
                          <Plus :size="14" /> {{ t('ws.parallels') }}
                        </button>
                        <button
                          v-if="g.member.status !== 'retest'" class="btn-ghost btn-sm text-warn-dark dark:text-warn-light"
                          :title="t('ws.retestMark')" @click="openRetest(g.member)"
                        >
                          <RotateCcw :size="14" /> {{ t('ws.retest') }}
                        </button>
                        <button v-else class="btn-ghost btn-sm" @click="clearRetest(g.member)">
                          <X :size="14" /> {{ t('ws.retestUnmark') }}
                        </button>
                        <button
                          v-if="canRemoveSample(g)" class="btn-ghost btn-icon h-7 w-7 hover:text-crit"
                          :title="t('ws.removeSample')" @click="removeTarget = g.member"
                        >
                          <Trash2 :size="14" />
                        </button>
                      </div>
                    </div>
                  </td>
                </tr>

                <!-- Parallellar -->
                <tr
                  v-for="(i, pi) in g.rows" :key="form.rows[i].id ?? `new-${i}`"
                  class="transition-colors"
                  :class="[
                    form.rows[i].excluded && 'opacity-55 bg-surface-sunken/50',
                    g.member.status === 'retest' && !form.rows[i].excluded && 'bg-warn-light/30 dark:bg-warn-dark/10',
                    calc.rows[i]?.out_of_tolerance?.length && !form.rows[i].excluded && 'bg-crit-light/60 dark:bg-crit-dark/15',
                  ]"
                >
                  <td class="px-3 py-1.5 text-ink-muted tnum align-middle whitespace-nowrap">
                    <span class="font-medium">{{ multi ? pi + 1 : i + 1 }}</span>
                    <span
                      v-if="form.rows[i].is_extra"
                      class="ml-1 rounded bg-aqua-50 text-aqua-700 dark:bg-aqua-800/35 dark:text-aqua-200 px-1 text-[10px] font-medium"
                      :title="t('ws.extraHint', { who: form.rows[i].adder || '—' })"
                    >+</span>
                    <AlertTriangle
                      v-if="calc.rows[i]?.out_of_tolerance?.length && !form.rows[i].excluded"
                      :size="13" class="inline ml-1 -mt-0.5 text-crit" :title="t('ws.outOfTolerance')"
                    />
                  </td>
                  <td
                    v-for="c in rowCols" :key="c.key" class="px-1.5 py-1 align-middle"
                    :class="c.type === 'computed' && 'bg-assay-50/50 dark:bg-assay-800/10'"
                  >
                    <div
                      v-if="c.type === 'computed'"
                      class="px-2 h-8 flex items-center justify-end tnum font-semibold text-ink"
                    >{{ fmt(calc.rows[i]?.computed?.[c.key], c.decimals, locale) }}</div>
                    <input
                      v-else
                      v-model="form.rows[i].inputs[c.key]"
                      :data-ws-cell="`${i}:${editableCols.indexOf(c)}`"
                      :inputmode="c.type === 'input' ? 'decimal' : 'text'"
                      :disabled="!canEdit"
                      :title="cellError(i, c.key) || ''"
                      class="w-full h-8 px-2 rounded border bg-surface text-[13px] text-ink transition-colors
                             focus:outline-none focus:border-assay-500 disabled:bg-transparent disabled:border-transparent"
                      :class="[
                        c.type === 'input' ? 'text-right tnum' : 'text-left',
                        cellError(i, c.key) ? 'border-crit bg-crit-light/50' : 'border-line/70 hover:border-ink-faint',
                      ]"
                      @keydown="onCellKey($event, i, editableCols.indexOf(c))"
                    >
                  </td>
                  <td class="px-2 py-1 text-right whitespace-nowrap align-middle">
                    <span
                      v-if="form.rows[i].excluded" class="mr-1 text-[10.5px] uppercase tracking-wide text-ink-faint"
                      :title="form.rows[i].note || ''"
                    >{{ t('ws.excluded') }}</span>
                    <template v-if="canEdit">
                      <button class="btn-ghost btn-icon h-7 w-7" :title="form.rows[i].excluded ? t('ws.include') : t('ws.exclude')" @click="toggleExclude(i)">
                        <EyeOff v-if="!form.rows[i].excluded" :size="15" /><Eye v-else :size="15" />
                      </button>
                      <button
                        v-if="canRemoveRow(i)" class="btn-ghost btn-icon h-7 w-7 hover:text-crit"
                        :title="t('ws.removeRow')" @click="removeRow(i)"
                      >
                        <X :size="15" />
                      </button>
                    </template>
                  </td>
                </tr>
                <tr v-for="i in g.rows.filter((x) => form.rows[x].excluded && form.rows[x].note)" :key="`note-${i}`" class="text-[12px]">
                  <td />
                  <td :colspan="rowCols.length + 1" class="px-2 pb-1.5 -mt-1 italic text-ink-muted">
                    ↳ {{ t('ws.excluded') }}: {{ form.rows[i].note }}
                  </td>
                </tr>

                <!-- Proba natijasi (partiya varaqda) -->
                <tr v-if="multi && sumCols.length" class="bg-ok-light/25 dark:bg-ok-dark/10 text-[12.5px]">
                  <td />
                  <td :colspan="rowCols.length + 1" class="px-2 py-1.5">
                    <span v-for="c in sumCols" :key="c.key" class="mr-5 whitespace-nowrap">
                      <span class="text-ink-muted">{{ colLabel(c, locale) }}:</span>
                      <span class="font-semibold tnum text-ink ml-1">{{ fmt(groupCalc(g.member.sample_id).summary[c.key], c.decimals, locale) || '—' }}</span>
                      <span v-if="c.unit" class="text-ink-muted"> {{ c.unit }}</span>
                    </span>
                    <template v-if="firstTolerance(g.member.sample_id)">
                      <span v-if="firstTolerance(g.member.sample_id).n === 1" class="text-warn-dark dark:text-warn-light">{{ t('ws.uncontrolled') }}</span>
                      <span
                        v-else-if="firstTolerance(g.member.sample_id).ok !== null"
                        class="rounded-full px-2 py-0.5 font-medium tnum"
                        :class="firstTolerance(g.member.sample_id).ok
                          ? 'bg-ok-light text-ok-dark dark:bg-ok-dark/25 dark:text-ok-light'
                          : 'bg-crit-light text-crit-dark dark:bg-crit-dark/25 dark:text-crit-light'"
                      >
                        {{ t(firstTolerance(g.member.sample_id).ok ? 'ws.toleranceOk' : 'ws.toleranceBad', {
                          dev: fmt(firstTolerance(g.member.sample_id).deviation, 2, locale),
                          lim: fmt(firstTolerance(g.member.sample_id).limit, null, locale),
                        }) }}
                      </span>
                    </template>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
          <div v-if="canEdit" class="px-4 py-2.5 border-t border-line flex flex-wrap gap-2">
            <button v-if="!multi && members[0]" class="btn-ghost btn-sm" @click="addParallel(members[0].sample_id)">
              <Plus :size="15" /> {{ t('ws.addParallel') }}
            </button>
            <button v-if="auth.can('worksheets.update')" class="btn-ghost btn-sm" @click="addOpen = true">
              <UserPlus :size="15" /> {{ t('ws.addSample') }}
            </button>
            <button
              v-if="!multi && members[0] && members[0].status !== 'retest'"
              class="btn-ghost btn-sm text-warn-dark dark:text-warn-light" @click="openRetest(members[0])"
            >
              <RotateCcw :size="15" /> {{ t('ws.retestMark') }}
            </button>
            <button v-else-if="!multi && members[0]" class="btn-ghost btn-sm" @click="clearRetest(members[0])">
              <X :size="15" /> {{ t('ws.retestUnmark') }}
            </button>
          </div>
        </section>

        <!-- ================= Natija + bosqichlar ================= -->
        <div class="grid gap-4 lg:grid-cols-2">
          <section class="panel p-4">
            <h2 class="text-[15px] font-semibold text-ink mb-3">{{ multi ? t('ws.sampleResults') : t('ws.summary') }}</h2>

            <!-- Partiya: probalar × natijalar -->
            <div v-if="multi" class="overflow-x-auto -mx-1">
              <table class="w-full text-[13px]">
                <thead>
                  <tr class="text-left text-[12px] text-ink-muted border-b border-line">
                    <th class="px-1 py-1.5 font-medium">{{ t('ws.sample') }}</th>
                    <th v-for="c in sumCols" :key="c.key" class="px-1 py-1.5 font-medium text-right">
                      {{ colLabel(c, locale) }}
                      <span v-if="c.element_symbol" class="text-assay-600 dark:text-assay-300"> → {{ c.element_symbol }}</span>
                    </th>
                    <th class="px-1 py-1.5 font-medium text-right">{{ t('ws.tolerance') }}</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-line">
                  <tr v-for="g in groups" :key="g.member.sample_id" :class="g.member.status === 'retest' && 'opacity-70'">
                    <td class="px-1 py-1.5">
                      <span class="font-mono text-ink">{{ g.sample?.sample_number }}</span>
                      <span class="text-[11.5px] text-ink-faint"> {{ g.sample?.code }}</span>
                    </td>
                    <td v-for="c in sumCols" :key="c.key" class="px-1 py-1.5 text-right tnum font-semibold text-ink">
                      {{ fmt(groupCalc(g.member.sample_id).summary[c.key], c.decimals, locale) || '—' }}
                    </td>
                    <td class="px-1 py-1.5 text-right text-[12px] whitespace-nowrap">
                      <span v-if="g.member.status === 'retest'" class="text-warn-dark dark:text-warn-light">{{ t('ws.retest') }}</span>
                      <template v-else-if="firstTolerance(g.member.sample_id)">
                        <span v-if="firstTolerance(g.member.sample_id).n === 1" class="text-warn-dark dark:text-warn-light">{{ t('ws.uncontrolled') }}</span>
                        <span v-else-if="firstTolerance(g.member.sample_id).ok === null" class="text-ink-faint">—</span>
                        <span v-else :class="firstTolerance(g.member.sample_id).ok ? 'text-ok-dark dark:text-ok-light' : 'text-crit font-medium'">
                          {{ fmt(firstTolerance(g.member.sample_id).deviation, 2, locale) }}%
                        </span>
                      </template>
                      <span v-else class="text-ink-faint">—</span>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>

            <!-- Bitta proba: avvalgidek kartochkalar -->
            <div v-else class="space-y-3">
              <div v-for="c in sumCols" :key="c.key" class="rounded-md border border-line px-3.5 py-3">
                <div class="flex items-start justify-between gap-3">
                  <p class="text-[13px] text-ink-muted leading-snug">{{ colLabel(c, locale) }}</p>
                  <span
                    v-if="c.element_id"
                    class="shrink-0 rounded-full px-2 py-0.5 text-[11px] font-medium"
                    :class="sampleElementIds(members[0]?.sample_id).has(Number(c.element_id))
                      ? 'bg-assay-50 text-assay-700 dark:bg-assay-800/30 dark:text-assay-200'
                      : 'bg-surface-sunken text-ink-faint'"
                    :title="sampleElementIds(members[0]?.sample_id).has(Number(c.element_id)) ? '' : t('ws.pushNo')"
                  >{{ t('ws.toElement', { el: c.element_symbol || '?' }) }}</span>
                </div>
                <p class="mt-1 text-[26px] font-semibold tnum text-ink leading-none">
                  {{ fmt(groupCalc(members[0]?.sample_id).summary[c.key], c.decimals, locale) || '—' }}
                  <span v-if="c.unit" class="text-[14px] font-normal text-ink-muted">{{ c.unit }}</span>
                </p>
                <div v-if="groupCalc(members[0]?.sample_id).tolerance[c.key]" class="mt-2 flex items-center gap-2 text-[12.5px]">
                  <span class="text-ink-muted">{{ t('ws.tolerance') }}:</span>
                  <span v-if="groupCalc(members[0]?.sample_id).tolerance[c.key].n === 1" class="text-warn-dark dark:text-warn-light">{{ t('ws.uncontrolled') }}</span>
                  <span v-else-if="groupCalc(members[0]?.sample_id).tolerance[c.key].ok === null" class="text-ink-faint">{{ t('ws.toleranceNa') }}</span>
                  <span
                    v-else
                    class="rounded-full px-2 py-0.5 font-medium tnum"
                    :class="groupCalc(members[0]?.sample_id).tolerance[c.key].ok
                      ? 'bg-ok-light text-ok-dark dark:bg-ok-dark/25 dark:text-ok-light'
                      : 'bg-crit-light text-crit-dark dark:bg-crit-dark/25 dark:text-crit-light'"
                  >
                    {{ t(groupCalc(members[0]?.sample_id).tolerance[c.key].ok ? 'ws.toleranceOk' : 'ws.toleranceBad', {
                      dev: fmt(groupCalc(members[0]?.sample_id).tolerance[c.key].deviation, 2, locale),
                      lim: fmt(groupCalc(members[0]?.sample_id).tolerance[c.key].limit, null, locale),
                    }) }}
                  </span>
                </div>
              </div>
              <p v-if="members[0]?.status === 'retest'" class="rounded-md bg-warn-light dark:bg-warn-dark/20 px-3 py-2 text-[12.5px] text-warn-dark dark:text-warn-light">
                <RotateCcw :size="13" class="inline -mt-0.5" /> {{ t('ws.retest') }}: {{ members[0].retest_reason }} — {{ t('ws.pushWillSkip') }}
              </p>
            </div>

            <!-- Varaq bo'yicha jami -->
            <div v-if="totCols.length" class="mt-3 pt-3 border-t border-line">
              <p class="text-[12px] font-medium uppercase tracking-wide text-ink-muted mb-1.5">{{ t('ws.totals') }}</p>
              <div v-for="c in totCols" :key="c.key" class="flex items-center justify-between gap-3 text-[13.5px] py-0.5">
                <span class="text-ink-muted">{{ colLabel(c, locale) }}</span>
                <span class="font-semibold tnum text-ink">{{ fmt(calc.totals[c.key], c.decimals, locale) || '—' }} <span class="font-normal text-ink-muted">{{ c.unit }}</span></span>
              </div>
            </div>

            <div v-if="calc.errors.length" class="mt-3 rounded-md border border-crit/40 bg-crit-light dark:bg-crit-dark/20 px-3 py-2.5 text-[12.5px] text-crit-dark dark:text-crit-light">
              <p class="font-medium mb-1">{{ t('ws.formulaErrors') }}</p>
              <p v-for="(e, ei) in calc.errors" :key="ei"><span class="font-mono">{{ e.key }}</span>: {{ e.message }}</p>
            </div>
          </section>

          <section class="panel p-4">
            <h2 class="text-[15px] font-semibold text-ink">{{ t('ws.stages') }}</h2>
            <p class="text-[12.5px] text-ink-muted mb-3">{{ t('ws.stagesHint') }}</p>
            <div class="space-y-2.5">
              <template v-for="s in stages" :key="s.key">
                <div v-for="slot in Math.max(0, s.signers ?? 1)" :key="`${s.key}#${slot}`" class="grid grid-cols-[minmax(0,1.1fr)_minmax(0,1.4fr)_minmax(0,1fr)] gap-2 items-center">
                  <p class="text-[13px] text-ink leading-tight">
                    {{ colLabel(s, locale) }}<span v-if="(s.signers ?? 1) > 1" class="text-ink-faint"> ({{ slot }})</span>
                  </p>
                  <div>
                    <input
                      v-if="signoff(s.key, slot).other"
                      v-model="signoff(s.key, slot).performer_name" class="field-input h-9"
                      :placeholder="t('ws.performerName')" :disabled="!canEdit"
                      @keydown.esc="signoff(s.key, slot).other = false"
                    >
                    <select
                      v-else class="field-input h-9" :disabled="!canEdit"
                      :value="signoff(s.key, slot).performer_id"
                      @change="onPerformerChange(signoff(s.key, slot), $event.target.value)"
                    >
                      <option value="">—</option>
                      <option v-for="u in performerOptions" :key="u.id" :value="u.id">{{ u.name }}</option>
                      <option value="__other">{{ t('ws.performerOther') }}</option>
                    </select>
                  </div>
                  <input v-model="signoff(s.key, slot).performed_at" type="date" class="field-input h-9" :disabled="!canEdit">
                </div>
              </template>
            </div>
            <div class="mt-4">
              <label class="field-label">{{ t('ws.notes') }}</label>
              <textarea v-model="form.notes" rows="2" class="field-input" :disabled="!canEdit" />
            </div>
          </section>
        </div>

        <ScanPanel
          ref="scanPanel"
          :worksheet-id="ws.id"
          :scans="ws.scans ?? []"
          :can-upload="auth.can('worksheets.update')"
          :can-delete="canEdit"
          :selected-id="compare ? activeScan?.id : null"
          @changed="reloadScans"
          @select="selectScan"
        />
      </div>
    </div>

    <!-- ================= Tasdiqlash ================= -->
    <BaseModal v-model="approveOpen" :title="t('ws.approveTitle')" size="md">
      <p class="text-[13.5px] text-ink-muted">{{ t('ws.approveText') }}</p>
      <div class="mt-4 space-y-2 max-h-[45vh] overflow-y-auto">
        <div v-for="g in groups" :key="g.member.sample_id" class="rounded-md bg-surface-sunken px-3 py-2 text-[13.5px]">
          <p v-if="multi" class="text-[12.5px] text-ink-muted mb-1">
            <span class="font-mono text-ink">{{ g.sample?.code }}</span> · № {{ g.sample?.sample_number }}
          </p>
          <p v-if="g.member.status === 'retest'" class="text-[12.5px] text-warn-dark dark:text-warn-light">
            <RotateCcw :size="12" class="inline -mt-0.5" /> {{ t('ws.retest') }} — {{ t('ws.pushWillSkip') }}
          </p>
          <template v-else>
            <div v-for="c in sumCols" :key="c.key" class="flex items-center justify-between gap-3">
              <span class="text-ink">{{ colLabel(c, locale) }}</span>
              <span class="flex items-center gap-2">
                <span class="font-semibold tnum text-ink">{{ fmt(groupCalc(g.member.sample_id).summary[c.key], c.decimals, locale) || '—' }} {{ c.unit }}</span>
                <span v-if="c.element_id && !sampleElementIds(g.member.sample_id).has(Number(c.element_id))" class="text-[11.5px] text-ink-faint">({{ t('ws.pushNo') }})</span>
                <span v-else-if="c.element_id" class="text-[11.5px] text-assay-600 dark:text-assay-300">→ {{ c.element_symbol }}</span>
              </span>
            </div>
          </template>
        </div>
      </div>
      <label class="mt-4 flex items-center gap-2.5 text-[13.5px] text-ink cursor-pointer">
        <input v-model="pushResults" type="checkbox" class="rounded border-line text-assay-500 focus:ring-assay-500">
        {{ t('ws.pushResults') }}
      </label>
      <label v-if="toleranceFails.length" class="mt-3 flex items-start gap-2.5 rounded-md border border-crit/40 bg-crit-light dark:bg-crit-dark/20 px-3 py-2.5 text-[13px] text-crit-dark dark:text-crit-light cursor-pointer">
        <input v-model="acceptWarnings" type="checkbox" class="mt-0.5 rounded border-crit text-crit focus:ring-crit">
        <span>{{ t('ws.acceptWarnings') }}</span>
      </label>
      <template #footer>
        <button class="btn-ghost" @click="approveOpen = false">{{ t('common.cancel') }}</button>
        <button class="btn-primary" :disabled="approveBusy || (toleranceFails.length && !acceptWarnings)" @click="approve">
          <ShieldCheck :size="16" /> {{ t('ws.approve') }}
        </button>
      </template>
    </BaseModal>

    <BaseModal v-model="reopenOpen" :title="t('ws.reopenTitle')" size="sm">
      <label class="field-label">{{ t('ws.reopenReason') }}</label>
      <textarea v-model="reopenReason" rows="3" class="field-input" />
      <template #footer>
        <button class="btn-ghost" @click="reopenOpen = false">{{ t('common.cancel') }}</button>
        <button class="btn-primary" :disabled="approveBusy || reopenReason.trim().length < 5" @click="reopen">
          <RotateCcw :size="16" /> {{ t('ws.reopen') }}
        </button>
      </template>
    </BaseModal>

    <!-- ================= Parallelni hisobdan chiqarish (sabab) ================= -->
    <BaseModal v-model="excludeOpen" :title="t('ws.excludeTitle')" size="sm">
      <p class="text-[13px] text-ink-muted mb-3">{{ t('ws.excludeHint') }}</p>
      <label class="field-label">{{ t('ws.excludeReason') }}</label>
      <textarea v-model="excludeReason" rows="2" class="field-input" maxlength="255" />
      <template #footer>
        <button class="btn-ghost" @click="excludeOpen = false">{{ t('common.cancel') }}</button>
        <button class="btn-primary" :disabled="excludeReason.trim().length < 3" @click="confirmExclude">
          <EyeOff :size="16" /> {{ t('ws.exclude') }}
        </button>
      </template>
    </BaseModal>

    <!-- ================= Takroriy tahlil ================= -->
    <BaseModal
      :model-value="!!retestTarget" :title="t('ws.retestTitle')" :subtitle="retestTarget?.sample?.code" size="sm"
      @update:model-value="!$event && (retestTarget = null)"
    >
      <p class="text-[13px] text-ink-muted mb-3">{{ t('ws.retestHint') }}</p>
      <label class="field-label">{{ t('ws.retestReason') }}</label>
      <textarea v-model="retestReason" rows="2" class="field-input" maxlength="500" />
      <template #footer>
        <button class="btn-ghost" @click="retestTarget = null">{{ t('common.cancel') }}</button>
        <button class="btn-primary" :disabled="saving || retestReason.trim().length < 3" @click="markRetest">
          <RotateCcw :size="16" /> {{ t('ws.retestMark') }}
        </button>
      </template>
    </BaseModal>

    <!-- ================= Partiyaga proba qo'shish ================= -->
    <BaseModal v-model="addOpen" :title="t('ws.addSampleTitle')" :subtitle="`№ ${ws.number}`" size="lg">
      <CandidatePicker
        v-if="addOpen"
        v-model="addSelected"
        :template-id="ws.template_id"
        :except-worksheet="ws.id"
        :existing-rows="form.rows.length"
        :existing-samples="members.length"
      />
      <template #footer>
        <button class="btn-ghost" @click="addOpen = false">{{ t('common.cancel') }}</button>
        <button class="btn-primary" :disabled="saving || !addSelected.length" @click="addSamples">
          <UserPlus :size="16" /> {{ t('ws.addSample') }}
        </button>
      </template>
    </BaseModal>

    <ConfirmDialog
      :model-value="!!removeTarget" :title="t('ws.removeSample')"
      :body="t('ws.removeSampleConfirm', { code: removeTarget?.sample?.code ?? '' })"
      :confirm-label="t('ws.removeSample')" :cancel-label="t('common.cancel')"
      @update:model-value="!$event && (removeTarget = null)" @confirm="removeSample"
    />

    <ConfirmDialog
      v-model="deleteOpen" :title="t('ws.delete')" :body="t('ws.deleteConfirm')"
      :confirm-label="t('ws.delete')" :cancel-label="t('common.cancel')" @confirm="destroy"
    />
  </div>
</template>
