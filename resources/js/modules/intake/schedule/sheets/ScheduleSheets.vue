<script setup>
/**
 * [Paket 37] Grafik jadvallari — Excel ko'rinishidagi kunlik jadval.
 *   laboratoriya / rahbariyat:  /schedule-sheets          (oq kataklarga kiritish — schedule_sheets.enter)
 *   buyurtmachi:                /portal/schedule-sheets   (faqat ko'rish; route.meta.portal)
 * Oq — kiritiladi, sariq — formula / o'rtacha (serverda hisoblanadi), kulrang — namuna natijasi.
 */
import { computed, nextTick, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { ChevronLeft, ChevronRight, FileSpreadsheet, Lock, Table } from 'lucide-vue-next'
import api from '@/lib/api'
import PageHeader from '@/components/ui/PageHeader.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import { useUiStore } from '@/stores/ui'
import { apiErrorMessage } from '../../requests/shared'
import { RUN_TONE, isoDate, niceDate, shiftDate } from '../shared'
import '../i18n'
import './i18n'

const { t, locale } = useI18n()
const route = useRoute()
const router = useRouter()
const ui = useUiStore()

const portal = computed(() => !!route.meta.portal)
const base = computed(() => (portal.value ? '/portal/schedule-sheets' : '/schedule-sheets'))

const today = isoDate()
const date = ref(route.query.date || today)
const sheets = ref([])
const sheetId = ref(Number(route.query.sheet) || null)
const grid = ref(null)
const listLoading = ref(true)
const loading = ref(false)
const loadError = ref('')

async function loadList() {
  listLoading.value = true
  try {
    const { data } = await api.get(base.value)
    sheets.value = data.data ?? []
    if (!sheets.value.some((s) => s.id === sheetId.value)) sheetId.value = sheets.value[0]?.id ?? null
  } catch (e) {
    loadError.value = apiErrorMessage(e, t('ssheet.loadError'))
  } finally {
    listLoading.value = false
  }
}

async function load() {
  if (!sheetId.value) { grid.value = null; return }
  loading.value = true
  loadError.value = ''
  try {
    const { data } = await api.get(`${base.value}/${sheetId.value}`, { params: { date: date.value } })
    grid.value = data
  } catch (e) {
    loadError.value = apiErrorMessage(e, t('ssheet.loadError'))
  } finally {
    loading.value = false
  }
}

onMounted(async () => { await loadList(); load() })
watch([sheetId, date], () => {
  router.replace({ query: { ...route.query, sheet: sheetId.value || undefined, date: date.value === today ? undefined : date.value } })
  load()
})

const columns = computed(() => grid.value?.columns ?? [])
const rows = computed(() => grid.value?.rows ?? [])
const showName = computed(() => new Set(rows.value.map((r) => r.name)).size > 1)

const isCalc = (c) => c.kind === 'formula' || c.kind === 'average'
function fmt(v, c) {
  if (v === null || v === undefined || v === '') return ''
  const d = Number.isInteger(c.decimals) ? c.decimals : 2
  return Number(v).toLocaleString(locale.value === 'en' ? 'en-US' : 'ru-RU', { minimumFractionDigits: d, maximumFractionDigits: d, useGrouping: false })
}
const meta = (r, c) => r.meta?.[c.key] ?? {}
const editable = (r, c) => !!grid.value?.can_edit && !!meta(r, c).editable

/* ---------- kiritish ---------- */
const drafts = ref({}) // `${run}:${key}` -> matn (fokusda)
const saving = ref({})
const failed = ref({})
const cellKey = (r, c) => `${r.run_id}:${c.key}`

function onFocus(r, c) {
  const k = cellKey(r, c)
  if (drafts.value[k] === undefined) drafts.value[k] = fmt(r.values[c.key], c)
}

async function commit(r, c) {
  const k = cellKey(r, c)
  const raw = (drafts.value[k] ?? '').trim()
  delete drafts.value[k]
  const before = fmt(r.values[c.key], c)
  if (raw === before) return
  saving.value[k] = true
  delete failed.value[k]
  try {
    const { data } = await api.put(`${base.value}/${sheetId.value}/cell`, { run_id: r.run_id, key: c.key, value: raw })
    if (data.date === grid.value?.date) grid.value = data
  } catch (e) {
    failed.value[k] = apiErrorMessage(e, t('ssheet.saveError'))
    ui.error(failed.value[k])
  } finally {
    delete saving.value[k]
  }
}

function move(e, ri, ci, dr) {
  e.preventDefault()
  const target = document.querySelector(`[data-cell="${ri + dr}-${ci}"]`)
  if (target) target.focus()
  else e.target.blur()
}

function cancel(e, r, c) {
  delete drafts.value[cellKey(r, c)]
  nextTick(() => e.target.blur())
}

/* ---------- Excel ---------- */
const exporting = ref(false)
async function exportXlsx() {
  exporting.value = true
  try {
    const res = await api.get(`${base.value}/${sheetId.value}/export`, { params: { date: date.value }, responseType: 'blob' })
    const cd = res.headers?.['content-disposition'] || ''
    const m = /filename\*?=(?:UTF-8'')?"?([^";]+)"?/i.exec(cd)
    const url = URL.createObjectURL(res.data)
    const a = document.createElement('a')
    a.href = url
    a.download = m ? decodeURIComponent(m[1]) : `jadval_${date.value}.xlsx`
    document.body.appendChild(a)
    a.click()
    a.remove()
    setTimeout(() => URL.revokeObjectURL(url), 4000)
  } catch (e) {
    ui.error(t('ssheet.loadError'))
  } finally {
    exporting.value = false
  }
}
</script>

<template>
  <div>
    <PageHeader :title="portal ? t('ssheet.portalTitle') : t('ssheet.title')">
      <template #actions>
        <button v-if="grid && rows.length" class="btn-outline" :disabled="exporting" data-export @click="exportXlsx">
          <FileSpreadsheet :size="16" /> {{ t('ssheet.excel') }}
        </button>
      </template>
    </PageHeader>
    <p class="mb-4 max-w-3xl text-[13.5px] leading-relaxed text-ink-muted">{{ portal ? t('ssheet.portalHint') : t('ssheet.hint') }}</p>

    <div v-if="listLoading" class="h-24 rounded-panel bg-surface-sunken animate-pulse" />
    <div v-else-if="!sheets.length" class="panel">
      <EmptyState :icon="Table" :title="t('ssheet.empty')" :hint="portal ? '' : t('ssheet.emptyHint')" />
    </div>

    <template v-else>
      <div class="panel p-3 mb-3 flex flex-wrap items-center gap-2">
        <select v-if="sheets.length > 1" v-model="sheetId" class="field-input w-auto min-w-[240px]" :aria-label="t('ssheet.sheet')" data-sheet-select>
          <option v-for="s in sheets" :key="s.id" :value="s.id">{{ s.name }}{{ portal ? '' : ` — ${s.department}` }}</option>
        </select>
        <span v-else class="mr-2 text-[14px] font-semibold text-ink">{{ sheets[0].name }}<span v-if="!portal" class="font-normal text-ink-muted"> — {{ sheets[0].department }}</span></span>
        <span class="mx-1 h-6 w-px bg-line" />
        <button class="btn-ghost btn-icon h-8 w-8" :title="t('sched.prev')" :aria-label="t('sched.prev')" @click="date = shiftDate(date, -1)"><ChevronLeft :size="16" /></button>
        <input v-model="date" type="date" class="field-input w-auto">
        <button class="btn-ghost btn-icon h-8 w-8" :title="t('sched.next')" :aria-label="t('sched.next')" @click="date = shiftDate(date, 1)"><ChevronRight :size="16" /></button>
        <button v-if="date !== today" class="btn-ghost btn-sm" @click="date = today">{{ t('sched.today') }}</button>
        <span class="ml-2 text-[13.5px] font-medium text-ink">{{ niceDate(date, locale) }}</span>
        <span class="flex-1" />
        <span class="flex flex-wrap items-center gap-3 text-[12px] text-ink-muted">
          <span class="flex items-center gap-1.5"><i class="inline-block h-3 w-4 rounded-sm border border-line bg-white dark:bg-surface" />{{ t('ssheet.legendInput') }}</span>
          <span class="flex items-center gap-1.5"><i class="inline-block h-3 w-4 rounded-sm border border-line ss-calc" />{{ t('ssheet.legendCalc') }}</span>
          <span class="flex items-center gap-1.5"><i class="inline-block h-3 w-4 rounded-sm border border-line bg-surface-sunken" />{{ t('ssheet.legendSample') }}</span>
        </span>
      </div>
      <p v-if="grid && !grid.can_edit && !portal" class="mb-3 text-[12.5px] text-ink-faint">{{ t('ssheet.readOnly') }}</p>

      <div v-if="loading && !grid" class="h-40 rounded-panel bg-surface-sunken animate-pulse" />
      <p v-else-if="loadError" class="panel p-4 text-[14px] text-crit">{{ loadError }}</p>
      <div v-else-if="grid && !rows.length" class="panel">
        <EmptyState :icon="Table" :title="t('ssheet.noRows')" />
      </div>

      <div v-else-if="grid" class="panel overflow-auto max-h-[75vh]" :class="loading && 'opacity-60'" data-sheet-grid>
        <table class="ss-table text-[13px]">
          <thead>
            <tr>
              <th class="ss-head w-10 sticky left-0 z-20">{{ t('ssheet.n') }}</th>
              <th class="ss-head w-24 sticky left-10 z-20">{{ t('ssheet.time') }}</th>
              <th v-for="c in columns" :key="c.key" class="ss-head min-w-[104px] max-w-[180px]" :class="isCalc(c) && 'ss-calc'" :data-col="c.key">
                <span class="block whitespace-normal leading-snug">{{ c.title }}</span>
                <span v-if="c.unit" class="block font-normal text-ink-faint">{{ c.unit }}</span>
              </th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="(r, ri) in rows" :key="r.run_id" :data-row="r.run_id">
              <td class="ss-cell sticky left-0 z-10 bg-surface-raised text-center text-ink-muted tnum">{{ r.n }}</td>
              <td class="ss-cell sticky left-10 z-10 bg-surface-raised" :title="r.name">
                <span class="font-mono font-semibold text-ink tnum">{{ r.time }}</span>
                <span v-if="r.status !== 'received'" class="ml-1.5 rounded px-1 py-px text-[10.5px]" :class="RUN_TONE[r.status]">{{ t(`sched.status.${r.status}`) }}</span>
                <span v-if="showName" class="block truncate text-[11px] text-ink-faint">{{ r.name }}</span>
              </td>
              <td v-for="(c, ci) in columns" :key="c.key" class="ss-cell p-0 text-right tnum"
                  :class="[isCalc(c) && 'ss-calc font-medium', meta(r, c).from === 'sample' && 'bg-surface-sunken', failed[cellKey(r, c)] && 'ring-1 ring-inset ring-crit']">
                <input v-if="editable(r, c)"
                       class="ss-input" :class="saving[cellKey(r, c)] && 'opacity-50'"
                       :value="drafts[cellKey(r, c)] ?? fmt(r.values[c.key], c)"
                       inputmode="decimal" :data-cell="`${ri}-${ci}`"
                       :title="meta(r, c).pending ? t('ssheet.pending') : ''"
                       :aria-label="`${r.time} — ${c.title}`"
                       @focus="onFocus(r, c)"
                       @input="drafts[cellKey(r, c)] = $event.target.value"
                       @blur="commit(r, c)"
                       @keydown.enter="move($event, ri, ci, 1)"
                       @keydown.down="move($event, ri, ci, 1)"
                       @keydown.up="move($event, ri, ci, -1)"
                       @keydown.esc="cancel($event, r, c)">
                <span v-else-if="meta(r, c).locked" class="flex justify-end px-2 py-1.5 text-ink-faint" :title="t('ssheet.locked')"><Lock :size="13" /></span>
                <span v-else class="block px-2 py-1.5"
                      :title="meta(r, c).from === 'sample' ? t('ssheet.fromSample', { code: meta(r, c).sample }) : meta(r, c).error ? t('ssheet.formulaError', { msg: meta(r, c).error }) : ''"
                      :class="meta(r, c).error && 'text-crit'">{{ meta(r, c).error ? '#!' : fmt(r.values[c.key], c) }}</span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </template>
  </div>
</template>

<style scoped>
.ss-table { border-collapse: separate; border-spacing: 0; min-width: 100%; }
.ss-head {
  position: sticky; top: 0;
  padding: 6px 8px; vertical-align: bottom; text-align: center;
  font-size: 12px; font-weight: 600; color: rgb(var(--ink));
  background: rgb(var(--surface-sunken));
  border-right: 1px solid rgb(var(--line)); border-bottom: 2px solid rgb(var(--line));
}
.ss-cell { border-right: 1px solid rgb(var(--line)); border-bottom: 1px solid rgb(var(--line)); white-space: nowrap; }
.ss-calc { background: #fff4c3; }
.ss-head.ss-calc { background: #f6e08e; }
.ss-input {
  display: block; width: 100%; min-width: 0;
  padding: 6px 8px; text-align: right; background: transparent; border: 0; outline: none;
  color: rgb(var(--ink)); font: inherit; font-variant-numeric: tabular-nums;
}
.ss-input:focus { box-shadow: inset 0 0 0 2px rgb(var(--ink-muted)); background: rgb(var(--surface)); }
:global(.dark) .ss-calc { background: rgb(120 100 20 / 0.35); }
:global(.dark) .ss-head.ss-calc { background: rgb(140 115 20 / 0.55); }
</style>
