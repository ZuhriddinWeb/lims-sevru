<script setup>
/**
 * Ishchi varaqlar: davrda ochilgan (asosiy / nazorat), hozir ochiq varaqlar holati, sifat belgilari.
 */
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { AlertTriangle, ClipboardList } from 'lucide-vue-next'
import { num } from '../helpers'

const props = defineProps({ data: { type: Object, default: null } })
defineEmits(['open'])
const { t, locale } = useI18n()

const STATUSES = ['draft', 'printed', 'in_progress', 'completed']
const maxOpen = computed(() => Math.max(1, ...STATUSES.map((s) => props.data?.open?.[s] ?? 0)))
const extras = computed(() => [
  ['approved', props.data?.approved],
  ['warnings', props.data?.with_warnings],
  ['extra', props.data?.extra_parallels],
  ['retests', props.data?.retests],
].filter(([, v]) => v !== null && v !== undefined))
</script>

<template>
  <section class="panel p-4 flex flex-col" data-worksheets>
    <header class="flex items-center justify-between gap-2 mb-3">
      <h2 class="text-[14px] font-semibold text-ink">{{ t('insights.worksheets.title') }}</h2>
      <button type="button" class="btn-ghost btn-sm" @click="$emit('open', { name: 'worksheets' })"><ClipboardList :size="14" /> {{ t('insights.showAll') }}</button>
    </header>

    <p v-if="data?.available === false" class="text-[13px] text-ink-faint">{{ t('insights.worksheets.unavailable') }}</p>
    <p v-else-if="data?.error" class="text-[13px] text-crit">{{ t('insights.blockError') }}</p>
    <template v-else-if="data">
      <p class="text-[12px] text-ink-muted mb-1.5">{{ t('insights.worksheets.created') }}</p>
      <div class="grid grid-cols-2 gap-2 mb-4">
        <div class="rounded-md bg-surface-sunken/60 px-3 py-2">
          <p class="text-[20px] font-semibold tnum text-ink leading-none" data-created="main">{{ num(data.created.main, locale) }}</p>
          <p class="text-[12px] text-ink-muted mt-1">{{ t('insights.worksheets.main') }}</p>
        </div>
        <div class="rounded-md bg-surface-sunken/60 px-3 py-2">
          <p class="text-[20px] font-semibold tnum text-ink leading-none" data-created="control">{{ num(data.created.control, locale) }}</p>
          <p class="text-[12px] text-ink-muted mt-1">{{ t('insights.worksheets.control') }}</p>
        </div>
      </div>

      <p class="text-[12px] text-ink-muted mb-1.5">{{ t('insights.worksheets.open') }}</p>
      <dl class="space-y-1.5 mb-4">
        <div v-for="s in STATUSES" :key="s" class="grid grid-cols-[minmax(0,1fr)_auto] items-center gap-x-3 gap-y-0.5" :data-open="s">
          <dt class="text-[13px] text-ink">{{ t(`insights.worksheets.status.${s}`) }}</dt>
          <dd class="text-[13px] font-semibold tnum text-ink text-right">{{ num(data.open[s], locale) }}</dd>
          <dd class="col-span-2 h-1 rounded-full bg-surface-sunken overflow-hidden">
            <span class="block h-full rounded-full bg-assay-400 dark:bg-assay-500" :style="{ width: `${((data.open[s] ?? 0) / maxOpen) * 100}%` }" />
          </dd>
        </div>
      </dl>

      <p v-if="data.awaiting?.old" class="mb-3 flex items-center gap-1.5 rounded-md bg-warn-light dark:bg-warn-dark/20 px-2.5 py-1.5 text-[12.5px] text-warn-dark dark:text-warn-light" data-awaiting-old>
        <AlertTriangle :size="14" /> {{ t('insights.worksheets.awaitingOld', { n: num(data.awaiting.old, locale) }) }}
      </p>

      <dl class="mt-auto grid grid-cols-2 gap-x-3 gap-y-1.5 border-t border-line pt-3">
        <div v-for="[k, v] in extras" :key="k" class="flex items-center justify-between gap-2" :data-extra="k">
          <dt class="text-[12.5px] text-ink-muted">{{ t(`insights.worksheets.${k}`) }}</dt>
          <dd class="text-[13px] font-semibold tnum text-ink">{{ num(v, locale) }}</dd>
        </div>
      </dl>
    </template>
  </section>
</template>
