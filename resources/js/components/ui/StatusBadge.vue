<script setup>
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

const props = defineProps({
  status: { type: String, default: null },
  size: { type: String, default: 'md' },
})

const { t, te } = useI18n()

// Har bir status — ma'noli rang. Chap tomonda nuqta, matn yonida.
const TONE = {
  // probalar
  registered: 'slate', assigned: 'info', in_progress: 'warn',
  completed: 'ok', archived: 'muted',
  pending: 'slate', done: 'ok',
  // obyektlar
  new: 'info', in_testing: 'warn', tested: 'ok', defect: 'crit', disposed: 'muted',
  // uskunalar
  working: 'ok', maintenance: 'warn', out_of_service: 'crit', awaiting_calibration: 'warn',
  planned: 'slate', cancelled: 'muted',
  // baholash
  normal: 'ok', warning: 'warn', critical: 'crit', unknown: 'muted',
  passed: 'ok', failed: 'crit',
  active: 'ok', inactive: 'crit',
}

const CLASSES = {
  ok:    'bg-ok-light text-ok-dark dark:bg-ok-dark/25 dark:text-ok-light',
  warn:  'bg-warn-light text-warn-dark dark:bg-warn-dark/25 dark:text-warn-light',
  crit:  'bg-crit-light text-crit-dark dark:bg-crit-dark/25 dark:text-crit-light',
  info:  'bg-aqua-50 text-aqua-700 dark:bg-aqua-800/35 dark:text-aqua-200',
  slate: 'bg-surface-sunken text-ink-muted dark:bg-white/5',
  muted: 'bg-surface-sunken text-ink-faint dark:bg-white/5',
}

const DOT = {
  ok: 'bg-ok', warn: 'bg-warn', crit: 'bg-crit',
  info: 'bg-aqua-500', slate: 'bg-ink-faint', muted: 'bg-ink-faint/60',
}

const tone = computed(() => TONE[props.status] || 'slate')
const label = computed(() => {
  const key = `status.${props.status}`
  return te(key) ? t(key) : (props.status ?? '—')
})
</script>

<template>
  <span
    class="inline-flex items-center gap-1.5 rounded-full font-medium whitespace-nowrap"
    :class="[CLASSES[tone], size === 'sm' ? 'px-2 py-0.5 text-[11px]' : 'px-2.5 py-1 text-[12px]']"
  >
    <span class="h-1.5 w-1.5 rounded-full shrink-0" :class="DOT[tone]" />
    {{ label }}
  </span>
</template>
