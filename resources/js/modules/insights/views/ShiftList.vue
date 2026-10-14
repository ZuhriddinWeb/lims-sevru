<script setup>
/**
 * Administratsiya → Smenalar: laboratoriya ish smenalarini sozlash.
 * Sutka chizig'ida qaysi soatlar qaysi smenaga tegishli va smenasiz vaqt ko'rinadi.
 */
import { computed, onMounted, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { Clock, Moon, Pencil, Plus, Radio, Trash2, X } from 'lucide-vue-next'
import api from '@/lib/api'
import PageHeader from '@/components/ui/PageHeader.vue'
import ConfirmDialog from '@/components/ui/ConfirmDialog.vue'
import { useUiStore } from '@/stores/ui'
import { apiErrorMessage, hours } from '../helpers'
import '../i18n'

const { t, locale } = useI18n()
const ui = useUiStore()

const items = ref([])
const current = ref(null)
const loading = ref(true)
const saving = ref(false)
const form = reactive({ open: false, id: null, name: '', start_time: '08:00', end_time: '20:00', is_active: true, sort_order: '' })
const errors = ref({})
const target = ref(null)

async function load() {
  loading.value = true
  try {
    const { data } = await api.get('/lab-shifts')
    items.value = data.data ?? []
    current.value = data.current ?? null
  } catch (e) {
    ui.error(apiErrorMessage(e, t('insights.loadError')))
  } finally {
    loading.value = false
  }
}
onMounted(load)

function add() {
  Object.assign(form, { open: true, id: null, name: '', start_time: '08:00', end_time: '20:00', is_active: true, sort_order: '' })
  errors.value = {}
}
function edit(s) {
  Object.assign(form, { open: true, id: s.id, name: s.name, start_time: s.start_time, end_time: s.end_time, is_active: s.is_active, sort_order: s.sort_order })
  errors.value = {}
}

async function save() {
  saving.value = true
  errors.value = {}
  const body = {
    name: form.name, start_time: form.start_time, end_time: form.end_time, is_active: form.is_active,
    sort_order: form.sort_order === '' || form.sort_order === null ? null : Number(form.sort_order),
  }
  try {
    if (form.id) await api.put(`/lab-shifts/${form.id}`, body)
    else await api.post('/lab-shifts', body)
    form.open = false
    ui.success(t('insights.shiftAdmin.saved'))
    await load()
  } catch (e) {
    errors.value = e?.response?.data?.errors ?? {}
    ui.error(apiErrorMessage(e, t('insights.loadError')))
  } finally {
    saving.value = false
  }
}

async function destroy() {
  const s = target.value
  if (!s) return
  try {
    await api.delete(`/lab-shifts/${s.id}`)
    ui.success(t('insights.shiftAdmin.deleted'))
    target.value = null
    await load()
  } catch (e) {
    ui.error(apiErrorMessage(e, t('insights.loadError')))
  }
}

async function toggle(s) {
  try {
    await api.put(`/lab-shifts/${s.id}`, { name: s.name, start_time: s.start_time, end_time: s.end_time, sort_order: s.sort_order, is_active: !s.is_active })
    await load()
  } catch (e) {
    ui.error(apiErrorMessage(e, t('insights.loadError')))
  }
}

/* ---- tayyor namunalar ---- */
const TEMPLATES = {
  two: [['day', '08:00', '20:00'], ['night', '20:00', '08:00']],
  three: [['first', '07:00', '15:00'], ['second', '15:00', '23:00'], ['third', '23:00', '07:00']],
}
const hasActive = computed(() => items.value.some((s) => s.is_active))
async function applyTemplate(key) {
  if (hasActive.value) { ui.error(t('insights.shiftAdmin.templateBusy')); return }
  saving.value = true
  try {
    let order = 1
    for (const [name, a, b] of TEMPLATES[key]) {
      await api.post('/lab-shifts', { name: t(`insights.shiftAdmin.${name}`), start_time: a, end_time: b, is_active: true, sort_order: order++ })
    }
    ui.success(t('insights.shiftAdmin.saved'))
  } catch (e) {
    ui.error(apiErrorMessage(e, t('insights.loadError')))
  } finally {
    saving.value = false
    await load()
  }
}

/* ---- sutka chizig'i ---- */
const toMin = (v) => { const [h, m] = String(v).split(':').map(Number); return h * 60 + m }
const segments = computed(() => {
  const out = []
  items.value.filter((s) => s.is_active).forEach((s, i) => {
    const a = toMin(s.start_time)
    const b = toMin(s.end_time)
    const parts = b > a ? [[a, b]] : [[a, 1440], [0, b]].filter(([x, y]) => y > x)
    for (const [x, y] of parts) out.push({ id: s.id, name: s.name, left: (x / 1440) * 100, width: ((y - x) / 1440) * 100, alt: i % 2 === 1 })
  })
  return out
})
const gaps = computed(() => {
  const covered = new Array(1440).fill(false)
  for (const s of items.value.filter((x) => x.is_active)) {
    const a = toMin(s.start_time)
    const b = toMin(s.end_time)
    const len = b > a ? b - a : b - a + 1440
    for (let k = 0; k < len; k++) covered[(a + k) % 1440] = true
  }
  const out = []
  let start = null
  for (let m = 0; m <= 1440; m++) {
    const free = m < 1440 && !covered[m]
    if (free && start === null) start = m
    if (!free && start !== null) { out.push([start, m]); start = null }
  }
  const fmt = (m) => `${String(Math.floor(m / 60) % 24).padStart(2, '0')}:${String(m % 60).padStart(2, '0')}`
  return out.map(([a, b]) => ({ left: (a / 1440) * 100, width: ((b - a) / 1440) * 100, label: `${fmt(a)}–${fmt(b)}` }))
})
const TICKS = [0, 3, 6, 9, 12, 15, 18, 21, 24]
</script>

<template>
  <div>
    <PageHeader :title="t('insights.shiftAdmin.title')" :subtitle="t('insights.shiftAdmin.subtitle')">
      <template #actions>
        <button type="button" class="btn-primary" data-add @click="add"><Plus :size="16" /> {{ t('insights.shiftAdmin.add') }}</button>
      </template>
    </PageHeader>

    <!-- Forma -->
    <section v-if="form.open" class="panel p-4 mb-4" data-form>
      <header class="flex items-center justify-between gap-2 mb-3">
        <h2 class="text-[15px] font-semibold text-ink">{{ form.id ? t('insights.shiftAdmin.edit') : t('insights.shiftAdmin.add') }}</h2>
        <button type="button" class="btn-ghost btn-icon" @click="form.open = false"><X :size="16" /></button>
      </header>
      <div class="grid gap-3 sm:grid-cols-[minmax(0,1.6fr)_minmax(0,1fr)_minmax(0,1fr)_minmax(0,0.7fr)] items-start">
        <div>
          <label class="field-label">{{ t('insights.shiftAdmin.name') }}</label>
          <input v-model="form.name" class="field-input" :class="errors.name ? 'field-input-error' : ''" :placeholder="t('insights.shiftAdmin.namePh')" maxlength="64" data-f="name">
          <p v-if="errors.name" class="mt-1 text-[12px] text-crit">{{ errors.name[0] }}</p>
        </div>
        <div>
          <label class="field-label">{{ t('insights.shiftAdmin.start') }}</label>
          <input v-model="form.start_time" type="time" class="field-input" :class="errors.start_time ? 'field-input-error' : ''" data-f="start">
          <p v-if="errors.start_time" class="mt-1 text-[12px] text-crit" data-err="start">{{ errors.start_time[0] }}</p>
        </div>
        <div>
          <label class="field-label">{{ t('insights.shiftAdmin.end') }}</label>
          <input v-model="form.end_time" type="time" class="field-input" :class="errors.end_time ? 'field-input-error' : ''" data-f="end">
          <p v-if="errors.end_time" class="mt-1 text-[12px] text-crit">{{ errors.end_time[0] }}</p>
        </div>
        <div>
          <label class="field-label">{{ t('insights.shiftAdmin.order') }}</label>
          <input v-model="form.sort_order" type="number" min="0" max="999" class="field-input" data-f="order">
        </div>
      </div>
      <p class="mt-2 text-[12.5px] text-ink-muted">{{ t('insights.shiftAdmin.hint') }}</p>
      <div class="mt-3 flex flex-wrap items-center justify-between gap-2">
        <label class="inline-flex items-center gap-2 text-[13.5px] text-ink cursor-pointer">
          <input v-model="form.is_active" type="checkbox" class="rounded border-line" data-f="active"> {{ t('insights.shiftAdmin.active') }}
        </label>
        <div class="flex gap-2">
          <button type="button" class="btn-ghost" @click="form.open = false">{{ t('common.cancel') }}</button>
          <button type="button" class="btn-primary" :disabled="saving || !form.name.trim()" data-save @click="save">{{ t('common.save') }}</button>
        </div>
      </div>
    </section>

    <!-- Sutka chizig'i -->
    <section class="panel p-4 mb-4" data-coverage>
      <div class="flex flex-wrap items-center justify-between gap-2 mb-3">
        <h2 class="text-[14px] font-semibold text-ink">{{ t('insights.shiftAdmin.coverage') }}</h2>
        <span class="inline-flex items-center gap-1.5 text-[12.5px]" :class="current ? 'text-assay-700 dark:text-assay-300' : 'text-ink-muted'" data-current>
          <Radio :size="14" /> {{ current ? t('insights.shiftAdmin.now', { name: current.name }) : t('insights.shiftAdmin.none') }}
        </span>
      </div>
      <div class="relative h-9 rounded-md bg-surface-sunken overflow-hidden">
        <div
          v-for="(g, i) in gaps" :key="`g${i}`"
          class="absolute inset-y-0 border-x border-dashed border-line"
          :style="{ left: `${g.left}%`, width: `${g.width}%` }" data-gap
        />
        <div
          v-for="(s, i) in segments" :key="`${s.id}-${i}`"
          class="absolute inset-y-1 rounded grid place-items-center text-[12px] font-medium truncate px-1"
          :class="s.alt ? 'bg-assay-300 text-assay-900 dark:bg-assay-700 dark:text-white' : 'bg-assay-100 text-assay-800 dark:bg-assay-900 dark:text-assay-100'"
          :style="{ left: `calc(${s.left}% + 1px)`, width: `calc(${s.width}% - 2px)` }"
          :title="s.name" data-segment
        >{{ s.width > 8 ? s.name : '' }}</div>
      </div>
      <div class="relative h-4 mt-1 text-[10.5px] text-ink-faint tnum">
        <span v-for="h in TICKS" :key="h" class="absolute -translate-x-1/2" :style="{ left: `${(h / 24) * 100}%` }">{{ String(h).padStart(2, '0') }}</span>
      </div>
      <p class="mt-2 text-[12.5px]" :class="gaps.length && items.length ? 'text-warn-dark dark:text-warn-light' : 'text-ink-muted'" data-gaps>
        <template v-if="!items.length">{{ t('insights.shiftAdmin.emptyHint') }}</template>
        <template v-else-if="gaps.length">{{ t('insights.shiftAdmin.gap', { list: gaps.map((g) => g.label).join(', ') }) }}</template>
        <template v-else>{{ t('insights.shiftAdmin.full') }}</template>
      </p>
    </section>

    <!-- Ro'yxat -->
    <section class="panel overflow-hidden">
      <div v-if="loading" class="p-4 space-y-2"><div v-for="i in 2" :key="i" class="h-11 rounded bg-surface-sunken animate-pulse" /></div>

      <div v-else-if="!items.length" class="p-6 text-center" data-empty>
        <Clock :size="28" class="mx-auto text-ink-faint mb-2" />
        <p class="text-[14px] font-medium text-ink">{{ t('insights.shiftAdmin.empty') }}</p>
        <p class="text-[12.5px] text-ink-muted mt-1">{{ t('insights.shiftAdmin.emptyHint') }}</p>
        <div class="mt-4 flex flex-wrap justify-center gap-2">
          <button type="button" class="btn-outline btn-sm" :disabled="saving" data-template="two" @click="applyTemplate('two')">{{ t('insights.shiftAdmin.two') }}</button>
          <button type="button" class="btn-outline btn-sm" :disabled="saving" data-template="three" @click="applyTemplate('three')">{{ t('insights.shiftAdmin.three') }}</button>
        </div>
      </div>

      <table v-else class="w-full text-[13.5px]">
        <thead>
          <tr class="border-b border-line bg-surface-sunken/60 text-left text-[12px] uppercase tracking-wide text-ink-muted">
            <th class="px-4 py-2.5 font-medium">{{ t('insights.shiftAdmin.name') }}</th>
            <th class="px-4 py-2.5 font-medium">{{ t('insights.shiftAdmin.start') }} — {{ t('insights.shiftAdmin.end') }}</th>
            <th class="px-4 py-2.5 font-medium text-right">{{ t('insights.shiftAdmin.order') }}</th>
            <th class="px-4 py-2.5 font-medium">{{ t('insights.shiftAdmin.active') }}</th>
            <th class="px-4 py-2.5" />
          </tr>
        </thead>
        <tbody class="divide-y divide-line">
          <tr v-for="s in items" :key="s.id" :class="s.is_active ? '' : 'text-ink-faint'" :data-shift="s.id">
            <td class="px-4 py-2.5">
              <span class="font-medium" :class="s.is_active ? 'text-ink' : ''">{{ s.name }}</span>
              <span v-if="current && current.id === s.id" class="ml-2 rounded-full bg-assay-50 dark:bg-assay-900/40 px-2 py-0.5 text-[11px] text-assay-700 dark:text-assay-200">{{ t('insights.shifts.live') }}</span>
            </td>
            <td class="px-4 py-2.5 tnum">
              {{ s.start_time }} — {{ s.end_time }}
              <span class="text-ink-faint"> · {{ t('insights.shiftAdmin.duration', { h: hours(s.minutes / 60, locale) }) }}</span>
              <span v-if="s.crosses_midnight" class="ml-1 inline-flex items-center gap-1 text-[11.5px] text-ink-muted" data-crosses><Moon :size="12" />{{ t('insights.shiftAdmin.crosses') }}</span>
            </td>
            <td class="px-4 py-2.5 text-right tnum">{{ s.sort_order }}</td>
            <td class="px-4 py-2.5">
              <button type="button" class="rounded-full px-2.5 py-0.5 text-[12px] font-medium"
                      :class="s.is_active ? 'bg-ok-light text-ok-dark dark:bg-ok-dark/25 dark:text-ok-light' : 'bg-surface-sunken text-ink-muted'"
                      :data-toggle="s.id" @click="toggle(s)">
                {{ s.is_active ? t('insights.shiftAdmin.active') : t('insights.shiftAdmin.inactive') }}
              </button>
            </td>
            <td class="px-4 py-2.5 text-right whitespace-nowrap">
              <button type="button" class="btn-ghost btn-icon h-8 w-8" :title="t('insights.shiftAdmin.edit')" :data-edit="s.id" @click="edit(s)"><Pencil :size="15" /></button>
              <button type="button" class="btn-ghost btn-icon h-8 w-8 hover:text-crit" :title="t('insights.shiftAdmin.deleteTitle')" :data-delete="s.id" @click="target = s"><Trash2 :size="15" /></button>
            </td>
          </tr>
        </tbody>
      </table>
    </section>

    <ConfirmDialog
      :model-value="!!target" :title="t('insights.shiftAdmin.deleteTitle')" :body="t('insights.shiftAdmin.deleteText', { name: target?.name ?? '' })"
      :confirm-label="t('insights.shiftAdmin.deleteTitle')" :cancel-label="t('common.cancel')"
      @update:model-value="!$event && (target = null)" @confirm="destroy"
    />
  </div>
</template>
