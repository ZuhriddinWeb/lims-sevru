<script setup>
/**
 * Buyurtmachining spravochnigi: svodka bloklari va namuna olish nuqtalari. [Paket 29]
 * Faqat shu bo'limga ko'rinadi — laboratoriya nuqta nomlarini ko'rmaydi.
 * Ishlatilgan nuqta/blok o'chirilmaydi — nofaol bo'ladi (eski svodkalarda nomi qoladi).
 */
import { computed, nextTick, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { ArrowDown, ArrowUp, Check, EyeOff, ListPlus, Pencil, Plus, RotateCcw, Trash2, X } from 'lucide-vue-next'
import api from '@/lib/api'
import ConfirmDialog from '@/components/ui/ConfirmDialog.vue'
import { useUiStore } from '@/stores/ui'
import SectionDialog from './SectionDialog.vue'
import { apiError } from '../shared'
import '../i18n'

const props = defineProps({
  data: { type: Object, required: true },       // { department_id, sections, elements, can_write }
})
const emit = defineEmits(['changed'])
const { t } = useI18n()
const ui = useUiStore()

const canWrite = computed(() => !!props.data.can_write)
const sections = computed(() => props.data.sections ?? [])
const busy = ref(false)

const LAYOUT_TONE = {
  shift: 'bg-aqua-50 text-aqua-700 dark:bg-aqua-800/30 dark:text-aqua-200',
  day: 'bg-assay-50 text-assay-700 dark:bg-assay-800/30 dark:text-assay-200',
  list: 'bg-surface-sunken text-ink-muted',
}

async function run(fn, okMsg) {
  busy.value = true
  try {
    const { data } = await fn()
    emit('changed', data)
    if (okMsg) ui.success(typeof okMsg === 'function' ? okMsg(data) : okMsg)
    return data
  } catch (e) {
    ui.error(apiError(e, t('svodka.loadError')))
    return null
  } finally {
    busy.value = false
  }
}

/* ---------- bloklar ---------- */
const sectionDialog = reactive({ open: false, section: null, error: '' })
function newSection() {
  Object.assign(sectionDialog, { open: true, section: null, error: '' })
}
function editSection(s) {
  Object.assign(sectionDialog, { open: true, section: s, error: '' })
}
async function saveSection(payload) {
  busy.value = true
  sectionDialog.error = ''
  try {
    const s = sectionDialog.section
    const { data } = s
      ? await api.put(`/portal/svodka/sections/${s.id}`, { ...payload, is_active: s.is_active })
      : await api.post('/portal/svodka/sections', { ...payload, department_id: props.data.department_id })
    emit('changed', data)
    sectionDialog.open = false
    ui.success(t('svodka.msg.saved'))
  } catch (e) {
    sectionDialog.error = apiError(e, t('svodka.loadError'))
  } finally {
    busy.value = false
  }
}
function restoreSection(s) {
  run(() => api.put(`/portal/svodka/sections/${s.id}`, { title: s.title, layout: s.layout, columns: s.columns, is_active: true }), t('svodka.msg.restored'))
}

/* ---------- o'chirish (tasdiqlash bilan) ---------- */
const confirm = reactive({ open: false, kind: '', item: null })
const confirmBody = computed(() => {
  const it = confirm.item
  if (!it) return ''
  const used = confirm.kind === 'section' ? it.points?.some((p) => p.used) : it.used
  return used ? t('svodka.confirm.deactivateBody') : t(confirm.kind === 'section' ? 'svodka.confirm.deleteSectionBody' : 'svodka.confirm.deletePointBody')
})
function askDelete(kind, item) {
  Object.assign(confirm, { open: true, kind, item })
}
async function doDelete() {
  const { kind, item } = confirm
  confirm.open = false
  await run(
    () => api.delete(kind === 'section' ? `/portal/svodka/sections/${item.id}` : `/portal/svodka/points/${item.id}`),
    (d) => (d.result === 'deactivated' ? t('svodka.msg.deactivated') : t('svodka.msg.deleted')),
  )
}

/* ---------- nuqtalar ---------- */
const newName = reactive({})          // section_id => matn
const bulkOpen = reactive({})         // section_id => bool
const bulkText = reactive({})
const editing = reactive({ id: null, name: '' })

async function addPoint(s) {
  const name = (newName[s.id] || '').trim()
  if (!name) return
  const d = await run(() => api.post('/portal/svodka/points', { section_id: s.id, name, daily: /^sut[.\s]/i.test(name) }))
  if (d) {
    newName[s.id] = ''
    await nextTick()
    document.querySelector(`[data-new-point="${s.id}"]`)?.focus()
  }
}
async function addBulk(s) {
  const names = (bulkText[s.id] || '').trim()
  if (!names) return
  const d = await run(() => api.post('/portal/svodka/points/bulk', { section_id: s.id, names }), (r) => t('svodka.msg.bulkAdded', { n: r.added }))
  if (d) {
    bulkText[s.id] = ''
    bulkOpen[s.id] = false
  }
}
function startEdit(p) {
  Object.assign(editing, { id: p.id, name: p.name })
  nextTick(() => document.querySelector(`[data-edit-point="${p.id}"]`)?.focus())
}
async function saveEdit(p) {
  const name = editing.name.trim()
  if (!name || name === p.name) {
    editing.id = null
    return
  }
  const d = await run(() => api.put(`/portal/svodka/points/${p.id}`, { name, daily: p.daily }))
  if (d) editing.id = null
}
function toggleDaily(p) {
  run(() => api.put(`/portal/svodka/points/${p.id}`, { name: p.name, daily: !p.daily }))
}
function restorePoint(p) {
  run(() => api.put(`/portal/svodka/points/${p.id}`, { name: p.name, is_active: true }), t('svodka.msg.restored'))
}
async function move(s, i, dir) {
  const list = s.points
  const j = i + dir
  if (j < 0 || j >= list.length) return
  const a = list[i]
  const b = list[j]
  busy.value = true
  try {
    let data
    if (a.sort_order !== b.sort_order) {
      // tartib raqamlarini almashtirish — qolganlariga tegilmaydi
      await api.put(`/portal/svodka/points/${a.id}`, { name: a.name, sort_order: b.sort_order })
      ;({ data } = await api.put(`/portal/svodka/points/${b.id}`, { name: b.name, sort_order: a.sort_order }))
    } else {
      // teng raqamlar (eski ma'lumot) — butun blok qayta raqamlanadi
      const order = [...list]
      order.splice(i, 1)
      order.splice(j, 0, a)
      for (const [k, p] of order.entries()) {
        ;({ data } = await api.put(`/portal/svodka/points/${p.id}`, { name: p.name, sort_order: k + 1 }))
      }
    }
    emit('changed', data)
  } catch (e) {
    ui.error(apiError(e, t('svodka.loadError')))
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <div class="space-y-4" data-spravochnik>
    <div class="flex flex-wrap items-start justify-between gap-3">
      <p class="max-w-[720px] text-[13px] leading-relaxed text-ink-muted">
        <EyeOff :size="14" class="mr-1 inline -mt-0.5 text-assay-500" />{{ t('svodka.points.privacy') }}
      </p>
      <button v-if="canWrite" type="button" class="btn-primary btn-sm" :disabled="busy" data-new-section @click="newSection">
        <Plus :size="15" /> {{ t('svodka.section.new') }}
      </button>
    </div>

    <div v-if="!sections.length" class="panel px-4 py-10 text-center" data-no-sections>
      <p class="text-[14px] font-medium text-ink">{{ t('svodka.points.emptyTitle') }}</p>
      <p class="mt-1 text-[13px] text-ink-muted">{{ t('svodka.points.emptyHint') }}</p>
    </div>

    <section
      v-for="s in sections" :key="s.id" class="panel overflow-hidden"
      :class="!s.is_active && 'opacity-75'" :data-section="s.id"
    >
      <header class="flex flex-wrap items-center gap-x-3 gap-y-2 px-4 py-3 border-b border-line">
        <h3 class="text-[14.5px] font-semibold text-ink">{{ s.title }}</h3>
        <span class="rounded-full px-2 py-0.5 text-[11.5px] font-medium" :class="LAYOUT_TONE[s.layout]">{{ t(`svodka.layouts.${s.layout}`) }}</span>
        <span class="flex flex-wrap gap-1">
          <span v-for="c in s.columns" :key="c.element_id" class="rounded bg-surface-sunken px-1.5 py-0.5 font-mono text-[11.5px] text-ink-muted">
            {{ c.label || data.elements.find((e) => e.id === c.element_id)?.symbol || '?' }}
          </span>
        </span>
        <span v-if="!s.is_active" class="rounded-full bg-surface-sunken px-2 py-0.5 text-[11.5px] text-ink-muted">{{ t('svodka.points.inactive') }}</span>
        <span class="flex-1" />
        <template v-if="canWrite">
          <button v-if="!s.is_active" type="button" class="btn-ghost btn-sm" :disabled="busy" @click="restoreSection(s)">
            <RotateCcw :size="14" /> {{ t('svodka.restore') }}
          </button>
          <button type="button" class="btn-ghost btn-icon h-8 w-8" :title="t('svodka.edit')" :aria-label="t('svodka.edit')" data-edit-section @click="editSection(s)">
            <Pencil :size="15" />
          </button>
          <button v-if="s.is_active" type="button" class="btn-ghost btn-icon h-8 w-8 hover:text-crit" :title="t('svodka.remove')" :aria-label="t('svodka.remove')" data-del-section @click="askDelete('section', s)">
            <Trash2 :size="15" />
          </button>
        </template>
      </header>

      <ol v-if="s.points.length" class="divide-y divide-line">
        <li
          v-for="(p, i) in s.points" :key="p.id"
          class="flex items-center gap-2 px-4 py-1.5 min-h-[42px]" :class="!p.is_active && 'bg-surface-sunken/50'"
          :data-point="p.id"
        >
          <span class="w-6 shrink-0 text-right text-[12.5px] text-ink-faint tnum">{{ i + 1 }}</span>
          <template v-if="editing.id === p.id">
            <input
              v-model="editing.name" class="field-input h-8 flex-1 min-w-0" maxlength="150" :data-edit-point="p.id"
              @keydown.enter.prevent="saveEdit(p)" @keydown.esc="editing.id = null"
            >
            <button type="button" class="btn-ghost btn-icon h-8 w-8 text-ok" :aria-label="t('svodka.save')" @click="saveEdit(p)"><Check :size="16" /></button>
            <button type="button" class="btn-ghost btn-icon h-8 w-8" :aria-label="t('svodka.cancel')" @click="editing.id = null"><X :size="16" /></button>
          </template>
          <template v-else>
            <span class="flex-1 min-w-0 truncate text-[13.5px]" :class="p.is_active ? 'text-ink' : 'text-ink-faint line-through'" :title="p.name">{{ p.name }}</span>
            <button
              type="button" class="rounded-full px-2 py-0.5 text-[11px] font-medium transition-colors"
              :class="p.daily ? 'bg-assay-50 text-assay-700 dark:bg-assay-800/30 dark:text-assay-200' : 'text-ink-faint hover:bg-surface-sunken'"
              :disabled="!canWrite || busy" :title="t('svodka.points.dailyHint')" data-daily
              @click="toggleDaily(p)"
            >{{ p.daily ? t('svodka.points.daily') : t('svodka.points.shiftly') }}</button>
            <span class="hidden sm:inline-block w-[78px] text-[11px] text-ink-faint" :title="p.used ? t('svodka.points.usedHint') : null">{{ p.used ? t('svodka.points.used') : '' }}</span>
            <template v-if="canWrite">
              <button v-if="!p.is_active" type="button" class="btn-ghost btn-sm h-7 px-2" :disabled="busy" @click="restorePoint(p)">
                <RotateCcw :size="13" /> {{ t('svodka.restore') }}
              </button>
              <span class="flex shrink-0">
                <button type="button" class="btn-ghost btn-icon h-7 w-7" :disabled="busy || i === 0" :aria-label="t('svodka.up')" data-up @click="move(s, i, -1)"><ArrowUp :size="14" /></button>
                <button type="button" class="btn-ghost btn-icon h-7 w-7" :disabled="busy || i === s.points.length - 1" :aria-label="t('svodka.down')" data-down @click="move(s, i, 1)"><ArrowDown :size="14" /></button>
                <button type="button" class="btn-ghost btn-icon h-7 w-7" :aria-label="t('svodka.edit')" data-edit @click="startEdit(p)"><Pencil :size="14" /></button>
                <button v-if="p.is_active" type="button" class="btn-ghost btn-icon h-7 w-7 hover:text-crit" :aria-label="t('svodka.remove')" data-del @click="askDelete('point', p)"><Trash2 :size="14" /></button>
              </span>
            </template>
          </template>
        </li>
      </ol>
      <p v-else class="px-4 py-4 text-[13px] text-ink-faint">{{ t('svodka.points.noPoints') }}</p>

      <div v-if="canWrite" class="border-t border-line bg-surface-sunken/40 px-4 py-2.5 space-y-2">
        <div class="flex flex-wrap items-center gap-2">
          <input
            v-model="newName[s.id]" class="field-input h-8 flex-1 min-w-[180px]" maxlength="150"
            :placeholder="t('svodka.points.newPlaceholder')" :data-new-point="s.id"
            @keydown.enter.prevent="addPoint(s)"
          >
          <button type="button" class="btn-outline btn-sm" :disabled="busy || !(newName[s.id] || '').trim()" data-add-point @click="addPoint(s)">
            <Plus :size="14" /> {{ t('svodka.points.add') }}
          </button>
          <button type="button" class="btn-ghost btn-sm" data-bulk-toggle @click="bulkOpen[s.id] = !bulkOpen[s.id]">
            <ListPlus :size="15" /> {{ t('svodka.points.bulk') }}
          </button>
        </div>
        <div v-if="bulkOpen[s.id]" class="space-y-2" data-bulk>
          <textarea v-model="bulkText[s.id]" rows="6" class="field-input font-mono text-[13px]" :placeholder="t('svodka.points.bulkPlaceholder')" />
          <div class="flex flex-wrap items-center justify-between gap-2">
            <p class="text-[12px] text-ink-muted">{{ t('svodka.points.bulkHint') }}</p>
            <button type="button" class="btn-primary btn-sm" :disabled="busy || !(bulkText[s.id] || '').trim()" data-bulk-save @click="addBulk(s)">
              {{ t('svodka.points.bulkAdd') }}
            </button>
          </div>
        </div>
      </div>
    </section>

    <SectionDialog
      v-model="sectionDialog.open" :section="sectionDialog.section" :elements="data.elements"
      :busy="busy" :error="sectionDialog.error" @save="saveSection"
    />
    <ConfirmDialog
      v-model="confirm.open"
      :title="confirm.kind === 'section' ? t('svodka.confirm.deleteSection', { name: confirm.item?.title }) : t('svodka.confirm.deletePoint', { name: confirm.item?.name })"
      :body="confirmBody" :confirm-label="t('svodka.remove')" :cancel-label="t('svodka.cancel')" :loading="busy"
      @confirm="doDelete"
    />
  </div>
</template>
