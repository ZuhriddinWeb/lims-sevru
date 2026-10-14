<script setup>
/**
 * Proba qoldig'i rejalangan sarfga yetmasa — ogohlantirish (varaq yaratish / proba qo'shishda).
 *   <LowBalanceAlert :items="[{ code, need, available, unit }]" />
 */
import { useI18n } from 'vue-i18n'
import { AlertTriangle } from 'lucide-vue-next'
import { formatQty } from '../helpers'
import '../i18n'

defineProps({ items: { type: Array, default: () => [] } })
const { t, locale } = useI18n()
</script>

<template>
  <div v-if="items.length" class="rounded-md border border-warn/50 bg-warn-light/70 dark:bg-warn-dark/20 px-3 py-2.5 text-[13px] text-warn-dark dark:text-warn-light" data-low-balance>
    <p class="flex items-center gap-1.5 font-semibold">
      <AlertTriangle :size="15" /> {{ t('ws.lowBalanceTitle') }}
    </p>
    <ul class="mt-1.5 space-y-0.5">
      <li v-for="it in items" :key="it.sample_id ?? it.code" class="tnum">
        <span class="font-mono font-semibold">{{ it.code }}</span>:
        {{ t('ws.lowBalanceLine', { need: formatQty(it.need, locale), available: formatQty(it.available, locale), unit: it.unit }) }}
      </li>
    </ul>
    <p class="mt-1.5 text-[12.5px] opacity-90">{{ t('ws.lowBalanceHint') }}</p>
  </div>
</template>
