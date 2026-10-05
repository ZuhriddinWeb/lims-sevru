<script setup>
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { Sparkles, TrendingDown, TrendingUp, Minus } from 'lucide-vue-next'
import api from '@/lib/api'
import PageHeader from '@/components/ui/PageHeader.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import StatusBadge from '@/components/ui/StatusBadge.vue'
import { useCatalogStore } from '@/stores/catalogs'
import { useUiStore } from '@/stores/ui'
import { formatNumber } from '@/lib/format'

const { t } = useI18n()
const catalogs = useCatalogStore()
const ui = useUiStore()

const filters = ref({ monitoring_point_id: null, parameter: '', date_from: '', date_to: '' })
const result = ref(null)
const loading = ref(false)
const ran = ref(false)

const points = computed(() => catalogs.get('monitoringPoints'))

onMounted(() => catalogs.load('monitoringPoints'))

async function run() {
  loading.value = true
  ran.value = true
  try {
    const payload = Object.fromEntries(
      Object.entries(filters.value).filter(([, v]) => v !== '' && v !== null)
    )
    const { data } = await api.post('/ai/analyze', payload)
    result.value = data
  } catch (e) {
    ui.error(e.response?.data?.message || t('toast.error'))
    result.value = null
  } finally {
    loading.value = false
  }
}

const VERDICT = {
  normal: { status: 'normal', key: 'verdictNormal' },
  attention: { status: 'warning', key: 'verdictAttention' },
  deterioration: { status: 'critical', key: 'verdictDeterioration' },
}

const TREND = {
  stable: { icon: Minus, key: 'trendStable', cls: 'text-ink-muted' },
  increasing: { icon: TrendingUp, key: 'trendIncreasing', cls: 'text-warn' },
  decreasing: { icon: TrendingDown, key: 'trendDecreasing', cls: 'text-aqua-600 dark:text-aqua-300' },
}

const summaryTone = computed(() => {
  const list = result.value?.analysis ?? []
  if (list.some((a) => a.verdict === 'deterioration')) return 'crit'
  if (list.some((a) => a.verdict === 'attention')) return 'warn'
  return 'ok'
})

const TONE_CLS = {
  ok:   'border-l-ok bg-ok-light/50 dark:bg-ok-dark/15',
  warn: 'border-l-warn bg-warn-light/50 dark:bg-warn-dark/15',
  crit: 'border-l-crit bg-crit-light/50 dark:bg-crit-dark/15',
}

// Qiymatlar ketma-ketligini sparkline SVG yo'liga aylantirish
function sparkPath(series, w = 260, h = 48) {
  if (!series?.length) return ''
  const values = series.map((s) => s.value)
  const min = Math.min(...values)
  const max = Math.max(...values)
  const span = max - min || 1
  const step = series.length > 1 ? w / (series.length - 1) : w
  return series
    .map((s, i) => {
      const x = i * step
      const y = h - ((s.value - min) / span) * (h - 8) - 4
      return `${i === 0 ? 'M' : 'L'}${x.toFixed(1)},${y.toFixed(1)}`
    })
    .join(' ')
}

function pointColor(assessment) {
  return assessment === 'critical' ? '#B33A3A' : assessment === 'warning' ? '#B5751F' : '#2F7A4F'
}

function sparkPoints(series, w = 260, h = 48) {
  if (!series?.length) return []
  const values = series.map((s) => s.value)
  const min = Math.min(...values)
  const max = Math.max(...values)
  const span = max - min || 1
  const step = series.length > 1 ? w / (series.length - 1) : w
  return series.map((s, i) => ({
    x: i * step,
    y: h - ((s.value - min) / span) * (h - 8) - 4,
    color: pointColor(s.assessment),
    value: s.value,
    date: s.date,
  }))
}
</script>

<template>
  <div>
    <PageHeader :title="t('ai.title')" :subtitle="t('ai.subtitle')" />

    <!-- Shartlar -->
    <section class="panel p-4 mb-5">
      <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
        <div class="lg:col-span-2">
          <label class="field-label">{{ t('ai.selectPoint') }}</label>
          <select v-model="filters.monitoring_point_id" class="field-input">
            <option :value="null">{{ t('ai.allPoints') }}</option>
            <option v-for="p in points" :key="p.id" :value="p.id">{{ p.code }} · {{ p.name }}</option>
          </select>
        </div>
        <div>
          <label class="field-label">{{ t('ai.parameter') }}</label>
          <input v-model="filters.parameter" class="field-input" :placeholder="t('ai.allParameters')" />
        </div>
        <div>
          <label class="field-label">{{ t('common.dateFrom') }}</label>
          <input v-model="filters.date_from" type="date" class="field-input tnum" />
        </div>
        <div>
          <label class="field-label">{{ t('common.dateTo') }}</label>
          <input v-model="filters.date_to" type="date" class="field-input tnum" />
        </div>
      </div>

      <div class="mt-4 flex justify-end">
        <button class="btn-primary" :disabled="loading" @click="run">
          <Sparkles :size="17" />
          {{ loading ? t('common.loading') : t('ai.runAnalysis') }}
        </button>
      </div>
    </section>

    <!-- Boshlang'ich holat -->
    <EmptyState v-if="!ran" :icon="Sparkles" :title="t('ai.emptyTitle')" :hint="t('ai.emptyHint')" />

    <!-- Ma'lumot yo'q -->
    <EmptyState v-else-if="result?.status === 'no_data'" :icon="Sparkles"
                :title="t('ai.noData')" :hint="t('ai.noDataHint')" />

    <template v-else-if="result?.analysis?.length">
      <!-- Xulosa -->
      <div class="panel border-l-[3px] p-4 mb-5" :class="TONE_CLS[summaryTone]">
        <p class="text-[11.5px] text-ink-faint mb-1">{{ t('ai.summary') }}</p>
        <p class="text-[14.5px] text-ink leading-relaxed">{{ result.summary }}</p>
        <p v-if="result.ai_text" class="mt-2.5 text-[13.5px] text-ink-muted leading-relaxed">{{ result.ai_text }}</p>
      </div>

      <!-- Ko'rsatkichlar bo'yicha -->
      <div class="grid gap-4 lg:grid-cols-2">
        <article
          v-for="(a, i) in result.analysis" :key="a.parameter"
          class="panel p-4 animate-row-in"
          :style="{ animationDelay: `${i * 50}ms` }"
        >
          <header class="flex items-start justify-between gap-3 mb-3.5">
            <div class="min-w-0">
              <h3 class="text-[15px] font-semibold text-ink truncate">{{ a.parameter }}</h3>
              <p class="text-[12px] text-ink-faint mt-0.5">
                {{ t('ai.measurements') }}: {{ a.measurements }}
              </p>
            </div>
            <StatusBadge :status="VERDICT[a.verdict].status" size="sm" />
          </header>

          <!-- Dinamika -->
          <svg viewBox="0 0 260 48" class="w-full h-12 mb-3.5" preserveAspectRatio="none" role="img"
               :aria-label="`${a.parameter}: ${t('ai.trend')}`">
            <path :d="sparkPath(a.series)" fill="none" stroke="currentColor"
                  class="text-assay-400" stroke-width="1.5" vector-effect="non-scaling-stroke" />
            <circle v-for="(p, j) in sparkPoints(a.series)" :key="j"
                    :cx="p.x" :cy="p.y" r="2.5" :fill="p.color" />
          </svg>

          <dl class="grid grid-cols-4 gap-3 pt-3 border-t border-line">
            <div>
              <dt class="text-[11px] text-ink-faint">{{ t('ai.average') }}</dt>
              <dd class="text-[13.5px] text-ink tnum font-medium">{{ formatNumber(a.average) }}</dd>
            </div>
            <div>
              <dt class="text-[11px] text-ink-faint">{{ t('ai.minValue') }}</dt>
              <dd class="text-[13.5px] text-ink-muted tnum">{{ formatNumber(a.min) }}</dd>
            </div>
            <div>
              <dt class="text-[11px] text-ink-faint">{{ t('ai.maxValue') }}</dt>
              <dd class="text-[13.5px] text-ink-muted tnum">{{ formatNumber(a.max) }}</dd>
            </div>
            <div>
              <dt class="text-[11px] text-ink-faint">{{ t('ai.change') }}</dt>
              <dd class="flex items-center gap-1 text-[13.5px] tnum font-medium" :class="TREND[a.trend].cls">
                <component :is="TREND[a.trend].icon" :size="13" />
                {{ a.change_percent > 0 ? '+' : '' }}{{ a.change_percent }}%
              </dd>
            </div>
          </dl>

          <p v-if="a.critical_count || a.warning_count" class="mt-3 text-[12.5px] text-ink-muted">
            <span v-if="a.critical_count" class="text-crit font-medium">{{ a.critical_count }}</span>
            <span v-if="a.critical_count"> {{ t('status.critical').toLowerCase() }}</span>
            <span v-if="a.critical_count && a.warning_count"> · </span>
            <span v-if="a.warning_count" class="text-warn font-medium">{{ a.warning_count }}</span>
            <span v-if="a.warning_count"> {{ t('status.warning').toLowerCase() }}</span>
          </p>
        </article>
      </div>
    </template>
  </div>
</template>
