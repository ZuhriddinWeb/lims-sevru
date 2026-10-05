<script setup>
defineProps({
  rows: { type: Number, default: 6 },
  cols: { type: Number, default: 5 },
})
</script>

<template>
  <div class="divide-y divide-line">
    <div v-for="r in rows" :key="r" class="flex items-center gap-4 px-4 py-3.5">
      <div
        v-for="c in cols" :key="c"
        class="relative h-3.5 rounded bg-surface-sunken overflow-hidden"
        :class="c === 1 ? 'w-28' : c === cols ? 'w-16 ml-auto' : 'flex-1 max-w-[180px]'"
        :style="{ animationDelay: `${r * 40}ms` }"
      >
        <div class="absolute inset-0 -translate-x-full bg-gradient-to-r from-transparent via-black/[.04] dark:via-white/[.06] to-transparent animate-[shimmer_1.4s_infinite]" />
      </div>
    </div>
  </div>
</template>
