<script setup>
/**
 * Bitta xodimning faoliyati: ko'rsatkichlar, smenalar, kun × smena jadvali va vaqt chizig'i.
 * Ruxsati yo'q xodim faqat o'z sahifasini ochadi («Mening faoliyatim»); boshqasini ochsa — server 403 qaytaradi.
 */
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import {
  ArrowLeft, BookText, CheckCheck, ClipboardList, Droplets, FilePlus2, FlaskConical, Microscope, PenLine,
  PlayCircle, RotateCcw, Scale, TestTube, UserRound,
} from 'lucide-vue-next'
import api from '@/lib/api'
import PageHeader from '@/components/ui/PageHeader.vue'
import { useAuthStore } from '@/stores/auth'
import { localized } from '@/i18n'
import PeriodBar from '../components/PeriodBar.vue'
import ShiftHeat from '../components/ShiftHeat.vue'
import { apiErrorMessage, bucketLabel, dm, hm, isoDate, num, parseTs, qty, rangeLabel } from '../helpers'
import '../i18n'

const { t, locale } = useI18n()
const route = useRoute()
const router = useRouter()
const auth = useAuthStore()

const PRESETS = ['shift', 'prev_shift', 'today', 'yesterday', 'week', 'month', 'year', 'custom']
const q = route.query ?? {}
const filters = ref({
  period: PRESETS.includes(q.period) ? q.period : 'week',
  date_from: q.date_from ?? '',
  date_to: q.date_to ?? '',
  laboratory_id: '', group_id: '', customer_department_id: '',
})
const withLog = ref(false)
const typeFilter = ref('all')

const data = ref(null)
const loading = ref(false)
const error = ref('')
let seq = 0

const userId = computed(() => route.params.id)

function params() {
  const f = filters.value
  const out = { period: f.period }
  if (f.period === 'custom') { out.date_from = f.date_from || undefined; out.date_to = f.date_to || undefined }
  return out
}

async function load() {
  const my = ++seq
  loading.value = true
  error.value = ''
  try {
    const { data: res } = await api.get(`/insights/staff/${userId.value}`, { params: { ...params(), with_log: withLog.value ? 1 : undefined } })
    if (my !== seq) return
    data.value = res
    if (typeFilter.value !== 'all' && !types.value.some((x) => x.type === typeFilter.value)) typeFilter.value = 'all'
  } catch (e) {
    if (my === seq) error.value = apiErrorMessage(e, t('insights.loadError'))
  } finally {
    if (my === seq) loading.value = false
  }
}
onMounted(load)

watch(filters, () => {
  const query = Object.fromEntries(Object.entries(params()).filter(([, v]) => v !== undefined && v !== ''))
  router.replace({ query }).catch(() => {})
  const f = filters.value
  if (f.period !== 'custom' || (f.date_from && f.date_to)) load()
}, { deep: true })
watch(withLog, load)
watch(userId, (v) => { if (v) load() })

const canAll = computed(() => auth.can('staff_activity.view'))
// Ruxsatsiz xodim faqat o'zini ko'radi — sarlavha «Mening faoliyatim»
const own = computed(() => !canAll.value)
const user = computed(() => data.value?.user ?? null)
const shifts = computed(() => data.value?.shifts ?? [])
const shiftName = (id) => shifts.value.find((s) => s.id === id)?.name ?? ''
const summary = computed(() => data.value?.summary ?? null)

const title = computed(() => (own.value ? t('insights.staff.ownTitle') : (user.value?.name ?? '…')))
const subtitle = computed(() => {
  if (own.value) return t('insights.staff.ownSubtitle')
  const u = user.value
  if (!u) return ''
  return [u.position, u.group ? localized(u.group, 'name') : null, u.role ? localized(u.role, 'display_name') : null].filter(Boolean).join(' · ')
})

const periodLabel = computed(() => {
  const p = data.value?.period
  if (!p) return ''
  if (p.shift) return `${p.shift.name} · ${rangeLabel(p.shift.start, p.shift.end, true)}`
  return rangeLabel(p.from, p.to)
})

/* ---- ko'rsatkichlar ---- */
const metricTiles = computed(() => {
  const m = summary.value?.metrics ?? {}
  return (data.value?.metrics ?? []).filter((k) => m[k]).map((k) => ({ key: k, value: m[k] }))
})
const shiftRows = computed(() => {
  const sh = summary.value?.shifts ?? {}
  const out = shifts.value.map((s) => ({ key: String(s.id), label: `${s.name}`, sub: `${s.start_time}–${s.end_time}`, value: sh[String(s.id)] ?? 0 }))
  if (sh.out) out.push({ key: 'out', label: t('insights.staff.out'), sub: '', value: sh.out })
  if (sh.nt) out.push({ key: 'nt', label: t('insights.staff.noTime'), sub: '', value: sh.nt })
  return out
})
const shiftMax = computed(() => Math.max(1, ...shiftRows.value.map((r) => r.value)))

/* ---- kun × smena ---- */
const heat = computed(() => {
  const p = data.value?.period
  if (!p || data.value?.grain !== 'day' || p.bucket !== 'day' || !shifts.value.length) return null
  const ds = summary.value?.day_shifts ?? {}
  const days = []
  const end = parseTs(p.end)
  for (let d = parseTs(p.from), guard = 0; d <= parseTs(p.to) && guard < 100; d.setDate(d.getDate() + 1), guard++) {
    const key = isoDate(d)
    days.push({ date: key, future: new Date(d.getFullYear(), d.getMonth(), d.getDate()) > end })
  }
  const rows = shifts.value.map((s) => ({ id: s.id, name: s.name, cells: Object.fromEntries(days.map((d) => [d.date, ds[d.date]?.[String(s.id)] ?? 0])) }))
  if (Object.values(ds).some((x) => x.nt)) rows.push({ id: 'nt', name: t('insights.staff.noTime'), cells: Object.fromEntries(days.map((d) => [d.date, ds[d.date]?.nt ?? 0])) })
  return { days, rows }
})

/* ---- vaqt chizig'i ---- */
const ICONS = {
  received: TestTube, started: PlayCircle, results: FlaskConical, worksheets: FilePlus2, approved: CheckCheck,
  signoffs: PenLine, extra: ClipboardList, retests: RotateCcw, movements: Scale, water: Droplets, equipment: Microscope, log: BookText,
}
const events = computed(() => data.value?.events ?? [])
const types = computed(() => {
  const c = {}
  for (const e of events.value) c[e.type] = (c[e.type] ?? 0) + 1
  return Object.entries(c).map(([type, n]) => ({ type, n }))
})
const shown = computed(() => (typeFilter.value === 'all' ? events.value : events.value.filter((e) => e.type === typeFilter.value)))
const days = computed(() => {
  const out = []
  for (const e of shown.value) {
    const d = e.at.slice(0, 10)
    if (!out.length || out[out.length - 1].date !== d) out.push({ date: d, items: [] })
    out[out.length - 1].items.push(e)
  }
  return out
})

const stageLabel = (st) => {
  const l = st?.label ?? {}
  return (locale.value === 'ru' ? l.ru : null) || l.uz || st?.key || ''
}
const wsText = (w) => (w ? `№ ${w.number}${w.kind === 'control' ? ` (${t('insights.staff.control')})` : ''}` : '')

/** Amal matni: sarlavha + bog'langan obyektlar (bosilsa ochiladi) + izoh */
function describe(e) {
  const links = []
  const sample = (s) => s?.id && links.push({ text: s.code, to: { name: 'sample-detail', params: { id: s.id } }, mono: true })
  const ws = (w) => w?.id && links.push({ text: wsText(w), to: { name: 'worksheet-detail', params: { id: w.id } } })
  let extra = ''
  let head = t(`insights.staff.ev.${e.type}`)
  switch (e.type) {
    case 'received': case 'started': sample(e.sample); if (e.sample?.number) extra = `№ ${e.sample.number}`; break
    case 'results': sample(e.sample); extra = (e.elements ?? []).join(', '); break
    case 'worksheets': ws(e.worksheet); extra = e.worksheet?.template ?? ''; break
    case 'approved': ws(e.worksheet); break
    case 'signoffs': head = `${head}: ${stageLabel(e.stage)}${e.stage?.slot > 1 ? ` (${e.stage.slot})` : ''}`; ws(e.worksheet); break
    case 'extra': ws(e.worksheet); sample(e.sample); break
    case 'retests': sample(e.sample); ws(e.worksheet); extra = e.note ?? ''; break
    case 'movements': {
      sample(e.sample)
      const k = t(`insights.staff.moveKinds.${e.kind}`)
      extra = `${k}: ${e.quantity > 0 ? '+' : ''}${qty(e.quantity, locale.value)} ${e.unit ?? ''}${e.note ? ` — ${e.note}` : ''}`
      break
    }
    case 'water': if (e.order?.id) links.push({ text: e.order.number, to: { name: 'water-order-detail', params: { id: e.order.id } } }); extra = e.parameter ?? ''; break
    case 'equipment': if (e.equipment?.id) links.push({ text: e.equipment.name, to: { name: 'equipment-detail', params: { id: e.equipment.id } } }); extra = e.note ?? ''; break
    case 'log': extra = e.note ?? e.action ?? ''; break
  }
  return { head, links, extra }
}

const back = () => router.push({ name: 'staff-activity', query: params() })
const open = (to) => router.push(to)
const fmtAt = (s) => { const d = parseTs(s); return d ? `${dm(d)} ${hm(d)}` : '—' }
</script>

<template>
  <div>
    <PageHeader :title="title" :subtitle="subtitle">
      <template #actions>
        <button v-if="canAll" type="button" class="btn-ghost btn-sm" data-back @click="back"><ArrowLeft :size="15" /> {{ t('insights.staff.back') }}</button>
      </template>
    </PageHeader>

    <PeriodBar v-model="filters" :shifts="shifts" />
    <p class="mt-2 mb-4 text-[12.5px] font-medium text-ink tnum" data-period-label>{{ periodLabel }}</p>

    <p v-if="error" class="panel p-4 mb-4 text-[14px] text-crit" data-error>{{ error }}</p>

    <template v-if="data">
      <!-- Ko'rsatkichlar -->
      <div class="grid gap-4 lg:grid-cols-3 mb-4" :class="loading ? 'opacity-70' : ''">
        <section class="panel p-4 lg:col-span-2" data-tiles>
          <div v-if="!metricTiles.length" class="py-6 text-center text-[13.5px] text-ink-muted" data-no-activity>
            <UserRound :size="26" class="mx-auto text-ink-faint mb-2" />{{ t('insights.staff.empty') }}
          </div>
          <div v-else class="grid gap-2 grid-cols-2 sm:grid-cols-3 xl:grid-cols-4">
            <div v-for="m in metricTiles" :key="m.key" class="rounded-md bg-surface-sunken/60 px-3 py-2.5" :data-metric="m.key">
              <p class="text-[22px] font-semibold tnum text-ink leading-none">{{ num(m.value, locale) }}</p>
              <p class="text-[12px] text-ink-muted mt-1.5 leading-tight">{{ t(`insights.staff.metrics.${m.key}`) }}</p>
            </div>
          </div>
          <dl v-if="summary" class="mt-3 pt-3 border-t border-line flex flex-wrap gap-x-6 gap-y-1 text-[12.5px]">
            <div v-if="summary.active_days != null" class="flex gap-1.5"><dt class="text-ink-muted">{{ t('insights.staff.activeDays') }}:</dt><dd class="font-semibold tnum text-ink" data-active-days>{{ summary.active_days }}</dd></div>
            <div v-if="summary.first_at" class="flex gap-1.5"><dt class="text-ink-muted">{{ t('insights.staff.first') }}:</dt><dd class="tnum text-ink">{{ fmtAt(summary.first_at) }}</dd></div>
            <div v-if="summary.last_at" class="flex gap-1.5"><dt class="text-ink-muted">{{ t('insights.staff.last') }}:</dt><dd class="tnum text-ink">{{ fmtAt(summary.last_at) }}</dd></div>
          </dl>
        </section>

        <section class="panel p-4" data-shift-split>
          <h2 class="text-[14px] font-semibold text-ink mb-3">{{ t('insights.staff.shifts') }}</h2>
          <p v-if="!shifts.length" class="text-[12.5px] text-ink-muted">{{ t('insights.shifts.notConfigured') }}</p>
          <dl v-else class="space-y-2">
            <div v-for="r in shiftRows" :key="r.key" :data-shift="r.key">
              <div class="flex items-baseline justify-between gap-2">
                <dt class="text-[13px] text-ink">{{ r.label }} <span v-if="r.sub" class="text-[11.5px] text-ink-faint tnum">{{ r.sub }}</span></dt>
                <dd class="text-[13.5px] font-semibold tnum text-ink">{{ num(r.value, locale) }}</dd>
              </div>
              <div class="h-1.5 mt-1 rounded-full bg-surface-sunken overflow-hidden">
                <div class="h-full rounded-full bg-assay-400 dark:bg-assay-500" :style="{ width: `${(r.value / shiftMax) * 100}%` }" />
              </div>
            </div>
          </dl>
        </section>
      </div>

      <ShiftHeat v-if="heat" :heat="heat" :title="t('insights.staff.heat')" :hint="t('insights.staff.heatHint')" class="mb-4" />

      <!-- Vaqt chizig'i -->
      <section class="panel p-4" data-timeline>
        <header class="flex flex-wrap items-center justify-between gap-2 mb-3">
          <h2 class="text-[14px] font-semibold text-ink">{{ t('insights.staff.timeline') }}</h2>
          <label class="inline-flex items-center gap-1.5 text-[12.5px] text-ink-muted cursor-pointer select-none">
            <input v-model="withLog" type="checkbox" class="rounded border-line" data-with-log> {{ t('insights.staff.withLog') }}
          </label>
        </header>
        <div v-if="types.length > 1" class="flex flex-wrap gap-1.5 mb-3" data-type-chips>
          <button type="button" class="rounded-full px-2.5 py-1 text-[12px] font-medium border" :class="typeFilter === 'all' ? 'border-assay-500 bg-assay-50 text-assay-800 dark:bg-assay-900/40 dark:text-assay-200' : 'border-line text-ink-muted hover:text-ink'" data-type="all" @click="typeFilter = 'all'">
            {{ t('insights.staff.allTypes') }} <span class="tnum">{{ events.length }}</span>
          </button>
          <button
            v-for="x in types" :key="x.type" type="button"
            class="rounded-full px-2.5 py-1 text-[12px] font-medium border inline-flex items-center gap-1"
            :class="typeFilter === x.type ? 'border-assay-500 bg-assay-50 text-assay-800 dark:bg-assay-900/40 dark:text-assay-200' : 'border-line text-ink-muted hover:text-ink'"
            :data-type="x.type" @click="typeFilter = x.type"
          >
            <component :is="ICONS[x.type]" :size="13" />{{ t(`insights.staff.ev.${x.type}`) }} <span class="tnum">{{ x.n }}</span>
          </button>
        </div>

        <p v-if="!shown.length" class="py-6 text-center text-[13px] text-ink-faint" data-no-events>{{ t('insights.staff.noEvents') }}</p>
        <div v-else class="space-y-4">
          <div v-for="d in days" :key="d.date" :data-day="d.date">
            <p class="sticky top-0 z-[1] bg-surface py-1 text-[12px] font-semibold uppercase tracking-wide text-ink-muted">{{ bucketLabel(d.date, 'day', locale, true) }}</p>
            <ol class="relative border-l border-line ml-2">
              <li v-for="(e, i) in d.items" :key="i" class="relative pl-5 py-1.5" :data-event="e.type">
                <span class="absolute -left-[9px] top-2 h-[18px] w-[18px] rounded-full bg-surface border border-line grid place-items-center text-ink-muted">
                  <component :is="ICONS[e.type]" :size="11" />
                </span>
                <div class="flex flex-wrap items-baseline gap-x-2 gap-y-0.5">
                  <span class="text-[12px] tnum font-medium text-ink-muted w-11 shrink-0" :title="e.time_known ? '' : t('insights.staff.timeUnknown')">{{ e.time_known ? e.at.slice(11, 16) : '—' }}</span>
                  <span class="text-[13px] text-ink">{{ describe(e).head }}</span>
                  <button
                    v-for="(l, li) in describe(e).links" :key="li" type="button"
                    class="text-[12.5px] text-assay-700 dark:text-assay-300 hover:underline underline-offset-2" :class="l.mono ? 'font-mono' : ''"
                    data-link @click="open(l.to)"
                  >{{ l.text }}</button>
                  <span v-if="describe(e).extra" class="text-[12px] text-ink-faint">{{ describe(e).extra }}</span>
                  <span v-if="e.shift_id" class="ml-auto rounded bg-surface-sunken px-1.5 py-0.5 text-[11px] text-ink-muted" data-shift-badge>{{ shiftName(e.shift_id) }}</span>
                </div>
              </li>
            </ol>
          </div>
          <p v-if="data.truncated" class="text-[12px] text-ink-faint" data-truncated>{{ t('insights.staff.truncated', { n: data.limit }) }}</p>
        </div>
      </section>
    </template>
    <div v-else-if="loading" class="space-y-3"><div v-for="i in 3" :key="i" class="h-24 rounded-panel bg-surface-sunken animate-pulse" /></div>
  </div>
</template>
