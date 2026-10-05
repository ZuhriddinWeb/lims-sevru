<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { ArrowLeft, Printer } from 'lucide-vue-next'
import api from '@/lib/api'
import { formatDate, formatDateTime, formatNumber } from '@/lib/format'
import { localized } from '@/i18n'

const route = useRoute()
const router = useRouter()
const { t } = useI18n()

const data = ref(null)
const loading = ref(true)

onMounted(async () => {
  try {
    const res = await api.get(`/reports/sample-protocol/${route.params.id}`)
    data.value = res.data
  } finally {
    loading.value = false
  }
})

const sample = computed(() => data.value?.sample)

function print() {
  window.print()
}

const header = computed(() => {
  const s = sample.value
  if (!s) return []
  return [
    [t('sample.code'), s.code],
    [t('sample.number'), s.sample_number],
    [t('sample.sourceDocument'), s.source_document],
    [t('sample.sourceDivision'), s.source_division],
    [t('sample.customerGroup'), localized(s.customer_group, 'name')],
    [t('sample.assignedGroup'), localized(s.assigned_group, 'name')],
    [t('sample.stateOfMatter'), t(`sample.${s.state_of_matter}`)],
    [t('sample.registeredAt'), formatDateTime(s.registered_at)],
    [t('sample.completedAt'), formatDateTime(s.completed_at)],
  ].filter(([, v]) => v)
})
</script>

<template>
  <div>
    <!-- Boshqaruv paneli — chop etishda ko'rinmaydi -->
    <div class="flex items-center justify-between gap-3 mb-5 print:hidden">
      <button class="btn-ghost" @click="router.back()">
        <ArrowLeft :size="18" /> {{ t('common.back') }}
      </button>
      <button class="btn-primary" @click="print">
        <Printer :size="17" /> {{ t('report.print') }}
      </button>
    </div>

    <div v-if="loading" class="h-80 panel animate-pulse" />

    <!-- Bayonnoma varaqasi -->
    <article
      v-else-if="sample"
      class="panel p-8 max-w-[860px] mx-auto print:border-0 print:shadow-none print:p-0 print:max-w-none"
    >
      <header class="text-center pb-5 border-b-2 border-ink">
        <p class="text-[13px] text-ink-muted">{{ t('auth.signInSubtitle') }}</p>
        <h1 class="text-[20px] font-semibold text-ink mt-2">{{ t('report.protocol') }}</h1>
        <p class="text-[15px] font-mono text-ink mt-1">{{ sample.code }}</p>
      </header>

      <!-- Proba ma'lumotlari -->
      <table class="w-full mt-5 text-[13px]">
        <tbody>
          <tr v-for="[label, value] in header" :key="label" class="align-top">
            <td class="py-1.5 pr-4 text-ink-muted w-[42%]">{{ label }}</td>
            <td class="py-1.5 text-ink font-medium">{{ value }}</td>
          </tr>
        </tbody>
      </table>

      <!-- Natijalar jadvali -->
      <h2 class="text-[14px] font-semibold text-ink mt-7 mb-2.5">{{ t('sample.results') }}</h2>
      <table class="w-full text-[13px] border-collapse">
        <thead>
          <tr class="border-y border-ink text-[12px] text-ink-muted">
            <th class="py-2 text-left font-semibold w-8">№</th>
            <th class="py-2 text-left font-semibold">{{ t('water.parameter') }}</th>
            <th class="py-2 text-right font-semibold">{{ t('sample.resultValue') }}</th>
            <th class="py-2 text-left font-semibold pl-3">{{ t('sample.resultUnit') }}</th>
            <th class="py-2 text-left font-semibold">{{ t('usage.method') }}</th>
            <th class="py-2 text-left font-semibold">{{ t('usage.equipment') }}</th>
            <th class="py-2 text-left font-semibold">{{ t('common.date') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="(se, i) in sample.sample_elements" :key="se.id" class="border-b border-line">
            <td class="py-2 text-ink-muted tnum">{{ i + 1 }}</td>
            <td class="py-2 text-ink">
              <span class="font-mono font-medium">{{ se.element?.symbol }}</span>
              <span class="text-ink-muted"> · {{ localized(se.element, 'name') }}</span>
            </td>
            <td class="py-2 text-right text-ink font-medium tnum">
              {{ se.status === 'done' ? formatNumber(se.result_value) : '—' }}
            </td>
            <td class="py-2 pl-3 text-ink-muted">{{ se.result_unit ?? '—' }}</td>
            <td class="py-2 text-ink-muted">{{ se.method ?? '—' }}</td>
            <td class="py-2 text-ink-muted">{{ se.equipment?.name ?? '—' }}</td>
            <td class="py-2 text-ink-muted tnum">{{ formatDate(se.result_entered_at) }}</td>
          </tr>
        </tbody>
      </table>

      <!-- Imzolar -->
      <div class="grid grid-cols-2 gap-10 mt-12 text-[13px]">
        <div>
          <p class="text-ink-muted">{{ t('report.executor') }}</p>
          <div class="mt-8 border-b border-ink" />
          <p class="mt-1.5 text-[11.5px] text-ink-faint">{{ t('report.signature') }}</p>
        </div>
        <div>
          <p class="text-ink-muted">{{ t('report.approver') }}</p>
          <div class="mt-8 border-b border-ink" />
          <p class="mt-1.5 text-[11.5px] text-ink-faint">{{ t('report.signature') }}</p>
        </div>
      </div>

      <footer class="mt-8 pt-3 border-t border-line text-[11.5px] text-ink-faint flex justify-between">
        <span>{{ t('report.generatedBy') }}: {{ data.generated_by }}</span>
        <span>{{ t('report.generatedAt') }}: {{ formatDateTime(data.generated_at) }}</span>
      </footer>
    </article>
  </div>
</template>

<style>
@media print {
  body { background: #fff !important; }
  /* Sahifa chetlari va sidebar chop etishda olib tashlanadi */
  aside, header.sticky { display: none !important; }
  main { padding: 0 !important; }
  .panel { box-shadow: none !important; border: none !important; }
}
</style>
