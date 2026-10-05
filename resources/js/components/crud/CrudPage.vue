<script setup>
import { computed, getCurrentInstance, onMounted, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { Download, Pencil, Plus, Search, SlidersHorizontal, Trash2, X } from 'lucide-vue-next'
import { useI18n as useI18nGlobal } from 'vue-i18n'
import { useAuthStore } from '@/stores/auth'
import { useUiStore } from '@/stores/ui'
import { useCrud } from '@/composables/useCrud'
import DataTable from './DataTable.vue'
import FormField from './FormField.vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import Pagination from '@/components/ui/Pagination.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import ConfirmDialog from '@/components/ui/ConfirmDialog.vue'

const props = defineProps({
  resource: { type: Object, required: true },
})
const emit = defineEmits(['row-click', 'saved', 'deleted'])

const { t, locale } = useI18n()
const auth = useAuthStore()
const ui = useUiStore()

const crud = useCrud(props.resource)
const { rows, meta, loading, saving, errors, params } = crud

/* ---------- Ruxsatlar: tugmalar shu asosda ko'rinadi ---------- */
const p = props.resource.permission
const canCreate = computed(() => !p || auth.can(`${p}.create`))
const canUpdate = computed(() => !p || auth.can(`${p}.update`))
const canDelete = computed(() => !p || auth.can(`${p}.delete`))

/* ---------- Forma ---------- */
const formOpen = ref(false)
const editing = ref(null)
const form = reactive({})

const allFields = computed(() =>
  (props.resource.form?.sections ?? []).flatMap((s) => s.fields)
)

function resetForm(row = null) {
  Object.keys(form).forEach((k) => delete form[k])
  allFields.value.forEach((f) => {
    let v = row ? row[f.key] : (f.default ?? null)
    if (f.type === 'multiselect') {
      v = row && f.fromRelation
        ? (row[f.fromRelation] ?? []).map((x) => x.id)
        : (Array.isArray(v) ? v : [])
    }
    if (f.type === 'switch') v = row ? !!row[f.key] : (f.default ?? false)
    if ((f.type === 'date' || f.type === 'datetime-local') && v) v = String(v).slice(0, f.type === 'date' ? 10 : 16)
    form[f.key] = v
  })
  errors.value = {}
}

function openCreate() {
  editing.value = null
  resetForm(null)
  formOpen.value = true
}

function openEdit(row) {
  editing.value = row
  resetForm(row)
  formOpen.value = true
}

async function submit() {
  const payload = { ...form }
  if (props.resource.beforeSave) props.resource.beforeSave(payload, editing.value)
  try {
    const saved = await crud.save(payload, editing.value?.id ?? null)
    formOpen.value = false
    emit('saved', saved)
  } catch { /* xatolar formada ko'rsatiladi */ }
}

/* ---------- O'chirish ---------- */
const confirmOpen = ref(false)
const target = ref(null)

function askDelete(row) {
  target.value = row
  confirmOpen.value = true
}

async function confirmDelete() {
  await crud.remove(target.value.id)
  confirmOpen.value = false
  emit('deleted', target.value)
  target.value = null
}

// Ota-komponent @row-click ni tinglayaptimi — qator bosiladigan bo'ladimi shu hal qiladi
const instance = getCurrentInstance()
const rowsClickable = computed(() => !!instance?.vnode?.props?.onRowClick)

const rowTitle = (row) => props.resource.rowTitle ? props.resource.rowTitle(row) : (row?.name ?? `#${row?.id}`)

/* ---------- Eksport (TZ 2.13) ---------- */
// Joriy filtrlar bilan CSV faylni yuklab olish
const canExport = computed(() =>
  !!props.resource.exportEndpoint && (auth.can('reports.export') || auth.isAdmin)
)

function exportFile() {
  const clean = Object.fromEntries(
    Object.entries(params).filter(([k, v]) =>
      !['page', 'per_page', 'sort_by', 'sort_dir'].includes(k) &&
      v !== '' && v !== null && v !== undefined && v !== false
    )
  )
  clean.locale = locale.value

  const query = new URLSearchParams(clean).toString()
  window.open(`/api${props.resource.exportEndpoint}?${query}`, '_blank')
  ui.info(t('report.exported'))
}

/* ---------- Filtrlar ---------- */
const filtersOpen = ref(false)
const hasFilters = computed(() => (props.resource.filters ?? []).length > 0)

watch(() => props.resource.filters?.map((f) => params[f.key]), () => {
  params.page = 1
  crud.fetch()
}, { deep: true })

onMounted(crud.fetch)

defineExpose({ refresh: crud.fetch, openCreate, openEdit })
</script>

<template>
  <div>
    <PageHeader
      :title="resource.titleKey ? t(resource.titleKey) : resource.title"
      :subtitle="resource.subtitleKey ? t(resource.subtitleKey) : resource.subtitle"
    >
      <template #actions>
        <slot name="header-actions" />
        <button v-if="canCreate && !resource.hideCreate" class="btn-primary" @click="openCreate">
          <Plus :size="17" />
          <span class="hidden sm:inline">{{ resource.createLabelKey ? t(resource.createLabelKey) : t('common.create') }}</span>
        </button>
      </template>
    </PageHeader>

    <!-- Qidiruv va filtrlar -->
    <div class="flex flex-wrap items-center gap-2 mb-4">
      <div v-if="resource.searchable !== false" class="relative flex-1 min-w-[220px] max-w-md">
        <Search :size="16" class="absolute left-3 top-1/2 -translate-y-1/2 text-ink-faint pointer-events-none" />
        <input
          v-model="params.search"
          type="search"
          class="field-input pl-9"
          :placeholder="t('common.searchPlaceholder')"
        />
      </div>

      <button
        v-if="hasFilters"
        class="btn-outline"
        :class="{ 'border-assay-400 text-assay-600 dark:text-assay-300': filtersOpen || crud.activeFilterCount.value }"
        @click="filtersOpen = !filtersOpen"
      >
        <SlidersHorizontal :size="16" />
        {{ t('common.filters') }}
        <span
          v-if="crud.activeFilterCount.value"
          class="ml-0.5 h-5 min-w-5 px-1 rounded-full bg-assay-500 text-white text-[11px] font-semibold grid place-items-center tnum"
        >{{ crud.activeFilterCount.value }}</span>
      </button>

      <button
        v-if="crud.activeFilterCount.value || params.search"
        class="btn-ghost btn-sm"
        @click="crud.resetFilters(); crud.fetch()"
      >
        <X :size="15" /> {{ t('common.resetFilters') }}
      </button>

      <button v-if="canExport" class="btn-outline" @click="exportFile">
        <Download :size="16" />
        <span class="hidden sm:inline">{{ t('report.export') }}</span>
      </button>

      <slot name="toolbar" />
    </div>

    <Transition
      enter-active-class="transition-all duration-200 ease-out overflow-hidden"
      enter-from-class="opacity-0 max-h-0" enter-to-class="opacity-100 max-h-80"
      leave-active-class="transition-all duration-150 ease-in overflow-hidden"
      leave-from-class="opacity-100 max-h-80" leave-to-class="opacity-0 max-h-0"
    >
      <div v-if="filtersOpen && hasFilters" class="panel p-4 mb-4">
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
          <FormField
            v-for="f in resource.filters" :key="f.key"
            :field="f"
            :form="params"
            v-model="params[f.key]"
          />
        </div>
      </div>
    </Transition>

    <!-- Jadval -->
    <DataTable
      :columns="resource.columns"
      :rows="rows"
      :loading="loading"
      :sort-by="params.sort_by"
      :sort-dir="params.sort_dir"
      :searching="!!params.search || crud.activeFilterCount.value > 0"
      :clickable="rowsClickable"
      @sort="crud.setSort"
      @row-click="emit('row-click', $event)"
    >
      <!-- Maxsus kataklarni uzatish -->
      <template v-for="(_, name) in $slots" #[name]="slotData">
        <slot :name="name" v-bind="slotData" />
      </template>

      <template #row-actions="{ row }">
        <slot name="extra-actions" :row="row" />
        <button
          v-if="canUpdate && !resource.hideEdit"
          class="btn-ghost btn-sm btn-icon" :title="t('common.edit')"
          @click="openEdit(row)"
        >
          <Pencil :size="15" />
        </button>
        <button
          v-if="canDelete && !resource.hideDelete"
          class="btn-ghost btn-sm btn-icon hover:text-crit" :title="t('common.delete')"
          @click="askDelete(row)"
        >
          <Trash2 :size="15" />
        </button>
      </template>

      <template #empty-action>
        <button v-if="canCreate && !resource.hideCreate" class="btn-primary" @click="openCreate">
          <Plus :size="17" /> {{ resource.createLabelKey ? t(resource.createLabelKey) : t('common.create') }}
        </button>
      </template>

      <template #footer>
        <Pagination
          :meta="meta"
          :per-page="params.per_page"
          @change="params.page = $event"
          @per-page="params.per_page = $event; params.page = 1"
        />
      </template>
    </DataTable>

    <!-- Yaratish / tahrirlash formasi -->
    <BaseModal
      v-model="formOpen"
      :size="resource.form?.size ?? 'lg'"
      :title="editing
        ? `${t('common.edit')} · ${rowTitle(editing)}`
        : (resource.createLabelKey ? t(resource.createLabelKey) : t('common.create'))"
      :subtitle="resource.itemTitleKey ? t(resource.itemTitleKey) : null"
    >
      <form class="space-y-6" @submit.prevent="submit">
        <section v-for="(section, si) in resource.form?.sections ?? []" :key="si">
          <h3
            v-if="section.titleKey"
            class="text-[13px] font-semibold text-ink mb-3 pb-2 border-b border-line"
          >{{ t(section.titleKey) }}</h3>

          <div class="grid gap-4 sm:grid-cols-2">
            <FormField
              v-for="f in section.fields" :key="f.key"
              :field="f"
              :form="form"
              :error="errors[f.key]"
              v-model="form[f.key]"
            />
          </div>
        </section>

        <button type="submit" class="hidden" />
      </form>

      <template #footer>
        <button class="btn-ghost" @click="formOpen = false">{{ t('common.cancel') }}</button>
        <button class="btn-primary" :disabled="saving" @click="submit">
          {{ saving ? t('common.loading') : t('common.save') }}
        </button>
      </template>
    </BaseModal>

    <ConfirmDialog
      v-model="confirmOpen"
      :title="t('confirm.deleteTitle')"
      :body="t('confirm.deleteBody', { name: rowTitle(target) })"
      :confirm-label="t('confirm.deleteAction')"
      :cancel-label="t('common.cancel')"
      :loading="saving"
      @confirm="confirmDelete"
    />
  </div>
</template>
