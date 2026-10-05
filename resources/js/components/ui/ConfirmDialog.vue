<script setup>
import { AlertTriangle } from 'lucide-vue-next'
import BaseModal from './BaseModal.vue'

defineProps({
  modelValue: Boolean,
  title: String,
  body: String,
  confirmLabel: String,
  cancelLabel: String,
  danger: { type: Boolean, default: true },
  loading: Boolean,
})
const emit = defineEmits(['update:modelValue', 'confirm'])
</script>

<template>
  <BaseModal :model-value="modelValue" size="sm" @update:model-value="emit('update:modelValue', $event)">
    <div class="flex gap-4">
      <div
        class="h-10 w-10 shrink-0 rounded-full grid place-items-center"
        :class="danger ? 'bg-crit-light text-crit dark:bg-crit-dark/30' : 'bg-warn-light text-warn dark:bg-warn-dark/30'"
      >
        <AlertTriangle :size="20" />
      </div>
      <div class="min-w-0 pt-0.5">
        <h3 class="text-[15px] font-semibold text-ink">{{ title }}</h3>
        <p class="mt-1.5 text-[14px] leading-relaxed text-ink-muted">{{ body }}</p>
      </div>
    </div>

    <template #footer>
      <button class="btn-ghost" @click="emit('update:modelValue', false)">{{ cancelLabel }}</button>
      <button :class="danger ? 'btn-danger' : 'btn-primary'" :disabled="loading" @click="emit('confirm')">
        {{ confirmLabel }}
      </button>
    </template>
  </BaseModal>
</template>
