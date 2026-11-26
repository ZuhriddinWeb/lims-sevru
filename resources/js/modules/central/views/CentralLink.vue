<script setup>
/**
 * [Paket 44] Administratsiya → «Markaziy portal»: ulanish, nima yuboriladi, holat, hozir yuborish.
 */
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { CheckCircle2, CircleOff, Eye, KeyRound, Lock, RadioTower, Save, Send, ShieldCheck, Tags, XCircle } from 'lucide-vue-next'
import api from '@/lib/api'
import PageHeader from '@/components/ui/PageHeader.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import { useUiStore } from '@/stores/ui'
import { formatDateTime } from '@/lib/format'
import { apiErrorMessage, fieldError } from '@/modules/intake/requests/shared'
import '../i18n'

const { t } = useI18n()
const ui = useUiStore()

const NAMED = ['groups', 'customers', 'equipment'] // nomlar bilan ketadigan bloklar

const data = ref(null)
const loading = ref(true)
const busy = ref(false)
const pushing = ref(false)
const errors = ref({})
const form = ref({ enabled: false, url: '', interval: 5, datasets: [], key: '' })
const keyEdit = ref(false)
const preview = ref(null)
const previewOpen = ref(false)

function fill(d) {
  data.value = d
  form.value = { enabled: d.enabled, url: d.url, interval: d.interval, datasets: [...d.datasets], key: '' }
  keyEdit.value = !d.key_set
}

async function load() {
  loading.value = true
  try {
    fill((await api.get('/central-link')).data)
  } catch (e) {
    ui.error(apiErrorMessage(e, t('central.pushFailed', { e: '' })))
  } finally {
    loading.value = false
  }
}
onMounted(load)

const toggle = (k) => {
  const s = new Set(form.value.datasets)
  s.has(k) ? s.delete(k) : s.add(k)
  form.value.datasets = data.value.all.filter((x) => s.has(x))
}

async function save(extra = {}) {
  busy.value = true
  errors.value = {}
  try {
    const body = { enabled: form.value.enabled, url: form.value.url, interval: form.value.interval, datasets: form.value.datasets, ...extra }
    if (keyEdit.value && form.value.key.trim()) body.key = form.value.key.trim()
    fill((await api.put('/central-link', body)).data)
    ui.success(t('central.saved'))
  } catch (e) {
    errors.value = e?.response?.data?.errors ?? {}
    ui.error(apiErrorMessage(e, t('central.pushFailed', { e: '' })))
  } finally {
    busy.value = false
  }
}

async function pushNow() {
  pushing.value = true
  try {
    const { data: d } = await api.post('/central-link/push')
    fill(d)
    ui.success(t('central.pushed'))
  } catch (e) {
    if (e?.response?.data?.status) fill(e.response.data)
    ui.error(t('central.pushFailed', { e: e?.response?.data?.status?.error ?? apiErrorMessage(e, '') }))
  } finally {
    pushing.value = false
  }
}

async function openPreview() {
  try {
    const { data: d } = await api.get('/central-link/preview', { params: { datasets: form.value.datasets.join(',') || 'none' } })
    preview.value = d
    previewOpen.value = true
  } catch (e) {
    ui.error(apiErrorMessage(e, ''))
  }
}

const st = computed(() => data.value?.status ?? {})
const health = computed(() => {
  if (!data.value?.enabled) return 'off'
  if (st.value.ok === true) return 'ok'
  if (st.value.ok === false) return 'bad'
  return 'never'
})
const kb = (b) => (b ? (b / 1024).toFixed(1) : '0')
const dirty = computed(() => data.value && (
  form.value.enabled !== data.value.enabled || form.value.url !== data.value.url || form.value.interval !== data.value.interval
  || form.value.datasets.join() !== data.value.datasets.join() || (keyEdit.value && form.value.key.trim() !== '')))
</script>

<template>
  <div>
    <PageHeader :title="t('central.title')">
      <template #actions>
        <button class="btn-outline" :disabled="!data" data-preview @click="openPreview"><Eye :size="16" /> {{ t('central.preview') }}</button>
        <button class="btn-primary" :disabled="pushing || !data?.key_set || !data?.url" data-push @click="pushNow"><Send :size="16" /> {{ t('central.pushNow') }}</button>
      </template>
    </PageHeader>
    <p class="mb-4 max-w-3xl text-[13.5px] leading-relaxed text-ink-muted">{{ t('central.hint') }}</p>

    <div v-if="loading" class="h-48 rounded-panel bg-surface-sunken animate-pulse" />

    <div v-else-if="data" class="grid gap-4 lg:grid-cols-[minmax(0,1.4fr)_minmax(0,1fr)]">
      <div class="space-y-4">
        <!-- Ulanish -->
        <section class="panel p-4" data-connection>
          <h2 class="mb-3 flex items-center gap-2 text-[15px] font-semibold text-ink"><RadioTower :size="17" /> {{ t('central.connection') }}</h2>

          <label class="mb-4 flex cursor-pointer items-start gap-3 rounded-md border border-line px-3 py-2.5" :class="form.enabled && 'border-ok/40 bg-ok-light/20'">
            <input v-model="form.enabled" type="checkbox" class="mt-0.5 rounded border-line text-assay-500 focus:ring-assay-500" data-enabled>
            <span>
              <span class="block text-[13.5px] font-medium text-ink">{{ t('central.enabled') }}</span>
              <span v-if="!form.enabled" class="block text-[12px] text-ink-faint">{{ t('central.enabledOff') }}</span>
            </span>
          </label>
          <p v-if="fieldError(errors, 'enabled')" class="-mt-2 mb-3 text-[12.5px] text-crit">{{ fieldError(errors, 'enabled') }}</p>

          <div class="grid gap-4 sm:grid-cols-[minmax(0,1fr)_160px]">
            <label class="block">
              <span class="field-label">{{ t('central.url') }}</span>
              <input v-model.trim="form.url" class="field-input font-mono text-[13px]" :class="fieldError(errors, 'url') && 'field-input-error'" placeholder="http://192.168.14.23:5000" data-url>
              <span class="mt-1 block text-[11.5px] text-ink-faint">{{ fieldError(errors, 'url') || t('central.urlHint') }}</span>
            </label>
            <label class="block">
              <span class="field-label">{{ t('central.interval') }}</span>
              <select v-model.number="form.interval" class="field-input" data-interval>
                <option v-for="n in data.intervals" :key="n" :value="n">{{ t('central.minutes', { n }) }}</option>
              </select>
            </label>
          </div>

          <div class="mt-4">
            <span class="field-label flex items-center gap-1.5"><KeyRound :size="14" /> {{ t('central.key') }}</span>
            <div v-if="data.key_set && !keyEdit" class="flex flex-wrap items-center gap-2">
              <span class="inline-flex items-center gap-1.5 rounded-md bg-surface-sunken px-2.5 py-1.5 font-mono text-[12.5px] text-ink" data-key-hint>
                <Lock :size="13" class="text-ink-faint" /> {{ data.key_hint }}
              </span>
              <button class="btn-ghost text-[13px]" @click="keyEdit = true">{{ t('central.keyReplace') }}</button>
              <button class="btn-ghost text-[13px] text-crit" :disabled="busy" @click="save({ clear_key: true })">{{ t('central.keyClear') }}</button>
            </div>
            <input v-else v-model="form.key" type="password" autocomplete="off" class="field-input font-mono text-[13px]" :class="fieldError(errors, 'key') && 'field-input-error'" placeholder="ngmk1.…" data-key>
            <span class="mt-1 block text-[11.5px]" :class="fieldError(errors, 'key') ? 'text-crit' : 'text-ink-faint'">{{ fieldError(errors, 'key') || t('central.keyHint') }}</span>
          </div>
        </section>

        <!-- Nima yuboriladi -->
        <section class="panel p-4" data-datasets>
          <div class="mb-1 flex items-center gap-2">
            <h2 class="flex items-center gap-2 text-[15px] font-semibold text-ink"><ShieldCheck :size="17" /> {{ t('central.datasets') }}</h2>
            <button class="ml-auto btn-ghost text-[12.5px]" @click="form.datasets = [...data.defaults]">{{ t('central.defaults') }}</button>
          </div>
          <p class="mb-3 text-[12.5px] text-ink-muted">{{ t('central.datasetsHint') }}</p>
          <div class="grid gap-2 sm:grid-cols-2">
            <label
              v-for="k in data.all" :key="k"
              class="flex cursor-pointer items-start gap-3 rounded-md border px-3 py-2.5 transition-colors"
              :class="form.datasets.includes(k) ? 'border-assay-400/70 bg-assay-50/60 dark:bg-assay-800/20' : 'border-line hover:bg-surface-sunken'"
              :data-ds="k"
            >
              <input type="checkbox" class="mt-0.5 rounded border-line text-assay-500 focus:ring-assay-500" :checked="form.datasets.includes(k)" @change="toggle(k)">
              <span class="min-w-0">
                <span class="flex flex-wrap items-center gap-1.5 text-[13.5px] font-medium text-ink">
                  {{ t(`central.d.${k}.t`) }}
                  <span class="inline-flex items-center gap-1 rounded px-1.5 py-px text-[10.5px] font-medium" :class="NAMED.includes(k) ? 'bg-warn-light/60 text-warn-dark dark:text-warn-light' : 'bg-surface-sunken text-ink-muted'">
                    <Tags v-if="NAMED.includes(k)" :size="11" />{{ NAMED.includes(k) ? t('central.withNames') : t('central.onlyCounts') }}
                  </span>
                </span>
                <span class="block text-[12px] leading-snug text-ink-faint">{{ t(`central.d.${k}.h`) }}</span>
              </span>
            </label>
          </div>
        </section>

        <div class="flex justify-end">
          <button class="btn-primary" :disabled="busy || !dirty" data-save @click="save()"><Save :size="16" /> {{ t('central.save') }}</button>
        </div>
      </div>

      <!-- Holat -->
      <aside class="panel self-start p-4" data-status>
        <h2 class="mb-3 text-[15px] font-semibold text-ink">{{ t('central.status') }}</h2>
        <div class="mb-4 flex items-center gap-3 rounded-md px-3 py-3"
          :class="{ 'bg-ok-light/40 text-ok-dark dark:text-ok-light': health === 'ok', 'bg-crit/10 text-crit': health === 'bad', 'bg-surface-sunken text-ink-muted': health === 'off' || health === 'never' }"
          :data-health="health">
          <CheckCircle2 v-if="health === 'ok'" :size="22" />
          <XCircle v-else-if="health === 'bad'" :size="22" />
          <CircleOff v-else :size="22" />
          <span class="text-[14px] font-semibold">{{ health === 'ok' ? t('central.ok') : health === 'bad' ? t('central.bad') : health === 'off' ? t('central.off') : t('central.never') }}</span>
        </div>
        <dl class="space-y-2.5 text-[13px]">
          <div class="flex justify-between gap-3"><dt class="text-ink-muted">{{ t('central.lastAt') }}</dt><dd class="tnum text-ink">{{ st.last_at ? formatDateTime(st.last_at) : '—' }}</dd></div>
          <div class="flex justify-between gap-3"><dt class="text-ink-muted">{{ t('central.lastOk') }}</dt><dd class="tnum text-ink">{{ st.last_ok_at ? formatDateTime(st.last_ok_at) : '—' }}</dd></div>
          <div v-if="st.bytes" class="flex justify-between gap-3"><dt class="text-ink-muted" /><dd class="tnum text-ink-faint">{{ t('central.size', { kb: kb(st.bytes), ms: st.ms ?? '—' }) }}</dd></div>
          <div v-if="st.ok === false && st.error" class="rounded-md bg-crit/5 px-3 py-2 text-[12.5px] text-crit" data-error>
            <span class="font-medium">{{ t('central.error') }}<span v-if="st.http"> (HTTP {{ st.http }})</span>:</span> {{ st.error }}
            <span v-if="st.fails > 1" class="mt-1 block text-[11.5px]">{{ t('central.fails', { n: st.fails }) }}</span>
          </div>
        </dl>
      </aside>
    </div>

    <BaseModal v-model="previewOpen" :title="t('central.previewTitle')" size="lg">
      <template v-if="preview">
        <p class="mb-2 text-[12.5px] text-ink-muted">{{ t('central.previewSize', { kb: kb(preview.bytes), list: (preview.payload.datasets || []).map((k) => t(`central.d.${k}.t`)).join(', ') || '—' }) }}</p>
        <pre class="max-h-[60vh] overflow-auto rounded-md bg-surface-sunken p-3 text-[11.5px] leading-relaxed text-ink" data-preview-json>{{ JSON.stringify(preview.payload, null, 2) }}</pre>
      </template>
    </BaseModal>
  </div>
</template>
