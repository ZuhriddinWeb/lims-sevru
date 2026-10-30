<script setup>
/**
 * Spravochnik bloki: nomi, ko'rinishi va ko'rsatkich ustunlari (qaysi element natijasi qaysi ustunga). [Paket 29]
 */
import { computed, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { Plus, Trash2 } from 'lucide-vue-next'
import BaseModal from '@/components/ui/BaseModal.vue'
import { LAYOUTS } from '../shared'
import { nameOf } from '../../requests/shared'
import '../i18n'

const props = defineProps({
  modelValue: Boolean,
  section: { type: Object, default: null },     // null — yangi
  elements: { type: Array, default: () => [] },
  busy: Boolean,
  error: { type: String, default: '' },
})
const emit = defineEmits(['update:modelValue', 'save'])
const { t, locale } = useI18n()

const form = reactive({ title: '', layout: 'shift', columns: [] })
const localError = ref('')

watch(() => props.modelValue, (open) => {
  if (!open) return
  const s = props.section
  form.title = s?.title ?? ''
  form.layout = s?.layout ?? 'shift'
  form.columns = (s?.columns ?? []).map((c) => ({ element_id: c.element_id, label: c.label ?? '' }))
  if (!form.columns.length) form.columns.push({ element_id: '', label: '' })
  localError.value = ''
})

const byId = computed(() => Object.fromEntries(props.elements.map((e) => [e.id, e])))
function autoLabel(c) {
  const e = byId.value[c.element_id]
  return e ? `${e.symbol}${e.default_unit ? `,${e.default_unit}` : ''}` : ''
}
function addColumn() {
  form.columns.push({ element_id: '', label: '' })
}
function removeColumn(i) {
  form.columns.splice(i, 1)
}

function save() {
  const cols = form.columns.filter((c) => c.element_id)
  if (!form.title.trim()) {
    localError.value = t('svodka.section.titleRequired')
    return
  }
  if (!cols.length) {
    localError.value = t('svodka.section.columnsRequired')
    return
  }
  localError.value = ''
  emit('save', {
    title: form.title.trim(),
    layout: form.layout,
    columns: cols.map((c) => ({ element_id: Number(c.element_id), label: (c.label || '').trim() || null })),
  })
}
</script>

<template>
  <BaseModal
    :model-value="modelValue" size="md"
    :title="section ? t('svodka.section.editTitle') : t('svodka.section.newTitle')"
    @update:model-value="emit('update:modelValue', $event)"
  >
    <div class="space-y-5" data-section-dialog>
      <div>
        <label class="field-label">{{ t('svodka.section.title') }} *</label>
        <input v-model="form.title" class="field-input" maxlength="150" :placeholder="t('svodka.section.titleHint')" data-f="title">
      </div>

      <fieldset>
        <legend class="field-label">{{ t('svodka.section.layout') }}</legend>
        <div class="grid gap-2 sm:grid-cols-3">
          <label
            v-for="l in LAYOUTS" :key="l"
            class="flex cursor-pointer flex-col gap-1 rounded-md border px-3 py-2.5 transition-colors"
            :class="form.layout === l ? 'border-assay-500 bg-assay-50/60 dark:bg-assay-800/20' : 'border-line hover:border-ink-faint'"
            :data-layout-opt="l"
          >
            <span class="flex items-center gap-2 text-[13.5px] font-medium text-ink">
              <input v-model="form.layout" type="radio" :value="l" class="accent-assay-500">
              {{ t(`svodka.layouts.${l}`) }}
            </span>
            <span class="text-[12px] leading-snug text-ink-muted">{{ t(`svodka.layoutHints.${l}`) }}</span>
          </label>
        </div>
      </fieldset>

      <div>
        <p class="field-label">{{ t('svodka.section.columns') }} *</p>
        <p class="-mt-1 mb-2 text-[12px] text-ink-muted">{{ t('svodka.section.columnsHint') }}</p>
        <div class="space-y-2">
          <div v-for="(c, i) in form.columns" :key="i" class="flex items-center gap-2" :data-col="i">
            <select v-model="c.element_id" class="field-input h-9 flex-1 min-w-0" data-f="element">
              <option value="">{{ t('svodka.section.chooseElement') }}</option>
              <option v-for="e in elements" :key="e.id" :value="e.id">{{ e.symbol }}<template v-if="nameOf(e, locale)"> — {{ nameOf(e, locale) }}</template></option>
            </select>
            <input v-model="c.label" class="field-input h-9 w-[120px]" maxlength="40" :placeholder="autoLabel(c) || t('svodka.section.label')" :title="t('svodka.section.labelHint')" data-f="label">
            <button type="button" class="btn-ghost btn-icon h-9 w-9 hover:text-crit" :aria-label="t('svodka.remove')" @click="removeColumn(i)">
              <Trash2 :size="15" />
            </button>
          </div>
        </div>
        <button type="button" class="btn-ghost btn-sm mt-2" data-add-col @click="addColumn"><Plus :size="15" /> {{ t('svodka.section.addColumn') }}</button>
      </div>

      <p v-if="localError || error" class="text-[13px] text-crit" data-section-error>{{ localError || error }}</p>
    </div>
    <template #footer>
      <button class="btn-ghost" @click="emit('update:modelValue', false)">{{ t('svodka.cancel') }}</button>
      <button class="btn-primary" :disabled="busy" data-section-save @click="save">{{ t('svodka.save') }}</button>
    </template>
  </BaseModal>
</template>
