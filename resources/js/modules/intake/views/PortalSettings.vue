<script setup>
/**
 * [Paket 27] Portal sozlamalari — buyurtmachi bo'limlar portalda nimani ko'radi.
 * Ruxsat: portal.settings (admin'da doim bor). Yashirish serverda bajariladi.
 */
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue'
import { onBeforeRouteLeave } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { Eye, FileSpreadsheet, FlaskConical, ListChecks, RotateCcw, Save, Scale, TrendingUp } from 'lucide-vue-next'
import api from '@/lib/api'
import PageHeader from '@/components/ui/PageHeader.vue'
import { useUiStore } from '@/stores/ui'
import { formatDateTime } from '@/lib/format'
import { apiErrorMessage } from '../helpers'
import { DEFAULT_VISIBILITY, VISIBILITY_GROUPS } from '../portalVisibility'

const { t } = useI18n()
const ui = useUiStore()

const loading = ref(true)
const saving = ref(false)
const loadError = ref('')
const saved = reactive({ ...DEFAULT_VISIBILITY })
const form = reactive({ ...DEFAULT_VISIBILITY })
const meta = ref({ updated_at: null, updated_by: null })

const GROUP_ICON = { status: ListChecks, results: TrendingUp, analyses: FlaskConical, other: Scale }
const ITEM_ICON = { export: FileSpreadsheet, quantity: Scale }

async function load() {
  loading.value = true
  loadError.value = ''
  try {
    const { data } = await api.get('/portal-settings')
    Object.assign(saved, DEFAULT_VISIBILITY, data.visibility)
    Object.assign(form, saved)
    meta.value = { updated_at: data.updated_at, updated_by: data.updated_by }
  } catch (e) {
    loadError.value = apiErrorMessage(e, t('portalVis.settings.loadError'))
  } finally {
    loading.value = false
  }
}
onMounted(load)

const keys = Object.keys(DEFAULT_VISIBILITY)
const dirty = computed(() => keys.some((k) => form[k] !== saved[k]))
const visibleCount = computed(() => keys.filter((k) => effective(k)).length)

function itemTitle(k) {
  return t(`portalVis.settings.items.${k}.title`)
}
function blockedBy(item) {
  return (item.requires || []).filter((r) => !form[r])
}
/** Yoqilgan, lekin bog'liq sozlama o'chiq bo'lsa — amalda ko'rinmaydi */
function effective(k) {
  const item = VISIBILITY_GROUPS.flatMap((g) => g.items).find((i) => i.key === k)
  return !!form[k] && blockedBy(item || {}).length === 0
}

function toggle(k) {
  form[k] = !form[k]
}
function cancel() {
  Object.assign(form, saved)
}
function openAll() {
  Object.assign(form, DEFAULT_VISIBILITY)
}

async function save() {
  saving.value = true
  try {
    const { data } = await api.put('/portal-settings', { visibility: { ...form } })
    Object.assign(saved, DEFAULT_VISIBILITY, data.visibility)
    Object.assign(form, saved)
    meta.value = { updated_at: data.updated_at, updated_by: data.updated_by }
    ui.success(t('portalVis.settings.saved'))
  } catch (e) {
    ui.error(apiErrorMessage(e, t('portalVis.settings.saveError')))
  } finally {
    saving.value = false
  }
}

onBeforeRouteLeave(() => (dirty.value ? window.confirm(t('portalVis.settings.leave')) : true))
function beforeUnload(e) {
  if (dirty.value) {
    e.preventDefault()
    e.returnValue = ''
  }
}
window.addEventListener('beforeunload', beforeUnload)
onBeforeUnmount(() => window.removeEventListener('beforeunload', beforeUnload))
</script>

<template>
  <div class="max-w-[860px]">
    <PageHeader :title="t('portalVis.settings.title')" :subtitle="t('portalVis.settings.subtitle')">
      <template #actions>
        <RouterLink :to="{ name: 'portal' }" class="btn-outline" data-preview-link>
          <Eye :size="16" /> {{ t('portalVis.settings.preview') }}
        </RouterLink>
      </template>
    </PageHeader>

    <div v-if="loading" class="space-y-3">
      <div v-for="i in 4" :key="i" class="h-28 panel animate-pulse" />
    </div>

    <p v-else-if="loadError" class="panel p-4 text-[14px] text-crit" data-load-error>{{ loadError }}</p>

    <template v-else>
      <p class="mb-4 text-[13px] text-ink-muted" data-meta>
        <template v-if="meta.updated_at">{{ t('portalVis.settings.lastChange', { d: formatDateTime(meta.updated_at), who: meta.updated_by || '—' }) }}</template>
        <template v-else>{{ t('portalVis.settings.never') }}</template>
        · <span class="tnum">{{ t('portalVis.settings.summary', { n: visibleCount, total: keys.length }) }}</span>
      </p>

      <section v-for="g in VISIBILITY_GROUPS" :key="g.key" class="panel mb-4 overflow-hidden" :data-group="g.key">
        <header class="flex items-center gap-2 px-4 py-3 border-b border-line">
          <component :is="GROUP_ICON[g.key]" :size="16" class="text-assay-500" />
          <h2 class="text-[14px] font-semibold text-ink">{{ t(`portalVis.settings.groups.${g.key}`) }}</h2>
        </header>
        <ul class="divide-y divide-line">
          <li
            v-for="item in g.items" :key="item.key"
            class="flex items-start justify-between gap-4 px-4 py-3.5"
            :class="item.requires && 'pl-9'"
            :data-item="item.key" :data-on="form[item.key]"
          >
            <div class="min-w-0">
              <p class="flex items-center gap-2 text-[14px] font-medium" :class="effective(item.key) ? 'text-ink' : 'text-ink-muted'">
                <component :is="ITEM_ICON[item.key]" v-if="ITEM_ICON[item.key]" :size="15" class="text-ink-faint" />
                {{ itemTitle(item.key) }}
                <span v-if="form[item.key] !== saved[item.key]" class="h-1.5 w-1.5 rounded-full bg-assay-500" :title="t('portalVis.settings.unsaved')" />
              </p>
              <p class="mt-0.5 text-[12.5px] text-ink-muted">{{ t(`portalVis.settings.items.${item.key}.hint`) }}</p>
              <p v-if="form[item.key] && blockedBy(item).length" class="mt-1 text-[12px] text-warn-dark dark:text-warn-light" data-blocked>
                {{ t('portalVis.settings.requires', { names: blockedBy(item).map(itemTitle).join('», «') }) }}
              </p>
            </div>
            <button
              type="button" role="switch" :aria-checked="form[item.key]"
              :aria-label="itemTitle(item.key)"
              class="relative mt-0.5 h-6 w-10 rounded-full transition-colors shrink-0"
              :class="form[item.key] ? (effective(item.key) ? 'bg-assay-500' : 'bg-assay-300 dark:bg-assay-800') : 'bg-line'"
              :data-switch="item.key"
              @click="toggle(item.key)"
            >
              <span
                class="absolute left-0 top-0.5 h-5 w-5 rounded-full bg-white shadow transition-transform"
                :class="form[item.key] ? 'translate-x-[18px]' : 'translate-x-0.5'"
              />
            </button>
          </li>
        </ul>
      </section>

      <p class="mb-4 text-[12.5px] text-ink-faint">{{ t('portalVis.settings.note') }}</p>

      <div class="sticky bottom-0 -mx-1 px-1 py-3 bg-surface/90 backdrop-blur flex flex-wrap items-center justify-between gap-3 border-t border-line">
        <button type="button" class="btn-ghost btn-sm" :disabled="saving" data-open-all @click="openAll">
          <RotateCcw :size="15" /> {{ t('portalVis.settings.reset') }}
        </button>
        <div class="flex items-center gap-2">
          <span v-if="dirty" class="text-[12.5px] text-ink-muted" data-dirty>{{ t('portalVis.settings.unsaved') }}</span>
          <button type="button" class="btn-outline" :disabled="!dirty || saving" data-cancel @click="cancel">{{ t('portalVis.settings.cancel') }}</button>
          <button type="button" class="btn-primary" :disabled="!dirty || saving" data-save @click="save">
            <Save :size="16" /> {{ t('portalVis.settings.save') }}
          </button>
        </div>
      </div>
    </template>
  </div>
</template>
