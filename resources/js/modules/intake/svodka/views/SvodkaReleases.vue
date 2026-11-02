<script setup>
/**
 * Laboratoriya: buyurtmachi svodkalarini chiqarish (laboratoriya boshlig'i). [Paket 29]
 * Hech narsa o'zgartirilmaydi — «Chiqarish» o'sha paytdagi tasdiqlangan natijalar nusxasini buyurtmachiga ochadi.
 * Nuqta (namuna) nomlari bu yerda yo'q — faqat shifrlar va natijalar.
 * Sozlama (svodka rejimi, navbatchilik soatlari) — customer_departments.manage.
 * /svodka-releases/:id — xabarnomadan: bo'lim id.
 */
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { ArrowRight, ChevronLeft, ChevronRight, ClipboardCheck, EyeOff, Save, Send, Settings2, Users } from 'lucide-vue-next'
import api from '@/lib/api'
import PageHeader from '@/components/ui/PageHeader.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import ViewersDialog from '../components/ViewersDialog.vue'
import { useUiStore } from '@/stores/ui'
import { formatDateTime } from '@/lib/format'
import { formatValue } from '../../helpers'
import { RELEASE_TONE, addDays, apiError, dayLabel, releaseState, todayIso, weekday } from '../shared'
import '../i18n'

const route = useRoute()
const router = useRouter()
const ui = useUiStore()
const { t, locale } = useI18n()

const info = ref(null)               // { can_manage, can_release, departments }
const infoError = ref('')
const deptId = ref(null)
const to = ref(todayIso())
const DAYS = 7
const list = ref(null)
const listLoading = ref(false)
const listError = ref('')
const selected = ref(null)           // { date, shift }
const detail = ref(null)
const detailLoading = ref(false)

const dept = computed(() => info.value?.departments?.find((d) => d.id === deptId.value) ?? null)

async function loadInfo(keep = true) {
  try {
    const { data } = await api.get('/svodka-releases/departments')
    info.value = data
    const ds = data.departments ?? []
    const want = keep && deptId.value ? deptId.value : Number(route.params.id) || Number(route.query.department_id)
    deptId.value = (ds.find((d) => d.id === want) ?? ds.find((d) => d.enabled) ?? ds[0])?.id ?? null
  } catch (e) {
    infoError.value = apiError(e, t('svodka.loadError'))
  }
}

async function loadList() {
  list.value = null
  listError.value = ''
  if (!dept.value?.enabled) return
  listLoading.value = true
  try {
    const { data } = await api.get('/svodka-releases/periods', { params: { department_id: deptId.value, to: to.value, days: DAYS } })
    list.value = data
  } catch (e) {
    listError.value = apiError(e, t('svodka.loadError'))
  } finally {
    listLoading.value = false
  }
}

async function loadDetail() {
  if (!selected.value) {
    detail.value = null
    return
  }
  detailLoading.value = true
  try {
    const { data } = await api.get('/svodka-releases/period', {
      params: { department_id: deptId.value, date: selected.value.date, shift: selected.value.shift || undefined },
    })
    detail.value = data
  } catch (e) {
    detail.value = null
    ui.error(apiError(e, t('svodka.loadError')))
  } finally {
    detailLoading.value = false
  }
}

onMounted(async () => {
  await loadInfo(false)
  if (route.params.id) router.replace({ name: 'svodka-releases', params: {}, query: deptId.value ? { department_id: deptId.value } : {} })
  await loadList()
  pickDefault()
})

watch(deptId, async (v, old) => {
  if (!old || !v) return
  selected.value = null
  detail.value = null
  settingsOpen.value = false
  router.replace({ name: 'svodka-releases', query: { department_id: v } })
  await loadList()
  pickDefault()
})

/** Birinchi chiqarishni kutayotgan (yoki o'zgargan) davr — avtomatik ochiladi */
function pickDefault() {
  const ps = list.value?.periods ?? []
  const p = ps.find((x) => ['changed', 'ready'].includes(releaseState(x))) ?? null
  select(p)
}
function select(p) {
  selected.value = p ? { date: p.date, shift: p.shift } : null
  loadDetail()
}
const isSelected = (p) => selected.value && selected.value.date === p.date && selected.value.shift === p.shift

function page(dir) {
  to.value = addDays(to.value, dir * DAYS)
  loadList()
}
const atToday = computed(() => to.value >= todayIso())

function shiftName(shift) {
  return shift ? list.value?.[`shift${shift}_label`] ?? '' : t('svodka.day.wholeDay')
}
function stateText(p) {
  const st = releaseState(p)
  if (st === 'released') return t('svodka.rel.released', { rev: p.release.revision })
  if (st === 'changed') return t('svodka.rel.changed', { n: p.changed })
  if (st === 'ready') return t('svodka.rel.ready')
  if (st === 'incomplete') return t('svodka.rel.incomplete', { n: p.pending })
  if (st === 'running') return t('svodka.rel.running')
  return t('svodka.rel.empty')
}

/* ---- chiqarish ---- */
const releaseDialog = reactive({ open: false, note: '', busy: false })
const status = computed(() => detail.value?.status ?? null)
const canRelease = computed(() => {
  const s = status.value
  if (!info.value?.can_release || !s || !s.values) return false
  return !s.release || s.changed > 0
})
function openRelease() {
  releaseDialog.note = ''
  releaseDialog.open = true
}
async function doRelease() {
  releaseDialog.busy = true
  try {
    const { data } = await api.post('/svodka-releases', {
      department_id: deptId.value, date: detail.value.date, shift: detail.value.shift || null, note: releaseDialog.note || null,
    })
    ui.success(t('svodka.rel.done', { rev: data.revision, n: data.samples }))
    releaseDialog.open = false
    await loadList()
    await loadDetail()
  } catch (e) {
    ui.error(apiError(e, t('svodka.loadError')))
  } finally {
    releaseDialog.busy = false
  }
}

const val = (v) => (v === null || v === undefined ? '' : formatValue(v, null, locale.value))
/** Chiqarilgan qiymat hozirgisidan farq qiladimi (nusxa 6 xonagacha yaxlitlangan) */
const differs = (e) => e.released_value !== null && e.value !== null && Math.abs(Math.round(e.value * 1e6) / 1e6 - e.released_value) > 1e-9

/* ---- sozlama (customer_departments.manage) ---- */
const settingsOpen = ref(false)
const viewersOpen = ref(false)   // [P31] svodkani ko'ruvchilar (faqat admin)
const sform = reactive({ enabled: false, release_mode: 'day', shift1_label: '', shift2_label: '', shift1_start: '20:00', shift2_start: '08:00', title: '' })
const sbusy = ref(false)
const serr = ref('')
function openSettings() {
  const d = dept.value
  Object.assign(sform, {
    enabled: !!d.enabled, release_mode: d.release_mode || 'day',
    shift1_label: d.shift1_label || 'I-navbatchilik', shift2_label: d.shift2_label || 'II-navbatchilik',
    shift1_start: d.shift1_start || '20:00', shift2_start: d.shift2_start || '08:00', title: d.title || '',
  })
  serr.value = ''
  settingsOpen.value = !settingsOpen.value
}
async function saveSettings() {
  sbusy.value = true
  serr.value = ''
  try {
    await api.put(`/svodka-releases/settings/${deptId.value}`, { ...sform, title: sform.title || null })
    ui.success(t('svodka.settings.saved'))
    settingsOpen.value = false
    await loadInfo()
    await loadList()
    pickDefault()
  } catch (e) {
    serr.value = apiError(e, t('svodka.loadError'))
  } finally {
    sbusy.value = false
  }
}
</script>

<template>
  <div>
    <PageHeader :title="t('svodka.rel.title')" :subtitle="t('svodka.rel.subtitle')">
      <template #actions>
        <select v-if="(info?.departments?.length ?? 0) > 0" v-model="deptId" class="field-input h-9 w-auto min-w-[220px]" data-dept>
          <option v-for="d in info.departments" :key="d.id" :value="d.id">
            {{ d.name }}<template v-if="d.code"> ({{ d.code }})</template><template v-if="!d.enabled"> — {{ t('svodka.settings.off') }}</template>
          </option>
        </select>
        <button v-if="info?.can_manage && dept" type="button" class="btn-outline btn-sm" :class="settingsOpen && 'bg-surface-sunken'" data-settings-toggle @click="openSettings">
          <Settings2 :size="15" /> {{ t('svodka.settings.button') }}
        </button>
        <button v-if="info?.can_viewers" type="button" class="btn-outline btn-sm" data-viewers-open @click="viewersOpen = true">
          <Users :size="15" /> {{ t('svodka.viewers.button') }}
        </button>
      </template>
    </PageHeader>

    <p v-if="infoError" class="panel p-4 text-[14px] text-crit" data-info-error>{{ infoError }}</p>

    <template v-else-if="info">
      <div v-if="!info.departments.length" class="panel">
        <EmptyState :icon="ClipboardCheck" :title="t('svodka.rel.noDepartments')" :hint="t('svodka.rel.noDepartmentsHint')" />
      </div>

      <template v-else-if="dept">
        <p class="mb-4 flex items-start gap-2 rounded-md border border-line bg-surface-sunken/50 px-3 py-2 text-[13px] text-ink-muted" data-blind>
          <EyeOff :size="15" class="mt-0.5 shrink-0 text-assay-500" /> {{ t('svodka.rel.blind') }}
        </p>

        <!-- Sozlama -->
        <section v-if="settingsOpen" class="panel mb-4 p-4" data-settings>
          <div class="flex items-start justify-between gap-4">
            <div>
              <h2 class="text-[14.5px] font-semibold text-ink">{{ t('svodka.settings.title', { name: dept.name }) }}</h2>
              <p class="mt-0.5 text-[12.5px] text-ink-muted">{{ t('svodka.settings.hint') }}</p>
            </div>
            <button
              type="button" role="switch" :aria-checked="sform.enabled" :aria-label="t('svodka.settings.enabled')"
              class="relative mt-0.5 h-6 w-10 rounded-full transition-colors shrink-0"
              :class="sform.enabled ? 'bg-assay-500' : 'bg-line'" data-switch="enabled"
              @click="sform.enabled = !sform.enabled"
            >
              <span class="absolute left-0 top-0.5 h-5 w-5 rounded-full bg-white shadow transition-transform" :class="sform.enabled ? 'translate-x-[18px]' : 'translate-x-0.5'" />
            </button>
          </div>

          <div class="mt-4 grid gap-4 lg:grid-cols-2" :class="!sform.enabled && 'opacity-60'">
            <fieldset>
              <legend class="field-label">{{ t('svodka.settings.mode') }}</legend>
              <div class="grid gap-2 sm:grid-cols-2">
                <label
                  v-for="m in ['day', 'shift']" :key="m"
                  class="flex cursor-pointer flex-col gap-1 rounded-md border px-3 py-2.5"
                  :class="sform.release_mode === m ? 'border-assay-500 bg-assay-50/60 dark:bg-assay-800/20' : 'border-line hover:border-ink-faint'"
                  :data-mode="m"
                >
                  <span class="flex items-center gap-2 text-[13.5px] font-medium text-ink">
                    <input v-model="sform.release_mode" type="radio" :value="m" class="accent-assay-500"> {{ t(`svodka.settings.modes.${m}`) }}
                  </span>
                  <span class="text-[12px] leading-snug text-ink-muted">{{ t(`svodka.settings.modeHints.${m}`) }}</span>
                </label>
              </div>
            </fieldset>
            <div>
              <label class="field-label">{{ t('svodka.settings.docTitle') }}</label>
              <input v-model="sform.title" class="field-input" maxlength="150" :placeholder="dept.name">
            </div>
            <div class="grid grid-cols-[1fr_110px] gap-2">
              <div>
                <label class="field-label">{{ t('svodka.settings.shift1') }}</label>
                <input v-model="sform.shift1_label" class="field-input" maxlength="60">
              </div>
              <div>
                <label class="field-label">{{ t('svodka.settings.start') }}</label>
                <input v-model="sform.shift1_start" type="time" class="field-input" data-f="s1">
              </div>
            </div>
            <div class="grid grid-cols-[1fr_110px] gap-2">
              <div>
                <label class="field-label">{{ t('svodka.settings.shift2') }}</label>
                <input v-model="sform.shift2_label" class="field-input" maxlength="60">
              </div>
              <div>
                <label class="field-label">{{ t('svodka.settings.start') }}</label>
                <input v-model="sform.shift2_start" type="time" class="field-input" data-f="s2">
              </div>
            </div>
          </div>
          <p class="mt-3 text-[12.5px] text-ink-muted">{{ t('svodka.settings.periodRule', { s1: sform.shift1_label, a: sform.shift1_start, b: sform.shift2_start }) }}</p>
          <p v-if="serr" class="mt-2 text-[13px] text-crit" data-settings-error>{{ serr }}</p>
          <div class="mt-4 flex justify-end gap-2">
            <button type="button" class="btn-ghost" @click="settingsOpen = false">{{ t('svodka.cancel') }}</button>
            <button type="button" class="btn-primary" :disabled="sbusy" data-settings-save @click="saveSettings"><Save :size="16" /> {{ t('svodka.save') }}</button>
          </div>
        </section>

        <div v-if="!dept.enabled" class="panel p-4 text-[14px] text-ink-muted" data-dept-off>
          {{ t('svodka.rel.deptOff') }}<template v-if="info.can_manage"> {{ t('svodka.rel.deptOffManage') }}</template>
        </div>

        <p v-else-if="listError" class="panel p-4 text-[14px] text-crit">{{ listError }}</p>

        <div v-else class="grid gap-4 xl:grid-cols-[minmax(380px,460px)_1fr] items-start">
          <!-- Davrlar -->
          <section class="panel overflow-hidden" data-period-list>
            <header class="flex items-center justify-between gap-2 px-4 py-2.5 border-b border-line">
              <h2 class="text-[14px] font-semibold text-ink">{{ list?.release_mode === 'shift' ? t('svodka.rel.byShift') : t('svodka.rel.byDay') }}</h2>
              <div class="flex items-center gap-1">
                <button type="button" class="btn-ghost btn-icon h-8 w-8" :aria-label="t('svodka.day.prev')" data-older @click="page(-1)"><ChevronLeft :size="17" /></button>
                <button type="button" class="btn-ghost btn-icon h-8 w-8" :disabled="atToday" :aria-label="t('svodka.day.next')" data-newer @click="page(1)"><ChevronRight :size="17" /></button>
              </div>
            </header>
            <div v-if="listLoading && !list" class="p-4 space-y-2">
              <div v-for="i in 6" :key="i" class="h-10 rounded bg-surface-sunken animate-pulse" />
            </div>
            <ul v-else-if="list" class="divide-y divide-line">
              <li v-for="p in list.periods" :key="`${p.date}-${p.shift}`">
                <button
                  type="button" class="flex w-full items-center gap-3 px-4 py-2.5 text-left transition-colors"
                  :class="isSelected(p) ? 'bg-assay-50/70 dark:bg-assay-800/20' : 'hover:bg-surface-sunken/60'"
                  :data-period-row="`${p.date}-${p.shift ?? 'd'}`" :data-state="releaseState(p)"
                  @click="select(p)"
                >
                  <span class="min-w-0 flex-1">
                    <span class="block text-[13.5px] font-medium text-ink tnum">{{ dayLabel(p.date) }} <span class="font-normal text-ink-muted capitalize">· {{ weekday(p.date, t) }}</span></span>
                    <span class="block text-[12px] text-ink-muted">
                      {{ shiftName(p.shift) }} · {{ t('svodka.rel.samples', { n: p.samples, c: p.complete }) }}
                    </span>
                  </span>
                  <span class="shrink-0 rounded-full px-2 py-0.5 text-[11.5px] font-medium" :class="RELEASE_TONE[releaseState(p)]">{{ stateText(p) }}</span>
                  <ArrowRight :size="15" class="shrink-0 text-ink-faint" />
                </button>
              </li>
            </ul>
          </section>

          <!-- Tafsilot -->
          <section class="panel overflow-hidden" data-detail>
            <div v-if="!selected" class="px-4 py-12 text-center text-[13.5px] text-ink-muted">{{ t('svodka.rel.choose') }}</div>
            <div v-else-if="detailLoading && !detail" class="p-4"><div class="h-48 rounded bg-surface-sunken animate-pulse" /></div>
            <template v-else-if="detail">
              <header class="flex flex-wrap items-start justify-between gap-3 px-4 py-3 border-b border-line">
                <div class="min-w-0">
                  <h2 class="text-[15px] font-semibold text-ink tnum">{{ dayLabel(detail.date) }} · {{ detail.shift_label || t('svodka.day.wholeDay') }}</h2>
                  <p class="mt-0.5 text-[12.5px] text-ink-muted">
                    {{ t('svodka.rel.summary', { n: status.samples, c: status.complete, v: status.values }) }}
                    <template v-if="status.release"> · {{ t('svodka.rel.lastRelease', { rev: status.release.revision, at: formatDateTime(status.release.released_at), by: status.release.released_by || '—' }) }}</template>
                  </p>
                </div>
                <button
                  v-if="info.can_release" type="button" class="btn-primary"
                  :disabled="!canRelease" :title="!canRelease ? t('svodka.rel.nothing') : null" data-release
                  @click="openRelease"
                >
                  <Send :size="16" /> {{ status.release ? t('svodka.rel.rerelease') : t('svodka.rel.release') }}
                </button>
              </header>

              <p v-if="!status.ended" class="mx-4 mt-3 rounded-md bg-surface-sunken px-3 py-2 text-[12.5px] text-ink-muted" data-running>{{ t('svodka.rel.runningHint') }}</p>
              <p v-if="status.release && status.changed" class="mx-4 mt-3 rounded-md bg-warn-light/70 dark:bg-warn-dark/20 px-3 py-2 text-[12.5px] text-warn-dark dark:text-warn-light" data-changed>{{ t('svodka.rel.changedHint', { n: status.changed }) }}</p>

              <div v-if="detail.samples.length" class="overflow-x-auto mt-3">
                <table class="w-full text-[13px]">
                  <thead>
                    <tr class="border-y border-line bg-surface-sunken/60 text-left text-[12px] uppercase tracking-wide text-ink-muted">
                      <th class="px-4 py-2 font-medium">{{ t('svodka.rel.code') }}</th>
                      <th v-if="!detail.shift" class="px-4 py-2 font-medium">{{ t('svodka.day.shift') }}</th>
                      <th class="px-4 py-2 font-medium">{{ t('svodka.rel.results') }}</th>
                      <th class="px-4 py-2 font-medium text-right">{{ t('svodka.rel.inRelease') }}</th>
                    </tr>
                  </thead>
                  <tbody class="divide-y divide-line">
                    <tr v-for="s in detail.samples" :key="s.id" :class="s.changed && 'bg-warn-light/30 dark:bg-warn-dark/10'" :data-rel-sample="s.id">
                      <td class="px-4 py-2 whitespace-nowrap">
                        <span class="font-mono font-semibold text-ink">{{ s.customer_code || s.sample_number }}</span>
                        <span class="block font-mono text-[11.5px] text-ink-faint">{{ s.code }}</span>
                      </td>
                      <td v-if="!detail.shift" class="px-4 py-2 text-ink-muted whitespace-nowrap">{{ s.shift ? list?.[`shift${s.shift}_label`] : '' }}</td>
                      <td class="px-4 py-2">
                        <span class="flex flex-wrap gap-1">
                          <span
                            v-for="e in s.elements" :key="e.symbol"
                            class="inline-flex items-baseline gap-1 rounded px-1.5 py-0.5 text-[12px] whitespace-nowrap"
                            :class="e.value === null ? 'bg-surface-sunken text-ink-faint' : (differs(e) || (status.release && e.released_value === null) ? 'bg-warn-light text-warn-dark dark:bg-warn-dark/25 dark:text-warn-light' : 'bg-ok-light text-ok-dark dark:bg-ok-dark/25 dark:text-ok-light')"
                            :data-el="e.symbol"
                          >
                            <span class="font-mono text-[11.5px]">{{ e.symbol }}</span>
                            <template v-if="differs(e)">
                              <span class="tnum line-through opacity-70">{{ val(e.released_value) }}</span>→
                            </template>
                            <span class="tnum font-semibold">{{ e.value === null ? '…' : val(e.value) }}</span>
                            <span v-if="e.unit && e.value !== null" class="text-[11px] opacity-75">{{ e.unit }}</span>
                          </span>
                        </span>
                      </td>
                      <td class="px-4 py-2 text-right whitespace-nowrap text-[12px]">
                        <span v-if="s.changed" class="font-medium text-warn-dark dark:text-warn-light">{{ t('svodka.rel.sampleChanged') }}</span>
                        <span v-else-if="s.in_release" class="text-ok-dark dark:text-ok-light">✓</span>
                        <span v-else class="text-ink-faint">—</span>
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>
              <p v-else class="px-4 py-8 text-center text-[13.5px] text-ink-muted">{{ t('svodka.rel.noSamples') }}</p>
            </template>
          </section>
        </div>
      </template>
    </template>

    <BaseModal v-model="releaseDialog.open" size="sm" :title="status?.release ? t('svodka.rel.confirmReTitle') : t('svodka.rel.confirmTitle')">
      <div v-if="detail" class="space-y-3 text-[13.5px]" data-release-dialog>
        <p class="text-ink">
          <b class="tnum">{{ dayLabel(detail.date) }} · {{ detail.shift_label || t('svodka.day.wholeDay') }}</b><br>
          {{ t('svodka.rel.confirmBody', { n: status.samples, v: status.values }) }}
        </p>
        <p v-if="status.pending" class="rounded-md bg-warn-light/70 dark:bg-warn-dark/20 px-3 py-2 text-[12.5px] text-warn-dark dark:text-warn-light">{{ t('svodka.rel.confirmPending', { n: status.pending }) }}</p>
        <p v-if="!status.ended" class="rounded-md bg-surface-sunken px-3 py-2 text-[12.5px] text-ink-muted">{{ t('svodka.rel.runningHint') }}</p>
        <p class="text-[12.5px] text-ink-muted">{{ t('svodka.rel.confirmNote') }}</p>
        <div>
          <label class="field-label">{{ t('svodka.rel.note') }}</label>
          <textarea v-model="releaseDialog.note" rows="2" maxlength="500" class="field-input" :placeholder="status.release ? t('svodka.rel.notePlaceholderRe') : ''" data-f="note" />
        </div>
      </div>
      <template #footer>
        <button class="btn-ghost" @click="releaseDialog.open = false">{{ t('svodka.cancel') }}</button>
        <button class="btn-primary" :disabled="releaseDialog.busy" data-release-go @click="doRelease"><Send :size="16" /> {{ t('svodka.rel.release') }}</button>
      </template>
    </BaseModal>
    <ViewersDialog v-model="viewersOpen" />
  </div>
</template>
