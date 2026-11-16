<script setup>
/**
 * [Paket 34] «Shablonlarim» sahifasida — bo'linma xodimlari ro'yxati (kim olib keladi).
 * Ro'yxat buyurtmalardan o'zi to'planadi; bu yerda faqat xatoni tuzatish va ortiqchasini o'chirish.
 */
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { Check, Pencil, Search, Trash2, Users, X } from 'lucide-vue-next'
import api from '@/lib/api'
import { useUiStore } from '@/stores/ui'
import { formatDateTime } from '@/lib/format'
import { forgetContacts, loadContacts, matches } from '../contacts'
import { apiErrorMessage, fieldError } from '../shared'
import '../i18nP34'

const props = defineProps({
  departments: { type: Array, default: () => [] },
})
const { t } = useI18n()
const ui = useUiStore()

const dept = ref(props.departments[0]?.id ?? null)
watch(() => props.departments, (v) => { if (!dept.value && v.length) dept.value = v[0].id })
const rows = ref([])
const canWrite = ref(false)
const loading = ref(false)
const q = ref('')
const editing = ref(null) // { id, name, phone }
const errors = ref({})
const busy = ref(false)

const shown = computed(() => rows.value.filter((c) => matches(c, q.value)))

async function load() {
  if (!dept.value) return
  loading.value = true
  const r = await loadContacts(dept.value, true)
  rows.value = r.data
  canWrite.value = r.can_write
  loading.value = false
}
watch(dept, () => { editing.value = null; load() }, { immediate: true })

function edit(c) {
  errors.value = {}
  editing.value = { id: c.id, name: c.name, phone: c.phone ?? '' }
}

async function save() {
  busy.value = true
  errors.value = {}
  try {
    const { data } = await api.put(`/portal/contacts/${editing.value.id}`, { name: editing.value.name, phone: editing.value.phone || null })
    rows.value = rows.value.map((c) => (c.id === data.id ? { ...c, ...data } : c))
    forgetContacts(dept.value)
    editing.value = null
    ui.success(t('sreq.p34.saved'))
  } catch (e) {
    errors.value = e?.response?.data?.errors ?? {}
    if (!Object.keys(errors.value).length) ui.error(apiErrorMessage(e, t('sreq.p34.loadError')))
  } finally {
    busy.value = false
  }
}

async function remove(c) {
  if (!window.confirm(t('sreq.p34.removeConfirm', { name: c.name }))) return
  try {
    await api.delete(`/portal/contacts/${c.id}`)
    rows.value = rows.value.filter((x) => x.id !== c.id)
    forgetContacts(dept.value)
    ui.success(t('sreq.p34.removed'))
  } catch (e) {
    ui.error(apiErrorMessage(e, t('sreq.p34.loadError')))
  }
}
</script>

<template>
  <div class="panel overflow-hidden" data-contacts-panel>
    <div class="px-4 py-3 border-b border-line flex flex-wrap items-center gap-3">
      <h2 class="flex items-center gap-2 text-[15px] font-semibold text-ink"><Users :size="17" /> {{ t('sreq.p34.title') }}</h2>
      <select v-if="departments.length > 1" v-model="dept" class="field-input w-auto min-w-[200px]">
        <option v-for="d in departments" :key="d.id" :value="d.id">{{ d.name }}</option>
      </select>
      <span class="flex-1" />
      <div class="relative w-full sm:w-64">
        <Search :size="14" class="pointer-events-none absolute left-2.5 top-1/2 -translate-y-1/2 text-ink-faint" />
        <input v-model="q" class="field-input pl-8" :placeholder="t('sreq.p34.search')">
      </div>
    </div>
    <p class="px-4 pt-3 text-[13px] leading-relaxed text-ink-muted max-w-3xl">{{ t('sreq.p34.hint') }}</p>

    <div v-if="loading" class="p-4"><div class="h-16 rounded-md bg-surface-sunken animate-pulse" /></div>
    <p v-else-if="!rows.length" class="px-4 py-6 text-[13.5px] text-ink-faint">{{ t('sreq.p34.empty') }}</p>
    <div v-else class="overflow-x-auto mt-2">
      <table class="w-full text-[13.5px]">
        <thead>
          <tr class="text-left text-[11.5px] uppercase tracking-wide text-ink-faint border-b border-line">
            <th class="px-4 py-2 font-medium">{{ t('sreq.p34.colName') }}</th>
            <th class="px-4 py-2 font-medium">{{ t('sreq.p34.colPhone') }}</th>
            <th class="px-4 py-2 font-medium text-right">{{ t('sreq.p34.colUsed') }}</th>
            <th class="px-4 py-2 font-medium">{{ t('sreq.p34.colLast') }}</th>
            <th v-if="canWrite" class="px-4 py-2" />
          </tr>
        </thead>
        <tbody class="divide-y divide-line">
          <tr v-for="c in shown" :key="c.id" :data-contact-row="c.id">
            <template v-if="editing?.id === c.id">
              <td class="px-4 py-2">
                <input v-model="editing.name" class="field-input" maxlength="191" :class="fieldError(errors, 'name') && 'field-input-error'" data-edit-name>
                <p v-if="fieldError(errors, 'name')" class="mt-1 text-[12px] text-crit">{{ fieldError(errors, 'name') }}</p>
              </td>
              <td class="px-4 py-2"><input v-model="editing.phone" class="field-input" maxlength="64" inputmode="tel" data-edit-phone></td>
              <td class="px-4 py-2 text-right tnum text-ink-muted">{{ c.use_count }}</td>
              <td class="px-4 py-2 text-ink-muted">{{ c.last_used_at ? formatDateTime(c.last_used_at) : '—' }}</td>
              <td class="px-4 py-2 whitespace-nowrap text-right">
                <button class="btn-ghost btn-icon h-8 w-8" :title="t('sreq.p34.save')" :aria-label="t('sreq.p34.save')" :disabled="busy" data-edit-save @click="save"><Check :size="15" /></button>
                <button class="btn-ghost btn-icon h-8 w-8" :title="t('sreq.p34.cancel')" :aria-label="t('sreq.p34.cancel')" @click="editing = null"><X :size="15" /></button>
              </td>
            </template>
            <template v-else>
              <td class="px-4 py-2 text-ink">{{ c.name }}</td>
              <td class="px-4 py-2 font-mono text-[12.5px] text-ink-muted">{{ c.phone || '—' }}</td>
              <td class="px-4 py-2 text-right tnum text-ink-muted">{{ c.use_count }}</td>
              <td class="px-4 py-2 text-ink-muted">{{ c.last_used_at ? formatDateTime(c.last_used_at) : '—' }}</td>
              <td v-if="canWrite" class="px-4 py-2 whitespace-nowrap text-right">
                <button class="btn-ghost btn-icon h-8 w-8" :title="t('sreq.p34.edit')" :aria-label="t('sreq.p34.edit')" :data-contact-edit="c.id" @click="edit(c)"><Pencil :size="15" /></button>
                <button class="btn-ghost btn-icon h-8 w-8 hover:text-crit" :title="t('sreq.p34.remove')" :aria-label="t('sreq.p34.remove')" :data-contact-remove="c.id" @click="remove(c)"><Trash2 :size="15" /></button>
              </td>
            </template>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>
