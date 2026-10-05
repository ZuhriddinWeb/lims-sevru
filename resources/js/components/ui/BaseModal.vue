<script setup>
import { watch, onUnmounted } from 'vue'
import { X } from 'lucide-vue-next'

const props = defineProps({
  modelValue: Boolean,
  title: String,
  subtitle: String,
  size: { type: String, default: 'md' }, // sm | md | lg | xl
})
const emit = defineEmits(['update:modelValue'])

const SIZES = {
  sm: 'max-w-md', md: 'max-w-xl', lg: 'max-w-3xl', xl: 'max-w-5xl',
}

function close() { emit('update:modelValue', false) }

function onKey(e) { if (e.key === 'Escape') close() }

watch(() => props.modelValue, (open) => {
  document.body.style.overflow = open ? 'hidden' : ''
  open ? window.addEventListener('keydown', onKey) : window.removeEventListener('keydown', onKey)
})

onUnmounted(() => {
  document.body.style.overflow = ''
  window.removeEventListener('keydown', onKey)
})
</script>

<template>
  <Teleport to="body">
    <Transition
      enter-active-class="transition duration-150 ease-out"
      enter-from-class="opacity-0" enter-to-class="opacity-100"
      leave-active-class="transition duration-120 ease-in"
      leave-from-class="opacity-100" leave-to-class="opacity-0"
    >
      <div v-if="modelValue" class="fixed inset-0 z-50 bg-ink/40 backdrop-blur-[2px]" @click="close" />
    </Transition>

    <Transition
      enter-active-class="transition duration-180 ease-[cubic-bezier(.2,.8,.3,1)]"
      enter-from-class="opacity-0 translate-y-3 scale-[.98]"
      enter-to-class="opacity-100 translate-y-0 scale-100"
      leave-active-class="transition duration-130 ease-in"
      leave-from-class="opacity-100 translate-y-0 scale-100"
      leave-to-class="opacity-0 translate-y-2 scale-[.99]"
    >
      <div v-if="modelValue" class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto p-4 sm:p-6 pointer-events-none">
        <div
          class="panel w-full pointer-events-auto my-auto shadow-pop"
          :class="SIZES[size]"
          role="dialog" aria-modal="true"
        >
          <header v-if="title" class="flex items-start justify-between gap-4 px-5 py-4 border-b border-line">
            <div class="min-w-0">
              <h2 class="text-[16px] font-semibold text-ink leading-tight">{{ title }}</h2>
              <p v-if="subtitle" class="mt-0.5 text-[13px] text-ink-muted">{{ subtitle }}</p>
            </div>
            <button class="btn-ghost btn-icon -mr-1.5 -mt-1" @click="close" aria-label="Close">
              <X :size="18" />
            </button>
          </header>

          <div class="px-5 py-4"><slot /></div>

          <footer v-if="$slots.footer" class="flex items-center justify-end gap-2 px-5 py-3.5 border-t border-line bg-surface-sunken/60 rounded-b-panel">
            <slot name="footer" />
          </footer>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>
