<script setup>
/** Tasdiqlash oynasi + sabab maydoni (bekor qilish / rad etish) */
import { ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import BaseModal from '@/components/ui/BaseModal.vue'
import '../i18n'

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
})
const emit = defineEmits(['update:modelValue', 'confirm'])
const { t } = useI18n()
const reason = ref('')
watch(() => props.modelValue, (v) => { if (v) reason.value = '' })
</script>

<template>
  <BaseModal :model-value="modelValue" :title="title" size="sm" @update:model-value="emit('update:modelValue', $event)">
    <p class="text-[13.5px] leading-relaxed text-ink-muted">{{ body }}</p>
    <div v-if="withReason" class="mt-3">
      <label class="field-label">{{ required ? t('sreq.confirm.reason') + ' *' : t('sreq.confirm.reasonOptional') }}</label>
      <textarea v-model="reason" rows="2" class="field-input" maxlength="500" :placeholder="placeholder" data-reason-input />
    </div>
    <template #footer>
      <button class="btn-ghost" @click="emit('update:modelValue', false)">{{ t('common.cancel') }}</button>
      <button
        :class="danger ? 'btn-danger' : 'btn-primary'" data-confirm
        :disabled="loading || (withReason && required && reason.trim().length < 3)"
        @click="emit('confirm', reason.trim())"
      >{{ confirmLabel }}</button>
    </template>
  </BaseModal>
</template>
