<script setup>
/**
 * [Paket 31] Svodkani ko'ruvchilar — admin istalgan foydalanuvchiga svodkani buyurtmachidek ko'rishni beradi
 * (rol yaratish shart emas). Faqat admin ochadi.
 */
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { Eye, Trash2, UserPlus } from 'lucide-vue-next'
import BaseModal from '@/components/ui/BaseModal.vue'
import api from '@/lib/api'
import { apiError } from '../shared'
import '../i18n'

const props = defineProps({ modelValue: Boolean })
const emit = defineEmits(['update:modelValue'])
const { t } = useI18n()

const users = ref([])
const loading = ref(false)
const busy = ref(false)
const error = ref('')
const q = ref('')
const pick = ref('')

const viewers = computed(() => users.value.filter((u) => u.viewer || u.via_role))
const candidates = computed(() => {
  const s = q.value.trim().toLowerCase()
  return users.value.filter((u) => !u.viewer && !u.via_role
    && (!s || u.name.toLowerCase().includes(s) || (u.username ?? '').toLowerCase().includes(s)))
})

async function load() {
  loading.value = true
  error.value = ''
  try {
    const { data } = await api.get('/svodka-releases/viewers')
    users.value = data.users ?? []
  } catch (e) {
    error.value = apiError(e, t('svodka.loadError'))
  } finally {
    loading.value = false
  }
}

watch(() => props.modelValue, (open) => {
  if (!open) return
  q.value = ''
  pick.value = ''
  load()
}, { immediate: true })

async function add() {
  if (!pick.value) {
    error.value = t('svodka.viewers.choose')
    return
  }
  busy.value = true
  error.value = ''
  try {
    const { data } = await api.post('/svodka-releases/viewers', { user_id: Number(pick.value) })
    users.value = data.users ?? []
    pick.value = ''
    q.value = ''
  } catch (e) {
    error.value = apiError(e, t('svodka.loadError'))
  } finally {
    busy.value = false
  }
}

async function remove(u) {
  busy.value = true
  error.value = ''
  try {
    const { data } = await api.delete(`/svodka-releases/viewers/${u.id}`)
    users.value = data.users ?? []
  } catch (e) {
    error.value = apiError(e, t('svodka.loadError'))
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <BaseModal
    :model-value="modelValue" size="md" :title="t('svodka.viewers.title')" :subtitle="t('svodka.viewers.subtitle')"
    @update:model-value="emit('update:modelValue', $event)"
  >
    <div class="space-y-4" data-viewers-dialog>
      <div>
        <label class="field-label">{{ t('svodka.viewers.add') }}</label>
        <div class="flex flex-wrap gap-2">
          <input v-model="q" type="search" class="field-input h-9 min-w-[160px] flex-1" :placeholder="t('svodka.viewers.search')" data-viewers-search>
          <select v-model="pick" class="field-input h-9 min-w-[220px] flex-[2]" data-viewers-pick>
            <option value="">{{ t('svodka.viewers.choose') }}</option>
            <option v-for="u in candidates" :key="u.id" :value="u.id">
              {{ u.name }} ({{ u.username }}){{ u.role ? ' — ' + u.role : '' }}
            </option>
          </select>
          <button type="button" class="btn-primary btn-sm h-9" :disabled="busy || loading" data-viewers-add @click="add">
            <UserPlus :size="15" /> {{ t('svodka.viewers.add') }}
          </button>
        </div>
      </div>

      <div>
        <p class="field-label">{{ t('svodka.viewers.list') }}</p>
        <p v-if="loading" class="text-[13px] text-ink-muted">…</p>
        <p v-else-if="!viewers.length" class="rounded-md bg-surface-sunken px-3 py-2 text-[13px] text-ink-muted" data-viewers-empty>{{ t('svodka.viewers.empty') }}</p>
        <ul v-else class="divide-y divide-line rounded-md border border-line" data-viewers-list>
          <li v-for="u in viewers" :key="u.id" class="flex items-center gap-3 px-3 py-2 text-[13.5px]" :data-viewer="u.id">
            <Eye :size="15" class="shrink-0 text-ink-muted" />
            <span class="min-w-0 flex-1">
              <span class="text-ink">{{ u.name }}</span>
              <span class="text-ink-muted"> ({{ u.username }}){{ u.role ? ' — ' + u.role : '' }}</span>
            </span>
            <button v-if="u.viewer" type="button" class="btn-ghost btn-sm text-crit" :disabled="busy" data-viewer-remove @click="remove(u)">
              <Trash2 :size="14" /> {{ t('svodka.viewers.remove') }}
            </button>
            <span v-else class="text-[12px] text-ink-muted" :title="t('svodka.viewers.viaRoleHint')" data-via-role>{{ t('svodka.viewers.viaRole') }}</span>
          </li>
        </ul>
      </div>

      <p v-if="error" class="text-[13px] text-crit" data-viewers-error>{{ error }}</p>
      <p class="text-[12.5px] text-ink-muted">{{ t('svodka.viewers.reload') }}</p>
    </div>
  </BaseModal>
</template>
