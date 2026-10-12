<script setup>
import { onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { Archive, PlayCircle, Send } from 'lucide-vue-next'
import api from '@/lib/api'
import CrudPage from '@/components/crud/CrudPage.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import ConfirmDialog from '@/components/ui/ConfirmDialog.vue'
import FormField from '@/components/crud/FormField.vue'
import { sampleResource } from '@/resources/lab'
import { useAuthStore } from '@/stores/auth'
import { useUiStore } from '@/stores/ui'
import { useCatalogStore } from '@/stores/catalogs'
// Ishchi varaqlar moduli: jadvaldan chop etish (olib tashlansa — shu qator va pastdagi bitta teg o'chiriladi)
import SampleWorksheetPrintButton from '@/modules/worksheets/components/SampleWorksheetPrintButton.vue'

const router = useRouter()
const { t } = useI18n()
const auth = useAuthStore()
const ui = useUiStore()
const catalogs = useCatalogStore()

const page = ref(null)
const saving = ref(false)

/* Guruhga uzatish */
const assignOpen = ref(false)
const target = ref(null)
const assignGroupId = ref(null)

const assignField = {
  key: 'assigned_group_id',
  type: 'relation',
  labelKey: 'sample.assignedGroup',
  source: 'executorGroups',
  required: true,
}

function askAssign(row) {
  target.value = row
  assignGroupId.value = row.assigned_group_id ?? null
  assignOpen.value = true
}

async function assign() {
  if (!assignGroupId.value) return
  saving.value = true
  try {
    await api.patch(`/samples/${target.value.id}/assign`, { assigned_group_id: assignGroupId.value })
    ui.success(t('toast.updated'))
    assignOpen.value = false
    page.value?.refresh()
  } catch (e) {
    ui.error(e.response?.data?.message || t('toast.error'))
  } finally {
    saving.value = false
  }
}

/* Ishga olish */
async function takeToWork(row) {
  saving.value = true
  try {
    await api.patch(`/samples/${row.id}/take`, {})
    ui.success(t('work.taken'))
    page.value?.refresh()
  } catch (e) {
    ui.error(e.response?.data?.message || t('toast.error'))
  } finally {
    saving.value = false
  }
}

/* Arxivlash */
const archiveOpen = ref(false)

function askArchive(row) {
  target.value = row
  archiveOpen.value = true
}

async function archive() {
  saving.value = true
  try {
    await api.patch(`/samples/${target.value.id}/archive`, {})
    ui.success(t('toast.updated'))
    archiveOpen.value = false
    page.value?.refresh()
  } catch (e) {
    ui.error(e.response?.data?.message || t('toast.error'))
  } finally {
    saving.value = false
  }
}

onMounted(() => {
  catalogs.load('executorGroups')
  catalogs.load('elements')
})
</script>

<template>
  <div>
    <CrudPage
      ref="page"
      :resource="sampleResource"
      @row-click="router.push({ name: 'sample-detail', params: { id: $event.id } })"
      @saved="(s) => s?.id && router.push({ name: 'sample-detail', params: { id: s.id } })"
    >
      <!-- Tayyorlik darajasi — ingichka indikator -->
      <template #cell:progress="{ row }">
        <span class="inline-flex items-center gap-2 justify-end">
          <span class="h-1 w-14 rounded-full bg-surface-sunken overflow-hidden hidden lg:inline-block">
            <span
              class="block h-full rounded-full transition-[width] duration-500"
              :class="row.progress === 100 ? 'bg-ok' : 'bg-assay-400'"
              :style="{ width: `${row.progress}%` }"
            />
          </span>
          <span class="tnum text-[13px]" :class="row.progress === 100 ? 'text-ok font-medium' : 'text-ink-muted'">
            {{ row.progress }}%
          </span>
        </span>
      </template>

      <template #extra-actions="{ row }">
        <!-- Probaning ishchi varaqlarini chop etish (varag'i bo'lsa ko'rinadi) -->
        <SampleWorksheetPrintButton :sample="row" />
        <button
          v-if="(auth.can('samples.result') || auth.can('samples.update')) && row.status === 'assigned' && !row.started_at"
          class="btn-ghost btn-sm btn-icon" :title="t('work.take')"
          @click="takeToWork(row)"
        >
          <PlayCircle :size="15" />
        </button>
        <button
          v-if="auth.can('samples.update') && row.status !== 'archived'"
          class="btn-ghost btn-sm btn-icon" :title="t('sample.assign')"
          @click="askAssign(row)"
        >
          <Send :size="15" />
        </button>
        <button
          v-if="auth.can('samples.update') && row.status === 'completed'"
          class="btn-ghost btn-sm btn-icon" :title="t('sample.archive')"
          @click="askArchive(row)"
        >
          <Archive :size="15" />
        </button>
      </template>
    </CrudPage>

    <BaseModal v-model="assignOpen" size="sm" :title="t('sample.assignTitle')" :subtitle="target?.code">
      <FormField :field="assignField" v-model="assignGroupId" />
      <template #footer>
        <button class="btn-ghost" @click="assignOpen = false">{{ t('common.cancel') }}</button>
        <button class="btn-primary" :disabled="saving || !assignGroupId" @click="assign">{{ t('sample.assign') }}</button>
      </template>
    </BaseModal>

    <ConfirmDialog
      v-model="archiveOpen"
      :title="t('confirm.archiveTitle')"
      :body="t('confirm.archiveBody', { name: target?.code ?? '' })"
      :confirm-label="t('sample.archive')"
      :cancel-label="t('common.cancel')"
      :danger="false"
      :loading="saving"
      @confirm="archive"
    />
  </div>
</template>
