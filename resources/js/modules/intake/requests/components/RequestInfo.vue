<script setup>
/** Buyurtma sarlavhasi: holat, bo'lim, sanalar, kim olib keladi / topshirdi, yopilish sababi */
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { AlertTriangle, Info } from 'lucide-vue-next'
import { formatDate, formatDateTime } from '@/lib/format'
import StatusPill from './StatusPill.vue'
import { deliveryText, nameOf } from '../shared'
import '../i18n'
import '../../svodka/i18n' // [P29]

const props = defineProps({
  request: { type: Object, required: true },
  audience: { type: String, default: 'portal' }, // portal | lab
})
const { t, locale } = useI18n()
const r = computed(() => props.request)

const rows = computed(() => [
  [t('sreq.col.department'), r.value.department ? r.value.department.name + (r.value.department.code ? ` (${r.value.department.code})` : '') : '—'],
  r.value.laboratory_group && [t('sreq.group.label'), nameOf(r.value.laboratory_group, locale.value)], // [P30]
  [t('sreq.info.created'), `${formatDateTime(r.value.created_at)}${r.value.created_by ? ' · ' + r.value.created_by : ''}`],
  r.value.submitted_at && [t('sreq.info.submitted'), `${formatDateTime(r.value.submitted_at)}${r.value.submitted_by ? ' · ' + r.value.submitted_by : ''}`],
  (r.value.delivery_date || r.value.delivery_shift) && [t('sreq.col.delivery'), deliveryText(r.value)],
  // [P29] svodka (faqat buyurtmachi javobida)
  r.value.svodka_date && [t('svodka.form.row'), [formatDate(r.value.svodka_date), r.value.svodka_shift_label].filter(Boolean).join(' · ')],
  r.value.expires_at && ['submitted', 'expired'].includes(r.value.status) && [t('sreq.info.expires'), formatDate(r.value.expires_at)],
  (r.value.contact_name || r.value.contact_phone) && [t('sreq.col.contact'), [r.value.contact_name, r.value.contact_phone].filter(Boolean).join(', ')],
  // [P33] «Qabul qilindi: sana · kim qabul qilib oldi»; «Olib keldi: F.I.Sh., telefon»
  r.value.received_at && [t('sreq.info.received'), `${formatDateTime(r.value.received_at)}${r.value.received_by ? ' · ' + r.value.received_by : ''}`],
  (r.value.handed_over_by || r.value.handed_over_phone) && [t('sreq.receive.handedOverBy'), [r.value.handed_over_by, r.value.handed_over_phone].filter(Boolean).join(', ')],
  r.value.closed_at && ['rejected', 'cancelled'].includes(r.value.status) && [t('sreq.info.closed'), `${formatDateTime(r.value.closed_at)}${r.value.closed_by ? ' · ' + r.value.closed_by : ''}`],
  r.value.notes && [t('sreq.form.notes'), r.value.notes],
].filter(Boolean))

const note = computed(() => {
  if (props.audience !== 'portal') return null
  if (r.value.status === 'draft') return { tone: 'info', text: t('sreq.info.draftNote') }
  if (r.value.status === 'submitted' || r.value.status === 'partial') return { tone: 'info', text: t('sreq.info.sentNote') }
  if (r.value.status === 'expired') return { tone: 'warn', text: t('sreq.info.expiredNote') }
  return null
})
</script>

<template>
  <div class="panel p-4">
    <div class="flex flex-wrap items-center gap-2.5 mb-3">
      <span v-if="r.number" class="font-mono text-[17px] font-semibold text-ink" data-number>{{ r.number }}</span>
      <StatusPill :status="r.status" />
      <span v-if="r.counts.items" class="text-[13px] text-ink-muted tnum">{{ t('sreq.progress', { r: r.counts.received, n: r.counts.items }) }}</span>
      <span v-if="r.origin === 'lab'" class="text-[12px] text-ink-faint">· {{ t('sreq.info.byLab') }}</span>
    </div>

    <dl class="grid gap-x-6 gap-y-1.5 sm:grid-cols-[max-content_1fr] text-[13.5px]">
      <template v-for="[k, v] in rows" :key="k">
        <dt class="text-ink-muted">{{ k }}</dt>
        <dd class="text-ink min-w-0 break-words">{{ v }}</dd>
      </template>
    </dl>

    <p v-if="r.close_reason" class="mt-3 flex items-start gap-2 rounded-md bg-crit-light/60 dark:bg-crit-dark/20 px-3 py-2 text-[13px] text-crit-dark dark:text-crit-light" data-reason>
      <AlertTriangle :size="15" class="mt-0.5 shrink-0" /> <span><b>{{ t('sreq.info.reason') }}:</b> {{ r.close_reason }}</span>
    </p>
    <p
      v-if="note" class="mt-3 flex items-start gap-2 rounded-md px-3 py-2 text-[13px]"
      :class="note.tone === 'warn' ? 'bg-warn-light/70 text-warn-dark dark:bg-warn-dark/20 dark:text-warn-light' : 'bg-aqua-50 text-aqua-700 dark:bg-aqua-800/25 dark:text-aqua-200'"
      data-note
    >
      <Info :size="15" class="mt-0.5 shrink-0" /> {{ note.text }}
    </p>
  </div>
</template>
