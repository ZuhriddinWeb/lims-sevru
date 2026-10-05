<script setup>
import { onMounted, onUnmounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { AlertTriangle, Bell, CalendarClock, CheckCheck, Package, TestTube, Wrench } from 'lucide-vue-next'
import { useNotificationStore } from '@/stores/notifications'
import { formatDateTime } from '@/lib/format'

const { t } = useI18n()
const router = useRouter()
const store = useNotificationStore()

const open = ref(false)

const ICONS = {
  calibration: CalendarClock,
  maintenance: Wrench,
  storage: Package,
  sample: TestTube,
}

const LEVEL = {
  critical: 'text-crit bg-crit-light dark:bg-crit-dark/25',
  warning:  'text-warn bg-warn-light dark:bg-warn-dark/25',
  info:     'text-ink-muted bg-surface-sunken',
}

function go(item) {
  store.markRead(item.id)
  open.value = false
  if (item.link_route && item.link_id) {
    router.push({ name: item.link_route, params: { id: item.link_id } })
  }
}

onMounted(() => store.startPolling())
onUnmounted(() => store.stopPolling())
</script>

<template>
  <div class="relative">
    <button class="btn-ghost btn-icon relative" :title="t('notify.title')" @click="open = !open">
      <Bell :size="18" />
      <span
        v-if="store.unreadCount"
        class="absolute -top-0.5 -right-0.5 h-4 min-w-4 px-1 rounded-full bg-crit text-white
               text-[10px] font-semibold grid place-items-center tnum"
      >{{ store.unreadCount > 99 ? '99+' : store.unreadCount }}</span>
    </button>

    <div v-if="open" class="fixed inset-0 z-10" @click="open = false" />

    <Transition
      enter-active-class="transition duration-150 ease-out" enter-from-class="opacity-0 -translate-y-1"
      leave-active-class="transition duration-100 ease-in" leave-to-class="opacity-0"
    >
      <div v-if="open" class="absolute right-0 mt-1 z-20 w-[min(380px,calc(100vw-2rem))] panel shadow-pop overflow-hidden">
        <header class="flex items-center justify-between gap-2 px-4 py-3 border-b border-line">
          <h3 class="text-[13.5px] font-semibold text-ink">{{ t('notify.title') }}</h3>
          <button v-if="store.unreadCount" class="btn-ghost btn-sm" @click="store.markAllRead">
            <CheckCheck :size="14" /> {{ t('notify.markAll') }}
          </button>
        </header>

        <div v-if="store.loading && !store.items.length" class="p-4 space-y-2">
          <div v-for="i in 3" :key="i" class="h-12 rounded bg-surface-sunken animate-pulse" />
        </div>

        <div v-else-if="!store.items.length" class="px-4 py-10 text-center">
          <Bell :size="22" class="mx-auto text-ink-faint mb-2" />
          <p class="text-[13.5px] text-ink-muted">{{ t('notify.empty') }}</p>
        </div>

        <ul v-else class="max-h-[420px] overflow-y-auto divide-y divide-line">
          <li
            v-for="item in store.items" :key="item.id"
            class="flex items-start gap-3 px-4 py-3 cursor-pointer transition-colors hover:bg-surface-sunken/70"
            :class="!item.read_at ? 'bg-assay-50/40 dark:bg-assay-900/15' : ''"
            @click="go(item)"
          >
            <span class="h-8 w-8 shrink-0 rounded-md grid place-items-center" :class="LEVEL[item.level]">
              <component :is="ICONS[item.type] || AlertTriangle" :size="15" />
            </span>
            <div class="min-w-0 flex-1">
              <p class="text-[13px] font-medium text-ink leading-snug">{{ item.title }}</p>
              <p v-if="item.body" class="text-[12px] text-ink-muted mt-0.5 truncate">{{ item.body }}</p>
              <p class="text-[11px] text-ink-faint mt-1 tnum">{{ formatDateTime(item.created_at) }}</p>
            </div>
            <span v-if="!item.read_at" class="h-1.5 w-1.5 rounded-full bg-assay-500 shrink-0 mt-1.5" />
          </li>
        </ul>
      </div>
    </Transition>
  </div>
</template>
