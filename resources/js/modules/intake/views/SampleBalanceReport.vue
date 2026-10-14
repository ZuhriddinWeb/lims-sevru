<script setup>
/**
 * «Proba qoldiqlari» — qabul qilingan miqdor, asosiy / nazorat tahlillariga va boshqa sarf, qoldiq.
 * Qatorni bosish — proba kartochkasi (u yerda tafsilot va harakatlar).
 */
import { onMounted, reactive, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { ChevronLeft, ChevronRight, Scale, Search } from 'lucide-vue-next'
import api from '@/lib/api'
import PageHeader from '@/components/ui/PageHeader.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import { useUiStore } from '@/stores/ui'
import { formatDate } from '@/lib/format'
import { localized } from '@/i18n'
import { STATE_TONE, apiErrorMessage, formatQty, unitLabel } from '../helpers'
import PeriodFilter from '../components/PeriodFilter.vue'
import '../i18n'

const { t, locale } = useI18n()
const router = useRouter()
const ui = useUiStore()

const items = ref([])
const departments = ref([])
const meta = reactive({ current_page: 1, last_page: 1, total: 0, from: 0, to: 0 })
const loading = ref(true)
const filters = reactive({ search: '', customer_department_id: '', quantity: 'with', state: '', date_from: '', date_to: '' })
let timer = null

async function load(page = 1) {
  loading.value = true
  try {
    const { data } = await api.get('/sample-balances', {
      params: {
        page, per_page: 25,
        search: filters.search || undefined,
        customer_department_id: filters.customer_department_id || undefined,
        quantity: filters.quantity,
        state: filters.state || undefined,
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
  load(1)
  try {
    const { data } = await api.get('/customer-departments/options')
    departments.value = data
  } catch { /* filtr ixtiyoriy */ }
})
watch(() => [filters.customer_department_id, filters.quantity, filters.state, filters.date_from, filters.date_to], () => load(1))
watch(() => filters.search, () => {
  clearTimeout(timer)
  timer = setTimeout(() => load(1), 300)
})

const q = (v) => formatQty(v, locale.value)
const customerOf = (r) => r.customer_department?.name || localized(r.customer_group, 'name') || '—'
</script>

<template>
  <div>
    <PageHeader :title="t('intake.report.title')" :subtitle="t('intake.report.subtitle')" />

    <div class="panel p-3 mb-4 flex flex-wrap items-center gap-2.5">
      <div class="relative flex-1 min-w-[220px]">
        <Search :size="16" class="absolute left-3 top-1/2 -translate-y-1/2 text-ink-faint" />
        <input v-model="filters.search" class="field-input pl-9" :placeholder="t('intake.report.search')">
      </div>
      <select v-model="filters.customer_department_id" class="field-input w-auto min-w-[190px]">
        <option value="">{{ t('intake.report.allDepartments') }}</option>
        <option v-for="d in departments" :key="d.id" :value="d.id">{{ d.name }}</option>
      </select>
      <select v-model="filters.quantity" class="field-input w-auto" data-f="quantity">
        <option value="with">{{ t('intake.report.quantityWith') }}</option>
        <option value="without">{{ t('intake.report.quantityWithout') }}</option>
        <option value="all">{{ t('intake.report.quantityAll') }}</option>
      </select>
      <select v-model="filters.state" class="field-input w-auto" data-f="state">
        <option value="">{{ t('intake.report.stateAll') }}</option>
        <option value="attention">{{ t('intake.report.stateAttention') }}</option>
        <option v-for="s in ['ok', 'low', 'empty', 'negative']" :key="s" :value="s">{{ t(`intake.panel.state.${s}`) }}</option>
      </select>
      <PeriodFilter v-model:from="filters.date_from" v-model:to="filters.date_to" class="w-full" />
    </div>

    <div class="panel overflow-hidden">
      <div v-if="loading && !items.length" class="p-4 space-y-2.5">
        <div v-for="i in 6" :key="i" class="h-10 rounded bg-surface-sunken animate-pulse" />
      </div>

      <EmptyState v-else-if="!items.length" :icon="Scale" :title="t('intake.report.empty')" />

      <div v-else class="overflow-x-auto">
        <table class="w-full text-[13.5px]">
          <thead>
            <tr class="border-b border-line bg-surface-sunken/60 text-left text-[12px] uppercase tracking-wide text-ink-muted">
              <th class="px-4 py-2.5 font-medium">{{ t('intake.report.sample') }}</th>
              <th class="px-4 py-2.5 font-medium">{{ t('intake.customer') }}</th>
              <th class="px-4 py-2.5 font-medium text-right">{{ t('intake.report.received') }}</th>
              <th class="px-4 py-2.5 font-medium text-right">{{ t('intake.report.main') }}</th>
              <th class="px-4 py-2.5 font-medium text-right">{{ t('intake.report.control') }}</th>
              <th class="px-4 py-2.5 font-medium text-right">{{ t('intake.report.other') }}</th>
              <th class="px-4 py-2.5 font-medium text-right">{{ t('intake.report.balance') }}</th>
              <th class="px-4 py-2.5 font-medium">{{ t('intake.report.state') }}</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-line">
            <tr
              v-for="r in items" :key="r.id"
              class="hover:bg-surface-sunken/60 cursor-pointer transition-colors"
              :data-row="r.id"
              @click="router.push({ name: 'sample-detail', params: { id: r.id } })"
            >
              <td class="px-4 py-2.5">
                <span class="font-mono font-semibold text-ink">{{ r.code }}</span>
                <span class="block text-[12px] text-ink-faint">№ {{ r.sample_number }} · {{ formatDate(r.registered_at) }}</span>
              </td>
              <td class="px-4 py-2.5">
                <span class="text-ink">{{ customerOf(r) }}</span>
                <span v-if="r.customer_code" class="block text-[12px] text-ink-muted">{{ r.customer_code }}</span>
              </td>
              <td class="px-4 py-2.5 text-right tnum text-ink">{{ q(r.received) }} <span class="text-ink-faint">{{ unitLabel(r.unit, t) }}</span></td>
              <td class="px-4 py-2.5 text-right tnum">{{ r.consumed.main ? q(r.consumed.main) : '—' }}</td>
              <td class="px-4 py-2.5 text-right tnum">{{ r.consumed.control ? q(r.consumed.control) : '—' }}</td>
              <td class="px-4 py-2.5 text-right tnum">{{ r.consumed.other ? q(r.consumed.other) : '—' }}</td>
              <td class="px-4 py-2.5 text-right tnum font-semibold" :class="r.balance < 0 ? 'text-crit' : 'text-ink'">{{ q(r.balance) }}</td>
              <td class="px-4 py-2.5">
                <span class="rounded-full px-2 py-0.5 text-[11.5px] font-medium whitespace-nowrap" :class="STATE_TONE[r.state]" :data-state="r.state">{{ t(`intake.panel.state.${r.state}`) }}</span>
              </td>
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
