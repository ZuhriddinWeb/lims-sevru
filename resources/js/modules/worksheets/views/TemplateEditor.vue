<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue'
import { onBeforeRouteLeave, useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import {
  AlertCircle, ArrowLeft, CheckCircle2, ChevronDown, ChevronUp, Languages, Plus, Save, ServerCog, Sigma, Trash2,
} from 'lucide-vue-next'
import api from '@/lib/api'
import { useUiStore } from '@/stores/ui'
import { localized } from '@/i18n'
import { FUNCTIONS, calculate, validateDefinition } from '../engine'
import { apiErrorMessage, colLabel, fmt, templateName } from '../helpers'
import '../i18n'

const route = useRoute()
const router = useRouter()
const { t, locale } = useI18n()
const ui = useUiStore()

const isNew = computed(() => route.params.id === 'new')
const loading = ref(true)
const saving = ref(false)
const elements = ref([])
const profiles = ref([])
const serverErrors = ref({})
const savedSnapshot = ref('')

const tpl = reactive({
  code: '', version: '', valid_from: '', name_uz: '', name_ru: '', name_uz_cyrl: '',
  test_profile_id: '', number_suffix: '', number_start: 1, default_rows: 2, max_samples: '',
  print_locale: 'uz', orientation: 'landscape', is_active: true, notes: '',
  stages: [], params: [],
})
const rowCols = ref([])
const sumCols = ref([])
const totCols = ref([])
const openLangs = ref(new Set())

/* ---------------------------------------------------------- bo'sh shablon */
const blankCol = (o = {}) => ({
  scope: 'row', key: '', type: 'input', stage: '', label_uz: '', label_ru: '', label_uz_cyrl: '',
  unit: '', decimals: null, rounding: 'round', formula: '', default_value: '', required: false,
  element_id: null, tolerance_pct: '', tolerance_source: '', tolerance_mode: 'mean', width: null, consumes_sample: false, ...o,
})

// «Proba sarfi» (naveska) — shablonda faqat bitta ustun: belgilansa, boshqalaridan olib tashlanadi
function toggleConsumes(col) {
  const on = !col.consumes_sample
  rowCols.value.forEach((c) => { c.consumes_sample = false })
  col.consumes_sample = on
}

function setBlank() {
  Object.assign(tpl, {
    code: '', version: '', valid_from: '', name_uz: '', name_ru: '', name_uz_cyrl: '', test_profile_id: '',
    number_suffix: '', number_start: 1, default_rows: 2, max_samples: '', print_locale: 'uz', orientation: 'landscape',
    is_active: true, notes: '',
    stages: [
      { key: 'shixtovka', label_uz: 'Shixtovka qildi', label_ru: 'Шихтовка', label_uz_cyrl: 'Шихтовка қилди', signers: 1 },
      { key: 'eritish', label_uz: 'Qaynatib eritdi', label_ru: 'Плавка', label_uz_cyrl: 'Қайнатиб эритди', signers: 1 },
      { key: 'kupelyatsiya', label_uz: 'Kupelyatsiya qildi', label_ru: 'Купелирование', label_uz_cyrl: 'Купеляция қилди', signers: 1 },
      { key: 'olchash', label_uz: "O'lchadi", label_ru: 'Взвешивание', label_uz_cyrl: 'Ўлчади', signers: 1 },
    ],
    params: [],
  })
  rowCols.value = [
    blankCol({ key: 'mass', label_uz: "Namuna og'irligi, g", label_ru: 'Навеска, г', stage: 'shixtovka', unit: 'g', default_value: '1.0', required: true, consumes_sample: true }),
    blankCol({ key: 'kortochka', label_uz: 'Kortochka, mg', label_ru: 'Корточка, мг', stage: 'olchash', unit: 'mg', required: true }),
    blankCol({ key: 'au_gt', type: 'computed', label_uz: 'Au, g/t', label_ru: 'Au, г/т', stage: 'olchash', unit: 'g/t', formula: 'kortochka / mass * 1000', decimals: 2 }),
  ]
  sumCols.value = [
    blankCol({ scope: 'summary', type: 'computed', key: 'avg_au', label_uz: "O'rtacha Au, g/t", label_ru: 'Среднее Au, г/т', unit: 'g/t', formula: 'avg(au_gt)', decimals: 2 }),
  ]
  totCols.value = []
}

function fromServer(data) {
  Object.assign(tpl, {
    code: data.code ?? '', version: data.version ?? '', valid_from: data.valid_from ?? '',
    name_uz: data.name_uz ?? '', name_ru: data.name_ru ?? '', name_uz_cyrl: data.name_uz_cyrl ?? '',
    test_profile_id: data.test_profile_id ?? '', number_suffix: data.number_suffix ?? '',
    number_start: data.number_start ?? 1, default_rows: data.default_rows ?? 2, max_samples: data.max_samples ?? '',
    print_locale: data.print_locale ?? 'uz', orientation: data.orientation ?? 'landscape',
    is_active: !!data.is_active, notes: data.notes ?? '',
    stages: (data.stages ?? []).map((s) => ({ label_ru: '', label_uz_cyrl: '', signers: 1, ...s })),
    params: (data.params ?? []).map((p) => ({ label_ru: '', label_uz_cyrl: '', unit: '', default: '', decimals: null, ...p, default: p.default ?? '' })),
  })
  const cols = (data.columns ?? []).map((c) => blankCol({
    ...c,
    stage: c.stage ?? '', unit: c.unit ?? '', formula: c.formula ?? '', default_value: c.default_value ?? '',
    label_ru: c.label_ru ?? '', label_uz_cyrl: c.label_uz_cyrl ?? '',
    tolerance_pct: c.tolerance_pct === null || c.tolerance_pct === undefined ? '' : String(Number(c.tolerance_pct)),
    tolerance_source: c.tolerance_source ?? '', tolerance_mode: c.tolerance_mode ?? 'mean',
    consumes_sample: !!c.consumes_sample,
  }))
  rowCols.value = cols.filter((c) => c.scope === 'row')
  sumCols.value = cols.filter((c) => c.scope === 'summary')
  totCols.value = cols.filter((c) => c.scope === 'total')
}

const snapshot = () => JSON.stringify({ tpl, r: rowCols.value, s: sumCols.value, j: totCols.value })

onMounted(async () => {
  try {
    const [els, prof] = await Promise.all([
      api.get('/elements', { params: { all: 1 } }).catch(() => ({ data: [] })),
      api.get('/test-profiles', { params: { all: 1 } }).catch(() => ({ data: [] })),
    ])
    elements.value = els.data
    profiles.value = prof.data
    if (isNew.value) {
      setBlank()
    } else {
      const { data } = await api.get(`/worksheet-templates/${route.params.id}`)
      fromServer(data)
    }
    resetPreview()
    savedSnapshot.value = snapshot()
  } catch (e) {
    ui.error(apiErrorMessage(e, t('ws.templateNotFound')))
    router.replace({ name: 'worksheet-templates' })
  } finally {
    loading.value = false
  }
})

/* ---------------------------------------------------------- ta'rif + xatolar */
function cleanCol(c) {
  const summary = c.scope === 'summary'
  const computedScope = summary || c.scope === 'total' // jami ham doim hisoblanadi
  return {
    scope: c.scope,
    key: (c.key || '').trim(),
    type: computedScope ? 'computed' : c.type,
    stage: c.stage || null,
    label_uz: c.label_uz,
    label_ru: c.label_ru || null,
    label_uz_cyrl: c.label_uz_cyrl || null,
    unit: c.unit || null,
    decimals: c.decimals === '' || c.decimals === null || c.decimals === undefined ? null : Number(c.decimals),
    rounding: c.rounding || 'round',
    formula: computedScope || c.type === 'computed' ? (c.formula || '') : null,
    default_value: !computedScope && c.type !== 'computed' && c.default_value !== '' ? String(c.default_value) : null,
    required: !computedScope && c.type === 'input' ? !!c.required : false,
    element_id: summary ? (c.element_id || null) : null,
    tolerance_pct: summary && c.tolerance_pct !== '' && c.tolerance_pct !== null ? String(c.tolerance_pct).replace(',', '.') : null,
    tolerance_source: summary && c.tolerance_pct !== '' ? (c.tolerance_source || null) : null,
    tolerance_mode: c.tolerance_mode || 'mean',
    width: c.width || null,
    consumes_sample: c.scope === 'row' && c.type === 'input' ? !!c.consumes_sample : false,
  }
}

const definition = computed(() => ({
  stages: tpl.stages.map((s) => ({ ...s, key: (s.key || '').trim(), signers: Number(s.signers ?? 1) })),
  params: tpl.params.map((p) => ({ ...p, key: (p.key || '').trim(), default: p.default === '' || p.default === null || p.default === undefined ? null : String(p.default) })),
  columns: [...rowCols.value, ...sumCols.value, ...totCols.value].map(cleanCol),
}))

const errors = computed(() => validateDefinition(definition.value))
const errorMap = computed(() => {
  const m = {}
  for (const e of errors.value) (m[e.field] ||= []).push(e.message)
  for (const [k, v] of Object.entries(serverErrors.value)) (m[k] ||= []).push(...v)
  return m
})
const errFor = (path) => errorMap.value[path]?.[0] ?? null
const rowPath = (i, f) => `columns.${i}.${f}`
const sumPath = (j, f) => `columns.${rowCols.value.length + j}.${f}`
const totPath = (k, f) => `columns.${rowCols.value.length + sumCols.value.length + k}.${f}`

const dirty = computed(() => !loading.value && snapshot() !== savedSnapshot.value)

/* ---------------------------------------------------------- tahrirlash */
function move(list, i, d) {
  const j = i + d
  if (j < 0 || j >= list.length) return
  const [x] = list.splice(i, 1)
  list.splice(j, 0, x)
}

function slugKey(label) {
  return (label || '').toLowerCase()
    .replace(/[ʻʼ'`’]/g, '')
    .replace(/[^a-z0-9]+/g, '_')
    .replace(/^_+|_+$/g, '')
    .replace(/^(\d)/, 'c$1')
    .slice(0, 40)
}
function autoKey(item) {
  if (!item._keyTouched && (!item.key || item.key === item._autoKey)) {
    item.key = slugKey(item.label_uz)
    item._autoKey = item.key
  }
}

function toggleLangs(id) {
  const s = new Set(openLangs.value)
  s.has(id) ? s.delete(id) : s.add(id)
  openLangs.value = s
}

const numericRowKeys = computed(() => rowCols.value.filter((c) => c.type !== 'text' && c.key).map((c) => c.key))
const paramKeys = computed(() => tpl.params.map((p) => p.key).filter(Boolean))

/* ------------------------------------------- formulaga token qo'shish */
const formulaEls = {}
const focused = ref(null) // 'r3' | 's0'
function insertToken(id, item, token) {
  const el = formulaEls[id]
  const v = item.formula || ''
  const start = el?.selectionStart ?? v.length
  const end = el?.selectionEnd ?? v.length
  const isFn = FUNCTIONS.includes(token)
  const ins = isFn ? `${token}()` : token
  const pad = start > 0 && !/[\s(]/.test(v[start - 1]) ? ' ' : ''
  item.formula = v.slice(0, start) + pad + ins + v.slice(end)
  requestAnimationFrame(() => {
    if (!el) return
    el.focus()
    const caret = start + pad.length + (isFn ? token.length + 1 : ins.length)
    el.setSelectionRange(caret, caret)
  })
}
function tokensFor(scope, selfKey) {
  const ids = [...paramKeys.value, ...numericRowKeys.value]
  if (scope === 'summary') ids.push(...sumCols.value.map((c) => c.key).filter(Boolean))
  if (scope === 'total') ids.push(...totCols.value.map((c) => c.key).filter(Boolean))
  return ids.filter((k) => k !== selfKey)
}
const SHOWN_FUNCS = ['avg', 'sum', 'min', 'max', 'count', 'round', 'trunc', 'abs']

/* ---------------------------------------------------------- sinab ko'rish */
const preview = reactive({ header: {}, rows: [] })
function resetPreview() {
  preview.header = {}
  preview.rows = Array.from({ length: Math.max(1, Math.min(6, Number(tpl.default_rows) || 2)) }, () => {
    const values = {}
    for (const c of rowCols.value) {
      if (c.type !== 'computed' && c.default_value !== '' && c.default_value !== null) values[c.key] = String(c.default_value)
    }
    return { values, excluded: false }
  })
}
const previewCalc = computed(() => calculate(definition.value, preview.header, preview.rows))
const previewCols = computed(() => definition.value.columns.filter((c) => c.scope === 'row' && c.key))
const serverCheck = ref(null)

async function checkOnServer() {
  serverCheck.value = null
  try {
    const { data } = await api.post('/worksheet-templates/preview', { ...payload(), preview: { header: preview.header, rows: preview.rows } })
    if (data.errors?.length) {
      serverCheck.value = { ok: false, text: data.errors.map((e) => e.message).join('; ') }
      return
    }
    const same = JSON.stringify(data.result.summary) === JSON.stringify(previewCalc.value.summary)
      && data.result.rows.every((r, i) => JSON.stringify(r.computed) === JSON.stringify(previewCalc.value.rows[i].computed))
    serverCheck.value = { ok: same, text: same ? t('ws.previewServerOk') : t('ws.previewServerDiff') }
  } catch (e) {
    serverCheck.value = { ok: false, text: apiErrorMessage(e, t('ws.saveError')) }
  }
}

/* ---------------------------------------------------------- saqlash */
function payload() {
  return {
    code: tpl.code, version: tpl.version || null, valid_from: tpl.valid_from || null,
    name_uz: tpl.name_uz, name_ru: tpl.name_ru || null, name_uz_cyrl: tpl.name_uz_cyrl || null,
    test_profile_id: tpl.test_profile_id || null, number_suffix: tpl.number_suffix || null,
    number_start: Number(tpl.number_start) || 1, default_rows: Number(tpl.default_rows) || 2,
    max_samples: tpl.max_samples === '' || tpl.max_samples === null ? null : Number(tpl.max_samples),
    print_locale: tpl.print_locale, orientation: tpl.orientation, is_active: !!tpl.is_active,
    notes: tpl.notes || null,
    stages: definition.value.stages.map(({ key, label_uz, label_ru, label_uz_cyrl, signers }) => ({
      key, label_uz, label_ru: label_ru || null, label_uz_cyrl: label_uz_cyrl || null, signers,
    })),
    params: definition.value.params.map(({ key, label_uz, label_ru, label_uz_cyrl, unit, default: d, decimals }) => ({
      key, label_uz, label_ru: label_ru || null, label_uz_cyrl: label_uz_cyrl || null, unit: unit || null,
      default: d, decimals: decimals === '' || decimals === null || decimals === undefined ? null : Number(decimals),
    })),
    columns: definition.value.columns,
  }
}

async function save() {
  if (errors.value.length) {
    ui.error(t('ws.errorsCount', { n: errors.value.length }))
    return
  }
  saving.value = true
  serverErrors.value = {}
  try {
    const { data } = isNew.value
      ? await api.post('/worksheet-templates', payload())
      : await api.put(`/worksheet-templates/${route.params.id}`, payload())
    fromServer(data)
    savedSnapshot.value = snapshot()
    ui.success(t('ws.tplSaved'))
    if (isNew.value) router.replace({ name: 'worksheet-template-edit', params: { id: data.id } })
  } catch (e) {
    serverErrors.value = e?.response?.data?.errors ?? {}
    ui.error(apiErrorMessage(e, t('ws.saveError')))
  } finally {
    saving.value = false
  }
}

const numberExample = computed(() => {
  const n = Number(tpl.number_start) || 1
  const s = (tpl.number_suffix || '').trim()
  if (!s) return `${tpl.code || 'KOD'}-${n}`
  return [...s].length === 1 ? `${n}${s}` : `${n} ${s}`
})

function beforeUnload(e) { if (dirty.value) { e.preventDefault(); e.returnValue = '' } }
window.addEventListener('beforeunload', beforeUnload)
onBeforeUnmount(() => window.removeEventListener('beforeunload', beforeUnload))
onBeforeRouteLeave(() => (dirty.value && !window.confirm(t('ws.leaveConfirm')) ? false : true))
</script>

<template>
  <div v-if="loading" class="space-y-4">
    <div class="h-14 panel animate-pulse" /><div class="h-96 panel animate-pulse" />
  </div>

  <div v-else>
    <!-- Sarlavha -->
    <div class="flex flex-wrap items-start justify-between gap-3 mb-4">
      <div class="flex items-start gap-3 min-w-0">
        <button class="btn-ghost btn-icon mt-0.5" @click="router.push({ name: 'worksheet-templates' })"><ArrowLeft :size="19" /></button>
        <div class="min-w-0">
          <h1 class="text-[22px] font-semibold tracking-[-.01em] text-ink leading-tight">
            {{ isNew ? t('ws.tplCreate') : (templateName(tpl, locale) || tpl.code) }}
            <span v-if="!isNew" class="font-mono text-[16px] text-ink-faint ml-1">{{ tpl.code }}</span>
          </h1>
          <p class="mt-1 text-[13px] text-ink-muted">{{ t('ws.tplSnapshotNote') }}</p>
        </div>
      </div>
      <div class="flex items-center gap-3">
        <span
          class="inline-flex items-center gap-1.5 text-[12.5px]"
          :class="errors.length ? 'text-crit' : 'text-ok'"
        >
          <AlertCircle v-if="errors.length" :size="15" /><CheckCircle2 v-else :size="15" />
          {{ errors.length ? t('ws.errorsCount', { n: errors.length }) : t('ws.noErrors') }}
        </span>
        <button class="btn-primary" :disabled="saving || !dirty" @click="save">
          <Save :size="16" /> {{ t('common.save') }}
        </button>
      </div>
    </div>

    <div class="grid gap-4 2xl:grid-cols-[minmax(0,1fr)_minmax(0,440px)]">
      <div class="space-y-4 min-w-0">
        <!-- ============ Asosiy ============ -->
        <section class="panel p-4">
          <h2 class="text-[15px] font-semibold text-ink mb-3">{{ t('ws.secBasic') }}</h2>
          <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <div>
              <label class="field-label">{{ t('ws.code') }} *</label>
              <input v-model="tpl.code" class="field-input font-mono" :class="errFor('code') && 'field-input-error'" placeholder="385-2026">
            </div>
            <div>
              <label class="field-label">{{ t('ws.version') }}</label>
              <input v-model="tpl.version" class="field-input">
            </div>
            <div>
              <label class="field-label">{{ t('ws.validFrom') }}</label>
              <input v-model="tpl.valid_from" type="date" class="field-input">
            </div>
            <div>
              <label class="field-label">{{ t('ws.testProfile') }}</label>
              <select v-model="tpl.test_profile_id" class="field-input">
                <option value="">—</option>
                <option v-for="p in profiles" :key="p.id" :value="p.id">{{ p.code }} · {{ localized(p, 'name') }}</option>
              </select>
            </div>
            <div class="sm:col-span-2">
              <label class="field-label">{{ t('ws.nameUz') }} *</label>
              <input v-model="tpl.name_uz" class="field-input" :class="errFor('name_uz') && 'field-input-error'">
            </div>
            <div>
              <label class="field-label">{{ t('ws.nameRu') }}</label>
              <input v-model="tpl.name_ru" class="field-input">
            </div>
            <div>
              <label class="field-label">{{ t('ws.nameCyrl') }}</label>
              <input v-model="tpl.name_uz_cyrl" class="field-input">
            </div>
            <div>
              <label class="field-label">{{ t('ws.numberSuffix') }}</label>
              <input v-model="tpl.number_suffix" class="field-input font-mono" placeholder="k/ch">
            </div>
            <div>
              <label class="field-label">{{ t('ws.numberStart') }}</label>
              <input v-model.number="tpl.number_start" type="number" min="1" class="field-input tnum">
              <p class="mt-1 text-[12px] text-ink-faint">{{ t('ws.numberPreview', { n: numberExample }) }}</p>
            </div>
            <div>
              <label class="field-label">{{ t('ws.defaultRows') }}</label>
              <input v-model.number="tpl.default_rows" type="number" min="1" max="6" class="field-input tnum"
                     :class="serverErrors.default_rows && 'field-input-error'">
            </div>
            <div>
              <label class="field-label">{{ t('ws.maxSamplesField') }}</label>
              <input v-model="tpl.max_samples" type="number" min="1" max="30" class="field-input tnum" :placeholder="t('ws.maxSamplesHint')">
            </div>
            <div>
              <label class="field-label">{{ t('ws.printLocale') }}</label>
              <select v-model="tpl.print_locale" class="field-input">
                <option value="uz">O'zbek (lotin)</option>
                <option value="uz_cyrl">Ўзбек (кирилл)</option>
                <option value="ru">Русский</option>
              </select>
            </div>
            <div>
              <label class="field-label">{{ t('ws.orientation') }}</label>
              <select v-model="tpl.orientation" class="field-input">
                <option value="landscape">{{ t('ws.landscape') }}</option>
                <option value="portrait">{{ t('ws.portrait') }}</option>
              </select>
            </div>
            <label class="flex items-center gap-2 text-[14px] text-ink mt-6 cursor-pointer">
              <input v-model="tpl.is_active" type="checkbox" class="rounded border-line text-assay-500 focus:ring-assay-500">
              {{ t('ws.active') }}
            </label>
            <div class="sm:col-span-2 lg:col-span-4">
              <label class="field-label">{{ t('ws.notes') }}</label>
              <textarea v-model="tpl.notes" rows="2" class="field-input" />
            </div>
          </div>
        </section>

        <!-- ============ Bosqichlar ============ -->
        <section class="panel p-4">
          <div class="flex items-center justify-between mb-3">
            <h2 class="text-[15px] font-semibold text-ink">{{ t('ws.secStages') }}</h2>
            <button class="btn-ghost btn-sm" @click="tpl.stages.push({ key: '', label_uz: '', label_ru: '', label_uz_cyrl: '', signers: 1 })">
              <Plus :size="15" /> {{ t('ws.addStage') }}
            </button>
          </div>
          <div class="space-y-2">
            <div v-for="(s, i) in tpl.stages" :key="i" class="grid gap-2 items-start grid-cols-[minmax(0,1fr)_minmax(0,1.4fr)_minmax(0,1.2fr)_minmax(0,1.2fr)_72px_auto]">
              <div>
                <input v-model="s.key" class="field-input h-9 font-mono text-[13px]" :placeholder="t('ws.key')" :class="errFor(`stages.${i}.key`) && 'field-input-error'">
                <p v-if="errFor(`stages.${i}.key`)" class="mt-0.5 text-[11.5px] text-crit">{{ errFor(`stages.${i}.key`) }}</p>
              </div>
              <input v-model="s.label_uz" class="field-input h-9" :placeholder="t('ws.label')" :class="errFor(`stages.${i}.label_uz`) && 'field-input-error'">
              <input v-model="s.label_ru" class="field-input h-9" :placeholder="t('ws.labelRu')">
              <input v-model="s.label_uz_cyrl" class="field-input h-9" :placeholder="t('ws.labelCyrl')">
              <input v-model.number="s.signers" type="number" min="0" max="6" class="field-input h-9 tnum" :title="t('ws.signers')">
              <div class="flex">
                <button class="btn-ghost btn-icon h-9 w-8" @click="move(tpl.stages, i, -1)"><ChevronUp :size="15" /></button>
                <button class="btn-ghost btn-icon h-9 w-8" @click="move(tpl.stages, i, 1)"><ChevronDown :size="15" /></button>
                <button class="btn-ghost btn-icon h-9 w-8 text-crit" @click="tpl.stages.splice(i, 1)"><Trash2 :size="15" /></button>
              </div>
            </div>
          </div>
          <p class="mt-2 text-[12px] text-ink-faint">{{ t('ws.signers') }}: 0–6</p>
        </section>

        <!-- ============ Parametrlar ============ -->
        <section class="panel p-4">
          <div class="flex items-center justify-between mb-3">
            <h2 class="text-[15px] font-semibold text-ink">{{ t('ws.secParams') }}</h2>
            <button class="btn-ghost btn-sm" @click="tpl.params.push({ key: '', label_uz: '', label_ru: '', label_uz_cyrl: '', unit: '', default: '', decimals: null })">
              <Plus :size="15" /> {{ t('ws.addParam') }}
            </button>
          </div>
          <div v-if="!tpl.params.length" class="text-[13px] text-ink-faint">—</div>
          <div class="space-y-2">
            <div v-for="(p, i) in tpl.params" :key="i" class="grid gap-2 items-start grid-cols-[minmax(0,1fr)_minmax(0,1.6fr)_minmax(0,1fr)_minmax(0,0.8fr)_minmax(0,0.9fr)_auto]">
              <div>
                <input v-model="p.key" class="field-input h-9 font-mono text-[13px]" :placeholder="t('ws.key')" :class="errFor(`params.${i}.key`) && 'field-input-error'">
                <p v-if="errFor(`params.${i}.key`)" class="mt-0.5 text-[11.5px] text-crit">{{ errFor(`params.${i}.key`) }}</p>
              </div>
              <input v-model="p.label_uz" class="field-input h-9" :placeholder="t('ws.label')">
              <input v-model="p.label_ru" class="field-input h-9" :placeholder="t('ws.labelRu')">
              <input v-model="p.unit" class="field-input h-9" :placeholder="t('ws.unit')">
              <input v-model="p.default" class="field-input h-9 tnum" :placeholder="t('ws.default')" :class="errFor(`params.${i}.default`) && 'field-input-error'">
              <button class="btn-ghost btn-icon h-9 w-8 text-crit" @click="tpl.params.splice(i, 1)"><Trash2 :size="15" /></button>
            </div>
          </div>
        </section>

        <!-- ============ Qator ustunlari ============ -->
        <section class="panel p-4">
          <div class="flex items-center justify-between mb-3">
            <h2 class="text-[15px] font-semibold text-ink">{{ t('ws.secColumns') }}</h2>
            <button class="btn-ghost btn-sm" @click="rowCols.push(blankCol())"><Plus :size="15" /> {{ t('ws.addColumn') }}</button>
          </div>
          <p v-if="errFor('columns')" class="mb-2 text-[12.5px] text-crit">{{ errFor('columns') }}</p>
          <div class="space-y-2.5">
            <div
              v-for="(c, i) in rowCols" :key="i"
              class="rounded-md border px-3 py-2.5 transition-colors"
              :class="c.type === 'computed' ? 'border-assay-200 bg-assay-50/40 dark:border-assay-800 dark:bg-assay-800/10' : 'border-line'"
            >
              <div class="grid gap-2 items-start grid-cols-[26px_minmax(0,1fr)_minmax(0,2fr)_minmax(0,1.1fr)_minmax(0,1.2fr)_auto]">
                <span class="h-9 grid place-items-center text-[12px] font-mono text-ink-faint">{{ i + 1 }}</span>
                <div>
                  <input v-model="c.key" class="field-input h-9 font-mono text-[13px]" :placeholder="t('ws.key')"
                         :class="errFor(rowPath(i, 'key')) && 'field-input-error'" @input="c._keyTouched = true">
                  <p v-if="errFor(rowPath(i, 'key'))" class="mt-0.5 text-[11.5px] text-crit leading-snug">{{ errFor(rowPath(i, 'key')) }}</p>
                </div>
                <div>
                  <input v-model="c.label_uz" class="field-input h-9" :placeholder="t('ws.label')"
                         :class="errFor(rowPath(i, 'label_uz')) && 'field-input-error'" @input="autoKey(c)">
                </div>
                <select v-model="c.type" class="field-input h-9">
                  <option value="input">{{ t('ws.typeInput') }}</option>
                  <option value="computed">{{ t('ws.typeComputed') }}</option>
                  <option value="text">{{ t('ws.typeText') }}</option>
                </select>
                <div>
                  <select v-model="c.stage" class="field-input h-9" :class="errFor(rowPath(i, 'stage')) && 'field-input-error'">
                    <option value="">{{ t('ws.noStage') }}</option>
                    <option v-for="s in tpl.stages" :key="s.key" :value="s.key">{{ colLabel(s, locale) || s.key }}</option>
                  </select>
                </div>
                <div class="flex">
                  <button class="btn-ghost btn-icon h-9 w-8" :title="t('ws.otherLangs')" @click="toggleLangs(`r${i}`)"><Languages :size="15" /></button>
                  <button class="btn-ghost btn-icon h-9 w-8" :title="t('ws.moveUp')" @click="move(rowCols, i, -1)"><ChevronUp :size="15" /></button>
                  <button class="btn-ghost btn-icon h-9 w-8" :title="t('ws.moveDown')" @click="move(rowCols, i, 1)"><ChevronDown :size="15" /></button>
                  <button class="btn-ghost btn-icon h-9 w-8 text-crit" :title="t('ws.remove')" @click="rowCols.splice(i, 1)"><Trash2 :size="15" /></button>
                </div>
              </div>

              <!-- Turga qarab -->
              <div class="mt-2 pl-[34px]">
                <div v-if="c.type === 'computed'" class="grid gap-2 grid-cols-[minmax(0,3fr)_minmax(0,0.8fr)_70px_minmax(0,1.4fr)]">
                  <div>
                    <div class="relative">
                      <Sigma :size="15" class="absolute left-2.5 top-1/2 -translate-y-1/2 text-assay-500" />
                      <input
                        :ref="(el) => (formulaEls[`r${i}`] = el)"
                        v-model="c.formula" class="field-input h-9 pl-8 font-mono text-[13px]" :placeholder="t('ws.formulaHint')"
                        :class="errFor(rowPath(i, 'formula')) && 'field-input-error'"
                        @focus="focused = `r${i}`"
                      >
                    </div>
                    <p v-if="errFor(rowPath(i, 'formula'))" class="mt-0.5 text-[11.5px] text-crit">{{ errFor(rowPath(i, 'formula')) }}</p>
                    <div v-if="focused === `r${i}`" class="mt-1.5 flex flex-wrap items-center gap-1 text-[11.5px]">
                      <span class="text-ink-faint mr-0.5">{{ t('ws.available') }}</span>
                      <button v-for="k in tokensFor('row', c.key)" :key="k" class="rounded bg-surface-sunken px-1.5 py-0.5 font-mono text-ink hover:bg-assay-100 dark:hover:bg-assay-800/40" @mousedown.prevent="insertToken(`r${i}`, c, k)">{{ k }}</button>
                      <span class="text-ink-faint mx-0.5">{{ t('ws.functions') }}</span>
                      <button v-for="f in ['round', 'trunc', 'abs', 'min', 'max']" :key="f" class="rounded bg-surface-sunken px-1.5 py-0.5 font-mono text-assay-700 dark:text-assay-300 hover:bg-assay-100 dark:hover:bg-assay-800/40" @mousedown.prevent="insertToken(`r${i}`, c, f)">{{ f }}()</button>
                    </div>
                  </div>
                  <input v-model="c.unit" class="field-input h-9" :placeholder="t('ws.unit')">
                  <input v-model.number="c.decimals" type="number" min="0" max="10" class="field-input h-9 tnum" :placeholder="t('ws.decimals')" :title="t('ws.decimals')">
                  <select v-model="c.rounding" class="field-input h-9" :title="t('ws.rounding')">
                    <option value="round">{{ t('ws.roundRound') }}</option>
                    <option value="trunc">{{ t('ws.roundTrunc') }}</option>
                    <option value="none">{{ t('ws.roundNone') }}</option>
                  </select>
                </div>
                <div v-else-if="c.type === 'input'" class="grid gap-2 grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto_auto] items-center">
                  <input v-model="c.unit" class="field-input h-9" :placeholder="t('ws.unit')">
                  <input v-model="c.default_value" class="field-input h-9 tnum" :placeholder="t('ws.default')">
                  <label class="flex items-center gap-2 text-[13px] text-ink-muted cursor-pointer">
                    <input v-model="c.required" type="checkbox" class="rounded border-line text-assay-500 focus:ring-assay-500">
                    {{ t('ws.required') }}
                  </label>
                  <label class="flex items-center gap-2 text-[13px] cursor-pointer" :class="c.consumes_sample ? 'text-aqua-700 dark:text-aqua-200 font-medium' : 'text-ink-muted'" :title="t('ws.consumesHint')" data-consumes>
                    <input :checked="c.consumes_sample" type="checkbox" class="rounded border-line text-aqua-600 focus:ring-aqua-500" @change="toggleConsumes(c)">
                    {{ t('ws.consumes') }}
                  </label>
                </div>
                <div v-if="openLangs.has(`r${i}`)" class="mt-2 grid gap-2 sm:grid-cols-2">
                  <input v-model="c.label_ru" class="field-input h-9" :placeholder="t('ws.labelRu')">
                  <input v-model="c.label_uz_cyrl" class="field-input h-9" :placeholder="t('ws.labelCyrl')">
                </div>
              </div>
            </div>
          </div>
        </section>

        <!-- ============ Yakuniy natijalar ============ -->
        <section class="panel p-4">
          <div class="flex items-center justify-between mb-3">
            <h2 class="text-[15px] font-semibold text-ink">{{ t('ws.secSummary') }}</h2>
            <button class="btn-ghost btn-sm" @click="sumCols.push(blankCol({ scope: 'summary', type: 'computed', decimals: 2 }))">
              <Plus :size="15" /> {{ t('ws.addSummary') }}
            </button>
          </div>
          <p class="-mt-2 mb-3 text-[12.5px] text-ink-muted">{{ t('ws.secSummaryHint') }}</p>
          <div class="space-y-2.5">
            <div v-for="(c, j) in sumCols" :key="j" class="rounded-md border border-ok/30 bg-ok-light/40 dark:bg-ok-dark/10 px-3 py-2.5">
              <div class="grid gap-2 items-start grid-cols-[minmax(0,1fr)_minmax(0,2fr)_minmax(0,1.6fr)_auto]">
                <div>
                  <input v-model="c.key" class="field-input h-9 font-mono text-[13px]" :placeholder="t('ws.key')"
                         :class="errFor(sumPath(j, 'key')) && 'field-input-error'" @input="c._keyTouched = true">
                  <p v-if="errFor(sumPath(j, 'key'))" class="mt-0.5 text-[11.5px] text-crit leading-snug">{{ errFor(sumPath(j, 'key')) }}</p>
                </div>
                <input v-model="c.label_uz" class="field-input h-9" :placeholder="t('ws.label')"
                       :class="errFor(sumPath(j, 'label_uz')) && 'field-input-error'" @input="autoKey(c)">
                <select v-model="c.element_id" class="field-input h-9" :title="t('ws.element')">
                  <option :value="null">{{ t('ws.noElement') }}</option>
                  <option v-for="e in elements" :key="e.id" :value="e.id">{{ e.symbol ? `${e.symbol} · ` : '' }}{{ localized(e, 'name') }}</option>
                </select>
                <div class="flex">
                  <button class="btn-ghost btn-icon h-9 w-8" @click="toggleLangs(`s${j}`)"><Languages :size="15" /></button>
                  <button class="btn-ghost btn-icon h-9 w-8" @click="move(sumCols, j, -1)"><ChevronUp :size="15" /></button>
                  <button class="btn-ghost btn-icon h-9 w-8" @click="move(sumCols, j, 1)"><ChevronDown :size="15" /></button>
                  <button class="btn-ghost btn-icon h-9 w-8 text-crit" @click="sumCols.splice(j, 1)"><Trash2 :size="15" /></button>
                </div>
              </div>
              <div class="mt-2 grid gap-2 grid-cols-[minmax(0,3fr)_minmax(0,0.8fr)_70px_minmax(0,1.4fr)]">
                <div>
                  <div class="relative">
                    <Sigma :size="15" class="absolute left-2.5 top-1/2 -translate-y-1/2 text-ok" />
                    <input
                      :ref="(el) => (formulaEls[`s${j}`] = el)"
                      v-model="c.formula" class="field-input h-9 pl-8 font-mono text-[13px]" placeholder="avg(au_gt)"
                      :class="errFor(sumPath(j, 'formula')) && 'field-input-error'"
                      @focus="focused = `s${j}`"
                    >
                  </div>
                  <p v-if="errFor(sumPath(j, 'formula'))" class="mt-0.5 text-[11.5px] text-crit">{{ errFor(sumPath(j, 'formula')) }}</p>
                  <div v-if="focused === `s${j}`" class="mt-1.5 flex flex-wrap items-center gap-1 text-[11.5px]">
                    <span class="text-ink-faint mr-0.5">{{ t('ws.available') }}</span>
                    <button v-for="k in tokensFor('summary', c.key)" :key="k" class="rounded bg-surface-sunken px-1.5 py-0.5 font-mono text-ink hover:bg-assay-100 dark:hover:bg-assay-800/40" @mousedown.prevent="insertToken(`s${j}`, c, k)">{{ k }}</button>
                    <span class="text-ink-faint mx-0.5">{{ t('ws.functions') }}</span>
                    <button v-for="f in SHOWN_FUNCS" :key="f" class="rounded bg-surface-sunken px-1.5 py-0.5 font-mono text-assay-700 dark:text-assay-300 hover:bg-assay-100 dark:hover:bg-assay-800/40" @mousedown.prevent="insertToken(`s${j}`, c, f)">{{ f }}()</button>
                  </div>
                </div>
                <input v-model="c.unit" class="field-input h-9" :placeholder="t('ws.unit')">
                <input v-model.number="c.decimals" type="number" min="0" max="10" class="field-input h-9 tnum" :title="t('ws.decimals')">
                <select v-model="c.rounding" class="field-input h-9">
                  <option value="round">{{ t('ws.roundRound') }}</option>
                  <option value="trunc">{{ t('ws.roundTrunc') }}</option>
                  <option value="none">{{ t('ws.roundNone') }}</option>
                </select>
              </div>
              <div class="mt-2 grid gap-2 grid-cols-[minmax(0,1fr)_minmax(0,1.4fr)_minmax(0,1.4fr)] items-start">
                <div>
                  <input v-model="c.tolerance_pct" class="field-input h-9 tnum" :placeholder="t('ws.tolerancePct')"
                         :class="errFor(sumPath(j, 'tolerance_pct')) && 'field-input-error'">
                  <p v-if="errFor(sumPath(j, 'tolerance_pct'))" class="mt-0.5 text-[11.5px] text-crit">{{ errFor(sumPath(j, 'tolerance_pct')) }}</p>
                </div>
                <div>
                  <select v-model="c.tolerance_source" class="field-input h-9" :disabled="!c.tolerance_pct"
                          :class="errFor(sumPath(j, 'tolerance_source')) && 'field-input-error'">
                    <option value="">{{ t('ws.toleranceSource') }}…</option>
                    <option v-for="k in numericRowKeys" :key="k" :value="k">{{ k }}</option>
                  </select>
                  <p v-if="errFor(sumPath(j, 'tolerance_source'))" class="mt-0.5 text-[11.5px] text-crit">{{ errFor(sumPath(j, 'tolerance_source')) }}</p>
                </div>
                <select v-model="c.tolerance_mode" class="field-input h-9" :disabled="!c.tolerance_pct">
                  <option value="mean">{{ t('ws.modeMean') }}</option>
                  <option value="range">{{ t('ws.modeRange') }}</option>
                </select>
              </div>
              <div v-if="openLangs.has(`s${j}`)" class="mt-2 grid gap-2 sm:grid-cols-2">
                <input v-model="c.label_ru" class="field-input h-9" :placeholder="t('ws.labelRu')">
                <input v-model="c.label_uz_cyrl" class="field-input h-9" :placeholder="t('ws.labelCyrl')">
              </div>
            </div>
          </div>
        </section>

        <!-- ============ Varaq bo'yicha jami ============ -->
        <section class="panel p-4">
          <div class="flex items-center justify-between mb-1">
            <h2 class="text-[15px] font-semibold text-ink">{{ t('ws.secTotals') }}</h2>
            <button class="btn-ghost btn-sm" @click="totCols.push(blankCol({ scope: 'total', type: 'computed', decimals: 3 }))">
              <Plus :size="15" /> {{ t('ws.addTotal') }}
            </button>
          </div>
          <p class="mb-3 text-[12.5px] text-ink-muted">{{ t('ws.secTotalsHint') }}</p>
          <div v-if="!totCols.length" class="text-[13px] text-ink-faint">—</div>
          <div class="space-y-2.5">
            <div v-for="(c, k) in totCols" :key="k" class="rounded-md border border-line bg-surface-sunken/40 px-3 py-2.5">
              <div class="grid gap-2 items-start grid-cols-[minmax(0,1fr)_minmax(0,2fr)_auto]">
                <div>
                  <input v-model="c.key" class="field-input h-9 font-mono text-[13px]" :placeholder="t('ws.key')"
                         :class="errFor(totPath(k, 'key')) && 'field-input-error'" @input="c._keyTouched = true">
                  <p v-if="errFor(totPath(k, 'key'))" class="mt-0.5 text-[11.5px] text-crit leading-snug">{{ errFor(totPath(k, 'key')) }}</p>
                </div>
                <input v-model="c.label_uz" class="field-input h-9" :placeholder="t('ws.label')"
                       :class="errFor(totPath(k, 'label_uz')) && 'field-input-error'" @input="autoKey(c)">
                <div class="flex">
                  <button class="btn-ghost btn-icon h-9 w-8" @click="toggleLangs(`t${k}`)"><Languages :size="15" /></button>
                  <button class="btn-ghost btn-icon h-9 w-8" @click="move(totCols, k, -1)"><ChevronUp :size="15" /></button>
                  <button class="btn-ghost btn-icon h-9 w-8" @click="move(totCols, k, 1)"><ChevronDown :size="15" /></button>
                  <button class="btn-ghost btn-icon h-9 w-8 text-crit" @click="totCols.splice(k, 1)"><Trash2 :size="15" /></button>
                </div>
              </div>
              <div class="mt-2 grid gap-2 grid-cols-[minmax(0,3fr)_minmax(0,0.8fr)_70px_minmax(0,1.4fr)]">
                <div>
                  <div class="relative">
                    <Sigma :size="15" class="absolute left-2.5 top-1/2 -translate-y-1/2 text-ink-muted" />
                    <input
                      :ref="(el) => (formulaEls[`t${k}`] = el)"
                      v-model="c.formula" class="field-input h-9 pl-8 font-mono text-[13px]" placeholder="sum(kortochka)"
                      :class="errFor(totPath(k, 'formula')) && 'field-input-error'"
                      @focus="focused = `t${k}`"
                    >
                  </div>
                  <p v-if="errFor(totPath(k, 'formula'))" class="mt-0.5 text-[11.5px] text-crit">{{ errFor(totPath(k, 'formula')) }}</p>
                  <div v-if="focused === `t${k}`" class="mt-1.5 flex flex-wrap items-center gap-1 text-[11.5px]">
                    <span class="text-ink-faint mr-0.5">{{ t('ws.available') }}</span>
                    <button v-for="key in tokensFor('total', c.key)" :key="key" class="rounded bg-surface-sunken px-1.5 py-0.5 font-mono text-ink hover:bg-assay-100 dark:hover:bg-assay-800/40" @mousedown.prevent="insertToken(`t${k}`, c, key)">{{ key }}</button>
                    <span class="text-ink-faint mx-0.5">{{ t('ws.functions') }}</span>
                    <button v-for="f in SHOWN_FUNCS" :key="f" class="rounded bg-surface-sunken px-1.5 py-0.5 font-mono text-assay-700 dark:text-assay-300 hover:bg-assay-100 dark:hover:bg-assay-800/40" @mousedown.prevent="insertToken(`t${k}`, c, f)">{{ f }}()</button>
                  </div>
                </div>
                <input v-model="c.unit" class="field-input h-9" :placeholder="t('ws.unit')">
                <input v-model.number="c.decimals" type="number" min="0" max="10" class="field-input h-9 tnum" :title="t('ws.decimals')">
                <select v-model="c.rounding" class="field-input h-9">
                  <option value="round">{{ t('ws.roundRound') }}</option>
                  <option value="trunc">{{ t('ws.roundTrunc') }}</option>
                  <option value="none">{{ t('ws.roundNone') }}</option>
                </select>
              </div>
              <div v-if="openLangs.has(`t${k}`)" class="mt-2 grid gap-2 sm:grid-cols-2">
                <input v-model="c.label_ru" class="field-input h-9" :placeholder="t('ws.labelRu')">
                <input v-model="c.label_uz_cyrl" class="field-input h-9" :placeholder="t('ws.labelCyrl')">
              </div>
            </div>
          </div>
        </section>
      </div>

      <!-- ============ Sinab ko'rish ============ -->
      <aside class="panel p-4 self-start 2xl:sticky 2xl:top-20">
        <div class="flex items-center justify-between gap-2">
          <h2 class="text-[15px] font-semibold text-ink">{{ t('ws.secPreview') }}</h2>
          <button class="btn-ghost btn-sm" :disabled="!!errors.length" @click="checkOnServer"><ServerCog :size="15" /> {{ t('ws.previewServer') }}</button>
        </div>
        <p class="text-[12.5px] text-ink-muted mt-0.5 mb-3">{{ t('ws.previewHint') }}</p>
        <p v-if="serverCheck" class="mb-3 rounded-md px-2.5 py-1.5 text-[12.5px]" :class="serverCheck.ok ? 'bg-ok-light text-ok-dark dark:bg-ok-dark/25 dark:text-ok-light' : 'bg-crit-light text-crit-dark dark:bg-crit-dark/25 dark:text-crit-light'">
          {{ serverCheck.text }}
        </p>

        <div v-if="tpl.params.length" class="mb-3 flex flex-wrap gap-2">
          <label v-for="p in definition.params.filter((x) => x.key)" :key="p.key" class="flex items-center gap-1.5 text-[12.5px] text-ink-muted">
            <span class="font-mono">{{ p.key }}</span>
            <input v-model="preview.header[p.key]" class="field-input h-8 w-20 text-right tnum text-[12.5px]" :placeholder="p.default ?? ''">
          </label>
        </div>

        <div class="overflow-x-auto -mx-1">
          <table class="w-full text-[12px] border-collapse">
            <thead>
              <tr class="text-ink-muted">
                <th class="px-1 py-1 text-left font-medium">#</th>
                <th v-for="c in previewCols" :key="c.key" class="px-1 py-1 font-mono font-medium text-right whitespace-nowrap" :class="c.type === 'computed' && 'text-assay-600 dark:text-assay-300'">{{ c.key }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="(r, i) in preview.rows" :key="i" class="border-t border-line" :class="previewCalc.rows[i]?.out_of_tolerance?.length && 'bg-crit-light/60 dark:bg-crit-dark/15'">
                <td class="px-1 py-1 text-ink-faint tnum">{{ i + 1 }}</td>
                <td v-for="c in previewCols" :key="c.key" class="px-0.5 py-0.5">
                  <span v-if="c.type === 'computed'" class="block text-right tnum font-semibold text-ink px-1">{{ fmt(previewCalc.rows[i]?.computed?.[c.key], c.decimals, locale) }}</span>
                  <input v-else v-model="r.values[c.key]" class="w-full min-w-[56px] h-7 px-1 rounded border border-line bg-surface text-right tnum text-[12px] focus:outline-none focus:border-assay-500" :class="c.type === 'text' && 'text-left'">
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <div class="mt-2 flex gap-1.5">
          <button class="btn-ghost btn-sm" @click="preview.rows.push({ values: {}, excluded: false })"><Plus :size="14" /></button>
          <button class="btn-ghost btn-sm" @click="resetPreview">{{ t('common.resetFilters') }}</button>
        </div>

        <div class="mt-3 space-y-1.5">
          <div v-for="c in definition.columns.filter((x) => x.scope === 'summary' && x.key)" :key="c.key" class="flex items-center justify-between gap-2 rounded-md bg-surface-sunken px-2.5 py-1.5 text-[13px]">
            <span class="text-ink-muted truncate">{{ c.label_uz || c.key }}</span>
            <span class="flex items-center gap-2 shrink-0">
              <span v-if="previewCalc.tolerance[c.key]?.ok === false" class="text-[11.5px] text-crit tnum">±{{ fmt(previewCalc.tolerance[c.key].deviation, 2, locale) }}%</span>
              <span class="font-semibold tnum text-ink">{{ fmt(previewCalc.summary[c.key], c.decimals, locale) || '—' }} <span class="font-normal text-ink-muted">{{ c.unit }}</span></span>
            </span>
          </div>
        </div>
        <div v-if="totCols.length" class="mt-1.5 space-y-1.5">
          <div v-for="c in definition.columns.filter((x) => x.scope === 'total' && x.key)" :key="c.key" class="flex items-center justify-between gap-2 rounded-md border border-line px-2.5 py-1.5 text-[13px]">
            <span class="text-ink-muted truncate">Σ {{ c.label_uz || c.key }}</span>
            <span class="font-semibold tnum text-ink">{{ fmt(previewCalc.totals[c.key], c.decimals, locale) || '—' }} <span class="font-normal text-ink-muted">{{ c.unit }}</span></span>
          </div>
        </div>
        <div v-if="previewCalc.errors.length" class="mt-3 text-[12px] text-crit space-y-0.5">
          <p v-for="(e, i) in previewCalc.errors" :key="i"><span class="font-mono">{{ e.key }}</span>: {{ e.message }}</p>
        </div>
      </aside>
    </div>
  </div>
</template>
