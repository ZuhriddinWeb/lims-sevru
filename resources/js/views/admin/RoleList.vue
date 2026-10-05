<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { Check, KeyRound, Pencil, Plus, Trash2 } from 'lucide-vue-next'
import api from '@/lib/api'
import PageHeader from '@/components/ui/PageHeader.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import ConfirmDialog from '@/components/ui/ConfirmDialog.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import TableSkeleton from '@/components/ui/TableSkeleton.vue'
import { useAuthStore } from '@/stores/auth'
import { useUiStore } from '@/stores/ui'

const { t, te, locale } = useI18n()
const auth = useAuthStore()
const ui = useUiStore()

const roles = ref([])
const permissionGroups = ref([])
const loading = ref(true)
const saving = ref(false)

async function load() {
  loading.value = true
  try {
    const [r, p] = await Promise.all([
      api.get('/roles', { params: { per_page: 100 } }),
      api.get('/permissions'),
    ])
    roles.value = r.data.data ?? r.data
    permissionGroups.value = p.data
  } catch {
    ui.error(t('toast.error'))
  } finally {
    loading.value = false
  }
}
onMounted(load)

/* Modul × amal matritsasi — ruxsatlarni shu ko'rinishda belgilash qulay */
const ABILITIES = ['view', 'create', 'update', 'delete', 'result', 'manage']

const matrix = computed(() =>
  permissionGroups.value.map((g) => ({
    module: g.module,
    label: te(`menu.${g.module}`) ? t(`menu.${g.module}`) : g.module,
    cells: ABILITIES.map((ability) => ({
      ability,
      permission: g.permissions.find((p) => p.ability === ability) ?? null,
    })),
  }))
)

const ABILITY_LABELS = {
  view: 'common.search', create: 'common.create', update: 'common.edit',
  delete: 'common.delete', result: 'sample.enterResult', manage: 'menuAdmin.titleField',
}

/* Forma */
const formOpen = ref(false)
const editing = ref(null)
const selected = ref(new Set())
const form = reactive({
  name: '', display_name_ru: '', display_name_uz: '', display_name_en: '', description: '',
})

function openCreate() {
  editing.value = null
  Object.assign(form, { name: '', display_name_ru: '', display_name_uz: '', display_name_en: '', description: '' })
  selected.value = new Set()
  formOpen.value = true
}

function openEdit(role) {
  editing.value = role
  Object.assign(form, {
    name: role.name,
    display_name_ru: role.display_name_ru ?? '',
    display_name_uz: role.display_name_uz ?? '',
    display_name_en: role.display_name_en ?? '',
    description: role.description ?? '',
  })
  selected.value = new Set((role.permissions ?? []).map((p) => p.id))
  formOpen.value = true
}

function toggle(id) {
  const next = new Set(selected.value)
  next.has(id) ? next.delete(id) : next.add(id)
  selected.value = next
}

function toggleModule(row) {
  const ids = row.cells.filter((c) => c.permission).map((c) => c.permission.id)
  const allOn = ids.every((id) => selected.value.has(id))
  const next = new Set(selected.value)
  ids.forEach((id) => (allOn ? next.delete(id) : next.add(id)))
  selected.value = next
}

function setAll(on) {
  selected.value = on
    ? new Set(permissionGroups.value.flatMap((g) => g.permissions.map((p) => p.id)))
    : new Set()
}

async function save() {
  saving.value = true
  try {
    const payload = { ...form, permission_ids: [...selected.value] }
    if (editing.value) {
      await api.put(`/roles/${editing.value.id}`, payload)
    } else {
      await api.post('/roles', payload)
    }
    ui.success(t('role.savedPermissions'))
    formOpen.value = false
    await load()
    await auth.fetchUser() // o'z ruxsatlari o'zgargan bo'lishi mumkin
  } catch (e) {
    ui.error(e.response?.data?.message || t('toast.error'))
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
    await api.delete(`/roles/${target.value.id}`)
    ui.success(t('toast.deleted'))
    confirmOpen.value = false
    await load()
  } catch (e) {
    ui.error(e.response?.data?.message || t('toast.error'))
  } finally {
    saving.value = false
  }
}

function roleLabel(role) {
  return role[`display_name_${locale.value}`] || role.display_name_ru || role.name
}

const canCreate = computed(() => auth.can('roles.create'))
const canUpdate = computed(() => auth.can('roles.update'))
const canDelete = computed(() => auth.can('roles.delete'))
</script>

<template>
  <div>
    <PageHeader :title="t('role.title')" :subtitle="t('role.subtitle')">
      <template #actions>
        <button v-if="canCreate" class="btn-primary" @click="openCreate">
          <Plus :size="17" /> {{ t('common.create') }}
        </button>
      </template>
    </PageHeader>

    <div class="panel overflow-hidden">
      <TableSkeleton v-if="loading" :cols="4" />
      <EmptyState v-else-if="!roles.length" :icon="KeyRound" :title="t('common.noData')" />

      <table v-else class="w-full text-left">
        <thead>
          <tr class="border-b border-line bg-surface-sunken/50 text-[12.5px] font-semibold text-ink-muted">
            <th class="px-4 py-2.5">{{ t('role.displayName') }}</th>
            <th class="px-4 py-2.5">{{ t('role.name') }}</th>
            <th class="px-4 py-2.5">{{ t('role.permissions') }}</th>
            <th class="px-4 py-2.5 text-right">{{ t('role.usersCount') }}</th>
            <th class="px-4 py-2.5 w-px" />
          </tr>
        </thead>
        <tbody class="divide-y divide-line">
          <tr v-for="(role, i) in roles" :key="role.id"
              class="group hover:bg-surface-sunken/60 transition-colors animate-row-in"
              :style="{ animationDelay: `${i * 30}ms` }">
            <td class="px-4 py-3">
              <span class="flex items-center gap-2">
                <span class="text-[13.5px] font-medium text-ink">{{ roleLabel(role) }}</span>
                <span v-if="role.is_system"
                      class="text-[10.5px] px-1.5 py-0.5 rounded bg-surface-sunken text-ink-faint"
                      :title="t('role.systemRoleHint')">{{ t('role.systemRole') }}</span>
              </span>
              <span v-if="role.description" class="block text-[12px] text-ink-faint mt-0.5">{{ role.description }}</span>
            </td>
            <td class="px-4 py-3 text-[13px] text-ink-muted font-mono">{{ role.name }}</td>
            <td class="px-4 py-3 text-[13px] text-ink-muted tnum">
              {{ role.name === 'admin' ? t('common.all') : (role.permissions?.length ?? 0) }}
            </td>
            <td class="px-4 py-3 text-right text-[13px] text-ink-muted tnum">{{ role.users_count ?? 0 }}</td>
            <td class="px-4 py-3 text-right whitespace-nowrap">
              <div class="flex items-center justify-end gap-0.5 md:opacity-0 md:group-hover:opacity-100 transition-opacity">
                <button v-if="canUpdate" class="btn-ghost btn-sm btn-icon" :title="t('common.edit')" @click="openEdit(role)">
                  <Pencil :size="15" />
                </button>
                <button v-if="canDelete && !role.is_system" class="btn-ghost btn-sm btn-icon hover:text-crit"
                        :title="t('common.delete')" @click="target = role; confirmOpen = true">
                  <Trash2 :size="15" />
                </button>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Rol formasi + ruxsat matritsasi -->
    <BaseModal
      v-model="formOpen"
      size="xl"
      :title="editing ? `${t('common.edit')} · ${roleLabel(editing)}` : t('common.create')"
      :subtitle="t('role.one')"
    >
      <div class="grid gap-4 sm:grid-cols-2 mb-6">
        <div>
          <label class="field-label">{{ t('role.name') }} <span class="text-crit">*</span></label>
          <input v-model="form.name" class="field-input font-mono" :disabled="editing?.is_system" />
          <p class="mt-1.5 text-[12.5px] text-ink-faint">{{ t('role.nameHint') }}</p>
        </div>
        <div>
          <label class="field-label">{{ t('role.displayName') }} (RU)</label>
          <input v-model="form.display_name_ru" class="field-input" />
        </div>
        <div>
          <label class="field-label">{{ t('role.displayName') }} (UZ)</label>
          <input v-model="form.display_name_uz" class="field-input" />
        </div>
        <div>
          <label class="field-label">{{ t('role.displayName') }} (EN)</label>
          <input v-model="form.display_name_en" class="field-input" />
        </div>
        <div class="sm:col-span-2">
          <label class="field-label">{{ t('role.description') }}</label>
          <input v-model="form.description" class="field-input" />
        </div>
      </div>

      <div class="flex items-center justify-between gap-3 pb-2.5 border-b border-line">
        <div>
          <h3 class="text-[13px] font-semibold text-ink">{{ t('role.permissions') }}</h3>
          <p class="text-[12.5px] text-ink-faint mt-0.5">{{ t('role.permissionsHint') }}</p>
        </div>
        <div class="flex items-center gap-1.5 shrink-0">
          <button class="btn-ghost btn-sm" @click="setAll(true)">{{ t('role.selectAll') }}</button>
          <button class="btn-ghost btn-sm" @click="setAll(false)">{{ t('role.clearAll') }}</button>
        </div>
      </div>

      <div class="overflow-x-auto -mx-5 px-5">
        <table class="w-full text-left min-w-[620px]">
          <thead>
            <tr class="text-[11.5px] font-semibold text-ink-faint">
              <th class="py-2.5 pr-4">{{ t('role.moduleAccess') }}</th>
              <th v-for="a in ABILITIES" :key="a" class="py-2.5 px-2 text-center w-[78px]">
                {{ t(ABILITY_LABELS[a]) }}
              </th>
            </tr>
          </thead>
          <tbody class="divide-y divide-line">
            <tr v-for="row in matrix" :key="row.module" class="hover:bg-surface-sunken/50 transition-colors">
              <td class="py-2.5 pr-4">
                <button class="text-[13.5px] text-ink hover:text-assay-600 dark:hover:text-assay-300 transition-colors text-left"
                        @click="toggleModule(row)">
                  {{ row.label }}
                </button>
              </td>
              <td v-for="cell in row.cells" :key="cell.ability" class="py-2.5 px-2 text-center">
                <button
                  v-if="cell.permission"
                  type="button"
                  class="h-5 w-5 rounded border grid place-items-center transition-all active:scale-90"
                  :class="selected.has(cell.permission.id)
                    ? 'bg-assay-500 border-assay-500 text-white'
                    : 'border-line hover:border-assay-400'"
                  :aria-label="cell.permission.name"
                  @click="toggle(cell.permission.id)"
                >
                  <Check v-if="selected.has(cell.permission.id)" :size="12" :stroke-width="3" />
                </button>
                <span v-else class="text-ink-faint/40 text-[13px]">·</span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <template #footer>
        <button class="btn-ghost" @click="formOpen = false">{{ t('common.cancel') }}</button>
        <button class="btn-primary" :disabled="saving || !form.name" @click="save">
          {{ saving ? t('common.loading') : t('common.save') }}
        </button>
      </template>
    </BaseModal>

    <ConfirmDialog
      v-model="confirmOpen"
      :title="t('confirm.deleteTitle')"
      :body="t('confirm.deleteBody', { name: target ? roleLabel(target) : '' })"
      :confirm-label="t('confirm.deleteAction')"
      :cancel-label="t('common.cancel')"
      :loading="saving"
      @confirm="remove"
    />
  </div>
</template>
