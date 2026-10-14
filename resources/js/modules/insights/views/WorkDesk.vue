<script setup>
/**
 * Ish stoli — bosh sahifa, rolga (ruxsatlarga) qarab.
 * Ma'lumot: GET /api/insights/desk. Har 2 daqiqada o'zi yangilanadi (sahifa ko'rinib turganda).
 * Eski bosh sahifa: «Eski ko'rinish» (/dashboard/classic).
 */
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import {
  ArrowRight, ClipboardCheck, ClipboardList, Clock, Droplets, FileSpreadsheet, FlaskConical, Hourglass, Inbox,
  ListTodo, Microscope, Package, RefreshCw, RotateCcw, Scale, TestTube, TrendingUp, UserCheck, History,
} from 'lucide-vue-next'
import api from '@/lib/api'
import PageHeader from '@/components/ui/PageHeader.vue'
import { useAuthStore } from '@/stores/auth'
import { localized } from '@/i18n'
import DeskCard from '../components/DeskCard.vue'
import { apiErrorMessage, dmy, dm, hm, num, parseTs, qty, rangeLabel, tag } from '../helpers'
import '../i18n'

const { t, locale } = useI18n()
const router = useRouter()
const auth = useAuthStore()

const data = ref(null)
const loading = ref(false)
const error = ref('')
const now = ref(new Date())

async function load() {
  loading.value = true
  error.value = ''
  try {
    const { data: res } = await api.get('/insights/desk')
    data.value = res
    now.value = parseTs(res.now) ?? new Date()
  } catch (e) {
    error.value = apiErrorMessage(e, t('insights.loadError'))
  } finally {
    loading.value = false
  }
}

let timer = null
let clock = null
onMounted(() => {
  load()
  timer = setInterval(() => {
    if (!loading.value && (typeof document === 'undefined' || document.visibilityState !== 'hidden')) load()
  }, 120000)
  clock = setInterval(() => { now.value = new Date(now.value.getTime() + 30000) }, 30000)
})
onBeforeUnmount(() => { clearInterval(timer); clearInterval(clock) })

const open = (to) => router.push(to)
const cards = computed(() => data.value?.cards ?? {})

/* ---- sarlavha ---- */
const greeting = computed(() => {
  const h = now.value.getHours()
  const k = h < 5 ? 'night' : h < 12 ? 'morning' : h < 18 ? 'day' : h < 23 ? 'evening' : 'night'
  const name = data.value?.user?.name ?? auth.user?.name ?? ''
  return `${t(`insights.desk.greet.${k}`)}${name ? `, ${name}` : ''}`
})
const today = computed(() => {
  const d = now.value
  const wd = d.toLocaleDateString(tag(locale.value), { weekday: 'long' })
  return `${wd.charAt(0).toUpperCase()}${wd.slice(1)}, ${dmy(d)}`
})
const shiftLine = computed(() => {
  const s = data.value?.shift
  if (!data.value) return ''
  if (!s) return t('insights.desk.noShift')
  const end = parseTs(s.end)
  const mins = Math.max(0, Math.round((end - now.value) / 60000))
  const left = mins >= 60 ? t('insights.desk.hm', { h: Math.floor(mins / 60), m: mins % 60 }) : t('insights.desk.m', { m: mins })
  return `${t('insights.desk.shift', { name: s.name, range: `${s.start_time}–${s.end_time}` })} · ${t('insights.desk.shiftLeft', { left })}`
})
const subtitle = computed(() => [today.value, shiftLine.value].filter(Boolean).join(' · '))

/* ---- mening ishim ---- */
const myTiles = computed(() => {
  const m = data.value?.my?.metrics ?? {}
  return (data.value?.my?.order ?? []).filter((k) => m[k]).map((k) => ({ key: k, value: m[k] }))
})
const myTitle = computed(() => (data.value?.period?.preset === 'shift' ? t('insights.desk.my.shift') : t('insights.desk.my.today')))
const mySince = computed(() => {
  const my = data.value?.my
  if (!my?.first_at) return ''
  return t('insights.desk.my.since', { from: hm(parseTs(my.first_at)), to: hm(parseTs(my.last_at)) })
})

/* ---- tezkor havolalar ---- */
const quick = computed(() => [
  { key: 'samples', icon: TestTube, to: { name: 'samples' }, show: auth.can('samples.view') },
  { key: 'worksheets', icon: FileSpreadsheet, to: { name: 'worksheets' }, show: auth.can('worksheets.view') },
  { key: 'balances', icon: Scale, to: { name: 'sample-balances' }, show: auth.can('samples.view') },
  { key: 'executive', icon: TrendingUp, to: { name: 'executive' }, show: auth.can('executive.view') },
  { key: auth.can('staff_activity.view') ? 'staff' : 'myActivity', icon: UserCheck, to: { name: 'staff-activity' }, show: true },
  { key: 'shifts', icon: Clock, to: { name: 'lab-shifts' }, show: auth.can('shifts.manage') },
  { key: 'equipment', icon: Microscope, to: { name: 'equipment' }, show: auth.can('equipment.view') && !auth.can('executive.view') },
  { key: 'water', icon: Droplets, to: { name: 'water-orders' }, show: auth.can('water_orders.result') },
].filter((q) => q.show))

/* ---- kartochkalar ---- */
const dd = (n) => t('insights.desk.days', { n })
const inDays = (n) => (n === 0 ? t('insights.desk.today') : n < 0 ? t('insights.desk.ago', { n: -n }) : t('insights.desk.inDays', { n }))
const wsNo = (w) => `№ ${w.number}${w.kind === 'control' ? ` (${t('insights.staff.control')})` : ''}`
const groupName = (g) => (g ? localized(g, 'name') : '')

const list = computed(() => {
  const c = cards.value
  const out = []
  if (c.intake) {
    out.push({
      name: 'intake', icon: Inbox, title: t('insights.desk.cards.intake'), count: c.intake.count, error: !!c.intake.error,
      chips: c.intake.error ? [] : [{ label: t('insights.desk.cards.intakeToday', { total: num(c.intake.today_total, locale.value), mine: num(c.intake.today_mine, locale.value) }) }],
      subtitle: t('insights.desk.cards.unassigned'),
      items: (c.intake.items ?? []).map((s) => ({ key: `i${s.id}`, main: s.code, mono: true, sub: s.number ? `№ ${s.number}` : '', badge: dd(s.days), alert: s.days >= 7, to: { name: 'sample-detail', params: { id: s.id } } })),
      recent: (c.intake.recent ?? []).map((s) => ({ key: `r${s.id}`, main: s.code, mono: true, sub: t(`insights.desk.status.${s.status}`), badge: hm(parseTs(s.at)), to: { name: 'sample-detail', params: { id: s.id } } })),
      more: { name: 'samples' },
    })
  }
  if (c.queue) {
    const q = c.queue
    out.push({
      name: 'queue', icon: ListTodo, title: `${t('insights.desk.cards.queue')}${q.group ? ` · ${groupName(q.group)}` : ''}`, count: q.count, error: !!q.error,
      chips: q.error ? [] : [
        { label: `${t('insights.desk.cards.queueAssigned')}: ${q.assigned}` },
        { label: `${t('insights.desk.cards.queueInProgress')}: ${q.in_progress}` },
        ...(q.overdue ? [{ label: `${t('insights.desk.cards.queueOverdue', { days: 7 })}: ${q.overdue}`, alert: true }] : []),
      ],
      items: (q.items ?? []).map((s) => ({
        key: `q${s.id}`, main: s.code, mono: true,
        sub: [t(`insights.desk.status.${s.status}`), s.pending ? t('insights.desk.cards.pending', { n: s.pending }) : '', s.worksheet ? wsNo(s.worksheet) : ''].filter(Boolean).join(' · '),
        badge: dd(s.days), alert: s.days >= 7, to: { name: 'sample-detail', params: { id: s.id } },
      })),
      more: { name: 'samples' },
    })
  }
  if (c.my_worksheets) {
    const w = c.my_worksheets
    out.push({
      name: 'my_worksheets', icon: FileSpreadsheet, title: t('insights.desk.cards.myWorksheets'), count: w.count, error: !!w.error,
      chips: Object.entries(w.by_status ?? {}).map(([k, v]) => ({ label: `${t(`insights.worksheets.status.${k}`)}: ${v}` })),
      items: (w.items ?? []).map((x) => ({
        key: `w${x.id}`, main: wsNo(x),
        sub: [x.template, x.date ? dm(parseTs(x.date)) : '', x.samples ? t('insights.desk.cards.samplesN', { n: x.samples }) : ''].filter(Boolean).join(' · '),
        badge: t(`insights.worksheets.status.${x.status}`), to: { name: 'worksheet-detail', params: { id: x.id } },
      })),
      more: { name: 'worksheets' },
    })
  }
  if (c.retests) {
    out.push({
      name: 'retests', icon: RotateCcw, title: t('insights.desk.cards.retests'), count: c.retests.count, alert: true, error: !!c.retests.error,
      items: (c.retests.items ?? []).map((r) => ({
        key: `rt${r.sample.id}-${r.worksheet.id}`, main: r.sample.code, mono: true, sub: [wsNo(r.worksheet), r.reason].filter(Boolean).join(' · '),
        badge: dd(r.days), to: { name: 'worksheet-detail', params: { id: r.worksheet.id } },
      })),
    })
  }
  if (c.approvals) {
    const a = c.approvals
    out.push({
      name: 'approvals', icon: ClipboardCheck, title: t('insights.desk.cards.approvals'), count: a.count, error: !!a.error,
      chips: a.old ? [{ label: t('insights.desk.cards.approvalsOld', { n: a.old }), alert: true }] : [],
      items: (a.items ?? []).map((w) => ({ key: `a${w.id}`, main: wsNo(w), sub: w.template ?? '', badge: dd(w.days), alert: w.days >= 2, to: { name: 'worksheet-detail', params: { id: w.id } } })),
      more: { name: 'worksheets' },
    })
  }
  if (c.overdue) {
    out.push({
      name: 'overdue', icon: Hourglass, title: t('insights.desk.cards.overdue', { days: c.overdue.days ?? 7 }), count: c.overdue.count, alert: true, error: !!c.overdue.error,
      items: (c.overdue.items ?? []).map((s) => ({ key: `o${s.id}`, main: s.code, mono: true, sub: [groupName(s.group), t(`insights.desk.status.${s.status}`)].filter(Boolean).join(' · '), badge: dd(s.days), alert: true, to: { name: 'sample-detail', params: { id: s.id } } })),
      more: { name: 'executive' },
    })
  }
  if (c.calibration) {
    const e = c.calibration
    out.push({
      name: 'calibration', icon: Microscope, title: t('insights.desk.cards.calibration'), count: e.count, alert: e.overdue > 0, error: !!e.error,
      chips: [
        ...(e.overdue ? [{ label: t('insights.desk.cards.calOverdue', { n: e.overdue }), alert: true }] : []),
        ...(e.blocked ? [{ label: t('insights.desk.cards.blocked', { n: e.blocked }) }] : []),
      ],
      items: (e.items ?? []).map((x) => ({ key: `e${x.id}`, main: x.name, sub: [x.serial, dmy(parseTs(x.date))].filter(Boolean).join(' · '), badge: inDays(x.in_days), alert: x.in_days < 0, to: { name: 'equipment-detail', params: { id: x.id } } })),
      more: { name: 'equipment' },
    })
  }
  if (c.storage) {
    out.push({
      name: 'storage', icon: Package, title: t('insights.desk.cards.storage'), count: c.storage.count, error: !!c.storage.error,
      items: (c.storage.items ?? []).map((o) => ({ key: `s${o.id}`, main: o.identifier, mono: true, sub: o.name, badge: inDays(o.in_days), alert: o.in_days < 0, to: { name: 'test-object-detail', params: { id: o.id } } })),
      more: { name: 'test-objects' },
    })
  }
  if (c.water) {
    out.push({
      name: 'water', icon: Droplets, title: t('insights.desk.cards.water'), count: c.water.count, error: !!c.water.error,
      items: (c.water.items ?? []).map((o) => ({ key: `wa${o.id}`, main: o.number, mono: true, sub: o.point ?? '', badge: dd(o.days), alert: o.days >= 7, to: { name: 'water-order-detail', params: { id: o.id } } })),
      more: { name: 'water-orders' },
    })
  }
  if (c.balances) {
    out.push({
      name: 'balances', icon: Scale, title: t('insights.desk.cards.balances'), count: c.balances.count, error: !!c.balances.error,
      items: (c.balances.items ?? []).map((b) => ({
        key: `b${b.id}`, main: b.code, mono: true,
        sub: b.available != null ? t('insights.attention.left', { v: `${qty(b.available, locale.value)} ${b.unit ?? ''}`.trim() }) : '',
        badge: t(`insights.attention.state.${b.state}`), alert: b.state !== 'low', to: { name: 'sample-detail', params: { id: b.id } },
      })),
      more: { name: 'sample-balances' },
    })
  }
  return out
})

const live = computed(() => cards.value.live && !cards.value.live.error ? cards.value.live : null)
const liveItems = computed(() => {
  const l = live.value
  if (!l) return []
  return [
    { key: 'received', label: t('insights.kpi.received'), value: l.received, icon: TestTube },
    { key: 'completed', label: t('insights.kpi.completed'), value: l.completed, icon: ClipboardList },
    { key: 'analyses', label: t('insights.kpi.analyses'), value: l.analyses, icon: FlaskConical },
    ...(l.worksheets != null ? [{ key: 'worksheets', label: t('insights.kpi.worksheets'), value: l.worksheets, icon: FileSpreadsheet }] : []),
    { key: 'in_work', label: t('insights.desk.cards.inWork'), value: l.in_work, icon: Hourglass },
  ]
})
const periodRange = computed(() => {
  const p = data.value?.period
  if (!p) return ''
  return p.shift ? rangeLabel(p.shift.start, p.shift.end, true) : rangeLabel(p.from, p.to)
})
</script>

<template>
  <div>
    <PageHeader :title="greeting" :subtitle="subtitle">
      <template #actions>
        <div class="flex items-center gap-2">
          <button type="button" class="btn-outline btn-sm" :disabled="loading" data-refresh @click="load">
            <RefreshCw :size="14" :class="loading ? 'animate-spin' : ''" /> {{ t('insights.refresh') }}
          </button>
          <button type="button" class="btn-ghost btn-sm" data-classic @click="open({ name: 'dashboard-classic' })">
            <History :size="14" /> {{ t('insights.desk.classic') }}
          </button>
        </div>
      </template>
    </PageHeader>

    <p v-if="error" class="panel p-4 mb-4 text-[14px] text-crit" data-error>{{ error }}</p>

    <!-- Mening ishim + tezkor havolalar -->
    <div class="grid gap-4 lg:grid-cols-3 mb-4">
      <section class="panel p-4 lg:col-span-2" data-my>
        <header class="flex flex-wrap items-baseline justify-between gap-2 mb-3">
          <h2 class="text-[14px] font-semibold text-ink">{{ myTitle }} <span v-if="periodRange" class="ml-1 text-[12px] font-normal text-ink-faint tnum">{{ periodRange }}</span></h2>
          <button type="button" class="text-[12.5px] text-assay-700 dark:text-assay-300 hover:underline underline-offset-2 inline-flex items-center gap-1" data-my-more @click="open({ name: 'staff-activity-user', params: { id: data?.user?.id ?? auth.user?.id }, query: { period: data?.period?.preset === 'shift' ? 'shift' : 'today' } })">
            {{ t('insights.desk.my.more') }} <ArrowRight :size="13" />
          </button>
        </header>
        <div v-if="!data && loading" class="grid grid-cols-2 sm:grid-cols-4 gap-2"><div v-for="i in 4" :key="i" class="h-16 rounded bg-surface-sunken animate-pulse" /></div>
        <p v-else-if="data && !myTiles.length" class="py-4 text-[13.5px] text-ink-muted" data-my-empty>{{ t('insights.desk.my.empty') }}</p>
        <div v-else class="grid gap-2 grid-cols-2 sm:grid-cols-3 xl:grid-cols-4">
          <div v-for="m in myTiles" :key="m.key" class="rounded-md bg-surface-sunken/60 px-3 py-2.5" :data-my-metric="m.key">
            <p class="text-[22px] font-semibold tnum text-ink leading-none">{{ num(m.value, locale) }}</p>
            <p class="text-[12px] text-ink-muted mt-1.5 leading-tight">{{ t(`insights.staff.metrics.${m.key}`) }}</p>
          </div>
        </div>
        <p v-if="mySince" class="mt-2 text-[12px] text-ink-faint tnum" data-my-since>{{ mySince }}</p>
      </section>

      <section class="panel p-4" data-quick>
        <h2 class="text-[14px] font-semibold text-ink mb-2">{{ t('insights.desk.quick.title') }}</h2>
        <div class="grid grid-cols-2 gap-1.5">
          <button
            v-for="q in quick" :key="q.key" type="button"
            class="flex items-center gap-2 rounded-md border border-line px-2.5 py-2 text-left text-[13px] text-ink hover:border-ink-faint hover:bg-surface-sunken/60 transition-colors"
            :data-quick="q.key" @click="open(q.to)"
          >
            <component :is="q.icon" :size="15" class="shrink-0 text-assay-600 dark:text-assay-300" />
            <span class="truncate">{{ t(`insights.desk.quick.${q.key}`) }}</span>
          </button>
        </div>
      </section>
    </div>

    <!-- Hozirgi smena (laboratoriya) -->
    <section v-if="live" class="panel p-4 mb-4" data-live>
      <header class="flex items-center justify-between gap-2 mb-3">
        <h2 class="text-[14px] font-semibold text-ink">{{ data?.period?.preset === 'shift' ? t('insights.desk.cards.live') : t('insights.desk.cards.liveToday') }}</h2>
        <button type="button" class="text-[12.5px] text-assay-700 dark:text-assay-300 hover:underline underline-offset-2" @click="open({ name: 'executive', query: { period: data?.period?.preset === 'shift' ? 'shift' : 'today' } })">{{ t('insights.desk.quick.executive') }} →</button>
      </header>
      <div class="grid gap-2 grid-cols-2 sm:grid-cols-3 lg:grid-cols-5">
        <div v-for="it in liveItems" :key="it.key" class="flex items-center gap-3 rounded-md bg-surface-sunken/60 px-3 py-2.5" :data-live-item="it.key">
          <component :is="it.icon" :size="18" class="shrink-0 text-assay-600 dark:text-assay-300" />
          <div class="min-w-0">
            <p class="text-[20px] font-semibold tnum text-ink leading-none">{{ num(it.value, locale) }}</p>
            <p class="text-[11.5px] text-ink-muted mt-1 truncate">{{ it.label }}</p>
          </div>
        </div>
      </div>
    </section>

    <!-- Kartochkalar -->
    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3 items-start" data-cards>
      <DeskCard
        v-for="c in list" :key="c.name" :name="c.name"
        :title="c.title" :icon="c.icon" :count="c.count" :alert="!!c.alert" :chips="c.chips ?? []"
        :items="c.items" :more="c.more ?? null" :error="c.error" :subtitle="c.subtitle ?? ''" @open="open"
      />
    </div>

    <!-- Qabul: oxirgi qabullarim (operator) -->
    <section v-if="list.find((c) => c.name === 'intake')?.recent?.length" class="panel p-4 mt-4" data-recent>
      <h2 class="text-[14px] font-semibold text-ink mb-2">{{ t('insights.desk.cards.recent') }}</h2>
      <ul class="divide-y divide-line -mx-1">
        <li v-for="it in list.find((c) => c.name === 'intake').recent" :key="it.key">
          <button type="button" class="w-full flex items-center gap-2 px-1 py-1.5 text-left rounded hover:bg-surface-sunken/60" :data-item="it.key" @click="open(it.to)">
            <span class="font-mono text-[13px] text-ink">{{ it.main }}</span>
            <span class="text-[12px] text-ink-faint">{{ it.sub }}</span>
            <span class="ml-auto text-[12px] tnum text-ink-muted">{{ it.badge }}</span>
          </button>
        </li>
      </ul>
    </section>
  </div>
</template>
