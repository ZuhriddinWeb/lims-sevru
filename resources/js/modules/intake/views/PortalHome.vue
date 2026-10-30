<script setup>
/**
 * Buyurtmachi portali — bo'lim topshirgan probalar ro'yxati (faqat ko'rish).
 * Laboratoriya xodimi (customer_departments.view) shu sahifani «ko'rib chiqish» rejimida ochadi.
 */
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { ChevronLeft, ChevronRight, Eye, FileSpreadsheet, Hourglass, Inbox, Loader2, Lock, Search, Sigma, SlidersHorizontal } from 'lucide-vue-next'
import api from '@/lib/api'
import PageHeader from '@/components/ui/PageHeader.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import { useUiStore } from '@/stores/ui'
import { formatDate } from '@/lib/format'
import StageStepper from '../components/StageStepper.vue'
import PeriodFilter from '../components/PeriodFilter.vue'
import { apiErrorMessage, formatValue, label, templateName } from '../helpers'
import { downloadPortalExport, visibilityOf } from '../portalVisibility' // [P27]
import '../i18n'
import '../svodka/i18n' // [P29]

const { t, locale } = useI18n()
const route = useRoute()
const router = useRouter()
const ui = useUiStore()

const ctx = ref(null)           // { portal, preview, departments, visibility, can_configure }
const ctxError = ref('')
const items = ref([])
const totals = ref([])          // [P28] varaqlar bo'yicha jami (faqat o'z namunalari bo'yicha)
const meta = reactive({ current_page: 1, last_page: 1, total: 0, from: 0, to: 0 })
const loading = ref(true)
const filters = reactive({
  search: route.query.search ?? '',
  status: route.query.status ?? 'active',
  department_id: route.query.department_id ?? '',
  date_from: route.query.date_from ?? '',
  date_to: route.query.date_to ?? '',
})
let timer = null

async function loadContext() {
  try {
    const { data } = await api.get('/portal/context')
    ctx.value = data
  } catch (e) {
    ctxError.value = e?.response?.status === 403 ? t('intake.portal.forbidden') : apiErrorMessage(e, t('intake.loadError'))
  }
}

async function load(page = 1) {
  if (!ctx.value) return
  loading.value = true
  try {
    const { data } = await api.get('/portal/samples', {
      params: {
        page,
        per_page: 20,
        search: filters.search || undefined,
        status: filters.status === 'all' ? undefined : filters.status,
        department_id: filters.department_id || undefined,
        date_from: filters.date_from || undefined,
        date_to: filters.date_to || undefined,
      },
    })
    items.value = data.data
    totals.value = data.worksheet_totals ?? []
    Object.assign(meta, {
      current_page: data.current_page, last_page: data.last_page,
      total: data.total, from: data.from ?? 0, to: data.to ?? 0,
    })
  } catch (e) {
    ui.error(apiErrorMessage(e, t('intake.loadError')))
  } finally {
    loading.value = false
  }
}

onMounted(async () => {
  await loadContext()
  loading.value = false
  if (ctx.value) load(1)
})

watch(() => [filters.status, filters.department_id, filters.date_from, filters.date_to], () => load(1))
watch(() => filters.search, () => {
  clearTimeout(timer)
  timer = setTimeout(() => load(1), 300)
})

const departments = computed(() => ctx.value?.departments ?? [])
const showDeptSelect = computed(() => ctx.value?.preview || departments.value.length > 1)
const notLinked = computed(() => ctx.value?.portal && departments.value.length === 0)

/* [P28] qatordagi natijalar: yakuniy / dastlabki / yopiq / kutilmoqda */
const CHIP = {
  final: 'bg-ok-light text-ok-dark dark:bg-ok-dark/25 dark:text-ok-light',
  preliminary: 'bg-warn-light text-warn-dark dark:bg-warn-dark/25 dark:text-warn-light border border-dashed border-warn/50',
  hidden: 'bg-surface-sunken text-ink-muted',
  waiting: 'bg-surface-sunken text-ink-muted',
  locked: 'bg-aqua-50 text-aqua-700 dark:bg-aqua-800/30 dark:text-aqua-200 border border-aqua-300/60', // [P29] svodka tasdig'ini kutmoqda
}
function chipTitle(r) {
  if (r.state === 'final') return t('portalVis.chipFinal')
  if (r.state === 'preliminary') return t('portalVis.chipPrelim', { n: r.worksheet || '' })
  if (r.state === 'hidden') return t('portalVis.hiddenHint')
  if (r.state === 'locked') return t('svodka.chipLocked')
  return t('portalVis.chipWaiting')
}
const hasPrelim = computed(() => items.value.some((row) => row.results?.some((r) => r.state === 'preliminary')))
const hasLocked = computed(() => items.value.some((row) => row.results?.some((r) => r.state === 'locked'))) // [P29]

/* [P27] admin belgilagan ko'rinish va Excel */
const vis = computed(() => visibilityOf(ctx.value))
const exporting = ref(false)
async function exportExcel() {
  if (exporting.value) return
  exporting.value = true
  try {
    const name = await downloadPortalExport({ ...filters }, locale.value)
    ui.success(t('portalVis.excelDone', { name }))
  } catch (e) {
    if (e.status !== 403 || e.serverMessage) ui.error(e.serverMessage || t('portalVis.excelError'))
    if (e.status === 403) loadContext()
  } finally {
    exporting.value = false
  }
}

function open(row) {
  router.push({ name: 'portal-sample', params: { id: row.id }, query: filters.department_id ? { department_id: filters.department_id } : {} })
}
</script>

<template>
  <div>
    <PageHeader :title="t('intake.portal.title')" :subtitle="t('intake.portal.subtitle')">
      <template #actions>
        <span class="inline-flex items-center gap-1.5 rounded-full bg-surface-sunken px-2.5 py-1 text-[12px] text-ink-muted">
          <Lock :size="13" /> {{ t('intake.portal.readOnly') }}
        </span>
        <RouterLink v-if="ctx?.can_configure" :to="{ name: 'portal-settings' }" class="btn-ghost btn-sm" :title="t('portalVis.configureHint')" data-configure>
          <SlidersHorizontal :size="15" /> {{ t('portalVis.configure') }}
        </RouterLink>
        <button
          v-if="ctx && !notLinked && vis.export"
          type="button" class="btn-outline btn-sm" :disabled="exporting || !items.length"
          :title="t('portalVis.excelHint')" data-export
          @click="exportExcel"
        >
          <Loader2 v-if="exporting" :size="15" class="animate-spin" />
          <FileSpreadsheet v-else :size="15" />
          {{ exporting ? t('portalVis.excelBusy') : t('portalVis.excel') }}
        </button>
      </template>
    </PageHeader>

    <p v-if="ctxError" class="panel p-4 text-[14px] text-crit" data-portal-error>{{ ctxError }}</p>

    <template v-else-if="ctx">
      <div v-if="ctx.preview" class="mb-4 flex items-center gap-2 rounded-md border border-aqua-300 bg-aqua-50 dark:bg-aqua-800/25 px-3 py-2 text-[13px] text-aqua-700 dark:text-aqua-200" data-preview>
        <Eye :size="15" /> {{ t('intake.portal.previewBanner') }}
      </div>
      <div v-if="notLinked" class="panel p-4 text-[14px] text-warn-dark dark:text-warn-light" data-not-linked>
        {{ t('intake.portal.notLinked') }}
      </div>

      <template v-else>
        <!-- Filtrlar -->
        <div class="panel p-3 mb-4 flex flex-wrap items-center gap-2.5">
          <div class="inline-flex rounded-md border border-line p-0.5 bg-surface-sunken/60" role="tablist">
            <button
              v-for="tab in ['active', 'done', 'all']" :key="tab" type="button"
              class="px-3 h-8 rounded text-[13px] font-medium transition-colors"
              :class="filters.status === tab ? 'bg-surface text-ink shadow-sm' : 'text-ink-muted hover:text-ink'"
              :data-tab="tab"
              @click="filters.status = tab"
            >{{ t(`intake.portal.tabs.${tab}`) }}</button>
          </div>
          <div class="relative flex-1 min-w-[200px]">
            <Search :size="16" class="absolute left-3 top-1/2 -translate-y-1/2 text-ink-faint" />
            <input v-model="filters.search" class="field-input pl-9" :placeholder="t('intake.portal.search')">
          </div>
          <select v-if="showDeptSelect" v-model="filters.department_id" class="field-input w-auto min-w-[200px]" data-dept-select>
            <option value="">{{ t('intake.portal.allDepartments') }}</option>
            <option v-for="d in departments" :key="d.id" :value="d.id">{{ d.name }}<template v-if="d.code"> ({{ d.code }})</template></option>
          </select>
          <!-- Topshirilgan sana bo'yicha: kun / hafta / oy / yil / oraliq -->
          <PeriodFilter v-model:from="filters.date_from" v-model:to="filters.date_to" class="w-full" />
        </div>

        <!-- [P28] Ishchi varaqlar bo'yicha jami (korotochkalar yig'indisi) -->
        <section v-if="totals.length && items.length" class="panel mb-4 overflow-hidden" data-totals>
          <header class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 px-4 py-3 border-b border-line">
            <h2 class="flex items-center gap-2 text-[14px] font-semibold text-ink">
              <Sigma :size="16" class="text-assay-500" /> {{ t('portalVis.totals.title') }}
            </h2>
            <p class="text-[12px] text-ink-muted">{{ t('portalVis.totals.hint') }}</p>
          </header>
          <div class="grid gap-3 p-3 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4">
            <div v-for="w in totals" :key="w.worksheet_id" class="rounded-md border border-line px-3.5 py-3" :data-ws-total="w.worksheet_id">
              <div class="flex items-start justify-between gap-2">
                <div class="min-w-0">
                  <p class="text-[13.5px] font-semibold text-ink">
                    {{ t('portalVis.totals.worksheet', { n: w.number }) }}
                    <span v-if="w.kind === 'control'" class="text-[11.5px] font-normal text-ink-muted">· {{ t('portalVis.totals.control') }}</span>
                  </p>
                  <p class="text-[12px] text-ink-muted truncate">{{ templateName(w.template, locale) }}<template v-if="w.worksheet_date"> · {{ formatDate(w.worksheet_date) }}</template></p>
                </div>
                <span
                  class="shrink-0 rounded-full px-2 py-0.5 text-[11px] font-medium"
                  :class="w.preliminary ? 'bg-warn-light text-warn-dark dark:bg-warn-dark/25 dark:text-warn-light' : 'bg-ok-light text-ok-dark dark:bg-ok-dark/25 dark:text-ok-light'"
                >{{ w.preliminary ? t('portalVis.totals.preliminary') : t('portalVis.totals.approved') }}</span>
              </div>
              <div v-for="c in w.totals" :key="c.key" class="mt-2" :data-total-key="c.key">
                <p class="text-[12px] text-ink-muted">{{ label(c, locale) }}</p>
                <p class="text-[20px] font-semibold tnum text-ink leading-tight">
                  {{ formatValue(c.value, c.decimals, locale) || '—' }} <span class="text-[12.5px] font-normal text-ink-muted">{{ c.unit }}</span>
                </p>
              </div>
              <p
                class="mt-1.5 text-[11.5px]" :class="w.partial ? 'text-warn-dark dark:text-warn-light' : 'text-ink-faint'"
                :title="w.partial ? t('portalVis.totals.partialHint', { own: w.samples_own, all: w.samples_all }) : null"
                data-ws-samples
              >{{ w.partial ? t('portalVis.totals.partial', { own: w.samples_own, all: w.samples_all }) : t('portalVis.totals.samples', { n: w.samples_all }) }}</p>
            </div>
          </div>
        </section>

        <div class="panel overflow-hidden">
          <div v-if="loading && !items.length" class="p-4 space-y-2.5">
            <div v-for="i in 5" :key="i" class="h-12 rounded bg-surface-sunken animate-pulse" />
          </div>

          <EmptyState v-else-if="!items.length" :icon="Inbox" :title="t('intake.portal.empty')" :hint="t('intake.portal.emptyHint')" />

          <div v-else class="overflow-x-auto">
            <table class="w-full text-[13.5px]">
              <thead>
                <tr class="border-b border-line bg-surface-sunken/60 text-left text-[12px] uppercase tracking-wide text-ink-muted">
                  <th class="px-4 py-2.5 font-medium">{{ t('intake.portal.code') }}</th>
                  <th class="px-4 py-2.5 font-medium">{{ t('intake.portal.registered') }}</th>
                  <th class="px-4 py-2.5 font-medium">
                    {{ items.some((row) => row.results) ? t('portalVis.resultsCol') : t('intake.portal.elements') }}
                    <!-- [P28] belgilar izohi -->
                    <span v-if="items.some((row) => row.results)" class="ml-2 inline-flex items-center gap-2 normal-case tracking-normal font-normal text-[11.5px]" data-legend>
                      <span class="inline-flex items-center gap-1"><span class="h-2.5 w-2.5 rounded-sm bg-ok-light border border-ok/40" />{{ t('portalVis.legendFinal') }}</span>
                      <span v-if="hasPrelim" class="inline-flex items-center gap-1"><span class="h-2.5 w-2.5 rounded-sm bg-warn-light border border-dashed border-warn/60" />{{ t('portalVis.legendPrelim') }}</span>
                      <span v-if="hasLocked" class="inline-flex items-center gap-1" data-legend-locked><Hourglass :size="11" class="text-aqua-600" />{{ t('svodka.legendLocked') }}</span>
                    </span>
                  </th>
                  <th v-if="vis.steps" class="px-4 py-2.5 font-medium min-w-[190px]">{{ t('intake.portal.stageCol') }}</th>
                  <th class="px-4 py-2.5 font-medium text-right">{{ items.some((row) => row.results) ? t('portalVis.progressCol') : t('intake.portal.progress') }}</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-line">
                <tr
                  v-for="row in items" :key="row.id"
                  class="hover:bg-surface-sunken/60 cursor-pointer transition-colors"
                  :data-sample="row.id"
                  @click="open(row)"
                >
                  <td class="px-4 py-2.5">
                    <span class="font-semibold text-ink">{{ row.customer_code || row.code }}</span>
                    <span class="block text-[12px] text-ink-faint font-mono">{{ row.code }}<template v-if="ctx.preview && row.department"> · {{ row.department.name }}</template></span>
                  </td>
                  <td class="px-4 py-2.5 text-ink-muted tnum whitespace-nowrap">{{ formatDate(row.registered_at) }}</td>
                  <td class="px-4 py-2.5">
                    <!-- [P28] natija qiymati bilan — kartochkani ochmasdan -->
                    <span v-if="row.results" class="flex flex-wrap gap-1" data-results>
                      <span
                        v-for="(r, i) in row.results" :key="i"
                        class="inline-flex items-baseline gap-1 rounded px-1.5 py-0.5 text-[12px] whitespace-nowrap"
                        :class="CHIP[r.state] || CHIP.waiting" :title="chipTitle(r)" :data-chip="r.state"
                      >
                        <span class="font-mono text-[11.5px]">{{ r.symbol }}</span>
                        <template v-if="r.value !== null && r.value !== undefined">
                          <span class="tnum font-semibold">{{ formatValue(r.value, r.decimals, locale) }}</span>
                          <span v-if="r.unit" class="text-[11px] opacity-75">{{ r.unit }}</span>
                        </template>
                        <Lock v-else-if="r.state === 'hidden'" :size="11" class="self-center" />
                        <Hourglass v-else-if="r.state === 'locked'" :size="11" class="self-center" />
                      </span>
                    </span>
                    <span v-else class="flex flex-wrap gap-1">
                      <span
                        v-for="(el, i) in row.elements" :key="i"
                        class="rounded px-1.5 py-0.5 text-[11.5px] font-mono"
                        :class="el.done ? 'bg-ok-light text-ok-dark dark:bg-ok-dark/25 dark:text-ok-light' : 'bg-surface-sunken text-ink-muted'"
                      >{{ el.symbol }}</span>
                    </span>
                  </td>
                  <td v-if="vis.steps" class="px-4 py-2.5"><StageStepper :steps="row.steps" :current="row.stage" compact /></td>
                  <td class="px-4 py-2.5 text-right tnum font-semibold" :class="row.progress === 100 ? 'text-ok' : 'text-ink'">{{ row.progress }}%</td>
                </tr>
              </tbody>
            </table>
          </div>

          <div v-if="meta.last_page > 1" class="flex items-center justify-between gap-3 px-4 py-3 border-t border-line text-[13px] text-ink-muted">
            <span>{{ t('common.showing', { from: meta.from, to: meta.to, total: meta.total }) }}</span>
            <div class="flex gap-1.5">
              <button class="btn-outline btn-sm" :disabled="meta.current_page <= 1" @click="load(meta.current_page - 1)">
                <ChevronLeft :size="15" />
              </button>
              <button class="btn-outline btn-sm" :disabled="meta.current_page >= meta.last_page" @click="load(meta.current_page + 1)">
                <ChevronRight :size="15" />
              </button>
            </div>
          </div>
        </div>
      </template>
    </template>
  </div>
</template>
