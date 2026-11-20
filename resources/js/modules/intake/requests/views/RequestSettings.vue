<script setup>
/**
 * [Paket 39] «Buyurtma sozlamalari» (sample_requests.settings — laboratoriya boshlig'i, admin):
 *   umumiy qiymatlar · bo'linmalar (grafik rejimi va unga xos qiymatlar) · yopish sabablari
 */
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { Check, Plus, Save, Settings2, Trash2 } from 'lucide-vue-next'
import api from '@/lib/api'
import PageHeader from '@/components/ui/PageHeader.vue'
import { useUiStore } from '@/stores/ui'
import { apiErrorMessage, fieldError } from '../shared'
import '../i18n'
import '../i18nP39'

const { t } = useI18n()
const ui = useUiStore()

const data = ref(null)
const loading = ref(true)
const loadError = ref('')
const g = ref({})          // umumiy qiymatlar (tahrir)
const gErrors = ref({})
const busy = ref(false)
const depts = ref([])      // tahrir nusxalari
const reasons = ref([])
const newReason = ref(null)

function fill(d) {
  data.value = d
  g.value = { ...d.settings }
  depts.value = d.departments.map((x) => ({ ...x, lead_minutes: x.lead_minutes ?? '', expire_mode: x.expire_mode ?? '', expire_hours: x.expire_hours ?? '', dirty: false }))
  reasons.value = d.reasons.map((r) => ({ ...r, name_ru: r.name_ru ?? '', name_en: r.name_en ?? '', dirty: false }))
}

async function load() {
  loading.value = true
  try {
    fill((await api.get('/request-settings')).data)
  } catch (e) {
    loadError.value = apiErrorMessage(e, t('sreq.loadError'))
  } finally {
    loading.value = false
  }
}
onMounted(load)

const MODES = computed(() => data.value?.expire_modes ?? ['day_end', 'shift_end', 'next_run', 'hours'])
const lim = (k) => data.value?.limits?.[k] ?? [0, 9999]

async function saveGlobal() {
  busy.value = true
  gErrors.value = {}
  try {
    const { data: r } = await api.put('/request-settings', g.value)
    data.value.settings = r.settings
    g.value = { ...r.settings }
    ui.success(t('sreq.p39.set.saved'))
  } catch (e) {
    gErrors.value = e?.response?.data?.errors ?? {}
    ui.error(apiErrorMessage(e, t('sreq.loadError')))
  } finally {
    busy.value = false
  }
}

async function saveDept(d) {
  busy.value = true
  try {
    const { data: r } = await api.put(`/request-settings/departments/${d.id}`, {
      enabled: !!d.enabled, late_minutes: d.late_minutes || null,
      lead_minutes: d.lead_minutes === '' ? null : Number(d.lead_minutes),
      expire_mode: d.expire_mode || null,
      expire_hours: d.expire_mode === 'hours' && d.expire_hours !== '' ? Number(d.expire_hours) : null,
    })
    fill(r)
    ui.success(t('sreq.p39.set.saved'))
  } catch (e) {
    ui.error(apiErrorMessage(e, t('sreq.loadError')))
  } finally {
    busy.value = false
  }
}

const reasonBody = (r) => ({ name_uz: r.name_uz, name_ru: r.name_ru || null, name_en: r.name_en || null, needs_note: !!r.needs_note, sort_order: Number(r.sort_order) || 0, is_active: !!r.is_active })

async function saveReason(r) {
  busy.value = true
  try {
    const { data: x } = await api.put(`/request-settings/reasons/${r.id}`, reasonBody(r))
    Object.assign(r, x, { name_ru: x.name_ru ?? '', name_en: x.name_en ?? '', dirty: false })
    ui.success(t('sreq.p39.set.saved'))
  } catch (e) {
    ui.error(apiErrorMessage(e, t('sreq.loadError')))
  } finally {
    busy.value = false
  }
}

async function addReason() {
  if (!newReason.value?.name_uz?.trim()) return
  busy.value = true
  try {
    const sort = Math.max(0, ...reasons.value.map((r) => r.sort_order)) + 10
    const { data: x } = await api.post('/request-settings/reasons', reasonBody({ ...newReason.value, sort_order: sort, is_active: true }))
    reasons.value.push({ ...x, name_ru: x.name_ru ?? '', name_en: x.name_en ?? '', dirty: false })
    newReason.value = null
  } catch (e) {
    ui.error(apiErrorMessage(e, t('sreq.loadError')))
  } finally {
    busy.value = false
  }
}

async function removeReason(r) {
  if (!window.confirm(t('sreq.p39.set.reasonRemoveConfirm', { name: r.name_uz }))) return
  try {
    const { data: x } = await api.delete(`/request-settings/reasons/${r.id}`)
    if (x.deactivated) {
      r.is_active = false
      ui.info(t('sreq.p39.set.reasonDeactivated'))
    } else {
      reasons.value = reasons.value.filter((y) => y.id !== r.id)
    }
  } catch (e) {
    ui.error(apiErrorMessage(e, t('sreq.loadError')))
  }
}
</script>

<template>
  <div>
    <PageHeader :title="t('sreq.p39.set.title')" />
    <p class="mb-4 max-w-3xl text-[13.5px] leading-relaxed text-ink-muted">{{ t('sreq.p39.set.hint') }}</p>

    <div v-if="loading" class="h-40 rounded-panel bg-surface-sunken animate-pulse" />
    <p v-else-if="loadError" class="panel p-4 text-[14px] text-crit">{{ loadError }}</p>

    <template v-else-if="data">
      <!-- Umumiy -->
      <section class="panel p-4 mb-4" data-global>
        <h2 class="mb-3 flex items-center gap-2 text-[15px] font-semibold text-ink"><Settings2 :size="17" /> {{ t('sreq.p39.set.global') }}</h2>
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
          <label class="block">
            <span class="field-label">{{ t('sreq.p39.set.lead') }}</span>
            <input v-model.number="g.schedule_lead_minutes" type="number" :min="lim('schedule_lead_minutes')[0]" :max="lim('schedule_lead_minutes')[1]" class="field-input" :class="fieldError(gErrors, 'schedule_lead_minutes') && 'field-input-error'" data-g="lead">
            <span class="mt-1 block text-[11.5px] text-ink-faint">{{ t('sreq.p39.set.leadHint') }}</span>
          </label>
          <label class="block">
            <span class="field-label">{{ t('sreq.p39.set.expire') }}</span>
            <select v-model="g.schedule_expire_mode" class="field-input" data-g="mode">
              <option v-for="m in MODES" :key="m" :value="m">{{ t(`sreq.p39.modes.${m}`) }}</option>
            </select>
            <input v-if="g.schedule_expire_mode === 'hours'" v-model.number="g.schedule_expire_hours" type="number" min="1" max="72" class="field-input mt-1.5" :aria-label="t('sreq.p39.set.hours')" data-g="hours">
            <span v-if="g.schedule_expire_mode === 'shift_end' && !data.has_shifts" class="mt-1 block text-[11.5px] text-warn-dark dark:text-warn-light">{{ t('sreq.p39.set.noShifts') }}</span>
          </label>
          <label class="block">
            <span class="field-label">{{ t('sreq.p39.set.lateHours') }}</span>
            <input v-model.number="g.late_entry_hours" type="number" min="1" max="720" class="field-input" :class="fieldError(gErrors, 'late_entry_hours') && 'field-input-error'" data-g="late">
            <span class="mt-1 block text-[11.5px] text-ink-faint">{{ t('sreq.p39.set.lateHoursHint') }}</span>
          </label>
          <label class="block">
            <span class="field-label">{{ t('sreq.p39.set.autoClose') }}</span>
            <input v-model.number="g.auto_close_days" type="number" min="0" max="90" class="field-input" :class="fieldError(gErrors, 'auto_close_days') && 'field-input-error'" data-g="auto">
            <span class="mt-1 block text-[11.5px] text-ink-faint">{{ t('sreq.p39.set.autoCloseHint') }}</span>
          </label>
        </div>
        <div class="mt-3 flex justify-end">
          <button class="btn-primary" :disabled="busy" data-save-global @click="saveGlobal"><Save :size="16" /> {{ t('sreq.p39.set.save') }}</button>
        </div>
      </section>

      <!-- Bo'linmalar -->
      <section class="panel mb-4 overflow-hidden" data-depts>
        <div class="px-4 py-3 border-b border-line">
          <h2 class="text-[15px] font-semibold text-ink">{{ t('sreq.p39.set.depts') }}</h2>
          <p class="mt-1 text-[12.5px] text-ink-muted max-w-3xl">{{ t('sreq.p39.set.deptsHint') }}</p>
        </div>
        <div class="overflow-x-auto">
          <table class="w-full text-[13px]">
            <thead>
              <tr class="text-left text-[11.5px] uppercase tracking-wide text-ink-faint border-b border-line">
                <th class="px-4 py-2 font-medium">{{ t('sreq.col.department') }}</th>
                <th class="px-3 py-2 font-medium">{{ t('sreq.p39.set.enabled') }}</th>
                <th class="px-3 py-2 font-medium">{{ t('sreq.p39.set.lateMin') }}</th>
                <th class="px-3 py-2 font-medium">{{ t('sreq.p39.set.lead') }}</th>
                <th class="px-3 py-2 font-medium">{{ t('sreq.p39.set.expire') }}</th>
                <th class="px-3 py-2 font-medium text-right">{{ t('sreq.p39.set.rows') }}</th>
                <th class="px-3 py-2" />
              </tr>
            </thead>
            <tbody class="divide-y divide-line">
              <tr v-for="d in depts" :key="d.id" :data-dept="d.id" @input="d.dirty = true" @change="d.dirty = true">
                <td class="px-4 py-2 text-ink">{{ d.name }}</td>
                <td class="px-3 py-2"><input v-model="d.enabled" type="checkbox" class="rounded border-line text-assay-500 focus:ring-assay-500" data-d="enabled"></td>
                <td class="px-3 py-2"><input v-model.number="d.late_minutes" type="number" min="5" max="600" class="field-input h-8 w-20" :disabled="!d.enabled"></td>
                <td class="px-3 py-2"><input v-model="d.lead_minutes" type="number" min="0" max="720" class="field-input h-8 w-24" :placeholder="String(data.settings.schedule_lead_minutes)" :disabled="!d.enabled" data-d="lead"></td>
                <td class="px-3 py-2">
                  <div class="flex items-center gap-1.5">
                    <select v-model="d.expire_mode" class="field-input h-8 w-auto" :disabled="!d.enabled" data-d="mode">
                      <option value="">{{ t('sreq.p39.set.useGlobal', { v: t(`sreq.p39.modes.${data.settings.schedule_expire_mode}`) }) }}</option>
                      <option v-for="m in MODES" :key="m" :value="m">{{ t(`sreq.p39.modes.${m}`) }}</option>
                    </select>
                    <input v-if="d.expire_mode === 'hours'" v-model="d.expire_hours" type="number" min="1" max="72" class="field-input h-8 w-16" :placeholder="String(data.settings.schedule_expire_hours)" :aria-label="t('sreq.p39.set.hours')">
                  </div>
                </td>
                <td class="px-3 py-2 text-right tnum text-ink-muted">{{ d.schedules }}</td>
                <td class="px-3 py-2 text-right">
                  <button class="btn-outline btn-sm" :disabled="busy || !d.dirty" :data-save-dept="d.id" @click="saveDept(d)"><Check :size="14" /> {{ t('sreq.p39.set.save') }}</button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <!-- Sabablar -->
      <section class="panel overflow-hidden" data-reasons>
        <div class="px-4 py-3 border-b border-line flex flex-wrap items-center gap-3">
          <div class="flex-1 min-w-[240px]">
            <h2 class="text-[15px] font-semibold text-ink">{{ t('sreq.p39.set.reasons') }}</h2>
            <p class="mt-1 text-[12.5px] text-ink-muted max-w-3xl">{{ t('sreq.p39.set.reasonsHint') }}</p>
          </div>
          <button class="btn-outline btn-sm" data-add-reason @click="newReason = { name_uz: '', name_ru: '', name_en: '', needs_note: false }"><Plus :size="14" /> {{ t('sreq.p39.set.addReason') }}</button>
        </div>
        <div class="overflow-x-auto">
          <table class="w-full text-[13px]">
            <thead>
              <tr class="text-left text-[11.5px] uppercase tracking-wide text-ink-faint border-b border-line">
                <th class="px-4 py-2 font-medium">UZ</th>
                <th class="px-3 py-2 font-medium">RU</th>
                <th class="px-3 py-2 font-medium">EN</th>
                <th class="px-3 py-2 font-medium">{{ t('sreq.p39.set.needsNote') }}</th>
                <th class="px-3 py-2 font-medium">{{ t('sreq.p39.set.active') }}</th>
                <th class="px-3 py-2 font-medium text-right">{{ t('sreq.p39.set.used') }}</th>
                <th class="px-3 py-2" />
              </tr>
            </thead>
            <tbody class="divide-y divide-line">
              <tr v-for="r in reasons" :key="r.id" :class="!r.is_active && 'opacity-60'" :data-reason="r.id" @input="r.dirty = true" @change="r.dirty = true">
                <td class="px-4 py-1.5"><input v-model="r.name_uz" class="field-input h-8 min-w-[220px]" maxlength="191"></td>
                <td class="px-3 py-1.5"><input v-model="r.name_ru" class="field-input h-8 min-w-[180px]" maxlength="191"></td>
                <td class="px-3 py-1.5"><input v-model="r.name_en" class="field-input h-8 min-w-[160px]" maxlength="191"></td>
                <td class="px-3 py-1.5"><input v-model="r.needs_note" type="checkbox" class="rounded border-line text-assay-500 focus:ring-assay-500"></td>
                <td class="px-3 py-1.5"><input v-model="r.is_active" type="checkbox" class="rounded border-line text-assay-500 focus:ring-assay-500"></td>
                <td class="px-3 py-1.5 text-right tnum text-ink-muted">{{ r.used }}</td>
                <td class="px-3 py-1.5 whitespace-nowrap text-right">
                  <button class="btn-ghost btn-icon h-8 w-8" :title="t('sreq.p39.set.save')" :aria-label="t('sreq.p39.set.save')" :disabled="busy || !r.dirty || !r.name_uz.trim()" @click="saveReason(r)"><Check :size="15" /></button>
                  <button class="btn-ghost btn-icon h-8 w-8 hover:text-crit" :title="t('sreq.p39.set.remove')" :aria-label="t('sreq.p39.set.remove')" @click="removeReason(r)"><Trash2 :size="15" /></button>
                </td>
              </tr>
              <tr v-if="newReason" data-new-reason-row>
                <td class="px-4 py-1.5"><input v-model="newReason.name_uz" class="field-input h-8 min-w-[220px]" maxlength="191" :placeholder="t('sreq.p39.set.reasonPh')" data-new-reason></td>
                <td class="px-3 py-1.5"><input v-model="newReason.name_ru" class="field-input h-8 min-w-[180px]" maxlength="191"></td>
                <td class="px-3 py-1.5"><input v-model="newReason.name_en" class="field-input h-8 min-w-[160px]" maxlength="191"></td>
                <td class="px-3 py-1.5"><input v-model="newReason.needs_note" type="checkbox" class="rounded border-line text-assay-500 focus:ring-assay-500"></td>
                <td colspan="2" />
                <td class="px-3 py-1.5 text-right">
                  <button class="btn-primary btn-sm" :disabled="busy || !newReason.name_uz.trim()" data-save-new-reason @click="addReason"><Check :size="14" /> {{ t('sreq.p39.set.save') }}</button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>
    </template>
  </div>
</template>
