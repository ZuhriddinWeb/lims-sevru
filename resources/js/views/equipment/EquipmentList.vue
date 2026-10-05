<script setup>
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { Lock, LockOpen } from 'lucide-vue-next'
import api from '@/lib/api'
import CrudPage from '@/components/crud/CrudPage.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import { equipmentResource } from '@/resources/lab'
import { useAuthStore } from '@/stores/auth'
import { useUiStore } from '@/stores/ui'

const router = useRouter()
const auth = useAuthStore()
const ui = useUiStore()
const { t } = useI18n()

const page = ref(null)
const blockOpen = ref(false)
const target = ref(null)
const reason = ref('')
const saving = ref(false)

function askBlock(row) {
  target.value = row
  reason.value = row.block_reason ?? ''
  if (row.is_blocked) {
    toggle(row, false)
  } else {
    blockOpen.value = true
  }
}

async function toggle(row, blocked) {
  saving.value = true
  try {
    await api.patch(`/equipment/${row.id}/block`, {
      is_blocked: blocked,
      block_reason: blocked ? reason.value : null,
    })
    ui.success(t('toast.updated'))
    blockOpen.value = false
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
      :resource="equipmentResource"
      @row-click="router.push({ name: 'equipment-detail', params: { id: $event.id } })"
    >
      <!-- Bloklangan uskunani nomida belgilash -->
      <template #cell:name="{ row, value }">
        <span class="flex items-center gap-2">
          <Lock v-if="row.is_blocked" :size="13" class="text-crit shrink-0" :title="row.block_reason" />
          {{ value }}
        </span>
      </template>

      <template #extra-actions="{ row }">
        <button
          v-if="auth.can('equipment.update')"
          class="btn-ghost btn-sm btn-icon"
          :class="row.is_blocked ? 'text-crit' : ''"
          :title="row.is_blocked ? t('equipment.unblock') : t('equipment.block')"
          @click="askBlock(row)"
        >
          <component :is="row.is_blocked ? Lock : LockOpen" :size="15" />
        </button>
      </template>
    </CrudPage>

    <BaseModal v-model="blockOpen" size="sm" :title="t('equipment.block')" :subtitle="target?.name">
      <p class="text-[13.5px] text-ink-muted mb-4 leading-relaxed">{{ t('equipment.blockHint') }}</p>
      <label class="field-label" for="reason">{{ t('equipment.blockReason') }}</label>
      <input id="reason" v-model="reason" class="field-input" />

      <template #footer>
        <button class="btn-ghost" @click="blockOpen = false">{{ t('common.cancel') }}</button>
        <button class="btn-danger" :disabled="saving" @click="toggle(target, true)">{{ t('equipment.block') }}</button>
      </template>
    </BaseModal>
  </div>
</template>
