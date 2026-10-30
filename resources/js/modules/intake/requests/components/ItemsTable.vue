<script setup>
/** Buyurtma namunalari — faqat ko'rish (holati va yaratilgan proba shifri bilan) */
import { useI18n } from 'vue-i18n'
import { KeyRound, MapPin } from 'lucide-vue-next'
import { ITEM_TONE, nameOf } from '../shared'
import '../i18n'
import '../i18nGroup' // [P30]

defineProps({
  items: { type: Array, default: () => [] },
  sampleRoute: { type: Function, default: null }, // (sample) => route location | null
})
const { t, locale } = useI18n()
</script>

<template>
  <div class="overflow-x-auto">
    <table class="w-full text-[13.5px]">
      <thead>
        <tr class="border-b border-line bg-surface-sunken/60 text-left text-[12px] uppercase tracking-wide text-ink-muted">
          <th class="px-4 py-2.5 font-medium w-10">№</th>
          <th class="px-4 py-2.5 font-medium">{{ t('sreq.form.code') }}</th>
          <th class="px-4 py-2.5 font-medium">{{ t('sreq.form.state') }}</th>
          <th class="px-4 py-2.5 font-medium">{{ t('sreq.form.what') }}</th>
          <th class="px-4 py-2.5 font-medium text-right">{{ t('sreq.form.quantity') }}</th>
          <th class="px-4 py-2.5 font-medium">{{ t('sreq.col.status') }}</th>
        </tr>
      </thead>
      <tbody class="divide-y divide-line">
        <tr v-for="i in items" :key="i.id" :data-item-row="i.id">
          <td class="px-4 py-2.5 text-ink-faint tnum">{{ i.position }}</td>
          <td class="px-4 py-2.5">
            <span class="font-mono font-semibold text-ink">{{ i.customer_code || '—' }}</span>
            <!-- [P29] svodka nuqtasi — server faqat buyurtmachiga beradi -->
            <span v-if="i.svodka_point" class="flex items-center gap-1 text-[12px] text-aqua-700 dark:text-aqua-200" data-point-name><MapPin :size="12" class="shrink-0" />{{ i.svodka_point }}</span>
            <!-- [P30] guruh shifri — server faqat buyurtmachiga va o'sha guruh xodimiga beradi -->
            <span v-if="i.group_code" class="flex items-center gap-1 font-mono text-[12px] text-assay-700 dark:text-assay-200" :title="t('sreq.group.codeHint')" data-group-code><KeyRound :size="12" class="shrink-0" />{{ i.group_code }}</span>
            <span v-if="i.notes" class="block text-[12px] text-ink-faint">{{ i.notes }}</span>
          </td>
          <td class="px-4 py-2.5 text-ink-muted">{{ t(`sreq.form.states.${i.state_of_matter}`) }}</td>
          <td class="px-4 py-2.5">
            <span v-if="i.profile" class="block text-[12px] text-ink-muted">{{ nameOf(i.profile, locale) }}</span>
            <span class="flex flex-wrap gap-1">
              <span v-for="e in i.elements" :key="e.id" class="rounded bg-surface-sunken px-1.5 py-0.5 text-[11.5px] font-mono" :title="nameOf(e, locale)">{{ e.symbol }}</span>
            </span>
          </td>
          <td class="px-4 py-2.5 text-right tnum text-ink-muted whitespace-nowrap">{{ i.quantity !== null && i.quantity !== undefined ? `${i.quantity} ${i.quantity_unit || ''}` : '—' }}</td>
          <td class="px-4 py-2.5 whitespace-nowrap">
            <span class="text-[12.5px] font-medium" :class="ITEM_TONE[i.status]">{{ t(`sreq.itemStatus.${i.status}`) }}</span>
            <template v-if="i.sample">
              <router-link v-if="sampleRoute && sampleRoute(i.sample)" :to="sampleRoute(i.sample)" class="block font-mono text-[12px] text-assay-700 dark:text-assay-200 hover:underline" data-sample-link>{{ i.sample.code }}</router-link>
              <span v-else class="block font-mono text-[12px] text-ink-faint">{{ i.sample.code }}</span>
            </template>
          </td>
        </tr>
      </tbody>
    </table>
  </div>
</template>
