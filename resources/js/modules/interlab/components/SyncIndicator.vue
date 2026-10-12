<script setup>
/** Sarlavhadagi sinxronlash holati: navbat, xato, «Hozir sinxronlash» */
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { AlertTriangle, CheckCircle2, Clock, RefreshCw } from 'lucide-vue-next'
import { formatDateTime } from '@/lib/format'
import '../i18n'

const props = defineProps({
  queue: { type: Object, default: () => ({ pending: 0, failed: 0 }) },
  lastSyncAt: { type: String, default: null },
  syncing: Boolean,
})
const emit = defineEmits(['sync'])
const { t } = useI18n()

const state = computed(() => {
  if (props.queue?.failed) return { icon: AlertTriangle, cls: 'text-crit', label: t('il.syncFailed', { n: props.queue.failed }) }
  if (props.queue?.pending) return { icon: Clock, cls: 'text-warn', label: t('il.syncPending', { n: props.queue.pending }) }
  return { icon: CheckCircle2, cls: 'text-ok', label: t('il.syncOk') }
})
</script>

<template>
  <div class="inline-flex items-center gap-1 rounded-md border border-line bg-surface pl-2.5 pr-1 h-9 text-[13px]">
    <component :is="state.icon" :size="15" :class="state.cls" />
    <span class="text-ink-muted mr-1" :title="lastSyncAt ? t('il.lastSync', { t: formatDateTime(lastSyncAt) }) : ''">{{ state.label }}</span>
    <button class="btn-ghost btn-icon h-7 w-7" :title="t('il.syncNow')" :disabled="syncing" @click="emit('sync')">
      <RefreshCw :size="14" :class="syncing ? 'animate-spin' : ''" />
    </button>
  </div>
</template>
