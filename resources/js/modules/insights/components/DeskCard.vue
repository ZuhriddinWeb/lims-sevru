<script setup>
/**
 * Ish stoli kartochkasi: sarlavha + son, qisqa ko'rsatkichlar (chip), ro'yxat, «Hammasi» havolasi.
 *   items: [{ key, main, mono?, sub?, badge?, alert?, to? }]
 *   chips: [{ label, alert? }]
 */
import { useI18n } from 'vue-i18n'
import { ChevronRight } from 'lucide-vue-next'
import { num } from '../helpers'

defineProps({
  title: { type: String, required: true },
  icon: { type: [Object, Function], default: null },
  count: { type: Number, default: null },
  alert: { type: Boolean, default: false },
  chips: { type: Array, default: () => [] },
  items: { type: Array, default: () => [] },
  more: { type: Object, default: null },
  error: { type: Boolean, default: false },
  name: { type: String, default: '' },
  subtitle: { type: String, default: '' },
})
defineEmits(['open'])
const { t, locale } = useI18n()
</script>

<template>
  <section class="panel p-4 flex flex-col min-w-0" :data-card="name">
    <header class="flex items-center justify-between gap-2 mb-2">
      <h2 class="flex items-center gap-2 text-[14px] font-semibold text-ink min-w-0">
        <component :is="icon" v-if="icon" :size="16" class="shrink-0 text-ink-muted" />
        <span class="truncate">{{ title }}</span>
      </h2>
      <span
        v-if="count !== null"
        class="shrink-0 rounded-full px-2.5 py-0.5 text-[12.5px] font-semibold tnum"
        :class="alert && count > 0 ? 'bg-crit-light text-crit dark:bg-crit-dark/30 dark:text-crit-light' : 'bg-surface-sunken text-ink'"
        data-count
      >{{ num(count, locale) }}</span>
    </header>

    <div v-if="chips.length" class="flex flex-wrap gap-1.5 mb-2" data-chips>
      <span
        v-for="(c, i) in chips" :key="i"
        class="rounded px-1.5 py-0.5 text-[11.5px]"
        :class="c.alert ? 'bg-crit-light text-crit dark:bg-crit-dark/30 dark:text-crit-light font-medium' : 'bg-surface-sunken text-ink-muted'"
      >{{ c.label }}</span>
    </div>

    <p v-if="subtitle && !error" class="text-[11.5px] font-medium uppercase tracking-wide text-ink-faint mt-1" data-subtitle>{{ subtitle }}</p>

    <p v-if="error" class="text-[13px] text-crit">{{ t('insights.blockError') }}</p>
    <p v-else-if="!items.length" class="py-3 text-[13px] text-ink-faint" data-empty>{{ t('insights.desk.none') }}</p>
    <ul v-else-if="items.length" class="divide-y divide-line -mx-1">
      <li v-for="it in items" :key="it.key">
        <button
          type="button" class="w-full flex items-center gap-2 px-1 py-1.5 text-left rounded hover:bg-surface-sunken/60 disabled:hover:bg-transparent"
          :disabled="!it.to" :data-item="it.key" @click="it.to && $emit('open', it.to)"
        >
          <span class="min-w-0 flex-1">
            <span class="block text-[13px] text-ink truncate" :class="it.mono ? 'font-mono' : ''">{{ it.main }}</span>
            <span v-if="it.sub" class="block text-[11.5px] text-ink-faint truncate">{{ it.sub }}</span>
          </span>
          <span v-if="it.badge" class="shrink-0 text-[12px] tnum" :class="it.alert ? 'text-crit font-medium' : 'text-ink-muted'">{{ it.badge }}</span>
          <ChevronRight v-if="it.to" :size="14" class="shrink-0 text-ink-faint" />
        </button>
      </li>
    </ul>

    <button v-if="more" type="button" class="mt-auto pt-2 self-start text-[12.5px] text-assay-700 dark:text-assay-300 hover:underline underline-offset-2" data-more @click="$emit('open', more)">
      {{ t('insights.desk.all') }} →
    </button>
  </section>
</template>
