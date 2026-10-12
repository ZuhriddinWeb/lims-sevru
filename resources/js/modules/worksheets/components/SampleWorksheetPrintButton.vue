<script setup>
/**
 * Probalar jadvali qatoridagi "chop etish" tugmasi.
 * Probada varaq bo'lmasa — ko'rinmaydi. Bitta varaq — darhol chop etish sahifasi,
 * bir nechta — tanlash oynasi ("Hammasini chop etish" bilan).
 * So'rovlar sahifa bo'yicha bitta paketga yig'iladi (sampleWorksheets.js).
 *
 *   <SampleWorksheetPrintButton :sample="row" />
 */
import { computed, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { AlertTriangle, Printer } from 'lucide-vue-next'
import BaseModal from '@/components/ui/BaseModal.vue'
import { useAuthStore } from '@/stores/auth'
import { formatDate } from '@/lib/format'
import WsStatusBadge from './WsStatusBadge.vue'
import { requestSampleWorksheets, sampleWorksheets } from '../sampleWorksheets'
import { templateName } from '../helpers'
import '../i18n'

const props = defineProps({ sample: { type: Object, required: true } })

const { t, locale } = useI18n()
const router = useRouter()
const auth = useAuthStore()

const allowed = computed(() => auth.can('worksheets.view'))
const items = computed(() => sampleWorksheets(props.sample?.id))
const pickOpen = ref(false)

watch(() => props.sample?.id, (id) => { if (allowed.value && id) requestSampleWorksheets(id) }, { immediate: true })

function go(ids) {
  pickOpen.value = false
  router.push({ name: 'worksheet-print', params: { id: ids.join(',') } })
}

function click() {
  if (items.value.length === 1) go([items.value[0].id])
  else pickOpen.value = true
}
</script>

<template>
  <template v-if="allowed && items.length">
    <button
      class="btn-ghost btn-sm btn-icon relative"
      :title="items.length === 1 ? `${t('ws.print')} · № ${items[0].number}` : t('ws.sampleWorksheets')"
      @click.stop="click"
    >
      <Printer :size="15" />
      <span
        v-if="items.length > 1"
        class="absolute -top-0.5 -right-0.5 min-w-[15px] h-[15px] px-0.5 rounded-full bg-assay-500 text-white text-[9.5px] font-semibold grid place-items-center tnum"
      >{{ items.length }}</span>
    </button>

    <BaseModal v-model="pickOpen" size="sm" :title="t('ws.sampleWorksheets')" :subtitle="sample.code">
      <ul class="divide-y divide-line -mx-1" @click.stop>
        <li
          v-for="w in items" :key="w.id"
          class="flex items-center justify-between gap-3 px-1 py-2 rounded hover:bg-surface-sunken/60 cursor-pointer"
          @click="go([w.id])"
        >
          <span class="min-w-0">
            <span class="font-mono font-semibold text-ink">{{ w.number }}</span>
            <AlertTriangle v-if="w.has_warnings" :size="13" class="inline ml-1 -mt-0.5 text-warn" />
            <span class="block text-[12.5px] text-ink-muted truncate">
              {{ templateName(w.template, locale) }} · {{ formatDate(w.worksheet_date) }}
              <template v-if="w.samples_count > 1"> · {{ t('ws.batchOf', { n: w.samples_count }) }}</template>
              <template v-if="w.member_status === 'retest'"> · {{ t('ws.memberRetest') }}</template>
            </span>
          </span>
          <span class="flex items-center gap-2 shrink-0">
            <WsStatusBadge :status="w.status" size="sm" />
            <Printer :size="15" class="text-ink-faint" />
          </span>
        </li>
      </ul>
      <template #footer>
        <button class="btn-ghost" @click="pickOpen = false">{{ t('common.close') }}</button>
        <button class="btn-primary" @click="go(items.map((w) => w.id))">
          <Printer :size="16" /> {{ t('ws.printAll') }}
        </button>
      </template>
    </BaseModal>
  </template>
</template>
