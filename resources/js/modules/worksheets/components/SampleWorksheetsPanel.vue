<script setup>
/**
 * Proba sahifasiga qo'yiladigan panel (ixtiyoriy):
 *   import SampleWorksheetsPanel from '@/modules/worksheets/components/SampleWorksheetsPanel.vue'
 *   <SampleWorksheetsPanel :sample="sample" />
 */
import { onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { AlertTriangle, FileSpreadsheet, Plus, Printer } from 'lucide-vue-next'
import api from '@/lib/api'
import { useAuthStore } from '@/stores/auth'
import { formatDate } from '@/lib/format'
import WsStatusBadge from './WsStatusBadge.vue'
import CreateWorksheetModal from './CreateWorksheetModal.vue'
import { templateName } from '../helpers'
import '../i18n'

const props = defineProps({ sample: { type: Object, required: true } })

const { t, locale } = useI18n()
const router = useRouter()
const auth = useAuthStore()

const items = ref([])
const createOpen = ref(false)

async function load() {
  if (!props.sample?.id || !auth.can('worksheets.view')) return
  try {
    const { data } = await api.get('/worksheets', { params: { sample_id: props.sample.id, per_page: 50 } })
    items.value = data.data
  } catch {
    items.value = []
  }
}

onMounted(load)
watch(() => props.sample?.id, load)

// Shu probaning partiyadagi holati (takroriy tahlilga qaytarilganmi)
const memberStatus = (w) => (w.samples ?? []).find((s) => String(s.id) === String(props.sample?.id))?.pivot?.status ?? 'active'
</script>

<template>
  <section v-if="auth.can('worksheets.view')" class="panel p-4">
    <div class="flex items-center justify-between gap-3 mb-3">
      <h3 class="flex items-center gap-2 text-[15px] font-semibold text-ink">
        <FileSpreadsheet :size="17" class="text-assay-500" /> {{ t('ws.title') }}
      </h3>
      <div class="flex items-center gap-1.5">
        <button
          v-if="items.length > 1" class="btn-ghost btn-sm"
          @click="router.push({ name: 'worksheet-print', params: { id: items.map((w) => w.id).join(',') } })"
        >
          <Printer :size="15" /> {{ t('ws.printAll') }}
        </button>
        <button v-if="auth.can('worksheets.create')" class="btn-outline btn-sm" @click="createOpen = true">
          <Plus :size="15" /> {{ t('ws.create') }}
        </button>
      </div>
    </div>

    <p v-if="!items.length" class="text-[13px] text-ink-faint">{{ t('ws.empty') }}</p>

    <ul v-else class="divide-y divide-line -mx-1">
      <li
        v-for="w in items" :key="w.id"
        class="flex items-center justify-between gap-3 px-1 py-2 rounded hover:bg-surface-sunken/60 cursor-pointer"
        @click="router.push({ name: 'worksheet-detail', params: { id: w.id } })"
      >
        <span class="min-w-0">
          <span class="font-mono font-semibold text-ink">{{ w.number }}</span>
          <AlertTriangle v-if="w.has_warnings" :size="13" class="inline ml-1 -mt-0.5 text-warn" />
          <span class="text-[12.5px] text-ink-muted"> · {{ templateName(w.template, locale) }} · {{ formatDate(w.worksheet_date) }}</span>
          <span v-if="(w.members_count ?? 1) > 1" class="ml-1.5 rounded-full bg-surface-sunken px-1.5 py-0.5 text-[11px] text-ink-muted">{{ t('ws.batchOf', { n: w.members_count }) }}</span>
          <span
            v-if="memberStatus(w) === 'retest'"
            class="ml-1 rounded-full bg-warn-light text-warn-dark dark:bg-warn-dark/25 dark:text-warn-light px-1.5 py-0.5 text-[11px] font-medium"
          >{{ t('ws.memberRetest') }}</span>
        </span>
        <span class="flex items-center gap-2 shrink-0" @click.stop>
          <WsStatusBadge :status="w.status" size="sm" />
          <button class="btn-ghost btn-icon h-7 w-7" @click="router.push({ name: 'worksheet-print', params: { id: w.id } })">
            <Printer :size="15" />
          </button>
        </span>
      </li>
    </ul>

    <CreateWorksheetModal v-model="createOpen" :sample="sample" @created="load" />
  </section>
</template>
