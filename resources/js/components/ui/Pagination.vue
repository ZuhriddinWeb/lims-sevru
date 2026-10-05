<script setup>
import { computed } from 'vue'
import { ChevronLeft, ChevronRight } from 'lucide-vue-next'
import { useI18n } from 'vue-i18n'

const props = defineProps({
  meta: { type: Object, default: () => ({}) }, // Laravel paginator
  perPage: { type: Number, default: 15 },
})
const emit = defineEmits(['change', 'per-page'])
const { t } = useI18n()

const current = computed(() => props.meta.current_page || 1)
const last = computed(() => props.meta.last_page || 1)
const from = computed(() => props.meta.from || 0)
const to = computed(() => props.meta.to || 0)
const total = computed(() => props.meta.total || 0)

// 1 … 4 5 [6] 7 8 … 20
const pages = computed(() => {
  const out = []
  const l = last.value, c = current.value
  if (l <= 7) { for (let i = 1; i <= l; i++) out.push(i); return out }
  out.push(1)
  if (c > 3) out.push('…')
  for (let i = Math.max(2, c - 1); i <= Math.min(l - 1, c + 1); i++) out.push(i)
  if (c < l - 2) out.push('…')
  out.push(l)
  return out
})
</script>

<template>
  <div v-if="total > 0" class="flex flex-wrap items-center justify-between gap-3 px-4 py-3 border-t border-line">
    <p class="text-[13px] text-ink-muted tnum">
      {{ t('common.showing', { from, to, total }) }}
    </p>

    <div class="flex items-center gap-3">
      <select
        class="h-8 px-2 text-[13px] bg-surface border border-line rounded-md text-ink-muted hover:border-ink-faint transition-colors focus:outline-none"
        :value="perPage"
        @change="emit('per-page', Number($event.target.value))"
        :aria-label="t('common.rowsPerPage')"
      >
        <option v-for="n in [10, 15, 25, 50, 100]" :key="n" :value="n">{{ n }}</option>
      </select>

      <nav v-if="last > 1" class="flex items-center gap-1">
        <button class="btn-ghost btn-sm btn-icon" :disabled="current <= 1" @click="emit('change', current - 1)">
          <ChevronLeft :size="16" />
        </button>

        <template v-for="(p, i) in pages" :key="i">
          <span v-if="p === '…'" class="px-1.5 text-[13px] text-ink-faint select-none">…</span>
          <button
            v-else
            class="h-8 min-w-8 px-2 rounded-md text-[13px] font-medium tnum transition-colors"
            :class="p === current
              ? 'bg-assay-500 text-white'
              : 'text-ink-muted hover:bg-surface-sunken hover:text-ink'"
            @click="emit('change', p)"
          >{{ p }}</button>
        </template>

        <button class="btn-ghost btn-sm btn-icon" :disabled="current >= last" @click="emit('change', current + 1)">
          <ChevronRight :size="16" />
        </button>
      </nav>
    </div>
  </div>
</template>
