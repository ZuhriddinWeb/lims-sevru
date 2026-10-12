<script setup>
/**
 * Chop etish sahifasi — bitta yoki bir nechta varaq:
 *   /worksheets/12/print        → bitta varaq
 *   /worksheets/12,13,14/print  → har biri alohida A4 sahifada
 */
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { ArrowLeft, Printer } from 'lucide-vue-next'
import api from '@/lib/api'
import { useUiStore } from '@/stores/ui'
import WorksheetSheet from '../components/WorksheetSheet.vue'
import { apiErrorMessage } from '../helpers'
import '../i18n'

const route = useRoute()
const router = useRouter()
const { t } = useI18n()
const ui = useUiStore()

const sheets = ref([])
const loading = ref(true)
const lang = ref('auto')        // auto — har bir varaq o'z shabloni tilida
const withValues = ref(false)
const spare = ref(2)

const ids = computed(() =>
  [...new Set(String(route.params.id ?? '').split(',').map((x) => Number(x)).filter((x) => x > 0))].slice(0, 50),
)

async function load() {
  loading.value = true
  const results = await Promise.allSettled(ids.value.map((id) => api.get(`/worksheets/${id}`)))
  sheets.value = results.filter((r) => r.status === 'fulfilled').map((r) => r.value.data)
  const failed = results.filter((r) => r.status === 'rejected')
  if (failed.length) ui.error(apiErrorMessage(failed[0].reason, t('ws.loadError')))
  if (!sheets.value.length) {
    router.replace({ name: 'worksheets' })
    return
  }
  // Hammasi hali bo'sh (yaratilgan / chop etilgan) bo'lsa — bo'sh blank, aks holda qiymatlar bilan
  withValues.value = sheets.value.some((w) => !['draft', 'printed'].includes(w.status))
  spare.value = withValues.value ? 0 : 2
  injectPageStyle()
  loading.value = false
}

onMounted(load)
watch(() => route.params.id, (v, old) => { if (v !== old) load() })

const sheetLang = (w) => (lang.value === 'auto' ? (w.template_snapshot?.print_locale || 'uz') : lang.value)

// Standart @page — birinchi varaq yo'nalishi bo'yicha; aralash bo'lsa nomli sahifalar (ws-page-*) ishlaydi
let styleEl = null
function injectPageStyle() {
  styleEl?.remove()
  styleEl = document.createElement('style')
  const first = sheets.value[0]?.template_snapshot?.orientation === 'portrait' ? 'A4 portrait' : 'A4 landscape'
  styleEl.textContent = `@media print {
    @page { size: ${first}; margin: 8mm; }
    @page ws-landscape { size: A4 landscape; margin: 8mm; }
    @page ws-portrait { size: A4 portrait; margin: 8mm; }
    .ws-page-landscape { page: ws-landscape; }
    .ws-page-portrait { page: ws-portrait; }
  }`
  document.head.appendChild(styleEl)
}
onBeforeUnmount(() => styleEl?.remove())

async function doPrint() {
  const results = await Promise.allSettled(sheets.value.map((w) => api.post(`/worksheets/${w.id}/printed`)))
  results.forEach((r, i) => {
    if (r.status === 'fulfilled') {
      sheets.value[i] = { ...sheets.value[i], status: r.value.data.status, printed_at: r.value.data.printed_at }
    }
  })
  window.print()
}

function back() {
  if (ids.value.length === 1) router.push({ name: 'worksheet-detail', params: { id: ids.value[0] } })
  else router.back()
}
</script>

<template>
  <div>
    <!-- Boshqaruv paneli (chop etishda ko'rinmaydi) -->
    <div class="print:hidden flex flex-wrap items-center justify-between gap-3 mb-4">
      <div class="flex items-center gap-3">
        <button class="btn-ghost" @click="back">
          <ArrowLeft :size="18" /> {{ t('ws.back') }}
        </button>
        <span v-if="sheets.length > 1" class="text-[13.5px] text-ink-muted">
          {{ sheets.map((w) => w.number).join(', ') }}
        </span>
      </div>
      <div class="flex flex-wrap items-center gap-3">
        <label class="flex items-center gap-2 text-[13.5px] text-ink-muted">
          {{ t('ws.printLang') }}
          <select v-model="lang" class="field-input h-9 w-auto">
            <option value="auto">{{ t('ws.printLangAuto') }}</option>
            <option value="uz">O'zbek (lotin)</option>
            <option value="uz_cyrl">Ўзбек (кирилл)</option>
            <option value="ru">Русский</option>
          </select>
        </label>
        <label class="flex items-center gap-2 text-[13.5px] text-ink-muted">
          {{ t('ws.printSpare') }}
          <select v-model.number="spare" class="field-input h-9 w-auto">
            <option v-for="n in [0, 1, 2, 3, 4, 6]" :key="n" :value="n">{{ n }}</option>
          </select>
        </label>
        <label class="flex items-center gap-2 text-[13.5px] text-ink cursor-pointer select-none">
          <input v-model="withValues" type="checkbox" class="rounded border-line text-assay-500 focus:ring-assay-500">
          {{ t('ws.printValues') }}
        </label>
        <button class="btn-primary" :disabled="loading || !sheets.length" @click="doPrint">
          <Printer :size="17" /> {{ t('ws.printDo') }}
        </button>
      </div>
    </div>

    <div v-if="loading" class="h-96 panel animate-pulse" />

    <!-- Qog'oz varaqlar: har biri alohida sahifa -->
    <div v-else class="overflow-x-auto pb-4 print:overflow-visible print:pb-0 space-y-6 print:space-y-0">
      <div v-for="w in sheets" :key="w.id" class="ws-sheet-wrap">
        <WorksheetSheet :ws="w" :lang="sheetLang(w)" :with-values="withValues" :spare="spare" />
      </div>
    </div>
  </div>
</template>

<style>
@media print {
  body { background: #fff !important; }
  aside, header.sticky { display: none !important; }
  main { padding: 0 !important; }
  .ws-sheet-wrap + .ws-sheet-wrap { break-before: page; page-break-before: always; }
}
</style>
