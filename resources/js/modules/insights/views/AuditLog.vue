<script setup>
/**
 * [P19] Audit jurnali — davr, xodim, bo'lim, amal, qidiruv; kunlar bo'yicha guruhlangan ro'yxat.
 * Har yozuvda: obyekt havolasi (yoki «Savatchada»), tafsilotlar, «Shu yozuv tarixi».
 * Filtrlar manzilda saqlanadi (havolani ulashish, orqaga qaytish). Eski sahifa: /activity-logs/classic
 */
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { ChevronDown, ChevronRight, FileSpreadsheet, History, ScrollText, Search, Trash2, X } from 'lucide-vue-next'
import api from '@/lib/api'
import PageHeader from '@/components/ui/PageHeader.vue'
import Pagination from '@/components/ui/Pagination.vue'
import { useAuthStore } from '@/stores/auth'
import { apiErrorMessage, dmy, isoDate, num, parseTs, tag } from '../helpers'
import '../i18n'

const { t, te, locale } = useI18n()
const route = useRoute()
const router = useRouter()
const auth = useAuthStore()

const PERIODS = ['today', 'yesterday', 'd7', 'd30', 'all', 'custom']
const BUCKETS = ['created', 'updated', 'deleted', 'restored', 'login', 'logout', 'interlab']
const TONE = {
  created: 'bg-ok-light text-ok-dark dark:bg-ok-dark/25 dark:text-ok-light',
  updated: 'bg-warn-light text-warn-dark dark:bg-warn-dark/25 dark:text-warn-light',
  deleted: 'bg-crit-light text-crit-dark dark:bg-crit-dark/25 dark:text-crit-light',
  restored: 'bg-aqua-50 text-aqua-700 dark:bg-aqua-800/35 dark:text-aqua-200',
  login: 'bg-surface-sunken text-ink-muted',
  logout: 'bg-surface-sunken text-ink-faint',
  interlab: 'bg-assay-50 text-assay-700 dark:bg-assay-900/40 dark:text-assay-200',
}

const q0 = route.query ?? {}
const f = reactive({
  period: PERIODS.includes(q0.period) ? q0.period : (q0.model_id ? 'all' : 'd7'),
  from: q0.from ?? '',
  to: q0.to ?? '',
  user_id: q0.user_id ?? '',
  model_type: q0.model_type ?? '',
  model_id: q0.model_id ?? '',
  action: q0.action ?? '',
  q: q0.q ?? '',
})
const subjectName = ref(q0.subject ?? '')
const page = ref(Number(q0.page) || 1)
const perPage = ref(50)
const search = ref(f.q)

const rows = ref([])
const meta = ref({})
const summary = ref(null)
const loading = ref(false)
const error = ref('')
const open = ref(new Set())
let seq = 0

/** Davr → sanalar (brauzer vaqti bo'yicha) */
function range() {
  const today = new Date()
  const back = (n) => { const d = new Date(today); d.setDate(d.getDate() - n); return isoDate(d) }
  switch (f.period) {
    case 'today': return { from: isoDate(today), to: isoDate(today) }
    case 'yesterday': return { from: back(1), to: back(1) }
    case 'd7': return { from: back(6), to: isoDate(today) }
    case 'd30': return { from: back(29), to: isoDate(today) }
    case 'custom': return { from: f.from || undefined, to: f.to || undefined }
    default: return {}
  }
}

function params() {
  const p = { ...range() }
  for (const k of ['user_id', 'model_type', 'model_id', 'action', 'q']) if (f[k] !== '' && f[k] != null) p[k] = f[k]
  if (!p.model_type) delete p.model_id
  return p
}

function syncQuery() {
  const q = {}
  for (const [k, v] of Object.entries(f)) if (v !== '' && v != null) q[k] = v
  if (q.period === 'd7' && !q.model_id) delete q.period
  if (q.period !== 'custom') { delete q.from; delete q.to }
  if (subjectName.value && f.model_id) q.subject = subjectName.value
  if (page.value > 1) q.page = page.value
  router.replace({ query: q }).catch(() => {})
}

async function load(withSummary = true) {
  const my = ++seq
  loading.value = true
  error.value = ''
  syncQuery()
  try {
    const p = params()
    const [list, sum] = await Promise.all([
      api.get('/audit/logs', { params: { ...p, page: page.value, per_page: perPage.value } }),
      withSummary ? api.get('/audit/summary', { params: p }) : Promise.resolve(null),
    ])
    if (my !== seq) return
    rows.value = list.data.data
    meta.value = list.data.meta
    if (sum) summary.value = sum.data
    open.value = new Set()
  } catch (e) {
    if (my === seq) error.value = apiErrorMessage(e, t('insights.loadError'))
  } finally {
    if (my === seq) loading.value = false
  }
}

// Filtr o'zgarsa — 1-sahifa va yig'indi ham; faqat sahifa o'zgarsa — ro'yxat (tartib muhim: avval sahifa)
const filterKey = computed(() => JSON.stringify([f.period, f.user_id, f.model_type, f.model_id, f.action, f.q,
  f.period === 'custom' ? f.from : '', f.period === 'custom' ? f.to : '']))
let filtersChanged = false
watch(filterKey, () => { filtersChanged = true; page.value = 1 })
watch(() => [filterKey.value, page.value, perPage.value], () => {
  const all = filtersChanged
  filtersChanged = false
  load(all)
})
watch(perPage, () => { page.value = 1 })

let timer = null
watch(search, (v) => {
  clearTimeout(timer)
  timer = setTimeout(() => { if (f.q !== v.trim()) f.q = v.trim() }, 350)
})
watch(() => f.q, (v) => { if (v !== search.value.trim()) search.value = v })

onMounted(() => load())

const anyFilter = computed(() => f.user_id || f.model_type || f.action || f.q || f.period !== 'd7')
function clearAll() {
  Object.assign(f, { period: 'd7', from: '', to: '', user_id: '', model_type: '', model_id: '', action: '', q: '' })
  subjectName.value = ''
  search.value = ''
}

/* ---------- nomlar ---------- */
const modelLabel = (type) => (type && te(`insights.audit.models.${type}`) ? t(`insights.audit.models.${type}`) : type)
const actionLabel = (row) => (te(`insights.audit.actions.${row.bucket}`) ? t(`insights.audit.actions.${row.bucket}`) : row.action)
const userName = (u) => (u ? u.name : t('insights.audit.system'))

const userOptions = computed(() => {
  const list = summary.value?.users ?? []
  const has = list.some((u) => String(u.id) === String(f.user_id))
  return has || !f.user_id ? list : [{ id: f.user_id, name: `#${f.user_id}`, count: 0 }, ...list]
})
const modelOptions = computed(() => {
  const list = (summary.value?.models ?? []).map((m) => ({ ...m, label: modelLabel(m.type) }))
  if (f.model_type && !list.some((m) => m.type === f.model_type)) list.unshift({ type: f.model_type, count: 0, label: modelLabel(f.model_type) })
  return list.sort((a, b) => b.count - a.count || a.label.localeCompare(b.label))
})

function toggleAction(b) { f.action = f.action === b ? '' : b }

/* ---------- kunlar bo'yicha guruh ---------- */
const days = computed(() => {
  const out = []
  const today = isoDate(new Date())
  const y = new Date(); y.setDate(y.getDate() - 1)
  const yesterday = isoDate(y)
  for (const r of rows.value) {
    const d = (r.at ?? '').slice(0, 10)
    let g = out[out.length - 1]
    if (!g || g.key !== d) {
      const date = parseTs(d)
      const head = d === today ? t('insights.audit.today') : d === yesterday ? t('insights.audit.yesterday')
        : date ? date.toLocaleDateString(tag(locale.value), { weekday: 'long' }) : d
      g = { key: d, head, date: date ? dmy(date) : '', rows: [] }
      out.push(g)
    }
    g.rows.push(r)
  }
  return out
})
const time = (at) => (at ?? '').slice(11, 19)

/* ---------- obyekt havolalari ---------- */
const LINKS = {
  Sample: (id) => ({ name: 'sample-detail', params: { id } }),
  Worksheet: (id) => ({ name: 'worksheet-detail', params: { id } }),
  WorksheetTemplate: (id) => ({ name: 'worksheet-template-edit', params: { id } }),
  TestObject: (id) => ({ name: 'test-object-detail', params: { id } }),
  Equipment: (id) => ({ name: 'equipment-detail', params: { id } }),
  WaterOrder: (id) => ({ name: 'water-order-detail', params: { id } }),
  InterlabReferral: (id) => ({ name: 'interlab-detail', params: { id } }),
  InterlabPeer: (id) => ({ name: 'interlab-network', params: { id } }),
  CustomerDepartment: () => ({ name: 'customer-departments' }),
  LabShift: () => ({ name: 'lab-shifts' }),
  User: (id) => (auth.can('staff_activity.view') ? { name: 'staff-activity-user', params: { id } } : { name: 'users' }),
  Role: () => ({ name: 'roles' }),
  MenuItem: () => ({ name: 'menu-items' }),
  Element: () => ({ name: 'elements' }),
  Laboratory: () => ({ name: 'laboratories' }),
  LaboratoryGroup: () => ({ name: 'laboratory-groups' }),
  TestProfile: () => ({ name: 'test-profiles' }),
  MonitoringPoint: () => ({ name: 'monitoring-points' }),
}
function linkOf(row) {
  const l = row.subject?.link
  if (!l || !LINKS[l.type]) return null
  const to = LINKS[l.type](l.id)
  if (typeof router.hasRoute === 'function' && !router.hasRoute(to.name)) return null
  return { to, parent: !!l.parent, label: l.parent ? t('insights.audit.parentOf', { name: modelLabel(l.type) }) : modelLabel(l.type) }
}
function goTrash(row) {
  const tr = row.subject.trash
  router.push({ name: 'trash', query: { type: tr.type, id: tr.id } })
}
function showHistory(row) {
  subjectName.value = row.description ? row.description.slice(0, 80) : `${modelLabel(row.model_type)} #${row.model_id}`
  Object.assign(f, { model_type: row.model_type, model_id: String(row.model_id), period: 'all', action: '', user_id: '', q: '' })
  search.value = ''
}
function clearSubject() {
  subjectName.value = ''
  Object.assign(f, { model_type: '', model_id: '', period: 'd7' })
}

/* ---------- tafsilotlar ---------- */
function toggle(id) {
  const s = new Set(open.value)
  s.has(id) ? s.delete(id) : s.add(id)
  open.value = s
}
function props(row) {
  const p = row.properties
  if (!p || typeof p !== 'object') return p ? [['', String(p)]] : []
  return Object.entries(p).map(([k, v]) => [k, v === null ? '—' : typeof v === 'object' ? JSON.stringify(v) : String(v)])
}

function exportXlsx() {
  const p = new URLSearchParams({ ...params(), locale: locale.value })
  window.open(`/api/audit/export?${p.toString()}`, '_blank')
}
</script>

<template>
  <div>
    <PageHeader :title="t('insights.audit.title')" :subtitle="t('insights.audit.subtitle')">
      <template #actions>
        <button type="button" class="btn-ghost btn-sm" data-classic @click="router.push({ name: 'activity-logs-classic' })">
          {{ t('insights.audit.classic') }}
        </button>
        <button type="button" class="btn-outline" :disabled="!meta.total" data-export @click="exportXlsx">
          <FileSpreadsheet :size="16" /> {{ t('insights.audit.export') }}
        </button>
      </template>
    </PageHeader>

    <!-- Filtrlar -->
    <div class="flex flex-wrap items-end gap-3 mb-4">
      <div class="inline-flex rounded-md border border-line bg-surface p-0.5" role="group" data-periods>
        <button
          v-for="p in PERIODS" :key="p" type="button"
          class="px-2.5 py-1.5 text-[13px] rounded transition-colors"
          :class="f.period === p ? 'bg-assay-500 text-white' : 'text-ink-muted hover:text-ink'"
          :aria-pressed="f.period === p" :data-period="p"
          @click="f.period = p"
        >{{ t(`insights.audit.period.${p}`) }}</button>
      </div>
      <template v-if="f.period === 'custom'">
        <div class="w-40">
          <label class="field-label" for="al-from">{{ t('insights.audit.from') }}</label>
          <input id="al-from" v-model="f.from" type="date" class="field-input tnum" data-f="from" />
        </div>
        <div class="w-40">
          <label class="field-label" for="al-to">{{ t('insights.audit.to') }}</label>
          <input id="al-to" v-model="f.to" type="date" class="field-input tnum" data-f="to" />
        </div>
      </template>
      <div class="w-52">
        <label class="field-label" for="al-user">{{ t('insights.audit.user') }}</label>
        <select id="al-user" v-model="f.user_id" class="field-input" data-f="user">
          <option value="">{{ t('insights.audit.allUsers') }}</option>
          <option v-for="u in userOptions" :key="u.id" :value="String(u.id)">
            {{ u.id === 'system' ? t('insights.audit.system') : u.name }} · {{ num(u.count, locale) }}
          </option>
        </select>
      </div>
      <div class="w-56">
        <label class="field-label" for="al-model">{{ t('insights.audit.module') }}</label>
        <select id="al-model" v-model="f.model_type" class="field-input" data-f="model" @change="f.model_id = ''; subjectName = ''">
          <option value="">{{ t('insights.audit.allModules') }}</option>
          <option v-for="m in modelOptions" :key="m.type" :value="m.type">{{ m.label }} · {{ num(m.count, locale) }}</option>
        </select>
      </div>
      <div class="flex-1 min-w-[220px]">
        <label class="field-label" for="al-q">&nbsp;</label>
        <div class="relative">
          <Search :size="15" class="absolute left-3 top-1/2 -translate-y-1/2 text-ink-faint pointer-events-none" />
          <input id="al-q" v-model="search" type="search" class="field-input pl-9" :placeholder="t('insights.audit.search')" data-f="q" />
        </div>
      </div>
      <button v-if="anyFilter" type="button" class="btn-ghost btn-sm mb-0.5" data-clear @click="clearAll">
        <X :size="14" /> {{ t('insights.audit.clear') }}
      </button>
    </div>

    <!-- Yozuv tarixi rejimi -->
    <div v-if="f.model_type && f.model_id" class="mb-4 flex flex-wrap items-center gap-2 rounded-md border border-assay-200 bg-assay-50 px-3 py-2 dark:border-assay-800 dark:bg-assay-900/30" data-subject>
      <History :size="16" class="text-assay-700 dark:text-assay-300" />
      <span class="text-[13px] text-ink">
        {{ t('insights.audit.historyOf', { name: `${modelLabel(f.model_type)} #${f.model_id}` }) }}
        <span v-if="subjectName" class="text-ink-muted">— {{ subjectName }}</span>
        <span class="text-ink-faint"> · {{ f.period === 'all' ? t('insights.audit.historyAll') : t(`insights.audit.period.${f.period}`) }}</span>
      </span>
      <button type="button" class="ml-auto btn-ghost btn-sm" data-subject-clear @click="clearSubject"><X :size="14" /></button>
    </div>

    <!-- Amallar bo'yicha sonlar -->
    <div class="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-8 gap-2 mb-4" data-buckets>
      <div class="panel px-3 py-2.5" data-total>
        <p class="text-[12px] text-ink-muted">{{ t('insights.audit.total') }}</p>
        <p class="text-[20px] font-semibold tnum text-ink leading-tight">{{ summary ? num(summary.total, locale) : '—' }}</p>
      </div>
      <button
        v-for="b in BUCKETS" :key="b" type="button"
        class="panel px-3 py-2.5 text-left transition-colors hover:border-ink-faint"
        :class="[f.action === b ? 'ring-2 ring-assay-400 border-assay-400' : '', summary && !summary.actions[b] ? 'opacity-60' : '']"
        :aria-pressed="f.action === b" :data-bucket="b"
        @click="toggleAction(b)"
      >
        <span class="inline-block rounded px-1.5 py-0.5 text-[11.5px] font-medium" :class="TONE[b]">{{ t(`insights.audit.actions.${b}`) }}</span>
        <p class="text-[20px] font-semibold tnum text-ink leading-tight mt-1">{{ summary ? num(summary.actions[b] ?? 0, locale) : '—' }}</p>
      </button>
    </div>

    <p v-if="error" class="mb-4 rounded-md bg-crit-light px-3 py-2.5 text-[13px] text-crit dark:bg-crit-dark/25" data-error>{{ error }}</p>

    <!-- Ro'yxat -->
    <div class="panel overflow-hidden" :class="loading && rows.length ? 'opacity-70' : ''">
      <div v-if="loading && !rows.length" class="p-6 space-y-3" data-loading>
        <div v-for="i in 5" :key="i" class="h-5 rounded bg-surface-sunken animate-pulse" />
      </div>
      <div v-else-if="!rows.length" class="py-14 text-center" data-empty>
        <ScrollText :size="28" class="mx-auto text-ink-faint" />
        <p class="mt-3 text-[14px] text-ink">{{ t('insights.audit.empty') }}</p>
        <p class="mt-1 text-[13px] text-ink-faint">{{ t('insights.audit.emptyHint') }}</p>
      </div>

      <template v-else>
        <div class="overflow-x-auto">
          <table class="w-full text-left min-w-[760px]">
            <thead>
              <tr class="border-b border-line bg-surface-sunken/50 text-[12px] font-semibold text-ink-muted">
                <th class="pl-4 pr-2 py-2.5 w-[86px]">{{ t('insights.audit.col.when') }}</th>
                <th class="px-2 py-2.5 w-[150px]">{{ t('insights.audit.col.action') }}</th>
                <th class="px-2 py-2.5">{{ t('insights.audit.col.what') }}</th>
                <th class="px-2 py-2.5 w-[190px]">{{ t('insights.audit.col.user') }}</th>
                <th class="px-2 py-2.5 w-[120px]">{{ t('insights.audit.col.ip') }}</th>
                <th class="pr-3 py-2.5 w-[72px]" />
              </tr>
            </thead>
            <tbody v-for="d in days" :key="d.key" :data-day="d.key">
              <tr class="bg-surface-sunken/40">
                <th colspan="6" class="px-4 py-1.5 text-[12px] font-semibold text-ink-muted text-left">
                  <span class="capitalize">{{ d.head }}</span>
                  <span class="font-normal text-ink-faint tnum"> · {{ d.date }} · {{ t('insights.audit.dayCount', { n: d.rows.length }) }}</span>
                </th>
              </tr>
              <template v-for="r in d.rows" :key="r.id">
                <tr class="border-t border-line hover:bg-surface-sunken/50 transition-colors align-top" :data-row="r.id">
                  <td class="pl-4 pr-2 py-2.5 text-[12.5px] text-ink-muted tnum font-mono whitespace-nowrap">{{ time(r.at) }}</td>
                  <td class="px-2 py-2.5">
                    <span class="inline-block rounded px-2 py-0.5 text-[11.5px] font-medium whitespace-nowrap" :class="TONE[r.bucket] ?? 'bg-surface-sunken text-ink-muted'" data-action>{{ actionLabel(r) }}</span>
                  </td>
                  <td class="px-2 py-2.5 min-w-0">
                    <p class="text-[13px] text-ink break-words">{{ r.description ?? '—' }}</p>
                    <div v-if="r.model_type" class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-[12px]">
                      <span class="text-ink-faint" data-model>{{ modelLabel(r.model_type) }}<template v-if="r.model_id"> #{{ r.model_id }}</template></span>
                      <button v-if="linkOf(r)" type="button" class="text-assay-700 dark:text-assay-300 hover:underline underline-offset-2 inline-flex items-center gap-0.5" data-open
                              @click="router.push(linkOf(r).to)">
                        {{ linkOf(r).parent ? linkOf(r).label : t('insights.audit.open') }} <ChevronRight :size="13" />
                      </button>
                      <button v-if="r.subject?.trash" type="button" class="inline-flex items-center gap-1 rounded bg-crit-light px-1.5 py-0.5 text-crit dark:bg-crit-dark/25 dark:text-crit-light hover:underline" data-trash
                              @click="goTrash(r)">
                        <Trash2 :size="12" /> {{ t('insights.audit.inTrash') }}
                      </button>
                      <span v-else-if="r.subject?.state === 'gone'" class="text-ink-faint italic" data-gone>{{ t('insights.audit.gone') }}</span>
                    </div>
                  </td>
                  <td class="px-2 py-2.5 text-[13px] truncate max-w-[190px]" :class="r.user ? 'text-ink' : 'text-ink-faint italic'" data-user>
                    <button v-if="r.user" type="button" class="hover:underline underline-offset-2 text-left truncate max-w-full" @click="f.user_id = String(r.user.id)">{{ r.user.name }}</button>
                    <template v-else>{{ t('insights.audit.system') }}</template>
                  </td>
                  <td class="px-2 py-2.5 text-[12px] text-ink-faint font-mono whitespace-nowrap">{{ r.ip ?? '—' }}</td>
                  <td class="pr-3 py-2.5 text-right whitespace-nowrap">
                    <button v-if="r.model_type && r.model_id && !(f.model_type === r.model_type && String(f.model_id) === String(r.model_id))"
                            type="button" class="btn-ghost btn-sm btn-icon" :title="t('insights.audit.historyBtn')" :aria-label="t('insights.audit.historyBtn')" data-history
                            @click="showHistory(r)">
                      <History :size="15" />
                    </button>
                    <button type="button" class="btn-ghost btn-sm btn-icon" :title="t('insights.audit.details')" :aria-label="t('insights.audit.details')" :aria-expanded="open.has(r.id)" data-toggle
                            @click="toggle(r.id)">
                      <component :is="open.has(r.id) ? ChevronDown : ChevronRight" :size="15" />
                    </button>
                  </td>
                </tr>
                <tr v-if="open.has(r.id)" class="bg-surface-sunken/30" :data-details="r.id">
                  <td />
                  <td colspan="5" class="px-2 pb-3 pt-1">
                    <dl class="grid grid-cols-[max-content_1fr] gap-x-4 gap-y-1 text-[12.5px]">
                      <dt class="text-ink-faint">{{ t('insights.audit.time') }}</dt><dd class="text-ink tnum">{{ r.at }}</dd>
                      <template v-if="r.model_type">
                        <dt class="text-ink-faint">{{ t('insights.audit.record') }}</dt>
                        <dd class="text-ink">{{ modelLabel(r.model_type) }} #{{ r.model_id ?? '—' }} <span class="text-ink-faint font-mono">({{ r.model_type }})</span></dd>
                      </template>
                      <dt class="text-ink-faint">{{ t('insights.audit.user2') }}</dt><dd class="text-ink">{{ userName(r.user) }}</dd>
                      <dt class="text-ink-faint">{{ t('insights.audit.ip') }}</dt><dd class="text-ink font-mono">{{ r.ip ?? '—' }}</dd>
                    </dl>
                    <p class="mt-2 mb-1 text-[12px] font-semibold text-ink-muted">{{ t('insights.audit.details') }}</p>
                    <p v-if="!props(r).length" class="text-[12.5px] text-ink-faint">{{ t('insights.audit.noDetails') }}</p>
                    <dl v-else class="grid grid-cols-[max-content_1fr] gap-x-4 gap-y-1 text-[12.5px]" data-props>
                      <template v-for="[k, v] in props(r)" :key="k">
                        <dt class="text-ink-faint font-mono">{{ k }}</dt><dd class="text-ink break-all">{{ v }}</dd>
                      </template>
                    </dl>
                  </td>
                </tr>
              </template>
            </tbody>
          </table>
        </div>
        <Pagination :meta="meta" :per-page="perPage" @change="page = $event" @per-page="perPage = $event" />
      </template>
    </div>
  </div>
</template>
