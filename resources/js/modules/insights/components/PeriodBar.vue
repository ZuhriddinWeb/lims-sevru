<script setup>
/**
 * Davr va filtrlar qatori: Joriy smena · Oldingi smena · Bugun · Kecha · Hafta · Oy · Yil · Oraliq
 * + laboratoriya / guruh / buyurtmachi bo'lim.
 *   <PeriodBar v-model="filters" :shifts="shifts" :labs="labs" :groups="groups" :departments="departments" />
 */
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { CalendarDays, Clock, RotateCcw } from 'lucide-vue-next'
import { localized } from '@/i18n'

const props = defineProps({
  modelValue: { type: Object, required: true },
  shifts: { type: Array, default: () => [] },
  labs: { type: Array, default: () => [] },
  groups: { type: Array, default: () => [] },
  departments: { type: Array, default: () => [] },
})
const emit = defineEmits(['update:modelValue'])
const { t } = useI18n()

const SHIFT = ['shift', 'prev_shift']
const DAYS = ['today', 'yesterday', 'week', 'month', 'year', 'custom']
const hasShifts = computed(() => props.shifts.length > 0)

function set(patch) {
  emit('update:modelValue', { ...props.modelValue, ...patch })
}
function choose(p) {
  if (p === 'custom') {
    const today = new Date()
    const pad = (n) => String(n).padStart(2, '0')
    const iso = `${today.getFullYear()}-${pad(today.getMonth() + 1)}-${pad(today.getDate())}`
    set({ period: p, date_from: props.modelValue.date_from || iso, date_to: props.modelValue.date_to || iso })
  } else {
    set({ period: p, date_from: '', date_to: '' })
  }
}
function setFrom(v) {
  const to = props.modelValue.date_to && props.modelValue.date_to < v ? v : props.modelValue.date_to
  set({ date_from: v, date_to: to })
}
function setTo(v) {
  const from = props.modelValue.date_from && props.modelValue.date_from > v ? v : props.modelValue.date_from
  set({ date_to: v, date_from: from })
}
const filtered = computed(() => !!(props.modelValue.laboratory_id || props.modelValue.group_id || props.modelValue.customer_department_id))
const btn = (on) => (on ? 'bg-surface text-ink shadow-sm' : 'text-ink-muted hover:text-ink')
</script>

<template>
  <div class="panel p-3 flex flex-col gap-2.5" data-period-bar>
    <div class="flex flex-wrap items-center gap-2">
      <!-- Smena -->
      <div class="inline-flex rounded-md border border-line p-0.5 bg-surface-sunken/60" role="group" :aria-label="t('insights.period.label')">
        <button
          v-for="p in SHIFT" :key="p" type="button"
          class="px-2.5 h-8 rounded text-[13px] font-medium transition-colors inline-flex items-center gap-1 disabled:opacity-45 disabled:cursor-not-allowed"
          :class="btn(modelValue.period === p)" :disabled="!hasShifts" :title="hasShifts ? '' : t('insights.period.noShiftsHint')"
          :data-preset="p" @click="choose(p)"
        >
          <Clock v-if="p === 'shift'" :size="14" />{{ t(`insights.period.${p}`) }}
        </button>
      </div>
      <!-- Kun / hafta / oy / yil -->
      <div class="inline-flex flex-wrap rounded-md border border-line p-0.5 bg-surface-sunken/60" role="group">
        <button
          v-for="p in DAYS" :key="p" type="button"
          class="px-2.5 h-8 rounded text-[13px] font-medium transition-colors inline-flex items-center gap-1"
          :class="btn(modelValue.period === p)" :data-preset="p" @click="choose(p)"
        >
          <CalendarDays v-if="p === 'custom'" :size="14" />{{ t(`insights.period.${p}`) }}
        </button>
      </div>
      <div v-if="modelValue.period === 'custom'" class="flex flex-wrap items-center gap-1.5" data-dates>
        <label class="text-[12.5px] text-ink-muted">{{ t('insights.period.from') }}</label>
        <input :value="modelValue.date_from" type="date" class="field-input h-9 w-auto" data-f="from" @change="setFrom($event.target.value)">
        <label class="text-[12.5px] text-ink-muted">{{ t('insights.period.to') }}</label>
        <input :value="modelValue.date_to" type="date" class="field-input h-9 w-auto" :min="modelValue.date_from || undefined" data-f="to" @change="setTo($event.target.value)">
      </div>
    </div>

    <div class="flex flex-wrap items-center gap-2">
      <select v-if="labs.length > 1" :value="modelValue.laboratory_id" class="field-input h-9 w-auto min-w-[180px]" data-f="lab" @change="set({ laboratory_id: $event.target.value })">
        <option value="">{{ t('insights.filters.allLabs') }}</option>
        <option v-for="l in labs" :key="l.id" :value="String(l.id)">{{ localized(l, 'name') }}</option>
      </select>
      <select v-if="groups.length" :value="modelValue.group_id" class="field-input h-9 w-auto min-w-[180px]" data-f="group" @change="set({ group_id: $event.target.value })">
        <option value="">{{ t('insights.filters.allGroups') }}</option>
        <option v-for="g in groups" :key="g.id" :value="String(g.id)">{{ localized(g, 'name') }}</option>
      </select>
      <select v-if="departments.length" :value="modelValue.customer_department_id" class="field-input h-9 w-auto min-w-[200px]" data-f="department" @change="set({ customer_department_id: $event.target.value })">
        <option value="">{{ t('insights.filters.allDepartments') }}</option>
        <option v-for="d in departments" :key="d.id" :value="String(d.id)">{{ d.name }}<template v-if="d.code"> ({{ d.code }})</template></option>
      </select>
      <button v-if="filtered" type="button" class="btn-ghost btn-sm" data-reset @click="set({ laboratory_id: '', group_id: '', customer_department_id: '' })">
        <RotateCcw :size="14" /> {{ t('insights.filters.reset') }}
      </button>
    </div>
  </div>
</template>
