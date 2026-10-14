<script setup>
/**
 * Nazorat tahlili natijasini asosiy natija bilan solishtirish jadvali.
 *   <ComparisonTable :items="comparison" />   // SampleAnalyses::comparison() javobi
 */
import { useI18n } from 'vue-i18n'
import { formatDate } from '@/lib/format'
import { formatSigned, formatValue, label } from '../helpers'
import '../i18n'

defineProps({ items: { type: Array, default: () => [] } })
const { t, locale } = useI18n()
</script>

<template>
  <div class="space-y-3" data-comparison>
    <div v-for="c in items" :key="c.worksheet_id" class="overflow-x-auto rounded-md border border-line">
      <p class="px-3 py-2 text-[12.5px] text-ink-muted bg-surface-sunken/60 border-b border-line">
        № <span class="font-mono font-semibold text-ink">{{ c.number }}</span>
        · {{ formatDate(c.worksheet_date) }}
        <span v-if="c.status !== 'approved'" class="ml-1 text-warn-dark dark:text-warn-light">· {{ t('intake.portal.preliminary') }}</span>
      </p>
      <table class="w-full text-[13px]">
        <thead>
          <tr class="text-left text-[12px] text-ink-muted">
            <th class="px-3 py-1.5 font-medium">{{ t('intake.panel.compareElement') }}</th>
            <th class="px-3 py-1.5 font-medium text-right">{{ t('intake.panel.compareMain') }}</th>
            <th class="px-3 py-1.5 font-medium text-right">{{ t('intake.panel.compareControl') }}</th>
            <th class="px-3 py-1.5 font-medium text-right">{{ t('intake.panel.compareDiff') }}</th>
            <th class="px-3 py-1.5 font-medium text-right">{{ t('intake.panel.compareRel') }}</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-line">
          <tr v-for="it in c.items" :key="it.key" :data-cmp="it.element_symbol || it.key">
            <td class="px-3 py-1.5">
              <span v-if="it.element_symbol" class="font-mono text-assay-600 dark:text-assay-300 mr-1.5">{{ it.element_symbol }}</span>
              <span class="text-ink-muted">{{ label(it, locale) }}</span>
            </td>
            <td class="px-3 py-1.5 text-right tnum">
              <template v-if="it.main !== null">
                {{ formatValue(it.main, it.decimals, locale) }}
                <span v-if="it.main_source === 'worksheet'" class="block text-[11px] text-warn-dark dark:text-warn-light">{{ t('intake.panel.mainFromWorksheet', { n: it.main_worksheet }) }}</span>
              </template>
              <span v-else class="text-[12px] text-ink-faint">{{ t('intake.panel.noMain') }}</span>
            </td>
            <td class="px-3 py-1.5 text-right tnum font-medium text-ink">{{ it.control !== null ? formatValue(it.control, it.decimals, locale) : '—' }}</td>
            <td class="px-3 py-1.5 text-right tnum">{{ formatSigned(it.diff, it.decimals, locale) }}</td>
            <td class="px-3 py-1.5 text-right tnum" :class="it.rel_pct === null ? 'text-ink-faint' : 'text-ink'">{{ it.rel_pct !== null ? formatSigned(it.rel_pct, 2, locale) : '—' }}</td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>
