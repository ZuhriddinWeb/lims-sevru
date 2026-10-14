<script setup>
/**
 * Kun × smena jadvali (tahlillar soni). Rang — bitta rangning och → to'q pog'onalari, raqam har katakda yozilgan.
 */
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { HEAT_STEPS, dm, heatLevel, num, parseTs, weekday, bucketLabel } from '../helpers'

const props = defineProps({
  heat: { type: Object, required: true },   // { days: [{date, future}], rows: [{id, name, cells: {date: n}}] }
  title: { type: String, default: '' },     // [P17] boshqa sahifalarda ham ishlatish uchun
  hint: { type: String, default: '' },
})
const { t, locale } = useI18n()

const max = computed(() => {
  let m = 0
  for (const r of props.heat.rows ?? []) for (const v of Object.values(r.cells ?? {})) if (v > m) m = v
  return m
})
const cell = (r, d) => (r.cells ?? {})[d.date] ?? 0
const isWeekend = (d) => { const x = parseTs(d.date); return x && (x.getDay() === 0 || x.getDay() === 6) }
const totals = computed(() => (props.heat.days ?? []).map((d) => (props.heat.rows ?? []).reduce((a, r) => a + cell(r, d), 0)))
</script>

<template>
  <section class="panel p-4" data-heat>
    <header class="flex flex-wrap items-start justify-between gap-2 mb-3">
      <div>
        <h2 class="text-[14px] font-semibold text-ink">{{ title || t('insights.shifts.heat') }}</h2>
        <p class="text-[12.5px] text-ink-muted max-w-[640px]">{{ hint || t('insights.shifts.heatHint') }}</p>
      </div>
      <div class="flex items-center gap-1 text-[11.5px] text-ink-muted" data-legend>
        <span>{{ t('insights.shifts.less') }}</span>
        <span v-for="(c, i) in HEAT_STEPS.slice(1)" :key="i" class="h-3 w-4 rounded-sm" :class="c" />
        <span>{{ t('insights.shifts.more') }}</span>
      </div>
    </header>

    <div class="overflow-x-auto">
      <table class="border-separate" style="border-spacing: 2px">
        <thead>
          <tr>
            <th class="sticky left-0 bg-surface z-10" />
            <th v-for="d in heat.days" :key="d.date" class="px-0 pb-1 font-normal text-center min-w-[30px]">
              <span class="block text-[10.5px] text-ink-faint leading-none" :class="isWeekend(d) ? 'font-semibold text-ink-muted' : ''">{{ weekday(d.date, locale) }}</span>
              <span class="block text-[11px] text-ink-muted tnum leading-tight">{{ dm(parseTs(d.date)).slice(0, 2) }}</span>
            </th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="r in heat.rows" :key="r.id" :data-heat-row="r.id">
            <th class="sticky left-0 bg-surface z-10 pr-2 text-left text-[12.5px] font-medium text-ink whitespace-nowrap">{{ r.name }}</th>
            <td
              v-for="d in heat.days" :key="d.date"
              class="h-8 min-w-[30px] rounded text-center text-[11.5px] tnum"
              :class="d.future ? 'border border-dashed border-line' : HEAT_STEPS[heatLevel(cell(r, d), max)]"
              :title="d.future ? '' : `${bucketLabel(d.date, 'day', locale, true)} · ${r.name}: ${num(cell(r, d), locale)}`"
              :data-cell="d.date"
            >{{ d.future || !cell(r, d) ? '' : num(cell(r, d), locale) }}</td>
          </tr>
          <tr>
            <th class="sticky left-0 bg-surface z-10" />
            <td v-for="(d, i) in heat.days" :key="d.date" class="text-center text-[10.5px] text-ink-faint tnum pt-0.5">{{ d.future ? '' : num(totals[i], locale) }}</td>
          </tr>
        </tbody>
      </table>
    </div>
  </section>
</template>
