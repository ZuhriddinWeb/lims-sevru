<script setup>
/**
 * Davr bo'yicha saralash: Hammasi · Bugun · Shu hafta · Shu oy · Shu yil · Oraliq (sanadan — sanagacha).
 *   <PeriodFilter v-model:from="filters.date_from" v-model:to="filters.date_to" />
 * Sanalar YYYY-MM-DD ko'rinishida (kompyuterning mahalliy sanasi bo'yicha). Hafta dushanbadan boshlanadi.
 */
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { CalendarDays } from 'lucide-vue-next'
import { presetRange } from '../helpers'
import '../i18n'

const props = defineProps({
  from: { type: String, default: '' },
  to: { type: String, default: '' },
})
const emit = defineEmits(['update:from', 'update:to'])
const { t } = useI18n()

const PRESETS = ['all', 'today', 'week', 'month', 'year', 'custom']

// Joriy sanalar qaysi davrga mos kelishini aniqlash (sahifa qayta ochilganda ham to'g'ri tugma yonsin)
function detect(from, to) {
  if (!from && !to) return 'all'
  for (const p of ['today', 'week', 'month', 'year']) {
    const [f, tt] = presetRange(p)
    if (f === from && tt === to) return p
  }
  return 'custom'
}

const preset = ref(detect(props.from, props.to))
watch(() => [props.from, props.to], ([f, tt]) => {
  if (preset.value === 'custom') return
  // Tanlangan davr hali ham to'g'ri bo'lsa — o'zgartirmaymiz (masalan, dushanba kuni «bugun» = «shu hafta»)
  const [pf, pt] = presetRange(preset.value)
  const still = preset.value === 'all' ? !f && !tt : pf === f && pt === tt
  if (!still) preset.value = detect(f, tt)
})

function choose(p) {
  preset.value = p
  if (p === 'custom') return // sanalarni foydalanuvchi o'zi tanlaydi (joriylari saqlanadi)
  const [f, tt] = presetRange(p)
  emit('update:from', f)
  emit('update:to', tt)
}

const showDates = computed(() => preset.value === 'custom')

function setFrom(v) {
  emit('update:from', v)
  // «gacha» «dan» dan oldin bo'lib qolmasin
  if (v && props.to && props.to < v) emit('update:to', v)
}
function setTo(v) {
  emit('update:to', v)
  if (v && props.from && props.from > v) emit('update:from', v)
}
</script>

<template>
  <div class="flex flex-wrap items-center gap-2" data-period>
    <div class="inline-flex flex-wrap rounded-md border border-line p-0.5 bg-surface-sunken/60" role="group" :aria-label="t('intake.period.label')">
      <button
        v-for="p in PRESETS" :key="p" type="button"
        class="px-2.5 h-8 rounded text-[13px] font-medium transition-colors inline-flex items-center gap-1"
        :class="preset === p ? 'bg-surface text-ink shadow-sm' : 'text-ink-muted hover:text-ink'"
        :data-preset="p"
        @click="choose(p)"
      >
        <CalendarDays v-if="p === 'custom'" :size="14" />
        {{ t(`intake.period.${p}`) }}
      </button>
    </div>
    <div v-if="showDates" class="flex flex-wrap items-center gap-1.5" data-period-dates>
      <label class="text-[12.5px] text-ink-muted">{{ t('intake.period.from') }}</label>
      <input :value="from" type="date" class="field-input h-9 w-auto" data-f="from" @change="setFrom($event.target.value)">
      <label class="text-[12.5px] text-ink-muted">{{ t('intake.period.to') }}</label>
      <input :value="to" type="date" class="field-input h-9 w-auto" :min="from || undefined" data-f="to" @change="setTo($event.target.value)">
    </div>
  </div>
</template>
