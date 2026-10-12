<script setup>
import { useI18n } from 'vue-i18n'
import { STATUS_DOT, STATUS_TONE } from '../helpers'
import '../i18n'

defineProps({
  status: { type: String, default: 'draft' },
  size: { type: String, default: 'md' },
})
const { t } = useI18n()
</script>

<template>
  <span
    class="inline-flex items-center gap-1.5 rounded-full font-medium whitespace-nowrap"
    :class="[STATUS_TONE[status] || STATUS_TONE.draft, size === 'sm' ? 'px-2 py-0.5 text-[11px]' : 'px-2.5 py-1 text-[12px]']"
  >
    <span class="h-1.5 w-1.5 rounded-full shrink-0" :class="STATUS_DOT[status] || STATUS_DOT.draft" />
    {{ t(`ws.st.${status}`) }}
  </span>
</template>
