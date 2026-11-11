<script setup>
/**
 * [Paket 32] Natijalar tahlili (menyuda «AI tahlil»).
 * Bo'lim → svodka nuqtasi → ko'rsatkich: dinamika, nuqtaning o'z tarixiga nisbatan baho
 * (keskin chetlanish / e'tibor / barqaror / ma'lumot kam), trend, navbatchiliklar farqi.
 * Faqat laboratoriya rahbariyati (results.analysis yoki «Svodkani ko'ruvchilar»).
 */
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { Info, Loader2, Minus, Sparkles, TrendingDown, TrendingUp, X } from 'lucide-vue-next'
import api from '@/lib/api'
import PageHeader from '@/components/ui/PageHeader.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import { formatNumber } from '@/lib/format'
import { addDays, apiError, dayLabel, todayIso } from '../shared'
import '../i18n'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()

const opts = ref(null)
const optsError = ref('')
const f = reactive({ department_id: null, section_id: '', point_id: '', symbol: '', shift: '', from: addDays(todayIso(), -29), to: todayIso() })
const res = ref(null)
const loading = ref(false)
const error = ref('')
const selKey = ref(null)
const hover = ref(null)
const showMethod = ref(false)

const dept = computed(() => opts.value?.departments?.find((d) => d.id === f.department_id) ?? null)
const sections = computed(() => dept.value?.sections ?? [])
const points = computed(() => {
  const list = f.section_id ? sections.value.filter((s) => s.id === Number(f.section_id)) : sections.value
  return list.flatMap((s) => s.points.map((p) => ({ ...p, section: s.title })))
})

const keyOf = (it) => `${it.point_id ?? 0}|${it.symbol}|${it.unit}`
const items = computed(() => res.value?.items ?? [])
const selected = computed(() => items.value.find((it) => keyOf(it) === selKey.value) ?? null)
const flagged = computed(() => items.value.filter((it) => it.verdict === 'deviation' || it.verdict === 'attention'))

const VERDICT_CLS = {
  deviation: 'bg-crit-light text-crit dark:bg-crit-dark/30',
  attention: 'bg-warn-light text-warn dark:bg-warn-dark/30',
  normal: 'bg-ok-light text-ok dark:bg-ok-dark/30',
  few: 'bg-surface-sunken text-ink-muted',
}
const TREND_ICON = { increasing: TrendingUp, decreasing: TrendingDown, stable: Minus, unknown: Minus }

const num = (v, d = 3) => (v === null || v === undefined ? '—' : formatNumber(v, d))
const pct = (v) => (v === null || v === undefined ? '—' : `${v > 0 ? '+' : ''}${formatNumber(v, 1)}%`)
const shiftName = (s) => t(s === 1 ? 'svodka.ra.shift1' : 'svodka.ra.shift2')
const pointName = (it) => it.point ?? t('svodka.ra.noPoint')
const symbolName = (it) => (it.unit ? `${it.symbol}, ${it.unit}` : it.symbol)

function reasonText(r) {
  if (r.type === 'last') {
    return t('svodka.ra.reason.last', { value: num(r.value), typical: num(r.typical), dir: t(r.z > 0 ? 'svodka.ra.up' : 'svodka.ra.down'), z: formatNumber(r.z, 1) })
  }
  if (r.type === 'outliers') return t('svodka.ra.reason.outliers', { n: r.count })
  if (r.type === 'trend') return t('svodka.ra.reason.trend', { pct: pct(r.pct), dir: t(r.pct > 0 ? 'svodka.ra.upTrend' : 'svodka.ra.downTrend') })
  if (r.type === 'shift') return t('svodka.ra.reason.shift', { pct: formatNumber(Math.abs(r.pct), 1), dir: t(r.pct > 0 ? 'svodka.ra.up' : 'svodka.ra.down') })
  return ''
}

async function loadOptions() {
  try {
    const { data } = await api.get('/result-analysis/options')
    opts.value = data
    const want = Number(route.query.department_id)
    const list = data.departments ?? []
    f.department_id = (list.find((d) => d.id === want) ?? list.find((d) => d.sections.length) ?? list[0])?.id ?? null
    if (f.department_id) run()
  } catch (e) {
    optsError.value = apiError(e, t('svodka.loadError'))
  }
}

async function run() {
  if (!f.department_id) return
  loading.value = true
  error.value = ''
  try {
    const params = Object.fromEntries(Object.entries(f).filter(([, v]) => v !== '' && v !== null))
    const { data } = await api.get('/result-analysis', { params })
    res.value = data
    if (!data.items.some((it) => keyOf(it) === selKey.value)) selKey.value = null
    router.replace({ query: { ...route.query, department_id: f.department_id } })
  } catch (e) {
    error.value = apiError(e, t('svodka.loadError'))
    res.value = null
  } finally {
    loading.value = false
  }
}

function preset(days) {
  f.to = todayIso()
  f.from = addDays(f.to, -(days - 1))
  run()
}

watch(() => f.department_id, (v, old) => { if (old !== null && v !== old) { f.section_id = ''; f.point_id = ''; run() } })
watch(() => f.section_id, () => { f.point_id = '' })

// ---------------------------------------------------------------- grafiklar
function scaleFor(it, h, pad, padBottom = pad) {
  const vals = it.series.map((s) => s.value)
  let lo = Math.min(...vals, ...(it.band ? [it.band[0]] : []))
  let hi = Math.max(...vals, ...(it.band ? [it.band[1]] : []))
  if (hi === lo) { hi += Math.abs(hi) * 0.1 || 1; lo -= Math.abs(lo) * 0.1 || 1 }
  return (v) => pad + (h - pad - padBottom) * (1 - (v - lo) / (hi - lo))
}
function xFor(it, w, pad) {
  const n = it.series.length
  return (i) => (n > 1 ? pad + (i * (w - 2 * pad)) / (n - 1) : w / 2)
}
function spark(it) {
  const w = 120, h = 28, y = scaleFor(it, h, 3), x = xFor(it, w, 3)
  return {
    path: it.series.map((s, i) => `${i ? 'L' : 'M'}${x(i).toFixed(1)},${y(s.value).toFixed(1)}`).join(' '),
    marks: it.series.map((s, i) => ({ x: x(i), y: y(s.value), flag: s.flag })).filter((m) => m.flag),
    last: { x: x(it.series.length - 1), y: y(it.series[it.series.length - 1].value) },
  }
}

// grafik haqiqiy kenglikda chiziladi (balandligi doimiy) — yozuvlar cho'zilmaydi
const H = 230, PX = 52, PY = 14, PB = 28   // PB — pastda sana yozuvlari uchun joy
const chartBox = ref(null)
const W = ref(760)
let ro = null
function observe() {
  ro?.disconnect()
  if (!chartBox.value || typeof ResizeObserver === 'undefined') return
  ro = new ResizeObserver(([e]) => { W.value = Math.max(320, Math.round(e.contentRect.width)) })
  ro.observe(chartBox.value)
}
onBeforeUnmount(() => ro?.disconnect())
watch(selKey, async () => { await nextTick(); observe() })
const chart = computed(() => {
  const it = selected.value
  if (!it) return null
  const w = W.value
  const y = scaleFor(it, H, PY, PB), x = xFor(it, w, PX)
  const vals = it.series.map((s) => s.value)
  const lo = Math.min(...vals, ...(it.band ? [it.band[0]] : [])), hi = Math.max(...vals, ...(it.band ? [it.band[1]] : []))
  const ticks = [lo, (lo + hi) / 2, hi].map((v) => ({ v, y: y(v) }))
  const step = Math.max(1, Math.ceil(it.series.length / Math.max(2, Math.floor(w / 90))))
  return {
    path: it.series.map((s, i) => `${i ? 'L' : 'M'}${x(i).toFixed(1)},${y(s.value).toFixed(1)}`).join(' '),
    band: it.band ? { y1: y(it.band[1]), y2: y(it.band[0]) } : null,
    median: y(it.median),
    ticks,
    labels: it.series.map((s, i) => ({ i, x: x(i), text: dayLabel(s.date).slice(0, 5) }))
      .filter((l) => l.i % step === 0 || l.i === it.series.length - 1)
      .filter((l, j, a) => j === 0 || (l.text !== a[j - 1].text && l.x - a[j - 1].x >= 40)),
    pts: it.series.map((s, i) => ({ ...s, i, x: x(i), y: y(s.value) })),
  }
})
const hoverPt = computed(() => (chart.value && hover.value !== null ? chart.value.pts[hover.value] : null))
// rangdan tashqari shakl ham: «e'tibor» — ichi bo'sh doira, «keskin» — katta to'la doira
const FLAG_FILL = { strong: 'fill-crit', warn: 'fill-surface stroke-warn' }

function pick(it) {
  selKey.value = selKey.value === keyOf(it) ? null : keyOf(it)
  hover.value = null
}

onMounted(loadOptions)
</script>

<template>
  <div>
    <PageHeader :title="t('svodka.ra.title')" :subtitle="t('svodka.ra.subtitle')" />

    <p v-if="optsError" class="panel p-4 text-[14px] text-crit" data-opts-error>{{ optsError }}</p>
    <EmptyState v-else-if="opts && !opts.departments.length" :icon="Sparkles" :title="t('svodka.ra.noDepts')" />

    <template v-else-if="opts">
      <!-- Shartlar -->
      <section class="panel mb-4 p-4" data-filters>
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-6">
          <div class="lg:col-span-2">
            <label class="field-label">{{ t('svodka.ra.dept') }}</label>
            <select v-model.number="f.department_id" class="field-input" data-f="dept">
              <option v-for="d in opts.departments" :key="d.id" :value="d.id">{{ d.name }}<template v-if="d.code"> ({{ d.code }})</template></option>
            </select>
          </div>
          <div>
            <label class="field-label">{{ t('svodka.ra.section') }}</label>
            <select v-model="f.section_id" class="field-input" data-f="section">
              <option value="">{{ t('svodka.ra.allSections') }}</option>
              <option v-for="s in sections" :key="s.id" :value="s.id">{{ s.title }}</option>
            </select>
          </div>
          <div>
            <label class="field-label">{{ t('svodka.ra.point') }}</label>
            <select v-model="f.point_id" class="field-input" data-f="point">
              <option value="">{{ t('svodka.ra.allPoints') }}</option>
              <option v-for="p in points" :key="p.id" :value="p.id">{{ p.name }}</option>
            </select>
          </div>
          <div>
            <label class="field-label">{{ t('svodka.ra.symbol') }}</label>
            <select v-model="f.symbol" class="field-input" data-f="symbol">
              <option value="">{{ t('svodka.ra.allSymbols') }}</option>
              <option v-for="s in opts.symbols" :key="s" :value="s">{{ s }}</option>
            </select>
          </div>
          <div>
            <label class="field-label">{{ t('svodka.ra.shift') }}</label>
            <select v-model="f.shift" class="field-input" data-f="shift">
              <option value="">{{ t('svodka.ra.allShifts') }}</option>
              <option value="1">{{ t('svodka.ra.shift1') }}</option>
              <option value="2">{{ t('svodka.ra.shift2') }}</option>
            </select>
          </div>
        </div>
        <div class="mt-3 flex flex-wrap items-end gap-3">
          <div>
            <label class="field-label">{{ t('svodka.ra.from') }}</label>
            <input v-model="f.from" type="date" class="field-input tnum h-9" data-f="from">
          </div>
          <div>
            <label class="field-label">{{ t('svodka.ra.to') }}</label>
            <input v-model="f.to" type="date" class="field-input tnum h-9" data-f="to">
          </div>
          <div class="flex gap-1">
            <button v-for="d in [7, 30, 90]" :key="d" type="button" class="btn-ghost btn-sm h-9" :data-preset="d" @click="preset(d)">{{ t(`svodka.ra.presets.d${d}`) }}</button>
          </div>
          <div class="ml-auto flex items-center gap-2">
            <button type="button" class="btn-ghost btn-sm h-9" :aria-expanded="showMethod" data-method-toggle @click="showMethod = !showMethod"><Info :size="15" /></button>
            <button type="button" class="btn-primary h-9" :disabled="loading" data-run @click="run">
              <Loader2 v-if="loading" :size="16" class="animate-spin" /><Sparkles v-else :size="16" />
              {{ loading ? t('svodka.ra.running') : t('svodka.ra.run') }}
            </button>
          </div>
        </div>
        <p v-if="showMethod" class="mt-3 rounded-md bg-surface-sunken px-3 py-2 text-[12.5px] leading-relaxed text-ink-muted" data-method>{{ t('svodka.ra.method') }}</p>
      </section>

      <p v-if="error" class="panel mb-4 p-4 text-[14px] text-crit" data-error>{{ error }}</p>

      <EmptyState v-if="res && !items.length" :icon="Sparkles" :title="t('svodka.ra.noData')" :hint="t('svodka.ra.noDataHint')" data-empty />

      <template v-else-if="res">
        <!-- Xulosa -->
        <section class="panel mb-4 border-l-[3px] p-4"
                 :class="res.counts.deviation ? 'border-l-crit' : res.counts.attention ? 'border-l-warn' : 'border-l-ok'" data-summary>
          <p class="text-[11.5px] text-ink-faint">{{ t('svodka.ra.summary') }}</p>
          <p class="mt-0.5 text-[14px] text-ink">
            {{ t('svodka.ra.summaryLine', { items: items.length, samples: res.samples, from: dayLabel(res.from), to: dayLabel(res.to) }) }}
          </p>
          <div class="mt-2 flex flex-wrap gap-2">
            <span v-for="v in ['deviation', 'attention', 'normal', 'few']" :key="v" v-show="res.counts[v]"
                  class="rounded-full px-2.5 py-0.5 text-[12.5px] font-medium" :class="VERDICT_CLS[v]" :data-count="v">
              {{ t(`svodka.ra.verdict.${v}`) }}: {{ res.counts[v] }}
            </span>
          </div>
          <ul v-if="flagged.length" class="mt-3 space-y-1 text-[13.5px]" data-flagged>
            <li v-for="it in flagged.slice(0, 6)" :key="keyOf(it)" class="flex gap-2">
              <span class="mt-1.5 h-2 w-2 shrink-0 rounded-full" :class="it.verdict === 'deviation' ? 'bg-crit' : 'bg-warn'" />
              <button type="button" class="text-left hover:underline" @click="pick(it)">
                <b class="font-medium text-ink">{{ pointName(it) }} — {{ symbolName(it) }}:</b>{{ ' ' }}<span class="text-ink-muted">{{ it.reasons.map(reasonText).join('; ') }}</span>
              </button>
            </li>
          </ul>
          <p v-else class="mt-2 text-[13.5px] text-ink-muted">{{ t('svodka.ra.allNormal') }}</p>
          <p v-if="res.unmapped" class="mt-2 text-[12.5px] text-ink-faint">{{ t('svodka.ra.unmapped', { n: res.unmapped }) }}</p>
        </section>

        <!-- Tanlangan: batafsil -->
        <section v-if="selected && chart" class="panel mb-4 p-4" data-detail>
          <header class="mb-2 flex items-start justify-between gap-3">
            <div>
              <h3 class="text-[15px] font-semibold text-ink">{{ pointName(selected) }} — {{ symbolName(selected) }}</h3>
              <p class="text-[12px] text-ink-faint"><template v-if="selected.section">{{ selected.section }} · </template>{{ t('svodka.ra.cols.n') }}: {{ selected.periods }}</p>
            </div>
            <div class="flex items-center gap-2">
              <span class="rounded-full px-2.5 py-0.5 text-[12.5px] font-medium" :class="VERDICT_CLS[selected.verdict]">{{ t(`svodka.ra.verdict.${selected.verdict}`) }}</span>
              <button type="button" class="btn-ghost btn-icon" :aria-label="t('svodka.ra.close')" data-detail-close @click="selKey = null"><X :size="16" /></button>
            </div>
          </header>

          <div ref="chartBox" class="relative" @mouseleave="hover = null">
            <svg :viewBox="`0 0 ${W} ${H}`" :width="W" :height="H" class="block max-w-full" role="img" :aria-label="`${pointName(selected)} — ${symbolName(selected)}`" data-chart>
              <rect v-if="chart.band" :x="PX" :width="W - 2 * PX" :y="chart.band.y1" :height="Math.max(1, chart.band.y2 - chart.band.y1)" class="fill-aqua-500/10 dark:fill-aqua-300/10" data-band />
              <line :x1="PX" :x2="W - PX" :y1="chart.median" :y2="chart.median" class="stroke-aqua-500/60 dark:stroke-aqua-300/60" stroke-dasharray="4 4" stroke-width="1" />
              <g class="text-ink-faint">
                <template v-for="tk in chart.ticks" :key="tk.v">
                  <line :x1="PX" :x2="W - PX" :y1="tk.y" :y2="tk.y" class="stroke-line" stroke-width="1" />
                  <text :x="PX - 6" :y="tk.y + 4" text-anchor="end" class="fill-current text-[11px] tnum">{{ num(tk.v) }}</text>
                </template>
                <text v-for="l in chart.labels" :key="l.i" :x="l.x" :y="H - 8" text-anchor="middle" class="fill-current text-[11px] tnum">{{ l.text }}</text>
              </g>
              <path :d="chart.path" fill="none" class="stroke-aqua-500 dark:stroke-aqua-300" stroke-width="2" stroke-linejoin="round" />
              <g v-for="p in chart.pts" :key="p.i">
                <circle :cx="p.x" :cy="p.y" :r="p.flag === 'strong' ? 6 : p.flag ? 4.5 : 3.5"
                        :class="p.flag === 'warn' ? 'fill-surface stroke-warn' : [FLAG_FILL[p.flag] || 'fill-aqua-500 dark:fill-aqua-300', 'stroke-surface']"
                        :stroke-width="p.flag === 'warn' ? 2.5 : 2" :data-pt="p.flag || 'normal'" />
                <rect :x="p.x - 10" :y="0" width="20" :height="H" fill="transparent" @mouseenter="hover = p.i" />
              </g>
            </svg>
            <div v-if="hoverPt" class="pointer-events-none absolute top-1 z-10 whitespace-nowrap rounded-md border border-line bg-surface px-2.5 py-1.5 text-[12px] shadow-sm"
                 :class="hoverPt.x > W * 0.7 ? '-translate-x-full' : hoverPt.x < W * 0.3 ? '' : '-translate-x-1/2'"
                 :style="{ left: `${hoverPt.x}px` }" data-tooltip>
              <b class="tnum text-ink">{{ num(hoverPt.value) }}</b>
              <span class="text-ink-muted"> · {{ dayLabel(hoverPt.date) }} · {{ shiftName(hoverPt.shift) }}</span>
              <span v-if="hoverPt.codes.length" class="text-ink-faint"> · {{ hoverPt.codes.join(', ') }}</span>
              <span v-if="hoverPt.flag" :class="hoverPt.flag === 'strong' ? 'text-crit' : 'text-warn'"> · z = {{ formatNumber(hoverPt.z, 1) }}</span>
            </div>
          </div>
          <div class="mt-1 flex flex-wrap gap-x-4 gap-y-1 text-[12px] text-ink-muted" data-legend>
            <span class="inline-flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-full bg-aqua-500 dark:bg-aqua-300" />{{ t('svodka.ra.legend.normal') }}</span>
            <span class="inline-flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-full border-2 border-warn" />{{ t('svodka.ra.legend.warn') }}</span>
            <span class="inline-flex items-center gap-1.5"><span class="h-3 w-3 rounded-full bg-crit" />{{ t('svodka.ra.legend.strong') }}</span>
            <span v-if="selected.band" class="inline-flex items-center gap-1.5"><span class="h-2.5 w-4 rounded-sm bg-aqua-500/15" />{{ t('svodka.ra.legend.band') }}</span>
          </div>

          <dl class="mt-4 grid grid-cols-2 gap-3 border-t border-line pt-3 sm:grid-cols-4 lg:grid-cols-8" data-stats>
            <div><dt class="text-[11px] text-ink-faint">{{ t('svodka.ra.stats.median') }}</dt><dd class="tnum text-[13.5px] font-medium text-ink">{{ num(selected.median) }}</dd></div>
            <div><dt class="text-[11px] text-ink-faint">{{ t('svodka.ra.stats.mean') }}</dt><dd class="tnum text-[13.5px] text-ink">{{ num(selected.mean) }}</dd></div>
            <div><dt class="text-[11px] text-ink-faint">{{ t('svodka.ra.stats.sd') }}</dt><dd class="tnum text-[13.5px] text-ink-muted">{{ num(selected.sd) }}</dd></div>
            <div><dt class="text-[11px] text-ink-faint">{{ t('svodka.ra.stats.min') }} – {{ t('svodka.ra.stats.max') }}</dt><dd class="tnum text-[13.5px] text-ink-muted">{{ num(selected.min) }} – {{ num(selected.max) }}</dd></div>
            <div><dt class="text-[11px] text-ink-faint">{{ t('svodka.ra.stats.change') }}</dt><dd class="tnum text-[13.5px] text-ink">{{ pct(selected.change_pct) }} <span class="text-ink-faint">(R² {{ formatNumber(selected.r2, 2) }})</span></dd></div>
            <div><dt class="text-[11px] text-ink-faint">{{ t('svodka.ra.stats.shift1') }}</dt><dd class="tnum text-[13.5px] text-ink-muted">{{ num(selected.shift_avg[1]) }}</dd></div>
            <div><dt class="text-[11px] text-ink-faint">{{ t('svodka.ra.stats.shift2') }}</dt><dd class="tnum text-[13.5px] text-ink-muted">{{ num(selected.shift_avg[2]) }}</dd></div>
            <div v-if="selected.band"><dt class="text-[11px] text-ink-faint">{{ t('svodka.ra.stats.band') }}</dt><dd class="tnum text-[13.5px] text-ink-muted">{{ num(selected.band[0]) }} – {{ num(selected.band[1]) }}</dd></div>
          </dl>
          <div class="mt-3">
            <p class="text-[11.5px] text-ink-faint">{{ t('svodka.ra.reasonsTitle') }}</p>
            <ul v-if="selected.reasons.length" class="mt-1 list-disc pl-5 text-[13.5px] text-ink" data-reasons>
              <li v-for="(r, i) in selected.reasons" :key="i">{{ reasonText(r) }}</li>
            </ul>
            <p v-else class="mt-1 text-[13.5px] text-ink-muted">{{ selected.verdict === 'few' ? t('svodka.ra.verdictHint.few') : t('svodka.ra.none') }}</p>
          </div>
        </section>

        <!-- Jadval -->
        <section class="panel overflow-hidden" data-table>
          <header class="flex items-baseline justify-between gap-3 border-b border-line px-4 py-2.5">
            <h3 class="text-[14px] font-semibold text-ink">{{ t('svodka.ra.table') }}</h3>
            <span class="text-[12px] text-ink-faint">{{ t('svodka.ra.tableHint') }}</span>
          </header>
          <div class="overflow-x-auto">
            <table class="w-full min-w-[860px] text-[13px]">
              <thead class="bg-surface-sunken text-[11.5px] text-ink-muted">
                <tr>
                  <th class="px-3 py-2 text-left font-medium">{{ t('svodka.ra.cols.point') }}</th>
                  <th class="px-3 py-2 text-left font-medium">{{ t('svodka.ra.cols.symbol') }}</th>
                  <th class="px-3 py-2 text-right font-medium">{{ t('svodka.ra.cols.n') }}</th>
                  <th class="px-3 py-2 text-right font-medium">{{ t('svodka.ra.cols.last') }}</th>
                  <th class="px-3 py-2 text-right font-medium">{{ t('svodka.ra.cols.median') }}</th>
                  <th class="px-3 py-2 text-right font-medium">{{ t('svodka.ra.cols.range') }}</th>
                  <th class="px-3 py-2 text-left font-medium">{{ t('svodka.ra.cols.trend') }}</th>
                  <th class="px-3 py-2 text-left font-medium">{{ t('svodka.ra.cols.chart') }}</th>
                  <th class="px-3 py-2 text-left font-medium">{{ t('svodka.ra.cols.verdict') }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="it in items" :key="keyOf(it)" class="cursor-pointer border-t border-line hover:bg-surface-sunken/60"
                    :class="selKey === keyOf(it) && 'bg-assay-500/5'" :data-row="it.verdict" @click="pick(it)">
                  <td class="px-3 py-2">
                    <span class="text-ink" :class="!it.point && 'italic text-ink-muted'">{{ pointName(it) }}</span>
                    <span v-if="it.section" class="block text-[11.5px] text-ink-faint">{{ it.section }}</span>
                  </td>
                  <td class="px-3 py-2 text-ink">{{ symbolName(it) }}</td>
                  <td class="px-3 py-2 text-right tnum text-ink-muted">{{ it.periods }}</td>
                  <td class="px-3 py-2 text-right tnum font-medium" :class="it.series[it.series.length - 1].flag === 'strong' ? 'text-crit' : it.series[it.series.length - 1].flag === 'warn' ? 'text-warn' : 'text-ink'">{{ num(it.last.value) }}</td>
                  <td class="px-3 py-2 text-right tnum text-ink-muted">{{ num(it.median) }}</td>
                  <td class="px-3 py-2 text-right tnum text-ink-muted">{{ num(it.min) }} – {{ num(it.max) }}</td>
                  <td class="px-3 py-2">
                    <span class="inline-flex items-center gap-1 tnum" :class="it.trend === 'increasing' || it.trend === 'decreasing' ? 'text-ink' : 'text-ink-faint'">
                      <component :is="TREND_ICON[it.trend]" :size="14" />
                      <template v-if="it.trend !== 'unknown'">{{ pct(it.change_pct) }}</template><template v-else>—</template>
                    </span>
                  </td>
                  <td class="px-3 py-1.5">
                    <svg viewBox="0 0 120 28" class="h-7 w-[120px]" aria-hidden="true">
                      <path :d="spark(it).path" fill="none" class="stroke-aqua-500 dark:stroke-aqua-300" stroke-width="1.5" />
                      <circle v-for="(m, j) in spark(it).marks" :key="j" :cx="m.x" :cy="m.y" :r="m.flag === 'strong' ? 3 : 2.5"
                              :class="FLAG_FILL[m.flag]" :stroke-width="m.flag === 'warn' ? 1.5 : 0" />
                    </svg>
                  </td>
                  <td class="px-3 py-2">
                    <span class="whitespace-nowrap rounded-full px-2 py-0.5 text-[12px] font-medium" :class="VERDICT_CLS[it.verdict]"
                          :title="it.verdict === 'few' ? t('svodka.ra.verdictHint.few') : it.reasons.map(reasonText).join('; ')">{{ t(`svodka.ra.verdict.${it.verdict}`) }}</span>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </section>
      </template>
    </template>
  </div>
</template>
