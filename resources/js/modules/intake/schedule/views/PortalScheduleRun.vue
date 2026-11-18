<script setup>
/**
 * [Paket 36] Buyurtmachi — bitta grafik partiyasi: rejadagi namunalar, qabul ma'lumoti va natijalar.
 * Natijalar «Mening probalarim» bilan bir xil qoidada (/portal/samples?run_id=) — jamlanma hisobot qulfi ham.
 */
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { ArrowLeft, Hourglass, Lock } from 'lucide-vue-next'
import api from '@/lib/api'
import PageHeader from '@/components/ui/PageHeader.vue'
import { useUiStore } from '@/stores/ui'
import { formatValue } from '../../helpers'
import { apiErrorMessage } from '../../requests/shared'
import { RUN_TONE, niceDate } from '../shared'
import '../../i18n'
import '../i18n'

const route = useRoute()
const router = useRouter()
const ui = useUiStore()
const { t, locale } = useI18n()

const run = ref(null)
const samples = ref([])
const loading = ref(true)
const loadError = ref('')

const CHIP = {
  final: 'bg-ok-light text-ok-dark dark:bg-ok-dark/25 dark:text-ok-light',
  preliminary: 'bg-warn-light text-warn-dark dark:bg-warn-dark/25 dark:text-warn-light border border-dashed border-warn/50',
  hidden: 'bg-surface-sunken text-ink-muted',
  waiting: 'bg-surface-sunken text-ink-muted',
  locked: 'bg-aqua-50 text-aqua-700 dark:bg-aqua-800/30 dark:text-aqua-200 border border-aqua-300/60',
}

onMounted(async () => {
  try {
    const { data } = await api.get(`/portal/schedule-runs/${route.params.id}`)
    run.value = data
    if (data.request_id) {
      const r = await api.get('/portal/samples', { params: { run_id: data.id, status: 'all', per_page: 50 } })
      samples.value = r.data.data ?? []
    }
  } catch (e) {
    loadError.value = apiErrorMessage(e, t('sched.loadError'))
    ui.error(loadError.value)
  } finally {
    loading.value = false
  }
})

const title = computed(() => (run.value ? t('sched.runTitle', { time: run.value.time, name: run.value.name || '' }) : '…'))
const timeOf = (dt) => (dt ? dt.slice(11, 16) : '')
</script>

<template>
  <div>
    <PageHeader :title="title">
      <template #actions>
        <button class="btn-ghost" @click="router.push({ name: 'portal-schedule', query: { tab: 'day', date: run?.date } })"><ArrowLeft :size="16" /> {{ t('sched.back') }}</button>
      </template>
    </PageHeader>

    <div v-if="loading" class="h-40 rounded-panel bg-surface-sunken animate-pulse" />
    <p v-else-if="loadError" class="panel p-4 text-[14px] text-crit">{{ loadError }}</p>

    <template v-else-if="run">
      <div class="panel p-4 mb-4 flex flex-wrap items-center gap-x-6 gap-y-2 text-[13.5px]">
        <span class="font-medium text-ink">{{ niceDate(run.date, locale) }}</span>
        <span class="rounded px-2 py-0.5 text-[12px] font-medium" :class="RUN_TONE[run.status]" data-status>{{ t(`sched.status.${run.status}`) }}</span>
        <span class="text-ink-muted">{{ t('sched.samples', { n: run.items_count }) }}</span>
        <template v-if="run.arrived_at">
          <span class="text-ok-dark dark:text-ok-light">{{ t('sched.receivedLine', { n: run.received_count, time: timeOf(run.arrived_at) }) }}</span>
          <span v-if="run.missing_count" class="text-crit">{{ t('sched.missingLine', { n: run.missing_count }) }}</span>
          <span v-if="run.received_by" class="text-ink-muted">{{ t('sched.receivedBy', { name: run.received_by }) }}</span>
        </template>
        <span v-if="run.department" class="text-ink-muted">{{ run.department.name }}</span>
        <span v-if="run.note" class="italic text-ink-muted">{{ run.note }}</span>
      </div>

      <!-- natijalar -->
      <section class="panel overflow-hidden mb-4" data-run-results>
        <h2 class="px-4 py-3 border-b border-line text-[14px] font-semibold text-ink">{{ t('sched.results') }}</h2>
        <p v-if="!samples.length" class="px-4 py-4 text-[13.5px] text-ink-faint">{{ t('sched.resultsWait') }}</p>
        <div v-else class="overflow-x-auto">
          <table class="w-full text-[13.5px]">
            <tbody class="divide-y divide-line">
              <tr v-for="row in samples" :key="row.id" class="cursor-pointer hover:bg-surface-sunken/60" @click="router.push({ name: 'portal-sample', params: { id: row.id } })">
                <td class="px-4 py-2.5">
                  <span class="font-semibold text-ink">{{ row.customer_code || row.code }}</span>
                  <span class="block text-[12px] text-ink-faint font-mono">{{ row.code }}</span>
                </td>
                <td class="px-4 py-2.5">
                  <span class="flex flex-wrap gap-1">
                    <span v-for="(r, i) in row.results ?? []" :key="i" class="inline-flex items-baseline gap-1 rounded px-1.5 py-0.5 text-[12px] whitespace-nowrap" :class="CHIP[r.state] || CHIP.waiting">
                      <span class="font-mono text-[11.5px]">{{ r.symbol }}</span>
                      <template v-if="r.value !== null && r.value !== undefined">
                        <span class="tnum font-semibold">{{ formatValue(r.value, r.decimals, locale) }}</span>
                        <span v-if="r.unit" class="text-[11px] opacity-75">{{ r.unit }}</span>
                      </template>
                      <Lock v-else-if="r.state === 'hidden'" :size="11" class="self-center" />
                      <Hourglass v-else-if="r.state === 'locked'" :size="11" class="self-center" />
                    </span>
                  </span>
                </td>
                <td class="px-4 py-2.5 text-right tnum font-semibold" :class="row.progress === 100 ? 'text-ok' : 'text-ink'">{{ row.progress }}%</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <!-- rejadagi namunalar -->
      <section class="panel overflow-hidden">
        <h2 class="px-4 py-3 border-b border-line text-[14px] font-semibold text-ink">{{ t('sched.plannedItems') }}</h2>
        <table class="w-full text-[13px]">
          <tbody class="divide-y divide-line">
            <tr v-for="(it, i) in run.items" :key="i">
              <td class="px-4 py-2 w-10 text-ink-faint tnum">{{ i + 1 }}</td>
              <td class="px-4 py-2 font-medium text-ink">{{ it.customer_code || '—' }}</td>
              <td class="px-4 py-2 text-ink-muted">{{ it.svodka_point || '' }}</td>
              <td class="px-4 py-2 font-mono text-[12px] text-ink-muted">{{ it.elements.join(', ') }}</td>
              <td class="px-4 py-2 text-right text-ink-muted tnum">{{ it.quantity ? `${it.quantity} ${it.quantity_unit || ''}` : '' }}</td>
            </tr>
          </tbody>
        </table>
      </section>
    </template>
  </div>
</template>
