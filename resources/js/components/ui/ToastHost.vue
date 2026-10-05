<script setup>
import { CheckCircle2, AlertCircle, Info, X } from 'lucide-vue-next'
import { useUiStore } from '@/stores/ui'

const ui = useUiStore()

const ICONS = { success: CheckCircle2, error: AlertCircle, info: Info }
const TONES = {
  success: 'border-l-ok text-ok-dark dark:text-ok-light',
  error:   'border-l-crit text-crit-dark dark:text-crit-light',
  info:    'border-l-aqua-500 text-aqua-700 dark:text-aqua-200',
}
</script>

<template>
  <div class="fixed bottom-4 right-4 z-[60] flex flex-col gap-2 w-[min(380px,calc(100vw-2rem))]">
    <TransitionGroup
      enter-active-class="transition duration-200 ease-[cubic-bezier(.2,.8,.3,1)]"
      enter-from-class="opacity-0 translate-x-4"
      enter-to-class="opacity-100 translate-x-0"
      leave-active-class="transition duration-150 ease-in absolute w-full"
      leave-from-class="opacity-100 translate-x-0"
      leave-to-class="opacity-0 translate-x-4"
      move-class="transition duration-200"
    >
      <div
        v-for="toast in ui.toasts" :key="toast.id"
        class="panel border-l-[3px] px-4 py-3 flex items-start gap-3 shadow-pop"
        :class="TONES[toast.type]"
        role="status"
      >
        <component :is="ICONS[toast.type]" :size="18" class="shrink-0 mt-0.5" />
        <p class="text-[14px] text-ink leading-snug flex-1 min-w-0">{{ toast.message }}</p>
        <button class="text-ink-faint hover:text-ink transition-colors shrink-0" @click="ui.dismiss(toast.id)">
          <X :size="15" />
        </button>
      </div>
    </TransitionGroup>
  </div>
</template>
