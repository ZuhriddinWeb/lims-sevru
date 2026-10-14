<script setup>
/**
 * Ko'rsatkichlar jadvali (guruhlar, buyurtmachilar).
 *   columns: [{ key, label, kind: 'num' | 'hours', bar?: true, alert?: true }]
 *   bar   — shu ustunda ingichka chiziq (eng kattasiga nisbatan, bitta rang)
 *   alert — 0 dan katta bo'lsa belgi + qizil matn (kechikkan)
 */
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { AlertTriangle } from 'lucide-vue-next'
import { hours, num } from '../helpers'

const props = defineProps({
  rows: { type: Array, default: () => [] },
  columns: { type: Array, required: true },
  nameLabel: { type: String, required: true },
  nameOf: { type: Function, required: true },
  subOf: { type: Function, default: null },
  muted: { type: Function, default: () => false },
  footer: { type: Object, default: null },  // { name, values: {key: n} }
})
const { t, locale } = useI18n()
const max = computed(() => {
  const out = {}
  for (const c of props.columns) if (c.bar) out[c.key] = Math.max(1, ...props.rows.map((r) => r[c.key] ?? 0))
  return out
})
const fmt = (c, v) => (c.kind === 'hours' ? (v == null ? '—' : `${hours(v, locale.value)} ${t('insights.hours')}`) : num(v ?? 0, locale.value))
</script>

<template>
  <div class="overflow-x-auto -mx-1">
    <table class="w-full text-[13px]">
      <thead>
        <tr class="text-left text-[11.5px] uppercase tracking-wide text-ink-muted border-b border-line">
          <th class="px-1.5 py-1.5 font-medium">{{ nameLabel }}</th>
          <th v-for="c in columns" :key="c.key" class="px-1.5 py-1.5 font-medium text-right whitespace-nowrap">{{ c.label }}</th>
        </tr>
      </thead>
      <tbody class="divide-y divide-line">
        <tr v-for="(r, i) in rows" :key="r.id ?? `x${i}`" :class="muted(r) ? 'text-ink-muted' : ''" data-row>
          <td class="px-1.5 py-2 min-w-[150px]">
            <span :class="muted(r) ? 'italic' : 'font-medium text-ink'">{{ nameOf(r) }}</span>
            <span v-if="subOf && subOf(r)" class="block text-[11.5px] text-ink-faint">{{ subOf(r) }}</span>
          </td>
          <td v-for="c in columns" :key="c.key" class="px-1.5 py-2 text-right align-top whitespace-nowrap" :data-col="c.key">
            <span
              class="tnum"
              :class="c.alert && r[c.key] > 0 ? 'inline-flex items-center gap-1 font-semibold text-crit' : 'text-ink'"
            >
              <AlertTriangle v-if="c.alert && r[c.key] > 0" :size="12" />{{ fmt(c, r[c.key]) }}
            </span>
            <span v-if="c.bar" class="block h-1 mt-1 ml-auto w-16 rounded-full bg-surface-sunken overflow-hidden">
              <span class="block h-full rounded-full bg-assay-400 dark:bg-assay-500" :style="{ width: `${((r[c.key] ?? 0) / max[c.key]) * 100}%` }" />
            </span>
          </td>
        </tr>
        <tr v-if="footer" class="text-ink-muted" data-footer>
          <td class="px-1.5 py-2 text-[12.5px] italic">{{ footer.name }}</td>
          <td v-for="c in columns" :key="c.key" class="px-1.5 py-2 text-right tnum">{{ footer.values[c.key] == null ? '' : fmt(c, footer.values[c.key]) }}</td>
        </tr>
      </tbody>
    </table>
  </div>
</template>
