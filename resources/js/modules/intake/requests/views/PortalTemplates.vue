<script setup>
/**
 * [Paket 33] Buyurtmachi — «Shablonlarim»: bo'linmaning doimiy buyurtma shablonlari.
 * Shablonni faqat shu bo'linma vakillari ko'radi va ishlatadi. Tartib — «Yangi buyurtma» tepasidagi tugmalar tartibi.
 */
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { ArrowDown, ArrowUp, LayoutTemplate, Pencil, Plus, Send, Trash2 } from 'lucide-vue-next'
import api from '@/lib/api'
import PageHeader from '@/components/ui/PageHeader.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import { useUiStore } from '@/stores/ui'
import { formatDateTime } from '@/lib/format'
import ReasonDialog from '../components/ReasonDialog.vue'
import { apiErrorMessage, nameOf } from '../shared'
import '../i18n'
import '../i18nGroup'
import '../i18nP33'

const { t, locale } = useI18n()
const router = useRouter()
const ui = useUiStore()

const list = ref([])
const options = ref(null)
const canWrite = ref(false)
const loading = ref(true)
const loadError = ref('')
const removing = ref(null)
const busy = ref(false)

const departments = computed(() => options.value?.departments ?? [])
const groupName = (id) => nameOf((options.value?.groups ?? []).find((g) => Number(g.id) === Number(id)), locale.value)
const shiftName = (id) => (options.value?.shifts ?? []).find((s) => Number(s.id) === Number(id))?.name ?? ''

/** Bo'limlar bo'yicha guruhlash (bitta bo'lim bo'lsa — sarlavhasiz) */
const blocks = computed(() => {
  const by = {}
  list.value.forEach((x) => { (by[x.department_id] ||= []).push(x) })
  return Object.entries(by).map(([id, rows]) => ({
    id: Number(id),
    name: departments.value.find((d) => Number(d.id) === Number(id))?.name ?? `#${id}`,
    rows,
  }))
})

async function load() {
  loading.value = true
  loadError.value = ''
  try {
    const [opt, tpl] = await Promise.all([api.get('/portal/requests/options'), api.get('/portal/request-templates')])
    options.value = opt.data
    list.value = tpl.data.data ?? []
    canWrite.value = !!tpl.data.can_write
  } catch (e) {
    loadError.value = apiErrorMessage(e, t('sreq.tpl.loadError'))
  } finally {
    loading.value = false
  }
}
onMounted(load)

async function move(block, i, dir) {
  const rows = [...block.rows]
  const j = i + dir
  if (j < 0 || j >= rows.length) return
  ;[rows[i], rows[j]] = [rows[j], rows[i]]
  const order = rows.map((r) => r.id)
  list.value = [...list.value.filter((x) => x.department_id !== block.id), ...rows]
  try {
    await api.post('/portal/request-templates/order', { ids: order })
  } catch (e) {
    ui.error(apiErrorMessage(e, t('sreq.tpl.loadError')))
    load()
  }
}

async function remove() {
  const tpl = removing.value
  if (!tpl) return
  busy.value = true
  try {
    await api.delete(`/portal/request-templates/${tpl.id}`)
    list.value = list.value.filter((x) => x.id !== tpl.id)
    ui.success(t('sreq.tpl.deleted'))
    removing.value = null
  } catch (e) {
    ui.error(apiErrorMessage(e, t('sreq.tpl.loadError')))
  } finally {
    busy.value = false
  }
}

const edit = (tpl) => router.push({ name: 'portal-template', params: { id: tpl.id } })
const use = (tpl) => router.push({ name: 'portal-request-new', query: { template: tpl.id, department_id: tpl.department_id } })
</script>

<template>
  <div>
    <PageHeader :title="t('sreq.tpl.title')">
      <template #actions>
        <button v-if="canWrite" class="btn-primary" data-new-template @click="router.push({ name: 'portal-template-new' })">
          <Plus :size="16" /> {{ t('sreq.tpl.newBtn') }}
        </button>
      </template>
    </PageHeader>

    <p class="mb-4 max-w-3xl text-[13.5px] leading-relaxed text-ink-muted">{{ t('sreq.tpl.hint') }}</p>
    <p v-if="!loading && !loadError && !canWrite" class="mb-4 text-[13px] text-aqua-700 dark:text-aqua-200">{{ t('sreq.tpl.readOnly') }}</p>

    <div v-if="loading" class="space-y-3">
      <div class="h-16 rounded-panel bg-surface-sunken animate-pulse" />
      <div class="h-16 rounded-panel bg-surface-sunken animate-pulse" />
    </div>
    <p v-else-if="loadError" class="panel p-4 text-[14px] text-crit">{{ loadError }}</p>

    <div v-else-if="!list.length" class="panel">
      <EmptyState :icon="LayoutTemplate" :title="t('sreq.tpl.empty')" :hint="t('sreq.tpl.emptyHint')">
        <button v-if="canWrite" class="btn-primary" @click="router.push({ name: 'portal-template-new' })">
          <Plus :size="16" /> {{ t('sreq.tpl.newBtn') }}
        </button>
      </EmptyState>
    </div>

    <div v-else class="space-y-4">
      <div v-for="block in blocks" :key="block.id" class="panel overflow-hidden" :data-dept="block.id">
        <div v-if="blocks.length > 1 || departments.length > 1" class="px-4 py-2.5 border-b border-line bg-surface-sunken/50 text-[13px] font-semibold text-ink">
          {{ block.name }}
        </div>
        <div class="divide-y divide-line">
          <div v-for="(tpl, i) in block.rows" :key="tpl.id" class="flex flex-wrap items-center gap-x-4 gap-y-2 px-4 py-3" :data-template-row="tpl.id">
            <div class="min-w-[220px] flex-1">
              <button type="button" class="text-left text-[14.5px] font-semibold text-ink hover:text-assay-700 dark:hover:text-assay-200" @click="canWrite ? edit(tpl) : null">
                {{ tpl.name }}
              </button>
              <p class="mt-0.5 flex flex-wrap gap-x-3 text-[12.5px] text-ink-muted">
                <span class="tnum">{{ t('sreq.tpl.items', { n: tpl.items_count }) }}</span>
                <span v-if="tpl.payload?.laboratory_group_id">{{ t('sreq.tpl.group') }}: {{ groupName(tpl.payload.laboratory_group_id) }}</span>
                <span v-if="tpl.payload?.delivery_shift_id">{{ t('sreq.tpl.shift') }}: {{ shiftName(tpl.payload.delivery_shift_id) }}</span>
                <span v-if="tpl.payload?.contact_name">{{ tpl.payload.contact_name }}</span>
                <span v-if="tpl.updated_at" class="text-ink-faint">{{ formatDateTime(tpl.updated_at) }}</span>
              </p>
            </div>
            <div class="flex items-center gap-1">
              <button v-if="canWrite" class="btn-outline btn-sm" :data-use="tpl.id" @click="use(tpl)">
                <Send :size="14" /> {{ t('sreq.newTitle') }}
              </button>
              <template v-if="canWrite">
                <button class="btn-ghost btn-icon h-8 w-8" :title="t('sreq.tpl.moveUp')" :aria-label="t('sreq.tpl.moveUp')" :disabled="i === 0" @click="move(block, i, -1)"><ArrowUp :size="15" /></button>
                <button class="btn-ghost btn-icon h-8 w-8" :title="t('sreq.tpl.moveDown')" :aria-label="t('sreq.tpl.moveDown')" :disabled="i === block.rows.length - 1" @click="move(block, i, 1)"><ArrowDown :size="15" /></button>
                <button class="btn-ghost btn-icon h-8 w-8" :title="t('sreq.tpl.edit')" :aria-label="t('sreq.tpl.edit')" :data-edit="tpl.id" @click="edit(tpl)"><Pencil :size="15" /></button>
                <button class="btn-ghost btn-icon h-8 w-8 hover:text-crit" :title="t('sreq.tpl.remove')" :aria-label="t('sreq.tpl.remove')" :data-remove="tpl.id" @click="removing = tpl"><Trash2 :size="15" /></button>
              </template>
            </div>
          </div>
        </div>
      </div>
    </div>

    <ReasonDialog
      :model-value="!!removing" :title="t('sreq.tpl.deleteTitle')" :body="removing ? t('sreq.tpl.deleteBody', { name: removing.name }) : ''"
      :with-reason="false" :confirm-label="t('sreq.tpl.remove')" :loading="busy"
      @update:model-value="!$event && (removing = null)" @confirm="remove"
    />
  </div>
</template>
