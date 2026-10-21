<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { Check, ChevronDown, X } from 'lucide-vue-next'
import { useCatalogStore } from '@/stores/catalogs'
import { localized } from '@/i18n'
import FormulaInput from './FormulaInput.vue' // [P25] kimyoviy belgi / o'lchov birligi konstruktori

const props = defineProps({
  field: { type: Object, required: true },
  modelValue: { default: null },
  error: { type: [String, Array], default: null },
  form: { type: Object, default: () => ({}) }, // boshqa maydonlarga bog'liqlik uchun
})
const emit = defineEmits(['update:modelValue'])

const { t, te, locale } = useI18n()
const catalogs = useCatalogStore()

const label = computed(() => props.field.labelKey ? t(props.field.labelKey) : props.field.label)
const hint = computed(() => props.field.hintKey ? t(props.field.hintKey) : props.field.hint)
const errorText = computed(() => Array.isArray(props.error) ? props.error[0] : props.error)

const value = computed({
  get: () => props.modelValue,
  set: (v) => emit('update:modelValue', v),
})

/* ---------- Variantlar: statik yoki katalogdan ---------- */
const remoteOptions = ref([])

async function loadSource() {
  if (!props.field.source) return
  remoteOptions.value = await catalogs.load(props.field.source)
}

onMounted(loadSource)
watch(() => props.field.source, loadSource)

const options = computed(() => {
  // Statik variantlar: [{ value, labelKey }]
  if (props.field.options) {
    return props.field.options.map((o) => ({
      value: o.value,
      label: o.labelKey && te(o.labelKey) ? t(o.labelKey) : (o.label ?? String(o.value)),
    }))
  }

  // Katalogdan: ko'p tilli nomni joriy tilga moslash
  let list = remoteOptions.value
  if (props.field.filterOptions) list = list.filter((o) => props.field.filterOptions(o, props.form))

  return list.map((o) => ({
    value: o.id ?? o.value,
    label: props.field.optionLabel
      ? props.field.optionLabel(o, locale.value)
      : (localized(o, props.field.optionBase ?? 'name') || o.name || o.title || `#${o.id}`),
    meta: o,
  }))
})

/* ---------- Ko'p tanlovli (multiselect) ---------- */
const multiOpen = ref(false)
const selected = computed(() => Array.isArray(value.value) ? value.value : [])

function toggleMulti(val) {
  const next = selected.value.includes(val)
    ? selected.value.filter((v) => v !== val)
    : [...selected.value, val]
  value.value = next
}

const selectedLabels = computed(() =>
  options.value.filter((o) => selected.value.includes(o.value))
)
</script>

<template>
  <div :class="field.col === 2 ? 'sm:col-span-2' : ''">
    <label v-if="field.type !== 'switch'" class="field-label" :for="`f-${field.key}`">
      {{ label }}
      <span v-if="field.required" class="text-crit">*</span>
      <span v-else-if="field.showOptional" class="text-ink-faint font-normal">({{ t('common.optional') }})</span>
    </label>

    <!-- Matn / raqam / sana / parol -->
    <input
      v-if="['text', 'number', 'date', 'datetime-local', 'password', 'email', 'tel'].includes(field.type)"
      :id="`f-${field.key}`"
      v-model="value"
      :type="field.type"
      class="field-input tnum"
      :class="{ 'field-input-error': errorText }"
      :placeholder="field.placeholderKey ? t(field.placeholderKey) : field.placeholder"
      :step="field.step"
      :min="field.min"
      :max="field.max"
      :disabled="field.disabled"
    />

    <!-- [P25] Kimyoviy belgi (SO4 → SO₄) yoki o'lchov birligi (dm3 → dm³) -->
    <FormulaInput
      v-else-if="field.type === 'formula' || field.type === 'unit'"
      :id="`f-${field.key}`"
      v-model="value"
      :mode="field.type"
      :error="!!errorText"
      :placeholder="field.placeholderKey ? t(field.placeholderKey) : field.placeholder"
      :disabled="field.disabled"
    />

    <!-- Ko'p qatorli matn -->
    <textarea
      v-else-if="field.type === 'textarea'"
      :id="`f-${field.key}`"
      v-model="value"
      class="field-input"
      :class="{ 'field-input-error': errorText }"
      :rows="field.rows ?? 3"
      :placeholder="field.placeholderKey ? t(field.placeholderKey) : field.placeholder"
    />

    <!-- Tanlov -->
    <div v-else-if="['select', 'relation'].includes(field.type)" class="relative">
      <select
        :id="`f-${field.key}`"
        v-model="value"
        class="field-input appearance-none pr-9"
        :class="{ 'field-input-error': errorText }"
        :disabled="field.disabled"
      >
        <option :value="null">{{ field.nullLabelKey ? t(field.nullLabelKey) : t('common.selectPlaceholder') }}</option>
        <option v-for="o in options" :key="o.value" :value="o.value">{{ o.label }}</option>
      </select>
      <ChevronDown :size="16" class="absolute right-3 top-1/2 -translate-y-1/2 text-ink-faint pointer-events-none" />
    </div>

    <!-- Ko'p tanlov (masalan: aniqlanadigan elementlar) -->
    <div v-else-if="field.type === 'multiselect'" class="relative">
      <button
        type="button"
        class="field-input flex items-center gap-2 text-left h-auto min-h-10 py-1.5 flex-wrap"
        :class="{ 'field-input-error': errorText }"
        @click="multiOpen = !multiOpen"
      >
        <template v-if="selectedLabels.length">
          <span
            v-for="s in selectedLabels" :key="s.value"
            class="inline-flex items-center gap-1 rounded bg-assay-50 dark:bg-assay-900/40 text-assay-700 dark:text-assay-200 px-2 py-0.5 text-[12.5px] font-medium"
          >
            {{ s.label }}
            <X :size="12" class="hover:text-crit" @click.stop="toggleMulti(s.value)" />
          </span>
        </template>
        <span v-else class="text-ink-faint">{{ t('common.selectPlaceholder') }}</span>
        <ChevronDown :size="16" class="ml-auto text-ink-faint shrink-0" />
      </button>

      <Transition
        enter-active-class="transition duration-150 ease-out"
        enter-from-class="opacity-0 -translate-y-1" enter-to-class="opacity-100 translate-y-0"
        leave-active-class="transition duration-100 ease-in"
        leave-from-class="opacity-100" leave-to-class="opacity-0"
      >
        <div
          v-if="multiOpen"
          class="absolute z-20 mt-1 w-full max-h-56 overflow-y-auto panel shadow-pop py-1"
        >
          <button
            v-for="o in options" :key="o.value"
            type="button"
            class="w-full flex items-center gap-2.5 px-3 py-2 text-[14px] text-left hover:bg-surface-sunken transition-colors"
            @click="toggleMulti(o.value)"
          >
            <span
              class="h-4 w-4 rounded border grid place-items-center shrink-0 transition-colors"
              :class="selected.includes(o.value) ? 'bg-assay-500 border-assay-500 text-white' : 'border-line'"
            >
              <Check v-if="selected.includes(o.value)" :size="11" :stroke-width="3" />
            </span>
            <span class="text-ink">{{ o.label }}</span>
            <span v-if="o.meta?.default_unit" class="ml-auto text-[12px] text-ink-faint">{{ o.meta.default_unit }}</span>
          </button>
        </div>
      </Transition>

      <div v-if="multiOpen" class="fixed inset-0 z-10" @click="multiOpen = false" />
    </div>

    <!-- Kalit (switch) -->
    <label v-else-if="field.type === 'switch'" class="flex items-center gap-3 cursor-pointer select-none py-1.5">
      <button
        type="button"
        role="switch"
        :aria-checked="!!value"
        class="relative h-6 w-10 rounded-full transition-colors shrink-0"
        :class="value ? 'bg-assay-500' : 'bg-line'"
        @click="value = !value"
      >
        <span
          class="absolute top-0.5 h-5 w-5 rounded-full bg-white shadow transition-transform"
          :class="value ? 'translate-x-[18px]' : 'translate-x-0.5'"
        />
      </button>
      <span class="text-[14px] text-ink">{{ label }}</span>
    </label>

    <p v-if="errorText" class="mt-1.5 text-[12.5px] text-crit">{{ errorText }}</p>
    <p v-else-if="hint" class="mt-1.5 text-[12.5px] text-ink-faint">{{ hint }}</p>
  </div>
</template>
