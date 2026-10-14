<script setup>
/**
 * [P19] Savatcha — o'chirilgan yozuvlar turlar bo'yicha: kim va qachon o'chirgani, qidiruv, sana, tiklash.
 * Tiklashdan oldin server tekshiradi: to'siq bo'lsa (masalan namuna boshqa varaqda) — sababi ko'rsatiladi.
 * Butunlay o'chirish yo'q (ISO/IEC 17025). Eski sahifa: /trash/classic
 */
import { computed, nextTick, onMounted, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { AlertTriangle, CheckCircle2, History, RefreshCw, RotateCcw, Search, ShieldCheck, Trash2, X, XCircle } from 'lucide-vue-next'
import api from '@/lib/api'
import PageHeader from '@/components/ui/PageHeader.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import Pagination from '@/components/ui/Pagination.vue'
import { useAuthStore } from '@/stores/auth'
import { useUiStore } from '@/stores/ui'
import { apiErrorMessage, num } from '../helpers'
import '../i18n'

const { t, te, locale } = useI18n()
const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const ui = useUiStore()

const STATUS_TONE = {
  registered: 'slate', assigned: 'info', in_progress: 'warn', completed: 'ok', archived: 'muted',
  new: 'info', in_testing: 'warn', tested: 'ok', defect: 'crit', disposed: 'muted',
  working: 'ok', maintenance: 'warn', out_of_service: 'crit', awaiting_calibration: 'warn',
  draft: 'slate', printed: 'info', approved: 'ok', active: 'ok', inactive: 'muted',
}
const TONE_CLASS = {
  ok: 'bg-ok-light text-ok-dark dark:bg-ok-dark/25 dark:text-ok-light',
  warn: 'bg-warn-light text-warn-dark dark:bg-warn-dark/25 dark:text-warn-light',
  crit: 'bg-crit-light text-crit-dark dark:bg-crit-dark/25 dark:text-crit-light',
  info: 'bg-aqua-50 text-aqua-700 dark:bg-aqua-800/35 dark:text-aqua-200',
  slate: 'bg-surface-sunken text-ink-muted',
  muted: 'bg-surface-sunken text-ink-faint',
}

const q0 = route.query ?? {}
const types = ref([])
const total = ref(0)
const active = ref(q0.type ?? '')
const focusId = ref(q0.id ? Number(q0.id) : null)
const f = reactive({ q: '', from: '', to: '', deleted_by: '' })
const search = ref('')
const page = ref(1)
const perPage = ref(25)

const rows = ref([])
const meta = ref({})
const deleters = ref([])
const loading = ref(false)
const error = ref('')
let seq = 0

const canRestore = computed(() => auth.can('trash.restore'))

async function loadCounts() {
  try {
    const { data } = await api.get('/recycle-bin')
    types.value = data.types
    total.value = data.total
    if (!types.value.some((x) => x.type === active.value)) {
      active.value = (types.value.find((x) => x.count > 0) ?? types.value[0])?.type ?? ''
    }
  } catch (e) {
    error.value = apiErrorMessage(e, t('insights.loadError'))
  }
}

async function loadRows() {
  if (!active.value) return
  const my = ++seq
  loading.value = true
  error.value = ''
  router.replace({ query: { type: active.value, ...(focusId.value ? { id: focusId.value } : {}) } }).catch(() => {})
  try {
    const params = { page: page.value, per_page: perPage.value, locale: locale.value }
    for (const [k, v] of Object.entries(f)) if (v) params[k] = v
    const { data } = await api.get(`/recycle-bin/${active.value}`, { params })
    if (my !== seq) return
    rows.value = data.data
    meta.value = data.meta
    deleters.value = data.deleters ?? []
    if (focusId.value) {
      await nextTick()
      document.querySelector(`[data-row="${focusId.value}"]`)?.scrollIntoView?.({ block: 'center' })
    }
  } catch (e) {
    if (my === seq) error.value = apiErrorMessage(e, t('insights.loadError'))
  } finally {
    if (my === seq) loading.value = false
  }
}

/** Turlar soni yangilanadi; tur o'zgarsa ro'yxatni kuzatuvchi yuklaydi, aks holda — shu yerda */
async function refresh() {
  const before = active.value
  await loadCounts()
  if (active.value === before) await loadRows()
}

onMounted(refresh)

function selectType(type) {
  if (type === active.value) return
  focusId.value = null
  search.value = ''
  Object.assign(f, { q: '', from: '', to: '', deleted_by: '' })
  active.value = type
}

// Filtr yoki tur o'zgarsa — 1-sahifa; so'ng bitta yuklash (tartib muhim: avval sahifa)
watch(() => [active.value, f.q, f.from, f.to, f.deleted_by, perPage.value], () => { page.value = 1 })
watch(() => JSON.stringify([active.value, f.q, f.from, f.to, f.deleted_by, page.value, perPage.value, locale.value]), loadRows)

let timer = null
watch(search, (v) => {
  clearTimeout(timer)
  timer = setTimeout(() => { if (f.q !== v.trim()) f.q = v.trim() }, 350)
})

const anyFilter = computed(() => f.q || f.from || f.to || f.deleted_by)
function clearFilters() {
  search.value = ''
  Object.assign(f, { q: '', from: '', to: '', deleted_by: '' })
}

/* ---------- ko'rinish ---------- */
const typeLabel = (type) => (te(`insights.trash.types.${type}`) ? t(`insights.trash.types.${type}`) : type)
const activeCount = computed(() => types.value.find((x) => x.type === active.value)?.count ?? 0)

function metaText(m) {
  if (m.k === 'kind') return t('insights.trash.meta.kind')
  if (m.k === 'object_type' && te(`objectType.${m.v}`)) return t(`objectType.${m.v}`)
  return te(`insights.trash.meta.${m.k}`) ? t(`insights.trash.meta.${m.k}`, { v: m.v }) : String(m.v)
}
function statusLabel(s) {
  if (!s) return ''
  if (active.value === 'worksheets' && te(`insights.trash.wsStatus.${s}`)) return t(`insights.trash.wsStatus.${s}`)
  if ((s === 'active' || s === 'inactive') && te(`insights.trash.${s}`)) return t(`insights.trash.${s}`)
  return te(`status.${s}`) ? t(`status.${s}`) : s
}
const statusClass = (s) => TONE_CLASS[STATUS_TONE[s] ?? 'slate']
function ago(days) {
  if (days === null || days === undefined) return ''
  if (days <= 0) return t('insights.trash.today')
  if (days === 1) return t('insights.trash.yesterday')
  return t('insights.trash.ago', { n: days })
}
const fmt = (at) => (at ? `${at.slice(8, 10)}.${at.slice(5, 7)}.${at.slice(0, 4)} ${at.slice(11, 16)}` : '—')

function history(row) {
  const model = { samples: 'Sample', worksheets: 'Worksheet', test_objects: 'TestObject', equipment: 'Equipment', water_orders: 'WaterOrder', users: 'User', worksheet_templates: 'WorksheetTemplate', customer_departments: 'CustomerDepartment' }[active.value]
  router.push({ name: 'activity-logs', query: { model_type: model, model_id: row.id, period: 'all', subject: row.title } })
}

/* ---------- tiklash ---------- */
const modal = reactive({ open: false, row: null, info: null, loading: false, busy: false, error: '' })

async function openRestore(row) {
  Object.assign(modal, { open: true, row, info: null, loading: true, busy: false, error: '' })
  try {
    const { data } = await api.get(`/recycle-bin/${active.value}/${row.id}`, { params: { locale: locale.value } })
    if (modal.row === row) modal.info = data
  } catch (e) {
    modal.error = apiErrorMessage(e, t('insights.loadError'))
  } finally {
    modal.loading = false
  }
}

async function doRestore() {
  if (!modal.row || modal.busy) return
  modal.busy = true
  modal.error = ''
  try {
    const { data } = await api.post(`/recycle-bin/${active.value}/${modal.row.id}/restore`, {}, { params: { locale: locale.value } })
    ui.success(t('insights.trash.restored', { title: data.title ?? modal.row.title }))
    modal.open = false
    if (focusId.value === modal.row.id) focusId.value = null
    await refresh()
  } catch (e) {
    modal.error = apiErrorMessage(e, t('insights.loadError'))
    // to'siq paydo bo'lgan bo'lishi mumkin — qayta tekshiramiz
    try {
      const { data } = await api.get(`/recycle-bin/${active.value}/${modal.row.id}`, { params: { locale: locale.value } })
      modal.info = data
    } catch { /* yozuv yo'q */ }
  } finally {
    modal.busy = false
  }
}
</script>

<template>
  <div>
    <PageHeader :title="t('insights.trash.title')" :subtitle="t('insights.trash.subtitle')">
      <template #actions>
        <button type="button" class="btn-ghost btn-sm" data-classic @click="router.push({ name: 'trash-classic' })">{{ t('insights.trash.classic') }}</button>
        <button type="button" class="btn-outline" :disabled="loading" data-refresh @click="refresh">
          <RefreshCw :size="15" :class="loading ? 'animate-spin' : ''" /> {{ t('insights.trash.refresh') }}
        </button>
      </template>
    </PageHeader>

    <p class="mb-4 flex items-start gap-2 text-[12.5px] text-ink-muted" data-iso>
      <ShieldCheck :size="15" class="mt-px shrink-0 text-ok" /> {{ t('insights.trash.iso') }}
    </p>

    <!-- Turlar -->
    <div class="mb-4 -mx-1 overflow-x-auto">
      <div class="flex gap-1.5 px-1 min-w-max" role="tablist" data-types>
        <button
          v-for="x in types" :key="x.type" type="button" role="tab"
          class="inline-flex items-center gap-2 rounded-md border px-3 py-2 text-[13px] transition-colors"
          :class="active === x.type
            ? 'border-assay-500 bg-assay-50 text-ink font-medium dark:bg-assay-900/35'
            : 'border-line bg-surface text-ink-muted hover:text-ink hover:border-ink-faint'"
          :aria-selected="active === x.type" :data-type="x.type"
          @click="selectType(x.type)"
        >
          {{ typeLabel(x.type) }}
          <span class="rounded-full px-1.5 py-px text-[11.5px] font-semibold tnum"
                :class="x.count ? 'bg-warn-light text-warn-dark dark:bg-warn-dark/30 dark:text-warn-light' : 'bg-surface-sunken text-ink-faint'">{{ num(x.count, locale) }}</span>
        </button>
      </div>
    </div>

    <div class="panel overflow-hidden">
      <!-- Filtrlar -->
      <div class="flex flex-wrap items-end gap-3 px-4 py-3 border-b border-line">
        <div class="flex-1 min-w-[200px]">
          <div class="relative">
            <Search :size="15" class="absolute left-3 top-1/2 -translate-y-1/2 text-ink-faint pointer-events-none" />
            <input v-model="search" type="search" class="field-input pl-9" :placeholder="t('insights.trash.search')" :aria-label="t('insights.trash.search')" data-f="q" />
          </div>
        </div>
        <div class="w-40">
          <label class="field-label" for="rb-from">{{ t('insights.trash.from') }}</label>
          <input id="rb-from" v-model="f.from" type="date" class="field-input tnum" data-f="from" />
        </div>
        <div class="w-40">
          <label class="field-label" for="rb-to">{{ t('insights.trash.to') }}</label>
          <input id="rb-to" v-model="f.to" type="date" class="field-input tnum" data-f="to" />
        </div>
        <div class="w-48">
          <label class="field-label" for="rb-by">{{ t('insights.trash.deletedBy') }}</label>
          <select id="rb-by" v-model="f.deleted_by" class="field-input" data-f="by">
            <option value="">{{ t('insights.trash.anyone') }}</option>
            <option v-for="u in deleters" :key="u.id" :value="String(u.id)">{{ u.name }} · {{ num(u.count, locale) }}</option>
          </select>
        </div>
        <button v-if="anyFilter" type="button" class="btn-ghost btn-sm mb-0.5" data-clear @click="clearFilters">
          <X :size="14" /> {{ t('insights.trash.clear') }}
        </button>
      </div>

      <p v-if="error" class="m-4 rounded-md bg-crit-light px-3 py-2.5 text-[13px] text-crit dark:bg-crit-dark/25" data-error>{{ error }}</p>

      <div v-if="loading && !rows.length" class="p-6 space-y-3" data-loading>
        <div v-for="i in 4" :key="i" class="h-5 rounded bg-surface-sunken animate-pulse" />
      </div>
      <div v-else-if="!rows.length && !error" class="py-14 text-center" data-empty>
        <Trash2 :size="28" class="mx-auto text-ink-faint" />
        <p class="mt-3 text-[14px] text-ink">
          {{ anyFilter ? t('insights.trash.notFound') : total ? t('insights.trash.emptyType') : t('insights.trash.empty') }}
        </p>
      </div>

      <template v-else-if="rows.length">
        <div class="overflow-x-auto" :class="loading ? 'opacity-70' : ''">
          <table class="w-full text-left min-w-[760px]">
            <thead>
              <tr class="border-b border-line bg-surface-sunken/50 text-[12px] font-semibold text-ink-muted">
                <th class="px-4 py-2.5">{{ t('insights.trash.col.record') }}</th>
                <th class="px-2 py-2.5 w-[140px]">{{ t('insights.trash.col.status') }}</th>
                <th class="px-2 py-2.5 w-[150px]">{{ t('insights.trash.col.deleted') }}</th>
                <th class="px-2 py-2.5 w-[180px]">{{ t('insights.trash.col.by') }}</th>
                <th class="pr-4 py-2.5 w-[170px]" />
              </tr>
            </thead>
            <tbody class="divide-y divide-line">
              <tr v-for="r in rows" :key="r.id" class="align-top transition-colors"
                  :class="focusId === r.id ? 'bg-assay-50 dark:bg-assay-900/30' : 'hover:bg-surface-sunken/50'" :data-row="r.id">
                <td class="px-4 py-2.5 min-w-0">
                  <p class="text-[13.5px] font-medium text-ink font-mono break-all" data-title>{{ r.title }}</p>
                  <p v-if="r.meta.length" class="mt-0.5 text-[12px] text-ink-muted" data-meta>
                    <template v-for="(m, i) in r.meta" :key="m.k"><span v-if="i" class="text-ink-faint"> · </span>{{ metaText(m) }}</template>
                  </p>
                </td>
                <td class="px-2 py-2.5">
                  <span v-if="r.status" class="inline-block rounded-full px-2 py-0.5 text-[11.5px] font-medium whitespace-nowrap" :class="statusClass(r.status)" data-status>{{ statusLabel(r.status) }}</span>
                </td>
                <td class="px-2 py-2.5 whitespace-nowrap">
                  <p class="text-[12.5px] text-ink tnum">{{ fmt(r.deleted_at) }}</p>
                  <p class="text-[11.5px] text-ink-faint" data-ago>{{ ago(r.days) }}</p>
                </td>
                <td class="px-2 py-2.5 text-[13px]" :title="r.note || undefined">
                  <span v-if="r.deleted_by" class="text-ink" data-by>{{ r.deleted_by.name }}</span>
                  <span v-else class="text-ink-faint italic">{{ t('insights.trash.unknown') }}</span>
                </td>
                <td class="pr-4 py-2 text-right whitespace-nowrap">
                  <button type="button" class="btn-ghost btn-sm" data-history @click="history(r)">
                    <History :size="14" /> {{ t('insights.trash.history') }}
                  </button>
                  <button v-if="canRestore" type="button" class="btn-outline btn-sm ml-1" data-restore @click="openRestore(r)">
                    <RotateCcw :size="14" /> {{ t('insights.trash.restore') }}
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <Pagination :meta="meta" :per-page="perPage" @change="page = $event" @per-page="perPage = $event" />
      </template>
    </div>
    <p v-if="!canRestore && activeCount" class="mt-3 text-[12.5px] text-ink-faint" data-noright>{{ t('insights.trash.noRight') }}</p>

    <!-- Tiklash -->
    <BaseModal v-model="modal.open" size="md" :title="`${t('insights.trash.modal.title')}: ${modal.row?.title ?? ''}`" :subtitle="typeLabel(active)">
      <div class="space-y-3" data-modal>
        <p v-if="modal.loading" class="text-[13px] text-ink-muted">{{ t('insights.trash.modal.checking') }}</p>
        <template v-else-if="modal.info">
          <p v-if="modal.info.item.meta.length" class="text-[13px] text-ink-muted">
            <template v-for="(m, i) in modal.info.item.meta" :key="m.k"><span v-if="i" class="text-ink-faint"> · </span>{{ metaText(m) }}</template>
          </p>
          <p class="text-[12.5px] text-ink-faint">
            {{ t('insights.trash.modal.deleted', { at: fmt(modal.info.item.deleted_at), by: modal.info.item.deleted_by?.name ?? t('insights.trash.unknown') }) }}
          </p>

          <div v-if="modal.info.blockers.length" class="rounded-md bg-crit-light px-3 py-2.5 dark:bg-crit-dark/25" data-blockers>
            <p class="flex items-center gap-1.5 text-[13px] font-semibold text-crit-dark dark:text-crit-light"><XCircle :size="15" /> {{ t('insights.trash.modal.blockers') }}</p>
            <ul class="mt-1 space-y-0.5 text-[13px] text-crit-dark dark:text-crit-light list-disc pl-5">
              <li v-for="(b, i) in modal.info.blockers" :key="i">{{ b }}</li>
            </ul>
          </div>
          <div v-if="modal.info.warnings.length" class="rounded-md bg-warn-light px-3 py-2.5 dark:bg-warn-dark/25" data-warnings>
            <p class="flex items-center gap-1.5 text-[13px] font-semibold text-warn-dark dark:text-warn-light"><AlertTriangle :size="15" /> {{ t('insights.trash.modal.warnings') }}</p>
            <ul class="mt-1 space-y-0.5 text-[13px] text-warn-dark dark:text-warn-light list-disc pl-5">
              <li v-for="(w, i) in modal.info.warnings" :key="i">{{ w }}</li>
            </ul>
          </div>
          <p v-if="!modal.info.blockers.length && !modal.info.warnings.length" class="flex items-center gap-1.5 text-[13px] text-ok" data-ok>
            <CheckCircle2 :size="15" /> {{ t('insights.trash.modal.ok') }}
          </p>
          <button v-if="modal.info.history" type="button" class="text-[12.5px] text-assay-700 dark:text-assay-300 hover:underline underline-offset-2" data-modal-history
                  @click="modal.open = false; history(modal.row)">
            {{ t('insights.trash.modal.history', { n: modal.info.history }) }} →
          </button>
        </template>
        <p v-if="modal.error" class="rounded-md bg-crit-light px-3 py-2.5 text-[13px] text-crit dark:bg-crit-dark/25" data-modal-error>{{ modal.error }}</p>
      </div>
      <template #footer>
        <button type="button" class="btn-ghost" @click="modal.open = false">{{ t('common.cancel') }}</button>
        <button type="button" class="btn-primary" :disabled="modal.loading || modal.busy || !modal.info || modal.info.blockers.length > 0" data-confirm @click="doRestore">
          <RotateCcw :size="15" /> {{ t('insights.trash.restore') }}
        </button>
      </template>
    </BaseModal>
  </div>
</template>
