<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import {
  AlertTriangle, CalendarClock, Droplets, FlaskConical,
  Microscope, Package, ShieldAlert, TestTube,
} from 'lucide-vue-next'
import api from '@/lib/api'
import PageHeader from '@/components/ui/PageHeader.vue'
import StatusBadge from '@/components/ui/StatusBadge.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import { formatDate, formatDateTime, formatMonth } from '@/lib/format'
import { localized } from '@/i18n'

const { t, locale } = useI18n()
const router = useRouter()

const data = ref(null)
const loading = ref(true)

onMounted(async () => {
  try {
    const res = await api.get('/dashboard')
    data.value = res.data
  } finally {
    loading.value = false
  }
})

const cards = computed(() => {
  const c = data.value?.cards ?? {}
  return [
    { key: 'samplesTotal', value: c.samples_total, icon: TestTube, route: 'samples', tone: 'assay' },
    { key: 'samplesInWork', value: c.samples_in_progress, icon: FlaskConical, route: 'samples', tone: 'assay' },
    { key: 'testObjects', value: c.test_objects_total, icon: Package, route: 'test-objects', tone: 'slate' },
    { key: 'equipmentTotal', value: c.equipment_total, icon: Microscope, route: 'equipment', tone: 'slate' },
    { key: 'equipmentBlocked', value: c.equipment_blocked, icon: ShieldAlert, route: 'equipment', tone: 'crit' },
    { key: 'waterOrders', value: c.water_orders_total, icon: Droplets, route: 'water-orders', tone: 'aqua' },
  ]
})

const TONES = {
  assay: 'text-assay-600 dark:text-assay-300 bg-assay-50 dark:bg-assay-900/35',
  aqua:  'text-aqua-600 dark:text-aqua-300 bg-aqua-50 dark:bg-aqua-800/35',
  crit:  'text-crit bg-crit-light dark:bg-crit-dark/25',
  slate: 'text-ink-muted bg-surface-sunken',
}

/* Status taqsimoti — gorizontal ulushlar */
function distribution(map) {
  const entries = Object.entries(map ?? {})
  const total = entries.reduce((s, [, v]) => s + v, 0) || 1
  return entries.map(([status, count]) => ({ status, count, pct: Math.round((count / total) * 100) }))
}

const samplesByStatus = computed(() => distribution(data.value?.samples_by_status))
const equipmentByStatus = computed(() => distribution(data.value?.equipment_by_status))

/* Oylar bo'yicha ustunlar */
const monthly = computed(() => {
  const list = data.value?.samples_by_month ?? []
  const max = Math.max(...list.map((m) => m.count), 1)
  return list.map((m) => ({ ...m, height: Math.round((m.count / max) * 100), label: formatMonth(m.period_month) }))
})

const today = computed(() =>
  new Date().toLocaleDateString(locale.value === 'en' ? 'en-GB' : locale.value === 'uz' ? 'uz-UZ' : 'ru-RU',
    { day: 'numeric', month: 'long', year: 'numeric' })
)

function daysLabel(dateStr) {
  if (!dateStr) return null
  const diff = Math.ceil((new Date(dateStr) - new Date()) / 86400000)
  return diff < 0 ? t('dashboard.overdue', { n: Math.abs(diff) }) : t('dashboard.daysLeft', { n: diff })
}

function isOverdue(dateStr) {
  return dateStr && new Date(dateStr) < new Date()
}
</script>

<template>
  <div>
    <PageHeader :title="t('dashboard.title')" :subtitle="t('dashboard.subtitle', { date: today })" />

    <!-- Ko'rsatkichlar -->
    <div class="grid gap-3 grid-cols-2 lg:grid-cols-3 xl:grid-cols-6 mb-5">
      <button
        v-for="(card, i) in cards" :key="card.key"
        class="panel p-4 text-left transition-all hover:border-ink-faint animate-row-in"
        :style="{ animationDelay: `${i * 40}ms` }"
        @click="router.push({ name: card.route })"
      >
        <div class="h-8 w-8 rounded-md grid place-items-center mb-3" :class="TONES[card.tone]">
          <component :is="card.icon" :size="16" />
        </div>
        <p class="text-[26px] font-semibold text-ink tnum leading-none">
          <span v-if="loading" class="inline-block h-6 w-10 rounded bg-surface-sunken animate-pulse" />
          <span v-else>{{ card.value ?? 0 }}</span>
        </p>
        <p class="mt-1.5 text-[12.5px] text-ink-muted leading-tight">{{ t(`dashboard.${card.key}`) }}</p>
      </button>
    </div>

    <div class="grid gap-5 lg:grid-cols-3">
      <!-- Kalibrovka eslatmalari -->
      <section class="panel lg:col-span-2">
        <header class="flex items-center gap-2.5 px-4 py-3.5 border-b border-line">
          <CalendarClock :size="17" class="text-warn" />
          <h2 class="text-[14px] font-semibold text-ink">{{ t('dashboard.calibrationReminders') }}</h2>
        </header>

        <EmptyState v-if="!loading && !data?.calibration_reminders?.length"
                    :icon="CalendarClock" :title="t('dashboard.calibrationEmpty')" />

        <ul v-else class="divide-y divide-line">
          <li
            v-for="eq in data?.calibration_reminders ?? []" :key="eq.id"
            class="flex items-center gap-3 px-4 py-3 hover:bg-surface-sunken/60 transition-colors cursor-pointer"
            @click="router.push({ name: 'equipment' })"
          >
            <span
              class="h-1.5 w-1.5 rounded-full shrink-0"
              :class="isOverdue(eq.next_calibration_at) ? 'bg-crit' : 'bg-warn'"
            />
            <div class="min-w-0 flex-1">
              <p class="text-[13.5px] font-medium text-ink truncate">{{ eq.name }}</p>
              <p class="text-[12px] text-ink-faint truncate font-mono">{{ eq.serial_number }}</p>
            </div>
            <div class="text-right shrink-0">
              <p class="text-[13px] text-ink tnum">{{ formatDate(eq.next_calibration_at) }}</p>
              <p class="text-[11.5px]" :class="isOverdue(eq.next_calibration_at) ? 'text-crit' : 'text-ink-faint'">
                {{ daysLabel(eq.next_calibration_at) }}
              </p>
            </div>
            <AlertTriangle v-if="eq.is_blocked" :size="15" class="text-crit shrink-0" />
          </li>
        </ul>
      </section>

      <!-- Saqlash muddati -->
      <section class="panel">
        <header class="flex items-center gap-2.5 px-4 py-3.5 border-b border-line">
          <Package :size="17" class="text-ink-muted" />
          <h2 class="text-[14px] font-semibold text-ink">{{ t('dashboard.expiringObjects') }}</h2>
        </header>

        <EmptyState v-if="!loading && !data?.expiring_objects?.length"
                    :icon="Package" :title="t('dashboard.expiringEmpty')" />

        <ul v-else class="divide-y divide-line">
          <li v-for="obj in data?.expiring_objects ?? []" :key="obj.id"
              class="px-4 py-3 hover:bg-surface-sunken/60 transition-colors cursor-pointer"
              @click="router.push({ name: 'test-objects' })">
            <div class="flex items-center justify-between gap-2">
              <p class="text-[12px] font-mono text-ink-faint">{{ obj.identifier }}</p>
              <StatusBadge :status="obj.status" size="sm" />
            </div>
            <p class="mt-1 text-[13.5px] text-ink truncate">{{ obj.name }}</p>
            <p class="mt-0.5 text-[12px] text-warn tnum">{{ formatDate(obj.storage_expiry) }}</p>
          </li>
        </ul>
      </section>

      <!-- Probalar statuslari -->
      <section class="panel p-4">
        <h2 class="text-[14px] font-semibold text-ink mb-4">{{ t('dashboard.samplesByStatus') }}</h2>
        <div class="space-y-3">
          <div v-for="d in samplesByStatus" :key="d.status">
            <div class="flex items-center justify-between gap-2 mb-1.5">
              <StatusBadge :status="d.status" size="sm" />
              <span class="text-[13px] text-ink tnum font-medium">{{ d.count }}</span>
            </div>
            <div class="h-1.5 rounded-full bg-surface-sunken overflow-hidden">
              <div class="h-full rounded-full bg-assay-400 transition-[width] duration-700 ease-out"
                   :style="{ width: `${d.pct}%` }" />
            </div>
          </div>
          <p v-if="!samplesByStatus.length && !loading" class="text-[13px] text-ink-faint">{{ t('common.noData') }}</p>
        </div>
      </section>

      <!-- Uskunalar holati -->
      <section class="panel p-4">
        <h2 class="text-[14px] font-semibold text-ink mb-4">{{ t('dashboard.equipmentByStatus') }}</h2>
        <div class="space-y-3">
          <div v-for="d in equipmentByStatus" :key="d.status">
            <div class="flex items-center justify-between gap-2 mb-1.5">
              <StatusBadge :status="d.status" size="sm" />
              <span class="text-[13px] text-ink tnum font-medium">{{ d.count }}</span>
            </div>
            <div class="h-1.5 rounded-full bg-surface-sunken overflow-hidden">
              <div class="h-full rounded-full bg-aqua-400 transition-[width] duration-700 ease-out"
                   :style="{ width: `${d.pct}%` }" />
            </div>
          </div>
          <p v-if="!equipmentByStatus.length && !loading" class="text-[13px] text-ink-faint">{{ t('common.noData') }}</p>
        </div>
      </section>

      <!-- Oylar bo'yicha -->
      <section class="panel p-4">
        <h2 class="text-[14px] font-semibold text-ink mb-4">{{ t('dashboard.samplesByMonth') }}</h2>
        <div v-if="monthly.length" class="flex items-end gap-2 h-32">
          <div v-for="m in monthly" :key="m.period_month" class="flex-1 flex flex-col items-center gap-2 min-w-0">
            <span class="text-[12px] text-ink tnum font-medium">{{ m.count }}</span>
            <div class="w-full rounded-t bg-assay-400/80 transition-[height] duration-700 ease-out"
                 :style="{ height: `${Math.max(m.height, 4)}%` }" />
            <span class="text-[10.5px] text-ink-faint truncate w-full text-center">{{ m.period_month.slice(5) }}</span>
          </div>
        </div>
        <p v-else-if="!loading" class="text-[13px] text-ink-faint">{{ t('common.noData') }}</p>
      </section>
    </div>

    <!-- Oxirgi probalar -->
    <section class="panel mt-5">
      <header class="flex items-center justify-between gap-2 px-4 py-3.5 border-b border-line">
        <h2 class="text-[14px] font-semibold text-ink">{{ t('dashboard.recentSamples') }}</h2>
        <button class="btn-ghost btn-sm" @click="router.push({ name: 'samples' })">{{ t('common.more') }}</button>
      </header>

      <EmptyState v-if="!loading && !data?.recent_samples?.length" :icon="TestTube" :title="t('common.noData')" />

      <ul v-else class="divide-y divide-line">
        <li v-for="s in data?.recent_samples ?? []" :key="s.id"
            class="flex flex-wrap items-center gap-x-4 gap-y-1 px-4 py-3 hover:bg-surface-sunken/60 transition-colors cursor-pointer"
            @click="router.push({ name: 'sample-detail', params: { id: s.id } })">
          <span class="font-mono text-[12.5px] text-ink font-medium w-[135px] shrink-0">{{ s.code }}</span>
          <span class="text-[13px] text-ink-muted flex-1 min-w-[120px] truncate">
            {{ localized(s.customer_group, 'name') || '—' }}
            <span class="text-ink-faint px-1">&rarr;</span>
            {{ localized(s.assigned_group, 'name') || '—' }}
          </span>
          <span class="text-[12.5px] text-ink-faint tnum shrink-0">{{ formatDateTime(s.registered_at) }}</span>
          <StatusBadge :status="s.status" size="sm" />
        </li>
      </ul>
    </section>
  </div>
</template>
