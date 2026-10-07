<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import {
  AlertTriangle, ArrowDown, ArrowRight, ArrowUp, CalendarClock,
  Clock, Droplets, FlaskConical, Microscope, TestTube, TrendingUp,
} from 'lucide-vue-next'
import api from '@/lib/api'
import PageHeader from '@/components/ui/PageHeader.vue'
import StatusBadge from '@/components/ui/StatusBadge.vue'
import { useCatalogStore } from '@/stores/catalogs'
import { formatDate, formatNumber } from '@/lib/format'
import { localized } from '@/i18n'

const { t, locale } = useI18n()
const router = useRouter()
const catalogs = useCatalogStore()

const data = ref(null)
const loading = ref(true)
const filters = ref({ period: 'month', laboratory_id: null })

const laboratories = computed(() => catalogs.get('laboratories'))

async function load() {
  loading.value = true
  try {
    const params = Object.fromEntries(
      Object.entries(filters.value).filter(([, v]) => v !== null && v !== '')
    )
    const { data: res } = await api.get('/executive', { params })
    data.value = res
  } finally {
    loading.value = false
  }
}

onMounted(() => {
  catalogs.load('laboratories')
  load()
})

watch(filters, load, { deep: true })

/* ---------- Asosiy ko'rsatkichlar ---------- */
const kpiCards = computed(() => {
  const k = data.value?.kpi
  if (!k) return []
  return [
    {
      key: 'samples', icon: TestTube,
      value: k.samples.value, change: k.samples.change,
      tone: 'assay', route: 'samples',
    },
    {
      key: 'tests', icon: FlaskConical,
      value: k.tests.value, change: k.tests.change,
      tone: 'assay', route: 'samples',
    },
    {
      key: 'turnaround', icon: Clock,
      value: k.turnaround_full.value, change: k.turnaround_full.change,
      suffix: t('exec.hours'), tone: 'aqua',
      sub: k.turnaround_lab.value != null
        ? `${t('exec.labOnly')}: ${k.turnaround_lab.value} ${t('exec.hours')}`
        : null,
    },
    {
      key: 'overdue', icon: AlertTriangle,
      value: k.overdue.value, tone: k.overdue.value > 0 ? 'crit' : 'ok',
      route: 'samples',
    },
  ]
})

const TONES = {
  assay: 'text-assay-600 dark:text-assay-300 bg-assay-50 dark:bg-assay-900/35',
  aqua:  'text-aqua-600 dark:text-aqua-300 bg-aqua-50 dark:bg-aqua-800/35',
  crit:  'text-crit bg-crit-light dark:bg-crit-dark/25',
  ok:    'text-ok bg-ok-light dark:bg-ok-dark/25',
}

// O'zgarish yo'nalishi: ijobiymi yoki salbiy
function changeTone(key, change) {
  if (change == null) return 'text-ink-faint'
  // Vaqt uchun kamayish yaxshi (server allaqachon teskari qilib yuboradi)
  return change > 0 ? 'text-ok' : change < 0 ? 'text-crit' : 'text-ink-faint'
}

/* ---------- Oylar grafigi ---------- */
const monthly = computed(() => {
  const list = data.value?.monthly ?? []
  const max = Math.max(...list.map((m) => Math.max(m.registered, m.completed)), 1)
  return list.map((m) => ({
    ...m,
    hReg: Math.round((m.registered / max) * 100),
    hDone: Math.round((m.completed / max) * 100),
  }))
})

/* ---------- Guruhlar yuklamasi ---------- */
const groupLoad = computed(() => {
  const list = data.value?.by_group ?? []
  const total = list.reduce((s, g) => s + g.count, 0) || 1
  return list.map((g) => ({ ...g, pct: Math.round((g.count / total) * 100) }))
})

const maxTurnaround = computed(() =>
  Math.max(...(data.value?.turnaround ?? []).map((g) => g.hours), 1)
)

/* ---------- Ekologiya ---------- */
const ecologyTotal = computed(() => {
  const e = data.value?.ecology
  if (!e) return 1
  return (e.critical + e.warning + e.normal) || 1
})

const PERIODS = [
  { value: 'month', labelKey: 'exec.periodMonth' },
  { value: 'quarter', labelKey: 'exec.periodQuarter' },
  { value: 'year', labelKey: 'exec.periodYear' },
]
</script>

<template>
  <div>
    <PageHeader :title="t('exec.title')" :subtitle="t('exec.subtitle')">
      <template #actions>
        <div class="flex items-center gap-2">
          <!-- Davr -->
          <div class="flex rounded-md border border-line overflow-hidden">
            <button
              v-for="p in PERIODS" :key="p.value"
              class="h-10 px-3 text-[13px] font-medium transition-colors"
              :class="filters.period === p.value
                ? 'bg-assay-500 text-white'
                : 'text-ink-muted hover:bg-surface-sunken'"
              @click="filters.period = p.value"
            >{{ t(p.labelKey) }}</button>
          </div>

          <!-- Laboratoriya -->
          <select v-model="filters.laboratory_id" class="field-input w-auto min-w-[170px]">
            <option :value="null">{{ t('exec.allLabs') }}</option>
            <option v-for="lab in laboratories" :key="lab.id" :value="lab.id">
              {{ localized(lab, 'name') }}
            </option>
          </select>
        </div>
      </template>
    </PageHeader>

    <!-- ======= ASOSIY KO'RSATKICHLAR ======= -->
    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4 mb-5">
      <div
        v-for="(card, i) in kpiCards" :key="card.key"
        class="panel p-4 animate-row-in"
        :class="card.route ? 'cursor-pointer transition-colors hover:border-ink-faint' : ''"
        :style="{ animationDelay: `${i * 50}ms` }"
        @click="card.route && router.push({ name: card.route })"
      >
        <div class="flex items-start justify-between gap-2 mb-3">
          <span class="h-8 w-8 rounded-md grid place-items-center" :class="TONES[card.tone]">
            <component :is="card.icon" :size="16" />
          </span>
          <span
            v-if="card.change != null"
            class="inline-flex items-center gap-0.5 text-[12px] font-semibold tnum"
            :class="changeTone(card.key, card.change)"
          >
            <component :is="card.change > 0 ? ArrowUp : card.change < 0 ? ArrowDown : ArrowRight" :size="12" />
            {{ Math.abs(card.change) }}%
          </span>
        </div>

        <p class="text-[28px] font-semibold text-ink tnum leading-none">
          <span v-if="loading" class="inline-block h-7 w-14 rounded bg-surface-sunken animate-pulse" />
          <template v-else>
            {{ card.value ?? '—' }}<span v-if="card.suffix && card.value != null" class="text-[15px] text-ink-muted ml-1">{{ card.suffix }}</span>
          </template>
        </p>
        <p class="mt-2 text-[12.5px] text-ink-muted leading-tight">{{ t(`exec.kpi.${card.key}`) }}</p>
        <p v-if="card.sub" class="mt-1 text-[11.5px] text-ink-faint">{{ card.sub }}</p>
      </div>
    </div>

    <!-- ======= DINAMIKA VA YUKLAMA ======= -->
    <div class="grid gap-5 lg:grid-cols-3 mb-5">
      <!-- Oylar bo'yicha -->
      <section class="panel p-4 lg:col-span-2">
        <header class="flex items-center justify-between gap-2 mb-4">
          <h2 class="text-[14px] font-semibold text-ink">{{ t('exec.monthlyTitle') }}</h2>
          <div class="flex items-center gap-3 text-[11.5px]">
            <span class="flex items-center gap-1.5">
              <span class="h-2 w-2 rounded-sm bg-assay-400" /> {{ t('exec.registered') }}
            </span>
            <span class="flex items-center gap-1.5">
              <span class="h-2 w-2 rounded-sm bg-ok" /> {{ t('exec.completed') }}
            </span>
          </div>
        </header>

        <div v-if="monthly.length" class="flex items-end gap-2 h-44">
          <div v-for="m in monthly" :key="m.month" class="flex-1 flex flex-col items-center gap-1.5 min-w-0">
            <span class="text-[11px] text-ink-muted tnum">{{ m.registered }}</span>
            <div class="w-full flex items-end gap-0.5 h-full">
              <div class="flex-1 rounded-t bg-assay-400/85 transition-[height] duration-700"
                   :style="{ height: `${Math.max(m.hReg, 2)}%` }" :title="`${t('exec.registered')}: ${m.registered}`" />
              <div class="flex-1 rounded-t bg-ok/80 transition-[height] duration-700"
                   :style="{ height: `${Math.max(m.hDone, 2)}%` }" :title="`${t('exec.completed')}: ${m.completed}`" />
            </div>
            <span class="text-[10px] text-ink-faint truncate w-full text-center">{{ m.label }}</span>
          </div>
        </div>
        <p v-else-if="!loading" class="text-[13px] text-ink-faint py-10 text-center">{{ t('common.noData') }}</p>
      </section>

      <!-- Uskunalar tayyorligi -->
      <section class="panel p-4">
        <h2 class="text-[14px] font-semibold text-ink mb-4">{{ t('exec.equipmentTitle') }}</h2>

        <div class="flex items-baseline gap-2 mb-4">
          <span class="text-[34px] font-semibold tnum leading-none"
                :class="(data?.equipment.readiness ?? 0) >= 80 ? 'text-ok' : (data?.equipment.readiness ?? 0) >= 50 ? 'text-warn' : 'text-crit'">
            {{ data?.equipment.readiness ?? 0 }}%
          </span>
          <span class="text-[12.5px] text-ink-muted">{{ t('exec.readiness') }}</span>
        </div>

        <div class="h-2 rounded-full bg-surface-sunken overflow-hidden mb-4">
          <div class="h-full rounded-full bg-ok transition-[width] duration-700"
               :style="{ width: `${data?.equipment.readiness ?? 0}%` }" />
        </div>

        <dl class="space-y-2.5">
          <div v-for="row in [
            ['working', data?.equipment.working, 'text-ok'],
            ['blocked', data?.equipment.blocked, 'text-crit'],
            ['waiting', data?.equipment.waiting, 'text-warn'],
            ['overdueCal', data?.equipment.overdue, 'text-crit'],
          ]" :key="row[0]" class="flex items-center justify-between gap-2">
            <dt class="text-[13px] text-ink-muted">{{ t(`exec.eq.${row[0]}`) }}</dt>
            <dd class="text-[14px] font-semibold tnum" :class="row[1] > 0 ? row[2] : 'text-ink-faint'">{{ row[1] ?? 0 }}</dd>
          </div>
        </dl>

        <button class="btn-ghost btn-sm w-full mt-3" @click="router.push({ name: 'equipment' })">
          <Microscope :size="14" /> {{ t('common.more') }}
        </button>
      </section>
    </div>

    <!-- ======= GURUHLAR ======= -->
    <div class="grid gap-5 lg:grid-cols-2 mb-5">
      <!-- Yuklama taqsimoti -->
      <section class="panel p-4">
        <h2 class="text-[14px] font-semibold text-ink mb-4">{{ t('exec.groupLoadTitle') }}</h2>

        <div v-if="groupLoad.length" class="space-y-3">
          <div v-for="g in groupLoad" :key="g.id">
            <div class="flex items-center justify-between gap-2 mb-1.5">
              <span class="text-[13px] text-ink truncate">{{ localized(g, 'name') }}</span>
              <span class="text-[13px] text-ink tnum font-medium shrink-0">
                {{ g.count }} <span class="text-ink-faint text-[11.5px]">· {{ g.pct }}%</span>
              </span>
            </div>
            <div class="h-1.5 rounded-full bg-surface-sunken overflow-hidden">
              <div class="h-full rounded-full bg-assay-400 transition-[width] duration-700"
                   :style="{ width: `${g.pct}%` }" />
            </div>
          </div>
        </div>
        <p v-else-if="!loading" class="text-[13px] text-ink-faint py-8 text-center">{{ t('common.noData') }}</p>
      </section>

      <!-- Bajarilish tezligi -->
      <section class="panel p-4">
        <h2 class="text-[14px] font-semibold text-ink mb-1">{{ t('exec.turnaroundTitle') }}</h2>
        <p class="text-[12px] text-ink-faint mb-4">{{ t('exec.turnaroundHint') }}</p>

        <div v-if="data?.turnaround?.length" class="space-y-3">
          <div v-for="g in data.turnaround" :key="g.id">
            <div class="flex items-center justify-between gap-2 mb-1.5">
              <span class="text-[13px] text-ink truncate">{{ localized(g, 'name') }}</span>
              <span class="text-[13px] tnum shrink-0">
                <span class="font-medium text-ink">{{ g.hours }}</span>
                <span class="text-ink-faint text-[11.5px]"> {{ t('exec.hours') }} · {{ g.count }} {{ t('exec.pcs') }}</span>
              </span>
            </div>
            <div class="h-1.5 rounded-full bg-surface-sunken overflow-hidden">
              <div class="h-full rounded-full bg-aqua-400 transition-[width] duration-700"
                   :style="{ width: `${Math.round((g.hours / maxTurnaround) * 100)}%` }" />
            </div>
          </div>
        </div>
        <p v-else-if="!loading" class="text-[13px] text-ink-faint py-8 text-center">{{ t('common.noData') }}</p>
      </section>
    </div>

    <!-- ======= EKOLOGIYA VA MUDDATLAR ======= -->
    <div class="grid gap-5 lg:grid-cols-3">
      <!-- Ekologiya -->
      <section class="panel p-4">
        <header class="flex items-center gap-2 mb-4">
          <Droplets :size="16" class="text-aqua-500" />
          <h2 class="text-[14px] font-semibold text-ink">{{ t('exec.ecologyTitle') }}</h2>
        </header>

        <div class="flex gap-1 h-2 rounded-full overflow-hidden mb-3">
          <div class="bg-ok transition-[width] duration-700"
               :style="{ width: `${((data?.ecology.normal ?? 0) / ecologyTotal) * 100}%` }" />
          <div class="bg-warn transition-[width] duration-700"
               :style="{ width: `${((data?.ecology.warning ?? 0) / ecologyTotal) * 100}%` }" />
          <div class="bg-crit transition-[width] duration-700"
               :style="{ width: `${((data?.ecology.critical ?? 0) / ecologyTotal) * 100}%` }" />
        </div>

        <dl class="grid grid-cols-3 gap-2 mb-4">
          <div v-for="row in [
            ['normal', data?.ecology.normal, 'text-ok'],
            ['warning', data?.ecology.warning, 'text-warn'],
            ['critical', data?.ecology.critical, 'text-crit'],
          ]" :key="row[0]">
            <dt class="text-[11px] text-ink-faint">{{ t(`status.${row[0]}`) }}</dt>
            <dd class="text-[16px] font-semibold tnum" :class="row[2]">{{ row[1] ?? 0 }}</dd>
          </div>
        </dl>

        <template v-if="data?.ecology.problem_points?.length">
          <p class="text-[11.5px] text-ink-faint mb-2">{{ t('exec.problemPoints') }}</p>
          <ul class="space-y-1.5">
            <li v-for="p in data.ecology.problem_points" :key="p.name"
                class="flex items-start justify-between gap-2 text-[12.5px]">
              <span class="text-ink truncate">{{ p.name }}</span>
              <span class="text-crit font-medium tnum shrink-0">{{ p.count }}</span>
            </li>
          </ul>
        </template>

        <button class="btn-ghost btn-sm w-full mt-3" @click="router.push({ name: 'ai-analysis' })">
          <TrendingUp :size="14" /> {{ t('menu.ai_analysis') }}
        </button>
      </section>

      <!-- Muddati o'tgan kalibrovkalar -->
      <section class="panel">
        <header class="flex items-center gap-2 px-4 py-3.5 border-b border-line">
          <CalendarClock :size="16" class="text-crit" />
          <h2 class="text-[14px] font-semibold text-ink">{{ t('exec.overdueCalibration') }}</h2>
        </header>

        <ul v-if="data?.overdue.equipment?.length" class="divide-y divide-line max-h-72 overflow-y-auto">
          <li v-for="eq in data.overdue.equipment" :key="eq.id"
              class="px-4 py-2.5 hover:bg-surface-sunken/60 transition-colors cursor-pointer"
              @click="router.push({ name: 'equipment-detail', params: { id: eq.id } })">
            <div class="flex items-center justify-between gap-2">
              <p class="text-[13px] font-medium text-ink truncate">{{ eq.name }}</p>
              <AlertTriangle v-if="eq.is_blocked" :size="13" class="text-crit shrink-0" />
            </div>
            <p class="text-[11.5px] text-ink-faint">{{ localized(eq.laboratory, 'name') }}</p>
            <p class="text-[12px] text-crit tnum">{{ formatDate(eq.next_calibration_at) }}</p>
          </li>
        </ul>
        <p v-else-if="!loading" class="text-[13px] text-ink-faint py-10 text-center px-4">{{ t('exec.noOverdue') }}</p>
      </section>

      <!-- Kechikkan probalar -->
      <section class="panel">
        <header class="flex items-center gap-2 px-4 py-3.5 border-b border-line">
          <Clock :size="16" class="text-warn" />
          <h2 class="text-[14px] font-semibold text-ink">{{ t('exec.overdueSamples') }}</h2>
        </header>

        <ul v-if="data?.overdue.samples?.length" class="divide-y divide-line max-h-72 overflow-y-auto">
          <li v-for="s in data.overdue.samples" :key="s.id"
              class="px-4 py-2.5 hover:bg-surface-sunken/60 transition-colors cursor-pointer"
              @click="router.push({ name: 'sample-detail', params: { id: s.id } })">
            <div class="flex items-center justify-between gap-2">
              <p class="text-[12.5px] font-mono font-medium text-ink">{{ s.code }}</p>
              <StatusBadge :status="s.status" size="sm" />
            </div>
            <p class="text-[11.5px] text-ink-faint truncate">{{ localized(s.assigned_group, 'name') }}</p>
            <p class="text-[12px] text-warn tnum">{{ formatDate(s.assigned_at) }}</p>
          </li>
        </ul>
        <p v-else-if="!loading" class="text-[13px] text-ink-faint py-10 text-center px-4">{{ t('exec.noOverdueSamples') }}</p>
      </section>
    </div>
  </div>
</template>
