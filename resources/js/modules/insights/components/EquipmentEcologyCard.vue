<script setup>
/**
 * Uskunalar tayyorligi va suv laboratoriyasi (ekologiya) — qisqa ko'rinish.
 */
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { Droplets, Microscope } from 'lucide-vue-next'
import { num } from '../helpers'

const props = defineProps({
  equipment: { type: Object, default: null },
  ecology: { type: Object, default: null },
})
defineEmits(['open'])
const { t, locale } = useI18n()

const readiness = computed(() => props.equipment?.readiness ?? null)
const readyTone = computed(() => (readiness.value == null ? 'text-ink-faint' : readiness.value >= 80 ? 'text-ok' : readiness.value >= 50 ? 'text-warn' : 'text-crit'))
const eqRows = computed(() => [
  ['working', props.equipment?.working, ''],
  ['blocked', props.equipment?.blocked, 'crit'],
  ['waiting', props.equipment?.waiting, 'warn'],
  ['overdue', props.equipment?.overdue, 'crit'],
  ['dueSoon', props.equipment?.due_soon, 'warn'],
])
const TONE = { crit: 'text-crit', warn: 'text-warn' }
const eco = computed(() => props.ecology?.available ? props.ecology : null)
</script>

<template>
  <section class="panel p-4 grid gap-5 md:grid-cols-2" data-equipment>
    <div>
      <header class="flex items-center justify-between gap-2 mb-3">
        <h2 class="text-[14px] font-semibold text-ink">{{ t('insights.equipment.title') }}</h2>
        <button type="button" class="btn-ghost btn-sm" @click="$emit('open', { name: 'equipment' })"><Microscope :size="14" /> {{ t('insights.showAll') }}</button>
      </header>
      <template v-if="equipment && !equipment.error">
        <div class="flex items-baseline gap-2 mb-2">
          <span class="text-[30px] font-semibold tnum leading-none" :class="readyTone" data-readiness>{{ readiness == null ? '—' : `${readiness}%` }}</span>
          <span class="text-[12.5px] text-ink-muted">{{ t('insights.equipment.readiness') }} · {{ num(equipment.working, locale) }} / {{ num(equipment.total, locale) }}</span>
        </div>
        <div class="h-1.5 rounded-full bg-surface-sunken overflow-hidden mb-3">
          <div class="h-full rounded-full bg-ok" :style="{ width: `${readiness ?? 0}%` }" />
        </div>
        <dl class="space-y-1.5">
          <div v-for="[k, v, tone] in eqRows" :key="k" class="flex items-center justify-between gap-2">
            <dt class="text-[13px] text-ink-muted">{{ t(`insights.equipment.${k}`) }}</dt>
            <dd class="text-[13.5px] font-semibold tnum" :class="v > 0 && tone ? TONE[tone] : 'text-ink'">{{ num(v ?? 0, locale) }}</dd>
          </div>
        </dl>
      </template>
      <p v-else-if="equipment?.error" class="text-[13px] text-crit">{{ t('insights.blockError') }}</p>
    </div>

    <div v-if="eco" data-ecology>
      <header class="flex items-center justify-between gap-2 mb-3">
        <h2 class="text-[14px] font-semibold text-ink">{{ t('insights.ecology.title') }}</h2>
        <button type="button" class="btn-ghost btn-sm" @click="$emit('open', { name: 'water-orders' })"><Droplets :size="14" /> {{ t('insights.showAll') }}</button>
      </header>
      <div class="grid grid-cols-2 gap-2 mb-3">
        <div class="rounded-md bg-surface-sunken/60 px-3 py-2">
          <p class="text-[20px] font-semibold tnum text-ink leading-none">{{ num(eco.measurements, locale) }}</p>
          <p class="text-[12px] text-ink-muted mt-1">{{ t('insights.ecology.measurements') }}</p>
        </div>
        <div class="rounded-md bg-surface-sunken/60 px-3 py-2">
          <p class="text-[20px] font-semibold tnum text-ink leading-none">{{ num(eco.orders, locale) }}</p>
          <p class="text-[12px] text-ink-muted mt-1">{{ t('insights.ecology.orders') }}</p>
        </div>
      </div>
      <dl class="space-y-1.5">
        <div v-for="[k, tone] in [['critical', 'crit'], ['warning', 'warn'], ['normal', '']]" :key="k" class="flex items-center justify-between gap-2">
          <dt class="text-[13px] text-ink-muted">{{ t(`insights.ecology.${k}`) }}</dt>
          <dd class="text-[13.5px] font-semibold tnum" :class="eco[k] > 0 && tone ? TONE[tone] : 'text-ink'">{{ num(eco[k], locale) }}</dd>
        </div>
      </dl>
      <div v-if="eco.problem_points?.length" class="mt-3 border-t border-line pt-2">
        <p class="text-[12px] text-ink-muted mb-1">{{ t('insights.ecology.points') }}</p>
        <p v-for="p in eco.problem_points" :key="p.name" class="flex items-center justify-between gap-2 text-[12.5px]">
          <span class="truncate text-ink">{{ p.name }} <span class="text-ink-faint">· {{ p.parameters.join(', ') }}</span></span>
          <span class="tnum font-semibold text-crit">{{ p.count }}</span>
        </p>
      </div>
    </div>
  </section>
</template>
