<script setup>
/**
 * Proba harakati bosqichlari: qabul → uzatildi → tahlil → tekshiruv → natija tayyor.
 *   <StageStepper :steps="steps" :current="current" />            — to'liq (kartochka)
 *   <StageStepper :steps="steps" :current="current" compact />    — ixcham (ro'yxat)
 */
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { Check } from 'lucide-vue-next'
import { formatDateTime } from '@/lib/format'
import '../i18n'

const props = defineProps({
  steps: { type: Array, default: () => [] },
  current: { type: String, default: 'registered' },
  compact: Boolean,
})
const { t } = useI18n()

const currentIndex = computed(() => props.steps.findIndex((s) => s.key === props.current))
const label = (key) => t(`intake.portal.stage.${key}`)
</script>

<template>
  <!-- Ixcham: joriy bosqich nomi + nuqtalar -->
  <div v-if="compact" class="min-w-0" data-stage-compact>
    <p class="text-[13px] font-medium truncate" :class="current === 'done' ? 'text-ok-dark dark:text-ok-light' : 'text-ink'">
      {{ label(current) }}
    </p>
    <div class="mt-1 flex items-center gap-1">
      <span
        v-for="(s, i) in steps" :key="s.key"
        class="h-1.5 rounded-full transition-all"
        :class="[s.done ? (current === 'done' ? 'bg-ok' : 'bg-assay-500') : 'bg-line', i === currentIndex ? 'w-5' : 'w-2.5']"
        :title="label(s.key)"
      />
    </div>
  </div>

  <!-- To'liq -->
  <ol v-else class="flex items-start gap-1" data-stage-full>
    <li v-for="(s, i) in steps" :key="s.key" class="flex-1 min-w-0">
      <div class="flex items-center gap-2">
        <span
          class="h-7 w-7 rounded-full grid place-items-center shrink-0 text-[12px] font-semibold transition-colors"
          :class="s.done
            ? (i === currentIndex && current !== 'done' ? 'bg-assay-500 text-white ring-4 ring-assay-500/20' : 'bg-assay-500 text-white')
            : 'bg-surface-sunken text-ink-faint border border-line'"
        >
          <Check v-if="s.done && !(i === currentIndex && current !== 'done')" :size="14" :stroke-width="3" />
          <span v-else>{{ i + 1 }}</span>
        </span>
        <span
          v-if="i < steps.length - 1"
          class="h-0.5 flex-1 rounded transition-colors"
          :class="steps[i + 1].done ? 'bg-assay-400' : 'bg-line'"
        />
      </div>
      <p class="mt-2 text-[12.5px] font-medium leading-tight" :class="s.done ? 'text-ink' : 'text-ink-faint'">{{ label(s.key) }}</p>
      <p v-if="s.at" class="text-[11.5px] text-ink-faint tnum">{{ formatDateTime(s.at) }}</p>
    </li>
  </ol>
</template>
