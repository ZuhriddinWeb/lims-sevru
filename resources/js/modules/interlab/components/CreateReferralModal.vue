<script setup>
/**
 * Yangi yo'llanma: laboratoriya → proba → elementlar → muddat/izoh → jo'natish.
 */
import { computed, reactive, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { Check, EyeOff, Search, Send, X } from 'lucide-vue-next'
import BaseModal from '@/components/ui/BaseModal.vue'
import api from '@/lib/api'
import { useUiStore } from '@/stores/ui'
import { formatDate } from '@/lib/format'
import { apiErrorMessage, elementName, fmtValue } from '../helpers'
import '../i18n'

const props = defineProps({ modelValue: Boolean })
const emit = defineEmits(['update:modelValue', 'created'])

const { t, locale } = useI18n()
const router = useRouter()
const ui = useUiStore()

const peers = ref([])
const samples = ref([])
const search = ref('')
const searching = ref(false)
const sample = ref(null)
const busy = ref(false)
const form = reactive({
  peer_id: null, elements: [], deadline: '', tolerance_pct: '', description: '', note: '', hide_code: false,
})
let timer = null

watch(() => props.modelValue, async (open) => {
  if (!open) return
  Object.assign(form, { peer_id: null, elements: [], deadline: '', tolerance_pct: '', description: '', note: '', hide_code: false })
  sample.value = null
  search.value = ''
  try {
    const { data } = await api.get('/interlab/peers/active')
    peers.value = data
    if (data.length === 1) form.peer_id = data[0].id
  } catch (e) {
    ui.error(apiErrorMessage(e, t('il.loadError')))
  }
  loadSamples()
})

async function loadSamples() {
  searching.value = true
  try {
    const { data } = await api.get('/interlab/samples', { params: { search: search.value || undefined } })
    samples.value = data
  } catch {
    samples.value = []
  } finally {
    searching.value = false
  }
}

watch(search, () => {
  clearTimeout(timer)
  timer = setTimeout(loadSamples, 300)
})

function pick(s) {
  sample.value = s
  form.elements = s.elements.map((e) => e.symbol)
}

function toggleElement(symbol) {
  const i = form.elements.indexOf(symbol)
  if (i >= 0) form.elements.splice(i, 1)
  else form.elements.push(symbol)
}

const canSend = computed(() => form.peer_id && sample.value && form.elements.length > 0 && !busy.value)

function close() {
  emit('update:modelValue', false)
}

async function submit() {
  if (!canSend.value) {
    if (sample.value && !form.elements.length) ui.error(t('il.pickElements'))
    return
  }
  busy.value = true
  try {
    const { data } = await api.post('/interlab/referrals', {
      peer_id: form.peer_id,
      sample_id: sample.value.id,
      elements: form.elements,
      deadline: form.deadline || null,
      tolerance_pct: form.tolerance_pct === '' ? null : Number(String(form.tolerance_pct).replace(',', '.')),
      description: form.description || null,
      note: form.note || null,
      hide_code: form.hide_code,
    })
    if (data.status === 'sending') ui.info(t('il.queued', { n: data.number }))
    else ui.success(t('il.created', { n: data.number }))
    emit('created', data)
    close()
    router.push({ name: 'interlab-detail', params: { id: data.id } })
  } catch (e) {
    ui.error(apiErrorMessage(e, t('il.saveError')))
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <BaseModal
    :model-value="modelValue" size="lg"
    :title="t('il.createTitle')" :subtitle="t('il.createSubtitle')"
    @update:model-value="emit('update:modelValue', $event)"
  >
    <div class="space-y-4">
      <!-- Laboratoriya -->
      <div>
        <label class="field-label">{{ t('il.lab') }}</label>
        <div class="grid sm:grid-cols-2 gap-2">
          <button
            v-for="p in peers" :key="p.id" type="button"
            class="text-left rounded-md border px-3 py-2 transition-all"
            :class="form.peer_id === p.id ? 'border-assay-500 ring-1 ring-assay-500 bg-assay-50/50 dark:bg-assay-800/20' : 'border-line hover:border-ink-faint'"
            @click="form.peer_id = p.id"
          >
            <span class="flex items-center justify-between gap-2">
              <span class="text-[14px] font-medium text-ink truncate">{{ p.name }}</span>
              <Check v-if="form.peer_id === p.id" :size="15" class="text-assay-500 shrink-0" />
            </span>
            <span class="text-[12px] text-ink-muted flex items-center gap-1.5">
              <span class="h-1.5 w-1.5 rounded-full" :class="p.online ? 'bg-ok' : 'bg-ink-faint'" />
              {{ p.online ? t('il.online') : t('il.offline') }}<template v-if="p.code"> · <span class="font-mono">{{ p.code }}</span></template>
            </span>
          </button>
        </div>
      </div>

      <!-- Proba -->
      <div>
        <label class="field-label">{{ t('il.sample') }}</label>
        <div v-if="sample" class="rounded-md border border-assay-300 bg-assay-50/50 dark:bg-assay-800/15 px-3 py-2 flex items-center justify-between gap-2">
          <span class="text-[13.5px] min-w-0">
            <span class="font-mono font-semibold text-ink">{{ sample.code }}</span>
            <span class="text-ink"> · № {{ sample.sample_number }}</span>
            <span class="text-ink-faint text-[12px]"> · {{ formatDate(sample.registered_at) }}</span>
            <span v-if="sample.open_referrals" class="ml-1.5 text-[12px] text-warn-dark dark:text-warn-light">{{ t('il.openReferrals', { n: sample.open_referrals }) }}</span>
          </span>
          <button type="button" class="btn-ghost btn-sm shrink-0" @click="sample = null; form.elements = []">
            <X :size="14" /> {{ t('il.change') }}
          </button>
        </div>
        <template v-else>
          <div class="relative mb-2">
            <Search :size="15" class="absolute left-3 top-1/2 -translate-y-1/2 text-ink-faint" />
            <input v-model="search" class="field-input h-9 pl-9" :placeholder="t('il.sampleSearch')">
          </div>
          <div class="max-h-[28vh] overflow-y-auto rounded-md border border-line divide-y divide-line">
            <div v-if="searching && !samples.length" class="p-3 space-y-2">
              <div v-for="i in 3" :key="i" class="h-8 rounded bg-surface-sunken animate-pulse" />
            </div>
            <p v-else-if="!samples.length" class="px-3 py-5 text-center text-[13px] text-ink-faint">{{ t('il.noSamples') }}</p>
            <button
              v-for="s in samples" :key="s.id" type="button"
              class="w-full text-left px-3 py-2 hover:bg-surface-sunken/60 transition-colors"
              @click="pick(s)"
            >
              <span class="font-mono text-[13.5px] font-semibold text-ink">{{ s.code }}</span>
              <span class="text-[13px] text-ink"> · № {{ s.sample_number }}</span>
              <span class="block text-[12px] text-ink-muted truncate">
                {{ formatDate(s.registered_at) }} · {{ s.elements.map((e) => e.symbol).join(', ') }}
                <span v-if="s.open_referrals" class="text-warn-dark dark:text-warn-light"> · {{ t('il.openReferrals', { n: s.open_referrals }) }}</span>
              </span>
            </button>
          </div>
        </template>
      </div>

      <!-- Elementlar -->
      <div v-if="sample">
        <label class="field-label">{{ t('il.elements') }}</label>
        <div class="flex flex-wrap gap-2">
          <button
            v-for="el in sample.elements" :key="el.symbol" type="button"
            class="inline-flex items-center gap-2 rounded-md border px-2.5 py-1.5 text-[13px] transition-colors"
            :class="form.elements.includes(el.symbol) ? 'border-assay-500 bg-assay-50 dark:bg-assay-800/25 text-ink' : 'border-line text-ink-muted hover:border-ink-faint'"
            @click="toggleElement(el.symbol)"
          >
            <span
              class="h-4 w-4 rounded border grid place-items-center"
              :class="form.elements.includes(el.symbol) ? 'bg-assay-500 border-assay-500 text-white' : 'border-line'"
            ><Check v-if="form.elements.includes(el.symbol)" :size="11" :stroke-width="3" /></span>
            <span class="font-semibold">{{ el.symbol }}</span>
            <span>{{ elementName(el, locale) }}</span>
            <span v-if="el.result_value !== null" class="text-[11.5px] text-ink-faint tnum">{{ t('il.ourResult', { v: fmtValue(el.result_value) }) }}</span>
          </button>
        </div>
        <p class="mt-1.5 text-[12px] text-ink-muted flex items-center gap-1.5"><EyeOff :size="13" /> {{ t('il.blindNote') }}</p>
      </div>

      <div v-if="sample" class="grid sm:grid-cols-2 gap-3">
        <div>
          <label class="field-label">{{ t('il.deadline') }}</label>
          <input v-model="form.deadline" type="date" class="field-input">
        </div>
        <div>
          <label class="field-label">{{ t('il.tolerance') }}</label>
          <input v-model="form.tolerance_pct" inputmode="decimal" class="field-input tnum" placeholder="—">
          <p class="mt-1 text-[11.5px] text-ink-faint">{{ t('il.toleranceHint') }}</p>
        </div>
        <div class="sm:col-span-2">
          <label class="field-label">{{ t('il.description') }}</label>
          <input v-model="form.description" class="field-input" :placeholder="t('il.descriptionHint')" maxlength="2000">
        </div>
        <div class="sm:col-span-2">
          <label class="field-label">{{ t('il.note') }}</label>
          <textarea v-model="form.note" rows="2" class="field-input" maxlength="2000" />
        </div>
        <label class="sm:col-span-2 inline-flex items-center gap-2 text-[13.5px] text-ink cursor-pointer select-none">
          <input v-model="form.hide_code" type="checkbox" class="rounded border-line text-assay-500 focus:ring-assay-500">
          {{ t('il.hideCode') }}
        </label>
      </div>
    </div>

    <template #footer>
      <button class="btn-ghost" @click="close">{{ t('common.cancel') }}</button>
      <button class="btn-primary" :disabled="!canSend" @click="submit">
        <Send :size="16" /> {{ t('il.send') }}
      </button>
    </template>
  </BaseModal>
</template>
