<script setup>
import { useI18n } from 'vue-i18n'
import { STATUS_TONE } from '../shared'
import '../i18n'

defineProps({ status: { type: String, required: true } })
const { t } = useI18n()
</script>

<template>
  <span
    class="inline-flex items-center rounded-full px-2 py-0.5 text-[12px] font-medium whitespace-nowrap"
    :class="STATUS_TONE[status] ?? STATUS_TONE.draft"
    :data-status="status"
  >{{ t(`sreq.status.${status}`) }}</span>
</template>
