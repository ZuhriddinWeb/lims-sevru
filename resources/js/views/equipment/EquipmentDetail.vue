<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { ArrowLeft, CalendarClock, History, Lock, Paperclip, Plus, Wrench } from 'lucide-vue-next'
import api from '@/lib/api'
import StatusBadge from '@/components/ui/StatusBadge.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import DocumentPanel from '@/components/crud/DocumentPanel.vue'
import { useAuthStore } from '@/stores/auth'
import { useUiStore } from '@/stores/ui'
import { formatDate, formatNumber } from '@/lib/format'
import { localized } from '@/i18n'

const route = useRoute()
const router = useRouter()
const { t } = useI18n()
const auth = useAuthStore()
const ui = useUiStore()

const item = ref(null)
const loading = ref(true)
const saving = ref(false)
const tab = ref('calibrations')

async function load() {
  loading.value = true
  try {
    const { data } = await api.get(`/equipment/${route.params.id}`)
    item.value = data
  } catch {
    ui.error(t('toast.error'))
  } finally {
    loading.value = false
  }
}
onMounted(load)

const canEdit = computed(() => auth.can('equipment.update'))
const canUploadDocs = computed(() => auth.can('documents.create') || auth.can('equipment.update'))
const canDeleteDocs = computed(() => auth.can('documents.delete') || auth.can('equipment.delete'))

// TZ 1.9 — hujjat turlari
const DOC_TYPES = [
  { value: 'calibration_certificate', labelKey: 'document.calibration_certificate' },
  { value: 'maintenance_act', labelKey: 'document.maintenance_act' },
  { value: 'manual', labelKey: 'document.manual' },
  { value: 'other', labelKey: 'document.other' },
]

// TZ 1.4 — to'liq o'zgarishlar tarixi
const history = ref([])
const historyLoading = ref(false)

async function loadHistory() {
  if (history.value.length || historyLoading.value) return
  historyLoading.value = true
  try {
    const { data } = await api.get(`/equipment/${route.params.id}/history`)
    history.value = data
  } catch {
    history.value = []
  } finally {
    historyLoading.value = false
  }
}

const TABS = [
  { key: 'calibrations', icon: CalendarClock, labelKey: 'equipment.calibrations' },
  { key: 'maintenances', icon: Wrench, labelKey: 'equipment.maintenances' },
  { key: 'documents', icon: Paperclip, labelKey: 'document.title' },
  { key: 'history', icon: History, labelKey: 'usage.history' },
]

const HISTORY_TONE = {
  calibration: 'bg-assay-100 dark:bg-assay-900/40 text-assay-700 dark:text-assay-200',
  maintenance: 'bg-warn-light dark:bg-warn-dark/30 text-warn-dark dark:text-warn-light',
  usage:       'bg-aqua-50 dark:bg-aqua-800/35 text-aqua-700 dark:text-aqua-200',
  change:      'bg-surface-sunken text-ink-muted',
}

watch(tab, (v) => { if (v === 'history') loadHistory() })

/* Kalibrovka formasi */
const calOpen = ref(false)
const calForm = reactive({
  type: 'calibration', performed_at: '', performed_by: '',
  result: 'passed', certificate_number: '', valid_until: '', notes: '',
})

function openCal() {
  Object.assign(calForm, {
    type: 'calibration', performed_at: new Date().toISOString().slice(0, 10),
    performed_by: '', result: 'passed', certificate_number: '', valid_until: '', notes: '',
  })
  calOpen.value = true
}

async function saveCal() {
  saving.value = true
  try {
    await api.post(`/equipment/${item.value.id}/calibrations`, calForm)
    ui.success(t('toast.created'))
    calOpen.value = false
    await load()
  } catch (e) {
    ui.error(e.response?.data?.message || t('toast.error'))
  } finally {
    saving.value = false
  }
}

/* Texnik xizmat formasi */
const mntOpen = ref(false)
const mntForm = reactive({
  type: 'scheduled', status: 'done', scheduled_at: '', performed_at: '',
  performed_by: '', description: '', components_replaced: '', cost: '',
})

function openMnt() {
  Object.assign(mntForm, {
    type: 'scheduled', status: 'done', scheduled_at: '',
    performed_at: new Date().toISOString().slice(0, 10),
    performed_by: '', description: '', components_replaced: '', cost: '',
  })
  mntOpen.value = true
}

async function saveMnt() {
  saving.value = true
  try {
    await api.post(`/equipment/${item.value.id}/maintenances`, mntForm)
    ui.success(t('toast.created'))
    mntOpen.value = false
    await load()
  } catch (e) {
    ui.error(e.response?.data?.message || t('toast.error'))
  } finally {
    saving.value = false
  }
}

const facts = computed(() => {
  const e = item.value
  if (!e) return []
  return [
    ['equipment.model', e.model],
    ['equipment.serialNumber', e.serial_number],
    ['equipment.inventoryNumber', e.inventory_number],
    ['catalog.laboratory', localized(e.laboratory, 'name')],
    ['catalog.groups', localized(e.group, 'name')],
    ['equipment.manufacturer', e.manufacturer],
    ['equipment.supplier', e.supplier],
    ['equipment.location', e.location],
    ['equipment.commissionedAt', formatDate(e.commissioned_at)],
    ['equipment.lastCalibration', formatDate(e.last_calibration_at)],
    ['equipment.nextCalibration', formatDate(e.next_calibration_at)],
    ['equipment.lastMaintenance', formatDate(e.last_maintenance_at)],
    ['equipment.nextMaintenance', formatDate(e.next_maintenance_at)],
    ['usage.usageCount', e.max_usage_before_calibration
      ? `${e.usage_count ?? 0} / ${e.max_usage_before_calibration}`
      : String(e.usage_count ?? 0)],
  ]
})
</script>

<template>
  <div v-if="loading" class="space-y-4">
    <div class="h-8 w-56 rounded bg-surface-sunken animate-pulse" />
    <div class="h-40 panel animate-pulse" />
  </div>

  <div v-else-if="item">
    <div class="flex flex-wrap items-start justify-between gap-4 mb-5">
      <div class="flex items-start gap-3 min-w-0">
        <button class="btn-ghost btn-icon mt-0.5" @click="router.push({ name: 'equipment' })">
          <ArrowLeft :size="18" />
        </button>
        <div class="min-w-0">
          <div class="flex items-center gap-3 flex-wrap">
            <h1 class="text-[22px] font-semibold tracking-[-.01em] text-ink">{{ item.name }}</h1>
            <StatusBadge :status="item.status" />
            <span v-if="item.is_blocked"
                  class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-crit-light dark:bg-crit-dark/25 text-crit-dark dark:text-crit-light text-[12px] font-medium">
              <Lock :size="12" /> {{ t('equipment.blocked') }}
            </span>
          </div>
          <p class="mt-1 text-[14px] text-ink-muted font-mono">{{ item.serial_number }}</p>
          <p v-if="item.is_blocked && item.block_reason" class="mt-1.5 text-[13px] text-crit">{{ item.block_reason }}</p>
        </div>
      </div>
    </div>

    <div class="grid gap-5 lg:grid-cols-[1fr_320px]">
      <section class="panel overflow-hidden">
        <!-- Tablar -->
        <div class="flex items-center gap-1 px-2 pt-2 border-b border-line">
          <button
            v-for="tb in TABS" :key="tb.key"
            class="flex items-center gap-2 px-3 py-2.5 text-[13.5px] font-medium rounded-t-md transition-colors relative whitespace-nowrap"
            :class="tab === tb.key ? 'text-ink' : 'text-ink-muted hover:text-ink'"
            @click="tab = tb.key"
          >
            <component :is="tb.icon" :size="15" />
            {{ t(tb.labelKey) }}
            <span v-if="tab === tb.key" class="absolute inset-x-2 -bottom-px h-0.5 rounded-t bg-assay-500" />
          </button>

          <button v-if="canEdit && ['calibrations', 'maintenances'].includes(tab)" class="btn-ghost btn-sm ml-auto mb-1"
                  @click="tab === 'calibrations' ? openCal() : openMnt()">
            <Plus :size="15" />
            {{ tab === 'calibrations' ? t('equipment.addCalibration') : t('equipment.addMaintenance') }}
          </button>
        </div>

        <!-- Kalibrovkalar -->
        <template v-if="tab === 'calibrations'">
          <EmptyState v-if="!item.calibrations?.length" :icon="CalendarClock" :title="t('common.noData')" />
          <table v-else class="w-full text-left">
            <thead>
              <tr class="border-b border-line bg-surface-sunken/50 text-[12.5px] font-semibold text-ink-muted">
                <th class="px-4 py-2.5">{{ t('equipment.performedAt') }}</th>
                <th class="px-4 py-2.5">{{ t('equipment.type') }}</th>
                <th class="px-4 py-2.5">{{ t('equipment.performedBy') }}</th>
                <th class="px-4 py-2.5">{{ t('equipment.certificateNumber') }}</th>
                <th class="px-4 py-2.5">{{ t('equipment.validUntil') }}</th>
                <th class="px-4 py-2.5">{{ t('equipment.result') }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-line">
              <tr v-for="c in item.calibrations" :key="c.id" class="hover:bg-surface-sunken/60 transition-colors">
                <td class="px-4 py-3 text-[13.5px] text-ink tnum">{{ formatDate(c.performed_at) }}</td>
                <td class="px-4 py-3 text-[13px] text-ink-muted">
                  {{ c.type === 'calibration' ? t('equipment.calibration') : t('equipment.result') }}
                </td>
                <td class="px-4 py-3 text-[13px] text-ink-muted">{{ c.performed_by ?? '—' }}</td>
                <td class="px-4 py-3 text-[13px] text-ink-muted font-mono">{{ c.certificate_number ?? '—' }}</td>
                <td class="px-4 py-3 text-[13px] text-ink-muted tnum">{{ formatDate(c.valid_until) }}</td>
                <td class="px-4 py-3"><StatusBadge :status="c.result" size="sm" /></td>
              </tr>
            </tbody>
          </table>
        </template>

        <!-- Hujjatlar (TZ 1.9) -->
        <DocumentPanel
          v-else-if="tab === 'documents'"
          :endpoint="`/equipment/${item.id}/documents`"
          :types="DOC_TYPES"
          :can-upload="canUploadDocs"
          :can-delete="canDeleteDocs"
        />

        <!-- To'liq tarix (TZ 1.4) -->
        <template v-else-if="tab === 'history'">
          <div v-if="historyLoading" class="p-4 space-y-2">
            <div v-for="i in 4" :key="i" class="h-10 rounded bg-surface-sunken animate-pulse" />
          </div>
          <EmptyState v-else-if="!history.length" :icon="History" :title="t('usage.historyEmpty')" />
          <ol v-else class="divide-y divide-line">
            <li v-for="(ev, i) in history" :key="i"
                class="flex items-start gap-3 px-4 py-3 animate-row-in"
                :style="{ animationDelay: `${Math.min(i * 20, 200)}ms` }">
              <span class="px-2 py-0.5 rounded text-[11px] font-medium shrink-0 mt-0.5" :class="HISTORY_TONE[ev.type]">
                {{ t(`usage.event${ev.type.charAt(0).toUpperCase() + ev.type.slice(1)}`) }}
              </span>
              <div class="min-w-0 flex-1">
                <p class="text-[13.5px] text-ink">{{ ev.title }}</p>
                <p v-if="ev.description" class="text-[12.5px] text-ink-muted truncate">{{ ev.description }}</p>
              </div>
              <div class="text-right shrink-0">
                <p class="text-[12.5px] text-ink-muted tnum">{{ formatDate(ev.date) }}</p>
                <p v-if="ev.user" class="text-[11.5px] text-ink-faint">{{ ev.user }}</p>
              </div>
              <StatusBadge v-if="ev.status" :status="ev.status" size="sm" />
            </li>
          </ol>
        </template>

        <!-- Texnik xizmat -->
        <template v-else-if="tab === 'maintenances'">
          <EmptyState v-if="!item.maintenances?.length" :icon="Wrench" :title="t('common.noData')" />
          <table v-else class="w-full text-left">
            <thead>
              <tr class="border-b border-line bg-surface-sunken/50 text-[12.5px] font-semibold text-ink-muted">
                <th class="px-4 py-2.5">{{ t('equipment.performedAt') }}</th>
                <th class="px-4 py-2.5">{{ t('equipment.type') }}</th>
                <th class="px-4 py-2.5">{{ t('equipment.componentsReplaced') }}</th>
                <th class="px-4 py-2.5 text-right">{{ t('equipment.cost') }}</th>
                <th class="px-4 py-2.5">{{ t('status.label') }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-line">
              <tr v-for="m in item.maintenances" :key="m.id" class="hover:bg-surface-sunken/60 transition-colors">
                <td class="px-4 py-3 text-[13.5px] text-ink tnum">{{ formatDate(m.performed_at ?? m.scheduled_at) }}</td>
                <td class="px-4 py-3 text-[13px] text-ink-muted">
                  {{ m.type === 'repair' ? t('equipment.repair')
                     : m.type === 'component_replacement' ? t('equipment.componentReplacement')
                     : t('equipment.scheduled') }}
                </td>
                <td class="px-4 py-3 text-[13px] text-ink-muted">{{ m.components_replaced ?? m.description ?? '—' }}</td>
                <td class="px-4 py-3 text-[13px] text-ink-muted text-right tnum">{{ m.cost ? formatNumber(m.cost, 2) : '—' }}</td>
                <td class="px-4 py-3"><StatusBadge :status="m.status" size="sm" /></td>
              </tr>
            </tbody>
          </table>
        </template>
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

    <!-- Kalibrovka modali -->
    <BaseModal v-model="calOpen" size="md" :title="t('equipment.addCalibration')" :subtitle="item.name">
      <div class="grid gap-4 sm:grid-cols-2">
        <div>
          <label class="field-label">{{ t('equipment.type') }}</label>
          <select v-model="calForm.type" class="field-input">
            <option value="calibration">{{ t('equipment.calibration') }}</option>
            <option value="verification">{{ t('equipment.result') }}</option>
          </select>
        </div>
        <div>
          <label class="field-label">{{ t('equipment.result') }}</label>
          <select v-model="calForm.result" class="field-input">
            <option value="passed">{{ t('equipment.passed') }}</option>
            <option value="failed">{{ t('equipment.failed') }}</option>
          </select>
        </div>
        <div><label class="field-label">{{ t('equipment.performedAt') }}</label>
             <input v-model="calForm.performed_at" type="date" class="field-input tnum" /></div>
        <div><label class="field-label">{{ t('equipment.validUntil') }}</label>
             <input v-model="calForm.valid_until" type="date" class="field-input tnum" /></div>
        <div><label class="field-label">{{ t('equipment.performedBy') }}</label>
             <input v-model="calForm.performed_by" class="field-input" /></div>
        <div><label class="field-label">{{ t('equipment.certificateNumber') }}</label>
             <input v-model="calForm.certificate_number" class="field-input" /></div>
        <div class="sm:col-span-2"><label class="field-label">{{ t('equipment.notes') }}</label>
             <textarea v-model="calForm.notes" class="field-input" rows="2" /></div>
      </div>
      <template #footer>
        <button class="btn-ghost" @click="calOpen = false">{{ t('common.cancel') }}</button>
        <button class="btn-primary" :disabled="saving" @click="saveCal">{{ t('common.save') }}</button>
      </template>
    </BaseModal>

    <!-- Texnik xizmat modali -->
    <BaseModal v-model="mntOpen" size="md" :title="t('equipment.addMaintenance')" :subtitle="item.name">
      <div class="grid gap-4 sm:grid-cols-2">
        <div>
          <label class="field-label">{{ t('equipment.type') }}</label>
          <select v-model="mntForm.type" class="field-input">
            <option value="scheduled">{{ t('equipment.scheduled') }}</option>
            <option value="repair">{{ t('equipment.repair') }}</option>
            <option value="component_replacement">{{ t('equipment.componentReplacement') }}</option>
          </select>
        </div>
        <div>
          <label class="field-label">{{ t('status.label') }}</label>
          <select v-model="mntForm.status" class="field-input">
            <option value="planned">{{ t('status.planned') }}</option>
            <option value="in_progress">{{ t('status.in_progress') }}</option>
            <option value="done">{{ t('status.done') }}</option>
            <option value="cancelled">{{ t('status.cancelled') }}</option>
          </select>
        </div>
        <div><label class="field-label">{{ t('equipment.scheduledAt') }}</label>
             <input v-model="mntForm.scheduled_at" type="date" class="field-input tnum" /></div>
        <div><label class="field-label">{{ t('equipment.performedAt') }}</label>
             <input v-model="mntForm.performed_at" type="date" class="field-input tnum" /></div>
        <div><label class="field-label">{{ t('equipment.performedBy') }}</label>
             <input v-model="mntForm.performed_by" class="field-input" /></div>
        <div><label class="field-label">{{ t('equipment.cost') }}</label>
             <input v-model="mntForm.cost" type="number" step="0.01" class="field-input tnum" /></div>
        <div class="sm:col-span-2"><label class="field-label">{{ t('equipment.componentsReplaced') }}</label>
             <input v-model="mntForm.components_replaced" class="field-input" /></div>
        <div class="sm:col-span-2"><label class="field-label">{{ t('testObject.description') }}</label>
             <textarea v-model="mntForm.description" class="field-input" rows="2" /></div>
      </div>
      <template #footer>
        <button class="btn-ghost" @click="mntOpen = false">{{ t('common.cancel') }}</button>
        <button class="btn-primary" :disabled="saving" @click="saveMnt">{{ t('common.save') }}</button>
      </template>
    </BaseModal>
  </div>
</template>
