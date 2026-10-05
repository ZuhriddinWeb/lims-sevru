<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { ArrowLeft, Droplets, ListPlus, PlayCircle, Plus, Trash2 } from 'lucide-vue-next'
import api from '@/lib/api'
import StatusBadge from '@/components/ui/StatusBadge.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import { useAuthStore } from '@/stores/auth'
import { useUiStore } from '@/stores/ui'
import { formatDateTime, formatNumber, todayISO } from '@/lib/format'
import { localized } from '@/i18n'

const route = useRoute()
const router = useRouter()
const { t } = useI18n()
const auth = useAuthStore()
const ui = useUiStore()

const order = ref(null)
const loading = ref(true)
const saving = ref(false)
const rows = ref([])
const equipmentList = ref([])

async function loadEquipment() {
  try {
    const { data } = await api.get('/equipment', { params: { all: 1, usable: 1 } })
    equipmentList.value = Array.isArray(data) ? data : (data.data ?? [])
  } catch {
    equipmentList.value = []
  }
}

// Monitoring nuqtasining standart ko'rsatkichlari va me'yorlarini qatorlar sifatida qo'yish
async function loadPointParameters() {
  const pointId = order.value?.monitoring_point_id
  if (!pointId) return
  try {
    const { data } = await api.get(`/monitoring-points/${pointId}/parameters`)
    const list = Array.isArray(data) ? data : (data.data ?? [])
    if (!list.length) {
      ui.info(t('common.noData'))
      return
    }
    const filled = rows.value.filter((r) => r.parameter)
    const existing = new Set(filled.map((r) => r.parameter))
    const added = list
      .filter((p) => !existing.has(p.parameter))
      .map((p) => ({
        parameter: p.parameter,
        value: null,
        unit: p.unit ?? '',
        norm_min: p.norm_min,
        norm_max: p.norm_max,
        equipment_id: null,
        measured_at: todayISO(),
      }))
    rows.value = [...filled, ...added]
    if (!rows.value.length) addRow()
    ui.success(t('usage.parametersLoaded'))
  } catch {
    ui.error(t('toast.error'))
  }
}

async function load() {
  loading.value = true
  try {
    const { data } = await api.get(`/water-orders/${route.params.id}`)
    order.value = data
    rows.value = (data.results ?? []).map((r) => ({
      parameter: r.parameter, value: r.value, unit: r.unit,
      norm_min: r.norm_min, norm_max: r.norm_max,
      equipment_id: r.equipment_id ?? null,
      measured_at: r.measured_at ? String(r.measured_at).slice(0, 10) : todayISO(),
      assessment: r.assessment,
    }))
    if (!rows.value.length) addRow()
  } catch {
    ui.error(t('toast.error'))
  } finally {
    loading.value = false
  }
}
onMounted(() => { load(); loadEquipment() })

const canEdit = computed(() => auth.can('water_orders.result'))

function addRow() {
  rows.value.push({ parameter: '', value: null, unit: '', norm_min: null, norm_max: null, equipment_id: null, measured_at: todayISO() })
}

function removeRow(i) {
  rows.value.splice(i, 1)
  if (!rows.value.length) addRow()
}

// Me'yor chegarasiga qarab oldindan baholash (server ham qayta hisoblaydi)
function preview(row) {
  if (row.value === null || row.value === '') return null
  const v = Number(row.value)
  if (row.norm_min !== null && row.norm_min !== '' && v < Number(row.norm_min)) return 'critical'
  if (row.norm_max !== null && row.norm_max !== '' && v > Number(row.norm_max)) return 'critical'
  if (row.norm_max !== null && row.norm_max !== '' && v > Number(row.norm_max) * 0.9) return 'warning'
  return 'normal'
}

async function takeToWork() {
  saving.value = true
  try {
    const { data } = await api.patch(`/water-orders/${order.value.id}/take`, {})
    order.value = data
    ui.success(t('work.taken'))
  } catch (e) {
    ui.error(e.response?.data?.message || t('toast.error'))
  } finally {
    saving.value = false
  }
}

async function save() {
  saving.value = true
  try {
    const payload = rows.value.filter((r) => r.parameter)
    const { data } = await api.put(`/water-orders/${order.value.id}/results`, { results: payload })
    order.value = data
    ui.success(t('water.resultsSaved'))
  } catch (e) {
    const errs = e.response?.data?.errors ?? {}
    const first = Object.values(errs)[0]
    ui.error((Array.isArray(first) ? first[0] : null) || e.response?.data?.message || t('toast.error'))
  } finally {
    saving.value = false
  }
}

const facts = computed(() => {
  const o = order.value
  if (!o) return []
  return [
    ['water.sourceDocument', o.source_document],
    ['water.division', o.division],
    ['water.assignedGroup', localized(o.assigned_group, 'name')],
    ['water.monitoringPoint', o.monitoring_point ? `${o.monitoring_point.code} · ${o.monitoring_point.name}` : null],
    ['water.registeredAt', formatDateTime(o.registered_at)],
    ['work.startedAt', o.started_at ? `${formatDateTime(o.started_at)}${o.starter ? ' · ' + o.starter.name : ''}` : null],
    ['water.completedAt', formatDateTime(o.completed_at)],
  ]
})
</script>

<template>
  <div v-if="loading" class="h-40 panel animate-pulse" />

  <div v-else-if="order">
    <div class="flex flex-wrap items-start justify-between gap-4 mb-5">
      <div class="flex items-start gap-3 min-w-0">
        <button class="btn-ghost btn-icon mt-0.5" @click="router.push({ name: 'water-orders' })">
          <ArrowLeft :size="18" />
        </button>
        <div class="min-w-0">
          <div class="flex items-center gap-3 flex-wrap">
            <h1 class="text-[22px] font-semibold tracking-[-.01em] text-ink font-mono">{{ order.order_number }}</h1>
            <StatusBadge :status="order.status" />
          </div>
          <p class="mt-1 text-[14px] text-ink-muted">
            {{ order.monitoring_point?.name ?? localized(order.assigned_group, 'name') }}
          </p>
        </div>
      </div>

      <div class="flex items-center gap-2">
        <button
          v-if="canEdit && !order.started_at"
          class="btn-outline" :disabled="saving" @click="takeToWork"
        >
          <PlayCircle :size="16" /> {{ t('work.take') }}
        </button>
        <button v-if="canEdit" class="btn-primary" :disabled="saving" @click="save">
          {{ saving ? t('common.loading') : t('water.saveResults') }}
        </button>
      </div>
    </div>

    <div class="grid gap-5 lg:grid-cols-[1fr_300px]">
      <section class="panel overflow-hidden">
        <header class="flex items-center justify-between gap-2 px-4 py-3.5 border-b border-line">
          <h2 class="text-[14px] font-semibold text-ink">{{ t('water.results') }}</h2>
          <div v-if="canEdit" class="flex items-center gap-1">
            <button v-if="order.monitoring_point_id" class="btn-ghost btn-sm" @click="loadPointParameters">
              <ListPlus :size="15" /> {{ t('usage.loadPointParameters') }}
            </button>
            <button class="btn-ghost btn-sm" @click="addRow">
              <Plus :size="15" /> {{ t('water.addRow') }}
            </button>
          </div>
        </header>

        <EmptyState v-if="!canEdit && !order.results?.length" :icon="Droplets" :title="t('common.noData')" />

        <!-- Tahrirlanadigan jadval -->
        <div v-else-if="canEdit" class="overflow-x-auto">
          <table class="w-full text-left min-w-[920px]">
            <thead>
              <tr class="border-b border-line bg-surface-sunken/50 text-[12.5px] font-semibold text-ink-muted">
                <th class="px-3 py-2.5 w-[20%]">{{ t('water.parameter') }}</th>
                <th class="px-3 py-2.5 w-[11%]">{{ t('water.value') }}</th>
                <th class="px-3 py-2.5 w-[9%]">{{ t('water.unit') }}</th>
                <th class="px-3 py-2.5 w-[10%]">{{ t('water.normMin') }}</th>
                <th class="px-3 py-2.5 w-[10%]">{{ t('water.normMax') }}</th>
                <th class="px-3 py-2.5 w-[16%]">{{ t('usage.equipment') }}</th>
                <th class="px-3 py-2.5 w-[12%]">{{ t('water.measuredAt') }}</th>
                <th class="px-3 py-2.5">{{ t('water.assessment') }}</th>
                <th class="px-3 py-2.5 w-px" />
              </tr>
            </thead>
            <tbody class="divide-y divide-line">
              <tr v-for="(row, i) in rows" :key="i" class="animate-row-in" :style="{ animationDelay: `${i * 25}ms` }">
                <td class="px-3 py-2"><input v-model="row.parameter" class="field-input h-9" :placeholder="t('water.parameter')" /></td>
                <td class="px-3 py-2"><input v-model="row.value" type="number" step="any" class="field-input h-9 tnum" /></td>
                <td class="px-3 py-2"><input v-model="row.unit" class="field-input h-9" /></td>
                <td class="px-3 py-2"><input v-model="row.norm_min" type="number" step="any" class="field-input h-9 tnum" /></td>
                <td class="px-3 py-2"><input v-model="row.norm_max" type="number" step="any" class="field-input h-9 tnum" /></td>
                <td class="px-3 py-2">
                  <select v-model="row.equipment_id" class="field-input h-9">
                    <option :value="null">—</option>
                    <option v-for="eq in equipmentList" :key="eq.id" :value="eq.id">{{ eq.name }}</option>
                  </select>
                </td>
                <td class="px-3 py-2"><input v-model="row.measured_at" type="date" class="field-input h-9 tnum" /></td>
                <td class="px-3 py-2"><StatusBadge v-if="preview(row)" :status="preview(row)" size="sm" /></td>
                <td class="px-3 py-2">
                  <button class="btn-ghost btn-sm btn-icon hover:text-crit" @click="removeRow(i)">
                    <Trash2 :size="15" />
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- Faqat ko'rish -->
        <table v-else class="w-full text-left">
          <thead>
            <tr class="border-b border-line bg-surface-sunken/50 text-[12.5px] font-semibold text-ink-muted">
              <th class="px-4 py-2.5">{{ t('water.parameter') }}</th>
              <th class="px-4 py-2.5 text-right">{{ t('water.value') }}</th>
              <th class="px-4 py-2.5">{{ t('water.unit') }}</th>
              <th class="px-4 py-2.5">{{ t('usage.equipment') }}</th>
              <th class="px-4 py-2.5">{{ t('water.assessment') }}</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-line">
            <tr v-for="r in order.results" :key="r.id" class="hover:bg-surface-sunken/60 transition-colors">
              <td class="px-4 py-3 text-[13.5px] text-ink">{{ r.parameter }}</td>
              <td class="px-4 py-3 text-right text-[14px] text-ink tnum font-medium">{{ formatNumber(r.value) }}</td>
              <td class="px-4 py-3 text-[13px] text-ink-muted">{{ r.unit ?? '—' }}</td>
              <td class="px-4 py-3 text-[13px] text-ink-muted">{{ r.equipment?.name ?? '—' }}</td>
              <td class="px-4 py-3"><StatusBadge :status="r.assessment" size="sm" /></td>
            </tr>
          </tbody>
        </table>
      </section>

      <aside class="panel p-4 h-fit">
        <h2 class="text-[13px] font-semibold text-ink mb-3.5">{{ t('common.details') }}</h2>
        <dl class="space-y-3">
          <div v-for="[key, value] in facts" :key="key">
            <dt class="text-[11.5px] text-ink-faint">{{ t(key) }}</dt>
            <dd class="text-[13.5px] text-ink mt-0.5">{{ value || '—' }}</dd>
          </div>
        </dl>
      </aside>
    </div>
  </div>
</template>
