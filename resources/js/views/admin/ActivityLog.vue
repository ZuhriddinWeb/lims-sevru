<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { ScrollText } from 'lucide-vue-next'
import api from '@/lib/api'
import PageHeader from '@/components/ui/PageHeader.vue'
import Pagination from '@/components/ui/Pagination.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import TableSkeleton from '@/components/ui/TableSkeleton.vue'
import { formatDateTime } from '@/lib/format'

const { t } = useI18n()

const rows = ref([])
const meta = ref({})
const loading = ref(true)

const params = reactive({ page: 1, per_page: 20, action: null, date_from: '', date_to: '' })

const ACTIONS = ['created', 'updated', 'deleted', 'login', 'logout']

const TONE = {
  created: 'bg-ok-light text-ok-dark dark:bg-ok-dark/25 dark:text-ok-light',
  updated: 'bg-warn-light text-warn-dark dark:bg-warn-dark/25 dark:text-warn-light',
  deleted: 'bg-crit-light text-crit-dark dark:bg-crit-dark/25 dark:text-crit-light',
  login:   'bg-aqua-50 text-aqua-700 dark:bg-aqua-800/35 dark:text-aqua-200',
  logout:  'bg-surface-sunken text-ink-muted',
}

async function load() {
  loading.value = true
  try {
    const clean = Object.fromEntries(Object.entries(params).filter(([, v]) => v !== '' && v !== null))
    const { data } = await api.get('/activity-logs', { params: clean })
    rows.value = data.data
    meta.value = {
      current_page: data.current_page, last_page: data.last_page,
      from: data.from, to: data.to, total: data.total,
    }
  } finally {
    loading.value = false
  }
}

onMounted(load)
watch(() => [params.page, params.per_page, params.action, params.date_from, params.date_to], load)
</script>

<template>
  <div>
    <PageHeader :title="t('log.title')" :subtitle="t('log.subtitle')" />

    <div class="flex flex-wrap items-end gap-3 mb-4">
      <div class="w-44">
        <label class="field-label">{{ t('log.action') }}</label>
        <select v-model="params.action" class="field-input" @change="params.page = 1">
          <option :value="null">{{ t('common.all') }}</option>
          <option v-for="a in ACTIONS" :key="a" :value="a">{{ t(`log.${a}`) }}</option>
        </select>
      </div>
      <div class="w-40">
        <label class="field-label">{{ t('common.dateFrom') }}</label>
        <input v-model="params.date_from" type="date" class="field-input tnum" @change="params.page = 1" />
      </div>
      <div class="w-40">
        <label class="field-label">{{ t('common.dateTo') }}</label>
        <input v-model="params.date_to" type="date" class="field-input tnum" @change="params.page = 1" />
      </div>
    </div>

    <div class="panel overflow-hidden">
      <TableSkeleton v-if="loading && !rows.length" :cols="5" />
      <EmptyState v-else-if="!rows.length" :icon="ScrollText" :title="t('common.noData')" />

      <template v-else>
        <div class="overflow-x-auto">
          <table class="w-full text-left min-w-[680px]">
            <thead>
              <tr class="border-b border-line bg-surface-sunken/50 text-[12.5px] font-semibold text-ink-muted">
                <th class="px-4 py-2.5 w-[150px]">{{ t('log.when') }}</th>
                <th class="px-4 py-2.5 w-[110px]">{{ t('log.action') }}</th>
                <th class="px-4 py-2.5">{{ t('log.description') }}</th>
                <th class="px-4 py-2.5 w-[150px]">{{ t('log.user') }}</th>
                <th class="px-4 py-2.5 w-[120px]">{{ t('log.ip') }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-line">
              <tr v-for="(row, i) in rows" :key="row.id"
                  class="hover:bg-surface-sunken/60 transition-colors animate-row-in"
                  :style="{ animationDelay: `${Math.min(i * 18, 200)}ms` }">
                <td class="px-4 py-2.5 text-[12.5px] text-ink-muted tnum whitespace-nowrap">
                  {{ formatDateTime(row.created_at) }}
                </td>
                <td class="px-4 py-2.5">
                  <span class="inline-block px-2 py-0.5 rounded text-[11.5px] font-medium" :class="TONE[row.action]">
                    {{ t(`log.${row.action}`) }}
                  </span>
                </td>
                <td class="px-4 py-2.5 text-[13px] text-ink">
                  {{ row.description ?? '—' }}
                  <span v-if="row.model_type" class="text-ink-faint text-[12px] ml-1.5">({{ row.model_type }})</span>
                </td>
                <td class="px-4 py-2.5 text-[13px] text-ink-muted truncate">{{ row.user?.name ?? '—' }}</td>
                <td class="px-4 py-2.5 text-[12.5px] text-ink-faint font-mono">{{ row.ip_address ?? '—' }}</td>
              </tr>
            </tbody>
          </table>
        </div>

        <Pagination :meta="meta" :per-page="params.per_page"
                    @change="params.page = $event" @per-page="params.per_page = $event; params.page = 1" />
      </template>
    </div>
  </div>
</template>
