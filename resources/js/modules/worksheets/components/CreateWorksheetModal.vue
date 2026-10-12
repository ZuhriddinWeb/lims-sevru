<script setup>
/**
 * Yangi ishchi varaq (partiya): 1) shablon → 2) mos probalar va parallellar soni.
 * Proba sahifasidan ochilsa (sample prop) — o'sha proba oldindan belgilanadi.
 */
import { computed, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { ArrowLeft, ArrowRight, Check, FileSpreadsheet, Printer } from 'lucide-vue-next'
import BaseModal from '@/components/ui/BaseModal.vue'
import api from '@/lib/api'
import { useUiStore } from '@/stores/ui'
import { apiErrorMessage, templateName, todayISO } from '../helpers'
import { invalidateSampleWorksheets } from '../sampleWorksheets'
import CandidatePicker from './CandidatePicker.vue'
import '../i18n'

const props = defineProps({
  modelValue: Boolean,
  /** Proba sahifasidan ochilsa — proba oldindan tanlangan */
  sample: { type: Object, default: null },
})
const emit = defineEmits(['update:modelValue', 'created'])

const { t, locale } = useI18n()
const router = useRouter()
const ui = useUiStore()

const step = ref(1)
const templates = ref([])
const templateId = ref(null)
const selected = ref([])
const tplMeta = ref(null)
const date = ref(todayISO())
const busy = ref(false)

watch(() => props.modelValue, async (open) => {
  if (!open) return
  step.value = 1
  templateId.value = null
  selected.value = []
  tplMeta.value = null
  date.value = todayISO()
  try {
    const { data } = await api.get('/worksheet-templates', { params: { active: 1 } })
    templates.value = data
  } catch (e) {
    ui.error(apiErrorMessage(e, t('ws.loadError')))
  }
})

const sampleElementIds = computed(() => (props.sample?.sample_elements ?? []).map((se) => Number(se.element_id)))

const sortedTemplates = computed(() => {
  const ids = new Set(sampleElementIds.value)
  return templates.value
    .map((tpl) => ({ ...tpl, matches: !!props.sample && (tpl.result_element_ids ?? []).some((id) => ids.has(Number(id))) }))
    .sort((a, b) => Number(b.matches) - Number(a.matches) || a.code.localeCompare(b.code))
})

watch(sortedTemplates, (list) => {
  if (!templateId.value && list.length && list[0].matches) templateId.value = list[0].id
})

const currentTpl = computed(() => templates.value.find((x) => x.id === templateId.value) ?? null)
const overLimit = computed(() => tplMeta.value?.max_samples && selected.value.length > tplMeta.value.max_samples)
const canCreate = computed(() => templateId.value && selected.value.length > 0 && !overLimit.value && !busy.value)

function close() {
  emit('update:modelValue', false)
}

async function create(andPrint) {
  if (!canCreate.value) return
  busy.value = true
  try {
    const { data } = await api.post('/worksheets', {
      template_id: templateId.value,
      worksheet_date: date.value,
      samples: selected.value.map((s) => ({ id: s.id, parallels: s.parallels })),
    })
    selected.value.forEach((s) => invalidateSampleWorksheets(s.id))
    ui.success(t('ws.created', { n: data.number }))
    emit('created', data)
    close()
    router.push({ name: andPrint ? 'worksheet-print' : 'worksheet-detail', params: { id: data.id } })
  } catch (e) {
    ui.error(apiErrorMessage(e, t('ws.saveError')))
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <BaseModal
    :model-value="modelValue" size="lg"
    :title="t('ws.createTitle')" :subtitle="t('ws.createSubtitle')"
    @update:model-value="emit('update:modelValue', $event)"
  >
    <!-- Qadamlar -->
    <ol class="flex items-center gap-2 mb-4 text-[13px]">
      <li v-for="(label, i) in [t('ws.step1'), t('ws.step2')]" :key="i" class="flex items-center gap-2">
        <span
          class="h-6 w-6 rounded-full grid place-items-center text-[12px] font-semibold"
          :class="step > i + 1 ? 'bg-ok text-white' : step === i + 1 ? 'bg-assay-500 text-white' : 'bg-surface-sunken text-ink-muted'"
        >
          <Check v-if="step > i + 1" :size="13" :stroke-width="3" />
          <template v-else>{{ i + 1 }}</template>
        </span>
        <span :class="step === i + 1 ? 'text-ink font-medium' : 'text-ink-muted'">{{ label }}</span>
        <span v-if="i === 0" class="w-8 h-px bg-line mx-1" />
      </li>
      <li v-if="step === 2 && currentTpl" class="ml-auto text-ink-muted truncate">
        <span class="font-mono text-ink">{{ currentTpl.code }}</span> · {{ templateName(currentTpl, locale) }}
      </li>
    </ol>

    <!-- 1-qadam: shablon -->
    <div v-if="step === 1">
      <div v-if="sample" class="mb-3 rounded-md border border-assay-300 bg-assay-50/60 dark:bg-assay-800/20 px-3 py-2 text-[13px]">
        {{ t('ws.sample') }}: <span class="font-mono font-semibold text-ink">{{ sample.code }}</span>
        <span class="text-ink-muted"> · № {{ sample.sample_number }}</span>
      </div>
      <p v-if="!sortedTemplates.length" class="text-[13px] text-ink-muted">{{ t('ws.noTemplates') }}</p>
      <div class="grid sm:grid-cols-2 gap-2">
        <button
          v-for="tpl in sortedTemplates" :key="tpl.id" type="button"
          class="relative text-left rounded-md border px-3 py-2.5 transition-all"
          :class="templateId === tpl.id
            ? 'border-assay-500 ring-1 ring-assay-500 bg-assay-50/50 dark:bg-assay-800/20'
            : 'border-line hover:border-ink-faint'"
          @click="templateId = tpl.id"
          @dblclick="templateId = tpl.id; step = 2"
        >
          <div class="flex items-start justify-between gap-2">
            <div class="min-w-0">
              <p class="text-[14px] font-medium text-ink truncate">{{ templateName(tpl, locale) }}</p>
              <p class="text-[12px] text-ink-muted">
                <span class="font-mono">{{ tpl.code }}</span><span v-if="tpl.number_suffix"> · {{ tpl.number_suffix }}</span>
                · {{ t('ws.parallels') }}: {{ tpl.default_rows }}
              </p>
            </div>
            <span v-if="templateId === tpl.id" class="h-5 w-5 rounded-full bg-assay-500 text-white grid place-items-center shrink-0">
              <Check :size="13" />
            </span>
          </div>
          <span v-if="tpl.matches" class="mt-1.5 inline-block rounded-full bg-ok-light text-ok-dark dark:bg-ok-dark/25 dark:text-ok-light px-2 py-0.5 text-[11px] font-medium">
            {{ t('ws.matches') }}
          </span>
        </button>
      </div>
    </div>

    <!-- 2-qadam: probalar -->
    <div v-else>
      <CandidatePicker
        v-model="selected"
        :template-id="templateId"
        :preselect="sample ? [sample.id] : []"
        @meta="tplMeta = $event"
      />
      <div class="mt-3 grid grid-cols-2 gap-4">
        <div>
          <label class="field-label">{{ t('ws.date') }}</label>
          <input v-model="date" type="date" class="field-input">
        </div>
      </div>
    </div>

    <template #footer>
      <button class="btn-ghost" @click="close">{{ t('common.cancel') }}</button>
      <template v-if="step === 1">
        <button class="btn-primary" :disabled="!templateId" @click="step = 2">
          {{ t('ws.next') }} <ArrowRight :size="16" />
        </button>
      </template>
      <template v-else>
        <button class="btn-ghost" @click="step = 1"><ArrowLeft :size="16" /> {{ t('ws.back') }}</button>
        <button class="btn-outline" :disabled="!canCreate" @click="create(true)">
          <Printer :size="16" /> {{ t('ws.createAndPrint') }}
        </button>
        <button class="btn-primary" :disabled="!canCreate" @click="create(false)">
          <FileSpreadsheet :size="16" /> {{ t('ws.createBtn') }}
        </button>
      </template>
    </template>
  </BaseModal>
</template>
