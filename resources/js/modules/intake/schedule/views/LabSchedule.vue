<script setup>
/**
 * [Paket 36] Laboratoriya — «Grafik bo'yicha qabul»: kunning partiyalari (grafik yoqilgan bo'limlar).
 * «Keldi» → namunalar qabul qilinadi (proba raqami, yorliq), buyurtmachiga xabar boradi.
 * Laboratoriya namuna olish nuqtalarini ko'rmaydi.
 */
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { CalendarClock, CheckSquare, ChevronLeft, ChevronRight, Settings2, Tags } from 'lucide-vue-next'
import api from '@/lib/api'
import PageHeader from '@/components/ui/PageHeader.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import { useUiStore } from '@/stores/ui'
import ContactPicker from '../../requests/components/ContactPicker.vue'
import { apiErrorMessage } from '../../requests/shared'
import { RUN_TONE, isoDate, niceDate, shiftDate } from '../shared'
import '../../requests/i18n'
import '../../requests/i18nP33'
import '../i18n'

const { t, locale } = useI18n()
const route = useRoute()
const router = useRouter()
const ui = useUiStore()

const today = isoDate()
const date = ref(route.query.date || today)
const board = ref(null)
const loading = ref(true)
const loadError = ref('')

async function load() {
  loading.value = true
  loadError.value = ''
  try {
    const { data } = await api.get('/schedule-board', { params: { date: date.value } })
    board.value = data
  } catch (e) {
    loadError.value = apiErrorMessage(e, t('sched.loadError'))
  } finally {
    loading.value = false
  }
}
onMounted(load)
watch(date, (v) => {
  router.replace({ query: { ...route.query, date: v === today ? undefined : v } })
  load()
})

const runs = computed(() => board.value?.data ?? [])
const timeOf = (dt) => (dt ? dt.slice(11, 16) : '')

/* ---------- «Keldi» ---------- */
const arriving = ref(null)
const pick = ref([])
const courier = reactive({ name: '', phone: '', receivedBy: '' })
const contactPending = ref(false)
const busy = ref(false)

function openArrive(r) {
  arriving.value = r
  pick.value = r.items.map((_, i) => i + 1)
  courier.name = r.contact_name || ''
  courier.phone = r.contact_phone || ''
  courier.receivedBy = board.value?.me || ''
}
const allPicked = computed(() => arriving.value && pick.value.length === arriving.value.items.length)
function toggleAll() {
  pick.value = allPicked.value ? [] : arriving.value.items.map((_, i) => i + 1)
}

async function confirmArrive() {
  if (contactPending.value) {
    ui.error(t('sreq.p34.answerFirst'))
    return
  }
  busy.value = true
  try {
    const { data } = await api.post(`/schedule-board/${arriving.value.id}/arrive`, {
      positions: pick.value, handed_over_by: courier.name || null, handed_over_phone: courier.phone || null,
      received_by: courier.receivedBy || null,
    })
    board.value.data = board.value.data.map((r) => (r.id === data.id ? data : r))
    ui.success(t('sched.lab.done', { n: data.received_count }))
    arriving.value = null
  } catch (e) {
    ui.error(apiErrorMessage(e, t('sched.loadError')))
  } finally {
    busy.value = false
  }
}

/* ---------- sozlama ---------- */
const settingsOpen = ref(false)
const settings = ref([])
async function openSettings() {
  settingsOpen.value = true
  try {
    const { data } = await api.get('/schedule-board/settings')
    settings.value = data.data
  } catch (e) {
    ui.error(apiErrorMessage(e, t('sched.loadError')))
  }
}
async function saveSetting(d) {
  try {
    await api.put(`/schedule-board/settings/${d.id}`, { enabled: d.enabled, late_minutes: Number(d.late_minutes) || 30 })
    ui.success(t('sched.saved'))
  } catch (e) {
    ui.error(apiErrorMessage(e, t('sched.loadError')))
  }
}
watch(settingsOpen, (v) => { if (!v) load() })
</script>

<template>
  <div>
    <PageHeader :title="t('sched.lab.title')">
      <template #actions>
        <button v-if="board?.can_manage" class="btn-outline" data-settings @click="openSettings"><Settings2 :size="16" /> {{ t('sched.lab.settings') }}</button>
      </template>
    </PageHeader>
    <p class="mb-4 max-w-3xl text-[13.5px] leading-relaxed text-ink-muted">{{ t('sched.lab.hint') }}</p>

    <div class="panel p-3 mb-4 flex flex-wrap items-center gap-2">
      <button class="btn-ghost btn-icon h-8 w-8" :title="t('sched.prev')" :aria-label="t('sched.prev')" @click="date = shiftDate(date, -1)"><ChevronLeft :size="16" /></button>
      <input v-model="date" type="date" class="field-input w-auto">
      <button class="btn-ghost btn-icon h-8 w-8" :title="t('sched.next')" :aria-label="t('sched.next')" @click="date = shiftDate(date, 1)"><ChevronRight :size="16" /></button>
      <button v-if="date !== today" class="btn-ghost btn-sm" @click="date = today">{{ t('sched.today') }}</button>
      <span class="ml-2 text-[13.5px] font-medium text-ink">{{ niceDate(date, locale) }}</span>
    </div>

    <div v-if="loading" class="h-24 rounded-panel bg-surface-sunken animate-pulse" />
    <p v-else-if="loadError" class="panel p-4 text-[14px] text-crit">{{ loadError }}</p>
    <div v-else-if="!runs.length" class="panel">
      <EmptyState :icon="CalendarClock" :title="t('sched.lab.empty')" :hint="t('sched.lab.emptyHint')" />
    </div>

    <div v-else class="panel overflow-hidden divide-y divide-line">
      <div v-for="r in runs" :key="r.id" class="flex flex-wrap items-center gap-x-5 gap-y-2 px-4 py-3" :class="r.status === 'late' && 'bg-warn-light/30 dark:bg-warn-dark/10'" :data-run="r.id">
        <div class="w-16 shrink-0 font-mono text-[20px] font-semibold text-ink tnum">{{ r.time }}</div>
        <div class="min-w-[240px] flex-1">
          <div class="flex flex-wrap items-center gap-2">
            <span class="text-[14.5px] font-semibold text-ink">{{ r.department?.name }}</span>
            <span class="text-[13.5px] text-ink-muted">«{{ r.name }}»</span>
            <span class="rounded px-2 py-0.5 text-[12px] font-medium" :class="RUN_TONE[r.status]" :data-status="r.status">{{ t(`sched.status.${r.status}`) }}</span>
          </div>
          <p class="mt-0.5 flex flex-wrap gap-x-3 text-[12.5px] text-ink-muted">
            <span>{{ t('sched.samples', { n: r.items_count }) }}</span>
            <span class="font-mono">{{ [...new Set(r.items.flatMap((i) => i.elements))].join(', ') }}</span>
            <template v-if="r.arrived_at">
              <span class="text-ok-dark dark:text-ok-light">{{ t('sched.receivedLine', { n: r.received_count, time: timeOf(r.arrived_at) }) }}</span>
              <span v-if="r.missing_count" class="text-crit">{{ t('sched.missingLine', { n: r.missing_count }) }}</span>
              <span v-if="r.received_by">{{ t('sched.receivedBy', { name: r.received_by }) }}</span>
            </template>
            <span v-if="r.note" class="italic">{{ r.note }}</span>
          </p>
        </div>
        <div class="flex items-center gap-1.5">
          <template v-if="['planned', 'late'].includes(r.status)">
            <button v-if="r.date <= today" class="btn-primary btn-sm" :data-arrive="r.id" @click="openArrive(r)">
              <CheckSquare :size="15" /> {{ t('sched.lab.arrived') }}
            </button>
            <span v-else class="text-[12px] text-ink-faint">{{ t('sched.lab.future') }}</span>
          </template>
          <button v-if="r.request_id" class="btn-outline btn-sm" @click="router.push({ name: 'sample-request', params: { id: r.request_id } })">
            <Tags :size="15" /> {{ t('sched.lab.openRequest') }}
          </button>
        </div>
      </div>
    </div>

    <!-- «Keldi» -->
    <BaseModal
      :model-value="!!arriving" size="lg"
      :title="arriving ? t('sched.lab.arriveTitle', { dept: arriving.department?.name, time: arriving.time, name: arriving.name }) : ''"
      @update:model-value="!$event && (arriving = null)"
    >
      <template v-if="arriving">
        <p class="mb-2 text-[13px] text-ink-muted">{{ t('sched.lab.arriveHint') }}</p>
        <div class="max-h-72 overflow-y-auto rounded-md border border-line">
          <label class="flex items-center gap-3 border-b border-line bg-surface-sunken/50 px-3 py-2 text-[13px] font-medium">
            <input type="checkbox" class="h-4 w-4" :checked="allPicked" @change="toggleAll"> {{ t('sched.lab.all') }} ({{ pick.length }}/{{ arriving.items.length }})
          </label>
          <label v-for="(it, i) in arriving.items" :key="i" class="flex items-center gap-3 px-3 py-1.5 text-[13px] hover:bg-surface-sunken/40" :data-pick="i + 1">
            <input v-model="pick" type="checkbox" class="h-4 w-4" :value="i + 1">
            <span class="w-6 text-ink-faint tnum">{{ i + 1 }}</span>
            <span class="w-28 font-medium text-ink">{{ it.customer_code || '—' }}</span>
            <span class="flex-1 font-mono text-[12px] text-ink-muted">{{ it.elements.join(', ') }}</span>
            <span class="text-ink-muted tnum">{{ it.quantity ? `${it.quantity} ${it.quantity_unit || ''}` : '' }}</span>
          </label>
        </div>
        <div class="mt-4 flex flex-wrap items-end gap-3">
          <ContactPicker
            v-model:name="courier.name" v-model:phone="courier.phone" :department-id="arriving.department?.id"
            :name-label="t('sreq.receive.handedOverBy')" :phone-label="t('sreq.form.phone')"
            name-class="min-w-[220px] flex-1" phone-class="w-[200px]" data-name="handed" data-phone="handed-phone"
            @update:pending="contactPending = $event"
          />
          <div class="min-w-[200px]">
            <label class="field-label">{{ t('sreq.p33.receivedBy') }}</label>
            <select v-model="courier.receivedBy" class="field-input" data-received-by>
              <option v-for="u in board?.receivers ?? []" :key="u.id" :value="u.id">{{ u.name }}</option>
            </select>
          </div>
        </div>
      </template>
      <template #footer>
        <button class="btn-ghost" @click="arriving = null">{{ t('common.cancel') }}</button>
        <button class="btn-primary" :disabled="busy || !pick.length" data-confirm-arrive @click="confirmArrive">
          {{ t('sched.lab.confirm', { n: pick.length }) }}
        </button>
      </template>
    </BaseModal>

    <!-- Sozlama -->
    <BaseModal v-model="settingsOpen" :title="t('sched.lab.setTitle')" size="lg">
      <p class="mb-3 text-[13px] leading-relaxed text-ink-muted">{{ t('sched.lab.setHint') }}</p>
      <table class="w-full text-[13.5px]">
        <thead>
          <tr class="text-left text-[11.5px] uppercase tracking-wide text-ink-faint border-b border-line">
            <th class="py-2 font-medium" />
            <th class="py-2 font-medium">{{ t('sched.lab.setEnabled') }}</th>
            <th class="py-2 font-medium">{{ t('sched.lab.setLate') }}</th>
            <th class="py-2 font-medium text-right">{{ t('sched.lab.setRows') }}</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-line">
          <tr v-for="d in settings" :key="d.id" :data-setting="d.id">
            <td class="py-2 pr-3 text-ink">{{ d.name }}</td>
            <td class="py-2"><input v-model="d.enabled" type="checkbox" class="h-4 w-4" @change="saveSetting(d)"></td>
            <td class="py-2"><input v-model="d.late_minutes" type="number" min="5" max="600" class="field-input w-24" :disabled="!d.enabled" @change="saveSetting(d)"></td>
            <td class="py-2 text-right tnum text-ink-muted">{{ d.schedules }}</td>
          </tr>
        </tbody>
      </table>
    </BaseModal>
  </div>
</template>
