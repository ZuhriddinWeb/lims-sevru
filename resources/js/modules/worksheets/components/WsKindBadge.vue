<script setup>
/** Varaq turi belgisi: faqat «nazorat uchun» varaqlarda ko'rinadi (asosiy — belgisiz) */
import { useI18n } from 'vue-i18n'
import { ShieldCheck } from 'lucide-vue-next'
import '../i18n'

defineProps({
  kind: { type: String, default: 'main' },
  size: { type: String, default: 'md' },
})
const { t } = useI18n()
</script>

<template>
  <span
    v-if="kind === 'control'"
    class="inline-flex items-center gap-1 rounded-full bg-aqua-50 text-aqua-700 dark:bg-aqua-800/35 dark:text-aqua-200 font-medium whitespace-nowrap align-middle"
    :class="size === 'sm' ? 'px-1.5 py-0.5 text-[10.5px]' : 'px-2 py-0.5 text-[12px]'"
    :title="t('ws.kindControlHint')"
    data-kind="control"
  >
    <ShieldCheck :size="size === 'sm' ? 11 : 13" /> {{ t('ws.kindControl') }}
  </span>
</template>
