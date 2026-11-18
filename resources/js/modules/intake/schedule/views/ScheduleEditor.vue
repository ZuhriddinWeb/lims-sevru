<script setup>
/**
 * [Paket 36] Grafik qatorini yaratish / tahrirlash yoki bitta kunning namunalarini o'zgartirish.
 *   portal-schedule-new   — yangi qator
 *   portal-schedule-edit  — qator (vaqt, kunlar, namunalar)
 *   portal-schedule-day   — shu kunning partiyasi (faqat namunalar; grafik o'zgarmaydi)
 * Namunalar formasi — buyurtma formasi (RequestForm, sanalarsiz).
 */
import { computed, onMounted, reactive, ref } from 'vue'
import { onBeforeRouteLeave, useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { ArrowLeft, Info, Save } from 'lucide-vue-next'
import api from '@/lib/api'
import PageHeader from '@/components/ui/PageHeader.vue'
import { useUiStore } from '@/stores/ui'
import RequestForm from '../../requests/components/RequestForm.vue'
import { apiErrorMessage, blankItem, fieldError } from '../../requests/shared'
import { formFromTemplate, templateFromForm } from '../../requests/templates'
import { niceDate } from '../shared'
import '../../requests/i18n'
import '../../requests/i18nP33'
import '../i18n'

const route = useRoute()
const router = useRouter()
const ui = useUiStore()
const { t, locale } = useI18n()

const mode = computed(() => (route.name === 'portal-schedule-day' ? 'day' : route.params.id ? 'edit' : 'new'))
const id = computed(() => (route.params.id ? Number(route.params.id) : null))

const options = ref(null)
const templates = ref([])
const enabled = ref([])
const run = ref(null)
const head = reactive({ name: '', time: '08:00', weekdays: [1, 2, 3, 4, 5, 6, 7], is_active: true })
const form = reactive({ department_id: '', delivery_date: '', delivery_shift_id: '', svodka_date: '', svodka_shift: '', laboratory_group_id: '', contact_name: '', contact_phone: '', notes: '', items: [] })
const errors = ref({})
const loading = ref(true)
const loadError = ref('')
const busy = ref(false)
const saved = ref('')
const snapshot = () => JSON.stringify([head, form.department_id, templateFromForm(form)])
const dirty = computed(() => !loading.value && !loadError.value && snapshot() !== saved.value)
const deptOptions = computed(() => ({ ...(options.value ?? {}), departments: (options.value?.departments ?? []).filter((d) => enabled.value.includes(Number(d.id))) }))
const showDepartment = computed(() => mode.value === 'new' && enabled.value.length > 1)

const title = computed(() => {
  if (mode.value === 'day') return run.value ? t('sched.dayTitle', { date: niceDate(run.value.date, locale.value), time: run.value.time }) : '…'
  return mode.value === 'edit' ? t('sched.editTitle', { name: head.name || '…' }) : t('sched.newTitle')
})

async function load() {
  loading.value = true
  try {
    const [opt, list, tpl] = await Promise.all([
      api.get('/portal/requests/options'), api.get('/portal/schedules'), api.get('/portal/request-templates').catch(() => ({ data: { data: [] } })),
    ])
    options.value = opt.data
    enabled.value = (list.data.enabled_departments ?? []).map((d) => Number(d.id))
    templates.value = tpl.data.data ?? []
    if (!list.data.can_write) {
      loadError.value = t('sched.readOnly')
      return
    }
    if (mode.value === 'day') {
      const { data } = await api.get(`/portal/schedule-runs/${id.value}`)
      run.value = data
      Object.assign(form, formFromTemplate({ department_id: data.department?.id, payload: data.payload }))
    } else if (mode.value === 'edit') {
      const s = (list.data.data ?? []).find((x) => x.id === id.value)
      if (!s) {
        loadError.value = t('sched.loadError')
        return
      }
      Object.assign(head, { name: s.name, time: s.time, weekdays: [...s.weekdays], is_active: s.is_active })
      Object.assign(form, formFromTemplate(s))
    } else {
      Object.assign(form, formFromTemplate(null), { items: [blankItem()] })
      const want = Number(route.query.department_id) || (enabled.value.length === 1 ? enabled.value[0] : '')
      if (want && enabled.value.includes(want)) form.department_id = want
      if (opt.data.groups?.length === 1) form.laboratory_group_id = opt.data.groups[0].id
    }
    if (!form.items.length) form.items.push(blankItem())
  } catch (e) {
    loadError.value = apiErrorMessage(e, t('sched.loadError'))
  } finally {
    loading.value = false
    saved.value = snapshot()
  }
}
onMounted(load)
onBeforeRouteLeave(() => (dirty.value && !busy.value && !window.confirm(t('sreq.msg.unsaved')) ? false : true))

/* «Shablondan olish» — P33 shablonlari */
const tplPick = ref('')
function applyTemplate() {
  const tpl = templates.value.find((x) => x.id === Number(tplPick.value))
  if (!tpl) return
  const f = formFromTemplate(tpl)
  Object.assign(form, { laboratory_group_id: f.laboratory_group_id || form.laboratory_group_id, contact_name: f.contact_name, contact_phone: f.contact_phone, notes: f.notes, items: f.items })
  if (!head.name) head.name = tpl.name
  tplPick.value = ''
}
const deptTemplates = computed(() => templates.value.filter((x) => !form.department_id || x.department_id === Number(form.department_id)))

function toggleDay(d) {
  head.weekdays = head.weekdays.includes(d) ? head.weekdays.filter((x) => x !== d) : [...head.weekdays, d].sort()
}
const preset = (days) => { head.weekdays = [...days] }

function mapErrors(errs) {
  const out = {}
  Object.entries(errs ?? {}).forEach(([k, v]) => { out[k.replace(/^payload\./, '')] = v })
  return out
}

async function save() {
  busy.value = true
  errors.value = {}
  try {
    const payload = templateFromForm(form)
    if (mode.value === 'day') {
      await api.put(`/portal/schedule-runs/${id.value}`, { payload })
    } else {
      const body = { department_id: form.department_id || null, name: head.name.trim(), time: head.time, weekdays: head.weekdays, is_active: head.is_active, payload }
      if (mode.value === 'edit') await api.put(`/portal/schedules/${id.value}`, body)
      else await api.post('/portal/schedules', body)
    }
    saved.value = snapshot()
    ui.success(t('sched.saved'))
    router.push({ name: 'portal-schedule', query: { tab: mode.value === 'day' ? 'day' : 'schedule', ...(mode.value === 'day' ? { date: run.value?.date } : {}) } })
  } catch (e) {
    errors.value = mapErrors(e?.response?.data?.errors)
    ui.error(apiErrorMessage(e, t('sched.loadError')))
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <div>
    <PageHeader :title="title">
      <template #actions>
        <button class="btn-ghost" @click="router.push({ name: 'portal-schedule', query: { tab: mode === 'day' ? 'day' : 'schedule' } })"><ArrowLeft :size="16" /> {{ t('sched.back') }}</button>
      </template>
    </PageHeader>

    <div v-if="loading" class="space-y-3">
      <div class="h-20 rounded-panel bg-surface-sunken animate-pulse" />
      <div class="h-56 rounded-panel bg-surface-sunken animate-pulse" />
    </div>
    <p v-else-if="loadError" class="panel p-4 text-[14px] text-warn-dark dark:text-warn-light">{{ loadError }}</p>

    <template v-else>
      <div v-if="mode === 'day'" class="panel p-4 mb-4">
        <p class="flex items-center gap-1.5 text-[13px] text-ink-muted"><Info :size="14" class="shrink-0" /> {{ t('sched.dayHint') }}</p>
      </div>

      <div v-else class="panel p-4 mb-4 grid gap-4 md:grid-cols-[minmax(0,1fr)_140px]">
        <div>
          <label class="field-label">{{ t('sched.name') }} *</label>
          <input v-model="head.name" class="field-input" maxlength="120" :placeholder="t('sched.namePlaceholder')" :class="fieldError(errors, 'name') && 'field-input-error'" data-row-name>
          <p v-if="fieldError(errors, 'name')" class="mt-1.5 text-[12.5px] text-crit">{{ fieldError(errors, 'name') }}</p>
        </div>
        <div>
          <label class="field-label">{{ t('sched.time') }} *</label>
          <input v-model="head.time" type="time" class="field-input" :class="fieldError(errors, 'time') && 'field-input-error'" data-row-time>
          <p v-if="fieldError(errors, 'time')" class="mt-1.5 text-[12.5px] text-crit">{{ fieldError(errors, 'time') }}</p>
        </div>
        <div class="md:col-span-2">
          <label class="field-label">{{ t('sched.days') }} *</label>
          <div class="flex flex-wrap items-center gap-1.5">
            <button
              v-for="d in [1, 2, 3, 4, 5, 6, 7]" :key="d" type="button" :data-day="d"
              class="h-8 w-10 rounded-md border text-[13px] font-medium transition-colors"
              :class="head.weekdays.includes(d) ? 'border-assay-500 bg-assay-50 text-assay-800 dark:bg-assay-800/30 dark:text-assay-100' : 'border-line text-ink-muted hover:border-assay-300'"
              @click="toggleDay(d)"
            >{{ t(`sched.dow.${d}`) }}</button>
            <span class="mx-1 h-5 w-px bg-line" />
            <button type="button" class="btn-ghost btn-sm" @click="preset([1, 2, 3, 4, 5, 6, 7])">{{ t('sched.everyDay') }}</button>
            <button type="button" class="btn-ghost btn-sm" @click="preset([1, 2, 3, 4, 5])">{{ t('sched.workDays') }}</button>
            <button type="button" class="btn-ghost btn-sm" @click="preset([1, 2, 3, 4, 5, 6])">{{ t('sched.workDays6') }}</button>
          </div>
          <p v-if="fieldError(errors, 'weekdays')" class="mt-1.5 text-[12.5px] text-crit">{{ fieldError(errors, 'weekdays') }}</p>
        </div>
        <div class="md:col-span-2 flex flex-wrap items-center gap-4">
          <label class="inline-flex items-center gap-2 text-[13.5px] text-ink">
            <input v-model="head.is_active" type="checkbox" class="h-4 w-4"> {{ t('sched.active') }}
          </label>
          <span class="flex-1" />
          <template v-if="deptTemplates.length">
            <label class="text-[13px] text-ink-muted">{{ t('sched.fromTemplate') }}</label>
            <select v-model="tplPick" class="field-input w-auto min-w-[200px]" data-from-template @change="applyTemplate">
              <option value="">{{ t('sched.fromTemplateNone') }}</option>
              <option v-for="x in deptTemplates" :key="x.id" :value="x.id">{{ x.name }} ({{ x.items_count }})</option>
            </select>
          </template>
        </div>
      </div>

      <p v-if="fieldError(errors, 'department_id')" class="mb-3 text-[13px] text-crit">{{ fieldError(errors, 'department_id') }}</p>
      <RequestForm :form="form" :options="deptOptions" :errors="errors" :show-department="showDepartment" fresh template-mode />

      <div class="sticky bottom-0 z-10 mt-4 pb-3">
        <div class="panel shadow-pop px-4 py-3 flex flex-wrap items-center gap-2">
          <p v-if="fieldError(errors, 'items')" class="text-[12.5px] text-crit">{{ fieldError(errors, 'items') }}</p>
          <span class="flex-1" />
          <span v-if="dirty" class="text-[12.5px] text-ink-faint">●</span>
          <button class="btn-primary" :disabled="busy || (mode !== 'day' && (!head.name.trim() || !head.weekdays.length)) || !form.items.length" data-row-save @click="save">
            <Save :size="16" /> {{ t('sched.save') }}
          </button>
        </div>
      </div>
    </template>
  </div>
</template>
