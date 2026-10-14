<script setup>
/**
 * Rahbariyat paneli 2.0 — laboratoriya ishi bir sahifada.
 * Ma'lumot: GET /api/insights/executive (davr, solishtirish, smenalar, ish oqimi, guruhlar, buyurtmachilar, varaqlar…).
 * Davr va filtrlar manzil satrida (?period=week&group_id=2) — sahifani yangilasa yoki havolani yuborsa saqlanadi.
 * Eski panel: «Eski ko'rinish» (/executive/classic).
 */
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import {
  AlertTriangle, CheckCircle2, ClipboardList, FlaskConical, History, RefreshCw, TestTube, Timer,
} from 'lucide-vue-next'
import api from '@/lib/api'
import PageHeader from '@/components/ui/PageHeader.vue'
import { useAuthStore } from '@/stores/auth'
import { useCatalogStore } from '@/stores/catalogs'
import { localized } from '@/i18n'
import PeriodBar from '../components/PeriodBar.vue'
import KpiTile from '../components/KpiTile.vue'
import FlowStrip from '../components/FlowStrip.vue'
import TrendChart from '../components/TrendChart.vue'
import ShiftPanel from '../components/ShiftPanel.vue'
import ShiftHeat from '../components/ShiftHeat.vue'
import MetricTable from '../components/MetricTable.vue'
import WorksheetsCard from '../components/WorksheetsCard.vue'
import AttentionCard from '../components/AttentionCard.vue'
import EquipmentEcologyCard from '../components/EquipmentEcologyCard.vue'
import { apiErrorMessage, hm, hours, num, rangeLabel } from '../helpers'
import '../i18n'

const { t, locale } = useI18n()
const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const catalogs = useCatalogStore()

const PRESETS = ['shift', 'prev_shift', 'today', 'yesterday', 'week', 'month', 'year', 'custom']
const q = route.query ?? {}
const filters = ref({
  period: PRESETS.includes(q.period) ? q.period : 'today',
  date_from: q.date_from ?? '',
  date_to: q.date_to ?? '',
  laboratory_id: q.laboratory_id ?? '',
  group_id: q.group_id ?? '',
  customer_department_id: q.customer_department_id ?? '',
})

const data = ref(null)
const departments = ref([])
const loading = ref(false)
const error = ref('')
const updatedAt = ref('')
let seq = 0

function params() {
  const f = filters.value
  const out = { period: f.period }
  if (f.period === 'custom') { out.date_from = f.date_from || undefined; out.date_to = f.date_to || undefined }
  for (const k of ['laboratory_id', 'group_id', 'customer_department_id']) if (f[k]) out[k] = f[k]
  return out
}

async function load() {
  const my = ++seq
  loading.value = true
  error.value = ''
  try {
    const { data: res } = await api.get('/insights/executive', { params: params() })
    if (my !== seq) return
    data.value = res
    updatedAt.value = hm(new Date())
  } catch (e) {
    if (my !== seq) return
    error.value = apiErrorMessage(e, t('insights.loadError'))
  } finally {
    if (my === seq) loading.value = false
  }
}

watch(filters, (f) => {
  const query = Object.fromEntries(Object.entries(params()).filter(([, v]) => v !== undefined && v !== ''))
  router.replace({ query }).catch(() => {})
  if (f.period !== 'custom' || (f.date_from && f.date_to)) load()
}, { deep: true })

/* ---- avto-yangilash: joriy smena / bugun uchun, sahifa ko'rinib turganda ---- */
const AUTO_KEY = 'lims.insights.auto'
const auto = ref((() => { try { return localStorage.getItem(AUTO_KEY) !== '0' } catch { return true } })())
watch(auto, (v) => { try { localStorage.setItem(AUTO_KEY, v ? '1' : '0') } catch { /* saqlanmasa ham ishlaydi */ } })
const live = computed(() => ['shift', 'today'].includes(filters.value.period))
let timer = null
onMounted(() => {
  load()
  catalogs.load('laboratories')
  catalogs.load('executorGroups')
  api.get('/customer-departments/options').then(({ data: d }) => { departments.value = Array.isArray(d) ? d : [] }).catch(() => {})
  timer = setInterval(() => {
    if (auto.value && live.value && !loading.value && (typeof document === 'undefined' || document.visibilityState !== 'hidden')) load()
  }, 120000)
})
onBeforeUnmount(() => clearInterval(timer))

const labs = computed(() => catalogs.get('laboratories'))
const groups = computed(() => catalogs.get('executorGroups'))
const shifts = computed(() => data.value?.shifts ?? [])
const canShifts = computed(() => auth.can('shifts.manage'))

/* ---- davr izohi ---- */
const periodNote = computed(() => {
  const p = data.value?.period
  if (!p) return null
  const withTime = p.bucket === 'hour'
  const main = p.shift
    ? `${p.shift.name} · ${rangeLabel(p.shift.start, p.shift.end, true)}`
    : rangeLabel(p.from, p.to, withTime && p.preset === 'custom')
  return {
    main,
    compare: t('insights.period.compare', { range: rangeLabel(p.prev_from, p.prev_to, withTime || !!p.shift) }),
    warn: p.shift_missing ? 'shiftMissing' : (p.preset === 'shift' && !p.shift_active ? 'shiftEnded' : null),
  }
})

/* ---- KPI ---- */
const k = computed(() => data.value?.kpi ?? null)
const kpiOk = computed(() => k.value && !k.value.error)

/* ---- dinamika ---- */
const trendTab = ref('samples')
const trendView = ref('chart')
const trendSeries = computed(() => (trendTab.value === 'samples'
  ? [{ key: 'received', label: t('insights.trend.received'), slot: 1 }, { key: 'completed', label: t('insights.trend.completed'), slot: 2 }]
  : [{ key: 'analyses', label: t('insights.trend.analysesSeries'), slot: 1 }]))
const bucketText = computed(() => ({ hour: t('insights.trend.byHour'), day: t('insights.trend.byDay'), month: t('insights.trend.byMonth') })[data.value?.trend?.bucket] ?? '')

/* ---- jadvallar ---- */
const groupCols = computed(() => [
  { key: 'in_work', label: t('insights.groups.cols.inWork'), bar: true },
  { key: 'overdue', label: t('insights.groups.cols.overdue'), alert: true },
  { key: 'received', label: t('insights.groups.cols.received') },
  { key: 'completed', label: t('insights.groups.cols.completed') },
  { key: 'analyses', label: t('insights.groups.cols.analyses') },
  { key: 'median_hours', label: t('insights.groups.cols.median'), kind: 'hours' },
])
const customerCols = computed(() => [
  { key: 'received', label: t('insights.customers.cols.received'), bar: true },
  { key: 'completed', label: t('insights.customers.cols.completed') },
  { key: 'in_work', label: t('insights.customers.cols.inWork') },
  { key: 'overdue', label: t('insights.customers.cols.overdue'), alert: true },
  { key: 'median_hours', label: t('insights.customers.cols.median'), kind: 'hours' },
])
const groupName = (r) => (r.unassigned ? t('insights.groups.unassigned') : localized(r, 'name'))
const customerName = (r) => (r.id ? r.name : t('insights.customers.none'))
const customerFooter = computed(() => {
  const o = data.value?.customers?.other
  return o ? { name: t('insights.customers.other', { n: o.count }), values: o } : null
})

const open = (to) => router.push(to)
</script>

<template>
  <div>
    <PageHeader :title="t('insights.title')" :subtitle="t('insights.subtitle')">
      <template #actions>
        <div class="flex flex-wrap items-center gap-2">
          <label v-if="live" class="inline-flex items-center gap-1.5 text-[12.5px] text-ink-muted cursor-pointer select-none">
            <input v-model="auto" type="checkbox" class="rounded border-line" data-auto> {{ t('insights.auto') }}
          </label>
          <span v-if="updatedAt" class="text-[12px] text-ink-faint tnum" data-updated>{{ t('insights.updated', { time: updatedAt }) }}</span>
          <button type="button" class="btn-outline btn-sm" :disabled="loading" data-refresh @click="load">
            <RefreshCw :size="14" :class="loading ? 'animate-spin' : ''" /> {{ t('insights.refresh') }}
          </button>
          <button type="button" class="btn-ghost btn-sm" data-classic @click="open({ name: 'executive-classic' })">
            <History :size="14" /> {{ t('insights.classic') }}
          </button>
        </div>
      </template>
    </PageHeader>

    <PeriodBar v-model="filters" :shifts="shifts" :labs="labs" :groups="groups" :departments="departments" />

    <div v-if="periodNote" class="mt-2 mb-4 flex flex-wrap items-center gap-x-3 gap-y-1 text-[12.5px]" data-period-note>
      <span class="font-medium text-ink tnum">{{ periodNote.main }}</span>
      <span class="text-ink-faint tnum">{{ periodNote.compare }}</span>
      <span v-if="periodNote.warn" class="inline-flex items-center gap-1 text-warn-dark dark:text-warn-light" data-period-warn>
        <AlertTriangle :size="13" /> {{ t(`insights.period.${periodNote.warn}`) }}
        <button v-if="periodNote.warn === 'shiftMissing' && canShifts" type="button" class="underline underline-offset-2" @click="open({ name: 'lab-shifts' })">{{ t('insights.period.setupShifts') }}</button>
      </span>
    </div>
    <div v-else class="mb-4" />

    <p v-if="error" class="panel p-4 mb-4 text-[14px] text-crit" data-error>{{ error }}</p>

    <div :class="loading && data ? 'opacity-70 transition-opacity' : 'transition-opacity'">
      <!-- ======= ASOSIY KO'RSATKICHLAR ======= -->
      <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-6 mb-4" data-kpis>
        <KpiTile
          :icon="TestTube" :label="t('insights.kpi.received')" :loading="!kpiOk"
          :value="k?.received?.value" :change="k?.received?.change" :to="{ name: 'samples' }" @open="open"
        />
        <KpiTile
          :icon="CheckCircle2" :label="t('insights.kpi.completed')" :loading="!kpiOk"
          :value="k?.completed?.value" :change="k?.completed?.change"
        />
        <KpiTile
          :icon="FlaskConical" :label="t('insights.kpi.analyses')" :hint="t('insights.kpi.analysesHint')" :loading="!kpiOk"
          :value="k?.analyses?.value" :change="k?.analyses?.change"
        />
        <KpiTile
          v-if="!kpiOk || k.worksheets" :icon="ClipboardList" :label="t('insights.kpi.worksheets')" :loading="!kpiOk"
          :value="k?.worksheets?.value" :change="k?.worksheets?.change"
          :sub="k?.worksheets ? t('insights.kpi.worksheetsSub', { n: num(k.worksheets.control, locale) }) : ''"
          :to="{ name: 'worksheets' }" @open="open"
        />
        <KpiTile
          :icon="Timer" :label="t('insights.kpi.turnaround')" :hint="t('insights.kpi.turnaroundHint')" :loading="!kpiOk"
          :value="k?.turnaround?.median == null ? null : hours(k.turnaround.median, locale)" :unit="t('insights.hours')"
          :change="k?.turnaround?.change" lower-is-better
          :sub="k?.turnaround?.count ? t('insights.kpi.turnaroundSub', { avg: hours(k.turnaround.avg, locale), p90: hours(k.turnaround.p90, locale) }) : ''"
        />
        <KpiTile
          :icon="AlertTriangle" :label="t('insights.kpi.overdue', { days: data?.overdue_days ?? 7 })" :loading="!kpiOk"
          :value="k?.overdue?.value" :compare="false" :tone="k?.overdue?.value ? 'crit' : 'ok'"
          :sub="k?.in_work ? t('insights.kpi.overdueSub', { n: num(k.in_work.value, locale) }) : ''"
        />
      </div>

      <!-- ======= ISH OQIMI ======= -->
      <p v-if="data?.flow?.error" class="panel p-4 mb-4 text-[13px] text-crit">{{ t('insights.blockError') }}</p>
      <FlowStrip v-else-if="data?.flow" :flow="data.flow" :overdue-days="data.overdue_days ?? 7" class="mb-4" />

      <!-- ======= DINAMIKA + SMENALAR ======= -->
      <div class="grid gap-4 lg:grid-cols-3 mb-4">
        <section class="panel p-4 lg:col-span-2 min-w-0" data-trend>
          <header class="flex flex-wrap items-center justify-between gap-2 mb-3">
            <div class="flex items-baseline gap-2">
              <h2 class="text-[14px] font-semibold text-ink">{{ t('insights.trend.title') }}</h2>
              <span class="text-[12px] text-ink-faint">{{ bucketText }}</span>
            </div>
            <div class="flex items-center gap-2">
              <div class="inline-flex rounded-md border border-line p-0.5 bg-surface-sunken/60" role="tablist">
                <button
                  v-for="tab in ['samples', 'analyses']" :key="tab" type="button"
                  class="px-2.5 h-7 rounded text-[12.5px] font-medium"
                  :class="trendTab === tab ? 'bg-surface text-ink shadow-sm' : 'text-ink-muted hover:text-ink'"
                  :data-trend-tab="tab" @click="trendTab = tab"
                >{{ t(`insights.trend.${tab}`) }}</button>
              </div>
              <div class="inline-flex rounded-md border border-line p-0.5 bg-surface-sunken/60">
                <button
                  v-for="v in ['chart', 'table']" :key="v" type="button"
                  class="px-2.5 h-7 rounded text-[12.5px] font-medium"
                  :class="trendView === v ? 'bg-surface text-ink shadow-sm' : 'text-ink-muted hover:text-ink'"
                  :data-trend-view="v" @click="trendView = v"
                >{{ t(`insights.trend.${v}`) }}</button>
              </div>
            </div>
          </header>
          <p v-if="data?.trend?.error" class="text-[13px] text-crit">{{ t('insights.blockError') }}</p>
          <TrendChart
            v-else-if="data?.trend"
            :points="data.trend.points" :series="trendSeries" :bucket="data.trend.bucket"
            :type="trendTab === 'samples' ? 'line' : 'bar'" :view="trendView"
          />
          <div v-else class="h-[230px] rounded bg-surface-sunken animate-pulse" />
        </section>

        <ShiftPanel :data="data?.by_shift ?? null" :can-configure="canShifts" @configure="open({ name: 'lab-shifts' })" />
      </div>

      <ShiftHeat v-if="data?.by_shift?.heat?.rows?.length" :heat="data.by_shift.heat" class="mb-4" />

      <!-- ======= Chap: guruhlar, buyurtmachilar, uskunalar · O'ng: diqqat, varaqlar ======= -->
      <div class="grid gap-4 lg:grid-cols-3 items-start">
        <div class="lg:col-span-2 space-y-4 min-w-0">
          <section class="panel p-4" data-groups>
            <h2 class="text-[14px] font-semibold text-ink mb-3">{{ t('insights.groups.title') }}</h2>
            <p v-if="data?.groups?.error" class="text-[13px] text-crit">{{ t('insights.blockError') }}</p>
            <p v-else-if="data?.groups && !data.groups.rows.length" class="text-[13px] text-ink-faint py-4">{{ t('insights.groups.empty') }}</p>
            <MetricTable
              v-else-if="data?.groups"
              :rows="data.groups.rows" :columns="groupCols" :name-label="t('insights.groups.cols.group')"
              :name-of="groupName" :sub-of="(r) => r.code" :muted="(r) => !!r.unassigned"
            />
          </section>

          <section class="panel p-4" data-customers>
            <h2 class="text-[14px] font-semibold text-ink mb-3">{{ t('insights.customers.title') }}</h2>
            <p v-if="data?.customers?.available === false" class="text-[13px] text-ink-faint">{{ t('insights.customers.unavailable') }}</p>
            <p v-else-if="data?.customers?.error" class="text-[13px] text-crit">{{ t('insights.blockError') }}</p>
            <p v-else-if="data?.customers && !data.customers.rows.length" class="text-[13px] text-ink-faint py-4">{{ t('insights.customers.empty') }}</p>
            <MetricTable
              v-else-if="data?.customers"
              :rows="data.customers.rows" :columns="customerCols" :name-label="t('insights.customers.cols.department')"
              :name-of="customerName" :sub-of="(r) => r.code" :muted="(r) => !r.id" :footer="customerFooter"
            />
          </section>

          <EquipmentEcologyCard :equipment="data?.equipment ?? null" :ecology="data?.ecology ?? null" @open="open" />
        </div>

        <div class="space-y-4 min-w-0">
          <AttentionCard
            :flow="data?.flow" :worksheets="data?.worksheets" :balances="data?.balances" :equipment="data?.equipment"
            :overdue-days="data?.overdue_days ?? 7" @open="open"
          />
          <WorksheetsCard :data="data?.worksheets ?? null" @open="open" />
        </div>
      </div>
    </div>
  </div>
</template>
