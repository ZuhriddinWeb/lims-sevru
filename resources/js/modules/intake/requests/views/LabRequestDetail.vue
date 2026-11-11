<script setup>
/**
 * Laboratoriya — bitta buyurtma: namunani qabul qilish (probalar yaratiladi) yoki rad etish.
 * Buyurtmachi kiritgan ma'lumot faqat taklif: laborant har qatorni to'g'rilaydi.
 */
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { ArrowLeft, CheckCircle2, Inbox, KeyRound, PackageCheck, Printer, XCircle } from 'lucide-vue-next'
import api from '@/lib/api'
import PageHeader from '@/components/ui/PageHeader.vue'
import { useUiStore } from '@/stores/ui'
import RequestInfo from '../components/RequestInfo.vue'
import ItemsTable from '../components/ItemsTable.vue'
import AnalytePicker from '../components/AnalytePicker.vue'
import ReasonDialog from '../components/ReasonDialog.vue'
import { apiErrorMessage, fieldError, nameOf, printSlip } from '../shared'
import '../i18n'
import '../../i18n'
import '../i18nGroup' // [P30]
import '../i18nP33' // [P33] qabul: telefon, kim qabul qilib oldi; shifr takrori ogohlantirishi

const route = useRoute()
const router = useRouter()
const ui = useUiStore()
const { t, locale } = useI18n()

const request = ref(null)
const options = ref(null)
const loading = ref(true)
const loadError = ref('')
const busy = ref(false)
const rows = ref([])
const handedOverBy = ref('')
const handedOverPhone = ref('') // [P33]
const receivedBy = ref('')      // [P33] qabul qilib olgan xodim (ro'yxatdan)
const errors = ref({})
const sentIds = ref([])
const created = ref([])
const rejectOpen = ref(false)

const pending = computed(() => (request.value?.items ?? []).filter((i) => i.status === 'pending'))
const closedItems = computed(() => (request.value?.items ?? []).filter((i) => i.status !== 'pending'))
const takeCount = computed(() => rows.value.filter((r) => r.take).length)
/* [P33] shu qabulda takrorlangan shifr — faqat ogohlantirish */
const dupCodes = computed(() => {
  const seen = {}
  rows.value.filter((r) => r.take).forEach((r) => {
    const c = (r.customer_code || '').trim()
    if (c) seen[c] = (seen[c] || 0) + 1
  })
  return seen
})
const isDup = (row) => row.take && (dupCodes.value[(row.customer_code || '').trim()] || 0) > 1
const receivers = computed(() => options.value?.receivers ?? [])
const allTaken = computed({
  get: () => rows.value.length > 0 && rows.value.every((r) => r.take),
  set: (v) => rows.value.forEach((r) => { r.take = v }),
})

function buildRows(r) {
  rows.value = r.items.filter((i) => i.status === 'pending').map((i) => ({
    id: i.id,
    position: i.position,
    take: true,
    sample_number: '',
    customer_code: i.customer_code ?? '',
    state_of_matter: i.state_of_matter || 'solid',
    test_profile_id: i.test_profile_id ?? null,
    element_ids: [...(i.element_ids ?? [])],
    received_quantity: '',
    quantity_unit: i.quantity_unit || 'g',
    planned: i.quantity !== null && i.quantity !== undefined ? `${i.quantity} ${i.quantity_unit || ''}` : '',
    assigned_group_id: r.laboratory_group_id ?? '', // [P30] buyurtmachi tanlagan guruh
    group_code: i.group_code ?? '', // [P30] faqat o'sha guruh xodimiga keladi
    notes: i.notes ?? '',
  }))
  handedOverBy.value = r.handed_over_by || r.contact_name || ''
  handedOverPhone.value = r.handed_over_phone || r.contact_phone || ''
  // sukut bo'yicha — tizimga kirgan xodim (ro'yxatda bo'lsa)
  if (!receivedBy.value) {
    const me = options.value?.me
    receivedBy.value = receivers.value.some((u) => u.id === me) ? me : (receivers.value[0]?.id ?? '')
  }
}

async function load() {
  loading.value = true
  try {
    const [opt, req] = await Promise.all([
      api.get('/sample-requests/options'),
      api.get(`/sample-requests/${route.params.id}`),
    ])
    options.value = opt.data
    receivedBy.value = '' // [P33] buyurtma almashganda qayta tanlanadi
    if (req.data.can?.edit) {
      router.replace({ name: 'sample-request-edit', params: { id: req.data.id } })
      return
    }
    request.value = req.data
    buildRows(req.data)
  } catch (e) {
    loadError.value = apiErrorMessage(e, t('sreq.loadError'))
  } finally {
    loading.value = false
  }
}

onMounted(load)
watch(() => route.params.id, (v) => {
  if (v && Number(v) !== request.value?.id) { created.value = []; load() }
})

const rowErr = (row, key) => {
  const idx = sentIds.value.indexOf(row.id)
  return idx >= 0 ? fieldError(errors.value, `items.${idx}.${key}`) : ''
}

async function receive() {
  const chosen = rows.value.filter((r) => r.take)
  if (!chosen.length) return
  busy.value = true
  errors.value = {}
  sentIds.value = chosen.map((r) => r.id)
  try {
    const { data } = await api.post(`/sample-requests/${request.value.id}/receive`, {
      handed_over_by: handedOverBy.value || null,
      handed_over_phone: handedOverPhone.value || null, // [P33]
      received_by: receivedBy.value || null,            // [P33]
      items: chosen.map((r) => ({
        id: r.id,
        sample_number: r.sample_number || null,
        customer_code: r.customer_code || null,
        state_of_matter: r.state_of_matter,
        test_profile_id: r.test_profile_id || null,
        element_ids: r.element_ids,
        received_quantity: r.received_quantity === '' ? null : String(r.received_quantity).replace(',', '.'),
        quantity_unit: r.quantity_unit,
        assigned_group_id: r.assigned_group_id || null,
        notes: r.notes || null,
      })),
    })
    created.value = data.samples
    request.value = data.request
    buildRows(data.request)
    sentIds.value = []
    ui.success(t('sreq.msg.received', { n: data.samples.length }))
  } catch (e) {
    errors.value = e?.response?.data?.errors ?? {}
    ui.error(apiErrorMessage(e, t('sreq.loadError')))
  } finally {
    busy.value = false
  }
}

async function reject(reason) {
  busy.value = true
  try {
    const { data } = await api.post(`/sample-requests/${request.value.id}/reject`, { reason })
    request.value = data
    buildRows(data)
    ui.success(t('sreq.msg.rejected'))
    rejectOpen.value = false
  } catch (e) {
    ui.error(apiErrorMessage(e, t('sreq.loadError')))
  } finally {
    busy.value = false
  }
}

const sampleRoute = (s) => ({ name: 'sample-detail', params: { id: s.id } })
</script>

<template>
  <div>
    <PageHeader :title="request?.number ? t('sreq.requestTitle', { n: request.number }) : t('sreq.labTitle')">
      <template #actions>
        <button v-if="request?.can?.print" class="btn-outline" data-print @click="printSlip(request, t, locale)">
          <Printer :size="16" /> {{ t('sreq.actions.print') }}
        </button>
        <button class="btn-ghost" @click="router.push({ name: 'sample-requests' })"><ArrowLeft :size="16" /> {{ t('sreq.actions.back') }}</button>
      </template>
    </PageHeader>

    <div v-if="loading" class="space-y-3">
      <div class="h-28 rounded-panel bg-surface-sunken animate-pulse" />
      <div class="h-56 rounded-panel bg-surface-sunken animate-pulse" />
    </div>
    <p v-else-if="loadError" class="panel p-4 text-[14px] text-crit">{{ loadError }}</p>

    <template v-else-if="request">
      <RequestInfo :request="request" audience="lab" class="mb-4" />

      <!-- Yangi yaratilgan probalar -->
      <div v-if="created.length" class="panel mb-4 p-4 border-ok/40" data-created>
        <p class="flex items-center gap-2 text-[14px] font-semibold text-ok-dark dark:text-ok-light mb-2">
          <CheckCircle2 :size="17" /> {{ t('sreq.receive.done') }}
        </p>
        <div class="flex flex-wrap gap-2">
          <router-link
            v-for="s in created" :key="s.id" :to="sampleRoute(s)"
            class="inline-flex items-center gap-2 rounded-md border border-line px-2.5 py-1.5 text-[13px] hover:bg-surface-sunken"
          >
            <span class="font-mono font-semibold text-ink">{{ s.code }}</span>
            <span v-if="s.customer_code" class="text-ink-faint font-mono text-[12px]">{{ s.customer_code }}</span>
          </router-link>
        </div>
      </div>

      <!-- Qabul formasi -->
      <div v-if="request.can.receive && rows.length" class="panel mb-4" data-receive>
        <div class="px-4 py-3 border-b border-line">
          <h2 class="flex items-center gap-2 text-[15px] font-semibold text-ink"><PackageCheck :size="17" /> {{ t('sreq.receive.title') }}</h2>
          <p class="mt-1 text-[13px] text-ink-muted max-w-3xl">{{ t('sreq.receive.hint') }}</p>
        </div>

        <div class="px-4 py-3 border-b border-line flex flex-wrap items-end gap-4">
          <div class="min-w-[240px] flex-1 max-w-sm">
            <label class="field-label">{{ t('sreq.receive.handedOverBy') }}</label>
            <input v-model="handedOverBy" class="field-input" maxlength="191" :placeholder="t('sreq.p33.handedHint')" data-handed>
          </div>
          <!-- [P33] olib kelgan shaxs telefoni -->
          <div class="w-[200px]">
            <label class="field-label">{{ t('sreq.form.phone') }}</label>
            <input v-model="handedOverPhone" class="field-input" maxlength="64" inputmode="tel" data-handed-phone>
          </div>
          <!-- [P33] kim qabul qilib oldi — laboratoriya xodimlari ro'yxatidan -->
          <div class="min-w-[240px] flex-1 max-w-sm">
            <label class="field-label">{{ t('sreq.p33.receivedBy') }} *</label>
            <select v-model="receivedBy" class="field-input" :class="fieldError(errors, 'received_by') && 'field-input-error'" data-received-by>
              <option v-for="u in receivers" :key="u.id" :value="u.id">{{ u.name }}<template v-if="u.position"> — {{ u.position }}</template></option>
            </select>
            <p v-if="fieldError(errors, 'received_by')" class="mt-1 text-[12px] text-crit">{{ fieldError(errors, 'received_by') }}</p>
          </div>
          <label class="inline-flex items-center gap-2 pb-2 text-[13.5px] text-ink cursor-pointer">
            <input v-model="allTaken" type="checkbox" class="rounded border-line text-assay-500 focus:ring-assay-500" data-all>
            {{ t('sreq.receive.selectAll') }}
          </label>
        </div>
        <p v-if="fieldError(errors, 'items')" class="px-4 pt-3 text-[12.5px] text-crit">{{ fieldError(errors, 'items') }}</p>

        <div class="divide-y divide-line">
          <div
            v-for="row in rows" :key="row.id" :data-row="row.id"
            class="grid gap-3 px-4 py-3 grid-cols-[28px_1fr] xl:grid-cols-[28px_140px_140px_110px_minmax(220px,1fr)_170px_170px] items-start transition-opacity"
            :class="!row.take && 'opacity-50'"
          >
            <label class="pt-2 flex flex-col items-center gap-1 cursor-pointer" :title="t('sreq.receive.take')">
              <input v-model="row.take" type="checkbox" class="rounded border-line text-assay-500 focus:ring-assay-500" data-take>
              <span class="text-[11px] text-ink-faint tnum">{{ row.position }}</span>
            </label>

            <div class="min-w-0">
              <label class="field-label">{{ t('sreq.form.code') }}</label>
              <input v-model="row.customer_code" class="field-input h-[34px] font-mono" :class="rowErr(row, 'customer_code') && 'field-input-error'" :disabled="!row.take">
              <p v-if="rowErr(row, 'customer_code')" class="mt-1 text-[12px] text-crit">{{ rowErr(row, 'customer_code') }}</p>
              <p v-else-if="isDup(row)" class="mt-1 text-[12px] text-warn-dark dark:text-warn-light" data-dup-warn>{{ t('sreq.form.dupCodeWarn') }}</p>
              <p v-if="row.group_code" class="mt-1 flex items-center gap-1 font-mono text-[12px] text-assay-700 dark:text-assay-200" :title="t('sreq.group.codeHint')" data-row-gcode>
                <KeyRound :size="12" class="shrink-0" /> {{ row.group_code }}
              </p>
            </div>
            <div class="col-start-2 xl:col-start-auto min-w-0">
              <label class="field-label">{{ t('sreq.receive.sampleNumber') }}</label>
              <input v-model="row.sample_number" class="field-input h-[34px]" :placeholder="row.customer_code || '—'" :disabled="!row.take">
            </div>
            <div class="col-start-2 xl:col-start-auto">
              <label class="field-label">{{ t('sreq.form.state') }}</label>
              <select v-model="row.state_of_matter" class="field-input h-[34px]" :disabled="!row.take">
                <option value="solid">{{ t('sreq.form.states.solid') }}</option>
                <option value="liquid">{{ t('sreq.form.states.liquid') }}</option>
              </select>
            </div>
            <div class="col-start-2 xl:col-start-auto min-w-0">
              <label class="field-label">{{ t('sreq.form.what') }}</label>
              <AnalytePicker
                v-model:profile-id="row.test_profile_id" v-model:element-ids="row.element_ids"
                :elements="options.elements" :profiles="options.profiles" :disabled="!row.take" :error="rowErr(row, 'element_ids')"
              />
            </div>
            <div class="col-start-2 xl:col-start-auto">
              <label class="field-label">{{ t('sreq.receive.receivedQty') }}</label>
              <div class="flex gap-1.5">
                <input v-model="row.received_quantity" inputmode="decimal" class="field-input h-[34px] tnum flex-1 min-w-0" :class="rowErr(row, 'received_quantity') && 'field-input-error'" :disabled="!row.take" data-qty>
                <select v-model="row.quantity_unit" class="field-input h-[34px] w-[64px] px-2" :disabled="!row.take">
                  <option v-for="u in options.units" :key="u" :value="u">{{ u }}</option>
                </select>
              </div>
              <p v-if="row.planned" class="mt-1 text-[11.5px] text-ink-faint">{{ t('sreq.receive.planned', { q: row.planned }) }}</p>
            </div>
            <div class="col-start-2 xl:col-start-auto">
              <label class="field-label">{{ t('sreq.receive.group') }}</label>
              <select v-model="row.assigned_group_id" class="field-input h-[34px]" :disabled="!row.take">
                <option value="">{{ t('sreq.receive.noGroup') }}</option>
                <option v-for="g in options.groups" :key="g.id" :value="g.id">{{ nameOf(g, locale) }}</option>
              </select>
            </div>
            <div v-if="row.notes" class="col-start-2 xl:col-span-6 text-[12.5px] text-ink-muted">
              {{ t('sreq.form.itemNotes') }}: {{ row.notes }}
            </div>
          </div>
        </div>

        <div class="px-4 py-3 border-t border-line bg-surface-sunken/40 flex flex-wrap items-center gap-2 rounded-b-panel">
          <button v-if="request.can.reject" class="btn-ghost text-crit" :disabled="busy" data-reject @click="rejectOpen = true">
            <XCircle :size="16" /> {{ t('sreq.actions.reject') }}
          </button>
          <span class="flex-1" />
          <button class="btn-primary" :disabled="busy || !takeCount" data-do-receive @click="receive">
            <PackageCheck :size="16" /> {{ t('sreq.actions.receive') }} <span class="tnum">({{ takeCount }})</span>
          </button>
        </div>
      </div>

      <p v-else-if="request.can.receive" class="panel mb-4 p-4 flex items-center gap-2 text-[13.5px] text-ink-muted"><Inbox :size="16" /> {{ t('sreq.receive.nothing') }}</p>

      <!-- Qabul qilingan / rad etilgan namunalar -->
      <div v-if="closedItems.length || !request.can.receive" class="panel overflow-hidden">
        <ItemsTable :items="request.can.receive ? closedItems : request.items" :sample-route="sampleRoute" />
      </div>
      <p v-if="pending.length && !request.can.receive" class="mt-3 text-[12.5px] text-ink-faint">{{ t('sreq.progress', { r: request.counts.received, n: request.counts.items }) }}</p>
    </template>

    <ReasonDialog
      v-model="rejectOpen" :title="t('sreq.confirm.rejectTitle')" :body="t('sreq.confirm.rejectBody')"
      :placeholder="t('sreq.confirm.rejectPlaceholder')" required :confirm-label="t('sreq.actions.reject')" :loading="busy"
      @confirm="reject"
    />
  </div>
</template>
