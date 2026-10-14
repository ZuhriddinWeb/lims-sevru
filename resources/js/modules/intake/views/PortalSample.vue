<script setup>
/**
 * Buyurtmachi portali — bitta proba: bosqichlar, natijalar (kiritilishi bilanoq, «dastlabki» belgisi bilan),
 * o'tkazilgan tahlillar (ishchi varaqdagi FAQAT shu probaning parallellari), proba miqdori.
 */
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { ArrowLeft, CheckCircle2, Clock, FlaskConical, Lock, RotateCcw, Scale, ShieldCheck } from 'lucide-vue-next'
import api from '@/lib/api'
import EmptyState from '@/components/ui/EmptyState.vue'
import { formatDate, formatDateTime } from '@/lib/format'
import { localized } from '@/i18n'
import StageStepper from '../components/StageStepper.vue'
import ComparisonTable from '../components/ComparisonTable.vue'
import { apiErrorMessage, formatQty, formatValue, label, templateName, unitLabel } from '../helpers'
import '../i18n'

const route = useRoute()
const router = useRouter()
const { t, locale } = useI18n()

const data = ref(null)
const loading = ref(true)
const error = ref('')

async function load() {
  loading.value = true
  error.value = ''
  try {
    const res = await api.get(`/portal/samples/${route.params.id}`, {
      params: route.query.department_id ? { department_id: route.query.department_id } : {},
    })
    data.value = res.data
  } catch (e) {
    data.value = null
    error.value = apiErrorMessage(e, t('intake.loadError'))
  } finally {
    loading.value = false
  }
}

onMounted(load)
watch(() => route.params.id, () => route.params.id && load())

const sample = computed(() => data.value?.sample ?? null)
const analyses = computed(() => data.value?.analyses ?? [])
const qty = computed(() => data.value?.quantity ?? null)
const consumedTotal = computed(() => {
  const c = qty.value?.consumed
  return c ? (Number(c.main) || 0) + (Number(c.control) || 0) + (Number(c.other) || 0) : null
})

function back() {
  router.push({ name: 'portal', query: route.query.department_id ? { department_id: route.query.department_id } : {} })
}

const cellValue = (row, col) => {
  const v = row.values?.[col.key]
  if (v === null || v === undefined || v === '') return ''
  return col.type === 'text' ? String(v) : formatValue(v, col.decimals, locale.value)
}
const summaryValue = (block, col) => formatValue(block.summary?.[col.key], col.decimals, locale.value)
const stageDoneCount = (b) => b.stages.filter((s) => s.done).length
</script>

<template>
  <div v-if="loading" class="space-y-4">
    <div class="h-8 w-56 rounded bg-surface-sunken animate-pulse" />
    <div class="h-28 panel animate-pulse" />
    <div class="h-48 panel animate-pulse" />
  </div>

  <div v-else-if="error" class="panel p-6 text-center">
    <p class="text-[14px] text-crit" data-error>{{ error }}</p>
    <button class="btn-outline mt-4" @click="back"><ArrowLeft :size="16" /> {{ t('intake.portal.back') }}</button>
  </div>

  <div v-else-if="sample">
    <!-- Sarlavha -->
    <div class="flex flex-wrap items-start justify-between gap-4 mb-5">
      <div class="flex items-start gap-3 min-w-0">
        <button class="btn-ghost btn-icon mt-0.5" :title="t('intake.portal.back')" @click="back">
          <ArrowLeft :size="18" />
        </button>
        <div class="min-w-0">
          <h1 class="text-[22px] font-semibold tracking-[-.01em] text-ink" data-title>{{ sample.customer_code || sample.code }}</h1>
          <p class="mt-1 text-[13.5px] text-ink-muted">
            {{ t('intake.portal.labCode') }}: <span class="font-mono text-ink">{{ sample.code }}</span>
            <template v-if="sample.department"> · {{ sample.department.name }}</template>
            · {{ t('intake.portal.registered') }}: {{ formatDate(sample.registered_at) }}
          </p>
        </div>
      </div>
      <div class="flex items-center gap-3">
        <span class="inline-flex items-center gap-1.5 rounded-full bg-surface-sunken px-2.5 py-1 text-[12px] text-ink-muted">
          <Lock :size="13" /> {{ t('intake.portal.readOnly') }}
        </span>
        <div class="text-right">
          <p class="text-[22px] font-semibold tnum leading-none" :class="sample.progress === 100 ? 'text-ok' : 'text-ink'">{{ sample.progress }}%</p>
          <p class="text-[12px] text-ink-faint mt-1">{{ t('intake.portal.progress') }}</p>
        </div>
      </div>
    </div>

    <!-- Bosqichlar -->
    <section class="panel p-4 mb-5 overflow-x-auto">
      <StageStepper :steps="data.steps.steps" :current="data.steps.current" class="min-w-[520px]" />
    </section>

    <div class="grid gap-5 lg:grid-cols-[1fr_300px] mb-5">
      <!-- Natijalar -->
      <section class="panel overflow-hidden">
        <header class="px-4 py-3.5 border-b border-line">
          <h2 class="text-[14px] font-semibold text-ink">{{ t('intake.portal.results') }}</h2>
        </header>
        <table class="w-full text-left text-[13.5px]" data-results>
          <thead>
            <tr class="border-b border-line bg-surface-sunken/50 text-[12.5px] font-semibold text-ink-muted">
              <th class="px-4 py-2.5">{{ t('intake.portal.element') }}</th>
              <th class="px-4 py-2.5 text-right">{{ t('intake.portal.value') }}</th>
              <th class="px-4 py-2.5" />
            </tr>
          </thead>
          <tbody class="divide-y divide-line">
            <tr v-for="(r, i) in data.results" :key="i">
              <td class="px-4 py-2.5">
                <span class="font-mono text-[12.5px] font-medium text-assay-600 dark:text-assay-300 mr-2">{{ r.element?.symbol }}</span>
                <span class="text-ink">{{ localized(r.element, 'name') }}</span>
              </td>
              <td class="px-4 py-2.5 text-right tnum">
                <template v-if="r.value !== null">
                  <span class="font-semibold text-ink">{{ formatValue(r.value, null, locale) }}</span>
                  <span class="text-ink-muted"> {{ r.unit }}</span>
                </template>
                <template v-else-if="r.preliminary">
                  <span class="font-medium text-ink">{{ formatValue(r.preliminary.value, r.preliminary.decimals, locale) }}</span>
                  <span class="text-ink-muted"> {{ r.preliminary.unit }}</span>
                </template>
                <span v-else class="text-ink-faint">—</span>
              </td>
              <td class="px-4 py-2.5 text-right whitespace-nowrap">
                <span v-if="r.value !== null" class="inline-flex items-center gap-1 rounded-full bg-ok-light text-ok-dark dark:bg-ok-dark/25 dark:text-ok-light px-2 py-0.5 text-[11.5px] font-medium">
                  <CheckCircle2 :size="12" /> {{ t('intake.portal.final') }}
                </span>
                <span
                  v-else-if="r.preliminary"
                  class="inline-flex items-center gap-1 rounded-full bg-warn-light text-warn-dark dark:bg-warn-dark/25 dark:text-warn-light px-2 py-0.5 text-[11.5px] font-medium"
                  :title="t('intake.portal.preliminaryHint', { n: r.preliminary.worksheet })"
                  data-preliminary
                >
                  <Clock :size="12" /> {{ t('intake.portal.preliminary') }}
                </span>
                <span v-else class="text-[12px] text-ink-faint">{{ t('intake.portal.waiting') }}</span>
              </td>
            </tr>
          </tbody>
        </table>
      </section>

      <!-- Miqdor -->
      <aside class="panel p-4 h-fit" data-quantity>
        <h2 class="flex items-center gap-2 text-[13px] font-semibold text-ink mb-3">
          <Scale :size="15" class="text-assay-500" /> {{ t('intake.portal.quantity') }}
        </h2>
        <dl v-if="qty && qty.received !== null" class="space-y-2.5 text-[13.5px]">
          <div class="flex justify-between gap-3">
            <dt class="text-ink-muted">{{ t('intake.portal.received') }}</dt>
            <dd class="tnum font-medium text-ink">{{ formatQty(qty.received, locale) }} {{ unitLabel(qty.unit, t) }}</dd>
          </div>
          <div class="flex justify-between gap-3">
            <dt class="text-ink-muted">{{ t('intake.portal.consumed') }}</dt>
            <dd class="tnum text-ink">{{ formatQty(consumedTotal, locale) }} {{ unitLabel(qty.unit, t) }}</dd>
          </div>
          <div class="flex justify-between gap-3 pt-2 border-t border-line">
            <dt class="text-ink-muted">{{ t('intake.portal.left') }}</dt>
            <dd class="tnum font-semibold" :class="qty.balance < 0 ? 'text-crit' : 'text-ink'">{{ formatQty(qty.balance, locale) }} {{ unitLabel(qty.unit, t) }}</dd>
          </div>
        </dl>
        <p v-else class="text-[13px] text-ink-faint">—</p>
      </aside>
    </div>

    <!-- O'tkazilgan tahlillar -->
    <h2 class="text-[15px] font-semibold text-ink mb-3">{{ t('intake.portal.analyses') }}</h2>
    <div v-if="!analyses.length" class="panel">
      <EmptyState :icon="FlaskConical" :title="t('intake.portal.noAnalyses')" />
    </div>

    <section v-for="b in analyses" :key="b.id" class="panel mb-4 overflow-hidden" :data-analysis="b.id">
      <header class="flex flex-wrap items-center justify-between gap-2 px-4 py-3 border-b border-line">
        <div class="min-w-0">
          <p class="flex flex-wrap items-center gap-2 text-[14px] font-semibold text-ink">
            {{ t('intake.portal.worksheet', { n: b.number }) }}
            <span
              class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11.5px] font-medium"
              :class="b.kind === 'control' ? 'bg-aqua-50 text-aqua-700 dark:bg-aqua-800/35 dark:text-aqua-200' : 'bg-assay-50 text-assay-700 dark:bg-assay-800/30 dark:text-assay-200'"
            >
              <ShieldCheck v-if="b.kind === 'control'" :size="12" />
              {{ b.kind === 'control' ? t('intake.portal.controlAnalysis') : t('intake.portal.mainAnalysis') }}
            </span>
          </p>
          <p class="text-[12.5px] text-ink-muted">
            {{ templateName(b.template, locale) }} <span class="font-mono text-ink-faint">· {{ b.template.code }}</span>
            · {{ formatDate(b.worksheet_date) }}
          </p>
        </div>
        <span
          class="rounded-full px-2.5 py-1 text-[12px] font-medium"
          :class="b.status === 'approved' ? 'bg-ok-light text-ok-dark dark:bg-ok-dark/25 dark:text-ok-light' : 'bg-warn-light text-warn-dark dark:bg-warn-dark/25 dark:text-warn-light'"
          data-ws-status
        >{{ b.status === 'approved' ? t('intake.portal.approvedAt', { d: formatDateTime(b.approved_at) }) : t(`intake.portal.wsStatus.${b.status}`) }}</span>
      </header>

      <div class="px-4 py-3 space-y-3">
        <p v-if="b.member_status === 'retest'" class="flex items-center gap-1.5 text-[13px] text-warn-dark dark:text-warn-light">
          <RotateCcw :size="14" /> {{ t('intake.portal.retest') }}
        </p>

        <!-- Bosqichlar (varaqdagi imzolar) -->
        <div v-if="b.stages.length" class="flex flex-wrap items-center gap-1.5" data-stages>
          <span class="text-[12px] text-ink-muted mr-1">{{ t('intake.portal.stages') }} ({{ stageDoneCount(b) }}/{{ b.stages.length }}):</span>
          <span
            v-for="s in b.stages" :key="s.key"
            class="inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-[12px]"
            :class="s.done ? 'border-ok/40 bg-ok-light/60 text-ok-dark dark:bg-ok-dark/20 dark:text-ok-light' : 'border-line text-ink-faint'"
            :data-stage="s.key" :data-done="s.done"
          >
            <CheckCircle2 v-if="s.done" :size="12" />
            {{ label(s, locale) }}<template v-if="s.date"> · {{ formatDate(s.date) }}</template>
          </span>
        </div>

        <!-- Parametrlar -->
        <p v-if="b.params.length" class="text-[12.5px] text-ink-muted">
          {{ t('intake.portal.params') }}:
          <template v-for="(p, i) in b.params" :key="p.key">
            <span v-if="i">; </span>{{ label(p, locale) }} = <span class="tnum text-ink">{{ formatValue(p.value, p.decimals, locale) || '—' }}</span> {{ p.unit }}
          </template>
        </p>

        <!-- Parallellar -->
        <div class="overflow-x-auto rounded-md border border-line">
          <table class="w-full text-[13px]" data-rows>
            <thead>
              <tr class="bg-surface-sunken/60 text-left text-[12px] text-ink-muted">
                <th class="px-3 py-2 font-medium whitespace-nowrap">{{ t('intake.portal.parallel') }}</th>
                <th v-for="c in b.columns" :key="c.key" class="px-3 py-2 font-medium text-right">
                  {{ label(c, locale) }}
                </th>
              </tr>
            </thead>
            <tbody class="divide-y divide-line">
              <tr v-for="(r, i) in b.rows" :key="i" :class="r.excluded ? 'text-ink-faint' : 'text-ink'">
                <td class="px-3 py-1.5 whitespace-nowrap">
                  {{ r.parallel_no }}
                  <span v-if="r.excluded" class="ml-1 text-[11px]" :title="r.note || ''">({{ t('intake.portal.excluded') }})</span>
                  <span v-if="r.is_extra" class="ml-1 text-[11px] text-ink-faint">({{ t('intake.portal.extra') }})</span>
                </td>
                <td v-for="c in b.columns" :key="c.key" class="px-3 py-1.5 text-right tnum" :class="r.excluded && 'line-through'">
                  {{ cellValue(r, c) }}
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- Natija (shu proba bo'yicha) -->
        <div v-if="b.summary_columns.length" class="flex flex-wrap gap-2" data-summary>
          <div v-for="c in b.summary_columns" :key="c.key" class="rounded-md bg-surface-sunken px-3 py-2">
            <p class="text-[11.5px] text-ink-muted">{{ label(c, locale) }}</p>
            <p class="text-[15px] font-semibold tnum text-ink">
              {{ summaryValue(b, c) || '—' }} <span class="text-[12px] font-normal text-ink-muted">{{ c.unit }}</span>
            </p>
          </div>
        </div>
      </div>
    </section>

    <!-- Nazorat bilan solishtirish -->
    <section v-if="data.comparison?.length" class="panel p-4 mt-5">
      <h2 class="flex items-center gap-2 text-[14px] font-semibold text-ink mb-3">
        <ShieldCheck :size="16" class="text-aqua-600" /> {{ t('intake.portal.comparison') }}
      </h2>
      <ComparisonTable :items="data.comparison" />
    </section>
  </div>
</template>
