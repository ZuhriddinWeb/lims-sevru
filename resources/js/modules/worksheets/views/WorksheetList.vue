<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import {
  AlertTriangle, ChevronLeft, ChevronRight, FileSpreadsheet, Paperclip, Plus, Printer, Search,
} from 'lucide-vue-next'
import api from '@/lib/api'
import PageHeader from '@/components/ui/PageHeader.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import { useAuthStore } from '@/stores/auth'
import { useUiStore } from '@/stores/ui'
import { formatDate } from '@/lib/format'
import WsStatusBadge from '../components/WsStatusBadge.vue'
import WsKindBadge from '../components/WsKindBadge.vue'
import CreateWorksheetModal from '../components/CreateWorksheetModal.vue'
import { STATUSES, apiErrorMessage, templateName } from '../helpers'
import '../i18n'

const { t, locale } = useI18n()
const router = useRouter()
const auth = useAuthStore()
const ui = useUiStore()

const items = ref([])
const meta = reactive({ current_page: 1, last_page: 1, total: 0, from: 0, to: 0 })
const loading = ref(true)
const templates = ref([])
const createOpen = ref(false)

const filters = reactive({ search: '', status: '', template_id: '', kind: '', warnings_only: false })
let timer = null

async function load(page = 1) {
  loading.value = true
  try {
    const { data } = await api.get('/worksheets', {
      params: {
        page,
        per_page: 20,
        search: filters.search || undefined,
        status: filters.status || undefined,
        template_id: filters.template_id || undefined,
        kind: filters.kind || undefined,
        warnings_only: filters.warnings_only ? 1 : undefined,
      },
    })
    items.value = data.data
    Object.assign(meta, {
      current_page: data.current_page, last_page: data.last_page,
      total: data.total, from: data.from ?? 0, to: data.to ?? 0,
    })
  } catch (e) {
    ui.error(apiErrorMessage(e, t('ws.loadError')))
  } finally {
    loading.value = false
  }
}

watch(() => [filters.status, filters.template_id, filters.kind, filters.warnings_only], () => load(1))
watch(() => filters.search, () => {
  clearTimeout(timer)
  timer = setTimeout(() => load(1), 300)
})

onMounted(async () => {
  load(1)
  try {
    const { data } = await api.get('/worksheet-templates')
    templates.value = data
  } catch { /* filtr ixtiyoriy */ }
})

const hasFilters = computed(() => filters.search || filters.status || filters.template_id || filters.kind || filters.warnings_only)

function open(row) {
  router.push({ name: 'worksheet-detail', params: { id: row.id } })
}
</script>

<template>
  <div>
    <PageHeader :title="t('ws.title')" :subtitle="t('ws.subtitle')">
      <template #actions>
        <button v-if="auth.can('worksheets.create')" class="btn-primary" @click="createOpen = true">
          <Plus :size="17" /> {{ t('ws.create') }}
        </button>
      </template>
    </PageHeader>

    <!-- Filtrlar -->
    <div class="panel p-3 mb-4 flex flex-wrap items-center gap-2.5">
      <div class="relative flex-1 min-w-[220px]">
        <Search :size="16" class="absolute left-3 top-1/2 -translate-y-1/2 text-ink-faint" />
        <input v-model="filters.search" class="field-input pl-9" :placeholder="t('ws.searchPlaceholder')">
      </div>
      <select v-model="filters.status" class="field-input w-auto min-w-[170px]">
        <option value="">{{ t('ws.allStatuses') }}</option>
        <option v-for="s in STATUSES" :key="s" :value="s">{{ t(`ws.st.${s}`) }}</option>
      </select>
      <select v-model="filters.template_id" class="field-input w-auto min-w-[190px]">
        <option value="">{{ t('ws.allTemplates') }}</option>
        <option v-for="tpl in templates" :key="tpl.id" :value="tpl.id">{{ tpl.code }} · {{ templateName(tpl, locale) }}</option>
      </select>
      <select v-model="filters.kind" class="field-input w-auto min-w-[150px]" data-kind-filter>
        <option value="">{{ t('ws.kindAll') }}</option>
        <option value="main">{{ t('ws.kindMain') }}</option>
        <option value="control">{{ t('ws.kindControl') }}</option>
      </select>
      <label class="inline-flex items-center gap-2 text-[13.5px] text-ink-muted px-1 cursor-pointer select-none">
        <input v-model="filters.warnings_only" type="checkbox" class="rounded border-line text-assay-500 focus:ring-assay-500">
        {{ t('ws.warningsOnly') }}
      </label>
    </div>

    <div class="panel overflow-hidden">
      <div v-if="loading && !items.length" class="p-4 space-y-2.5">
        <div v-for="i in 6" :key="i" class="h-10 rounded bg-surface-sunken animate-pulse" />
      </div>

      <EmptyState
        v-else-if="!items.length"
        :icon="FileSpreadsheet"
        :title="hasFilters ? t('common.nothingFound') : t('ws.empty')"
        :hint="hasFilters ? t('common.nothingFoundHint') : t('ws.emptyHint')"
      >
        <button v-if="!hasFilters && auth.can('worksheets.create')" class="btn-primary" @click="createOpen = true">
          <Plus :size="16" /> {{ t('ws.create') }}
        </button>
      </EmptyState>

      <div v-else class="overflow-x-auto">
        <table class="w-full text-[13.5px]">
          <thead>
            <tr class="border-b border-line bg-surface-sunken/60 text-left text-[12px] uppercase tracking-wide text-ink-muted">
              <th class="px-4 py-2.5 font-medium">{{ t('ws.number') }}</th>
              <th class="px-4 py-2.5 font-medium">{{ t('ws.date') }}</th>
              <th class="px-4 py-2.5 font-medium">{{ t('ws.samples') }}</th>
              <th class="px-4 py-2.5 font-medium">{{ t('ws.material') }}</th>
              <th class="px-4 py-2.5 font-medium">{{ t('ws.status') }}</th>
              <th class="px-4 py-2.5 font-medium">{{ t('ws.createdBy') }}</th>
              <th class="px-4 py-2.5 font-medium w-24" />
            </tr>
          </thead>
          <tbody class="divide-y divide-line">
            <tr
              v-for="(row, i) in items" :key="row.id"
              class="hover:bg-surface-sunken/60 cursor-pointer transition-colors animate-row-in"
              :style="{ animationDelay: `${Math.min(i, 12) * 18}ms` }"
              @click="open(row)"
            >
              <td class="px-4 py-2.5">
                <span class="font-mono font-semibold text-ink">{{ row.number }}</span>
                <WsKindBadge :kind="row.kind" size="sm" class="ml-1.5" />
                <AlertTriangle v-if="row.has_warnings" :size="14" class="inline ml-1.5 -mt-0.5 text-warn" :title="t('ws.warnings')" />
              </td>
              <td class="px-4 py-2.5 text-ink-muted tnum whitespace-nowrap">{{ formatDate(row.worksheet_date) }}</td>
              <td class="px-4 py-2.5">
                <template v-if="(row.members_count ?? 1) > 1">
                  <span class="text-ink">{{ (row.samples ?? []).slice(0, 4).map((s) => s.sample_number).join(', ') }}</span>
                  <span v-if="row.members_count > 4" class="text-ink-faint"> …</span>
                  <span class="block text-[12px] text-ink-muted">{{ t('ws.samplesN', { n: row.members_count }) }}</span>
                </template>
                <template v-else>
                  <span class="font-mono text-ink">{{ row.samples?.[0]?.code ?? row.sample?.code }}</span>
                  <span class="text-ink-muted"> · № {{ row.samples?.[0]?.sample_number ?? row.sample?.sample_number }}</span>
                </template>
              </td>
              <td class="px-4 py-2.5 text-ink">
                {{ templateName(row.template, locale) }}
                <span class="text-[12px] text-ink-faint font-mono ml-1">{{ row.template?.code }}</span>
              </td>
              <td class="px-4 py-2.5"><WsStatusBadge :status="row.status" size="sm" /></td>
              <td class="px-4 py-2.5 text-ink-muted">{{ row.creator?.name ?? '—' }}</td>
              <td class="px-4 py-2.5 text-right whitespace-nowrap" @click.stop>
                <span v-if="row.scans_count" class="inline-flex items-center gap-0.5 text-[12px] text-ink-faint mr-2" :title="t('ws.scans')">
                  <Paperclip :size="13" />{{ row.scans_count }}
                </span>
                <button class="btn-ghost btn-icon h-8 w-8" :title="t('ws.print')" @click="router.push({ name: 'worksheet-print', params: { id: row.id } })">
                  <Printer :size="16" />
                </button>
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

    <CreateWorksheetModal v-model="createOpen" @created="load(1)" />
  </div>
</template>
