<script setup>
/**
 * [Paket 33] Buyurtma shablonini yaratish / tahrirlash (buyurtmachi).
 * Buyurtma formasi bilan bir xil (RequestForm, templateMode) — faqat sanalar yo'q.
 */
import { computed, onMounted, reactive, ref } from 'vue'
import { onBeforeRouteLeave, useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { ArrowLeft, Info, Save } from 'lucide-vue-next'
import api from '@/lib/api'
import PageHeader from '@/components/ui/PageHeader.vue'
import { useUiStore } from '@/stores/ui'
import RequestForm from '../components/RequestForm.vue'
import { apiErrorMessage, blankItem, fieldError } from '../shared'
import { formFromTemplate, templateFromForm } from '../templates'
import '../i18n'
import '../i18nP33'

const route = useRoute()
const router = useRouter()
const ui = useUiStore()
const { t } = useI18n()

const id = computed(() => (route.params.id ? Number(route.params.id) : null))
const options = ref(null)
const name = ref('')
const form = reactive({ department_id: '', delivery_date: '', delivery_shift_id: '', svodka_date: '', svodka_shift: '', laboratory_group_id: '', contact_name: '', contact_phone: '', notes: '', items: [] })
const errors = ref({})
const loading = ref(true)
const loadError = ref('')
const busy = ref(false)
const saved = ref('')
const snapshot = () => JSON.stringify([name.value, form.department_id, templateFromForm(form)])
const dirty = computed(() => !loading.value && !loadError.value && snapshot() !== saved.value)
const showDepartment = computed(() => (options.value?.departments?.length ?? 0) !== 1)

const title = computed(() => (id.value ? t('sreq.tpl.editTitle', { name: name.value || '…' }) : t('sreq.tpl.newTitle')))

async function load() {
  loading.value = true
  try {
    const [opt, list] = await Promise.all([api.get('/portal/requests/options'), api.get('/portal/request-templates')])
    options.value = opt.data
    if (!list.data.can_write) {
      loadError.value = t('sreq.tpl.readOnly')
      return
    }
    if (id.value) {
      const tpl = (list.data.data ?? []).find((x) => x.id === id.value)
      if (!tpl) {
        loadError.value = t('sreq.empty')
        return
      }
      name.value = tpl.name
      Object.assign(form, formFromTemplate(tpl))
      if (!form.items.length) form.items.push(blankItem())
    } else {
      Object.assign(form, formFromTemplate(null), { items: [blankItem()] })
      if (opt.data.departments?.length === 1) form.department_id = opt.data.departments[0].id
      const want = Number(route.query.department_id)
      if (want && opt.data.departments?.some((d) => Number(d.id) === want)) form.department_id = want
      if (opt.data.groups?.length === 1) form.laboratory_group_id = opt.data.groups[0].id
    }
  } catch (e) {
    loadError.value = apiErrorMessage(e, t('sreq.tpl.loadError'))
  } finally {
    loading.value = false
    saved.value = snapshot()
  }
}
onMounted(load)

onBeforeRouteLeave(() => (dirty.value && !busy.value && !window.confirm(t('sreq.msg.unsaved')) ? false : true))

/** Shablon xatolari (payload.items.0.x) → forma xatolari (items.0.x) */
function mapErrors(errs) {
  const out = {}
  Object.entries(errs ?? {}).forEach(([k, v]) => { out[k.replace(/^payload\./, '')] = v })
  return out
}

async function save() {
  busy.value = true
  errors.value = {}
  try {
    const body = { department_id: form.department_id || null, name: name.value.trim(), payload: templateFromForm(form) }
    const { data } = id.value
      ? await api.put(`/portal/request-templates/${id.value}`, body)
      : await api.post('/portal/request-templates', body)
    name.value = data.name
    saved.value = snapshot()
    ui.success(t('sreq.tpl.saved'))
    router.push({ name: 'portal-templates' })
  } catch (e) {
    errors.value = mapErrors(e?.response?.data?.errors)
    ui.error(apiErrorMessage(e, t('sreq.tpl.loadError')))
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <div>
    <PageHeader :title="title">
      <template #actions>
        <button class="btn-ghost" @click="router.push({ name: 'portal-templates' })"><ArrowLeft :size="16" /> {{ t('sreq.tpl.back') }}</button>
      </template>
    </PageHeader>

    <div v-if="loading" class="space-y-3">
      <div class="h-20 rounded-panel bg-surface-sunken animate-pulse" />
      <div class="h-56 rounded-panel bg-surface-sunken animate-pulse" />
    </div>
    <p v-else-if="loadError" class="panel p-4 text-[14px] text-warn-dark dark:text-warn-light">{{ loadError }}</p>

    <template v-else>
      <div class="panel p-4 mb-4">
        <label class="field-label">{{ t('sreq.tpl.name') }} *</label>
        <input
          v-model="name" class="field-input max-w-xl" maxlength="120" :placeholder="t('sreq.tpl.namePlaceholder')"
          :class="fieldError(errors, 'name') && 'field-input-error'" data-template-name
        >
        <p v-if="fieldError(errors, 'name')" class="mt-1.5 text-[12.5px] text-crit">{{ fieldError(errors, 'name') }}</p>
        <p class="mt-2 flex items-center gap-1.5 text-[12.5px] text-ink-faint"><Info :size="13" class="shrink-0" /> {{ t('sreq.tpl.noDate') }}</p>
      </div>

      <RequestForm :form="form" :options="options" :errors="errors" :show-department="showDepartment" fresh template-mode />

      <div class="sticky bottom-0 z-10 mt-4 pb-3">
        <div class="panel shadow-pop px-4 py-3 flex flex-wrap items-center gap-2">
          <p v-if="fieldError(errors, 'items')" class="text-[12.5px] text-crit">{{ fieldError(errors, 'items') }}</p>
          <span class="flex-1" />
          <span v-if="dirty" class="text-[12.5px] text-ink-faint">●</span>
          <button class="btn-primary" :disabled="busy || !name.trim() || !form.items.length" data-template-save @click="save">
            <Save :size="16" /> {{ t('sreq.tpl.save') }}
          </button>
        </div>
      </div>
    </template>
  </div>
</template>
