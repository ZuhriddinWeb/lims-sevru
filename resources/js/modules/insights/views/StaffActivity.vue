<script setup>
/**
 * Xodimlar faoliyati — jadval (boshliq va rahbariyat uchun: «staff_activity.view»).
 * Ruxsati yo'q xodim bu sahifaga kirsa — o'z sahifasiga («Mening faoliyatim») o'tkaziladi.
 * Tartib: guruh, so'ng ism. Reyting yo'q (eng ko'p/eng kam ajratilmaydi).
 */
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { ChevronRight, FileSpreadsheet, HelpCircle, Users } from 'lucide-vue-next'
import api from '@/lib/api'
import PageHeader from '@/components/ui/PageHeader.vue'
import { useAuthStore } from '@/stores/auth'
import { useCatalogStore } from '@/stores/catalogs'
import { localized } from '@/i18n'
import PeriodBar from '../components/PeriodBar.vue'
import { apiErrorMessage, dm, hm, num, parseTs, rangeLabel } from '../helpers'
import '../i18n'

const { t, locale } = useI18n()
const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const catalogs = useCatalogStore()

const PRESETS = ['shift', 'prev_shift', 'today', 'yesterday', 'week', 'month', 'year', 'custom']
const q = route.query ?? {}
const filters = ref({
  period: PRESETS.includes(q.period) ? q.period : 'week',
  date_from: q.date_from ?? '',
  date_to: q.date_to ?? '',
  laboratory_id: q.laboratory_id ?? '',
  group_id: q.group_id ?? '',
  customer_department_id: '',
})
const idle = ref(q.idle === '1')

const data = ref(null)
const loading = ref(false)
const error = ref('')
const showHelp = ref(false)
let seq = 0

function params() {
  const f = filters.value
  const out = { period: f.period }
  if (f.period === 'custom') { out.date_from = f.date_from || undefined; out.date_to = f.date_to || undefined }
  if (f.laboratory_id) out.laboratory_id = f.laboratory_id
  if (f.group_id) out.group_id = f.group_id
  if (idle.value) out.idle = 1
  return out
}

async function load() {
  const my = ++seq
  loading.value = true
  error.value = ''
  try {
    const { data: res } = await api.get('/insights/staff', { params: params() })
    if (my !== seq) return
    data.value = res
  } catch (e) {
    if (my === seq) error.value = apiErrorMessage(e, t('insights.loadError'))
  } finally {
    if (my === seq) loading.value = false
  }
}

const canAll = computed(() => auth.can('staff_activity.view'))

onMounted(() => {
  if (!canAll.value) {
    // Faqat o'zi — to'g'ridan-to'g'ri o'z sahifasiga
    router.replace({ name: 'staff-activity-user', params: { id: auth.user?.id }, query: route.query })
    return
  }
  catalogs.load('laboratories')
  load()
})

watch([filters, idle], () => {
  const query = Object.fromEntries(Object.entries(params()).filter(([, v]) => v !== undefined && v !== ''))
  router.replace({ query }).catch(() => {})
  const f = filters.value
  if (f.period !== 'custom' || (f.date_from && f.date_to)) load()
}, { deep: true })

const labs = computed(() => catalogs.get('laboratories'))
const groups = computed(() => data.value?.groups ?? [])
const shifts = computed(() => data.value?.shifts ?? [])
const rows = computed(() => data.value?.rows ?? [])

/** Ustunlar: davrda biror qiymati bor ko'rsatkichlar; hech narsa bo'lmasa — asosiylari */
const CORE = ['received', 'results', 'worksheets', 'signoffs']
const columns = computed(() => {
  const all = data.value?.metrics ?? []
  const totals = data.value?.totals?.metrics ?? {}
  const used = all.filter((m) => (totals[m] ?? 0) > 0)
  return used.length ? used : all.filter((m) => CORE.includes(m))
})
const shiftCols = computed(() => {
  const ts = data.value?.totals?.shifts ?? {}
  const out = shifts.value.map((s) => ({ key: String(s.id), label: s.name }))
  if (ts.out) out.push({ key: 'out', label: t('insights.staff.out') })
  if (ts.nt) out.push({ key: 'nt', label: t('insights.staff.noTime') })
  return out
})
const showShifts = computed(() => shifts.value.length > 0)
const colspan = computed(() => columns.value.length + 3 + (showShifts.value ? shiftCols.value.length : 0) + (data.value?.grain === 'day' ? 1 : 0))
const hourly = computed(() => data.value?.period?.bucket === 'hour')

function timeRange(r) {
  if (!r.first_at) return '—'
  const a = parseTs(r.first_at)
  const b = parseTs(r.last_at)
  if (hourly.value) return `${hm(a)}–${hm(b)}`
  return `${dm(a)} ${hm(a)} — ${dm(b)} ${hm(b)}`
}
const fmtAt = (v) => { const d = parseTs(v); return d ? `${dm(d)} ${hm(d)}` : '' }
const groupName = (g) => (g ? localized(g, 'name') : t('insights.staff.noGroup'))
const rowTotal = (r) => Object.values(r.metrics ?? {}).reduce((a, b) => a + b, 0)

/* ---- guruh sarlavhalari bilan ---- */
const grouped = computed(() => {
  const out = []
  let last = undefined
  for (const r of rows.value) {
    const gid = r.user.group?.id ?? null
    if (gid !== last) {
      out.push({ header: true, key: `g${gid}`, name: groupName(r.user.group) })
      last = gid
    }
    out.push({ header: false, key: `u${r.user.id}`, row: r })
  }
  return out
})

function open(r) {
  router.push({ name: 'staff-activity-user', params: { id: r.user.id }, query: params() })
}

function exportExcel() {
  const p = new URLSearchParams({ ...params(), locale: locale.value })
  window.open(`/api/insights/staff/export?${p.toString()}`, '_blank')
}

const periodLabel = computed(() => {
  const p = data.value?.period
  if (!p) return ''
  if (p.shift) return `${p.shift.name} · ${rangeLabel(p.shift.start, p.shift.end, true)}`
  return rangeLabel(p.from, p.to)
})
</script>

<template>
  <div>
    <PageHeader :title="t('insights.staff.title')" :subtitle="t('insights.staff.subtitle')">
      <template #actions>
        <button type="button" class="btn-outline btn-sm" :disabled="!rows.length" data-excel @click="exportExcel">
          <FileSpreadsheet :size="15" /> {{ t('insights.staff.excel') }}
        </button>
      </template>
    </PageHeader>

    <PeriodBar v-model="filters" :shifts="shifts" :labs="labs" :groups="groups" />
    <div class="mt-2 mb-4 flex flex-wrap items-center justify-between gap-2">
      <span class="text-[12.5px] font-medium text-ink tnum" data-period-label>{{ periodLabel }}</span>
      <div class="flex flex-wrap items-center gap-3">
        <label class="inline-flex items-center gap-1.5 text-[12.5px] text-ink-muted cursor-pointer select-none">
          <input v-model="idle" type="checkbox" class="rounded border-line" data-idle> {{ t('insights.staff.idle') }}
        </label>
        <button type="button" class="inline-flex items-center gap-1 text-[12.5px] text-ink-muted hover:text-ink" data-help-toggle @click="showHelp = !showHelp">
          <HelpCircle :size="14" /> {{ t('insights.staff.help') }}
        </button>
      </div>
    </div>

    <section v-if="showHelp" class="panel p-4 mb-4 text-[12.5px]" data-help>
      <p class="text-ink-muted mb-2">{{ t('insights.staff.helpNote') }}</p>
      <dl class="grid gap-x-6 gap-y-1 sm:grid-cols-2">
        <div v-for="m in data?.metrics ?? []" :key="m" class="flex gap-2">
          <dt class="font-medium text-ink whitespace-nowrap">{{ t(`insights.staff.metrics.${m}`) }}:</dt>
          <dd class="text-ink-muted">{{ t(`insights.staff.explain.${m}`) }}</dd>
        </div>
      </dl>
    </section>

    <p v-if="error" class="panel p-4 mb-4 text-[14px] text-crit" data-error>{{ error }}</p>

    <section class="panel overflow-hidden" :class="loading && data ? 'opacity-70' : ''">
      <div v-if="!data && loading" class="p-4 space-y-2"><div v-for="i in 5" :key="i" class="h-10 rounded bg-surface-sunken animate-pulse" /></div>

      <div v-else-if="data && !rows.length" class="p-8 text-center" data-empty>
        <Users :size="28" class="mx-auto text-ink-faint mb-2" />
        <p class="text-[14px] text-ink-muted">{{ t('insights.staff.empty') }}</p>
      </div>

      <div v-else-if="data" class="overflow-x-auto">
        <table class="w-full text-[13px]" data-staff-table>
          <thead>
            <tr class="border-b border-line bg-surface-sunken/60 text-left text-[11.5px] uppercase tracking-wide text-ink-muted">
              <th class="px-3 py-2.5 font-medium sticky left-0 bg-surface-sunken z-10 min-w-[190px]">{{ t('insights.staff.employee') }}</th>
              <th v-for="m in columns" :key="m" class="px-2 py-2.5 font-medium text-right whitespace-nowrap" :title="t(`insights.staff.metrics.${m}`)" :data-col="m">{{ t(`insights.staff.short.${m}`) }}</th>
              <th
                v-for="(sc, i) in (showShifts ? shiftCols : [])" :key="`sh-${sc.key}`"
                class="px-2 py-2.5 font-medium text-right whitespace-nowrap bg-assay-50/60 dark:bg-assay-900/20" :class="i === 0 ? 'border-l border-line' : ''"
                :title="`${t('insights.staff.shifts')}: ${sc.label}`" :data-shift-col="sc.key"
              >{{ sc.label }}</th>
              <th v-if="data.grain === 'day'" class="px-2 py-2.5 font-medium text-right whitespace-nowrap">{{ t('insights.staff.activeDays') }}</th>
              <th class="px-3 py-2.5 font-medium whitespace-nowrap">{{ t('insights.staff.time') }}</th>
              <th class="w-6" />
            </tr>
          </thead>
          <tbody>
            <template v-for="item in grouped" :key="item.key">
              <tr v-if="item.header" class="bg-surface-sunken/40" data-group-header>
                <td :colspan="colspan" class="px-3 py-1.5 text-[12px] font-semibold text-ink-muted">{{ item.name }}</td>
              </tr>
              <tr
                v-else
                class="border-t border-line hover:bg-surface-sunken/60 cursor-pointer transition-colors"
                :class="rowTotal(item.row) ? '' : 'text-ink-faint'"
                :data-user="item.row.user.id" @click="open(item.row)"
              >
                <td class="px-3 py-2 sticky left-0 bg-surface z-10">
                  <span class="font-medium" :class="rowTotal(item.row) ? 'text-ink' : ''">{{ item.row.user.name }}</span>
                  <span v-if="!item.row.user.is_active" class="ml-1 text-[11px] text-ink-faint">({{ t('insights.staff.inactive') }})</span>
                  <span v-if="item.row.user.position" class="block text-[11.5px] text-ink-faint truncate max-w-[220px]">{{ item.row.user.position }}</span>
                </td>
                <td v-for="m in columns" :key="m" class="px-2 py-2 text-right tnum" :class="item.row.metrics[m] ? 'text-ink' : 'text-ink-faint'" :data-cell="m">
                  {{ item.row.metrics[m] ? num(item.row.metrics[m], locale) : '·' }}
                </td>
                <td
                  v-for="(sc, i) in (showShifts ? shiftCols : [])" :key="`sh-${sc.key}`"
                  class="px-2 py-2 text-right tnum bg-assay-50/40 dark:bg-assay-900/10" :class="[i === 0 ? 'border-l border-line' : '', item.row.shifts[sc.key] ? 'text-ink' : 'text-ink-faint']"
                  :data-shift-cell="sc.key"
                >{{ item.row.shifts[sc.key] ? num(item.row.shifts[sc.key], locale) : '·' }}</td>
                <td v-if="data.grain === 'day'" class="px-2 py-2 text-right tnum" data-days>{{ item.row.active_days || '·' }}</td>
                <td class="px-3 py-2 text-[12px] text-ink-muted tnum whitespace-nowrap leading-tight" data-time>
                  <template v-if="!item.row.first_at">—</template>
                  <template v-else-if="hourly">{{ timeRange(item.row) }}</template>
                  <template v-else><span class="block">{{ fmtAt(item.row.first_at) }}</span><span class="block text-ink-faint">{{ fmtAt(item.row.last_at) }}</span></template>
                </td>
                <td class="pr-2 text-ink-faint"><ChevronRight :size="15" /></td>
              </tr>
            </template>
          </tbody>
          <tfoot>
            <tr class="border-t-2 border-line bg-surface-sunken/40 font-semibold" data-totals>
              <td class="px-3 py-2 sticky left-0 bg-surface-sunken z-10">{{ t('insights.staff.total') }}</td>
              <td v-for="m in columns" :key="m" class="px-2 py-2 text-right tnum text-ink">{{ num(data.totals.metrics[m] ?? 0, locale) }}</td>
              <td
                v-for="(sc, i) in (showShifts ? shiftCols : [])" :key="`sh-${sc.key}`"
                class="px-2 py-2 text-right tnum text-ink bg-assay-50/40 dark:bg-assay-900/10" :class="i === 0 ? 'border-l border-line' : ''"
              >{{ num(data.totals.shifts[sc.key] ?? 0, locale) }}</td>
              <td v-if="data.grain === 'day'" />
              <td colspan="2" />
            </tr>
          </tfoot>
        </table>
      </div>
    </section>
  </div>
</template>
