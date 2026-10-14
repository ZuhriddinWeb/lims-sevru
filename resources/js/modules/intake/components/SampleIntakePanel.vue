<script setup>
/**
 * Proba kartochkasidagi panel: buyurtmachi bo'lim va shifr, qabul qilingan miqdor, sarf va qoldiq,
 * qo'lda harakatlar (boshqa sarf, qaytarish, utilizatsiya, tuzatish), nazorat tahlili solishtiruvi.
 *
 *   import SampleIntakePanel from '@/modules/intake/components/SampleIntakePanel.vue'
 *   <SampleIntakePanel v-if="sample" :sample="sample" />
 */
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { AlertTriangle, Building2, FileSpreadsheet, Pencil, Plus, Scale, ShieldCheck, Undo2 } from 'lucide-vue-next'
import api from '@/lib/api'
import BaseModal from '@/components/ui/BaseModal.vue'
import ConfirmDialog from '@/components/ui/ConfirmDialog.vue'
import { useAuthStore } from '@/stores/auth'
import { useUiStore } from '@/stores/ui'
import { formatDate, formatDateTime } from '@/lib/format'
import ComparisonTable from './ComparisonTable.vue'
import { MOVEMENT_KINDS, STATE_TONE, UNITS, apiErrorMessage, formatQty, templateName, unitLabel } from '../helpers'
import '../i18n'

const props = defineProps({ sample: { type: Object, required: true } })
const emit = defineEmits(['changed'])

const { t, locale } = useI18n()
const router = useRouter()
const auth = useAuthStore()
const ui = useUiStore()

const data = ref(null)
const loading = ref(true)
const busy = ref(false)
const canManage = computed(() => auth.can('sample_quantity.manage'))

async function load() {
  if (!props.sample?.id) return
  loading.value = true
  try {
    const res = await api.get(`/samples/${props.sample.id}/intake`)
    data.value = res.data
  } catch (e) {
    data.value = null
    ui.error(apiErrorMessage(e, t('intake.loadError')))
  } finally {
    loading.value = false
  }
}
onMounted(load)
watch(() => props.sample?.id, load)

const intake = computed(() => data.value?.intake ?? {})
const L = computed(() => data.value?.ledger ?? null)
const u = computed(() => unitLabel(L.value?.unit, t))
const hasQty = computed(() => L.value && L.value.received !== null)
const q = (v) => formatQty(v, locale.value)

// Sarf ulushlari (chiziq uchun), qabul qilingan miqdorga nisbatan
const bars = computed(() => {
  const l = L.value
  if (!l || !l.received) return []
  const pct = (v) => Math.max(0, Math.min(100, (Number(v) || 0) / l.received * 100))
  return [
    { key: 'main', w: pct(l.consumed.main), cls: 'bg-assay-500' },
    { key: 'control', w: pct(l.consumed.control), cls: 'bg-aqua-500' },
    { key: 'other', w: pct(l.consumed.other), cls: 'bg-warn' },
  ]
})
const retainedPct = computed(() => (L.value?.received && L.value.retained ? Math.min(100, L.value.retained / L.value.received * 100) : 0))

/* ------------------------------------------------ qabul qilingan miqdor */
const qtyOpen = ref(false)
const qtyForm = reactive({ received_quantity: '', quantity_unit: 'g', retained_quantity: '' })
const qtyErrors = ref({})
function openQty() {
  qtyForm.received_quantity = intake.value.received_quantity ?? ''
  qtyForm.quantity_unit = intake.value.quantity_unit || 'g'
  qtyForm.retained_quantity = intake.value.retained_quantity ?? ''
  qtyErrors.value = {}
  qtyOpen.value = true
}
async function saveQty() {
  busy.value = true
  try {
    const res = await api.put(`/samples/${props.sample.id}/quantity`, {
      received_quantity: String(qtyForm.received_quantity).replace(',', '.'),
      quantity_unit: qtyForm.quantity_unit,
      retained_quantity: qtyForm.retained_quantity === '' || qtyForm.retained_quantity === null ? null : String(qtyForm.retained_quantity).replace(',', '.'),
    })
    data.value = res.data
    qtyOpen.value = false
    ui.success(t('intake.saved'))
    emit('changed')
  } catch (e) {
    qtyErrors.value = e?.response?.data?.errors ?? {}
    ui.error(apiErrorMessage(e, t('intake.saveError')))
  } finally {
    busy.value = false
  }
}

/* ------------------------------------------------------- harakatlar */
const moveOpen = ref(false)
const moveForm = reactive({ kind: 'other', amount: '', sign: -1, note: '' })
const moveErrors = ref({})
const overBalance = ref(null) // 409 — qoldiqdan ko'p
function openMove() {
  Object.assign(moveForm, { kind: 'other', amount: '', sign: -1, note: '' })
  moveErrors.value = {}
  overBalance.value = null
  moveOpen.value = true
}
watch(() => [moveForm.kind, moveForm.amount, moveForm.sign], () => { overBalance.value = null })

async function saveMove(confirm = false) {
  const amount = Number(String(moveForm.amount).replace(',', '.'))
  busy.value = true
  try {
    const res = await api.post(`/samples/${props.sample.id}/quantity/movements`, {
      kind: moveForm.kind,
      quantity: moveForm.kind === 'correction' ? moveForm.sign * amount : amount,
      note: moveForm.note || null,
      confirm: confirm || undefined,
    })
    data.value = res.data
    moveOpen.value = false
    ui.success(t('intake.saved'))
    emit('changed')
  } catch (e) {
    if (e?.response?.status === 409 && e.response.data?.need_confirm) {
      overBalance.value = e.response.data
    } else {
      moveErrors.value = e?.response?.data?.errors ?? {}
      ui.error(apiErrorMessage(e, t('intake.saveError')))
    }
  } finally {
    busy.value = false
  }
}

const cancelTarget = ref(null)
async function cancelMove() {
  const m = cancelTarget.value
  cancelTarget.value = null
  if (!m) return
  busy.value = true
  try {
    const res = await api.delete(`/samples/${props.sample.id}/quantity/movements/${m.id}`)
    data.value = res.data
    emit('changed')
  } catch (e) {
    ui.error(apiErrorMessage(e, t('intake.saveError')))
  } finally {
    busy.value = false
  }
}

const fieldErr = (bag, k) => (bag[k] ? (Array.isArray(bag[k]) ? bag[k][0] : bag[k]) : '')
const kindSign = (m) => (m.delta > 0 ? '+' : '−')
</script>

<template>
  <section class="panel p-4" data-intake-panel>
    <div class="flex flex-wrap items-center justify-between gap-3 mb-3">
      <h3 class="flex items-center gap-2 text-[15px] font-semibold text-ink">
        <Scale :size="17" class="text-assay-500" /> {{ t('intake.panel.title') }}
      </h3>
      <div v-if="canManage && data" class="flex items-center gap-1.5">
        <button class="btn-ghost btn-sm" data-edit-qty @click="openQty">
          <Pencil :size="14" /> {{ hasQty ? t('intake.panel.editQuantity') : t('intake.panel.setQuantity') }}
        </button>
        <button v-if="hasQty" class="btn-outline btn-sm" data-add-move @click="openMove">
          <Plus :size="15" /> {{ t('intake.panel.addMovement') }}
        </button>
      </div>
    </div>

    <div v-if="loading && !data" class="space-y-2">
      <div class="h-6 rounded bg-surface-sunken animate-pulse" />
      <div class="h-14 rounded bg-surface-sunken animate-pulse" />
    </div>

    <template v-else-if="data">
      <!-- Buyurtmachi -->
      <div class="flex flex-wrap items-center gap-x-5 gap-y-1 text-[13.5px] mb-3">
        <span v-if="intake.customer_department" class="flex items-center gap-1.5 text-ink" data-dept>
          <Building2 :size="15" class="text-ink-faint" />
          {{ intake.customer_department.name }}
          <span v-if="intake.customer_department.code" class="text-ink-faint font-mono text-[12px]">({{ intake.customer_department.code }})</span>
        </span>
        <span v-if="intake.customer_code" class="text-ink-muted">
          {{ t('intake.customerCode') }}: <b class="text-ink font-semibold" data-ccode>{{ intake.customer_code }}</b>
        </span>
        <span v-if="intake.registered_by" class="text-[12.5px] text-ink-faint">
          {{ t('intake.panel.receivedBy') }}: {{ intake.registered_by }}, {{ formatDateTime(intake.registered_at) }}
        </span>
      </div>

      <p v-if="!hasQty" class="rounded-md bg-surface-sunken px-3 py-2.5 text-[13px] text-ink-muted" data-no-qty>
        {{ t('intake.panel.noQuantity') }}
      </p>

      <template v-else>
        <!-- Ko'rsatkichlar -->
        <div class="grid gap-2 grid-cols-2 sm:grid-cols-4 mb-3" data-stats>
          <div class="rounded-md bg-surface-sunken px-3 py-2">
            <p class="text-[11.5px] text-ink-muted">{{ t('intake.panel.received') }}</p>
            <p class="text-[16px] font-semibold tnum text-ink">{{ q(L.received) }} <span class="text-[12px] font-normal text-ink-muted">{{ u }}</span></p>
          </div>
          <div class="rounded-md bg-surface-sunken px-3 py-2">
            <p class="text-[11.5px] text-ink-muted">{{ t('intake.panel.consumed') }}</p>
            <p class="text-[13px] tnum text-ink leading-snug">
              <span class="inline-block h-2 w-2 rounded-full bg-assay-500 mr-1" />{{ q(L.consumed.main) }}
              <span class="inline-block h-2 w-2 rounded-full bg-aqua-500 ml-2 mr-1" />{{ q(L.consumed.control) }}
              <template v-if="L.consumed.other"><span class="inline-block h-2 w-2 rounded-full bg-warn ml-2 mr-1" />{{ q(L.consumed.other) }}</template>
            </p>
            <p v-if="L.planned" class="text-[11px] text-ink-faint" :title="t('intake.panel.plannedHint')">{{ t('intake.panel.planned', { n: `${q(L.planned)} ${u}` }) }}</p>
          </div>
          <div class="rounded-md bg-surface-sunken px-3 py-2">
            <p class="text-[11.5px] text-ink-muted">{{ t('intake.panel.balance') }}</p>
            <p class="text-[16px] font-semibold tnum" :class="L.balance < 0 ? 'text-crit' : 'text-ink'" data-balance>
              {{ q(L.balance) }} <span class="text-[12px] font-normal text-ink-muted">{{ u }}</span>
            </p>
            <span class="inline-block mt-0.5 rounded-full px-1.5 py-px text-[11px] font-medium" :class="STATE_TONE[L.state]" :data-state="L.state">{{ t(`intake.panel.state.${L.state}`) }}</span>
          </div>
          <div class="rounded-md bg-surface-sunken px-3 py-2" :title="t('intake.panel.retainedHint')">
            <p class="text-[11.5px] text-ink-muted">{{ t('intake.panel.retained') }} · {{ t('intake.panel.available') }}</p>
            <p class="text-[13px] tnum text-ink">{{ L.retained !== null ? q(L.retained) : '—' }} · <b>{{ q(L.available) }}</b> {{ u }}</p>
            <p v-if="L.corrections" class="text-[11px] text-ink-faint">{{ t('intake.panel.corrections') }}: {{ L.corrections > 0 ? '+' : '' }}{{ q(L.corrections) }}</p>
          </div>
        </div>

        <!-- Chiziq: sarf ulushlari -->
        <div class="relative h-2 rounded-full bg-surface-sunken overflow-hidden flex mb-3" aria-hidden="true">
          <span v-for="b in bars" :key="b.key" class="h-full" :class="b.cls" :style="{ width: `${b.w}%` }" />
          <span v-if="retainedPct" class="absolute right-0 top-0 h-full border-l-2 border-ink/40 bg-ink/10" :style="{ width: `${retainedPct}%` }" />
        </div>
      </template>

      <!-- Sarf tafsiloti -->
      <div v-if="L && (L.worksheets?.length || L.movements?.length)" class="rounded-md border border-line overflow-x-auto">
        <table class="w-full text-[13px]" data-usage>
          <tbody class="divide-y divide-line">
            <tr v-for="w in L.worksheets" :key="`w${w.id}`" class="hover:bg-surface-sunken/60 cursor-pointer" @click="router.push({ name: 'worksheet-detail', params: { id: w.id } })">
              <td class="px-3 py-1.5 whitespace-nowrap">
                <FileSpreadsheet :size="13" class="inline -mt-0.5 mr-1 text-ink-faint" />
                <span class="font-mono font-semibold text-ink">{{ w.number }}</span>
                <span v-if="w.kind === 'control'" class="ml-1.5 inline-flex items-center gap-0.5 rounded-full bg-aqua-50 text-aqua-700 dark:bg-aqua-800/35 dark:text-aqua-200 px-1.5 py-px text-[10.5px] font-medium">
                  <ShieldCheck :size="10" /> {{ t('intake.panel.controlBadge') }}
                </span>
              </td>
              <td class="px-3 py-1.5 text-ink-muted truncate max-w-[220px]">{{ templateName({ code: w.template_code, name_uz: w.template_name_uz, name_ru: w.template_name_ru }, locale) }}</td>
              <td class="px-3 py-1.5 text-ink-muted tnum whitespace-nowrap">{{ formatDate(w.worksheet_date) }}</td>
              <td class="px-3 py-1.5 text-ink-muted whitespace-nowrap">{{ t('intake.panel.parallels', { n: w.parallels }) }}</td>
              <td class="px-3 py-1.5 text-right tnum whitespace-nowrap">
                <template v-if="w.issue === 'no_column'"><span class="text-[12px] text-ink-faint">{{ t('intake.panel.noColumn') }}</span></template>
                <template v-else-if="w.issue === 'unit_mismatch'">
                  <span class="text-[12px] text-warn-dark dark:text-warn-light"><AlertTriangle :size="12" class="inline -mt-0.5" /> {{ q(w.quantity) }} {{ w.column_unit }} — {{ t('intake.panel.unitMismatch') }}</span>
                </template>
                <template v-else>
                  <span class="text-ink">−{{ q(w.quantity_converted) }} {{ u }}</span>
                  <span v-if="w.planned" class="ml-1 rounded bg-surface-sunken px-1 text-[10.5px] text-ink-muted" :title="t('intake.panel.plannedHint')">{{ t('intake.panel.plannedBadge') }}</span>
                </template>
              </td>
              <td class="px-3 py-1.5 w-px" />
            </tr>
            <tr v-for="m in L.movements" :key="`m${m.id}`" :data-move="m.id">
              <td class="px-3 py-1.5 whitespace-nowrap text-ink">
                <Undo2 v-if="m.kind === 'return'" :size="13" class="inline -mt-0.5 mr-1 text-ink-faint" />
                {{ t(`intake.panel.kinds.${m.kind}`) }}
              </td>
              <td class="px-3 py-1.5 text-ink-muted truncate max-w-[220px]" :title="m.note || ''">{{ m.note || '—' }}</td>
              <td class="px-3 py-1.5 text-ink-muted tnum whitespace-nowrap">{{ formatDate(m.created_at) }}</td>
              <td class="px-3 py-1.5 text-ink-faint whitespace-nowrap">{{ m.created_by?.name ?? '' }}</td>
              <td class="px-3 py-1.5 text-right tnum whitespace-nowrap" :class="m.delta > 0 ? 'text-ok-dark dark:text-ok-light' : 'text-ink'">
                {{ kindSign(m) }}{{ q(Math.abs(m.delta)) }} {{ unitLabel(m.unit, t) }}
              </td>
              <td class="px-3 py-1.5 w-px text-right">
                <button v-if="canManage" class="btn-ghost btn-sm text-[12px]" :title="t('intake.panel.cancelTitle')" @click="cancelTarget = m">
                  {{ t('intake.panel.cancelMovement') }}
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <p v-else-if="hasQty" class="text-[12.5px] text-ink-faint">{{ t('intake.panel.noUsage') }}</p>

      <!-- Nazorat tahlili -->
      <div v-if="data.comparison?.length" class="mt-4">
        <h4 class="flex items-center gap-1.5 text-[13.5px] font-semibold text-ink">
          <ShieldCheck :size="15" class="text-aqua-600" /> {{ t('intake.panel.compareTitle') }}
        </h4>
        <p class="text-[12px] text-ink-faint mb-2">{{ t('intake.panel.compareHint') }}</p>
        <ComparisonTable :items="data.comparison" />
      </div>
    </template>

    <!-- Miqdor formasi -->
    <BaseModal v-model="qtyOpen" :title="t('intake.panel.quantityTitle')" :subtitle="data?.sample?.code ?? sample.code" size="sm">
      <div class="grid gap-3 grid-cols-[minmax(0,1fr)_110px]">
        <div>
          <label class="field-label">{{ t('intake.quantity') }}</label>
          <input v-model="qtyForm.received_quantity" inputmode="decimal" class="field-input tnum" data-f="received">
          <p v-if="fieldErr(qtyErrors, 'received_quantity')" class="mt-1.5 text-[12.5px] text-crit">{{ fieldErr(qtyErrors, 'received_quantity') }}</p>
        </div>
        <div>
          <label class="field-label">{{ t('intake.unit') }}</label>
          <select v-model="qtyForm.quantity_unit" class="field-input" data-f="unit">
            <option v-for="x in UNITS" :key="x" :value="x">{{ unitLabel(x, t) }}</option>
          </select>
        </div>
        <div class="col-span-2">
          <label class="field-label">{{ t('intake.panel.retained') }}</label>
          <input v-model="qtyForm.retained_quantity" inputmode="decimal" class="field-input tnum" data-f="retained">
          <p class="mt-1.5 text-[12.5px] text-ink-faint">{{ t('intake.panel.retainedHint') }}</p>
          <p v-if="fieldErr(qtyErrors, 'retained_quantity')" class="mt-1.5 text-[12.5px] text-crit">{{ fieldErr(qtyErrors, 'retained_quantity') }}</p>
        </div>
      </div>
      <template #footer>
        <button class="btn-ghost" @click="qtyOpen = false">{{ t('common.cancel') }}</button>
        <button class="btn-primary" :disabled="busy || qtyForm.received_quantity === ''" data-save-qty @click="saveQty">{{ t('common.save') }}</button>
      </template>
    </BaseModal>

    <!-- Harakat formasi -->
    <BaseModal v-model="moveOpen" :title="t('intake.panel.movementTitle')" :subtitle="data?.sample?.code ?? sample.code" size="sm">
      <div class="space-y-3">
        <div>
          <label class="field-label">{{ t('intake.panel.kind') }}</label>
          <select v-model="moveForm.kind" class="field-input" data-f="kind">
            <option v-for="k in MOVEMENT_KINDS" :key="k" :value="k">{{ t(`intake.panel.kinds.${k}`) }}</option>
          </select>
          <p class="mt-1.5 text-[12.5px] text-ink-faint">{{ t(`intake.panel.kindHints.${moveForm.kind}`) }}</p>
        </div>
        <div class="grid gap-3" :class="moveForm.kind === 'correction' ? 'grid-cols-2' : 'grid-cols-1'">
          <div v-if="moveForm.kind === 'correction'">
            <label class="field-label">{{ t('intake.panel.direction') }}</label>
            <select v-model.number="moveForm.sign" class="field-input" data-f="sign">
              <option :value="1">{{ t('intake.panel.plus') }}</option>
              <option :value="-1">{{ t('intake.panel.minus') }}</option>
            </select>
          </div>
          <div>
            <label class="field-label">{{ t('intake.panel.amount') }}, {{ u }}</label>
            <input v-model="moveForm.amount" inputmode="decimal" class="field-input tnum" data-f="amount">
            <p v-if="fieldErr(moveErrors, 'quantity')" class="mt-1.5 text-[12.5px] text-crit">{{ fieldErr(moveErrors, 'quantity') }}</p>
          </div>
        </div>
        <div>
          <label class="field-label">{{ t('intake.panel.note') }}</label>
          <input v-model="moveForm.note" class="field-input" maxlength="500" data-f="note">
          <p v-if="fieldErr(moveErrors, 'note')" class="mt-1.5 text-[12.5px] text-crit">{{ fieldErr(moveErrors, 'note') }}</p>
        </div>
        <p v-if="overBalance" class="rounded-md border border-warn/50 bg-warn-light/70 dark:bg-warn-dark/20 px-3 py-2 text-[13px] text-warn-dark dark:text-warn-light" data-over>
          <AlertTriangle :size="14" class="inline -mt-0.5" />
          {{ t('intake.panel.overBalance', { n: q(overBalance.balance), unit: unitLabel(overBalance.unit, t) }) }}
        </p>
      </div>
      <template #footer>
        <button class="btn-ghost" @click="moveOpen = false">{{ t('common.cancel') }}</button>
        <button v-if="overBalance" class="btn-primary" :disabled="busy" data-write-anyway @click="saveMove(true)">{{ t('intake.panel.writeAnyway') }}</button>
        <button v-else class="btn-primary" :disabled="busy || moveForm.amount === ''" data-save-move @click="saveMove()">{{ t('common.save') }}</button>
      </template>
    </BaseModal>

    <ConfirmDialog
      :model-value="!!cancelTarget" :title="t('intake.panel.cancelTitle')" :body="t('intake.panel.cancelBody')"
      :confirm-label="t('intake.panel.cancelMovement')" :cancel-label="t('common.cancel')"
      @update:model-value="!$event && (cancelTarget = null)" @confirm="cancelMove"
    />
  </section>
</template>
