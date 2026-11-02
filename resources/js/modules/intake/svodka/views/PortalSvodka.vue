<script setup>
/**
 * Buyurtmachi svodkasi — FAQAT buyurtmachi bo'limiga. [Paket 29]
 *   «Svodka» — kunlik jadval (laboratoriya boshlig'i tasdiqlagan natijalar avtomatik tushadi), Excel;
 *   «Nuqtalar» — o'z spravochnigi (bloklar va namuna olish nuqtalari).
 * /portal/svodka/:id — xabarnomadan: chiqarish (release) id → o'sha kun ochiladi.
 */
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import {
  CalendarDays, CheckCircle2, ChevronLeft, ChevronRight, Eye, FileSpreadsheet, Hourglass, Info, Link2, ListOrdered, Loader2, Table2,
} from 'lucide-vue-next'
import api from '@/lib/api'
import PageHeader from '@/components/ui/PageHeader.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import { useUiStore } from '@/stores/ui'
import { formatDateTime } from '@/lib/format'
import SvodkaSectionTable from '../components/SvodkaSectionTable.vue'
import SpravochnikPanel from '../components/SpravochnikPanel.vue'
import AssignDialog from '../components/AssignDialog.vue'
import {
  STATE_TONE, addDays, apiError, dayLabel, dayPeriods, downloadSvodka, periodState, shiftText, todayIso, weekday,
} from '../shared'
import '../i18n'

const route = useRoute()
const router = useRouter()
const ui = useUiStore()
const { t } = useI18n()

const ctx = ref(null)
const ctxError = ref('')
const deptId = ref(null)
const tab = ref(route.query.tab === 'points' ? 'points' : 'day')
const date = ref(typeof route.query.date === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(route.query.date) ? route.query.date : '')
const day = ref(null)
const dayLoading = ref(false)
const dayError = ref('')
const sprav = ref(null)

const dept = computed(() => ctx.value?.departments?.find((d) => d.id === deptId.value) ?? null)
const enabled = computed(() => !!dept.value?.enabled)
const canWrite = computed(() => !!ctx.value?.can_write)

async function loadContext() {
  try {
    const { data } = await api.get('/portal/svodka/context')
    ctx.value = data
    // xabarnomadan kelgan bo'lsa — qaysi bo'lim va kun
    if (route.params.id) {
      try {
        const { data: rel } = await api.get(`/portal/svodka/release/${route.params.id}`)
        deptId.value = rel.department_id
        date.value = rel.date
        tab.value = 'day'
      } catch { /* topilmadi — oddiy ochiladi */ }
    }
    const want = Number(route.query.department_id) || deptId.value
    const list = data.departments ?? []
    deptId.value = (list.find((d) => d.id === want) ?? list.find((d) => d.enabled) ?? list[0])?.id ?? null
  } catch (e) {
    ctxError.value = e?.response?.status === 403 ? t('svodka.onlyCustomer') : apiError(e, t('svodka.loadError'))
  }
}

async function loadSprav() {
  if (!deptId.value) return
  try {
    const { data } = await api.get('/portal/svodka/spravochnik', { params: { department_id: deptId.value } })
    sprav.value = data
  } catch (e) {
    ui.error(apiError(e, t('svodka.loadError')))
  }
}

async function loadDay() {
  if (!deptId.value || !enabled.value || !date.value) return
  dayLoading.value = true
  dayError.value = ''
  try {
    const { data } = await api.get('/portal/svodka/day', { params: { department_id: deptId.value, date: date.value } })
    day.value = data
  } catch (e) {
    day.value = null
    dayError.value = apiError(e, t('svodka.loadError'))
  } finally {
    dayLoading.value = false
  }
}

function syncQuery() {
  const q = { ...route.query, tab: tab.value === 'points' ? 'points' : undefined, date: tab.value === 'day' ? date.value || undefined : undefined }
  if ((ctx.value?.departments?.length ?? 0) > 1) q.department_id = deptId.value
  router.replace({ name: 'portal-svodka', params: {}, query: Object.fromEntries(Object.entries(q).filter(([, v]) => v !== undefined && v !== '')) })
}

onMounted(async () => {
  await loadContext()
  if (!ctx.value || !deptId.value) return
  if (!enabled.value) tab.value = 'points'
  if (!date.value) date.value = dept.value?.current?.date || todayIso()
  await Promise.all([loadSprav(), loadDay()])
  syncQuery()
})

watch(deptId, async (v, old) => {
  if (!old || !v) return
  if (!enabled.value) tab.value = 'points'
  date.value = dept.value?.current?.date || todayIso()
  day.value = null
  await Promise.all([loadSprav(), loadDay()])
  syncQuery()
})
watch(date, (v, old) => {
  if (old && v && v !== old) {
    loadDay()
    syncQuery()
  }
})
watch(tab, () => syncQuery())

function shiftDay(n) {
  date.value = addDays(date.value, n)
}
function goToday() {
  date.value = dept.value?.current?.date || todayIso()
}

/* ---- davr holati (navbatchilik / sutka) ---- */
const periods = computed(() => dayPeriods(day.value))
function periodTitle(p) {
  return p.shift ? shiftText(day.value.settings, p.shift) : t('svodka.day.wholeDay')
}
function periodText(p) {
  const st = periodState(p)
  if (st === 'released') {
    return t(p.release.revision > 1 ? 'svodka.state.releasedRev' : 'svodka.state.released', { rev: p.release.revision, at: formatDateTime(p.release.released_at) })
  }
  if (st === 'waiting') return t('svodka.state.waiting', { n: p.waiting })
  if (st === 'unreleased') return t('svodka.state.unreleased', { n: p.samples })
  return t('svodka.state.empty')
}
const anyReleased = computed(() => periods.value.some((p) => p.release))

/* ---- bog'lash ---- */
const assign = reactive({ open: false, entry: null })
function openAssign(entry) {
  assign.entry = entry
  assign.open = true
}
function onAssigned(data) {
  if (data.date !== date.value) {
    date.value = data.date      // kuzatuvchi qayta yuklaydi
  } else {
    day.value = data
  }
  ui.success(t('svodka.assign.done'))
  loadSprav()
}

/* ---- Excel ---- */
const exp = reactive({ open: false, from: '', to: '', busy: false })
function openExport() {
  exp.from = date.value
  exp.to = date.value
  exp.open = true
}
async function doExport() {
  if (exp.busy) return
  exp.busy = true
  try {
    const name = await downloadSvodka({ department_id: deptId.value, from: exp.from, to: exp.to || exp.from })
    ui.success(t('svodka.export.done', { name }))
    exp.open = false
  } catch (e) {
    ui.error(e.serverMessage || t('svodka.export.error'))
  } finally {
    exp.busy = false
  }
}
const exportDays = computed(() => {
  if (!exp.from || !exp.to) return 0
  return Math.round((new Date(`${exp.to}T00:00:00`) - new Date(`${exp.from}T00:00:00`)) / 86400000) + 1
})
</script>

<template>
  <div>
    <PageHeader :title="t('svodka.title')" :subtitle="t('svodka.subtitle')">
      <template #actions>
        <!-- [P31] xodim svodka.view bilan — faqat ko'rish -->
        <span v-if="ctx?.viewer" class="inline-flex items-center gap-1.5 rounded-full bg-surface-sunken px-2.5 py-1 text-[12px] text-ink-muted" :title="t('svodka.viewOnlyHint')" data-view-only>
          <Eye :size="13" /> {{ t('svodka.viewOnly') }}
        </span>
        <select
          v-if="(ctx?.departments?.length ?? 0) > 1" v-model="deptId"
          class="field-input h-9 w-auto min-w-[200px]" data-dept
        >
          <option v-for="d in ctx.departments" :key="d.id" :value="d.id">{{ d.name }}<template v-if="d.code"> ({{ d.code }})</template></option>
        </select>
      </template>
    </PageHeader>

    <p v-if="ctxError" class="panel p-4 text-[14px] text-warn-dark dark:text-warn-light" data-ctx-error>{{ ctxError }}</p>
    <p v-else-if="ctx && !deptId" class="panel p-4 text-[14px] text-warn-dark dark:text-warn-light" data-not-linked>{{ t('svodka.notLinked') }}</p>

    <template v-else-if="ctx">
      <!-- Bo'limlar orasida: svodka / spravochnik -->
      <div class="mb-4 flex flex-wrap items-center gap-3">
        <div class="inline-flex rounded-md border border-line p-0.5 bg-surface-sunken/60" role="tablist">
          <button
            type="button" role="tab" :aria-selected="tab === 'day'" :disabled="!enabled"
            class="inline-flex items-center gap-1.5 px-3 h-8 rounded text-[13px] font-medium transition-colors disabled:opacity-50"
            :class="tab === 'day' ? 'bg-surface text-ink shadow-sm' : 'text-ink-muted hover:text-ink'"
            data-tab="day" @click="tab = 'day'"
          ><Table2 :size="15" /> {{ t('svodka.tabs.day') }}</button>
          <button
            type="button" role="tab" :aria-selected="tab === 'points'"
            class="inline-flex items-center gap-1.5 px-3 h-8 rounded text-[13px] font-medium transition-colors"
            :class="tab === 'points' ? 'bg-surface text-ink shadow-sm' : 'text-ink-muted hover:text-ink'"
            data-tab="points" @click="tab = 'points'"
          ><ListOrdered :size="15" /> {{ t('svodka.tabs.points') }}</button>
        </div>
        <p v-if="!enabled" class="flex items-center gap-1.5 text-[13px] text-ink-muted" data-disabled-note>
          <Info :size="15" class="text-aqua-500" /> {{ t('svodka.disabledNote') }}
        </p>
      </div>

      <!-- ===== Svodka (kun) ===== -->
      <template v-if="tab === 'day' && enabled">
        <div class="panel p-3 mb-4 flex flex-wrap items-center gap-2.5">
          <div class="flex items-center gap-1">
            <button type="button" class="btn-ghost btn-icon h-9 w-9" :aria-label="t('svodka.day.prev')" data-prev @click="shiftDay(-1)"><ChevronLeft :size="18" /></button>
            <input v-model="date" type="date" class="field-input h-9 w-[160px]" data-date>
            <button type="button" class="btn-ghost btn-icon h-9 w-9" :aria-label="t('svodka.day.next')" data-next @click="shiftDay(1)"><ChevronRight :size="18" /></button>
          </div>
          <button type="button" class="btn-ghost btn-sm" data-today @click="goToday"><CalendarDays :size="15" /> {{ t('svodka.day.today') }}</button>
          <span class="text-[13px] text-ink-muted capitalize">{{ weekday(date, t) }}</span>
          <span class="flex-1" />
          <button type="button" class="btn-outline btn-sm" :title="t('svodka.export.hint')" data-export @click="openExport">
            <FileSpreadsheet :size="15" /> {{ t('svodka.export.button') }}
          </button>
        </div>

        <!-- Davrlar holati -->
        <div v-if="day" class="mb-4 grid gap-2" :class="periods.length > 1 ? 'sm:grid-cols-2' : ''" data-periods>
          <div
            v-for="p in periods" :key="p.key"
            class="flex items-start gap-2.5 rounded-md px-3 py-2.5 text-[13px]" :class="STATE_TONE[periodState(p)]"
            :data-period="p.key" :data-state="periodState(p)"
          >
            <CheckCircle2 v-if="periodState(p) === 'released'" :size="16" class="mt-0.5 shrink-0" />
            <Hourglass v-else :size="16" class="mt-0.5 shrink-0" />
            <div class="min-w-0">
              <p class="font-semibold">{{ periodTitle(p) }}</p>
              <p>{{ periodText(p) }}</p>
            </div>
          </div>
        </div>

        <p v-if="dayError" class="panel p-4 text-[14px] text-crit" data-day-error>{{ dayError }}</p>
        <div v-else-if="dayLoading && !day" class="space-y-3">
          <div class="h-64 rounded-panel bg-surface-sunken animate-pulse" />
        </div>

        <template v-else-if="day">
          <div v-if="!day.sections.length" class="panel px-4 py-10 text-center" data-no-sections>
            <p class="text-[14px] font-medium text-ink">{{ t('svodka.points.emptyTitle') }}</p>
            <p class="mt-1 text-[13px] text-ink-muted">{{ t('svodka.day.setupHint') }}</p>
            <button type="button" class="btn-outline btn-sm mt-4" @click="tab = 'points'"><ListOrdered :size="15" /> {{ t('svodka.tabs.points') }}</button>
          </div>

          <section
            v-for="s in day.sections" :key="s.id" class="panel mb-4 overflow-hidden"
            :class="dayLoading && 'opacity-60 transition-opacity'" :data-day-section="s.id"
          >
            <header class="flex flex-wrap items-baseline justify-between gap-2 px-4 py-3 border-b border-line">
              <h2 class="text-[14.5px] font-semibold text-ink">
                {{ s.title }}
                <span v-if="!s.active" class="ml-1 text-[12px] font-normal text-ink-faint">({{ t('svodka.points.inactive') }})</span>
              </h2>
              <span v-if="s === day.sections[0]" class="text-[12.5px] text-ink-muted tnum">{{ dayLabel(day.date) }}<template v-if="day.settings.title"> · {{ day.settings.title }}</template></span>
            </header>
            <SvodkaSectionTable :section="s" :settings="day.settings" :can-write="canWrite" @assign="openAssign" />
          </section>

          <p v-if="day.sections.length" class="mb-5 text-[12px] text-ink-faint">
            {{ t('svodka.day.legendPending') }}<template v-if="canWrite"> · {{ t('svodka.day.legendClick') }}</template>
            <template v-if="!anyReleased"> · {{ t('svodka.day.legendEmpty') }}</template>
          </p>

          <!-- Bog'lanmagan namunalar -->
          <section v-if="day.unassigned.length" class="panel overflow-hidden" data-unassigned>
            <header class="px-4 py-3 border-b border-line">
              <h2 class="text-[14px] font-semibold text-ink">{{ t('svodka.unassigned.title', { n: day.unassigned.length }) }}</h2>
              <p class="mt-0.5 text-[12.5px] text-ink-muted">{{ t('svodka.unassigned.hint') }}</p>
            </header>
            <ul class="divide-y divide-line">
              <li v-for="u in day.unassigned" :key="u.sample_id" class="flex flex-wrap items-center gap-x-4 gap-y-1.5 px-4 py-2.5" :data-unassigned-sample="u.sample_id">
                <span class="min-w-[90px]">
                  <span class="block font-mono font-semibold text-ink">{{ u.code }}</span>
                  <span v-if="u.lab_code && u.lab_code !== u.code" class="block font-mono text-[11.5px] text-ink-faint">{{ u.lab_code }}</span>
                </span>
                <span class="text-[12.5px] text-ink-muted tnum">{{ formatDateTime(u.registered_at) }} · {{ shiftText(day.settings, u.shift, false) }}</span>
                <span class="flex flex-wrap gap-1">
                  <span v-for="e in u.elements" :key="e" class="rounded bg-surface-sunken px-1.5 py-0.5 font-mono text-[11.5px] text-ink-muted">{{ e }}</span>
                </span>
                <span class="flex-1" />
                <span v-if="u.released" class="text-[12px] text-ink-faint">{{ t('svodka.day.releasedHint') }}</span>
                <button v-else-if="canWrite" type="button" class="btn-outline btn-sm" data-assign @click="openAssign({ sample_id: u.sample_id, code: u.code, shift: u.shift, point_id: null })">
                  <Link2 :size="14" /> {{ t('svodka.unassigned.assign') }}
                </button>
              </li>
            </ul>
          </section>
        </template>
      </template>

      <!-- ===== Spravochnik ===== -->
      <template v-else-if="tab === 'points'">
        <SpravochnikPanel v-if="sprav" :data="sprav" @changed="(d) => { sprav = d; loadDay() }" />
        <div v-else class="h-48 rounded-panel bg-surface-sunken animate-pulse" />
      </template>
    </template>

    <AssignDialog
      v-model="assign.open" :entry="assign.entry" :sections="sprav?.sections ?? []"
      :settings="day?.settings ?? {}" :date="date" @saved="onAssigned"
    />

    <BaseModal v-model="exp.open" size="sm" :title="t('svodka.export.title')" :subtitle="t('svodka.export.subtitle')">
      <div class="grid grid-cols-2 gap-3" data-export-dialog>
        <div>
          <label class="field-label">{{ t('svodka.export.from') }}</label>
          <input v-model="exp.from" type="date" class="field-input" data-f="from">
        </div>
        <div>
          <label class="field-label">{{ t('svodka.export.to') }}</label>
          <input v-model="exp.to" type="date" class="field-input" :min="exp.from" data-f="to">
        </div>
      </div>
      <p class="mt-3 text-[12.5px]" :class="exportDays > 63 || exportDays < 1 ? 'text-crit' : 'text-ink-muted'">
        {{ exportDays > 63 ? t('svodka.export.tooLong') : t('svodka.export.sheets', { n: Math.max(exportDays, 0) }) }}
      </p>
      <template #footer>
        <button class="btn-ghost" @click="exp.open = false">{{ t('svodka.cancel') }}</button>
        <button class="btn-primary" :disabled="exp.busy || exportDays < 1 || exportDays > 63" data-export-go @click="doExport">
          <Loader2 v-if="exp.busy" :size="15" class="animate-spin" />
          <FileSpreadsheet v-else :size="15" />
          {{ exp.busy ? t('svodka.export.busy') : t('svodka.export.download') }}
        </button>
      </template>
    </BaseModal>
  </div>
</template>
