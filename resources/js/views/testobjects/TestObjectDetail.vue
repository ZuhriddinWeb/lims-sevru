<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { ArrowLeft, Trash } from 'lucide-vue-next'
import api from '@/lib/api'
import StatusBadge from '@/components/ui/StatusBadge.vue'
import ConfirmDialog from '@/components/ui/ConfirmDialog.vue'
import DocumentPanel from '@/components/crud/DocumentPanel.vue'
import { useAuthStore } from '@/stores/auth'
import { useUiStore } from '@/stores/ui'
import { formatDate } from '@/lib/format'
import { localized } from '@/i18n'

const route = useRoute()
const router = useRouter()
const { t } = useI18n()
const auth = useAuthStore()
const ui = useUiStore()

const item = ref(null)
const loading = ref(true)
const saving = ref(false)

async function load() {
  loading.value = true
  try {
    const { data } = await api.get(`/test-objects/${route.params.id}`)
    item.value = data
  } catch {
    ui.error(t('toast.error'))
  } finally {
    loading.value = false
  }
}
onMounted(load)

// TZ 2.8 — hamroh hujjatlar
const DOC_TYPES = [
  { value: 'waybill', labelKey: 'document.waybill' },
  { value: 'acceptance_act', labelKey: 'document.acceptance_act' },
  { value: 'disposal_act', labelKey: 'document.disposal_act' },
  { value: 'protocol', labelKey: 'document.protocol' },
  { value: 'other', labelKey: 'document.other' },
]

const canUploadDocs = computed(() => auth.can('documents.create') || auth.can('test_objects.update'))
const canDeleteDocs = computed(() => auth.can('documents.delete') || auth.can('test_objects.delete'))
const canDispose = computed(() => auth.can('test_objects.update') && item.value?.status !== 'disposed')

/* Utilizatsiya */
const confirmOpen = ref(false)

async function dispose() {
  saving.value = true
  try {
    const { data } = await api.patch(`/test-objects/${item.value.id}/dispose`, {})
    item.value = data
    ui.success(t('testObject.disposed'))
    confirmOpen.value = false
  } catch (e) {
    ui.error(e.response?.data?.message || t('toast.error'))
  } finally {
    saving.value = false
  }
}

const facts = computed(() => {
  const o = item.value
  if (!o) return []
  return [
    ['testObject.type', t(`objectType.${o.type}`)],
    ['testObject.code', o.code],
    ['testObject.batch', o.batch],
    ['testObject.supplier', o.source_supplier],
    ['testObject.receivedAt', formatDate(o.received_at)],
    ['catalog.laboratory', localized(o.laboratory, 'name')],
    ['profile.one', localized(o.profile, 'name')],
    ['testObject.storageLocation', o.storage_location],
    ['testObject.storageExpiry', formatDate(o.storage_expiry)],
    ['testObject.disposedAt', formatDate(o.disposed_at)],
    ['testObject.disposalAct', o.disposal_act],
  ]
})

const texts = computed(() => {
  const o = item.value
  if (!o) return []
  return [
    ['testObject.description', o.description],
    ['testObject.physical', o.physical_characteristics],
    ['testObject.chemical', o.chemical_composition],
    ['testObject.storageConditions', o.storage_conditions],
    ['testObject.transportConditions', o.transport_conditions],
    ['testObject.documents', o.accompanying_documents],
  ].filter(([, v]) => v)
})
</script>

<template>
  <div v-if="loading" class="space-y-4">
    <div class="h-8 w-56 rounded bg-surface-sunken animate-pulse" />
    <div class="h-40 panel animate-pulse" />
  </div>

  <div v-else-if="item">
    <div class="flex flex-wrap items-start justify-between gap-4 mb-5">
      <div class="flex items-start gap-3 min-w-0">
        <button class="btn-ghost btn-icon mt-0.5" @click="router.push({ name: 'test-objects' })">
          <ArrowLeft :size="18" />
        </button>
        <div class="min-w-0">
          <div class="flex items-center gap-3 flex-wrap">
            <h1 class="text-[22px] font-semibold tracking-[-.01em] text-ink">{{ item.name }}</h1>
            <StatusBadge :status="item.status" />
          </div>
          <p class="mt-1 text-[14px] text-ink-muted font-mono">{{ item.identifier }}</p>
        </div>
      </div>

      <button v-if="canDispose" class="btn-outline" @click="confirmOpen = true">
        <Trash :size="16" /> {{ t('testObject.dispose') }}
      </button>
    </div>

    <div class="grid gap-5 lg:grid-cols-[1fr_320px]">
      <div class="space-y-5 min-w-0">
        <!-- Aniqlanadigan elementlar -->
        <section class="panel p-4">
          <h2 class="text-[13px] font-semibold text-ink mb-3">{{ t('sample.elements') }}</h2>
          <div v-if="item.elements?.length" class="flex flex-wrap gap-1.5">
            <span
              v-for="el in item.elements" :key="el.id"
              class="inline-flex items-center gap-1.5 rounded bg-assay-50 dark:bg-assay-900/40 text-assay-700 dark:text-assay-200 px-2.5 py-1 text-[12.5px] font-medium"
            >
              <span class="font-mono">{{ el.symbol }}</span>
              <span class="font-normal">{{ localized(el, 'name') }}</span>
            </span>
          </div>
          <p v-else class="text-[13px] text-ink-faint">{{ t('common.none') }}</p>
        </section>

        <!-- Matnli tavsiflar -->
        <section v-if="texts.length" class="panel p-4">
          <dl class="space-y-3.5">
            <div v-for="[key, value] in texts" :key="key">
              <dt class="text-[11.5px] text-ink-faint">{{ t(key) }}</dt>
              <dd class="text-[13.5px] text-ink mt-0.5 leading-relaxed whitespace-pre-line">{{ value }}</dd>
            </div>
          </dl>
        </section>

        <!-- Hujjatlar (TZ 2.8) -->
        <section class="panel overflow-hidden">
          <DocumentPanel
            :endpoint="`/test-objects/${item.id}/documents`"
            :types="DOC_TYPES"
            :can-upload="canUploadDocs"
            :can-delete="canDeleteDocs"
          />
        </section>
      </div>

      <aside class="panel p-4 h-fit">
        <h2 class="text-[13px] font-semibold text-ink mb-3.5">{{ t('common.details') }}</h2>
        <dl class="space-y-3">
          <div v-for="[key, value] in facts" :key="key">
            <dt class="text-[11.5px] text-ink-faint">{{ t(key) }}</dt>
            <dd class="text-[13.5px] text-ink mt-0.5">{{ value || '—' }}</dd>
          </div>
        </dl>
      </aside>
    </div>

    <ConfirmDialog
      v-model="confirmOpen"
      :title="t('confirm.disposeTitle')"
      :body="t('confirm.disposeBody', { name: item.name })"
      :confirm-label="t('testObject.dispose')"
      :cancel-label="t('common.cancel')"
      :danger="false"
      :loading="saving"
      @confirm="dispose"
    />
  </div>
</template>
