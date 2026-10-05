<script setup>
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { Archive, PlayCircle } from 'lucide-vue-next'
import api from '@/lib/api'
import CrudPage from '@/components/crud/CrudPage.vue'
import ConfirmDialog from '@/components/ui/ConfirmDialog.vue'
import { waterOrderResource } from '@/resources/lab'
import { useAuthStore } from '@/stores/auth'
import { useUiStore } from '@/stores/ui'

const router = useRouter()
const { t } = useI18n()
const auth = useAuthStore()
const ui = useUiStore()

const page = ref(null)
const saving = ref(false)

/* Ishga olish */
async function takeToWork(row) {
  saving.value = true
  try {
    await api.patch(`/water-orders/${row.id}/take`, {})
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
const target = ref(null)

async function archive() {
  saving.value = true
  try {
    await api.patch(`/water-orders/${target.value.id}/archive`, {})
    ui.success(t('toast.updated'))
    archiveOpen.value = false
    page.value?.refresh()
  } catch (e) {
    ui.error(e.response?.data?.message || t('toast.error'))
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <div>
    <CrudPage
      ref="page"
      :resource="waterOrderResource"
      @row-click="router.push({ name: 'water-order-detail', params: { id: $event.id } })"
      @saved="(o) => o?.id && router.push({ name: 'water-order-detail', params: { id: o.id } })"
    >
      <template #extra-actions="{ row }">
        <button
          v-if="(auth.can('water_orders.result') || auth.can('water_orders.update')) && row.status === 'registered' && !row.started_at"
          class="btn-ghost btn-sm btn-icon" :title="t('work.take')"
          @click="takeToWork(row)"
        >
          <PlayCircle :size="15" />
        </button>
        <button
          v-if="auth.can('water_orders.update') && row.status === 'completed'"
          class="btn-ghost btn-sm btn-icon" :title="t('sample.archive')"
          @click="target = row; archiveOpen = true"
        >
          <Archive :size="15" />
        </button>
      </template>
    </CrudPage>

    <ConfirmDialog
      v-model="archiveOpen"
      :title="t('confirm.archiveTitle')"
      :body="t('confirm.archiveBody', { name: target?.order_number ?? '' })"
      :confirm-label="t('sample.archive')"
      :cancel-label="t('common.cancel')"
      :danger="false"
      :loading="saving"
      @confirm="archive"
    />
  </div>
</template>
