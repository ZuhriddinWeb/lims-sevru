<script setup>
/**
 * [Paket 39] «Buyurtmalar nazorati»: davr bo'yicha o'z vaqtida qabul, kechiktirib kiritilgan,
 * sabab bilan yopilgan (sabablar kesimida), avtomatik yopilgan, hali yopilmagan. Excel.
 */
import { onMounted, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { ClipboardCheck, FileSpreadsheet } from 'lucide-vue-next'
import api from '@/lib/api'
import PageHeader from '@/components/ui/PageHeader.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import { useUiStore } from '@/stores/ui'
import { formatDateTime } from '@/lib/format'
import { apiErrorMessage } from '../shared'
import '../i18n'
import '../i18nP39'

const { t } = useI18n()
const ui = useUiStore()

const iso = (d) => { const x = new Date(d); x.setMinutes(x.getMinutes() - x.getTimezoneOffset()); return x.toISOString().slice(0, 10) }
const now = new Date()
const f = reactive({ from: iso(new Date(now.getFullYear(), now.getMonth(), 1)), to: iso(now), department_id: '', source: '' })
const departments = ref([])
const data = ref(null)
const loading = ref(false)
const exporting = ref(false)

const params = () => ({ from: f.from, to: f.to, department_id: f.department_id || undefined, source: f.source || undefined })

async function load() {
  loading.value = true
  try {
    data.value = (await api.get('/request-control', { params: params() })).data
  } catch (e) {
    ui.error(apiErrorMessage(e, t('sreq.loadError')))
  } finally {
    loading.value = false
  }
}

onMounted(async () => {
  try {
    departments.value = (await api.get('/sample-requests/options')).data.departments ?? []
  } catch { departments.value = [] }
  load()
})
watch(() => ({ ...f }), load, { deep: true })

async function exportXlsx() {
  exporting.value = true
  try {
    const res = await api.get('/request-control', { params: { ...params(), export: 1 }, responseType: 'blob' })
    const url = URL.createObjectURL(res.data)
    const a = document.createElement('a')
    a.href = url
    a.download = `buyurtmalar_nazorati_${f.from}_${f.to}.xlsx`
    document.body.appendChild(a)
    a.click()
    a.remove()
    setTimeout(() => URL.revokeObjectURL(url), 4000)
  } catch (e) {
    ui.error(t('sreq.loadError'))
  } finally {
    exporting.value = false
  }
}

const CARDS = [
  ['total', ''], ['received', ''], ['on_time', 'text-ok-dark dark:text-ok-light'], ['late_entry', 'text-warn-dark dark:text-warn-light'],
  ['closed', ''], ['auto_closed', 'text-crit'], ['unclosed', 'text-crit'], ['waiting', ''],
]
const pct = (a, b) => (b ? Math.round((a / b) * 100) : 0)
const reasonName = (r) => (r.key === 'auto' ? t('sreq.p39.ctl.autoReason') : r.name ?? t('sreq.p39.ctl.textReason'))
</script>

<template>
  <div>
    <PageHeader :title="t('sreq.p39.ctl.title')">
      <template #actions>
        <button v-if="data?.totals?.total" class="btn-outline" :disabled="exporting" data-export @click="exportXlsx"><FileSpreadsheet :size="16" /> Excel</button>
      </template>
    </PageHeader>
    <p class="mb-4 max-w-3xl text-[13.5px] leading-relaxed text-ink-muted">{{ t('sreq.p39.ctl.hint') }}</p>

    <div class="panel p-3 mb-4 flex flex-wrap items-center gap-2.5">
      <input v-model="f.from" type="date" class="field-input w-auto" :aria-label="t('sreq.p39.ctl.from')">
      <span class="text-ink-faint">—</span>
      <input v-model="f.to" type="date" class="field-input w-auto" :aria-label="t('sreq.p39.ctl.to')">
      <select v-model="f.department_id" class="field-input w-auto min-w-[200px]">
        <option value="">{{ t('intake.portal.allDepartments') }}</option>
        <option v-for="d in departments" :key="d.id" :value="d.id">{{ d.name }}</option>
      </select>
      <select v-model="f.source" class="field-input w-auto min-w-[170px]">
        <option value="">{{ t('sreq.p39.sourceAll') }}</option>
        <option value="request">{{ t('sreq.p39.sourceRequest') }}</option>
        <option value="schedule">{{ t('sreq.p39.sourceSchedule') }}</option>
      </select>
    </div>

    <div v-if="loading && !data" class="h-40 rounded-panel bg-surface-sunken animate-pulse" />
    <div v-else-if="data && !data.totals.total" class="panel"><EmptyState :icon="ClipboardCheck" :title="t('sreq.p39.ctl.empty')" /></div>

    <template v-else-if="data">
      <div class="grid gap-3 grid-cols-2 sm:grid-cols-4 mb-4" :class="loading && 'opacity-60'" data-totals>
        <div v-for="[k, tone] in CARDS" :key="k" class="panel px-4 py-3" :data-card="k">
          <p class="text-[12px] text-ink-muted">{{ t(`sreq.p39.ctl.c.${k}`) }}</p>
          <p class="mt-0.5 text-[22px] font-semibold tnum" :class="tone || 'text-ink'">{{ data.totals[k] }}</p>
          <p v-if="['on_time', 'late_entry'].includes(k) && data.totals.received" class="text-[11.5px] text-ink-faint tnum">{{ pct(data.totals[k], data.totals.received) }}%</p>
        </div>
      </div>

      <section class="panel overflow-hidden mb-4">
        <h2 class="px-4 py-3 border-b border-line text-[15px] font-semibold text-ink">{{ t('sreq.p39.ctl.byDept') }}</h2>
        <div class="overflow-x-auto">
          <table class="w-full text-[13px]">
            <thead>
              <tr class="text-left text-[11.5px] uppercase tracking-wide text-ink-faint border-b border-line">
                <th class="px-4 py-2 font-medium">{{ t('sreq.col.department') }}</th>
                <th v-for="[k] in CARDS" :key="k" class="px-3 py-2 font-medium text-right">{{ t(`sreq.p39.ctl.c.${k}`) }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-line">
              <tr v-for="d in data.departments" :key="d.id">
                <td class="px-4 py-2 text-ink">{{ d.name }} <span v-if="d.schedule" class="text-[11.5px] text-ink-faint">· {{ t('sreq.p39.ctl.schedN', { n: d.schedule }) }}</span></td>
                <td v-for="[k, tone] in CARDS" :key="k" class="px-3 py-2 text-right tnum" :class="d[k] ? tone || 'text-ink' : 'text-ink-faint'">{{ d[k] }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <div class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.6fr)]">
        <section class="panel overflow-hidden self-start">
          <h2 class="px-4 py-3 border-b border-line text-[15px] font-semibold text-ink">{{ t('sreq.p39.ctl.reasons') }}</h2>
          <p v-if="!data.reasons.length" class="px-4 py-4 text-[13px] text-ink-faint">—</p>
          <ul v-else class="divide-y divide-line text-[13px]">
            <li v-for="r in data.reasons" :key="r.key" class="flex items-center gap-3 px-4 py-2">
              <span class="flex-1 min-w-0" :class="r.key === 'auto' ? 'text-crit' : 'text-ink'">{{ reasonName(r) }}</span>
              <span class="tnum font-semibold text-ink">{{ r.count }}</span>
            </li>
          </ul>
        </section>
        <section class="panel overflow-hidden self-start">
          <h2 class="px-4 py-3 border-b border-line text-[15px] font-semibold text-ink">{{ t('sreq.p39.ctl.late') }}</h2>
          <p v-if="!data.late.length" class="px-4 py-4 text-[13px] text-ink-faint">—</p>
          <div v-else class="overflow-x-auto">
            <table class="w-full text-[13px]">
              <thead>
                <tr class="text-left text-[11.5px] uppercase tracking-wide text-ink-faint border-b border-line">
                  <th class="px-4 py-2 font-medium">{{ t('sreq.col.number') }}</th>
                  <th class="px-3 py-2 font-medium">{{ t('sreq.col.department') }}</th>
                  <th class="px-3 py-2 font-medium">{{ t('sreq.p39.ctl.actual') }}</th>
                  <th class="px-3 py-2 font-medium">{{ t('sreq.p39.enteredAt') }}</th>
                  <th class="px-3 py-2 font-medium text-right">{{ t('sreq.p39.ctl.delay') }}</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-line">
                <tr v-for="l in data.late" :key="l.id">
                  <td class="px-4 py-2"><router-link :to="{ name: 'sample-request', params: { id: l.id } }" class="font-mono font-semibold text-ink hover:underline">{{ l.number }}</router-link></td>
                  <td class="px-3 py-2 text-ink-muted">{{ l.department }}</td>
                  <td class="px-3 py-2 tnum">{{ formatDateTime(l.received_at) }}</td>
                  <td class="px-3 py-2 tnum text-ink-muted">{{ formatDateTime(l.entered_at) }}</td>
                  <td class="px-3 py-2 text-right tnum">{{ l.delay_hours }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </section>
      </div>
    </template>
  </div>
</template>
