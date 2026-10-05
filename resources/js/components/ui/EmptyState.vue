<script setup>
import { Inbox } from 'lucide-vue-next'

defineProps({
  icon: { type: [Object, Function], default: null },
  title: String,
  hint: String,
})
</script>

<template>
  <div class="flex flex-col items-center justify-center py-14 px-6 text-center">
    <div class="h-12 w-12 rounded-full bg-surface-sunken grid place-items-center text-ink-faint mb-3.5">
      <component :is="icon || Inbox" :size="22" />
    </div>
    <p class="text-[15px] font-medium text-ink">{{ title }}</p>
    <p v-if="hint" class="mt-1 text-[13.5px] text-ink-muted max-w-sm leading-relaxed">{{ hint }}</p>
    <div v-if="$slots.default" class="mt-4"><slot /></div>
  </div>
</template>
