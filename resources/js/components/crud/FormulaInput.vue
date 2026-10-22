<script setup>
/**
 * Kimyoviy belgi / o'lchov birligi maydoni — konstruktor. [Paket 25]
 *   - yozish paytida avtomatik: SO4 → SO₄, Fe^3+ → Fe³⁺ (formula); dm3 → dm³ (unit)
 *   - Ω tugmasi: indekslar va maxsus belgilar paneli, kursor turgan joyga qo'yiladi
 *   - «Avto» — avtomatik aylantirishni o'chirish (masalan, OD600 kabi nom uchun), tanlov eslab qolinadi
 */
import { computed, nextTick, onBeforeUnmount, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { i18n } from '@/i18n'
import { PALETTE, convertWithMap } from '@/lib/chemtext'

const props = defineProps({
  modelValue: { type: [String, Number, null], default: '' },
  mode: { type: String, default: 'formula' }, // formula | unit
  id: { type: String, default: undefined },
  placeholder: { type: String, default: undefined },
  error: { type: Boolean, default: false },
  disabled: Boolean,
})
const emit = defineEmits(['update:modelValue'])

const messages = {
  uz: { chem: { palette: 'Belgilar', sub: 'Pastki indeks', sup: 'Yuqori indeks', sym: 'Belgilar', auto: 'Avto', autoHint: "Yozishda avtomatik aylantirish", hintFormula: "SO4 → SO₄ · Fe^3+ → Fe³⁺ · (NH4)2SO4 → (NH₄)₂SO₄", hintUnit: "dm3 → dm³ · 10^6 → 10⁶" } },
  ru: { chem: { palette: 'Символы', sub: 'Нижний индекс', sup: 'Верхний индекс', sym: 'Символы', auto: 'Авто', autoHint: 'Автопреобразование при вводе', hintFormula: 'SO4 → SO₄ · Fe^3+ → Fe³⁺ · (NH4)2SO4 → (NH₄)₂SO₄', hintUnit: 'dm3 → dm³ · 10^6 → 10⁶' } },
  en: { chem: { palette: 'Symbols', sub: 'Subscript', sup: 'Superscript', sym: 'Symbols', auto: 'Auto', autoHint: 'Convert while typing', hintFormula: 'SO4 → SO₄ · Fe^3+ → Fe³⁺ · (NH4)2SO4 → (NH₄)₂SO₄', hintUnit: 'dm3 → dm³ · 10^6 → 10⁶' } },
}
for (const [l, m] of Object.entries(messages)) i18n.global.mergeLocaleMessage(l, m)
const { t } = useI18n()

const KEY = 'chemtext.auto'
const readAuto = () => { try { return localStorage.getItem(KEY) !== '0' } catch { return true } }
const auto = ref(readAuto())
function toggleAuto() {
  auto.value = !auto.value
  try { localStorage.setItem(KEY, auto.value ? '1' : '0') } catch { /* xotira yopiq — shu oynada ishlaydi */ }
}

const input = ref(null)
const root = ref(null)
const open = ref(false)
const focused = ref(false)
const text = computed(() => (props.modelValue === null || props.modelValue === undefined ? '' : String(props.modelValue)))

function onInput(e) {
  const el = e.target
  let value = el.value
  if (auto.value) {
    const caret = el.selectionStart ?? value.length
    const res = convertWithMap(value, props.mode)
    if (res.text !== value) {
      value = res.text
      el.value = value
      const pos = res.map[caret] ?? value.length
      el.setSelectionRange(pos, pos)
    }
  }
  emit('update:modelValue', value)
}

function insert(ch) {
  const el = input.value
  const v = text.value
  const start = el?.selectionStart ?? v.length
  const end = el?.selectionEnd ?? v.length
  const next = v.slice(0, start) + ch + v.slice(end)
  emit('update:modelValue', next)
  nextTick(() => {
    el?.focus()
    el?.setSelectionRange(start + ch.length, start + ch.length)
  })
}

function onDoc(e) {
  if (open.value && root.value && !root.value.contains(e.target)) open.value = false
}
document.addEventListener('mousedown', onDoc)
onBeforeUnmount(() => document.removeEventListener('mousedown', onDoc))
</script>

<template>
  <div ref="root" class="relative">
    <input
      :id="id"
      ref="input"
      :value="text"
      type="text"
      class="field-input pr-11 font-mono"
      :class="{ 'field-input-error': error }"
      :placeholder="placeholder"
      :disabled="disabled"
      autocomplete="off"
      spellcheck="false"
      data-formula-input
      @input="onInput"
      @focus="focused = true"
      @blur="focused = false"
    >
    <button
      type="button"
      class="absolute right-1.5 top-1/2 -translate-y-1/2 h-7 w-8 grid place-items-center rounded text-ink-muted hover:bg-surface-sunken hover:text-ink"
      :class="open && 'bg-surface-sunken text-ink'"
      :title="t('chem.palette')"
      :aria-label="t('chem.palette')"
      :disabled="disabled"
      data-palette-toggle
      @mousedown.prevent
      @click="open = !open"
    >
      <span class="text-[15px] leading-none">Ω</span>
    </button>

    <p v-if="(focused || open) && auto" class="mt-1 text-[11.5px] text-ink-faint font-mono">
      {{ mode === 'unit' ? t('chem.hintUnit') : t('chem.hintFormula') }}
    </p>

    <div
      v-if="open"
      class="absolute right-0 z-30 mt-1 w-[288px] panel shadow-pop p-2.5 space-y-2"
      data-palette
      @mousedown.prevent
    >
      <div v-for="group in ['sub', 'sup', 'sym']" :key="group">
        <p class="mb-1 text-[11px] font-medium uppercase tracking-wide text-ink-faint">{{ t(`chem.${group}`) }}</p>
        <div class="grid grid-cols-8 gap-1">
          <button
            v-for="ch in PALETTE[group]" :key="ch" type="button"
            class="h-8 rounded border border-line text-[15px] text-ink hover:bg-assay-50 hover:border-assay-300 dark:hover:bg-assay-800/30"
            :data-char="ch"
            @click="insert(ch)"
          >{{ ch }}</button>
        </div>
      </div>
      <label class="flex items-center justify-between gap-2 pt-1.5 border-t border-line text-[12.5px] text-ink-muted cursor-pointer" :title="t('chem.autoHint')">
        <span>{{ t('chem.autoHint') }}</span>
        <button
          type="button" role="switch" :aria-checked="auto"
          class="relative h-5 w-9 rounded-full transition-colors shrink-0"
          :class="auto ? 'bg-assay-500' : 'bg-line'"
          data-auto
          @click="toggleAuto"
        >
          <span class="absolute left-0 top-0.5 h-4 w-4 rounded-full bg-white shadow transition-transform" :class="auto ? 'translate-x-[18px]' : 'translate-x-0.5'" />
        </button>
      </label>
    </div>
  </div>
</template>
