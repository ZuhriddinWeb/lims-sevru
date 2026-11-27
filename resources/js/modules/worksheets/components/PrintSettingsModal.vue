<script setup>
/**
 * [P45] Ishchi varaq chop etish sozlamalari — pastki «Yaratdi / Tasdiqladi» bloki va QR kod.
 * Ruxsat: worksheet_templates.manage (admin). Saqlanadi: app_settings → worksheets.print.
 */
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import api from '@/lib/api'
import BaseModal from '@/components/ui/BaseModal.vue'
import { useUiStore } from '@/stores/ui'
import { qrEncode, qrSvgPath } from '@/lib/qr'
import { apiErrorMessage } from '../helpers'
import '../i18n'

const props = defineProps({ modelValue: Boolean })
const emit = defineEmits(['update:modelValue'])
const { t } = useI18n()
const ui = useUiStore()

const form = ref({ sign_block: true, qr: true, qr_mode: 'text' })
const loading = ref(false)
const saving = ref(false)

watch(() => props.modelValue, async (open) => {
  if (!open) return
  loading.value = true
  try {
    const { data } = await api.get('/worksheet-print-settings')
    form.value = { ...data.settings }
  } catch (e) {
    ui.error(apiErrorMessage(e, t('ws.loadError')))
  } finally {
    loading.value = false
  }
})

async function save() {
  saving.value = true
  try {
    const { data } = await api.put('/worksheet-print-settings', form.value)
    form.value = { ...data.settings }
    ui.success(t('ws.psSaved'))
    emit('update:modelValue', false)
  } catch (e) {
    ui.error(apiErrorMessage(e, t('ws.saveError')))
  } finally {
    saving.value = false
  }
}

// Namuna ko'rinish (sozlama tanlovi bo'yicha)
const sample = computed(() => {
  const text = form.value.qr_mode === 'link'
    ? `${window.location.origin}/worksheets/622?v=7F3A-91C2`
    : "LIMS · Ishchi varaq № 622n\nBo'tana · 384-2026\nNamuna: p-1386 (PR-202611-4206)\nTasdiqladi: Shohimov Sh.J.\n26.11.2026 08:18\nKod: 7F3A-91C2"
  const q = qrEncode(text, 'M')
  return { d: qrSvgPath(q, 0), n: q.size }
})
</script>

<template>
  <BaseModal :model-value="modelValue" size="md" :title="t('ws.psTitle')" :subtitle="t('ws.psSubtitle')" @update:model-value="emit('update:modelValue', $event)">
    <div v-if="loading" class="h-40 animate-pulse rounded-lg bg-surface-sunken" />
    <div v-else class="space-y-4">
      <label class="flex items-start gap-3 cursor-pointer select-none">
        <input v-model="form.sign_block" type="checkbox" class="mt-0.5 rounded border-line text-assay-500 focus:ring-assay-500">
        <span>
          <span class="block text-[14px] font-medium text-ink">{{ t('ws.psBlock') }}</span>
          <span class="block text-[12.5px] text-ink-muted">{{ t('ws.psBlockHint') }}</span>
        </span>
      </label>

      <label class="flex items-start gap-3 select-none" :class="form.sign_block ? 'cursor-pointer' : 'opacity-50'">
        <input v-model="form.qr" type="checkbox" :disabled="!form.sign_block" class="mt-0.5 rounded border-line text-assay-500 focus:ring-assay-500">
        <span>
          <span class="block text-[14px] font-medium text-ink">{{ t('ws.psQr') }}</span>
          <span class="block text-[12.5px] text-ink-muted">{{ t('ws.psQrHint') }}</span>
        </span>
      </label>

      <fieldset v-if="form.sign_block && form.qr" class="ml-7 space-y-2">
        <legend class="text-[12.5px] font-medium text-ink-muted mb-1">{{ t('ws.psMode') }}</legend>
        <label v-for="m in ['text', 'link']" :key="m" class="flex items-start gap-2.5 cursor-pointer">
          <input v-model="form.qr_mode" type="radio" :value="m" class="mt-0.5 border-line text-assay-500 focus:ring-assay-500">
          <span>
            <span class="block text-[13.5px] text-ink">{{ t(`ws.psMode_${m}`) }}</span>
            <span class="block text-[12px] text-ink-faint">{{ t(`ws.psMode_${m}_hint`) }}</span>
          </span>
        </label>
      </fieldset>

      <!-- namuna -->
      <div v-if="form.sign_block" class="rounded-lg border border-line bg-surface-sunken/50 p-3">
        <p class="text-[11.5px] font-medium uppercase tracking-wide text-ink-faint mb-2">{{ t('ws.psPreview') }}</p>
        <div class="flex items-center gap-3 rounded-md border border-ink/70 bg-white p-2.5 text-black max-w-[330px]" style="font-family:'Times New Roman',serif">
          <svg v-if="form.qr" :viewBox="`-1 -1 ${sample.n + 2} ${sample.n + 2}`" class="w-[76px] h-[76px] shrink-0" shape-rendering="crispEdges">
            <rect x="-1" y="-1" :width="sample.n + 2" :height="sample.n + 2" fill="#fff" /><path :d="sample.d" fill="#000" />
          </svg>
          <div class="min-w-0 leading-tight">
            <p class="text-[10px] font-bold uppercase tracking-wider text-neutral-700 border-b border-neutral-400 pb-0.5 mb-1">Tasdiqladi</p>
            <p class="text-[13px] font-bold">Shohimov Sh.J.</p>
            <p class="text-[11.5px]">26.11.2026 08:18</p>
            <p class="text-[10.5px] font-mono mt-0.5">Kod: 7F3A-91C2</p>
          </div>
        </div>
      </div>
    </div>

    <template #footer>
      <button class="btn-ghost" @click="emit('update:modelValue', false)">{{ t('common.cancel') }}</button>
      <button class="btn-primary" :disabled="saving || loading" @click="save">{{ t('common.save') }}</button>
    </template>
  </BaseModal>
</template>
