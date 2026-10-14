<script setup>
/**
 * Ish oqimi — hozir: 4 bosqich (qabul → uzatilgan → tahlilda → natija/tekshiruv),
 * har birida soni va qabul qilinganidan beri o'tgan vaqt bo'yicha taqsimot (bitta rangning och → to'q pog'onalari).
 */
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { AlertTriangle, ChevronRight } from 'lucide-vue-next'
import { AGING, AGING_CLASS, num } from '../helpers'

const props = defineProps({
  flow: { type: Object, default: null },
  overdueDays: { type: Number, default: 7 },
})
const { t, locale } = useI18n()

const stages = computed(() => (props.flow?.stages ?? []).map((s) => {
  const total = s.count || 0
  return {
    ...s,
    parts: AGING.map((k) => ({ key: k, value: s.aging?.[k] ?? 0, pct: total ? ((s.aging?.[k] ?? 0) / total) * 100 : 0 })).filter((p) => p.value > 0),
  }
}))
const max = computed(() => Math.max(1, ...stages.value.map((s) => s.count)))
</script>

<template>
  <section class="panel p-4" data-flow>
    <header class="flex flex-wrap items-start justify-between gap-2 mb-3">
      <div>
        <h2 class="text-[14px] font-semibold text-ink">{{ t('insights.flow.title') }}</h2>
        <p class="text-[12.5px] text-ink-muted">{{ t('insights.flow.subtitle') }}</p>
      </div>
      <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-[12.5px] text-ink-muted">
        <span class="tnum">{{ t('insights.flow.open', { n: num(flow?.open ?? 0, locale) }) }}</span>
        <span class="tnum">{{ t('insights.flow.completed', { n: num(flow?.completed ?? 0, locale) }) }}</span>
      </div>
    </header>

    <div class="grid gap-2 sm:grid-cols-2 xl:grid-cols-4">
      <div v-for="(s, i) in stages" :key="s.key" class="relative rounded-md border border-line bg-surface-sunken/40 p-3" :data-stage="s.key">
        <ChevronRight v-if="i < stages.length - 1" :size="16" class="hidden xl:block absolute -right-[13px] top-1/2 -translate-y-1/2 text-ink-faint z-10 bg-surface rounded-full" />
        <div class="flex items-baseline justify-between gap-2">
          <p class="text-[13px] font-medium text-ink">{{ t(`insights.flow.stages.${s.key}`) }}</p>
          <p class="text-[22px] font-semibold tnum text-ink leading-none" data-count>{{ num(s.count, locale) }}</p>
        </div>
        <p class="text-[11.5px] text-ink-faint mt-0.5 mb-2.5">{{ t(`insights.flow.stageHints.${s.key}`) }}</p>
        <!-- yosh bo'yicha taqsimot: uzunligi — shu bosqichdagi soniga mutanosib -->
        <div class="h-2 rounded-full bg-surface-sunken overflow-hidden">
          <div class="h-full flex gap-[2px]" :style="{ width: `${(s.count / max) * 100}%` }">
            <span
              v-for="p in s.parts" :key="p.key"
              class="h-full first:rounded-l-full last:rounded-r-full"
              :class="AGING_CLASS[p.key]" :style="{ flexGrow: p.value, flexBasis: 0, minWidth: '3px' }"
              :title="`${t(`insights.flow.aging.${p.key}`)}: ${p.value}`"
              :data-age="p.key"
            />
          </div>
        </div>
        <p v-if="s.aging?.over" class="mt-2 inline-flex items-center gap-1 text-[12px] font-medium text-crit" data-over>
          <AlertTriangle :size="13" /> {{ t('insights.flow.aging.over') }}: {{ num(s.aging.over, locale) }}
        </p>
      </div>
    </div>

    <!-- Legenda -->
    <div class="mt-3 flex flex-wrap items-center gap-x-4 gap-y-1 text-[11.5px] text-ink-muted" data-legend>
      <span>{{ t('insights.flow.aging.label') }}:</span>
      <span v-for="k in AGING" :key="k" class="inline-flex items-center gap-1.5">
        <span class="h-2.5 w-2.5 rounded-sm" :class="AGING_CLASS[k]" />{{ t(`insights.flow.aging.${k}`) }}
      </span>
    </div>
  </section>
</template>
