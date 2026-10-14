<script setup>
/**
 * Shablonga mos probalarni tanlash (yangi varaq yoki mavjud varaqqa qo'shish).
 *   <CandidatePicker v-model="selected" :template-id="id" :preselect="[12]" @meta="tplMeta = $event" />
 * v-model: [{ id, parallels, code, sample_number, quantity }] — ro'yxatga olinish tartibida.
 * kind="control" — nazorat varag'i uchun (natijasi chiqqan probalar ham, yangilari birinchi).
 * Proba qoldig'i ma'lum bo'lsa — har probada qoldiq va tanlangan parallellar uchun kerakli miqdor ko'rinadi.
 */
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { AlertTriangle, Check, Minus, Plus, RotateCcw, Scale, Search } from 'lucide-vue-next'
import api from '@/lib/api'
import { localized } from '@/i18n'
import { formatDate } from '@/lib/format'
import { MAX_PARALLELS, formatQty, pageEstimate } from '../helpers'
import '../i18n'

const props = defineProps({
  templateId: { type: [Number, String], required: true },
  exceptWorksheet: { type: [Number, String], default: null },
  preselect: { type: Array, default: () => [] },
  /** Varaqda allaqachon bor qatorlar (proba qo'shishda sahifa hisobi uchun) */
  existingRows: { type: Number, default: 0 },
  existingSamples: { type: Number, default: 0 },
  modelValue: { type: Array, default: () => [] },
  /** main | control */
  kind: { type: String, default: 'main' },
})
const emit = defineEmits(['update:modelValue', 'meta'])

const { t, locale } = useI18n()

const list = ref([])
const meta = ref({ default_rows: 2, max_samples: null, orientation: 'landscape' })
const loading = ref(false)
const filters = reactive({ search: '', date_from: '', date_to: '' })
const picked = reactive(new Map()) // id → { id, parallels, code, sample_number, registered_at }
const allValue = ref(2)
let timer = null
let preselected = false

async function load() {
  if (!props.templateId) return
  loading.value = true
  try {
    const { data } = await api.get('/worksheets/candidates', {
      params: {
        template_id: props.templateId,
        kind: props.kind === 'control' ? 'control' : undefined,
        except_worksheet: props.exceptWorksheet || undefined,
        search: filters.search || undefined,
        date_from: filters.date_from || undefined,
        date_to: filters.date_to || undefined,
      },
    })
    list.value = data.samples ?? []
    meta.value = data.template ?? meta.value
    allValue.value = meta.value.default_rows || 2
    emit('meta', meta.value)
    if (!preselected) {
      preselected = true
      for (const id of props.preselect) {
        const s = list.value.find((x) => Number(x.id) === Number(id))
        if (s) toggle(s)
      }
    }
  } catch {
    list.value = []
  } finally {
    loading.value = false
  }
}

onMounted(load)
watch(() => [props.templateId, props.kind], () => {
  picked.clear()
  preselected = false
  publish()
  load()
})
watch(() => [filters.search, filters.date_from, filters.date_to], () => {
  clearTimeout(timer)
  timer = setTimeout(load, 300)
})

function publish() {
  const out = [...picked.values()]
    .sort((a, b) => String(a.registered_at ?? '').localeCompare(String(b.registered_at ?? '')) || a.id - b.id)
    .map(({ id, parallels, code, sample_number, quantity }) => ({ id, parallels, code, sample_number, quantity }))
  emit('update:modelValue', out)
}

function toggle(s) {
  if (picked.has(s.id)) picked.delete(s.id)
  else picked.set(s.id, { id: s.id, code: s.code, sample_number: s.sample_number, registered_at: s.registered_at, parallels: meta.value.default_rows || 2, quantity: s.quantity ?? null })
  publish()
}

function setParallels(id, n) {
  const p = picked.get(id)
  if (!p) return
  p.parallels = Math.max(1, Math.min(MAX_PARALLELS, Number(n) || 1))
  publish()
}

function applyAll() {
  for (const p of picked.values()) p.parallels = Math.max(1, Math.min(MAX_PARALLELS, Number(allValue.value) || 1))
  publish()
}

const allVisibleChecked = computed(() => list.value.length > 0 && list.value.every((s) => picked.has(s.id)))
function toggleAll() {
  if (allVisibleChecked.value) list.value.forEach((s) => picked.delete(s.id))
  else list.value.forEach((s) => { if (!picked.has(s.id)) toggle(s) })
  publish()
}

const totalRows = computed(() => [...picked.values()].reduce((sum, p) => sum + p.parallels, 0) + props.existingRows)
const totalSamples = computed(() => picked.size + props.existingSamples)
const pages = computed(() => pageEstimate(totalRows.value, meta.value.orientation))
const overLimit = computed(() => meta.value.max_samples && totalSamples.value > meta.value.max_samples)

/* ---------- Proba qoldig'i ---------- */
// Tanlangan parallellar uchun kerakli miqdor (standart naveska × parallel), probaning birligida
function needOf(s) {
  const q = s.quantity
  const p = picked.get(s.id)
  if (!q || !p || q.per_parallel === null || q.per_parallel === undefined) return null
  return Math.round(q.per_parallel * p.parallels * 10000) / 10000
}
function isShort(s) {
  const need = needOf(s)
  return need !== null && s.quantity?.available !== null && s.quantity?.available !== undefined && need > s.quantity.available + 1e-9
}
const qtyTone = (q) => ({
  negative: 'text-crit', empty: 'text-crit', low: 'text-warn-dark dark:text-warn-light',
}[q?.state] ?? 'text-ink-muted')
</script>

<template>
  <div>
    <p class="text-[12.5px] text-ink-muted mb-3">{{ t('ws.candidatesHint') }}</p>

    <!-- Filtrlar -->
    <div class="flex flex-wrap items-center gap-2 mb-2">
      <div class="relative flex-1 min-w-[180px]">
        <Search :size="15" class="absolute left-3 top-1/2 -translate-y-1/2 text-ink-faint" />
        <input v-model="filters.search" class="field-input h-9 pl-9" :placeholder="t('ws.pickSampleHint')">
      </div>
      <input v-model="filters.date_from" type="date" class="field-input h-9 w-auto" :title="t('common.dateFrom')">
      <input v-model="filters.date_to" type="date" class="field-input h-9 w-auto" :title="t('common.dateTo')">
    </div>

    <div class="flex flex-wrap items-center justify-between gap-2 mb-2 text-[12.5px]">
      <button type="button" class="btn-ghost btn-sm" :disabled="!list.length" @click="toggleAll">
        <Check :size="14" /> {{ allVisibleChecked ? t('ws.clearSelection') : t('ws.selectAll') }}
      </button>
      <label v-if="picked.size" class="flex items-center gap-2 text-ink-muted">
        {{ t('ws.parallelsAll') }}
        <input v-model.number="allValue" type="number" min="1" :max="MAX_PARALLELS" class="field-input h-8 w-16 text-center tnum">
        <button type="button" class="btn-outline btn-sm" @click="applyAll">OK</button>
      </label>
    </div>

    <!-- Ro'yxat -->
    <div class="max-h-[46vh] overflow-y-auto rounded-md border border-line divide-y divide-line">
      <div v-if="loading && !list.length" class="p-3 space-y-2">
        <div v-for="i in 4" :key="i" class="h-9 rounded bg-surface-sunken animate-pulse" />
      </div>
      <p v-else-if="!list.length" class="px-3 py-6 text-center text-[13px] text-ink-faint">{{ t('ws.noCandidates') }}</p>

      <div
        v-for="s in list" :key="s.id"
        class="flex items-center gap-3 px-3 py-2 transition-colors"
        :class="picked.has(s.id) ? 'bg-assay-50/60 dark:bg-assay-800/15' : 'hover:bg-surface-sunken/60'"
      >
        <button
          type="button"
          class="h-[18px] w-[18px] shrink-0 rounded border grid place-items-center transition-colors"
          :class="picked.has(s.id) ? 'bg-assay-500 border-assay-500 text-white' : 'border-line bg-surface'"
          :aria-pressed="picked.has(s.id)"
          @click="toggle(s)"
        >
          <Check v-if="picked.has(s.id)" :size="12" :stroke-width="3" />
        </button>

        <button type="button" class="min-w-0 flex-1 text-left" @click="toggle(s)">
          <span class="font-mono text-[13.5px] font-semibold text-ink">{{ s.code }}</span>
          <span class="text-[13px] text-ink"> · № {{ s.sample_number }}</span>
          <span
            v-if="s.retest"
            class="ml-1.5 rounded-full bg-warn-light text-warn-dark dark:bg-warn-dark/25 dark:text-warn-light px-1.5 py-0.5 text-[10.5px] font-medium align-middle"
            :title="t('ws.retestFrom', { ws: s.retest.worksheet, reason: s.retest.reason || '—' })"
          ><RotateCcw :size="10" class="inline -mt-0.5" /> {{ t('ws.retestBadge') }}</span>
          <span class="block text-[12px] text-ink-muted truncate">
            {{ formatDate(s.registered_at) }}
            <template v-if="s.assigned_group"> · {{ localized(s.assigned_group, 'name') }}</template>
            <template v-if="s.results?.length"> · {{ s.results.map((r) => `${r.symbol} ${formatQty(r.value, locale)}`).join(', ') }}</template>
            <template v-else-if="s.elements?.length"> · {{ s.elements.join(', ') }}</template>
          </span>
          <span v-if="s.quantity" class="flex items-center gap-1 text-[11.5px] tnum" :class="isShort(s) ? 'text-crit font-medium' : qtyTone(s.quantity)" data-qty>
            <component :is="isShort(s) ? AlertTriangle : Scale" :size="12" class="shrink-0" />
            {{ t('ws.qtyLeft', { n: formatQty(s.quantity.available, locale), unit: s.quantity.unit }) }}
            <template v-if="needOf(s) !== null"> · {{ t('ws.qtyNeed', { n: formatQty(needOf(s), locale), unit: s.quantity.unit }) }}</template>
            <template v-if="isShort(s)"> — {{ t('ws.qtyShort') }}</template>
          </span>
        </button>

        <div v-if="picked.has(s.id)" class="flex items-center gap-1 shrink-0" :title="t('ws.parallels')">
          <button type="button" class="btn-ghost btn-icon h-7 w-7" :disabled="picked.get(s.id).parallels <= 1" @click="setParallels(s.id, picked.get(s.id).parallels - 1)">
            <Minus :size="14" />
          </button>
          <span class="w-6 text-center tnum font-semibold text-ink">{{ picked.get(s.id).parallels }}</span>
          <button type="button" class="btn-ghost btn-icon h-7 w-7" :disabled="picked.get(s.id).parallels >= MAX_PARALLELS" @click="setParallels(s.id, picked.get(s.id).parallels + 1)">
            <Plus :size="14" />
          </button>
        </div>
      </div>
    </div>

    <!-- Yig'indi -->
    <div class="mt-2 flex flex-wrap items-center justify-between gap-2 text-[12.5px]">
      <span class="text-ink">
        {{ t('ws.selectedInfo', { n: picked.size, rows: [...picked.values()].reduce((s, p) => s + p.parallels, 0) }) }}
        <span class="text-ink-faint"> · {{ t('ws.pageFit', { n: pages }) }}</span>
      </span>
      <span v-if="overLimit" class="text-crit font-medium">{{ t('ws.maxSamples', { n: meta.max_samples }) }}</span>
    </div>
  </div>
</template>
