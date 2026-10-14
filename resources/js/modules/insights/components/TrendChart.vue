<script setup>
/**
 * Dinamika grafigi (SVG, kutubxonasiz).
 *   - type="line": 1–2 qator, chiziq 2px, bo'sh (kelajak) nuqtalarda uziladi
 *   - type="bar":  bitta qator, ustunlar asosga tayangan, yuqori burchaklari yumaloq
 *   - sichqoncha bilan: vertikal chiziq + qiymatlar oynasi; 2 qator bo'lsa — legenda
 *   - view="table": xuddi shu ma'lumot jadvalda
 * Ranglar: 1-qator — oltin (#B8862A), 2-qator — ko'k (#2a78d6, tungi rejimda #3987e5); rang sinovdan o'tgan.
 */
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { bucketLabel, niceTicks, num } from '../helpers'

const props = defineProps({
  points: { type: Array, default: () => [] },     // [{ key, <series.key>: number|null }]
  series: { type: Array, default: () => [] },     // [{ key, label, slot: 1|2 }]
  bucket: { type: String, default: 'day' },
  type: { type: String, default: 'line' },
  view: { type: String, default: 'chart' },
  height: { type: Number, default: 230 },
})
const { t, locale } = useI18n()

const box = ref(null)
const width = ref(640)
let ro = null
onMounted(() => {
  const measure = () => { if (box.value) width.value = Math.max(280, Math.round(box.value.clientWidth)) }
  measure()
  if (typeof ResizeObserver !== 'undefined') {
    ro = new ResizeObserver(measure)
    ro.observe(box.value)
  }
})
onBeforeUnmount(() => ro?.disconnect())

const M = { top: 10, right: 10, bottom: 24, left: 40 }
const innerW = computed(() => width.value - M.left - M.right)
const innerH = computed(() => props.height - M.top - M.bottom)
const n = computed(() => props.points.length)
const band = computed(() => (n.value ? innerW.value / n.value : innerW.value))
const x = (i) => M.left + band.value * (i + 0.5)

const maxVal = computed(() => {
  let m = 0
  for (const p of props.points) for (const s of props.series) if (p[s.key] != null && p[s.key] > m) m = p[s.key]
  return m
})
const ticks = computed(() => niceTicks(maxVal.value))
const yMax = computed(() => ticks.value[ticks.value.length - 1] || 1)
const y = (v) => M.top + innerH.value - (v / yMax.value) * innerH.value

const empty = computed(() => !props.points.some((p) => props.series.some((s) => p[s.key])))

/** Chiziq yo'li: null nuqtalarda uziladi */
function linePath(key) {
  let d = ''
  let pen = false
  props.points.forEach((p, i) => {
    const v = p[key]
    if (v === null || v === undefined) { pen = false; return }
    d += `${pen ? 'L' : 'M'}${x(i).toFixed(1)},${y(v).toFixed(1)}`
    pen = true
  })
  return d
}

/** Ustun: kengligi band − 2px oraliq, eng ko'pi 28px; tepasi 4px yumaloq, pasti tekis */
const barW = computed(() => Math.max(2, Math.min(28, band.value - 2)))
function barPath(i, v) {
  if (!v) return ''
  const w = barW.value
  const x0 = x(i) - w / 2
  const y0 = y(v)
  const base = y(0)
  const r = Math.min(4, w / 2, base - y0)
  return `M${x0},${base}V${y0 + r}Q${x0},${y0} ${x0 + r},${y0}H${x0 + w - r}Q${x0 + w},${y0} ${x0 + w},${y0 + r}V${base}Z`
}

/** O'q yozuvlari ustma-ust tushmasin: har k-chi */
const labelEvery = computed(() => {
  const need = props.bucket === 'hour' ? 40 : props.bucket === 'month' ? 44 : 42
  return Math.max(1, Math.ceil(need / band.value))
})
const xLabels = computed(() => props.points
  .map((p, i) => ({ i, text: bucketLabel(p.key, props.bucket, locale.value) }))
  .filter((l) => l.i % labelEvery.value === 0))

const showMarkers = computed(() => props.type === 'line' && n.value <= 16)

/* ---- sichqoncha ---- */
const hover = ref(-1)
const tip = computed(() => {
  if (hover.value < 0 || !props.points[hover.value]) return null
  const p = props.points[hover.value]
  const left = Math.min(Math.max(x(hover.value) + 12, 4), width.value - 190)
  const flip = x(hover.value) + 200 > width.value
  return {
    title: bucketLabel(p.key, props.bucket, locale.value, true),
    rows: props.series.map((s) => ({ ...s, value: p[s.key] })),
    left: flip ? x(hover.value) - 196 : left,
  }
})

const aria = computed(() => props.series.map((s) => {
  const total = props.points.reduce((a, p) => a + (p[s.key] || 0), 0)
  return `${s.label}: ${num(total, locale.value)}`
}).join('; '))
</script>

<template>
  <div class="ins-chart">
    <!-- Legenda — 2 va undan ortiq qator bo'lsa -->
    <div v-if="series.length > 1 && view === 'chart'" class="flex flex-wrap items-center gap-x-4 gap-y-1 mb-2 text-[12px] text-ink-muted" data-legend>
      <span v-for="s in series" :key="s.key" class="inline-flex items-center gap-1.5">
        <span class="ins-swatch" :class="`ins-s${s.slot}`" />{{ s.label }}
      </span>
    </div>

    <div v-show="view === 'chart'" ref="box" class="relative" @mouseleave="hover = -1">
      <svg :width="width" :height="height" :viewBox="`0 0 ${width} ${height}`" role="img" :aria-label="aria" class="block select-none" data-chart>
        <!-- to'r va Y o'qi -->
        <g>
          <template v-for="tv in ticks" :key="tv">
            <line :x1="M.left" :x2="width - M.right" :y1="y(tv)" :y2="y(tv)" class="ins-grid" :class="tv === 0 ? 'ins-base' : ''" />
            <text :x="M.left - 6" :y="y(tv) + 3.5" text-anchor="end" class="ins-axis">{{ num(tv, locale) }}</text>
          </template>
        </g>
        <!-- X o'qi -->
        <g>
          <text v-for="l in xLabels" :key="l.i" :x="x(l.i)" :y="height - 6" text-anchor="middle" class="ins-axis">{{ l.text }}</text>
        </g>

        <!-- vertikal chiziq -->
        <line v-if="hover >= 0" :x1="x(hover)" :x2="x(hover)" :y1="M.top" :y2="M.top + innerH" class="ins-cross" />

        <!-- ustunlar -->
        <g v-if="type === 'bar'">
          <path
            v-for="(p, i) in points" :key="p.key"
            :d="barPath(i, p[series[0]?.key])"
            :class="[`ins-fill-s${series[0]?.slot ?? 1}`, hover >= 0 && hover !== i ? 'opacity-60' : '']"
            data-bar
          />
        </g>

        <!-- chiziqlar -->
        <g v-else>
          <path v-for="s in series" :key="s.key" :d="linePath(s.key)" class="ins-line" :class="`ins-stroke-s${s.slot}`" :data-line="s.key" />
          <template v-for="s in series" :key="`m-${s.key}`">
            <template v-for="(p, i) in points" :key="`${s.key}-${p.key}`">
              <circle
                v-if="p[s.key] != null && (showMarkers || hover === i)"
                :cx="x(i)" :cy="y(p[s.key])" r="4"
                class="ins-marker" :class="`ins-fill-s${s.slot}`"
              />
            </template>
          </template>
        </g>

        <!-- sichqoncha nishonlari (belgidan kattaroq) -->
        <rect
          v-for="(p, i) in points" :key="`h-${p.key}`"
          :x="M.left + band * i" :y="M.top" :width="band" :height="innerH"
          fill="transparent" @mouseenter="hover = i" @focus="hover = i"
        />
      </svg>

      <p v-if="empty" class="absolute inset-0 grid place-items-center text-[13px] text-ink-faint pointer-events-none">{{ t('insights.noData') }}</p>

      <div
        v-if="tip"
        class="absolute top-1 z-10 min-w-[170px] rounded-md border border-line bg-surface-raised shadow-pop px-3 py-2 text-[12.5px] pointer-events-none"
        :style="{ left: `${tip.left}px` }" data-tip
      >
        <p class="font-medium text-ink mb-1">{{ tip.title }}</p>
        <p v-for="r in tip.rows" :key="r.key" class="flex items-center justify-between gap-4">
          <span class="inline-flex items-center gap-1.5 text-ink-muted"><span class="ins-swatch" :class="`ins-s${r.slot}`" />{{ r.label }}</span>
          <span class="tnum font-semibold text-ink">{{ r.value == null ? '—' : num(r.value, locale) }}</span>
        </p>
      </div>
    </div>

    <!-- Jadval ko'rinishi -->
    <div v-if="view === 'table'" class="max-h-[300px] overflow-auto rounded-md border border-line" data-table>
      <table class="w-full text-[13px]">
        <thead class="sticky top-0 bg-surface-sunken">
          <tr class="text-left text-[12px] text-ink-muted">
            <th class="px-3 py-1.5 font-medium">{{ t('insights.trend.when') }}</th>
            <th v-for="s in series" :key="s.key" class="px-3 py-1.5 font-medium text-right">{{ s.label }}</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-line">
          <tr v-for="p in points" :key="p.key">
            <td class="px-3 py-1 text-ink-muted">{{ bucketLabel(p.key, bucket, locale, true) }}</td>
            <td v-for="s in series" :key="s.key" class="px-3 py-1 text-right tnum text-ink">{{ p[s.key] == null ? '—' : num(p[s.key], locale) }}</td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>

<style>
.ins-chart { --ins-s1: #B8862A; --ins-s2: #2a78d6; }
.dark .ins-chart { --ins-s1: #B8862A; --ins-s2: #3987e5; }
.ins-chart .ins-grid { stroke: rgb(var(--line)); stroke-width: 1; shape-rendering: crispEdges; }
.ins-chart .ins-base { stroke: rgb(var(--ink-faint)); }
.ins-chart .ins-axis { fill: rgb(var(--ink-muted)); font-size: 11px; font-variant-numeric: tabular-nums; }
.ins-chart .ins-cross { stroke: rgb(var(--ink-faint)); stroke-width: 1; stroke-dasharray: 3 3; }
.ins-chart .ins-line { fill: none; stroke-width: 2; stroke-linejoin: round; stroke-linecap: round; }
.ins-chart .ins-stroke-s1 { stroke: var(--ins-s1); }
.ins-chart .ins-stroke-s2 { stroke: var(--ins-s2); }
.ins-chart .ins-fill-s1 { fill: var(--ins-s1); }
.ins-chart .ins-fill-s2 { fill: var(--ins-s2); }
.ins-chart .ins-marker { stroke: rgb(var(--surface)); stroke-width: 2; }
.ins-chart .ins-swatch { display: inline-block; width: 10px; height: 10px; border-radius: 2px; }
.ins-chart .ins-s1 { background: var(--ins-s1); }
.ins-chart .ins-s2 { background: var(--ins-s2); }
</style>
