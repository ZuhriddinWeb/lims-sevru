<script setup>
/**
 * Bitta ishchi varaq — qog'oz forma ko'rinishida (chop etish uchun).
 * WorksheetPrint.vue bir yoki bir nechta varaqni shu komponent bilan chizadi.
 */
import { computed } from 'vue'
import { calculate } from '../engine'
import { displayInput, fmt, printLabel, printName, sortedMembers } from '../helpers'

const props = defineProps({
  ws: { type: Object, required: true },
  lang: { type: String, default: 'uz' },        // uz | uz_cyrl | ru
  withValues: Boolean,
  spare: { type: Number, default: 0 },
})

// Qog'oz forma matnlari — chop etish tilida (interfeys tilidan mustaqil)
const PT = {
  uz: { sheet: 'Ishchi varaq', rowNo: '№ t/r', sampleNo: 'Namuna №', sample: 'Namuna', samples: 'Namunalar', count: 'ta', retest: 'takroriy', year: 'y.', footer: 'LIMS', ditto: '-//-', control: 'Nazorat uchun' },
  uz_cyrl: { sheet: 'Ишчи варақ', rowNo: '№ т/р', sampleNo: 'Намуна №', sample: 'Намуна', samples: 'Намуналар', count: 'та', retest: 'такрорий', year: 'й.', footer: 'LIMS', ditto: '-//-', control: 'Назорат учун' },
  ru: { sheet: 'Рабочий лист', rowNo: '№ п/п', sampleNo: 'Проба №', sample: 'Проба', samples: 'Пробы', count: 'шт.', retest: 'повтор', year: 'г.', footer: 'LIMS', ditto: '-//-', control: 'Контрольный' },
}

const ws = computed(() => props.ws)
const lang = computed(() => props.lang)
const withValues = computed(() => props.withValues)
const def = computed(() => ws.value?.template_snapshot ?? { columns: [], params: [], stages: [] })
const rowCols = computed(() => def.value.columns.filter((c) => (c.scope ?? 'row') === 'row'))
const sumCols = computed(() => def.value.columns.filter((c) => c.scope === 'summary'))
const totCols = computed(() => def.value.columns.filter((c) => c.scope === 'total'))

// Partiya: probalar varaqdagi tartibda; eski varaqlarda a'zolar bo'lmasa — asosiy proba
const members = computed(() => {
  const list = sortedMembers(ws.value)
  return list.length ? list : (ws.value?.sample ? [{ sample_id: Number(ws.value.sample_id), sample: ws.value.sample, status: 'active' }] : [])
})
const multi = computed(() => members.value.length > 1)
const memberOf = (sampleId) => members.value.find((m) => m.sample_id === Number(sampleId))
// Qator qaysi probaga tegishli (son ko'rinishida — SQL Server satr qaytarishi mumkin)
const sidOf = (r) => Number(r?.sample_id ?? ws.value?.sample_id)
const pt = computed(() => PT[lang.value] ?? PT.uz)
const landscape = computed(() => def.value.orientation !== 'portrait')

const calc = computed(() => calculate(
  def.value,
  ws.value?.header_values ?? {},
  (ws.value?.rows ?? []).map((r) => ({ values: r.inputs ?? {}, excluded: r.excluded, group: sidOf(r) })),
))
const groupSummary = (sampleId) =>
  calc.value.groups.find((g) => String(g.key) === String(sampleId ?? ws.value?.sample_id))?.summary ?? {}

// Qatorlar: har probaning birinchi paralleli raqam bilan, keyingilari «-//-»; oxirida zaxira bo'sh qatorlar
const printRows = computed(() => {
  const src = ws.value?.rows ?? []
  const rows = src.map((r, i) => {
    const sid = sidOf(r)
    const first = i === 0 || sidOf(src[i - 1]) !== sid
    let span = 1
    while (first && i + span < src.length && sidOf(src[i + span]) === sid) span++
    return { ...r, idx: i, spare: false, sid, first, span: first ? span : 0, groupStart: first && i > 0 }
  })
  for (let k = 0; k < props.spare; k++) rows.push({ idx: -1, spare: true, inputs: {}, first: true, span: 1, groupStart: k === 0 && rows.length > 0 })
  return rows
})

const sampleNumberOf = (row) => memberOf(row.sid)?.sample?.sample_number ?? ws.value?.sample?.sample_number ?? ''
function summaryCell(row, c) {
  if (row.spare || !props.withValues) return ''
  if (memberOf(row.sid)?.status === 'retest') return PT[lang.value]?.retest ?? ''
  return fmt(groupSummary(row.sid)[c.key], c.decimals, lang.value)
}

// Qatorlar ko'p bo'lsa — varaq bitta A4 ga sig'ishi uchun qator balandligi kichrayadi
const density = computed(() => {
  const n = printRows.value.length
  const [d1, d2] = landscape.value ? [9, 12] : [16, 21]
  return n > d2 ? 'ws-xdense' : n > d1 ? 'ws-dense' : ''
})

function cell(row, c) {
  if (row.spare) return ''
  if (c.type === 'computed') {
    return withValues.value ? fmt(calc.value.rows[row.idx]?.computed?.[c.key], c.decimals, lang.value) : ''
  }
  const raw = row.inputs?.[c.key]
  if (raw === undefined || raw === null || raw === '') return ''
  const isDefault = c.default_value !== null && c.default_value !== undefined && String(c.default_value) === String(raw)
  if (!withValues.value && !isDefault) return ''
  return c.type === 'input' ? displayInput(raw, lang.value) : raw
}

const dateParts = computed(() => {
  const d = ws.value?.worksheet_date
  if (!d) return { dd: '', mm: '', yyyy: '' }
  const [yyyy, mm, dd] = String(d).slice(0, 10).split('-')
  return { dd, mm, yyyy }
})

function headerValue(p) {
  const v = ws.value?.header_values?.[p.key]
  return withValues.value && v !== null && v !== undefined && v !== '' ? displayInput(v, lang.value) : ''
}

function signerName(stage, slot) {
  if (!withValues.value) return ''
  const s = (ws.value?.signoffs ?? []).find((x) => x.stage === stage && x.slot === slot)
  return s ? (s.performer?.name || s.performer_name || '') : ''
}

const signLines = computed(() => {
  const out = []
  for (const s of def.value.stages ?? []) {
    const n = Math.max(0, s.signers ?? 1)
    for (let slot = 1; slot <= n; slot++) {
      out.push({ key: `${s.key}#${slot}`, label: printLabel(s, lang.value), slot, multi: n > 1, name: signerName(s.key, slot) })
    }
  }
  return out
})
</script>

<template>
  <article
    class="ws-sheet mx-auto bg-white text-black shadow-pop print:shadow-none"
    :class="landscape ? 'ws-landscape ws-page-landscape' : 'ws-portrait ws-page-portrait'"
  >
    <!-- Sarlavha -->
    <header class="flex items-start justify-between gap-6">
      <div class="w-1/4 text-[9pt] leading-tight">
        <p v-for="p in def.params" :key="p.key" class="whitespace-nowrap">
          {{ printLabel(p, lang) }}:
          <span class="ws-fill">{{ headerValue(p) }}</span> {{ p.unit }}
        </p>
      </div>
      <div class="flex-1 text-center">
        <p class="text-[15pt] font-bold leading-tight">
          № <span class="ws-fill ws-fill-num">{{ ws.number }}</span> {{ pt.sheet }}
        </p>
        <p class="text-[11.5pt] font-semibold mt-0.5">{{ printName(def, lang) }}</p>
        <p v-if="ws.kind === 'control'" class="ws-control-mark inline-block mt-1 px-2 py-px border border-black text-[9.5pt] font-bold uppercase tracking-wider">{{ pt.control }}</p>
      </div>
      <div class="w-1/4 text-right text-[11pt] whitespace-nowrap pt-1">
        «<span class="ws-fill ws-fill-sm">{{ dateParts.dd }}</span>»
        <span class="ws-fill ws-fill-sm">{{ dateParts.mm }}</span>
        <span class="ws-fill ws-fill-md">{{ dateParts.yyyy }}</span> {{ pt.year }}
      </div>
    </header>

    <p v-if="multi" class="mt-2 text-[10pt]">
      {{ pt.samples }} ({{ members.length }} {{ pt.count }}):
      <b>{{ members.map((m) => m.sample?.sample_number).join(', ') }}</b>
    </p>
    <p v-else class="mt-2 text-[10pt]">
      {{ pt.sample }}: <b>{{ members[0]?.sample?.sample_number }}</b>
      <span class="text-[8.5pt]"> ({{ members[0]?.sample?.code }})</span>
    </p>

    <!-- Jadval -->
    <table class="ws-table mt-2 w-full" :class="density">
      <thead>
        <tr>
          <th class="w-[9mm]">{{ pt.rowNo }}</th>
          <th class="w-[18mm]">{{ pt.sampleNo }}</th>
          <th v-for="c in rowCols" :key="c.key">{{ printLabel(c, lang) }}</th>
          <!-- Partiya varaqda yakuniy natija — har probaga bitta katak (qog'ozdagi «O'rtacha qiymati» ustuni) -->
          <template v-if="multi">
            <th v-for="c in sumCols" :key="`s-${c.key}`">{{ printLabel(c, lang) }}</th>
          </template>
        </tr>
      </thead>
      <tbody>
        <tr
          v-for="(row, ri) in printRows" :key="ri"
          :class="[row.excluded && withValues && 'ws-excluded', multi && row.groupStart && 'ws-group-start']"
        >
          <td class="text-center">{{ ri + 1 }}</td>
          <td class="text-center">
            <template v-if="!row.spare">{{ row.first ? sampleNumberOf(row) : pt.ditto }}</template>
          </td>
          <td v-for="c in rowCols" :key="c.key" :class="c.type === 'text' ? 'text-left' : 'text-right'">
            {{ cell(row, c) }}
          </td>
          <template v-if="multi && row.span">
            <td v-for="c in sumCols" :key="`s-${c.key}`" :rowspan="row.span" class="text-center ws-summary-cell">
              {{ summaryCell(row, c) }}
            </td>
          </template>
        </tr>
      </tbody>
    </table>

    <!-- Yakuniy natijalar (bitta probali varaq) va varaq bo'yicha jami -->
    <div v-if="(!multi && sumCols.length) || totCols.length" class="mt-3 flex flex-wrap gap-x-10 gap-y-1.5 text-[10.5pt]">
      <template v-if="!multi">
        <p v-for="c in sumCols" :key="c.key" class="whitespace-nowrap">
          {{ printLabel(c, lang) }}:
          <span class="ws-fill ws-fill-lg font-semibold">{{ withValues ? fmt(groupSummary(members[0]?.sample_id)[c.key], c.decimals, lang) : '' }}</span>
        </p>
      </template>
      <p v-for="c in totCols" :key="c.key" class="whitespace-nowrap">
        {{ printLabel(c, lang) }}:
        <span class="ws-fill ws-fill-lg font-semibold">{{ withValues ? fmt(calc.totals[c.key], c.decimals, lang) : '' }}</span>
      </p>
    </div>

    <!-- Imzolar -->
    <div class="mt-5 grid grid-cols-2 gap-x-12 gap-y-3 text-[10pt]">
      <p v-for="s in signLines" :key="s.key" class="flex items-end gap-2">
        <span class="whitespace-nowrap">{{ s.label }}<template v-if="s.multi"> ({{ s.slot }})</template></span>
        <span class="ws-sign flex-1">{{ s.name }}</span>
      </p>
    </div>

    <footer class="mt-5 flex items-end justify-between text-[8pt]">
      <span>{{ pt.footer }} · № {{ ws.number }} · {{ multi ? `${members.length} ${pt.count}` : members[0]?.sample?.code }}</span>
      <span class="font-semibold text-[9pt]">{{ def.code }}</span>
    </footer>
  </article>
</template>

<style scoped>
.ws-sheet {
  font-family: 'Times New Roman', 'Liberation Serif', serif;
  padding: 10mm 11mm;
  box-sizing: border-box;
}
.ws-landscape { width: 297mm; min-height: 210mm; }
.ws-portrait { width: 210mm; min-height: 297mm; }

.ws-table { border-collapse: collapse; table-layout: auto; font-size: 10pt; }
.ws-table th,
.ws-table td { border: 0.6pt solid #000; padding: 1mm 1.4mm; }
.ws-table th { font-weight: 600; font-size: 8.5pt; line-height: 1.15; text-align: center; vertical-align: middle; }
.ws-table td { height: 8.5mm; font-variant-numeric: tabular-nums; }
.ws-excluded td { color: #777; text-decoration: line-through; }
.ws-table tr { break-inside: avoid; page-break-inside: avoid; }
.ws-dense td { height: 7mm; padding-top: 0.6mm; padding-bottom: 0.6mm; }
.ws-xdense td { height: 5.8mm; padding-top: 0.4mm; padding-bottom: 0.4mm; font-size: 9.5pt; }
.ws-group-start td { border-top-width: 1.4pt; }
.ws-table td.ws-summary-cell { font-weight: 600; vertical-align: middle; }

.ws-fill {
  display: inline-block;
  min-width: 22mm;
  border-bottom: 0.6pt solid #000;
  text-align: center;
  line-height: 1.1;
}
.ws-fill-num { min-width: 26mm; }
.ws-fill-sm { min-width: 8mm; }
.ws-fill-md { min-width: 14mm; }
.ws-fill-lg { min-width: 30mm; }
.ws-sign { border-bottom: 0.6pt solid #000; min-height: 5mm; padding-left: 2mm; font-style: italic; }

@media print {
  .ws-sheet { width: auto; min-height: 0; padding: 0; }
}
</style>
