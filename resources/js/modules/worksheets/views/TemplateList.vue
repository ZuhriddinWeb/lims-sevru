<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { Copy, LayoutTemplate, Pencil, Plus, Search, Trash2 } from 'lucide-vue-next'
import api from '@/lib/api'
import PageHeader from '@/components/ui/PageHeader.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import ConfirmDialog from '@/components/ui/ConfirmDialog.vue'
import { useUiStore } from '@/stores/ui'
import { apiErrorMessage, templateName } from '../helpers'
import '../i18n'

const { t, locale } = useI18n()
const router = useRouter()
const ui = useUiStore()

const items = ref([])
const elements = ref([])
const loading = ref(true)
const search = ref('')
const showInactive = ref(true)
const toDelete = ref(null)

async function load() {
  loading.value = true
  try {
    const { data } = await api.get('/worksheet-templates')
    items.value = data
  } catch (e) {
    ui.error(apiErrorMessage(e, t('ws.loadError')))
  } finally {
    loading.value = false
  }
}

onMounted(async () => {
  load()
  try {
    const { data } = await api.get('/elements', { params: { all: 1 } })
    elements.value = data
  } catch { /* ixtiyoriy */ }
})

const filtered = computed(() => {
  const q = search.value.trim().toLowerCase()
  return items.value.filter((x) =>
    (showInactive.value || x.is_active)
    && (!q || [x.code, x.name_uz, x.name_ru, x.name_uz_cyrl].some((v) => (v || '').toLowerCase().includes(q))))
})

const symbol = (id) => elements.value.find((e) => e.id === id)?.symbol ?? '?'

async function duplicate(tpl) {
  try {
    const { data } = await api.post(`/worksheet-templates/${tpl.id}/duplicate`)
    ui.success(t('ws.tplDuplicated'))
    router.push({ name: 'worksheet-template-edit', params: { id: data.id } })
  } catch (e) {
    ui.error(apiErrorMessage(e, t('ws.saveError')))
  }
}

async function remove() {
  const tpl = toDelete.value
  toDelete.value = null
  try {
    await api.delete(`/worksheet-templates/${tpl.id}`)
    ui.success(t('ws.tplDeleted'))
    load()
  } catch (e) {
    ui.error(apiErrorMessage(e, t('ws.saveError')))
  }
}
</script>

<template>
  <div>
    <PageHeader :title="t('ws.tplTitle')" :subtitle="t('ws.tplSubtitle')">
      <template #actions>
        <button class="btn-primary" @click="router.push({ name: 'worksheet-template-edit', params: { id: 'new' } })">
          <Plus :size="17" /> {{ t('ws.tplCreate') }}
        </button>
      </template>
    </PageHeader>

    <div class="panel p-3 mb-4 flex flex-wrap items-center gap-3">
      <div class="relative flex-1 min-w-[220px]">
        <Search :size="16" class="absolute left-3 top-1/2 -translate-y-1/2 text-ink-faint" />
        <input v-model="search" class="field-input pl-9" :placeholder="t('common.searchPlaceholder')">
      </div>
      <label class="inline-flex items-center gap-2 text-[13.5px] text-ink-muted cursor-pointer select-none">
        <input v-model="showInactive" type="checkbox" class="rounded border-line text-assay-500 focus:ring-assay-500">
        {{ t('ws.tplShowInactive') }}
      </label>
    </div>

    <div v-if="loading" class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
      <div v-for="i in 3" :key="i" class="h-44 panel animate-pulse" />
    </div>

    <div v-else-if="!filtered.length" class="panel">
      <EmptyState :icon="LayoutTemplate" :title="t('ws.tplEmpty')" />
    </div>

    <div v-else class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
      <article
        v-for="(tpl, i) in filtered" :key="tpl.id"
        class="panel p-4 flex flex-col animate-row-in transition-shadow hover:shadow-pop"
        :class="!tpl.is_active && 'opacity-70'"
        :style="{ animationDelay: `${i * 30}ms` }"
      >
        <div class="flex items-start justify-between gap-3">
          <span class="rounded-md bg-assay-50 dark:bg-assay-800/30 text-assay-700 dark:text-assay-200 px-2 py-1 font-mono text-[12.5px] font-semibold">
            {{ tpl.code }}
          </span>
          <span
            class="rounded-full px-2 py-0.5 text-[11px] font-medium"
            :class="tpl.is_active ? 'bg-ok-light text-ok-dark dark:bg-ok-dark/25 dark:text-ok-light' : 'bg-surface-sunken text-ink-faint'"
          >{{ tpl.is_active ? t('ws.tplActive') : t('ws.tplInactive') }}</span>
        </div>
        <h3 class="mt-3 text-[16px] font-semibold text-ink leading-snug">{{ templateName(tpl, locale) }}</h3>
        <p v-if="tpl.name_uz_cyrl" class="text-[12.5px] text-ink-faint">{{ tpl.name_uz_cyrl }}</p>
        <div class="mt-3 flex flex-wrap gap-1.5 text-[12px]">
          <span class="rounded bg-surface-sunken px-2 py-0.5 text-ink-muted">{{ t('ws.tplColumns', { n: tpl.columns_count }) }}</span>
          <span class="rounded bg-surface-sunken px-2 py-0.5 text-ink-muted">{{ t('ws.tplWorksheets', { n: tpl.worksheets_count }) }}</span>
          <span v-if="tpl.number_suffix" class="rounded bg-surface-sunken px-2 py-0.5 text-ink-muted font-mono">№ …{{ tpl.number_suffix }}</span>
          <span v-for="id in tpl.result_element_ids" :key="id" class="rounded bg-assay-50 dark:bg-assay-800/30 px-2 py-0.5 text-assay-700 dark:text-assay-200 font-mono">
            → {{ symbol(id) }}
          </span>
        </div>
        <div class="mt-auto pt-4 flex items-center gap-1.5">
          <button class="btn-outline btn-sm flex-1" @click="router.push({ name: 'worksheet-template-edit', params: { id: tpl.id } })">
            <Pencil :size="14" /> {{ t('ws.tplEdit') }}
          </button>
          <button class="btn-ghost btn-sm" :title="t('ws.tplDuplicate')" @click="duplicate(tpl)"><Copy :size="15" /></button>
          <button class="btn-ghost btn-sm text-crit" :title="t('ws.tplDelete')" @click="toDelete = tpl"><Trash2 :size="15" /></button>
        </div>
      </article>
    </div>

    <ConfirmDialog
      :model-value="!!toDelete" :title="t('ws.tplDelete')" :body="t('ws.tplDeleteConfirm')"
      :confirm-label="t('ws.tplDelete')" :cancel-label="t('common.cancel')"
      @update:model-value="toDelete = null" @confirm="remove"
    />
  </div>
</template>
