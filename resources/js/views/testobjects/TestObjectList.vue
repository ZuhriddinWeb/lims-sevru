<script setup>
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { Trash } from 'lucide-vue-next'
import api from '@/lib/api'
import CrudPage from '@/components/crud/CrudPage.vue'
import ConfirmDialog from '@/components/ui/ConfirmDialog.vue'
import { testObjectResource } from '@/resources/lab'
import { useAuthStore } from '@/stores/auth'
import { useUiStore } from '@/stores/ui'

const { t } = useI18n()
const router = useRouter()
const auth = useAuthStore()
const ui = useUiStore()

const page = ref(null)
const confirmOpen = ref(false)
const target = ref(null)
const saving = ref(false)

function askDispose(row) {
  target.value = row
  confirmOpen.value = true
}

async function dispose() {
  saving.value = true
  try {
    await api.patch(`/test-objects/${target.value.id}/dispose`, {})
    ui.success(t('testObject.disposed'))
    confirmOpen.value = false
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
      :resource="testObjectResource"
      @row-click="router.push({ name: 'test-object-detail', params: { id: $event.id } })"
    >
      <template #extra-actions="{ row }">
        <button
          v-if="auth.can('test_objects.update') && row.status !== 'disposed'"
          class="btn-ghost btn-sm btn-icon" :title="t('testObject.dispose')"
          @click="askDispose(row)"
        >
          <Trash :size="15" />
        </button>
      </template>
    </CrudPage>

    <ConfirmDialog
      v-model="confirmOpen"
      :title="t('confirm.disposeTitle')"
      :body="t('confirm.disposeBody', { name: target?.name ?? '' })"
      :confirm-label="t('testObject.dispose')"
      :cancel-label="t('common.cancel')"
      :danger="false"
      :loading="saving"
      @confirm="dispose"
    />
  </div>
</template>
