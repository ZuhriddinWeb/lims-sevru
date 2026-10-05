<script setup>
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { Download, FileText, Paperclip, Trash2, Upload } from 'lucide-vue-next'
import api from '@/lib/api'
import BaseModal from '@/components/ui/BaseModal.vue'
import ConfirmDialog from '@/components/ui/ConfirmDialog.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import { useUiStore } from '@/stores/ui'
import { formatDateTime } from '@/lib/format'

const props = defineProps({
  // Hujjatlar manzili, masalan: /equipment/5/documents
  endpoint: { type: String, required: true },
  // Hujjat turlari: [{ value, labelKey }]
  types: { type: Array, required: true },
  canUpload: { type: Boolean, default: false },
  canDelete: { type: Boolean, default: false },
})

const { t } = useI18n()
const ui = useUiStore()

const documents = ref([])
const loading = ref(true)
const saving = ref(false)

async function load() {
  loading.value = true
  try {
    const { data } = await api.get(props.endpoint)
    documents.value = Array.isArray(data) ? data : (data.data ?? [])
  } catch {
    documents.value = []
  } finally {
    loading.value = false
  }
}
onMounted(load)

/* Yuklash */
const uploadOpen = ref(false)
const form = ref({ type: props.types[0]?.value, title: '', file: null })
const fileInput = ref(null)
const dragging = ref(false)

function openUpload() {
  form.value = { type: props.types[0]?.value, title: '', file: null }
  uploadOpen.value = true
}

function pickFile(file) {
  if (!file) return
  form.value.file = file
  if (!form.value.title) {
    form.value.title = file.name.replace(/\.[^.]+$/, '')
  }
}

function onDrop(e) {
  dragging.value = false
  pickFile(e.dataTransfer.files?.[0])
}

async function upload() {
  if (!form.value.file) return
  saving.value = true
  try {
    const fd = new FormData()
    fd.append('type', form.value.type)
    fd.append('title', form.value.title)
    fd.append('file', form.value.file)

    await api.post(props.endpoint, fd, { headers: { 'Content-Type': 'multipart/form-data' } })
    ui.success(t('document.uploaded'))
    uploadOpen.value = false
    await load()
  } catch (e) {
    const err = e.response?.data?.errors
    ui.error(err?.file?.[0] || e.response?.data?.message || t('toast.error'))
  } finally {
    saving.value = false
  }
}

/* O'chirish */
const confirmOpen = ref(false)
const target = ref(null)

async function remove() {
  saving.value = true
  try {
    await api.delete(`${props.endpoint}/${target.value.id}`)
    ui.success(t('toast.deleted'))
    confirmOpen.value = false
    await load()
  } catch (e) {
    ui.error(e.response?.data?.message || t('toast.error'))
  } finally {
    saving.value = false
  }
}

function typeLabel(value) {
  const type = props.types.find((x) => x.value === value)
  return type ? t(type.labelKey) : value
}

function sizeLabel(bytes) {
  if (!bytes) return ''
  const units = ['B', 'KB', 'MB']
  let i = 0
  let n = bytes
  while (n >= 1024 && i < units.length - 1) { n /= 1024; i++ }
  return `${n.toFixed(i === 0 ? 0 : 1)} ${units[i]}`
}

const accept = '.pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png,.webp,.zip,.rar'
</script>

<template>
  <div>
    <header class="flex items-center justify-between gap-2 px-4 py-3.5 border-b border-line">
      <h2 class="text-[14px] font-semibold text-ink">{{ t('document.title') }}</h2>
      <button v-if="canUpload" class="btn-ghost btn-sm" @click="openUpload">
        <Upload :size="15" /> {{ t('document.upload') }}
      </button>
    </header>

    <div v-if="loading" class="p-4 space-y-2">
      <div v-for="i in 2" :key="i" class="h-10 rounded bg-surface-sunken animate-pulse" />
    </div>

    <EmptyState
      v-else-if="!documents.length"
      :icon="Paperclip"
      :title="t('document.empty')"
      :hint="canUpload ? t('document.emptyHint') : null"
    />

    <ul v-else class="divide-y divide-line">
      <li
        v-for="(doc, i) in documents" :key="doc.id"
        class="flex items-center gap-3 px-4 py-3 group hover:bg-surface-sunken/60 transition-colors animate-row-in"
        :style="{ animationDelay: `${i * 30}ms` }"
      >
        <span class="h-9 w-9 shrink-0 rounded-md bg-surface-sunken grid place-items-center text-ink-muted">
          <FileText :size="17" />
        </span>

        <div class="min-w-0 flex-1">
          <p class="text-[13.5px] font-medium text-ink truncate">{{ doc.title }}</p>
          <p class="text-[12px] text-ink-faint truncate">
            {{ typeLabel(doc.type) }}
            <span v-if="doc.file_size"> · {{ sizeLabel(doc.file_size) }}</span>
            <span v-if="doc.uploader"> · {{ doc.uploader.name }}</span>
            <span> · {{ formatDateTime(doc.created_at) }}</span>
          </p>
        </div>

        <div class="flex items-center gap-0.5 shrink-0">
          <a
            v-if="doc.url" :href="doc.url" target="_blank" rel="noopener"
            class="btn-ghost btn-sm btn-icon" :title="t('document.download')"
          >
            <Download :size="15" />
          </a>
          <button
            v-if="canDelete"
            class="btn-ghost btn-sm btn-icon hover:text-crit md:opacity-0 md:group-hover:opacity-100 transition-opacity"
            :title="t('common.delete')"
            @click="target = doc; confirmOpen = true"
          >
            <Trash2 :size="15" />
          </button>
        </div>
      </li>
    </ul>

    <!-- Yuklash oynasi -->
    <BaseModal v-model="uploadOpen" size="sm" :title="t('document.upload')">
      <div class="space-y-4">
        <div>
          <label class="field-label">{{ t('document.type') }}</label>
          <select v-model="form.type" class="field-input">
            <option v-for="tp in types" :key="tp.value" :value="tp.value">{{ t(tp.labelKey) }}</option>
          </select>
        </div>

        <div>
          <label class="field-label">{{ t('document.name') }}</label>
          <input v-model="form.title" class="field-input" />
        </div>

        <!-- Tashlab yuborish maydoni -->
        <div
          class="rounded-md border-2 border-dashed transition-colors cursor-pointer px-4 py-6 text-center"
          :class="dragging ? 'border-assay-400 bg-assay-50/50 dark:bg-assay-900/20' : 'border-line hover:border-ink-faint'"
          @click="fileInput?.click()"
          @dragover.prevent="dragging = true"
          @dragleave.prevent="dragging = false"
          @drop.prevent="onDrop"
        >
          <input ref="fileInput" type="file" class="hidden" :accept="accept"
                 @change="pickFile($event.target.files?.[0])" />

          <Upload :size="20" class="mx-auto text-ink-faint mb-2" />
          <p v-if="form.file" class="text-[13.5px] text-ink font-medium truncate">{{ form.file.name }}</p>
          <p v-else class="text-[13.5px] text-ink-muted">{{ t('document.dropHere') }}</p>
          <p class="text-[12px] text-ink-faint mt-1">{{ t('document.formats') }}</p>
        </div>
      </div>

      <template #footer>
        <button class="btn-ghost" @click="uploadOpen = false">{{ t('common.cancel') }}</button>
        <button class="btn-primary" :disabled="saving || !form.file || !form.title" @click="upload">
          {{ saving ? t('common.loading') : t('document.upload') }}
        </button>
      </template>
    </BaseModal>

    <ConfirmDialog
      v-model="confirmOpen"
      :title="t('confirm.deleteTitle')"
      :body="t('confirm.deleteBody', { name: target?.title ?? '' })"
      :confirm-label="t('confirm.deleteAction')"
      :cancel-label="t('common.cancel')"
      :loading="saving"
      @confirm="remove"
    />
  </div>
</template>
