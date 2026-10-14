<script setup>
/**
 * Smenalar: hozirgi smena (boshlanganidan beri) va davr bo'yicha smenalar jadvali.
 * Jadvaldagi ingichka chiziq — ustun ichida eng kattasiga nisbatan (bitta rang, faqat yordamchi belgi).
 */
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { Clock, Radio, Settings2, Users } from 'lucide-vue-next'
import { num, parseTs, hm } from '../helpers'

const props = defineProps({
  data: { type: Object, default: null },
  canConfigure: { type: Boolean, default: false },
})
defineEmits(['configure'])
const { t, te, locale } = useI18n()

const COLS = computed(() => {
  const rows = props.data?.rows ?? []
  const has = (k) => rows.some((r) => k in r)
  return ['received', 'completed', 'analyses', 'worksheets', 'signoffs'].filter(has)
})
const max = computed(() => {
  const out = {}
  for (const c of COLS.value) out[c] = Math.max(1, ...(props.data?.rows ?? []).map((r) => r[c] ?? 0))
  return out
})
const live = computed(() => props.data?.live ?? null)
const liveSince = computed(() => (live.value ? hm(parseTs(live.value.start)) : ''))
</script>

<template>
  <section class="panel p-4 flex flex-col" data-shifts>
    <header class="mb-3">
      <h2 class="text-[14px] font-semibold text-ink">{{ t('insights.shifts.title') }}</h2>
      <p class="text-[12.5px] text-ink-muted">{{ t('insights.shifts.subtitle') }}</p>
    </header>

    <div v-if="data && data.configured === false" class="flex-1 grid place-items-center text-center py-6" data-not-configured>
      <div>
        <Clock :size="28" class="mx-auto text-ink-faint mb-2" />
        <p class="text-[13.5px] font-medium text-ink">{{ t('insights.shifts.notConfigured') }}</p>
        <p class="text-[12.5px] text-ink-muted mt-1 max-w-[280px] mx-auto">{{ t('insights.shifts.notConfiguredHint') }}</p>
        <button v-if="canConfigure" type="button" class="btn-outline btn-sm mt-3" data-configure @click="$emit('configure')">
          <Settings2 :size="14" /> {{ t('insights.shifts.configure') }}
        </button>
      </div>
    </div>

    <template v-else-if="data && !data.error">
      <!-- Hozirgi smena -->
      <div v-if="live" class="rounded-md border border-assay-300 dark:border-assay-700 bg-assay-50 dark:bg-assay-900/30 p-3 mb-3" data-live>
        <p class="flex items-center gap-1.5 text-[12.5px] font-medium text-assay-700 dark:text-assay-200">
          <Radio :size="14" /> {{ t('insights.shifts.live') }}: {{ live.name }}
          <span class="font-normal text-ink-muted">· {{ t('insights.shifts.since', { time: liveSince }) }}</span>
        </p>
        <dl class="mt-2 grid grid-cols-4 gap-2">
          <div v-for="k in ['received', 'completed', 'analyses', 'worksheets']" :key="k" class="min-w-0">
            <dd class="text-[18px] font-semibold tnum text-ink leading-none" :data-live-value="k">{{ live[k] == null ? '—' : num(live[k], locale) }}</dd>
            <dt class="text-[11px] text-ink-muted mt-1 truncate">{{ t(`insights.shifts.cols.${k}`) }}</dt>
          </div>
        </dl>
      </div>

      <div class="overflow-x-auto -mx-1">
        <table class="w-full text-[13px]">
          <thead>
            <tr class="text-left text-[11.5px] uppercase tracking-wide text-ink-muted border-b border-line">
              <th class="px-1 py-1.5 font-medium">{{ t('insights.shifts.cols.shift') }}</th>
              <th v-for="c in COLS" :key="c" class="px-1 py-1.5 font-medium text-right" :title="te(`insights.shifts.colHints.${c}`) ? t(`insights.shifts.colHints.${c}`) : undefined">
                {{ t(`insights.shifts.cols.${c}`) }}
              </th>
            </tr>
          </thead>
          <tbody class="divide-y divide-line">
            <tr v-for="r in data.rows" :key="r.id" :data-shift-row="r.id">
              <td class="px-1 py-2">
                <span class="font-medium text-ink">{{ r.name }}</span>
                <span class="block text-[11.5px] text-ink-faint tnum">{{ r.start_time }}–{{ r.end_time }}</span>
              </td>
              <td v-for="c in COLS" :key="c" class="px-1 py-2 text-right align-top">
                <span class="tnum text-ink" :data-cell="c">{{ num(r[c], locale) }}</span>
                <span class="block h-1 mt-1 rounded-full bg-surface-sunken overflow-hidden">
                  <span class="block h-full rounded-full bg-assay-400 dark:bg-assay-500" :style="{ width: `${((r[c] ?? 0) / max[c]) * 100}%` }" />
                </span>
              </td>
            </tr>
            <tr v-if="data.outside" class="text-ink-muted" data-outside>
              <td class="px-1 py-2 text-[12.5px]">{{ t('insights.shifts.outside') }}</td>
              <td v-for="c in COLS" :key="c" class="px-1 py-2 text-right tnum">{{ num(data.outside[c] ?? 0, locale) }}</td>
            </tr>
          </tbody>
        </table>
      </div>

      <div class="mt-auto pt-3 space-y-1 text-[12px] text-ink-muted">
        <p class="inline-flex items-center gap-1.5" data-staff><Users :size="13" /> {{ t('insights.shifts.staff', { n: num(data.staff_active ?? 0, locale) }) }}</p>
        <p v-if="data.signoffs_no_time" class="text-ink-faint" data-no-time>{{ t('insights.shifts.noTime', { n: num(data.signoffs_no_time, locale) }) }}</p>
      </div>
    </template>

    <p v-else-if="data?.error" class="text-[13px] text-crit">{{ t('insights.blockError') }}</p>
    <div v-else class="space-y-2">
      <div v-for="i in 3" :key="i" class="h-9 rounded bg-surface-sunken animate-pulse" />
    </div>
  </section>
</template>
