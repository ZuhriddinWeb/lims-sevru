<script setup>
/**
 * Buyurtma: yangi / qoralamani tahrirlash / ko'rish.
 *   meta.mode = 'portal' — buyurtmachi (/api/portal/requests), yuborilganini ko'radi, qaytarib oladi, bekor qiladi;
 *   meta.mode = 'lab'    — xodim buyurtmachi nomidan (/api/sample-requests), faqat o'z qoralamasi;
 *                          yuborilgan so'rov laboratoriyada «sample-request» sahifasida ochiladi.
 */
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { onBeforeRouteLeave, useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { ArrowLeft, Eye, Printer, Send, Save, Trash2, Undo2, XCircle } from 'lucide-vue-next'
import api from '@/lib/api'
import PageHeader from '@/components/ui/PageHeader.vue'
import { useUiStore } from '@/stores/ui'
import RequestForm from '../components/RequestForm.vue'
import RequestInfo from '../components/RequestInfo.vue'
import ItemsTable from '../components/ItemsTable.vue'
import ReasonDialog from '../components/ReasonDialog.vue'
import { apiErrorMessage, blankItem, printSlip, toForm, toPayload } from '../shared'
import '../i18n'

const route = useRoute()
const router = useRouter()
const ui = useUiStore()
const { t, locale } = useI18n()

const mode = computed(() => (route.meta.mode === 'lab' ? 'lab' : 'portal'))
const base = computed(() => (mode.value === 'lab' ? '/sample-requests' : '/portal/requests'))
const listRoute = computed(() => ({ name: mode.value === 'lab' ? 'sample-requests' : 'portal-requests' }))

const options = ref(null)
const request = ref(null)
const form = reactive({ department_id: '', delivery_date: '', delivery_shift_id: '', contact_name: '', contact_phone: '', notes: '', items: [] })
const errors = ref({})
const loading = ref(true)
const loadError = ref('')
const busy = ref(false)
const saved = ref('')
const dialog = ref('') // submit | withdraw | cancel | delete

const id = computed(() => (route.params.id ? Number(route.params.id) : null))
const editable = computed(() => !id.value || !!request.value?.can?.edit)
const snapshot = () => JSON.stringify(toPayload(form))
const dirty = computed(() => editable.value && snapshot() !== saved.value)
const showDepartment = computed(() => (options.value?.departments?.length ?? 0) !== 1 || mode.value === 'lab')

const title = computed(() => {
  if (!id.value) return t('sreq.newTitle')
  if (request.value?.number) return t('sreq.requestTitle', { n: request.value.number })
  return t('sreq.draftTitle')
})

function fill(r) {
  request.value = r
  Object.assign(form, toForm(r))
  errors.value = {}
  saved.value = snapshot()
}

async function load() {
  loading.value = true
  loadError.value = ''
  try {
    const [opt, req] = await Promise.all([
      api.get(`${base.value}/options`),
      id.value ? api.get(`${base.value}/${id.value}`) : Promise.resolve(null),
    ])
    options.value = opt.data
    if (req) {
      // laboratoriya: yuborilgan so'rov — qabul sahifasida
      if (mode.value === 'lab' && !req.data.can?.edit) {
        router.replace({ name: 'sample-request', params: { id: req.data.id } })
        return
      }
      fill(req.data)
    } else {
      Object.assign(form, { department_id: '', delivery_date: '', delivery_shift_id: '', contact_name: '', contact_phone: '', notes: '', items: [blankItem()] })
      if (opt.data.departments?.length === 1) form.department_id = opt.data.departments[0].id
      // portal ko'rib chiqish rejimidan tanlangan bo'lim bilan kelgan bo'lsa
      const want = Number(route.query.department_id)
      if (want && opt.data.departments?.some((d) => Number(d.id) === want)) form.department_id = want
      if (mode.value === 'portal' && !opt.data.can_write) loadError.value = t('sreq.readOnly')
      saved.value = snapshot()
    }
  } catch (e) {
    loadError.value = e?.response?.status === 404 ? t('sreq.empty') : apiErrorMessage(e, t('sreq.loadError'))
  } finally {
    loading.value = false
  }
}

onMounted(load)
// boshqa buyurtmaga o'tish (bildirishnoma, ortga tugmasi) — komponent qayta ishlatiladi
watch(() => route.params.id, (v) => {
  if (v && Number(v) !== request.value?.id) load()
})

onBeforeRouteLeave(() => (dirty.value && !busy.value && !window.confirm(t('sreq.msg.unsaved')) ? false : true))

async function save(submit = false) {
  busy.value = true
  errors.value = {}
  try {
    const body = { ...toPayload(form), submit }
    const { data } = id.value
      ? await api.put(`${base.value}/${id.value}`, body)
      : await api.post(base.value, body)
    fill(data)
    if (submit) ui.success(t('sreq.msg.submitted', { n: data.number }))
    else ui.success(t('sreq.msg.saved'))
    if (!id.value) {
      await router.replace({ name: mode.value === 'lab' ? 'sample-request-edit' : 'portal-request', params: { id: data.id } })
    }
    if (submit && mode.value === 'lab') {
      router.replace({ name: 'sample-request', params: { id: data.id } })
    }
  } catch (e) {
    errors.value = e?.response?.data?.errors ?? {}
    ui.error(apiErrorMessage(e, t('sreq.loadError')))
    // «Saqlash va yuborish»: qoralama saqlangan, yuborilmagan — sahifa shu qoralamaga o'tadi
    const savedId = e?.response?.data?.saved_id
    if (savedId && !id.value) {
      saved.value = snapshot()
      request.value = { id: savedId } // kuzatuvchi qayta yuklamasin — xatolar saqlansin
      await router.replace({ name: mode.value === 'lab' ? 'sample-request-edit' : 'portal-request', params: { id: savedId } })
      const { data } = await api.get(`${base.value}/${savedId}`)
      const keep = errors.value
      fill(data)
      errors.value = keep
    } else if (savedId) {
      saved.value = snapshot()
    }
  } finally {
    busy.value = false
    dialog.value = ''
  }
}

async function act(action, body = {}) {
  busy.value = true
  try {
    if (action === 'delete') {
      await api.delete(`${base.value}/${id.value}`)
      ui.success(t('sreq.msg.deleted'))
      saved.value = snapshot()
      router.replace(listRoute.value)
      return
    }
    const { data } = await api.post(`${base.value}/${id.value}/${action}`, body)
    fill(data)
    ui.success(t(action === 'withdraw' ? 'sreq.msg.withdrawn' : 'sreq.msg.cancelled'))
  } catch (e) {
    ui.error(apiErrorMessage(e, t('sreq.loadError')))
  } finally {
    busy.value = false
    dialog.value = ''
  }
}

const sampleRoute = (s) => (mode.value === 'portal' ? { name: 'portal-sample', params: { id: s.id } } : { name: 'sample-detail', params: { id: s.id } })
</script>

<template>
  <div>
    <PageHeader :title="title">
      <template #actions>
        <button class="btn-ghost" @click="router.push(listRoute)"><ArrowLeft :size="16" /> {{ t('sreq.actions.back') }}</button>
      </template>
    </PageHeader>

    <div v-if="loading" class="space-y-3">
      <div class="h-28 rounded-panel bg-surface-sunken animate-pulse" />
      <div class="h-56 rounded-panel bg-surface-sunken animate-pulse" />
    </div>

    <p v-else-if="loadError" class="panel p-4 text-[14px] text-warn-dark dark:text-warn-light" data-load-error>{{ loadError }}</p>

    <template v-else>
      <div v-if="options?.preview" class="mb-4 flex items-center gap-2 rounded-md border border-aqua-300 bg-aqua-50 dark:bg-aqua-800/25 px-3 py-2 text-[13px] text-aqua-700 dark:text-aqua-200">
        <Eye :size="15" /> {{ t('sreq.previewBanner') }}
      </div>

      <!-- Tahrirlash: yangi yoki qoralama -->
      <template v-if="editable">
        <RequestInfo v-if="request" :request="request" :audience="mode" class="mb-4" />
        <RequestForm :form="form" :options="options" :errors="errors" :show-department="showDepartment" />

        <div class="sticky bottom-0 z-10 mt-4 pb-3">
          <div class="panel shadow-pop px-4 py-3 flex flex-wrap items-center gap-2">
            <button v-if="request?.can?.delete" class="btn-ghost text-crit" :disabled="busy" data-delete @click="dialog = 'delete'">
              <Trash2 :size="16" /> {{ t('sreq.actions.delete') }}
            </button>
            <span class="flex-1" />
            <span v-if="dirty" class="text-[12.5px] text-ink-faint">●</span>
            <button class="btn-outline" :disabled="busy" data-save @click="save(false)">
              <Save :size="16" /> {{ t('sreq.actions.saveDraft') }}
            </button>
            <button class="btn-primary" :disabled="busy || !form.items.length" data-submit @click="dialog = 'submit'">
              <Send :size="16" /> {{ t('sreq.actions.saveAndSubmit') }}
            </button>
          </div>
        </div>
      </template>

      <!-- Ko'rish: yuborilgan / yopilgan -->
      <template v-else-if="request">
        <RequestInfo :request="request" :audience="mode" class="mb-4" />
        <div class="flex flex-wrap gap-2 mb-4">
          <button v-if="request.can.print" class="btn-outline" data-print @click="printSlip(request, t, locale)">
            <Printer :size="16" /> {{ t('sreq.actions.print') }}
          </button>
          <span class="flex-1" />
          <button v-if="request.can.withdraw" class="btn-outline" :disabled="busy" data-withdraw @click="dialog = 'withdraw'">
            <Undo2 :size="16" /> {{ t('sreq.actions.withdraw') }}
          </button>
          <button v-if="request.can.cancel" class="btn-ghost text-crit" :disabled="busy" data-cancel @click="dialog = 'cancel'">
            <XCircle :size="16" /> {{ t('sreq.actions.cancel') }}
          </button>
        </div>
        <div class="panel overflow-hidden">
          <ItemsTable :items="request.items" :sample-route="sampleRoute" />
        </div>
      </template>
    </template>

    <ReasonDialog
      :model-value="dialog === 'submit'" :title="t('sreq.confirm.submitTitle')"
      :body="t('sreq.confirm.submitBody', { d: options?.expire_days ?? 14 })" :with-reason="false" :danger="false"
      :confirm-label="t('sreq.actions.submit')" :loading="busy"
      @update:model-value="!$event && (dialog = '')" @confirm="save(true)"
    />
    <ReasonDialog
      :model-value="dialog === 'withdraw'" :title="t('sreq.confirm.withdrawTitle')" :body="t('sreq.confirm.withdrawBody')"
      :with-reason="false" :danger="false" :confirm-label="t('sreq.actions.withdraw')" :loading="busy"
      @update:model-value="!$event && (dialog = '')" @confirm="act('withdraw')"
    />
    <ReasonDialog
      :model-value="dialog === 'cancel'" :title="t('sreq.confirm.cancelTitle')" :body="t('sreq.confirm.cancelBody')"
      :confirm-label="t('sreq.actions.cancel')" :loading="busy"
      @update:model-value="!$event && (dialog = '')" @confirm="(reason) => act('cancel', { reason })"
    />
    <ReasonDialog
      :model-value="dialog === 'delete'" :title="t('sreq.confirm.deleteTitle')" :body="t('sreq.confirm.deleteBody')"
      :with-reason="false" :confirm-label="t('sreq.actions.delete')" :loading="busy"
      @update:model-value="!$event && (dialog = '')" @confirm="act('delete')"
    />
  </div>
</template>
