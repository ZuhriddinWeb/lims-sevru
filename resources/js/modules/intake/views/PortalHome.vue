<script setup>
/**
 * Buyurtmachi portali — bo'lim topshirgan probalar ro'yxati (faqat ko'rish).
 * Laboratoriya xodimi (customer_departments.view) shu sahifani «ko'rib chiqish» rejimida ochadi.
 */
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { ChevronLeft, ChevronRight, Eye, Inbox, Lock, Search } from 'lucide-vue-next'
import api from '@/lib/api'
import PageHeader from '@/components/ui/PageHeader.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import { useUiStore } from '@/stores/ui'
import { formatDate } from '@/lib/format'
import StageStepper from '../components/StageStepper.vue'
import PeriodFilter from '../components/PeriodFilter.vue'
import { apiErrorMessage } from '../helpers'
import '../i18n'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const ui = useUiStore()

const ctx = ref(null)           // { portal, preview, departments }
const ctxError = ref('')
const items = ref([])
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
                  <th class="px-4 py-2.5 font-medium">{{ t('intake.portal.elements') }}</th>
                  <th class="px-4 py-2.5 font-medium min-w-[190px]">{{ t('intake.portal.stageCol') }}</th>
                  <th class="px-4 py-2.5 font-medium text-right">{{ t('intake.portal.progress') }}</th>
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
                    <span class="flex flex-wrap gap-1">
                      <span
                        v-for="(el, i) in row.elements" :key="i"
                        class="rounded px-1.5 py-0.5 text-[11.5px] font-mono"
                        :class="el.done ? 'bg-ok-light text-ok-dark dark:bg-ok-dark/25 dark:text-ok-light' : 'bg-surface-sunken text-ink-muted'"
                      >{{ el.symbol }}</span>
                    </span>
                  </td>
                  <td class="px-4 py-2.5"><StageStepper :steps="row.steps" :current="row.stage" compact /></td>
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
