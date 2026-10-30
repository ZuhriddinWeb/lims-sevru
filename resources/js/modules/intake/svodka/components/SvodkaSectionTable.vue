<script setup>
/**
 * Svodkaning bitta bloki (jadval). [Paket 29]
 *   shift — Excel'dagi asosiy jadval: № | Namuna nomi | I-navbatchilik (Raqam + ko'rsatkichlar) | II-navbatchilik | O'rtacha
 *   day   — sutkalik: № | Namuna nomi | Raqam | ko'rsatkichlar
 *   list  — ro'yxat:  № | Namuna nomi | Navbatchilik | Raqam | ko'rsatkichlar
 * Qiymatlar — faqat laboratoriya boshlig'i chiqargan nusxadan (server shunday beradi).
 * Chiqarilmagan namuna shifrini bosib nuqtasini o'zgartirish mumkin (canWrite).
 */
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { formatValue } from '../../helpers'
import '../i18n'

const props = defineProps({
  section: { type: Object, required: true },
  settings: { type: Object, required: true },
  canWrite: { type: Boolean, default: false },
})
const emit = defineEmits(['assign'])
const { t, locale } = useI18n()

const cols = computed(() => props.section.columns ?? [])
const n = computed(() => Math.max(1, cols.value.length))
const val = (v) => (v === null || v === undefined ? '' : formatValue(v, null, locale.value))
const hours = (s) => props.settings[`shift${s}_hours`]

function clickable(c) {
  return props.canWrite && !!c.sample_id && !c.released && !c.pending
}
function codeTitle(c) {
  if (c.pending) return t('svodka.day.pendingHint')
  if (c.released) return t('svodka.day.releasedHint')
  if (clickable(c)) return t('svodka.day.reassignHint')
  return null
}
function assign(c, row, shift) {
  emit('assign', { sample_id: c.sample_id, code: c.code, shift, point_id: row.point_id })
}

const TH = 'border border-line px-2 py-1.5 text-[12px] font-semibold text-ink-muted text-center align-middle'
const TD = 'border border-line px-2 py-1.5 align-middle'
const STICKY1 = 'sticky left-0 z-[1] bg-surface'
const STICKY2 = 'sticky left-[40px] z-[1] bg-surface'
const GROUP = {
  1: 'bg-aqua-50/70 dark:bg-aqua-800/20',
  2: 'bg-assay-50/70 dark:bg-assay-800/20',
  avg: 'bg-surface-sunken',
}
</script>

<template>
  <div class="overflow-x-auto">
    <!-- Navbatchilik bo'yicha (asosiy) -->
    <table v-if="section.layout === 'shift'" class="w-full border-collapse text-[13px]" data-layout="shift">
      <thead>
        <tr>
          <th rowspan="2" :class="[TH, STICKY1, 'w-[40px] min-w-[40px] bg-surface-sunken']">№</th>
          <th rowspan="2" :class="[TH, STICKY2, 'min-w-[170px] text-left bg-surface-sunken']">{{ t('svodka.day.pointName') }}</th>
          <th v-for="s in [1, 2]" :key="s" :colspan="n + 1" :class="[TH, GROUP[s], 'text-ink']" :data-shift-head="s">
            {{ settings[`shift${s}_label`] }} <span class="font-normal text-ink-muted tnum">{{ hours(s) }}</span>
          </th>
          <th :colspan="n" :class="[TH, GROUP.avg, 'text-ink']">{{ t('svodka.day.average') }}</th>
        </tr>
        <tr>
          <template v-for="s in [1, 2]" :key="s">
            <th :class="[TH, GROUP[s], 'min-w-[70px]']">{{ t('svodka.day.number') }}</th>
            <th v-for="c in cols" :key="c.element_id" :class="[TH, GROUP[s], 'min-w-[64px] whitespace-nowrap']">{{ c.label }}</th>
          </template>
          <th v-for="c in cols" :key="c.element_id" :class="[TH, GROUP.avg, 'min-w-[64px] whitespace-nowrap']">{{ c.label }}</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="(row, i) in section.rows" :key="row.point_id" :data-point="row.point_id">
          <td :class="[TD, STICKY1, 'text-center text-ink-faint tnum']">{{ i + 1 }}</td>
          <td :class="[TD, STICKY2, 'font-medium', row.active === false ? 'text-ink-faint' : 'text-ink']">
            {{ row.name }}
            <span v-if="row.active === false" class="ml-1 text-[11px] font-normal">({{ t('svodka.points.inactive') }})</span>
          </td>
          <template v-for="s in [1, 2]" :key="s">
            <td :class="[TD, 'text-center', row.daily && s === 1 && !row.shifts[s].codes.length && 'bg-surface-sunken/60']" :data-codes="s">
              <span class="inline-flex flex-wrap justify-center gap-1">
                <template v-for="(c, k) in row.shifts[s].codes" :key="k">
                  <button
                    v-if="clickable(c)" type="button"
                    class="font-mono text-[12.5px] text-assay-700 dark:text-assay-200 underline decoration-dotted underline-offset-2 hover:decoration-solid"
                    :title="codeTitle(c)" data-code-btn @click="assign(c, row, s)"
                  >{{ c.code }}</button>
                  <span v-else class="font-mono text-[12.5px]" :class="c.pending ? 'text-ink-faint' : 'text-ink'" :title="codeTitle(c)">{{ c.code }}<sup v-if="c.pending">*</sup></span>
                </template>
              </span>
            </td>
            <td
              v-for="c in cols" :key="c.element_id"
              :class="[TD, 'text-right tnum', row.daily && s === 1 && !row.shifts[s].codes.length && 'bg-surface-sunken/60']"
              :data-val="`${s}-${c.element_id}`"
            >{{ val(row.shifts[s].values?.[c.element_id]) }}</td>
          </template>
          <td v-for="c in cols" :key="c.element_id" :class="[TD, GROUP.avg, 'text-right tnum font-semibold text-ink']" :data-avg="c.element_id">
            {{ val(row.avg?.[c.element_id]) }}
          </td>
        </tr>
        <tr v-if="!section.rows.length">
          <td :colspan="2 + (n + 1) * 2 + n" :class="[TD, 'text-center text-ink-faint py-4']">{{ t('svodka.day.noPoints') }}</td>
        </tr>
      </tbody>
    </table>

    <!-- Sutkalik -->
    <table v-else-if="section.layout === 'day'" class="w-full border-collapse text-[13px]" data-layout="day">
      <thead>
        <tr class="bg-surface-sunken">
          <th :class="[TH, 'w-[40px]']">№</th>
          <th :class="[TH, 'text-left min-w-[170px]']">{{ t('svodka.day.pointName') }}</th>
          <th :class="[TH, 'min-w-[80px]']">{{ t('svodka.day.number') }}</th>
          <th v-for="c in cols" :key="c.element_id" :class="[TH, 'min-w-[70px] whitespace-nowrap']">{{ c.label }}</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="(row, i) in section.rows" :key="row.point_id" :data-point="row.point_id">
          <td :class="[TD, 'text-center text-ink-faint tnum']">{{ i + 1 }}</td>
          <td :class="[TD, 'font-medium', row.active === false ? 'text-ink-faint' : 'text-ink']">{{ row.name }}</td>
          <td :class="[TD, 'text-center']">
            <span class="inline-flex flex-wrap justify-center gap-1">
              <template v-for="(c, k) in row.codes" :key="k">
                <button
                  v-if="clickable(c)" type="button"
                  class="font-mono text-[12.5px] text-assay-700 dark:text-assay-200 underline decoration-dotted underline-offset-2"
                  :title="codeTitle(c)" data-code-btn @click="assign(c, row, null)"
                >{{ c.code }}</button>
                <span v-else class="font-mono text-[12.5px]" :class="c.pending ? 'text-ink-faint' : 'text-ink'" :title="codeTitle(c)">{{ c.code }}<sup v-if="c.pending">*</sup></span>
              </template>
            </span>
          </td>
          <td v-for="c in cols" :key="c.element_id" :class="[TD, 'text-right tnum']">{{ val(row.values?.[c.element_id]) }}</td>
        </tr>
        <tr v-if="!section.rows.length">
          <td :colspan="3 + cols.length" :class="[TD, 'text-center text-ink-faint py-4']">{{ t('svodka.day.noPoints') }}</td>
        </tr>
      </tbody>
    </table>

    <!-- Ro'yxat -->
    <table v-else class="w-full border-collapse text-[13px]" data-layout="list">
      <thead>
        <tr class="bg-surface-sunken">
          <th :class="[TH, 'w-[40px]']">№</th>
          <th :class="[TH, 'text-left min-w-[170px]']">{{ t('svodka.day.pointName') }}</th>
          <th :class="[TH, 'min-w-[110px]']">{{ t('svodka.day.shift') }}</th>
          <th :class="[TH, 'min-w-[80px]']">{{ t('svodka.day.number') }}</th>
          <th v-for="c in cols" :key="c.element_id" :class="[TH, 'min-w-[70px] whitespace-nowrap']">{{ c.label }}</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="(row, i) in section.rows" :key="i" :data-list-row="i">
          <td :class="[TD, 'text-center text-ink-faint tnum']">{{ i + 1 }}</td>
          <td :class="[TD, 'font-medium text-ink']">{{ row.name }}</td>
          <td :class="[TD, 'text-center text-ink-muted']">{{ row.shift ? settings[`shift${row.shift}_label`] : '' }}</td>
          <td :class="[TD, 'text-center']">
            <button
              v-if="clickable(row)" type="button"
              class="font-mono text-[12.5px] text-assay-700 dark:text-assay-200 underline decoration-dotted underline-offset-2"
              :title="codeTitle(row)" data-code-btn @click="assign(row, row, row.shift)"
            >{{ row.code }}</button>
            <span v-else class="font-mono text-[12.5px]" :class="row.pending ? 'text-ink-faint' : 'text-ink'" :title="codeTitle(row)">{{ row.code }}<sup v-if="row.pending">*</sup></span>
          </td>
          <td v-for="c in cols" :key="c.element_id" :class="[TD, 'text-right tnum']">{{ val(row.values?.[c.element_id]) }}</td>
        </tr>
        <tr v-if="!section.rows.length">
          <td :colspan="4 + cols.length" :class="[TD, 'text-center text-ink-faint py-4']">{{ t('svodka.day.noSamples') }}</td>
        </tr>
      </tbody>
    </table>
  </div>
</template>
