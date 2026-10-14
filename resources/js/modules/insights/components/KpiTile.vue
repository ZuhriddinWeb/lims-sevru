<script setup>
/**
 * Ko'rsatkich plitkasi: katta raqam, birlik, oldingi davrga nisbatan o'zgarish (strelka + so'z), izoh.
 * O'zgarish rangi «yaxshi/yomon» ma'nosida (vaqt uchun kamayish — yaxshi), faqat rang emas — strelka va matn ham bor.
 */
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { ArrowDownRight, ArrowRight, ArrowUpRight } from 'lucide-vue-next'
import { deltaTone, num } from '../helpers'

const props = defineProps({
  label: { type: String, required: true },
  value: { type: [Number, String], default: null },
  unit: { type: String, default: '' },
  change: { type: Number, default: null },
  prev: { type: [Number, String], default: null },
  lowerIsBetter: { type: Boolean, default: false },
  sub: { type: String, default: '' },
  hint: { type: String, default: '' },
  icon: { type: [Object, Function], default: null },
  tone: { type: String, default: 'neutral' },  // neutral | crit
  loading: { type: Boolean, default: false },
  compare: { type: Boolean, default: true },
  to: { type: Object, default: null },
})
defineEmits(['open'])
const { t, locale } = useI18n()

const dTone = computed(() => deltaTone(props.change, props.lowerIsBetter))
const shown = computed(() => (typeof props.value === 'number' ? num(props.value, locale.value) : (props.value ?? '—')))
const ICON_TONE = {
  neutral: 'text-assay-600 dark:text-assay-300 bg-assay-50 dark:bg-assay-900/35',
  crit: 'text-crit bg-crit-light dark:bg-crit-dark/25',
  ok: 'text-ok bg-ok-light dark:bg-ok-dark/25',
}
</script>

<template>
  <div
    class="panel p-4 flex flex-col min-w-0"
    :class="to ? 'cursor-pointer transition-colors hover:border-ink-faint' : ''"
    :title="hint || undefined"
    data-kpi
    @click="to && $emit('open', to)"
  >
    <div class="flex items-start justify-between gap-2 mb-2.5">
      <span v-if="icon" class="h-8 w-8 rounded-md grid place-items-center shrink-0" :class="ICON_TONE[props.tone] ?? ICON_TONE.neutral">
        <component :is="icon" :size="16" />
      </span>
      <span
        v-if="compare && change != null && !loading"
        class="inline-flex items-center gap-0.5 text-[12px] font-semibold tnum"
        :class="dTone === 'good' ? 'text-ok' : dTone === 'bad' ? 'text-crit' : 'text-ink-faint'"
        :title="dTone === 'good' ? t('insights.kpi.better') : dTone === 'bad' ? t('insights.kpi.worse') : ''"
        data-delta
      >
        <component :is="change > 0 ? ArrowUpRight : change < 0 ? ArrowDownRight : ArrowRight" :size="13" />
        {{ num(Math.abs(change), locale) }}%
      </span>
      <span v-else-if="compare && !loading" class="text-[11.5px] text-ink-faint" data-delta-none>{{ t('insights.kpi.noCompare') }}</span>
    </div>

    <p class="text-[26px] font-semibold text-ink tnum leading-none" data-value>
      <span v-if="loading" class="inline-block h-7 w-14 rounded bg-surface-sunken animate-pulse" />
      <template v-else>{{ shown }}<span v-if="unit && value != null" class="text-[14px] font-medium text-ink-muted ml-1">{{ unit }}</span></template>
    </p>
    <p class="mt-2 text-[12.5px] text-ink-muted leading-tight">{{ label }}</p>
    <p v-if="sub && !loading" class="mt-1 text-[11.5px] text-ink-faint leading-tight" data-sub>{{ sub }}</p>
  </div>
</template>
