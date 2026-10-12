<script setup>
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { statusTone } from '../helpers'
import '../i18n'

const props = defineProps({
  status: { type: String, required: true },
  direction: { type: String, default: 'out' },
  size: { type: String, default: 'md' },
})
const { t } = useI18n()
const tone = computed(() => statusTone(props.direction, props.status))
</script>

<template>
  <span
    class="inline-flex items-center gap-1.5 rounded-full font-medium whitespace-nowrap"
    :class="[tone.badge, size === 'sm' ? 'px-2 py-0.5 text-[11px]' : 'px-2.5 py-1 text-[12px]']"
  >
    <span class="h-1.5 w-1.5 rounded-full shrink-0" :class="tone.dot" />
    {{ t(`il.st.${direction === 'in' ? 'in' : 'out'}.${status}`) }}
  </span>
</template>
