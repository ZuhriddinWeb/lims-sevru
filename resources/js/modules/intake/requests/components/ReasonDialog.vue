<script setup>
/**
 * Tasdiqlash oynasi + sabab maydoni (bekor qilish / rad etish).
 * [P39] reasons berilsa — sabab ro'yxatdan tanlanadi (reasonRequired — majburiy), izoh qo'shimcha;
 * «izoh majburiy» sabab (Boshqa) tanlansa — izoh ham majburiy. confirm(izoh, reasonId)
 */
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import BaseModal from '@/components/ui/BaseModal.vue'
import { nameOf } from '../shared'
import '../i18n'
import '../i18nP39'

const props = defineProps({
  modelValue: Boolean,
  title: String,
  body: String,
  confirmLabel: String,
  placeholder: String,
  required: Boolean,
  withReason: { type: Boolean, default: true },
  danger: { type: Boolean, default: true },
  loading: Boolean,
  reasons: { type: Array, default: () => [] },    // [P39] [{ id, name_uz, name_ru, name_en, needs_note }]
  reasonRequired: { type: Boolean, default: false },
})
const emit = defineEmits(['update:modelValue', 'confirm'])
const { t, locale } = useI18n()
const reason = ref('')
const reasonId = ref('')
watch(() => props.modelValue, (v) => { if (v) { reason.value = ''; reasonId.value = '' } })

const useList = computed(() => props.withReason && props.reasons.length > 0)
const picked = computed(() => props.reasons.find((r) => r.id === Number(reasonId.value)) ?? null)
const noteRequired = computed(() => {
  if (!props.withReason) return false
  if (useList.value) return !!picked.value?.needs_note || (!picked.value && props.required && !props.reasonRequired)
  return props.required
})
const ok = computed(() => {
  if (!props.withReason) return true
  if (useList.value && props.reasonRequired && !picked.value) return false
  if (useList.value && !picked.value && props.required && reason.value.trim().length < 3) return false
  if (noteRequired.value && reason.value.trim().length < 3) return false
  return true
})
</script>

<template>
  <BaseModal :model-value="modelValue" :title="title" size="sm" @update:model-value="emit('update:modelValue', $event)">
    <p class="text-[13.5px] leading-relaxed text-ink-muted">{{ body }}</p>
    <div v-if="useList" class="mt-3">
      <label class="field-label">{{ t('sreq.p39.reasonPick') }}{{ reasonRequired ? ' *' : '' }}</label>
      <select v-model="reasonId" class="field-input" data-reason-pick>
        <option value="">{{ t('sreq.p39.reasonChoose') }}</option>
        <option v-for="r in reasons" :key="r.id" :value="r.id">{{ nameOf(r, locale) }}</option>
      </select>
    </div>
    <div v-if="withReason" class="mt-3">
      <label class="field-label">{{ noteRequired ? (useList ? t('sreq.p39.noteRequired') : t('sreq.confirm.reason')) + ' *' : (useList ? t('sreq.p39.noteOptional') : t('sreq.confirm.reasonOptional')) }}</label>
      <textarea v-model="reason" rows="2" class="field-input" maxlength="400" :placeholder="placeholder" data-reason-input />
    </div>
    <template #footer>
      <button class="btn-ghost" @click="emit('update:modelValue', false)">{{ t('common.cancel') }}</button>
      <button
        :class="danger ? 'btn-danger' : 'btn-primary'" data-confirm
        :disabled="loading || !ok"
        @click="emit('confirm', reason.trim(), picked ? picked.id : null)"
      >{{ confirmLabel }}</button>
    </template>
  </BaseModal>
</template>
