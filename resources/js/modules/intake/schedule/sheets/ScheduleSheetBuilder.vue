<script setup>
/**
 * [Paket 37] Admin — «Grafik jadvali konstruktori»: qaysi ustun kiritiladi, qaysi biri namuna natijasi,
 * qaysi biri formula / o'rtacha. Server tekshiradi (ScheduleSheets::cleanDefinition); bu yerda — tezkor ko'rsatma.
 */
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { ArrowDown, ArrowUp, Eye, Plus, Save, Table, Trash2 } from 'lucide-vue-next'
import api from '@/lib/api'
import PageHeader from '@/components/ui/PageHeader.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import { useUiStore } from '@/stores/ui'
import { parseFormula, formulaIdentifiers } from '@/modules/worksheets/engine'
import { apiErrorMessage, fieldError } from '../../requests/shared'
import '../i18n'
import './i18n'

const { t } = useI18n()
const ui = useUiStore()

const KINDS = ['input', 'result', 'formula', 'average']
const list = ref([])
const opts = ref({ departments: [], schedules: [], elements: [], functions: [] })
const loading = ref(true)
const form = ref(null)
const errors = ref({})
const busy = ref(false)

async function load() {
  loading.value = true
  try {
    const [a, b] = await Promise.all([api.get('/schedule-sheet-templates'), api.get('/schedule-sheet-templates/options')])
    list.value = a.data.data ?? []
    opts.value = b.data
  } catch (e) {
    ui.error(apiErrorMessage(e, t('ssheet.loadError')))
  } finally {
    loading.value = false
  }
}
onMounted(load)

const blankCol = (kind = 'input') => ({ key: '', title: '', unit: '', kind, decimals: 2, item: 1, element_id: null, formula: '', source: '', mode: 'day' })

function create() {
  errors.value = {}
  form.value = { id: null, department_id: opts.value.departments.find((d) => d.schedule_enabled)?.id ?? null, name: '', schedule_ids: [], is_active: true, sort_order: 0, columns: [blankCol()] }
}
function edit(s) {
  errors.value = {}
  form.value = {
    id: s.id, department_id: s.department_id, name: s.name, schedule_ids: [...s.schedule_ids], is_active: s.is_active, sort_order: s.sort_order,
    columns: s.columns.map((c) => ({ ...blankCol(c.kind), ...c, unit: c.unit ?? '' })),
  }
}

const dept = computed(() => opts.value.departments.find((d) => d.id === form.value?.department_id))
const deptSchedules = computed(() => opts.value.schedules.filter((s) => s.department_id === form.value?.department_id))
const rowKeys = computed(() => (form.value?.columns ?? []).filter((c) => c.kind !== 'average' && c.key).map((c) => c.key))

function formulaIssue(c) {
  if (c.kind !== 'formula' || !c.formula.trim()) return ''
  try {
    const ids = formulaIdentifiers(parseFormula(c.formula))
    const bad = ids.filter((id) => id === c.key || !rowKeys.value.includes(id))
    return bad.length ? `«${bad.join(', ')}» ?` : ''
  } catch (e) {
    return e.message
  }
}

function moveCol(i, d) {
  const a = form.value.columns
  const j = i + d
  if (j < 0 || j >= a.length) return
  ;[a[i], a[j]] = [a[j], a[i]]
}
function toggleSchedule(id) {
  const a = form.value.schedule_ids
  const i = a.indexOf(id)
  if (i >= 0) a.splice(i, 1)
  else a.push(id)
}

async function save() {
  busy.value = true
  errors.value = {}
  const f = form.value
  const payload = {
    department_id: f.department_id, name: f.name, schedule_ids: f.schedule_ids, is_active: f.is_active, sort_order: f.sort_order,
    columns: f.columns.map((c) => {
      const o = { key: c.key, title: c.title, unit: c.unit || null, kind: c.kind, decimals: c.decimals }
      if (c.kind === 'result') Object.assign(o, { item: c.item, element_id: c.element_id })
      if (c.kind === 'formula') o.formula = c.formula
      if (c.kind === 'average') Object.assign(o, { source: c.source, mode: c.mode })
      return o
    }),
  }
  try {
    const { data } = f.id ? await api.put(`/schedule-sheet-templates/${f.id}`, payload) : await api.post('/schedule-sheet-templates', payload)
    ui.success(t('ssheet.builder.saved'))
    await load()
    edit(list.value.find((s) => s.id === data.id) ?? data)
  } catch (e) {
    errors.value = e?.response?.data?.errors ?? {}
    ui.error(apiErrorMessage(e, t('ssheet.saveError')))
  } finally {
    busy.value = false
  }
}

async function remove(s) {
  if (!window.confirm(t('ssheet.builder.removeConfirm', { name: s.name }))) return
  try {
    await api.delete(`/schedule-sheet-templates/${s.id}`)
    if (form.value?.id === s.id) form.value = null
    ui.success(t('ssheet.builder.removed'))
    load()
  } catch (e) {
    ui.error(apiErrorMessage(e, t('ssheet.saveError')))
  }
}

const colErr = (i, f) => fieldError(errors.value, `columns.${i}.${f}`)
</script>

<template>
  <div>
    <PageHeader :title="t('ssheet.builder.title')">
      <template #actions>
        <button class="btn-primary" data-new-sheet @click="create"><Plus :size="16" /> {{ t('ssheet.builder.add') }}</button>
      </template>
    </PageHeader>
    <p class="mb-4 max-w-4xl text-[13.5px] leading-relaxed text-ink-muted">{{ t('ssheet.builder.hint') }}</p>

    <div class="grid gap-4 lg:grid-cols-[280px_minmax(0,1fr)]">
      <!-- ro'yxat -->
      <div class="panel overflow-hidden self-start">
        <h2 class="px-4 py-3 border-b border-line text-[14px] font-semibold text-ink">{{ t('ssheet.builder.list') }}</h2>
        <div v-if="loading" class="p-4"><div class="h-16 rounded-md bg-surface-sunken animate-pulse" /></div>
        <p v-else-if="!list.length" class="px-4 py-5 text-[13px] text-ink-faint">{{ t('ssheet.builder.none') }}</p>
        <ul v-else class="divide-y divide-line">
          <li v-for="s in list" :key="s.id" class="flex items-center gap-2 px-4 py-2.5 cursor-pointer hover:bg-surface-sunken"
              :class="form?.id === s.id && 'bg-surface-sunken'" :data-sheet="s.id" @click="edit(s)">
            <div class="min-w-0 flex-1">
              <p class="truncate text-[13.5px] font-medium" :class="s.is_active ? 'text-ink' : 'text-ink-faint line-through'">{{ s.name }}</p>
              <p class="truncate text-[12px] text-ink-muted">{{ s.department }} · {{ s.columns.length }}</p>
            </div>
            <RouterLink :to="{ name: 'schedule-sheets', query: { sheet: s.id } }" class="btn-ghost btn-icon h-7 w-7" :title="t('ssheet.builder.preview')" @click.stop><Eye :size="14" /></RouterLink>
            <button class="btn-ghost btn-icon h-7 w-7 hover:text-crit" :title="t('ssheet.builder.remove')" @click.stop="remove(s)"><Trash2 :size="14" /></button>
          </li>
        </ul>
      </div>

      <!-- tahrir -->
      <div v-if="!form" class="panel"><EmptyState :icon="Table" :title="t('ssheet.builder.list')" :hint="t('ssheet.builder.hint')" /></div>
      <div v-else class="panel p-4 space-y-4" data-sheet-form>
        <div class="grid gap-3 sm:grid-cols-[1fr_1fr_90px_90px]">
          <label class="block">
            <span class="field-label">{{ t('ssheet.builder.department') }}</span>
            <select v-model="form.department_id" class="field-input" :class="fieldError(errors, 'department_id') && 'field-input-error'" @change="form.schedule_ids = []">
              <option :value="null" disabled>{{ t('ssheet.builder.choose') }}</option>
              <option v-for="d in opts.departments" :key="d.id" :value="d.id">{{ d.name }}{{ d.schedule_enabled ? '' : ' ·' }}</option>
            </select>
            <span v-if="dept && !dept.schedule_enabled" class="mt-1 block text-[12px] text-warn-dark dark:text-warn-light">{{ t('ssheet.builder.notEnabled') }}</span>
          </label>
          <label class="block">
            <span class="field-label">{{ t('ssheet.builder.name') }}</span>
            <input v-model="form.name" class="field-input" maxlength="150" :placeholder="t('ssheet.builder.namePh')" :class="fieldError(errors, 'name') && 'field-input-error'" data-sheet-name>
          </label>
          <label class="block">
            <span class="field-label">{{ t('ssheet.builder.order') }}</span>
            <input v-model.number="form.sort_order" type="number" min="0" class="field-input">
          </label>
          <label class="flex items-end gap-2 pb-2 text-[13.5px] text-ink">
            <input v-model="form.is_active" type="checkbox" class="h-4 w-4"> {{ t('ssheet.builder.active') }}
          </label>
        </div>

        <div v-if="deptSchedules.length">
          <span class="field-label">{{ t('ssheet.builder.schedules') }}</span>
          <div class="flex flex-wrap gap-2">
            <button v-for="s in deptSchedules" :key="s.id" type="button" class="rounded-md border px-2.5 py-1 text-[12.5px]"
                    :class="form.schedule_ids.includes(s.id) ? 'border-ink bg-ink text-surface' : 'border-line text-ink-muted hover:border-ink-faint'"
                    @click="toggleSchedule(s.id)">
              <span class="font-mono">{{ s.time }}</span> {{ s.name }} · {{ s.items }}
            </button>
          </div>
          <p class="mt-1 text-[12px] text-ink-faint">{{ t('ssheet.builder.schedulesAll') }}</p>
        </div>

        <div>
          <div class="mb-2 flex items-center gap-2">
            <h3 class="text-[14px] font-semibold text-ink">{{ t('ssheet.builder.columns') }}</h3>
            <span class="text-[12px] text-ink-faint">{{ t('ssheet.builder.keyHint') }}</span>
            <span class="flex-1" />
            <button class="btn-outline btn-sm" data-add-col @click="form.columns.push(blankCol())"><Plus :size="14" /> {{ t('ssheet.builder.addColumn') }}</button>
          </div>
          <p v-if="fieldError(errors, 'columns')" class="mb-2 text-[12.5px] text-crit">{{ fieldError(errors, 'columns') }}</p>

          <div class="space-y-2">
            <div v-for="(c, i) in form.columns" :key="i" class="rounded-md border border-line p-3"
                 :class="(c.kind === 'formula' || c.kind === 'average') && 'bg-[#fff8d6] dark:bg-[rgb(120_100_20/0.18)]'" :data-col-row="i">
              <div class="grid gap-2 sm:grid-cols-[28px_120px_minmax(0,1fr)_90px_150px_70px_auto] items-start">
                <span class="pt-2 text-[12px] font-mono text-ink-faint">{{ i + 1 }}</span>
                <div>
                  <input v-model.trim="c.key" class="field-input font-mono" :placeholder="t('ssheet.builder.key')" maxlength="40" :class="colErr(i, 'key') && 'field-input-error'" data-col-key>
                </div>
                <input v-model="c.title" class="field-input" :placeholder="t('ssheet.builder.colTitle')" maxlength="200" :class="colErr(i, 'title') && 'field-input-error'" data-col-title>
                <input v-model="c.unit" class="field-input" :placeholder="t('ssheet.builder.unit')" maxlength="30">
                <select v-model="c.kind" class="field-input" :aria-label="t('ssheet.builder.kind')" data-col-kind>
                  <option v-for="k in KINDS" :key="k" :value="k">{{ t(`ssheet.builder.kinds.${k}`) }}</option>
                </select>
                <input v-model.number="c.decimals" type="number" min="0" max="6" class="field-input" :title="t('ssheet.builder.decimals')" :aria-label="t('ssheet.builder.decimals')">
                <div class="flex gap-0.5">
                  <button class="btn-ghost btn-icon h-8 w-8" :title="t('ssheet.builder.up')" :disabled="i === 0" @click="moveCol(i, -1)"><ArrowUp :size="14" /></button>
                  <button class="btn-ghost btn-icon h-8 w-8" :title="t('ssheet.builder.down')" :disabled="i === form.columns.length - 1" @click="moveCol(i, 1)"><ArrowDown :size="14" /></button>
                  <button class="btn-ghost btn-icon h-8 w-8 hover:text-crit" :title="t('ssheet.builder.remove')" @click="form.columns.splice(i, 1)"><Trash2 :size="14" /></button>
                </div>
              </div>

              <div v-if="c.kind === 'result'" class="mt-2 grid gap-2 sm:grid-cols-[28px_120px_minmax(0,1fr)]">
                <span />
                <label class="block">
                  <span class="text-[11.5px] text-ink-faint">{{ t('ssheet.builder.item') }}</span>
                  <input v-model.number="c.item" type="number" min="1" class="field-input" :title="t('ssheet.builder.itemHint')" :class="colErr(i, 'item') && 'field-input-error'">
                </label>
                <label class="block">
                  <span class="text-[11.5px] text-ink-faint">{{ t('ssheet.builder.element') }}</span>
                  <select v-model="c.element_id" class="field-input" :class="colErr(i, 'element_id') && 'field-input-error'">
                    <option :value="null" disabled>{{ t('ssheet.builder.choose') }}</option>
                    <option v-for="e in opts.elements" :key="e.id" :value="e.id">{{ e.symbol }} — {{ e.name }}{{ e.unit ? `, ${e.unit}` : '' }}</option>
                  </select>
                </label>
              </div>
              <div v-else-if="c.kind === 'formula'" class="mt-2 grid gap-2 sm:grid-cols-[28px_minmax(0,1fr)]">
                <span />
                <div>
                  <input v-model="c.formula" class="field-input font-mono" :placeholder="t('ssheet.builder.formulaPh')" :class="(colErr(i, 'formula') || formulaIssue(c)) && 'field-input-error'" data-col-formula>
                  <p class="mt-1 text-[11.5px] text-ink-faint">
                    <span v-for="k in rowKeys.filter((k) => k !== c.key)" :key="k" class="mr-1 inline-block cursor-pointer rounded bg-surface-sunken px-1 font-mono text-ink-muted hover:text-ink" @click="c.formula = (c.formula ? c.formula + ' ' : '') + k">{{ k }}</span>
                  </p>
                  <p v-if="colErr(i, 'formula') || formulaIssue(c)" class="mt-1 text-[12px] text-crit">{{ colErr(i, 'formula') || formulaIssue(c) }}</p>
                  <p v-else class="mt-1 text-[11.5px] text-ink-faint">{{ t('ssheet.builder.formulaHint', { fns: opts.functions.join(', ') }) }}</p>
                </div>
              </div>
              <div v-else-if="c.kind === 'average'" class="mt-2 grid gap-2 sm:grid-cols-[28px_200px_minmax(0,1fr)]">
                <span />
                <label class="block">
                  <span class="text-[11.5px] text-ink-faint">{{ t('ssheet.builder.source') }}</span>
                  <select v-model="c.source" class="field-input" :class="colErr(i, 'source') && 'field-input-error'">
                    <option value="" disabled>{{ t('ssheet.builder.choose') }}</option>
                    <option v-for="k in rowKeys" :key="k" :value="k">{{ k }} — {{ form.columns.find((x) => x.key === k)?.title }}</option>
                  </select>
                </label>
                <label class="block">
                  <span class="text-[11.5px] text-ink-faint">{{ t('ssheet.builder.mode') }}</span>
                  <select v-model="c.mode" class="field-input">
                    <option value="day">{{ t('ssheet.builder.modes.day') }}</option>
                    <option value="running">{{ t('ssheet.builder.modes.running') }}</option>
                  </select>
                </label>
              </div>
              <p v-for="f in ['key', 'title']" v-show="colErr(i, f)" :key="f" class="mt-1 text-[12px] text-crit">{{ colErr(i, f) }}</p>
              <p v-if="colErr(i, 'item') || colErr(i, 'element_id') || colErr(i, 'source') || colErr(i, 'kind')" class="mt-1 text-[12px] text-crit">
                {{ colErr(i, 'item') || colErr(i, 'element_id') || colErr(i, 'source') || colErr(i, 'kind') }}
              </p>
            </div>
          </div>
        </div>

        <div class="flex justify-end gap-2 border-t border-line pt-3">
          <button class="btn-primary" :disabled="busy" data-save-sheet @click="save"><Save :size="16" /> {{ t('ssheet.builder.save') }}</button>
        </div>
      </div>
    </div>
  </div>
</template>
