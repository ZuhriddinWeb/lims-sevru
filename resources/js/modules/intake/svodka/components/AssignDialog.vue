<script setup>
/**
 * Namunani spravochnik nuqtasiga bog'lash (buyurtmasiz kelgan yoki xato tanlangan namuna). [Paket 29]
 * Laboratoriya chiqargan namuna — server rad etadi.
 */
import { computed, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import BaseModal from '@/components/ui/BaseModal.vue'
import api from '@/lib/api'
import { apiError } from '../shared'
import '../i18n'

const props = defineProps({
  modelValue: Boolean,
  entry: { type: Object, default: null },        // { sample_id, code, shift, point_id?, registered_at? }
  sections: { type: Array, default: () => [] },  // spravochnik bloklari (faqat faol nuqtalar tanlanadi)
  settings: { type: Object, default: () => ({}) },
  date: { type: String, default: '' },
})
const emit = defineEmits(['update:modelValue', 'saved'])
const { t } = useI18n()

const form = reactive({ point_id: '', date: '', shift: 2 })
const busy = ref(false)
const error = ref('')

watch(() => props.modelValue, (open) => {
  if (!open || !props.entry) return
  form.point_id = props.entry.point_id ?? ''
  form.date = props.date
  form.shift = props.entry.shift || 2
  error.value = ''
})

const groups = computed(() => props.sections
  .filter((s) => s.is_active !== false)
  .map((s) => ({ ...s, points: (s.points ?? []).filter((p) => p.is_active !== false || p.id === props.entry?.point_id) }))
  .filter((s) => s.points.length))
const reassign = computed(() => !!props.entry?.point_id)

async function save() {
  if (!form.point_id && !reassign.value) {
    error.value = t('svodka.assign.choosePoint')
    return
  }
  busy.value = true
  error.value = ''
  try {
    const { data } = await api.put('/portal/svodka/assign', {
      sample_id: props.entry.sample_id, point_id: form.point_id || null, date: form.date, shift: Number(form.shift),
    })
    emit('saved', data)
    emit('update:modelValue', false)
  } catch (e) {
    error.value = apiError(e, t('svodka.loadError'))
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <BaseModal
    :model-value="modelValue" size="sm"
    :title="reassign ? t('svodka.assign.titleChange', { code: entry?.code }) : t('svodka.assign.title', { code: entry?.code })"
    :subtitle="t('svodka.assign.subtitle')"
    @update:model-value="emit('update:modelValue', $event)"
  >
    <div class="space-y-4" data-assign-dialog>
      <div>
        <label class="field-label">{{ t('svodka.assign.point') }} *</label>
        <select v-model="form.point_id" class="field-input" data-f="point">
          <option value="">{{ reassign ? t('svodka.assign.unlink') : t('svodka.assign.choosePoint') }}</option>
          <optgroup v-for="g in groups" :key="g.id" :label="g.title">
            <option v-for="p in g.points" :key="p.id" :value="p.id">{{ p.name }}</option>
          </optgroup>
        </select>
      </div>
      <div class="grid grid-cols-2 gap-3">
        <div>
          <label class="field-label">{{ t('svodka.assign.date') }}</label>
          <input v-model="form.date" type="date" class="field-input" data-f="date">
        </div>
        <div>
          <label class="field-label">{{ t('svodka.assign.shift') }}</label>
          <select v-model="form.shift" class="field-input" data-f="shift">
            <option :value="1">{{ settings.shift1_label }} · {{ settings.shift1_hours }}</option>
            <option :value="2">{{ settings.shift2_label }} · {{ settings.shift2_hours }}</option>
          </select>
        </div>
      </div>
      <p class="text-[12.5px] text-ink-muted">{{ t('svodka.assign.hint') }}</p>
      <p v-if="error" class="text-[13px] text-crit" data-assign-error>{{ error }}</p>
    </div>
    <template #footer>
      <button class="btn-ghost" @click="emit('update:modelValue', false)">{{ t('svodka.cancel') }}</button>
      <button class="btn-primary" :disabled="busy" data-assign-save @click="save">{{ t('svodka.assign.save') }}</button>
    </template>
  </BaseModal>
</template>
