<script setup>
/** Laboratoriya — «Buyurtmalar»: kutilayotgan namunalar navbati, qabul qilinganlar, yopilganlar */
import { onMounted, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { ChevronLeft, ChevronRight, ClipboardList, Plus, Search } from 'lucide-vue-next'
import api from '@/lib/api'
import PageHeader from '@/components/ui/PageHeader.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import { useUiStore } from '@/stores/ui'
import { formatDate, formatDateTime } from '@/lib/format'
import StatusPill from '../components/StatusPill.vue'
import { apiErrorMessage } from '../shared'
import '../i18n'
import '../../i18n'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const ui = useUiStore()

const TABS = ['waiting', 'expired', 'received', 'closed', 'drafts', 'all']
const options = ref(null)
const items = ref([])
const counts = ref({})
const meta = reactive({ current_page: 1, last_page: 1, total: 0, from: 0, to: 0 })
const loading = ref(true)
const filters = reactive({
  tab: TABS.includes(route.query.tab) ? route.query.tab : 'waiting',
  search: route.query.search ?? '',
  department_id: route.query.department_id ?? '',
})
let timer = null

async function load(page = 1) {
  loading.value = true
  try {
    const { data } = await api.get('/sample-requests', {
      params: { page, tab: filters.tab, search: filters.search || undefined, department_id: filters.department_id || undefined },
    })
    items.value = data.data
    counts.value = data.tab_counts ?? {}
    Object.assign(meta, { current_page: data.current_page, last_page: data.last_page, total: data.total, from: data.from ?? 0, to: data.to ?? 0 })
    router.replace({ query: { ...(filters.tab !== 'waiting' ? { tab: filters.tab } : {}), ...(filters.search ? { search: filters.search } : {}), ...(filters.department_id ? { department_id: filters.department_id } : {}) } })
  } catch (e) {
    ui.error(apiErrorMessage(e, t('sreq.loadError')))
  } finally {
    loading.value = false
  }
}

onMounted(async () => {
  try {
    options.value = (await api.get('/sample-requests/options')).data
  } catch { options.value = { departments: [], can_create: false } }
  load(1)
})

watch(() => [filters.tab, filters.department_id], () => load(1))
watch(() => filters.search, () => { clearTimeout(timer); timer = setTimeout(() => load(1), 300) })

function open(row) {
  if (row.status === 'draft') router.push({ name: 'sample-request-edit', params: { id: row.id } })
  else router.push({ name: 'sample-request', params: { id: row.id } })
}

/** Skaner yoki qo'lda raqam: Enter — aniq mos kelsa darhol ochadi */
function onEnter() {
  const q = filters.search.trim().toUpperCase()
  const hit = items.value.find((r) => (r.number || '').toUpperCase() === q)
  if (hit) open(hit)
}
</script>

<template>
  <div>
    <PageHeader :title="t('sreq.labTitle')" :subtitle="t('sreq.labSubtitle')">
      <template #actions>
        <button v-if="options?.can_create" class="btn-outline" data-new @click="router.push({ name: 'sample-request-new' })">
          <Plus :size="16" /> {{ t('sreq.newOnBehalf') }}
        </button>
      </template>
    </PageHeader>

    <div class="panel p-3 mb-4 flex flex-wrap items-center gap-2.5">
      <div class="inline-flex flex-wrap rounded-md border border-line p-0.5 bg-surface-sunken/60" role="tablist">
        <button
          v-for="tab in TABS" :key="tab" type="button" :data-tab="tab"
          class="px-3 h-8 rounded text-[13px] font-medium transition-colors inline-flex items-center gap-1.5"
          :class="filters.tab === tab ? 'bg-surface text-ink shadow-sm' : 'text-ink-muted hover:text-ink'"
          @click="filters.tab = tab"
        >
          {{ t(`sreq.tabs.${tab}`) }}
          <span
            v-if="counts[tab]" class="rounded-full px-1.5 text-[11px] tnum"
            :class="tab === 'waiting' ? 'bg-assay-500 text-white' : tab === 'expired' ? 'bg-crit text-white' : 'bg-surface-sunken text-ink-muted'"
          >{{ counts[tab] }}</span>
        </button>
      </div>
      <div class="relative flex-1 min-w-[200px]">
        <Search :size="16" class="absolute left-3 top-1/2 -translate-y-1/2 text-ink-faint" />
        <input v-model="filters.search" class="field-input pl-9" :placeholder="t('sreq.searchLab')" data-search @keydown.enter="onEnter">
      </div>
      <select v-model="filters.department_id" class="field-input w-auto min-w-[200px]">
        <option value="">{{ t('intake.portal.allDepartments') }}</option>
        <option v-for="d in options?.departments ?? []" :key="d.id" :value="d.id">{{ d.name }}</option>
      </select>
    </div>

    <div class="panel overflow-hidden">
      <div v-if="loading && !items.length" class="p-4 space-y-2.5">
        <div v-for="i in 4" :key="i" class="h-12 rounded bg-surface-sunken animate-pulse" />
      </div>
      <EmptyState v-else-if="!items.length" :icon="ClipboardList" :title="t('sreq.emptyLab')" />
      <div v-else class="overflow-x-auto">
        <table class="w-full text-[13.5px]">
          <thead>
            <tr class="border-b border-line bg-surface-sunken/60 text-left text-[12px] uppercase tracking-wide text-ink-muted">
              <th class="px-4 py-2.5 font-medium">{{ t('sreq.col.number') }}</th>
              <th class="px-4 py-2.5 font-medium">{{ t('sreq.col.department') }}</th>
              <th class="px-4 py-2.5 font-medium">{{ t('sreq.col.samples') }}</th>
              <th class="px-4 py-2.5 font-medium">{{ t('sreq.col.delivery') }}</th>
              <th class="px-4 py-2.5 font-medium">{{ t('sreq.col.status') }}</th>
              <th class="px-4 py-2.5 font-medium text-right">{{ t('sreq.col.submitted') }}</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-line">
            <tr v-for="row in items" :key="row.id" class="hover:bg-surface-sunken/60 cursor-pointer transition-colors" :data-request="row.id" @click="open(row)">
              <td class="px-4 py-2.5">
                <span class="font-mono font-semibold text-ink">{{ row.number || t('sreq.status.draft') }}</span>
                <span v-if="row.codes?.length" class="block text-[12px] text-ink-faint font-mono truncate max-w-[240px]">{{ row.codes.join(', ') }}</span>
              </td>
              <td class="px-4 py-2.5 text-ink">{{ row.department?.name }}</td>
              <td class="px-4 py-2.5 tnum text-ink-muted whitespace-nowrap">
                {{ t('sreq.nSamples', { n: row.counts.items }) }}
                <span v-if="row.counts.received" class="block text-[12px] text-ok-dark dark:text-ok-light">{{ t('sreq.progress', { r: row.counts.received, n: row.counts.items }) }}</span>
              </td>
              <td class="px-4 py-2.5 text-ink-muted">
                <span class="tnum">{{ row.delivery_date ? formatDate(row.delivery_date) : '—' }}</span>
                <span v-if="row.delivery_shift" class="block text-[12px] text-ink-muted" data-shift>{{ row.delivery_shift }}</span>
                <span v-if="row.contact_name" class="block text-[12px] text-ink-faint">{{ row.contact_name }}</span>
              </td>
              <td class="px-4 py-2.5"><StatusPill :status="row.status" /></td>
              <td class="px-4 py-2.5 text-right text-ink-muted tnum whitespace-nowrap">{{ formatDateTime(row.submitted_at || row.created_at) }}</td>
            </tr>
          </tbody>
        </table>
      </div>
      <div v-if="meta.last_page > 1" class="flex items-center justify-between gap-3 px-4 py-3 border-t border-line text-[13px] text-ink-muted">
        <span>{{ t('common.showing', { from: meta.from, to: meta.to, total: meta.total }) }}</span>
        <div class="flex gap-1.5">
          <button class="btn-outline btn-sm" :disabled="meta.current_page <= 1" @click="load(meta.current_page - 1)"><ChevronLeft :size="15" /></button>
          <button class="btn-outline btn-sm" :disabled="meta.current_page >= meta.last_page" @click="load(meta.current_page + 1)"><ChevronRight :size="15" /></button>
        </div>
      </div>
    </div>
  </div>
</template>
