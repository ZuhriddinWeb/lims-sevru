<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import {
  AlertTriangle, ArrowDownLeft, ArrowLeftRight, ArrowUpRight, ChevronLeft, ChevronRight, Clock, EyeOff, Network, Plus, Search,
} from 'lucide-vue-next'
import api from '@/lib/api'
import PageHeader from '@/components/ui/PageHeader.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import { useUiStore } from '@/stores/ui'
import { formatDate } from '@/lib/format'
import IlStatusBadge from '../components/IlStatusBadge.vue'
import SyncIndicator from '../components/SyncIndicator.vue'
import CreateReferralModal from '../components/CreateReferralModal.vue'
import { IN_STATUSES, OUT_STATUSES, apiErrorMessage, useInterlabSync } from '../helpers'
import '../i18n'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const ui = useUiStore()

const summary = ref(null)
const items = ref([])
const peers = ref([])
const loading = ref(true)
const createOpen = ref(false)
const meta = reactive({ current_page: 1, last_page: 1, total: 0, from: 0, to: 0 })
const tab = ref(route.query.tab === 'out' || route.query.tab === 'in' ? route.query.tab : null)
const filters = reactive({ search: '', status: '', peer_id: '', open_only: false })
let timer = null

async function loadSummary() {
  try {
    const { data } = await api.get('/interlab/summary')
    summary.value = data
    if (!tab.value) {
      tab.value = data.in_new > 0 || (data.can.process && !data.can.send) ? 'in' : 'out'
    }
  } catch (e) {
    ui.error(apiErrorMessage(e, t('il.loadError')))
  }
}

async function load(page = 1, quiet = false) {
  if (!tab.value) return
  if (!quiet) loading.value = true
  try {
    const { data } = await api.get('/interlab/referrals', {
      params: {
        page, direction: tab.value,
        search: filters.search || undefined,
        status: filters.status || undefined,
        peer_id: filters.peer_id || undefined,
        open_only: filters.open_only ? 1 : undefined,
      },
    })
    items.value = data.data
    Object.assign(meta, { current_page: data.current_page, last_page: data.last_page, total: data.total, from: data.from ?? 0, to: data.to ?? 0 })
  } catch (e) {
    if (!quiet) ui.error(apiErrorMessage(e, t('il.loadError')))
  } finally {
    loading.value = false
  }
}

const { queue, lastSyncAt, syncing, syncNow } = useInterlabSync(async () => {
  await Promise.all([loadSummary(), load(meta.current_page, true)])
})

async function manualSync() {
  const r = await syncNow()
  if (r) ui.success(t('il.syncDone'))
}

onMounted(async () => {
  await loadSummary()
  load(1)
  try {
    const { data } = await api.get('/interlab/peers/active')
    peers.value = data
  } catch { /* filtr ixtiyoriy */ }
})

watch(tab, (v) => {
  filters.status = ''
  router.replace({ query: { ...route.query, tab: v } })
  load(1)
})
watch(() => [filters.status, filters.peer_id, filters.open_only], () => load(1))
watch(() => filters.search, () => {
  clearTimeout(timer)
  timer = setTimeout(() => load(1), 300)
})

const statuses = computed(() => (tab.value === 'in' ? IN_STATUSES : OUT_STATUSES))
const hasFilters = computed(() => filters.search || filters.status || filters.peer_id || filters.open_only)
const canCreate = computed(() => summary.value?.can.send && summary.value?.configured)
const noPeers = computed(() => summary.value && summary.value.peers_active === 0)

function open(row) {
  router.push({ name: 'interlab-detail', params: { id: row.id } })
}
</script>

<template>
  <div>
    <PageHeader :title="t('il.title')" :subtitle="t('il.subtitle')">
      <template #actions>
        <SyncIndicator v-if="summary?.configured" :queue="queue" :last-sync-at="lastSyncAt || summary?.last_sync_at" :syncing="syncing" @sync="manualSync" />
        <button
          v-if="canCreate" class="btn-primary" :disabled="noPeers"
          :title="noPeers ? t('il.noPeers') : ''" @click="createOpen = true"
        >
          <Plus :size="17" /> {{ t('il.newReferral') }}
        </button>
      </template>
    </PageHeader>

    <!-- Sozlanmagan -->
    <div v-if="summary && !summary.configured" class="panel p-6 mb-4 flex flex-wrap items-center justify-between gap-4">
      <div class="flex items-start gap-3 min-w-0">
        <div class="h-10 w-10 rounded-full bg-warn-light text-warn-dark grid place-items-center shrink-0"><Network :size="19" /></div>
        <div>
          <p class="text-[15px] font-medium text-ink">{{ t('il.notConfigured') }}</p>
          <p class="text-[13.5px] text-ink-muted mt-0.5">{{ t('il.notConfiguredHint') }}</p>
        </div>
      </div>
      <router-link v-if="summary.can.manage" :to="{ name: 'interlab-network' }" class="btn-outline">{{ t('il.openNetwork') }}</router-link>
    </div>

    <template v-else>
      <!-- Tablar -->
      <div class="flex items-center gap-1 mb-3 border-b border-line">
        <button
          v-for="d in ['in', 'out']" :key="d"
          class="relative -mb-px flex items-center gap-2 px-3.5 py-2.5 text-[14px] font-medium border-b-2 transition-colors"
          :class="tab === d ? 'border-assay-500 text-ink' : 'border-transparent text-ink-muted hover:text-ink'"
          @click="tab = d"
        >
          <component :is="d === 'in' ? ArrowDownLeft : ArrowUpRight" :size="16" />
          {{ d === 'in' ? t('il.tabIn') : t('il.tabOut') }}
          <span
            v-if="d === 'in' ? summary?.in_new : summary?.out_unreviewed"
            class="min-w-[20px] h-5 px-1.5 rounded-full bg-assay-500 text-white text-[11px] font-semibold grid place-items-center tnum"
          >{{ d === 'in' ? summary.in_new : summary.out_unreviewed }}</span>
        </button>
      </div>

      <!-- Filtrlar -->
      <div class="panel p-3 mb-4 flex flex-wrap items-center gap-2.5">
        <div class="relative flex-1 min-w-[220px]">
          <Search :size="16" class="absolute left-3 top-1/2 -translate-y-1/2 text-ink-faint" />
          <input v-model="filters.search" class="field-input pl-9" :placeholder="t('il.searchPlaceholder')">
        </div>
        <select v-model="filters.status" class="field-input w-auto min-w-[170px]">
          <option value="">{{ t('il.allStatuses') }}</option>
          <option v-for="s in statuses" :key="s" :value="s">{{ t(`il.st.${tab || 'out'}.${s}`) }}</option>
        </select>
        <select v-if="peers.length > 1" v-model="filters.peer_id" class="field-input w-auto min-w-[180px]">
          <option value="">{{ t('il.allLabs') }}</option>
          <option v-for="p in peers" :key="p.id" :value="p.id">{{ p.name }}</option>
        </select>
        <label class="inline-flex items-center gap-2 text-[13.5px] text-ink-muted px-1 cursor-pointer select-none">
          <input v-model="filters.open_only" type="checkbox" class="rounded border-line text-assay-500 focus:ring-assay-500">
          {{ t('il.openOnly') }}
        </label>
      </div>

      <div class="panel overflow-hidden">
        <div v-if="loading && !items.length" class="p-4 space-y-2.5">
          <div v-for="i in 6" :key="i" class="h-10 rounded bg-surface-sunken animate-pulse" />
        </div>

        <EmptyState
          v-else-if="!items.length"
          :icon="ArrowLeftRight"
          :title="hasFilters ? t('common.nothingFound') : (tab === 'in' ? t('il.emptyIn') : t('il.emptyOut'))"
          :hint="hasFilters ? t('common.nothingFoundHint') : (tab === 'in' ? t('il.emptyInHint') : t('il.emptyOutHint'))"
        >
          <button v-if="!hasFilters && tab === 'out' && canCreate && !noPeers" class="btn-primary" @click="createOpen = true">
            <Plus :size="16" /> {{ t('il.newReferral') }}
          </button>
        </EmptyState>

        <div v-else class="overflow-x-auto">
          <table class="w-full text-[13.5px]">
            <thead>
              <tr class="border-b border-line bg-surface-sunken/60 text-left text-[12px] uppercase tracking-wide text-ink-muted">
                <th class="px-4 py-2.5 font-medium">{{ t('il.colNumber') }}</th>
                <th class="px-4 py-2.5 font-medium">{{ t('il.colDate') }}</th>
                <th class="px-4 py-2.5 font-medium">{{ t('il.colLab') }}</th>
                <th class="px-4 py-2.5 font-medium">{{ t('il.colSample') }}</th>
                <th class="px-4 py-2.5 font-medium">{{ t('il.colElements') }}</th>
                <th class="px-4 py-2.5 font-medium">{{ t('il.colDeadline') }}</th>
                <th class="px-4 py-2.5 font-medium">{{ t('il.colStatus') }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-line">
              <tr
                v-for="(row, i) in items" :key="row.id"
                class="hover:bg-surface-sunken/60 cursor-pointer transition-colors animate-row-in"
                :class="(tab === 'in' && row.status === 'sent') || row.unreviewed ? 'bg-assay-50/40 dark:bg-assay-800/10' : ''"
                :style="{ animationDelay: `${Math.min(i, 12) * 18}ms` }"
                @click="open(row)"
              >
                <td class="px-4 py-2.5 whitespace-nowrap">
                  <span class="font-mono font-semibold text-ink">{{ row.number }}</span>
                  <span v-if="row.unreviewed" class="ml-1.5 inline-block h-2 w-2 rounded-full bg-assay-500 align-middle" :title="t('il.unreviewed')" />
                </td>
                <td class="px-4 py-2.5 text-ink-muted tnum whitespace-nowrap">{{ formatDate(row.sent_at || row.created_at) }}</td>
                <td class="px-4 py-2.5">
                  <span class="inline-flex items-center gap-1.5 text-ink">
                    <span class="h-1.5 w-1.5 rounded-full shrink-0" :class="row.peer?.online ? 'bg-ok' : 'bg-ink-faint'" />
                    {{ row.peer?.name }}
                  </span>
                </td>
                <td class="px-4 py-2.5 whitespace-nowrap">
                  <template v-if="row.sample_code">
                    <span class="font-mono text-ink">{{ row.sample_code }}</span>
                    <span class="text-ink-muted"> · № {{ row.sample_number }}</span>
                    <EyeOff v-if="row.hide_code" :size="13" class="inline ml-1 -mt-0.5 text-ink-faint" :title="t('il.hideCode')" />
                  </template>
                  <span v-else class="inline-flex items-center gap-1 text-ink-faint text-[12.5px]"><EyeOff :size="13" /> {{ t('il.hiddenCode') }}</span>
                </td>
                <td class="px-4 py-2.5">
                  <span class="flex flex-wrap gap-1">
                    <span v-for="el in row.elements" :key="el.symbol" class="rounded bg-surface-sunken px-1.5 py-0.5 text-[12px] font-semibold text-ink">{{ el.symbol }}</span>
                  </span>
                </td>
                <td class="px-4 py-2.5 tnum whitespace-nowrap" :class="row.overdue ? 'text-crit font-medium' : 'text-ink-muted'">
                  <AlertTriangle v-if="row.overdue" :size="13" class="inline -mt-0.5 mr-0.5" :title="t('il.overdue')" />
                  {{ row.deadline ? formatDate(row.deadline) : '—' }}
                </td>
                <td class="px-4 py-2.5 whitespace-nowrap">
                  <IlStatusBadge :status="row.status" :direction="row.direction" size="sm" />
                  <Clock v-if="row.sync?.pending" :size="14" class="inline ml-1.5 -mt-0.5 text-warn" :title="t('il.syncPending', { n: row.sync.pending })" />
                  <AlertTriangle v-if="row.sync?.failed" :size="14" class="inline ml-1.5 -mt-0.5 text-crit" :title="t('il.syncFailed', { n: row.sync.failed })" />
                </td>
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

    <CreateReferralModal v-if="canCreate" v-model="createOpen" @created="loadSummary" />
  </div>
</template>
