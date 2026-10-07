<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import {
  Atom, Building2, CalendarRange, Download, Droplets,
  Microscope, Printer, Users,
} from 'lucide-vue-next'
import api from '@/lib/api'
import PageHeader from '@/components/ui/PageHeader.vue'
import StatusBadge from '@/components/ui/StatusBadge.vue'
import TableSkeleton from '@/components/ui/TableSkeleton.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import { useCatalogStore } from '@/stores/catalogs'
import { useUiStore } from '@/stores/ui'
import { formatNumber } from '@/lib/format'
import { localized } from '@/i18n'

const { t, te, locale } = useI18n()
const catalogs = useCatalogStore()
const ui = useUiStore()

const data = ref(null)
const loading = ref(true)

const filters = ref({ type: 'monthly', period: 'month', laboratory_id: null })

const laboratories = computed(() => catalogs.get('laboratories'))

/* Hisobot turlari */
const TYPES = [
  { value: 'monthly',      icon: CalendarRange, labelKey: 'rep.type.monthly' },
  { value: 'groups',       icon: Users,         labelKey: 'rep.type.groups' },
  { value: 'laboratories', icon: Building2,     labelKey: 'rep.type.laboratories' },
  { value: 'elements',     icon: Atom,          labelKey: 'rep.type.elements' },
  { value: 'equipment',    icon: Microscope,    labelKey: 'rep.type.equipment' },
  { value: 'ecology',      icon: Droplets,      labelKey: 'rep.type.ecology' },
]

const PERIODS = [
  { value: 'month',   labelKey: 'exec.periodMonth' },
  { value: 'quarter', labelKey: 'exec.periodQuarter' },
  { value: 'year',    labelKey: 'exec.periodYear' },
]

// Oylik hisobot har doim 12 oyni ko'rsatadi — davr filtri unga ta'sir qilmaydi
const periodApplies = computed(() => filters.value.type !== 'monthly')

async function load() {
  loading.value = true
  try {
    const params = { ...filters.value, locale: locale.value }
    Object.keys(params).forEach((k) => (params[k] == null || params[k] === '') && delete params[k])
    const { data: res } = await api.get('/management-reports', { params })
    data.value = res
  } catch (e) {
    ui.error(e.response?.data?.message || t('toast.error'))
    data.value = null
  } finally {
    loading.value = false
  }
}

onMounted(() => {
  catalogs.load('laboratories')
  load()
})

watch(filters, load, { deep: true })

/* Katak qiymatini ko'rsatishga tayyorlash */
function cell(row, col) {
  const v = row[col.key]
  if (v === null || v === undefined || v === '') return '—'
  if (col.type === 'pointType') return te(`monitoring.${v}`) ? t(`monitoring.${v}`) : v
  if (typeof v === 'number' && !Number.isInteger(v)) return formatNumber(v, 4)
  return v
}

function colLabel(col) {
  return te(col.labelKey) ? t(col.labelKey) : col.labelKey
}

// Kalibrovkagacha qolgan kunlar rangi
function daysTone(n) {
  if (n === null || n === undefined) return 'text-ink-faint'
  if (n < 0) return 'text-crit font-medium'
  if (n <= 7) return 'text-warn font-medium'
  return 'text-ink-muted'
}

function exportFile() {
  const params = new URLSearchParams({ ...filters.value, locale: locale.value })
  Object.entries(filters.value).forEach(([k, v]) => {
    if (v == null || v === '') params.delete(k)
  })
  window.open(`/api/management-reports/export?${params.toString()}`, '_blank')
  ui.info(t('report.exported'))
}

function printReport() {
  window.print()
}

const activeType = computed(() => TYPES.find((x) => x.value === filters.value.type))
const periodLabel = computed(() => {
  const p = data.value?.period
  if (!p) return ''
  return `${p.from} — ${p.to}`
})
</script>

<template>
  <div>
    <PageHeader :title="t('rep.title')" :subtitle="t('rep.subtitle')">
      <template #actions>
        <button class="btn-outline print:hidden" @click="printReport">
          <Printer :size="16" />
          <span class="hidden sm:inline">{{ t('report.print') }}</span>
        </button>
        <button class="btn-primary print:hidden" @click="exportFile">
          <Download :size="16" />
          <span class="hidden sm:inline">{{ t('report.export') }}</span>
        </button>
      </template>
    </PageHeader>

    <!-- Hisobot turlari -->
    <div class="flex flex-wrap gap-1.5 mb-4 print:hidden">
      <button
        v-for="tp in TYPES" :key="tp.value"
        class="inline-flex items-center gap-2 h-9 px-3 rounded-md text-[13px] font-medium transition-colors border"
        :class="filters.type === tp.value
          ? 'bg-assay-500 border-assay-500 text-white'
          : 'border-line text-ink-muted hover:bg-surface-sunken hover:text-ink'"
        @click="filters.type = tp.value"
      >
        <component :is="tp.icon" :size="15" />
        {{ t(tp.labelKey) }}
      </button>
    </div>

    <!-- Filtrlar -->
    <div class="flex flex-wrap items-center gap-2 mb-4 print:hidden">
      <div v-if="periodApplies" class="flex rounded-md border border-line overflow-hidden">
        <button
          v-for="p in PERIODS" :key="p.value"
          class="h-10 px-3 text-[13px] font-medium transition-colors"
          :class="filters.period === p.value
            ? 'bg-assay-500 text-white'
            : 'text-ink-muted hover:bg-surface-sunken'"
          @click="filters.period = p.value"
        >{{ t(p.labelKey) }}</button>
      </div>

      <select
        v-if="['monthly', 'elements', 'equipment'].includes(filters.type)"
        v-model="filters.laboratory_id"
        class="field-input w-auto min-w-[180px]"
      >
        <option :value="null">{{ t('exec.allLabs') }}</option>
        <option v-for="lab in laboratories" :key="lab.id" :value="lab.id">
          {{ localized(lab, 'name') }}
        </option>
      </select>
    </div>

    <!-- Chop etish uchun sarlavha (faqat bosmada ko'rinadi) -->
    <div class="hidden print:block mb-4">
      <h1 class="text-[16pt] font-bold">{{ t(activeType?.labelKey) }}</h1>
      <p class="text-[10pt] text-ink-muted">{{ periodLabel }}</p>
    </div>

    <!-- Jadval -->
    <div class="panel overflow-hidden">
      <TableSkeleton v-if="loading && !data" :cols="6" :rows="8" />

      <EmptyState
        v-else-if="!data?.rows?.length"
        :title="t('common.noData')"
        :hint="t('rep.noDataHint')"
      />

      <div v-else class="overflow-x-auto">
        <table class="w-full text-left border-collapse">
          <thead>
            <tr class="border-b border-line bg-surface-sunken/60">
              <th
                v-for="col in data.columns" :key="col.key"
                class="px-4 py-2.5 text-[12.5px] font-semibold text-ink-muted whitespace-nowrap"
                :class="col.align === 'right' ? 'text-right' : ''"
              >{{ colLabel(col) }}</th>
            </tr>
          </thead>

          <tbody class="divide-y divide-line">
            <tr
              v-for="(row, i) in data.rows" :key="i"
              class="hover:bg-surface-sunken/50 transition-colors animate-row-in"
              :style="{ animationDelay: `${Math.min(i * 18, 200)}ms` }"
            >
              <td
                v-for="col in data.columns" :key="col.key"
                class="px-4 py-2.5 text-[13.5px]"
                :class="[
                  col.align === 'right' ? 'text-right tnum' : '',
                  col.mono ? 'font-mono text-[12.5px]' : '',
                  col.key === data.columns[0].key ? 'text-ink font-medium' : 'text-ink-muted',
                ]"
              >
                <!-- Uskuna holati -->
                <template v-if="col.type === 'status'">
                  <span class="inline-flex items-center gap-1.5">
                    <StatusBadge :status="row[col.key]" size="sm" />
                    <StatusBadge v-if="row.blocked" status="critical" size="sm" />
                  </span>
                </template>

                <!-- Qolgan kunlar -->
                <span v-else-if="col.type === 'days'" :class="daysTone(row[col.key])">
                  {{ row[col.key] === null || row[col.key] === undefined ? '—' : row[col.key] }}
                </span>

                <!-- Kritik/ogohlantirish ustunlari rangli -->
                <span
                  v-else-if="col.key === 'critical'"
                  :class="row.critical > 0 ? 'text-crit font-medium' : 'text-ink-faint'"
                >{{ row.critical }}</span>
                <span
                  v-else-if="col.key === 'warning'"
                  :class="row.warning > 0 ? 'text-warn font-medium' : 'text-ink-faint'"
                >{{ row.warning }}</span>
                <span
                  v-else-if="col.key === 'eq_blocked' || col.key === 'pending' || col.key === 'in_work'"
                  :class="row[col.key] > 0 ? 'text-warn' : 'text-ink-faint'"
                >{{ row[col.key] }}</span>

                <!-- Bajarilish foizi -->
                <span
                  v-else-if="col.key === 'percent' || col.key === 'eq_readiness'"
                  :class="row[col.key] >= 80 ? 'text-ok font-medium' : row[col.key] >= 50 ? 'text-warn' : 'text-crit'"
                >{{ cell(row, col) }}{{ col.suffix ?? '' }}</span>

                <template v-else>{{ cell(row, col) }}{{ col.suffix && row[col.key] != null ? col.suffix : '' }}</template>
              </td>
            </tr>
          </tbody>

          <!-- Jami -->
          <tfoot v-if="data.totals">
            <tr class="border-t-2 border-ink bg-surface-sunken/70">
              <td
                v-for="col in data.columns" :key="col.key"
                class="px-4 py-3 text-[13.5px] font-semibold text-ink"
                :class="col.align === 'right' ? 'text-right tnum' : ''"
              >
                <template v-if="data.totals[col.key] === 'rep.total'">{{ t('rep.total') }}</template>
                <template v-else-if="data.totals[col.key] !== null && data.totals[col.key] !== undefined">
                  {{ data.totals[col.key] }}
                </template>
                <template v-else>&nbsp;</template>
              </td>
            </tr>
          </tfoot>
        </table>
      </div>
    </div>

    <p v-if="data?.rows?.length" class="mt-3 text-[12px] text-ink-faint">
      {{ t('rep.rowsCount', { n: data.rows.length }) }} · {{ periodLabel }}
    </p>
  </div>
</template>

<style>
@media print {
  aside, header.sticky { display: none !important; }
  main { padding: 0 !important; }
  .panel { box-shadow: none !important; border: none !important; }
  table { font-size: 9pt !important; }
  thead { display: table-header-group; }
  tr { page-break-inside: avoid; }
}
</style>
