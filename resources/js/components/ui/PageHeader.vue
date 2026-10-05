<script setup>
defineProps({
  title: String,
  subtitle: String,
})
</script>

<template>
  <div class="flex flex-wrap items-end justify-between gap-4 mb-5">
    <div class="min-w-0">
      <h1 class="text-[22px] sm:text-[24px] font-semibold tracking-[-.01em] text-ink leading-tight">
        {{ title }}
      </h1>
      <p v-if="subtitle" class="mt-1 text-[14px] text-ink-muted">{{ subtitle }}</p>
    </div>
    <div v-if="$slots.actions" class="flex items-center gap-2 shrink-0"><slot name="actions" /></div>
  </div>
</template>
