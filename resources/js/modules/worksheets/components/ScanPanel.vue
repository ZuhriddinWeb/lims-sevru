<script setup>
import { onUnmounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { Camera, ExternalLink, FileText, Loader2, Trash2, Upload } from 'lucide-vue-next'
import api from '@/lib/api'
import { useUiStore } from '@/stores/ui'
import { apiErrorMessage } from '../helpers'
import '../i18n'

const props = defineProps({
  worksheetId: { type: Number, required: true },
  scans: { type: Array, default: () => [] },
  canUpload: Boolean,
  canDelete: Boolean,
  selectedId: { type: Number, default: null },
})
const emit = defineEmits(['changed', 'select'])

const { t } = useI18n()
const ui = useUiStore()

const fileInput = ref(null)
const cameraInput = ref(null)
const uploading = ref(false)
const urls = ref({})        // scanId → blob URL (rasm ko'rinishi uchun)

const isImage = (s) => (s.mime || '').startsWith('image/')

// Skanlar sessiya cookie bilan, axios orqali olinadi (blob) — to'g'ridan-to'g'ri havola emas
async function loadUrl(scan) {
  if (urls.value[scan.id]) return urls.value[scan.id]
  const { data } = await api.get(`/worksheets/${props.worksheetId}/scans/${scan.id}`, { responseType: 'blob' })
  const url = URL.createObjectURL(data)
  urls.value = { ...urls.value, [scan.id]: url }
  return url
}

watch(() => props.scans, (list) => {
  list.filter(isImage).forEach((s) => loadUrl(s).catch(() => {}))
}, { immediate: true })

onUnmounted(() => Object.values(urls.value).forEach((u) => URL.revokeObjectURL(u)))

async function upload(event) {
  const files = [...(event.target.files ?? [])]
  event.target.value = ''
  if (!files.length) return
  const form = new FormData()
  files.slice(0, 10).forEach((f) => form.append('files[]', f))
  uploading.value = true
  try {
    await api.post(`/worksheets/${props.worksheetId}/scans`, form)
    ui.success(t('ws.scanUploaded'))
    emit('changed')
  } catch (e) {
    ui.error(apiErrorMessage(e, t('ws.saveError')))
  } finally {
    uploading.value = false
  }
}

async function openScan(scan) {
  try {
    const url = await loadUrl(scan)
    window.open(url, '_blank', 'noopener')
  } catch (e) {
    ui.error(apiErrorMessage(e, t('ws.loadError')))
  }
}

async function remove(scan) {
  if (!window.confirm(t('ws.scanDelete') + '?')) return
  try {
    await api.delete(`/worksheets/${props.worksheetId}/scans/${scan.id}`)
    ui.success(t('ws.scanDeleted'))
    emit('changed')
  } catch (e) {
    ui.error(apiErrorMessage(e, t('ws.saveError')))
  }
}

const sizeLabel = (b) => (b > 1048576 ? `${(b / 1048576).toFixed(1)} MB` : `${Math.max(1, Math.round(b / 1024))} KB`)

defineExpose({ loadUrl })
</script>

<template>
  <section class="panel p-4">
    <div class="flex flex-wrap items-start justify-between gap-3">
      <div class="min-w-0">
        <h3 class="text-[15px] font-semibold text-ink">{{ t('ws.scanTitle') }}</h3>
        <p class="text-[12.5px] text-ink-muted mt-0.5 max-w-md">{{ t('ws.scanHint') }}</p>
      </div>
      <div v-if="canUpload" class="flex gap-2">
        <input ref="fileInput" type="file" class="hidden" multiple accept="image/jpeg,image/png,image/webp,application/pdf" @change="upload">
        <input ref="cameraInput" type="file" class="hidden" accept="image/*" capture="environment" @change="upload">
        <button class="btn-outline btn-sm" :disabled="uploading" @click="cameraInput.click()">
          <Camera :size="15" /> {{ t('ws.scanCamera') }}
        </button>
        <button class="btn-outline btn-sm" :disabled="uploading" @click="fileInput.click()">
          <Loader2 v-if="uploading" :size="15" class="animate-spin" />
          <Upload v-else :size="15" />
          {{ uploading ? t('ws.scanUploading') : t('ws.scanUpload') }}
        </button>
      </div>
    </div>

    <p v-if="!scans.length" class="mt-4 text-[13px] text-ink-faint">{{ t('ws.scanEmpty') }}</p>

    <div v-else class="mt-4 grid grid-cols-2 sm:grid-cols-3 gap-3">
      <div
        v-for="s in scans" :key="s.id"
        class="group relative rounded-md border overflow-hidden bg-surface-sunken transition-all"
        :class="selectedId === s.id ? 'border-assay-500 ring-1 ring-assay-500' : 'border-line'"
      >
        <button class="block w-full aspect-[4/3] bg-surface-sunken" @click="isImage(s) ? emit('select', s) : openScan(s)">
          <img v-if="isImage(s) && urls[s.id]" :src="urls[s.id]" :alt="s.original_name" class="h-full w-full object-cover">
          <span v-else class="h-full w-full grid place-items-center text-ink-faint"><FileText :size="28" /></span>
        </button>
        <div class="px-2 py-1.5 border-t border-line bg-surface">
          <p class="text-[12px] text-ink truncate" :title="s.original_name">{{ s.original_name }}</p>
          <p class="text-[11px] text-ink-faint">{{ sizeLabel(s.size) }} · {{ s.uploader?.name ?? '—' }}</p>
        </div>
        <div class="absolute top-1.5 right-1.5 flex gap-1 opacity-0 group-hover:opacity-100 focus-within:opacity-100 transition-opacity">
          <button class="h-7 w-7 grid place-items-center rounded bg-surface/90 text-ink-muted hover:text-ink shadow-sm" :title="t('ws.scanOpen')" @click="openScan(s)">
            <ExternalLink :size="14" />
          </button>
          <button v-if="canDelete" class="h-7 w-7 grid place-items-center rounded bg-surface/90 text-crit hover:bg-crit-light shadow-sm" :title="t('ws.scanDelete')" @click="remove(s)">
            <Trash2 :size="14" />
          </button>
        </div>
      </div>
    </div>
  </section>
</template>
