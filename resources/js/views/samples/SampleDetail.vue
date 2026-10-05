<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { ArrowLeft, Check, FileText, FlaskConical } from 'lucide-vue-next'
import api from '@/lib/api'
import StatusBadge from '@/components/ui/StatusBadge.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import { useAuthStore } from '@/stores/auth'
import { useUiStore } from '@/stores/ui'
import { formatDateTime, formatNumber } from '@/lib/format'
import { localized } from '@/i18n'

const route = useRoute()
const router = useRouter()
const { t } = useI18n()
const auth = useAuthStore()
const ui = useUiStore()

const sample = ref(null)
const loading = ref(true)
const saving = ref(false)

// TZ 1.7/1.8 — sinovga yaroqli uskunalar (bloklanganlar ro'yxatga tushmaydi)
const equipmentList = ref([])

async function loadEquipment() {
  try {
    const { data } = await api.get('/equipment', { params: { all: 1, usable: 1 } })
    equipmentList.value = Array.isArray(data) ? data : (data.data ?? [])
  } catch {
    equipmentList.value = []
  }
}

async function load() {
  loading.value = true
  try {
    const { data } = await api.get(`/samples/${route.params.id}`)
    sample.value = data
  } catch {
    ui.error(t('toast.error'))
  } finally {
    loading.value = false
  }
}
onMounted(() => { load(); loadEquipment() })

/* Natija kiritish */
const resultOpen = ref(false)
const resultError = ref('')
const activeElement = ref(null)
const resultForm = ref({ result_value: '', result_unit: '', equipment_id: null, method: '', notes: '' })

function openResult(se) {
  activeElement.value = se
  resultForm.value = {
    result_value: se.result_value ?? '',
    result_unit: se.result_unit ?? se.element?.default_unit ?? '',
    equipment_id: se.equipment_id ?? null,
    method: se.method ?? '',
    notes: se.notes ?? '',
  }
  resultError.value = ''
  resultOpen.value = true
}

async function saveResult() {
  saving.value = true
  resultError.value = ''
  try {
    const { data } = await api.put(
      `/samples/${sample.value.id}/elements/${activeElement.value.id}/result`,
      resultForm.value
    )
    sample.value = data
    ui.success(t('sample.resultSaved'))
    resultOpen.value = false
    loadEquipment() // hisoblagich o'zgargan bo'lishi mumkin
  } catch (e) {
    // Bloklangan uskuna yoki muddati o'tgan kalibrovka — serverdan aniq sabab keladi
    resultError.value = e.response?.data?.errors?.equipment_id?.[0]
      || e.response?.data?.message
      || t('toast.error')
    ui.error(resultError.value)
  } finally {
    saving.value = false
  }
}

const canEnterResult = computed(() =>
  auth.can('samples.result') && sample.value?.status !== 'archived'
)

/* Proba yo'li — bosqichlar ketma-ketligi */
const steps = computed(() => {
  const s = sample.value
  if (!s) return []
  return [
    { key: 'flowRegistered', at: s.registered_at, done: !!s.registered_at },
    { key: 'flowAssigned', at: s.assigned_at, done: !!s.assigned_at },
    { key: 'flowProgress', at: s.started_at, done: ['in_progress', 'completed', 'archived'].includes(s.status) },
    { key: 'flowDone', at: s.completed_at, done: !!s.completed_at },
  ]
})

const facts = computed(() => {
  const s = sample.value
  if (!s) return []
  return [
    ['sample.number', s.sample_number],
    ['sample.sourceDocument', s.source_document],
    ['sample.sourceDivision', s.source_division],
    ['sample.customerGroup', localized(s.customer_group, 'name')],
    ['sample.assignedGroup', localized(s.assigned_group, 'name')],
    ['sample.stateOfMatter', t(`sample.${s.state_of_matter}`)],
    ['sample.registeredAt', formatDateTime(s.registered_at)],
    ['work.startedAt', s.started_at ? `${formatDateTime(s.started_at)}${s.starter ? ' · ' + s.starter.name : ''}` : null],
    ['sample.completedAt', formatDateTime(s.completed_at)],
  ]
})
</script>

<template>
  <div v-if="loading" class="space-y-4">
    <div class="h-8 w-48 rounded bg-surface-sunken animate-pulse" />
    <div class="h-40 panel animate-pulse" />
  </div>

  <div v-else-if="sample">
    <!-- Sarlavha -->
    <div class="flex flex-wrap items-start justify-between gap-4 mb-5">
      <div class="flex items-start gap-3 min-w-0">
        <button class="btn-ghost btn-icon mt-0.5" @click="router.push({ name: 'samples' })">
          <ArrowLeft :size="18" />
        </button>
        <div class="min-w-0">
          <div class="flex items-center gap-3 flex-wrap">
            <h1 class="text-[22px] font-semibold tracking-[-.01em] text-ink font-mono">{{ sample.code }}</h1>
            <StatusBadge :status="sample.status" />
          </div>
          <p class="mt-1 text-[14px] text-ink-muted">
            {{ sample.sample_number }} · {{ localized(sample.customer_group, 'name') }}
          </p>
        </div>
      </div>

      <div class="flex items-center gap-3">
        <button
          v-if="sample.progress > 0"
          class="btn-outline"
          @click="router.push({ name: 'sample-protocol', params: { id: sample.id } })"
        >
          <FileText :size="16" /> {{ t('report.protocol') }}
        </button>
        <div class="text-right">
          <p class="text-[22px] font-semibold tnum leading-none"
             :class="sample.progress === 100 ? 'text-ok' : 'text-ink'">{{ sample.progress }}%</p>
          <p class="text-[12px] text-ink-faint mt-1">{{ t('sample.progress') }}</p>
        </div>
      </div>
    </div>

    <!-- Proba harakati -->
    <section class="panel p-4 mb-5">
      <h2 class="text-[13px] font-semibold text-ink mb-4">{{ t('sample.flowTitle') }}</h2>
      <ol class="flex items-start gap-1">
        <li v-for="(step, i) in steps" :key="step.key" class="flex-1 min-w-0">
          <div class="flex items-center gap-2">
            <span
              class="h-6 w-6 rounded-full grid place-items-center shrink-0 text-[11px] font-semibold transition-colors"
              :class="step.done
                ? 'bg-assay-500 text-white'
                : 'bg-surface-sunken text-ink-faint border border-line'"
            >
              <Check v-if="step.done" :size="13" :stroke-width="3" />
              <span v-else>{{ i + 1 }}</span>
            </span>
            <span
              v-if="i < steps.length - 1"
              class="h-0.5 flex-1 rounded transition-colors"
              :class="steps[i + 1].done ? 'bg-assay-400' : 'bg-line'"
            />
          </div>
          <p class="mt-2 text-[12.5px] font-medium" :class="step.done ? 'text-ink' : 'text-ink-faint'">
            {{ t(`sample.${step.key}`) }}
          </p>
          <p v-if="step.at" class="text-[11.5px] text-ink-faint tnum">{{ formatDateTime(step.at) }}</p>
        </li>
      </ol>
    </section>

    <div class="grid gap-5 lg:grid-cols-[1fr_320px]">
      <!-- Natijalar -->
      <section class="panel overflow-hidden">
        <header class="flex items-center justify-between gap-2 px-4 py-3.5 border-b border-line">
          <h2 class="text-[14px] font-semibold text-ink">{{ t('sample.results') }}</h2>
          <span class="text-[12.5px] text-ink-faint tnum">
            {{ sample.sample_elements?.filter(e => e.status === 'done').length ?? 0 }}
            / {{ sample.sample_elements?.length ?? 0 }}
          </span>
        </header>

        <EmptyState v-if="!sample.sample_elements?.length" :icon="FlaskConical" :title="t('sample.noResults')" />

        <table v-else class="w-full text-left">
          <thead>
            <tr class="border-b border-line bg-surface-sunken/50 text-[12.5px] font-semibold text-ink-muted">
              <th class="px-4 py-2.5">{{ t('water.parameter') }}</th>
              <th class="px-4 py-2.5 text-right">{{ t('sample.resultValue') }}</th>
              <th class="px-4 py-2.5">{{ t('sample.resultUnit') }}</th>
              <th class="px-4 py-2.5">{{ t('usage.equipment') }}</th>
              <th class="px-4 py-2.5">{{ t('sample.completedAt') }}</th>
              <th class="px-4 py-2.5 w-px" />
            </tr>
          </thead>
          <tbody class="divide-y divide-line">
            <tr v-for="(se, i) in sample.sample_elements" :key="se.id"
                class="group hover:bg-surface-sunken/60 transition-colors animate-row-in"
                :style="{ animationDelay: `${i * 30}ms` }">
              <td class="px-4 py-3">
                <span class="flex items-center gap-2.5">
                  <span class="font-mono text-[12.5px] font-medium text-assay-600 dark:text-assay-300 w-10">
                    {{ se.element?.symbol }}
                  </span>
                  <span class="text-[13.5px] text-ink">{{ localized(se.element, 'name') }}</span>
                </span>
              </td>
              <td class="px-4 py-3 text-right tnum text-[14px]"
                  :class="se.status === 'done' ? 'text-ink font-medium' : 'text-ink-faint'">
                {{ se.status === 'done' ? formatNumber(se.result_value) : '—' }}
              </td>
              <td class="px-4 py-3 text-[13px] text-ink-muted">{{ se.result_unit ?? '—' }}</td>
              <td class="px-4 py-3 text-[12.5px] text-ink-muted">
                <span v-if="se.equipment" :title="se.equipment.serial_number">{{ se.equipment.name }}</span>
                <span v-else class="text-ink-faint">—</span>
              </td>
              <td class="px-4 py-3 text-[12.5px] text-ink-faint tnum">{{ formatDateTime(se.result_entered_at) }}</td>
              <td class="px-4 py-3 text-right">
                <button
                  v-if="canEnterResult"
                  class="btn-outline btn-sm md:opacity-0 md:group-hover:opacity-100 focus:opacity-100 transition-opacity"
                  @click="openResult(se)"
                >
                  {{ se.status === 'done' ? t('common.edit') : t('sample.enterResult') }}
                </button>
                <StatusBadge v-else :status="se.status" size="sm" />
              </td>
            </tr>
          </tbody>
        </table>
      </section>

      <!-- Ma'lumotlar -->
      <aside class="panel p-4 h-fit">
        <h2 class="text-[13px] font-semibold text-ink mb-3.5">{{ t('common.details') }}</h2>
        <dl class="space-y-3">
          <div v-for="[key, value] in facts" :key="key">
            <dt class="text-[11.5px] text-ink-faint">{{ t(key) }}</dt>
            <dd class="text-[13.5px] text-ink mt-0.5">{{ value || '—' }}</dd>
          </div>
          <div v-if="sample.notes">
            <dt class="text-[11.5px] text-ink-faint">{{ t('equipment.notes') }}</dt>
            <dd class="text-[13.5px] text-ink mt-0.5 leading-relaxed">{{ sample.notes }}</dd>
          </div>
        </dl>
      </aside>
    </div>

    <!-- Natija formasi -->
    <BaseModal
      v-model="resultOpen"
      size="sm"
      :title="t('sample.enterResult')"
      :subtitle="`${activeElement?.element?.symbol} · ${localized(activeElement?.element, 'name')}`"
    >
      <div class="grid gap-4 sm:grid-cols-2">
        <div>
          <label class="field-label" for="rv">{{ t('sample.resultValue') }}</label>
          <input id="rv" v-model="resultForm.result_value" type="number" step="any" class="field-input tnum" autofocus />
        </div>
        <div>
          <label class="field-label" for="ru">{{ t('sample.resultUnit') }}</label>
          <input id="ru" v-model="resultForm.result_unit" class="field-input" />
        </div>
        <div class="sm:col-span-2">
          <label class="field-label" for="req">{{ t('usage.equipment') }}</label>
          <select id="req" v-model="resultForm.equipment_id" class="field-input">
            <option :value="null">{{ t('common.selectPlaceholder') }}</option>
            <option v-for="eq in equipmentList" :key="eq.id" :value="eq.id">
              {{ eq.name }}<span v-if="eq.serial_number"> · {{ eq.serial_number }}</span>
            </option>
          </select>
          <p class="mt-1.5 text-[12.5px] text-ink-faint">{{ t('usage.equipmentHint') }}</p>
        </div>
        <div class="sm:col-span-2">
          <label class="field-label" for="rm">{{ t('usage.method') }}</label>
          <input id="rm" v-model="resultForm.method" class="field-input" />
        </div>
        <div class="sm:col-span-2">
          <label class="field-label" for="rn">{{ t('equipment.notes') }}</label>
          <textarea id="rn" v-model="resultForm.notes" class="field-input" rows="2" />
        </div>

        <p v-if="resultError" class="sm:col-span-2 text-[13px] text-crit bg-crit-light dark:bg-crit-dark/25 rounded-md px-3 py-2.5">
          {{ resultError }}
        </p>
      </div>

      <template #footer>
        <button class="btn-ghost" @click="resultOpen = false">{{ t('common.cancel') }}</button>
        <button class="btn-primary" :disabled="saving || resultForm.result_value === ''" @click="saveResult">
          {{ t('common.save') }}
        </button>
      </template>
    </BaseModal>
  </div>
</template>
