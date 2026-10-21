<script setup>
/** Buyurtmachi portali — «Buyurtmalarim»: qoralamalar, yuborilganlar, qabul qilinganlar */
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { ChevronLeft, ChevronRight, ClipboardList, Eye, Plus, Search } from 'lucide-vue-next'
import api from '@/lib/api'
import PageHeader from '@/components/ui/PageHeader.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import { useUiStore } from '@/stores/ui'
import { useAuthStore } from '@/stores/auth'
import { formatDateTime } from '@/lib/format'
import StatusPill from '../components/StatusPill.vue'
import { apiErrorMessage } from '../shared'
import '../i18n'
import '../../i18n' // intake.portal.* matnlari

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const ui = useUiStore()
const auth = useAuthStore()

// Ko'rib chiqish rejimida (xodim) portal orqali yozib bo'lmaydi — laboratoriya formasiga o'tadi
const canOnBehalf = computed(() => options.value?.preview && auth.can('sample_requests.create'))
function newOnBehalf() {
  router.push({ name: 'sample-request-new', query: filters.department_id ? { department_id: filters.department_id } : {} })
}

const TABS = ['draft', 'sent', 'done', 'closed', 'all']
const options = ref(null)
const ctxError = ref('')
const items = ref([])
const meta = reactive({ current_page: 1, last_page: 1, total: 0, from: 0, to: 0 })
const loading = ref(true)
const filters = reactive({
  tab: TABS.includes(route.query.tab) ? route.query.tab : 'all',
  search: route.query.search ?? '',
  department_id: route.query.department_id ?? '',
})
let timer = null

async function load(page = 1) {
  loading.value = true
  try {
    const { data } = await api.get('/portal/requests', {
      params: {
        page, tab: filters.tab, search: filters.search || undefined, department_id: filters.department_id || undefined,
      },
    })
    items.value = data.data
    Object.assign(meta, { current_page: data.current_page, last_page: data.last_page, total: data.total, from: data.from ?? 0, to: data.to ?? 0 })
    router.replace({ query: { ...(filters.tab !== 'all' ? { tab: filters.tab } : {}), ...(filters.search ? { search: filters.search } : {}), ...(filters.department_id ? { department_id: filters.department_id } : {}) } })
  } catch (e) {
    ui.error(apiErrorMessage(e, t('sreq.loadError')))
  } finally {
    loading.value = false
  }
}

onMounted(async () => {
  try {
    const { data } = await api.get('/portal/requests/options')
    options.value = data
  } catch (e) {
    ctxError.value = e?.response?.status === 403 ? t('intake.portal.forbidden') : apiErrorMessage(e, t('sreq.loadError'))
    loading.value = false
    return
  }
  load(1)
})

watch(() => [filters.tab, filters.department_id], () => load(1))
watch(() => filters.search, () => { clearTimeout(timer); timer = setTimeout(() => load(1), 300) })

const departments = computed(() => options.value?.departments ?? [])
const multiDept = computed(() => options.value?.preview || departments.value.length > 1)
const notLinked = computed(() => options.value && !options.value.preview && departments.value.length === 0)

function open(row) {
  router.push({ name: 'portal-request', params: { id: row.id } })
}
</script>

<template>
  <div>
    <PageHeader :title="t('sreq.portalTitle')" :subtitle="t('sreq.portalSubtitle')">
      <template #actions>
        <button v-if="options?.can_write && !notLinked" class="btn-primary" data-new @click="router.push({ name: 'portal-request-new' })">
          <Plus :size="16" /> {{ t('sreq.new') }}
        </button>
        <button v-else-if="canOnBehalf" class="btn-primary" data-new-behalf @click="newOnBehalf">
          <Plus :size="16" /> {{ t('sreq.newOnBehalf') }}
        </button>
      </template>
    </PageHeader>

    <p v-if="ctxError" class="panel p-4 text-[14px] text-crit">{{ ctxError }}</p>

    <template v-else-if="options">
      <div v-if="options.preview" class="mb-4 flex items-center gap-2 rounded-md border border-aqua-300 bg-aqua-50 dark:bg-aqua-800/25 px-3 py-2 text-[13px] text-aqua-700 dark:text-aqua-200">
        <Eye :size="15" class="shrink-0" />
        <span>{{ t('sreq.previewBanner') }}. {{ t('sreq.previewHint') }}</span>
      </div>
      <p v-if="notLinked" class="panel p-4 mb-4 text-[14px] text-warn-dark dark:text-warn-light" data-not-linked>{{ t('sreq.notLinked') }}</p>
      <p v-else-if="!options.can_write && !options.preview" class="mb-4 rounded-md bg-surface-sunken px-3 py-2 text-[13px] text-ink-muted">{{ t('sreq.readOnly') }}</p>

      <div class="panel p-3 mb-4 flex flex-wrap items-center gap-2.5">
        <div class="inline-flex flex-wrap rounded-md border border-line p-0.5 bg-surface-sunken/60" role="tablist">
          <button
            v-for="tab in TABS" :key="tab" type="button" :data-tab="tab"
            class="px-3 h-8 rounded text-[13px] font-medium transition-colors"
            :class="filters.tab === tab ? 'bg-surface text-ink shadow-sm' : 'text-ink-muted hover:text-ink'"
            @click="filters.tab = tab"
          >{{ t(`sreq.tabs.${tab}`) }}</button>
        </div>
        <div class="relative flex-1 min-w-[200px]">
          <Search :size="16" class="absolute left-3 top-1/2 -translate-y-1/2 text-ink-faint" />
          <input v-model="filters.search" class="field-input pl-9" :placeholder="t('sreq.search')">
        </div>
        <select v-if="multiDept" v-model="filters.department_id" class="field-input w-auto min-w-[200px]">
          <option value="">{{ t('intake.portal.allDepartments') }}</option>
          <option v-for="d in departments" :key="d.id" :value="d.id">{{ d.name }}</option>
        </select>
      </div>

      <div class="panel overflow-hidden">
        <div v-if="loading && !items.length" class="p-4 space-y-2.5">
          <div v-for="i in 4" :key="i" class="h-12 rounded bg-surface-sunken animate-pulse" />
        </div>
        <EmptyState v-else-if="!items.length" :icon="ClipboardList" :title="t('sreq.empty')" :hint="options.can_write ? t('sreq.emptyHint') : (canOnBehalf ? t('sreq.previewHint') : '')" />
        <div v-else class="overflow-x-auto">
          <table class="w-full text-[13.5px]">
            <thead>
              <tr class="border-b border-line bg-surface-sunken/60 text-left text-[12px] uppercase tracking-wide text-ink-muted">
                <th class="px-4 py-2.5 font-medium">{{ t('sreq.col.number') }}</th>
                <th v-if="multiDept" class="px-4 py-2.5 font-medium">{{ t('sreq.col.department') }}</th>
                <th class="px-4 py-2.5 font-medium">{{ t('sreq.col.samples') }}</th>
                <th class="px-4 py-2.5 font-medium">{{ t('sreq.col.status') }}</th>
                <th class="px-4 py-2.5 font-medium text-right">{{ t('sreq.col.updated') }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-line">
              <tr v-for="row in items" :key="row.id" class="hover:bg-surface-sunken/60 cursor-pointer transition-colors" :data-request="row.id" @click="open(row)">
                <td class="px-4 py-2.5">
                  <span class="font-mono font-semibold text-ink">{{ row.number || t('sreq.status.draft') }}</span>
                  <span v-if="row.codes?.length" class="block text-[12px] text-ink-faint font-mono truncate max-w-[260px]">{{ row.codes.join(', ') }}</span>
                </td>
                <td v-if="multiDept" class="px-4 py-2.5 text-ink-muted">{{ row.department?.name }}</td>
                <td class="px-4 py-2.5 tnum text-ink-muted whitespace-nowrap">
                  {{ t('sreq.nSamples', { n: row.counts.items }) }}
                  <span v-if="row.counts.received" class="block text-[12px] text-ok-dark dark:text-ok-light">{{ t('sreq.progress', { r: row.counts.received, n: row.counts.items }) }}</span>
                </td>
                <td class="px-4 py-2.5"><StatusPill :status="row.status" /></td>
                <td class="px-4 py-2.5 text-right text-ink-muted tnum whitespace-nowrap">{{ formatDateTime(row.updated_at) }}</td>
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
    </template>
  </div>
</template>
