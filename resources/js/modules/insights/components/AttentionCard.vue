<script setup>
/**
 * «Diqqat talab qiladi»: kechikayotgan probalar, tasdiq kutayotgan varaqlar, qoldig'i kam probalar,
 * kalibrovkasi o'tgan uskunalar. Har biri bosilsa — o'sha sahifa ochiladi.
 */
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { AlertTriangle, CheckCircle2, ChevronRight, ClipboardCheck, Hourglass, Microscope, Scale } from 'lucide-vue-next'
import { localized } from '@/i18n'
import { dmy, num, parseTs, qty } from '../helpers'

const props = defineProps({
  flow: { type: Object, default: null },
  worksheets: { type: Object, default: null },
  balances: { type: Object, default: null },
  equipment: { type: Object, default: null },
  overdueDays: { type: Number, default: 7 },
})
defineEmits(['open'])
const { t, locale } = useI18n()

const sections = computed(() => {
  const out = []
  const ov = props.flow?.overdue ?? []
  if (ov.length) {
    out.push({
      key: 'overdue', icon: Hourglass, title: t('insights.attention.overdue'), count: props.flow.overdue_count ?? ov.length,
      items: ov.slice(0, 4).map((s) => ({
        id: s.id, main: s.code, sub: [localized(s.group, 'name'), t(`insights.flow.stages.${s.stage}`)].filter(Boolean).join(' · '),
        badge: t('insights.days', { n: s.days }), to: { name: 'sample-detail', params: { id: s.id } },
      })),
    })
  }
  const aw = props.worksheets?.awaiting?.items ?? []
  if (aw.length) {
    out.push({
      key: 'awaiting', icon: ClipboardCheck, title: t('insights.attention.awaiting'), count: props.worksheets.awaiting.count,
      items: aw.slice(0, 4).map((w) => ({
        id: w.id, main: `№ ${w.number}`, sub: w.kind === 'control' ? t('insights.attention.control') : '',
        badge: w.days != null ? t('insights.days', { n: w.days }) : '', to: { name: 'worksheet-detail', params: { id: w.id } },
      })),
    })
  }
  const bl = props.balances?.items ?? []
  if (bl.length) {
    out.push({
      key: 'balances', icon: Scale, title: t('insights.attention.balances'), count: props.balances.attention,
      items: bl.slice(0, 4).map((b) => ({
        id: b.id, main: b.code, sub: b.available != null ? t('insights.attention.left', { v: `${qty(b.available, locale.value)} ${b.unit ?? ''}`.trim() }) : '',
        badge: t(`insights.attention.state.${b.state}`), to: { name: 'sample-detail', params: { id: b.id } },
      })),
    })
  }
  const eq = props.equipment?.items ?? []
  if (eq.length) {
    out.push({
      key: 'calibration', icon: Microscope, title: t('insights.attention.calibration'), count: props.equipment.overdue,
      items: eq.slice(0, 4).map((e) => ({
        id: e.id, main: e.name, sub: e.serial_number ?? '', plain: true,
        badge: e.next_calibration_at ? dmy(parseTs(e.next_calibration_at)) : '', to: { name: 'equipment-detail', params: { id: e.id } },
      })),
    })
  }
  return out
})
</script>

<template>
  <section class="panel p-4" data-attention>
    <h2 class="text-[14px] font-semibold text-ink mb-3 flex items-center gap-1.5">
      <AlertTriangle :size="15" class="text-warn" /> {{ t('insights.attention.title') }}
    </h2>
    <p v-if="!sections.length" class="flex items-center gap-1.5 text-[13px] text-ok" data-all-good>
      <CheckCircle2 :size="15" /> {{ t('insights.attention.none') }}
    </p>
    <div v-else class="space-y-4">
      <div v-for="s in sections" :key="s.key" :data-section="s.key">
        <p class="flex items-center justify-between gap-2 text-[12.5px] font-medium text-ink-muted mb-1">
          <span class="inline-flex items-center gap-1.5"><component :is="s.icon" :size="14" />{{ s.title }}</span>
          <span class="tnum rounded-full bg-surface-sunken px-2 py-0.5 text-[11.5px] text-ink">{{ num(s.count, locale) }}</span>
        </p>
        <ul class="divide-y divide-line">
          <li v-for="it in s.items" :key="it.id">
            <button type="button" class="w-full flex items-center gap-2 py-1.5 text-left rounded hover:bg-surface-sunken/60 px-1 -mx-1" :data-item="`${s.key}-${it.id}`" @click="$emit('open', it.to)">
              <span class="min-w-0 flex-1">
                <span class="block text-[13px] text-ink truncate" :class="it.plain ? '' : 'font-mono'">{{ it.main }}</span>
                <span v-if="it.sub" class="block text-[11.5px] text-ink-faint truncate">{{ it.sub }}</span>
              </span>
              <span v-if="it.badge" class="shrink-0 text-[12px] tnum text-ink-muted">{{ it.badge }}</span>
              <ChevronRight :size="14" class="shrink-0 text-ink-faint" />
            </button>
          </li>
        </ul>
      </div>
    </div>
  </section>
</template>
