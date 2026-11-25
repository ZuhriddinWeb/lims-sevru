<script setup>
/** Buyurtma sarlavhasi: holat, bo'lim, sanalar, kim olib keladi / topshirdi, yopilish sababi */
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { AlertTriangle, Info, Link2 } from 'lucide-vue-next'
import { formatDate, formatDateTime } from '@/lib/format'
import StatusPill from './StatusPill.vue'
import { deliveryText, nameOf } from '../shared'
import '../i18n'
import '../../svodka/i18n' // [P29]
import '../i18nP39' // [P39]
import '../i18nP42' // [P42]

const props = defineProps({
  request: { type: Object, required: true },
  audience: { type: String, default: 'portal' }, // portal | lab
})
const { t, locale } = useI18n()
const r = computed(() => props.request)

const rows = computed(() => [
  [t('sreq.col.department'), r.value.department ? r.value.department.name + (r.value.department.code ? ` (${r.value.department.code})` : '') : '—'],
  r.value.laboratory_group && [t('sreq.group.label'), nameOf(r.value.laboratory_group, locale.value)], // [P30]
  r.value.scheduled_at && [t('sreq.p39.scheduledAt'), formatDateTime(r.value.scheduled_at)], // [P39]
  [t('sreq.info.created'), `${formatDateTime(r.value.created_at)}${r.value.created_by ? ' · ' + r.value.created_by : ''}`],
  r.value.submitted_at && [t('sreq.info.submitted'), `${formatDateTime(r.value.submitted_at)}${r.value.submitted_by ? ' · ' + r.value.submitted_by : ''}`],
  (r.value.delivery_date || r.value.delivery_shift) && [t('sreq.col.delivery'), deliveryText(r.value)],
  // [P29] svodka (faqat buyurtmachi javobida)
  r.value.svodka_date && [t('svodka.form.row'), [formatDate(r.value.svodka_date), r.value.svodka_shift_label].filter(Boolean).join(' · ')],
  r.value.expires_at && ['submitted', 'expired'].includes(r.value.status) && [t('sreq.info.expires'), formatDate(r.value.expires_at)],
  (r.value.contact_name || r.value.contact_phone) && [t('sreq.col.contact'), [r.value.contact_name, r.value.contact_phone].filter(Boolean).join(', ')],
  // [P33] «Qabul qilindi: sana · kim qabul qilib oldi»; «Olib keldi: F.I.Sh., telefon»
  r.value.received_at && [t('sreq.info.received'), `${formatDateTime(r.value.received_at)}${r.value.received_by ? ' · ' + r.value.received_by : ''}`],
  // [P39] kechiktirib kiritilgan: dasturga qachon kiritilgan
  r.value.late_entry && r.value.received_entered_at && [t('sreq.p39.enteredAt'), formatDateTime(r.value.received_entered_at)],
  (r.value.handed_over_by || r.value.handed_over_phone) && [t('sreq.receive.handedOverBy'), [r.value.handed_over_by, r.value.handed_over_phone].filter(Boolean).join(', ')],
  r.value.closed_at && ['rejected', 'cancelled'].includes(r.value.status) && [t('sreq.info.closed'), `${formatDateTime(r.value.closed_at)}${r.value.closed_by ? ' · ' + r.value.closed_by : ''}`],
  r.value.notes && [t('sreq.form.notes'), r.value.notes],
].filter(Boolean))

const note = computed(() => {
  if (props.audience !== 'portal') return null
  if (r.value.status === 'draft') return { tone: 'info', text: t('sreq.info.draftNote') }
  if (r.value.status === 'submitted' || r.value.status === 'partial') return { tone: 'info', text: t('sreq.info.sentNote') }
  if (r.value.status === 'expired') return { tone: 'warn', text: t('sreq.p39.expiredNote') } // [P39] sabab bilan yopish
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
      <span v-if="r.origin === 'schedule'" class="rounded bg-aqua-50 px-1.5 py-0.5 text-[12px] font-medium text-aqua-700 dark:bg-aqua-800/30 dark:text-aqua-200" data-sched-badge>{{ t('sreq.p39.schedBadge', { time: (r.scheduled_at || '').slice(11, 16) }) }}</span>
      <span v-if="r.late_entry" class="rounded bg-warn-light/70 px-1.5 py-0.5 text-[12px] font-medium text-warn-dark dark:bg-warn-dark/25 dark:text-warn-light" data-late-badge>{{ t('sreq.p39.lateEntry') }}</span>
      <span v-if="r.auto_closed" class="rounded bg-crit-light/60 px-1.5 py-0.5 text-[12px] font-medium text-crit-dark dark:bg-crit-dark/25 dark:text-crit-light">{{ t('sreq.p39.autoClosed') }}</span>
    </div>

    <dl class="grid gap-x-6 gap-y-1.5 sm:grid-cols-[max-content_1fr] text-[13.5px]">
      <template v-for="[k, v] in rows" :key="k">
        <dt class="text-ink-muted">{{ k }}</dt>
        <dd class="text-ink min-w-0 break-words">{{ v }}</dd>
      </template>
    </dl>

    <!-- [P42] bir formadan guruhlar bo'yicha bo'lingan boshqa buyurtmalar -->
    <div v-if="r.split?.length" class="mt-3 flex flex-wrap items-center gap-x-2 gap-y-1 rounded-md border border-line px-3 py-2 text-[13px]" data-split>
      <Link2 :size="15" class="shrink-0 text-ink-faint" />
      <span class="text-ink-muted" :title="t('sreq.p42.splitHint')">{{ t('sreq.p42.split') }}:</span>
      <router-link
        v-for="x in r.split" :key="x.id" :to="{ name: audience === 'lab' ? 'sample-request' : 'portal-request', params: { id: x.id } }"
        class="font-mono font-semibold text-ink hover:underline"
      >{{ x.number || '#' + x.id }}<span v-if="x.group" class="ml-1 font-sans font-normal text-ink-faint">({{ nameOf(x.group, locale) }})</span></router-link>
    </div>

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
