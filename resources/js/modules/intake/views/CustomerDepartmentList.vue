<script setup>
/**
 * «Buyurtmachi bo'limlar» katalogi: zavoddagi proba topshiradigan bo'limlar
 * va ularga biriktirilgan portal («Buyurtmachi» roli) foydalanuvchilari.
 */
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { Check, Eye, Factory, Pencil, Phone, Plus, Search, Trash2, User } from 'lucide-vue-next'
import api from '@/lib/api'
import PageHeader from '@/components/ui/PageHeader.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import ConfirmDialog from '@/components/ui/ConfirmDialog.vue'
import { useAuthStore } from '@/stores/auth'
import { useUiStore } from '@/stores/ui'
import { apiErrorMessage } from '../helpers'
import { refreshDepartmentOptions } from '../sampleForm'
import '../i18n'

const { t } = useI18n()
const router = useRouter()
const auth = useAuthStore()
const ui = useUiStore()

const items = ref([])
const loading = ref(true)
const search = ref('')
const canManage = computed(() => auth.can('customer_departments.manage'))
const canPreview = computed(() => auth.can('customer_departments.view'))
let timer = null

async function load() {
  loading.value = true
  try {
    const { data } = await api.get('/customer-departments', { params: { search: search.value || undefined } })
    items.value = data
  } catch (e) {
    ui.error(apiErrorMessage(e, t('intake.loadError')))
  } finally {
    loading.value = false
  }
}
onMounted(load)
watch(search, () => {
  clearTimeout(timer)
  timer = setTimeout(load, 300)
})

/* ---------------------------------------------------------- forma */
const formOpen = ref(false)
const editing = ref(null)
const form = reactive({ code: '', name: '', contact_person: '', phone: '', notes: '', is_active: true, user_ids: [] })
const errors = ref({})
const saving = ref(false)
const portalUsers = ref([])
const usersLoaded = ref(false)

async function loadPortalUsers() {
  if (!canManage.value) return
  try {
    const { data } = await api.get('/customer-departments/portal-users')
    portalUsers.value = data
  } catch {
    portalUsers.value = []
  } finally {
    usersLoaded.value = true
  }
}

function openForm(row = null) {
  editing.value = row
  Object.assign(form, {
    code: row?.code ?? '', name: row?.name ?? '', contact_person: row?.contact_person ?? '',
    phone: row?.phone ?? '', notes: row?.notes ?? '', is_active: row ? !!row.is_active : true,
    user_ids: (row?.user_ids ?? []).map(Number),
  })
  errors.value = {}
  formOpen.value = true
  loadPortalUsers()
}

function toggleUser(id) {
  form.user_ids = form.user_ids.includes(id) ? form.user_ids.filter((x) => x !== id) : [...form.user_ids, id]
}
// Foydalanuvchi boshqa bo'limga ham biriktirilganmi (ma'lumot uchun)
const elsewhere = (u) => (u.departments ?? []).some((d) => Number(d) !== Number(editing.value?.id))

async function save() {
  saving.value = true
  try {
    const payload = { ...form, code: form.code || null }
    if (editing.value) await api.put(`/customer-departments/${editing.value.id}`, payload)
    else await api.post('/customer-departments', payload)
    formOpen.value = false
    ui.success(t('intake.saved'))
    refreshDepartmentOptions() // proba formasidagi ro'yxat ham yangilansin
    load()
  } catch (e) {
    errors.value = e?.response?.data?.errors ?? {}
    ui.error(apiErrorMessage(e, t('intake.saveError')))
  } finally {
    saving.value = false
  }
}

const err = (k) => (errors.value[k] ? errors.value[k][0] : '')

/* ------------------------------------------------------- o'chirish */
const target = ref(null)
async function destroy() {
  const row = target.value
  target.value = null
  if (!row) return
  try {
    await api.delete(`/customer-departments/${row.id}`)
    ui.success(t('intake.deps.deleted'))
    refreshDepartmentOptions()
    load()
  } catch (e) {
    ui.error(apiErrorMessage(e, t('intake.saveError')))
  }
}

function preview(row) {
  router.push({ name: 'portal', query: { department_id: row.id } })
}
</script>

<template>
  <div>
    <PageHeader :title="t('intake.deps.title')" :subtitle="t('intake.deps.subtitle')">
      <template #actions>
        <button v-if="canManage" class="btn-primary" data-add @click="openForm()">
          <Plus :size="17" /> {{ t('intake.deps.add') }}
        </button>
      </template>
    </PageHeader>

    <div class="panel p-3 mb-4">
      <div class="relative max-w-md">
        <Search :size="16" class="absolute left-3 top-1/2 -translate-y-1/2 text-ink-faint" />
        <input v-model="search" class="field-input pl-9" :placeholder="t('intake.deps.search')">
      </div>
    </div>

    <div class="panel overflow-hidden">
      <div v-if="loading && !items.length" class="p-4 space-y-2.5">
        <div v-for="i in 4" :key="i" class="h-12 rounded bg-surface-sunken animate-pulse" />
      </div>

      <EmptyState v-else-if="!items.length" :icon="Factory" :title="t('intake.deps.empty')" :hint="t('intake.deps.emptyHint')">
        <button v-if="canManage && !search" class="btn-primary" @click="openForm()"><Plus :size="16" /> {{ t('intake.deps.add') }}</button>
      </EmptyState>

      <div v-else class="overflow-x-auto">
        <table class="w-full text-[13.5px]">
          <thead>
            <tr class="border-b border-line bg-surface-sunken/60 text-left text-[12px] uppercase tracking-wide text-ink-muted">
              <th class="px-4 py-2.5 font-medium">{{ t('intake.deps.name') }}</th>
              <th class="px-4 py-2.5 font-medium">{{ t('intake.deps.contact') }}</th>
              <th class="px-4 py-2.5 font-medium">{{ t('intake.deps.users') }}</th>
              <th class="px-4 py-2.5 font-medium text-right">{{ t('intake.deps.samples') }}</th>
              <th class="px-4 py-2.5 font-medium w-32" />
            </tr>
          </thead>
          <tbody class="divide-y divide-line">
            <tr v-for="row in items" :key="row.id" :class="!row.is_active && 'opacity-60'" :data-dep="row.id">
              <td class="px-4 py-2.5">
                <span class="font-medium text-ink">{{ row.name }}</span>
                <span v-if="row.code" class="ml-1.5 font-mono text-[12px] text-ink-faint">{{ row.code }}</span>
                <span v-if="!row.is_active" class="ml-1.5 rounded-full bg-surface-sunken px-1.5 py-0.5 text-[11px] text-ink-muted">{{ t('intake.deps.inactive') }}</span>
              </td>
              <td class="px-4 py-2.5 text-ink-muted">
                <span v-if="row.contact_person" class="block">{{ row.contact_person }}</span>
                <span v-if="row.phone" class="block text-[12.5px]"><Phone :size="12" class="inline -mt-0.5" /> {{ row.phone }}</span>
                <span v-if="!row.contact_person && !row.phone">—</span>
              </td>
              <td class="px-4 py-2.5">
                <span v-if="!row.users.length" class="text-ink-faint">—</span>
                <span v-for="u in row.users" :key="u.id" class="mr-1 mb-0.5 inline-flex items-center gap-1 rounded-full bg-surface-sunken px-2 py-0.5 text-[12px]" :class="u.is_active ? 'text-ink' : 'text-ink-faint line-through'">
                  <User :size="11" /> {{ u.name }}
                </span>
              </td>
              <td class="px-4 py-2.5 text-right tnum text-ink-muted">{{ row.samples_count }}</td>
              <td class="px-4 py-2.5 text-right whitespace-nowrap">
                <button v-if="canPreview" class="btn-ghost btn-icon h-8 w-8" :title="t('intake.deps.previewPortal')" @click="preview(row)"><Eye :size="16" /></button>
                <button v-if="canManage" class="btn-ghost btn-icon h-8 w-8" :title="t('common.edit')" data-edit @click="openForm(row)"><Pencil :size="15" /></button>
                <button v-if="canManage" class="btn-ghost btn-icon h-8 w-8 text-crit" @click="target = row"><Trash2 :size="15" /></button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Forma -->
    <BaseModal v-model="formOpen" :title="editing ? t('intake.deps.edit') : t('intake.deps.add')" size="lg">
      <div class="grid gap-4 sm:grid-cols-2">
        <div class="sm:col-span-2">
          <label class="field-label">{{ t('intake.deps.name') }} *</label>
          <input v-model="form.name" class="field-input" :class="err('name') && 'field-input-error'" data-f="name">
          <p v-if="err('name')" class="mt-1.5 text-[12.5px] text-crit">{{ err('name') }}</p>
        </div>
        <div>
          <label class="field-label">{{ t('intake.deps.code') }}</label>
          <input v-model="form.code" class="field-input font-mono" :class="err('code') && 'field-input-error'" :placeholder="t('intake.deps.codeHint')" data-f="code">
          <p v-if="err('code')" class="mt-1.5 text-[12.5px] text-crit">{{ err('code') }}</p>
        </div>
        <div class="flex items-end pb-2">
          <label class="inline-flex items-center gap-2 text-[13.5px] text-ink cursor-pointer">
            <input v-model="form.is_active" type="checkbox" class="rounded border-line text-assay-500 focus:ring-assay-500">
            {{ t('intake.deps.active') }}
          </label>
        </div>
        <div>
          <label class="field-label">{{ t('intake.deps.contact') }}</label>
          <input v-model="form.contact_person" class="field-input">
        </div>
        <div>
          <label class="field-label">{{ t('intake.deps.phone') }}</label>
          <input v-model="form.phone" class="field-input">
        </div>
        <div class="sm:col-span-2">
          <label class="field-label">{{ t('intake.deps.notes') }}</label>
          <textarea v-model="form.notes" rows="2" class="field-input" />
        </div>

        <div class="sm:col-span-2">
          <label class="field-label">{{ t('intake.deps.users') }}</label>
          <p class="mb-2 text-[12.5px] text-ink-faint">{{ t('intake.deps.usersHint') }}</p>
          <p v-if="usersLoaded && !portalUsers.length" class="rounded-md bg-surface-sunken px-3 py-2 text-[13px] text-ink-muted" data-no-eligible>{{ t('intake.deps.noEligible') }}</p>
          <div v-else class="max-h-56 overflow-y-auto rounded-md border border-line divide-y divide-line">
            <button
              v-for="u in portalUsers" :key="u.id" type="button"
              class="w-full flex items-center gap-3 px-3 py-2 text-left transition-colors"
              :class="form.user_ids.includes(u.id) ? 'bg-assay-50/60 dark:bg-assay-800/15' : 'hover:bg-surface-sunken/60'"
              :data-user="u.id"
              @click="toggleUser(u.id)"
            >
              <span class="h-[18px] w-[18px] shrink-0 rounded border grid place-items-center" :class="form.user_ids.includes(u.id) ? 'bg-assay-500 border-assay-500 text-white' : 'border-line bg-surface'">
                <Check v-if="form.user_ids.includes(u.id)" :size="12" :stroke-width="3" />
              </span>
              <span class="min-w-0 flex-1">
                <span class="text-[13.5px] text-ink" :class="!u.is_active && 'line-through text-ink-faint'">{{ u.name }}</span>
                <span class="text-[12px] text-ink-faint"> · {{ u.username }}<template v-if="u.position"> · {{ u.position }}</template></span>
              </span>
              <span v-if="elsewhere(u)" class="text-[11.5px] text-ink-faint">{{ t('intake.deps.otherDeps') }}</span>
            </button>
          </div>
          <p v-if="err('user_ids')" class="mt-1.5 text-[12.5px] text-crit">{{ err('user_ids') }}</p>
        </div>
      </div>
      <template #footer>
        <button class="btn-ghost" @click="formOpen = false">{{ t('common.cancel') }}</button>
        <button class="btn-primary" :disabled="saving || !form.name.trim()" data-save @click="save">{{ t('common.save') }}</button>
      </template>
    </BaseModal>

    <ConfirmDialog
      :model-value="!!target" :title="t('intake.deps.deleteTitle')" :body="t('intake.deps.deleteBody', { name: target?.name ?? '' })"
      :confirm-label="t('intake.deps.deleteTitle')" :cancel-label="t('common.cancel')"
      @update:model-value="!$event && (target = null)" @confirm="destroy"
    />
  </div>
</template>
