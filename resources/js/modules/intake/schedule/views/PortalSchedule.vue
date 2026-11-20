<script setup>
/**
 * [Paket 36] Buyurtmachi — «Grafigim»: kunning partiyalari, grafik qatorlari, tarix.
 * Grafik faqat o'z bo'linmasiniki; bo'linmada laboratoriya grafik rejimini yoqqan bo'lishi kerak.
 */
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { CalendarClock, ChevronLeft, ChevronRight, Pencil, Plus, RotateCcw, Trash2, Undo2, XCircle } from 'lucide-vue-next'
import api from '@/lib/api'
import PageHeader from '@/components/ui/PageHeader.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import { useUiStore } from '@/stores/ui'
import ReasonDialog from '../../requests/components/ReasonDialog.vue'
import { apiErrorMessage } from '../../requests/shared'
import { RUN_TONE, daysText, isoDate, niceDate, shiftDate } from '../shared'
import '../i18n'
import '../i18nP39' // [P39]

const { t, locale } = useI18n()
const route = useRoute()
const router = useRouter()
const ui = useUiStore()

const tab = ref(['day', 'schedule', 'history'].includes(route.query.tab) ? route.query.tab : 'day')
watch(tab, (v) => router.replace({ query: { ...route.query, tab: v } }))

const meta = ref(null)          // { can_write, enabled_departments, data: schedules }
const loading = ref(true)
const loadError = ref('')
const dept = ref(route.query.department_id ? Number(route.query.department_id) : '')
const departments = computed(() => meta.value?.enabled_departments ?? [])
const canWrite = computed(() => !!meta.value?.can_write)
const rows = computed(() => (meta.value?.data ?? []).filter((s) => !dept.value || s.department_id === Number(dept.value)))
const today = isoDate()

async function loadMeta() {
  try {
    const { data } = await api.get('/portal/schedules')
    meta.value = data
  } catch (e) {
    loadError.value = apiErrorMessage(e, t('sched.loadError'))
  }
}

/* ---------- kun ---------- */
const date = ref(route.query.date || today)
const runs = ref([])
const runsLoading = ref(false)
async function loadRuns() {
  runsLoading.value = true
  try {
    const { data } = await api.get('/portal/schedule-runs', { params: { date: date.value, department_id: dept.value || undefined } })
    runs.value = data.data ?? []
  } catch (e) {
    ui.error(apiErrorMessage(e, t('sched.loadError')))
  } finally {
    runsLoading.value = false
  }
}
watch([date, dept], () => { if (tab.value === 'day') loadRuns() })

const skipping = ref(null)
const busy = ref(false)
async function act(run, action, body = {}) {
  busy.value = true
  try {
    const { data } = await api.post(`/portal/schedule-runs/${run.id}/${action}`, body)
    runs.value = runs.value.map((r) => (r.id === data.id ? data : r))
    skipping.value = null
  } catch (e) {
    ui.error(apiErrorMessage(e, t('sched.loadError')))
  } finally {
    busy.value = false
  }
}
const editable = (r) => canWrite.value && ['planned', 'late'].includes(r.status) && r.date >= today
// [P39] buyurtma yaratilgandan keyin shu kunga o'zgartirib bo'lmaydi — faqat «Bugun yo'q» (buyurtma sabab bilan yopiladi)
const changeable = (r) => editable(r) && !r.request_id
const reasons = computed(() => meta.value?.reasons ?? [])

/* ---------- grafik qatorlari ---------- */
const removing = ref(null)
async function removeRow() {
  busy.value = true
  try {
    await api.delete(`/portal/schedules/${removing.value.id}`)
    meta.value.data = meta.value.data.filter((s) => s.id !== removing.value.id)
    removing.value = null
    ui.success(t('sched.removed'))
  } catch (e) {
    ui.error(apiErrorMessage(e, t('sched.loadError')))
  } finally {
    busy.value = false
  }
}

/* ---------- tarix ---------- */
const histFrom = ref(shiftDate(today, -13))
const histTo = ref(today)
const history = ref([])
const histLoading = ref(false)
async function loadHistory() {
  histLoading.value = true
  try {
    const { data } = await api.get('/portal/schedule-runs/history', { params: { from: histFrom.value, to: histTo.value, department_id: dept.value || undefined } })
    history.value = data.data ?? []
  } catch (e) {
    ui.error(apiErrorMessage(e, t('sched.loadError')))
  } finally {
    histLoading.value = false
  }
}
watch([histFrom, histTo, dept], () => { if (tab.value === 'history') loadHistory() })

watch(tab, (v) => {
  if (v === 'day') loadRuns()
  if (v === 'history') loadHistory()
})

onMounted(async () => {
  await loadMeta()
  loading.value = false
  if (tab.value === 'day') loadRuns()
  if (tab.value === 'history') loadHistory()
})

const openRun = (r) => router.push({ name: 'portal-schedule-run', params: { id: r.id } })
const editDay = (r) => router.push({ name: 'portal-schedule-day', params: { id: r.id } })
const timeOf = (dt) => (dt ? dt.slice(11, 16) : '')
</script>

<template>
  <div>
    <PageHeader :title="t('sched.title')">
      <template #actions>
        <button v-if="canWrite && departments.length && tab === 'schedule'" class="btn-primary" data-new-row
                @click="router.push({ name: 'portal-schedule-new', query: dept ? { department_id: dept } : {} })">
          <Plus :size="16" /> {{ t('sched.newRow') }}
        </button>
      </template>
    </PageHeader>

    <p class="mb-4 max-w-3xl text-[13.5px] leading-relaxed text-ink-muted">{{ t('sched.hint') }}</p>

    <div v-if="loading" class="h-24 rounded-panel bg-surface-sunken animate-pulse" />
    <p v-else-if="loadError" class="panel p-4 text-[14px] text-crit">{{ loadError }}</p>
    <p v-else-if="!departments.length" class="panel p-4 text-[14px] text-warn-dark dark:text-warn-light" data-disabled>{{ t('sched.disabled') }}</p>

    <template v-else>
      <p v-if="!canWrite" class="mb-3 text-[13px] text-aqua-700 dark:text-aqua-200">{{ t('sched.readOnly') }}</p>

      <div class="panel p-3 mb-4 flex flex-wrap items-center gap-2.5">
        <div class="inline-flex rounded-md border border-line p-0.5 bg-surface-sunken/60" role="tablist">
          <button
            v-for="k in ['day', 'schedule', 'history']" :key="k" type="button" :data-tab="k"
            class="px-3 h-8 rounded text-[13px] font-medium transition-colors"
            :class="tab === k ? 'bg-surface text-ink shadow-sm' : 'text-ink-muted hover:text-ink'"
            @click="tab = k"
          >{{ t(`sched.tabs.${k}`) }}</button>
        </div>
        <select v-if="departments.length > 1" v-model="dept" class="field-input w-auto min-w-[200px]">
          <option value="">—</option>
          <option v-for="d in departments" :key="d.id" :value="d.id">{{ d.name }}</option>
        </select>
        <span class="flex-1" />
        <template v-if="tab === 'day'">
          <button class="btn-ghost btn-icon h-8 w-8" :title="t('sched.prev')" :aria-label="t('sched.prev')" @click="date = shiftDate(date, -1)"><ChevronLeft :size="16" /></button>
          <input v-model="date" type="date" class="field-input w-auto">
          <button class="btn-ghost btn-icon h-8 w-8" :title="t('sched.next')" :aria-label="t('sched.next')" @click="date = shiftDate(date, 1)"><ChevronRight :size="16" /></button>
          <button v-if="date !== today" class="btn-ghost btn-sm" @click="date = today">{{ t('sched.today') }}</button>
        </template>
        <template v-else-if="tab === 'history'">
          <span class="text-[13px] text-ink-muted">{{ t('sched.period') }}</span>
          <input v-model="histFrom" type="date" class="field-input w-auto">
          <span class="text-ink-faint">—</span>
          <input v-model="histTo" type="date" class="field-input w-auto">
        </template>
      </div>

      <!-- ===== Kun ===== -->
      <template v-if="tab === 'day'">
        <div v-if="runsLoading" class="h-24 rounded-panel bg-surface-sunken animate-pulse" />
        <div v-else-if="!runs.length" class="panel">
          <EmptyState :icon="CalendarClock" :title="t('sched.noRuns')" :hint="t('sched.noRunsHint')" />
        </div>
        <div v-else class="space-y-2.5">
          <div v-for="r in runs" :key="r.id" class="panel px-4 py-3 flex flex-wrap items-center gap-x-5 gap-y-2" :data-run="r.id">
            <div class="w-16 shrink-0 font-mono text-[20px] font-semibold text-ink tnum">{{ r.time }}</div>
            <div class="min-w-[220px] flex-1">
              <div class="flex flex-wrap items-center gap-2">
                <button type="button" class="text-[14.5px] font-semibold text-ink hover:text-assay-700 dark:hover:text-assay-200" @click="openRun(r)">{{ r.name }}</button>
                <span class="rounded px-2 py-0.5 text-[12px] font-medium" :class="RUN_TONE[r.status]" :data-status="r.status">{{ t(`sched.status.${r.status}`) }}</span>
                <span v-if="r.customized" class="text-[11.5px] text-assay-700 dark:text-assay-200">● {{ t('sched.customized') }}</span>
              </div>
              <p class="mt-0.5 flex flex-wrap gap-x-3 text-[12.5px] text-ink-muted">
                <span>{{ t('sched.samples', { n: r.items_count }) }}</span>
                <span v-if="departments.length > 1 && r.department">{{ r.department.name }}</span>
                <template v-if="r.arrived_at">
                  <span class="text-ok-dark dark:text-ok-light">{{ t('sched.receivedLine', { n: r.received_count, time: timeOf(r.arrived_at) }) }}</span>
                  <span v-if="r.missing_count" class="text-crit">{{ t('sched.missingLine', { n: r.missing_count }) }}</span>
                  <span v-if="r.received_by">{{ t('sched.receivedBy', { name: r.received_by }) }}</span>
                </template>
                <span v-if="r.note" class="italic">{{ r.note }}</span>
                <!-- [P39] buyurtma: yaratilgan bo'lsa — raqami, aks holda — qachon yaratiladi -->
                <router-link v-if="r.request_id" :to="{ name: 'portal-request', params: { id: r.request_id } }" class="font-mono text-assay-700 hover:underline dark:text-assay-200" data-run-request>
                  {{ t('sched.p39.order', { n: r.request_number }) }}
                </router-link>
                <span v-else-if="r.order_at && ['planned', 'late'].includes(r.status)" class="text-ink-faint" data-order-at>{{ t('sched.p39.orderAt', { time: r.order_at.slice(11, 16) }) }}</span>
              </p>
            </div>
            <div class="flex flex-wrap items-center gap-1">
              <button class="btn-outline btn-sm" @click="openRun(r)">{{ t('sched.open') }}</button>
              <template v-if="editable(r)">
                <button v-if="changeable(r)" class="btn-ghost btn-sm" :data-edit-day="r.id" @click="editDay(r)"><Pencil :size="14" /> {{ t('sched.editDay') }}</button>
                <button v-if="r.customized && changeable(r)" class="btn-ghost btn-sm" :disabled="busy" @click="act(r, 'reset')"><RotateCcw :size="14" /> {{ t('sched.resetDay') }}</button>
                <button class="btn-ghost btn-sm hover:text-crit" :data-skip="r.id" @click="skipping = r"><XCircle :size="14" /> {{ t('sched.skip') }}</button>
              </template>
              <button v-if="canWrite && r.status === 'skipped' && r.date >= today && !r.request_id" class="btn-ghost btn-sm" :disabled="busy" :data-unskip="r.id" @click="act(r, 'unskip')">
                <Undo2 :size="14" /> {{ t('sched.unskip') }}
              </button>
            </div>
          </div>
        </div>
      </template>

      <!-- ===== Grafik qatorlari ===== -->
      <template v-else-if="tab === 'schedule'">
        <div v-if="!rows.length" class="panel">
          <EmptyState :icon="CalendarClock" :title="t('sched.rowsEmpty')" :hint="t('sched.rowsEmptyHint')">
            <button v-if="canWrite" class="btn-primary" @click="router.push({ name: 'portal-schedule-new' })"><Plus :size="16" /> {{ t('sched.newRow') }}</button>
          </EmptyState>
        </div>
        <div v-else class="panel overflow-hidden divide-y divide-line">
          <div v-for="s in rows" :key="s.id" class="flex flex-wrap items-center gap-x-5 gap-y-2 px-4 py-3" :class="!s.is_active && 'opacity-60'" :data-row="s.id">
            <div class="w-16 shrink-0 font-mono text-[18px] font-semibold text-ink tnum">{{ s.time }}</div>
            <div class="min-w-[220px] flex-1">
              <p class="text-[14.5px] font-semibold text-ink">{{ s.name }}</p>
              <p class="mt-0.5 flex flex-wrap gap-x-3 text-[12.5px] text-ink-muted">
                <span>{{ daysText(s.weekdays, t) }}</span>
                <span>{{ t('sched.samples', { n: s.items_count }) }}</span>
                <span v-if="!s.is_active" class="text-warn-dark dark:text-warn-light">{{ t('sched.inactive') }}</span>
                <span v-if="departments.length > 1">{{ departments.find((d) => d.id === s.department_id)?.name }}</span>
              </p>
            </div>
            <div v-if="canWrite" class="flex items-center gap-1">
              <button class="btn-ghost btn-icon h-8 w-8" :title="t('sched.edit')" :aria-label="t('sched.edit')" :data-edit-row="s.id"
                      @click="router.push({ name: 'portal-schedule-edit', params: { id: s.id } })"><Pencil :size="15" /></button>
              <button class="btn-ghost btn-icon h-8 w-8 hover:text-crit" :title="t('sched.remove')" :aria-label="t('sched.remove')" :data-remove-row="s.id"
                      @click="removing = s"><Trash2 :size="15" /></button>
            </div>
          </div>
        </div>
      </template>

      <!-- ===== Tarix ===== -->
      <template v-else>
        <div v-if="histLoading" class="h-24 rounded-panel bg-surface-sunken animate-pulse" />
        <p v-else-if="!history.length" class="panel p-4 text-[13.5px] text-ink-faint">{{ t('sched.histEmpty') }}</p>
        <div v-else class="space-y-2.5">
          <div v-for="d in history" :key="d.date" class="panel overflow-hidden" :data-day="d.date">
            <div class="flex flex-wrap items-baseline justify-between gap-2 px-4 py-2 border-b border-line bg-surface-sunken/50">
              <button type="button" class="text-[13.5px] font-semibold text-ink hover:text-assay-700" @click="date = d.date; tab = 'day'">{{ niceDate(d.date, locale) }}</button>
              <span class="text-[12.5px] text-ink-muted">{{ t('sched.histDay', { received: d.received, planned: d.planned, samples: d.samples }) }}</span>
            </div>
            <div class="flex flex-wrap gap-2 px-4 py-2.5">
              <button
                v-for="r in d.runs" :key="r.id" type="button" class="inline-flex items-center gap-1.5 rounded-md border border-line px-2.5 py-1 text-[12.5px] hover:border-assay-400"
                @click="openRun(r)"
              >
                <span class="font-mono tnum">{{ r.time }}</span>
                <span class="text-ink">{{ r.name }}</span>
                <span class="rounded px-1.5 text-[11.5px]" :class="RUN_TONE[r.status]">{{ t(`sched.status.${r.status}`) }}</span>
                <span v-if="r.received_count" class="text-ink-muted">{{ r.received_count }}/{{ r.items_count }}</span>
              </button>
            </div>
          </div>
        </div>
      </template>
    </template>

    <ReasonDialog
      :model-value="!!skipping" :title="t('sched.skipTitle')" :body="skipping ? t('sched.skipBody', { name: skipping.name, time: skipping.time }) : ''"
      :confirm-label="t('sched.skip')" :placeholder="t('sched.skipNote')" :loading="busy"
      :reasons="reasons" :reason-required="reasons.length > 0"
      @update:model-value="!$event && (skipping = null)" @confirm="(note, reasonId) => act(skipping, 'skip', { note: note || null, reason_id: reasonId })"
    />
    <ReasonDialog
      :model-value="!!removing" :title="t('sched.removeTitle')" :body="removing ? t('sched.removeBody', { name: removing.name, time: removing.time }) : ''"
      :with-reason="false" :confirm-label="t('sched.remove')" :loading="busy"
      @update:model-value="!$event && (removing = null)" @confirm="removeRow"
    />
  </div>
</template>
