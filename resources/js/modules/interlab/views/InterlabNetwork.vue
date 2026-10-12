<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'
import {
  AlertTriangle, CheckCircle2, Copy, KeyRound, Link2, Network, Pencil, Plus, Power, PowerOff, RefreshCw,
  Server, ShieldCheck, Trash2, XCircle,
} from 'lucide-vue-next'
import api from '@/lib/api'
import PageHeader from '@/components/ui/PageHeader.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import ConfirmDialog from '@/components/ui/ConfirmDialog.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import { useUiStore } from '@/stores/ui'
import { formatDateTime } from '@/lib/format'
import { apiErrorMessage, peerTone } from '../helpers'
import '../i18n'

const { t } = useI18n()
const route = useRoute()
const ui = useUiStore()

const net = ref(null)
const loading = ref(true)
const busy = ref(false)
const setup = reactive({ name: '', code: '', base_url: '' })

const editOpen = ref(false)
// Kod yaratish
const inviteOpen = ref(false)
const invite = reactive({ hours: 24, note: '' })
const createdCode = ref(null) // { code, invite }
// Ulanish
const addOpen = ref(false)
const connectForm = reactive({ url: '', code: '' })
const connectError = ref('')
const removeTarget = ref(null)

const highlight = computed(() => Number(route.params.id) || null)
const activeInvites = computed(() => (net.value?.invites ?? []).filter((i) => i.state === 'active'))
const pastInvites = computed(() => (net.value?.invites ?? []).filter((i) => i.state !== 'active'))

async function load() {
  try {
    const { data } = await api.get('/interlab/network')
    net.value = data
    if (!data.identity && !setup.base_url) setup.base_url = data.suggested_url
  } catch (e) {
    ui.error(apiErrorMessage(e, t('il.loadError')))
  } finally {
    loading.value = false
  }
}
onMounted(load)

async function call(fn, ok) {
  busy.value = true
  try {
    const { data } = await fn()
    if (data?.peers) net.value = data
    if (ok) ui.success(ok)
    return data
  } catch (e) {
    ui.error(apiErrorMessage(e, t('il.saveError')))
    return null
  } finally {
    busy.value = false
  }
}

async function doSetup() {
  await call(() => api.post('/interlab/network/identity', setup), t('il.setupDone'))
}

function openEdit() {
  Object.assign(setup, { name: net.value.identity.name, code: net.value.identity.code, base_url: net.value.identity.base_url ?? '' })
  editOpen.value = true
}
async function doEdit() {
  if (await call(() => api.put('/interlab/network/identity', setup), t('il.saved'))) editOpen.value = false
}

// ---- Ulanish kodi
function openInvite() {
  Object.assign(invite, { hours: 24, note: '' })
  createdCode.value = null
  inviteOpen.value = true
}
async function doInvite() {
  const data = await call(() => api.post('/interlab/network/invites', { hours: invite.hours, note: invite.note || null }))
  if (data?.code) {
    createdCode.value = data
    load()
  }
}
async function revokeInvite(i) {
  await call(() => api.delete(`/interlab/network/invites/${i.id}`), t('il.inviteRevoked'))
}

// ---- Ulanish
function openAdd() {
  Object.assign(connectForm, { url: '', code: '' })
  connectError.value = ''
  addOpen.value = true
}
async function doConnect() {
  connectError.value = ''
  busy.value = true
  try {
    const { data } = await api.post('/interlab/network/peers', { url: connectForm.url, code: connectForm.code })
    net.value = data
    ui.success(t('il.connectedActive', { lab: data.connected?.name ?? '' }))
    addOpen.value = false
  } catch (e) {
    connectError.value = apiErrorMessage(e, t('il.saveError'))
  } finally {
    busy.value = false
  }
}

async function peerAction(p, action, ok) {
  await call(() => api.post(`/interlab/network/peers/${p.id}/${action}`), ok)
}
async function doRemove() {
  const p = removeTarget.value
  if (await call(() => api.delete(`/interlab/network/peers/${p.id}`))) removeTarget.value = null
}

async function copy(text) {
  try {
    await navigator.clipboard.writeText(text)
    ui.success(t('il.copied'))
  } catch { /* brauzer ruxsat bermadi — matnni qo'lda belgilash mumkin */ }
}

const schedulerState = computed(() => {
  const at = net.value?.identity?.last_sync_at
  if (!at) return { ok: false, text: t('il.schedulerNever') }
  const age = (Date.now() - new Date(at).getTime()) / 1000
  return age < 180 ? { ok: true, text: t('il.schedulerOk') } : { ok: false, text: t('il.schedulerWarn', { t: formatDateTime(at) }) }
})

const INVITE_TONE = {
  used: 'bg-ok-light text-ok-dark dark:bg-ok-dark/30 dark:text-ok-light',
  expired: 'bg-surface-sunken text-ink-faint',
  revoked: 'bg-crit-light text-crit-dark dark:bg-crit-dark/30 dark:text-crit-light',
}
</script>

<template>
  <div>
    <PageHeader :title="t('il.netTitle')" :subtitle="t('il.netSubtitle')">
      <template v-if="net?.identity" #actions>
        <button class="btn-outline" @click="openInvite"><KeyRound :size="16" /> {{ t('il.createInvite') }}</button>
        <button class="btn-primary" @click="openAdd"><Plus :size="17" /> {{ t('il.addPeer') }}</button>
      </template>
    </PageHeader>

    <div v-if="loading" class="space-y-4">
      <div class="h-40 panel animate-pulse" />
      <div class="h-56 panel animate-pulse" />
    </div>

    <!-- ===== 1. Sozlash ===== -->
    <section v-else-if="!net?.identity" class="panel p-5 max-w-2xl">
      <div class="flex items-start gap-3 mb-4">
        <div class="h-10 w-10 rounded-full bg-assay-50 text-assay-600 dark:bg-assay-800/30 grid place-items-center shrink-0"><Server :size="19" /></div>
        <div>
          <h2 class="text-[16px] font-semibold text-ink">{{ t('il.setupTitle') }}</h2>
          <p class="text-[13.5px] text-ink-muted mt-0.5">{{ t('il.setupHint') }}</p>
        </div>
      </div>
      <div class="grid sm:grid-cols-3 gap-3">
        <div class="sm:col-span-2">
          <label class="field-label">{{ t('il.labName') }}</label>
          <input v-model="setup.name" class="field-input" :placeholder="t('il.labNamePh')" maxlength="191">
        </div>
        <div>
          <label class="field-label">{{ t('il.labCode') }}</label>
          <input v-model="setup.code" class="field-input font-mono uppercase" placeholder="GMZ3" maxlength="20">
        </div>
        <p class="sm:col-span-3 -mt-1.5 text-[12px] text-ink-faint">{{ t('il.labCodeHint') }}</p>
        <div class="sm:col-span-3">
          <label class="field-label">{{ t('il.labUrl') }}</label>
          <input v-model="setup.base_url" class="field-input font-mono" placeholder="http://10.10.5.20" maxlength="255">
          <p class="mt-1 text-[12px] text-ink-faint">{{ t('il.labUrlHint') }}</p>
        </div>
      </div>
      <div class="mt-4 flex justify-end">
        <button class="btn-primary" :disabled="busy || !setup.name.trim() || !setup.code.trim()" @click="doSetup">
          <ShieldCheck :size="16" /> {{ t('il.setupBtn') }}
        </button>
      </div>
    </section>

    <template v-else>
      <div class="grid lg:grid-cols-3 gap-4">
        <!-- ===== Bu laboratoriya + kodlar ===== -->
        <div class="space-y-4 lg:col-span-1">
          <section class="panel p-4">
            <div class="flex items-start justify-between gap-2 mb-3">
              <h2 class="text-[15px] font-semibold text-ink">{{ t('il.thisLab') }}</h2>
              <button class="btn-ghost btn-sm" @click="openEdit"><Pencil :size="14" /> {{ t('il.edit') }}</button>
            </div>
            <p class="text-[15px] font-medium text-ink">{{ net.identity.name }}</p>
            <p class="text-[13px] text-ink-muted"><span class="font-mono">{{ net.identity.code }}</span> · <span class="font-mono break-all">{{ net.identity.base_url || '—' }}</span></p>
            <p class="mt-2 flex items-start gap-1.5 text-[12.5px]" :class="schedulerState.ok ? 'text-ok' : 'text-warn-dark dark:text-warn-light'">
              <component :is="schedulerState.ok ? CheckCircle2 : AlertTriangle" :size="14" class="shrink-0 mt-0.5" />
              <span>{{ schedulerState.text }}</span>
            </p>
            <details class="mt-3 text-[11.5px] text-ink-faint">
              <summary class="cursor-pointer select-none">{{ t('il.techInfo') }}</summary>
              <p class="mt-1 break-all">{{ t('il.nodeId') }}: <span class="font-mono">{{ net.identity.node_id }}</span></p>
              <p class="break-all">{{ t('il.keyId') }}: <span class="font-mono">{{ net.identity.fingerprint_short }}</span></p>
            </details>
          </section>

          <section class="panel overflow-hidden">
            <div class="px-4 py-3 border-b border-line flex items-center justify-between gap-2">
              <h2 class="text-[15px] font-semibold text-ink">{{ t('il.invites') }}</h2>
              <button class="btn-ghost btn-sm" @click="openInvite"><Plus :size="14" /> {{ t('il.createInviteShort') }}</button>
            </div>
            <p v-if="!net.invites.length" class="px-4 py-4 text-[13px] text-ink-faint">{{ t('il.noInvites') }}</p>
            <ul v-else class="divide-y divide-line">
              <li v-for="i in activeInvites" :key="i.id" class="px-4 py-2.5 flex items-start justify-between gap-2">
                <div class="min-w-0 text-[13px]">
                  <p class="text-ink">{{ i.note || t('il.inviteNoNote') }}</p>
                  <p class="text-[12px] text-ink-muted">{{ t('il.inviteUntil', { t: formatDateTime(i.expires_at) }) }}</p>
                </div>
                <button class="btn-ghost btn-sm shrink-0 hover:text-crit" :disabled="busy" @click="revokeInvite(i)"><XCircle :size="14" /> {{ t('il.revoke') }}</button>
              </li>
              <li v-for="i in pastInvites" :key="i.id" class="px-4 py-2.5 text-[13px]">
                <div class="flex items-center justify-between gap-2">
                  <span class="text-ink-muted truncate">{{ i.note || t('il.inviteNoNote') }}</span>
                  <span class="rounded-full px-2 py-0.5 text-[11px] font-medium shrink-0" :class="INVITE_TONE[i.state]">{{ t(`il.inviteSt.${i.state}`) }}</span>
                </div>
                <p v-if="i.state === 'used'" class="text-[12px] text-ink-muted">{{ i.used_by_name }} · {{ formatDateTime(i.used_at) }}<template v-if="i.used_ip"> · IP {{ i.used_ip }}</template></p>
              </li>
            </ul>
          </section>
        </div>

        <!-- ===== Ulangan laboratoriyalar ===== -->
        <section class="panel lg:col-span-2 overflow-hidden self-start">
          <div class="px-4 py-3 border-b border-line flex items-center justify-between gap-2">
            <h2 class="text-[15px] font-semibold text-ink">{{ t('il.peers') }}</h2>
            <span class="text-[12.5px] text-ink-muted">
              <template v-if="net.queue.pending">{{ t('il.queueN', { n: net.queue.pending }) }}</template>
              <template v-if="net.queue.failed"> · <span class="text-crit">{{ t('il.failedN', { n: net.queue.failed }) }}</span></template>
            </span>
          </div>

          <EmptyState v-if="!net.peers.length" :icon="Network" :title="t('il.noPeersYet')" :hint="t('il.noPeersHint')">
            <button class="btn-primary" @click="openAdd"><Plus :size="16" /> {{ t('il.addPeer') }}</button>
          </EmptyState>

          <ul v-else class="divide-y divide-line">
            <li
              v-for="p in net.peers" :key="p.id"
              class="px-4 py-3 flex flex-wrap items-start justify-between gap-3 transition-colors"
              :class="highlight === p.id ? 'ring-1 ring-inset ring-assay-400 bg-assay-50/40 dark:bg-assay-800/10' : ''"
            >
              <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-2">
                  <span class="text-[14.5px] font-medium text-ink">{{ p.name }}</span>
                  <span v-if="p.code" class="font-mono text-[12px] text-ink-faint">{{ p.code }}</span>
                  <span class="inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-[11px] font-medium" :class="peerTone(p.status).badge">
                    <span class="h-1.5 w-1.5 rounded-full" :class="peerTone(p.status).dot" /> {{ t(`il.peerSt.${p.status}`) }}
                  </span>
                </div>
                <p class="text-[12.5px] text-ink-muted font-mono break-all">{{ p.base_url }}</p>
                <p class="mt-0.5 text-[12px] text-ink-muted flex flex-wrap items-center gap-x-2 gap-y-0.5">
                  <span class="inline-flex items-center gap-1"><span class="h-1.5 w-1.5 rounded-full" :class="p.online ? 'bg-ok' : 'bg-ink-faint'" />{{ p.online ? t('il.online') : t('il.offline') }}</span>
                  <span>{{ t('il.lastContact', { t: p.last_ok_at || p.last_seen_at ? formatDateTime(p.last_ok_at || p.last_seen_at) : t('il.never') }) }}</span>
                  <span v-if="p.pending_messages" class="text-warn-dark dark:text-warn-light">{{ t('il.queueN', { n: p.pending_messages }) }}</span>
                  <span v-if="p.failed_messages" class="text-crit">{{ t('il.failedN', { n: p.failed_messages }) }}</span>
                  <span v-if="p.referrals">{{ t('il.referralsN', { n: p.referrals }) }}</span>
                </p>
                <p v-if="p.last_error && !p.online" class="mt-0.5 text-[12px] text-crit break-words">{{ p.last_error }}</p>
              </div>

              <div class="flex flex-wrap items-center gap-1.5">
                <template v-if="p.status === 'active'">
                  <button class="btn-ghost btn-sm" :disabled="busy" @click="peerAction(p, 'ping', t('il.pinged'))"><RefreshCw :size="14" /> {{ t('il.ping') }}</button>
                  <button class="btn-ghost btn-icon h-8 w-8" :disabled="busy" :title="t('il.disable')" @click="peerAction(p, 'disable')"><PowerOff :size="15" /></button>
                </template>
                <button v-if="p.status === 'disabled'" class="btn-outline btn-sm" :disabled="busy" @click="peerAction(p, 'enable')"><Power :size="14" /> {{ t('il.enable') }}</button>
                <button v-if="['pending_in', 'pending_out'].includes(p.status)" class="btn-outline btn-sm" :disabled="busy" @click="peerAction(p, 'reject')"><XCircle :size="14" /> {{ t('il.rejectPeer') }}</button>
                <button
                  v-if="p.status !== 'active' && !p.referrals" class="btn-ghost btn-icon h-8 w-8 hover:text-crit" :title="t('il.remove')"
                  @click="removeTarget = p"
                ><Trash2 :size="15" /></button>
              </div>
            </li>
          </ul>
        </section>
      </div>

      <!-- Qanday ishlaydi -->
      <section class="panel p-4 mt-4 text-[13.5px]">
        <h2 class="text-[15px] font-semibold text-ink mb-2">{{ t('il.howTitle') }}</h2>
        <ol class="list-decimal pl-5 space-y-1 text-ink-muted">
          <li>{{ t('il.how1') }}</li>
          <li>{{ t('il.how2') }}</li>
          <li>{{ t('il.how3') }}</li>
        </ol>
        <p class="mt-3 flex items-start gap-1.5 text-[12.5px] text-ok"><ShieldCheck :size="14" class="shrink-0 mt-0.5" /> {{ t('il.security') }}</p>
      </section>
    </template>

    <!-- ===== Modallar ===== -->
    <BaseModal v-model="editOpen" :title="t('il.editTitle')" :subtitle="t('il.editHint')" size="md">
      <div class="grid sm:grid-cols-3 gap-3">
        <div class="sm:col-span-2">
          <label class="field-label">{{ t('il.labName') }}</label>
          <input v-model="setup.name" class="field-input" maxlength="191">
        </div>
        <div>
          <label class="field-label">{{ t('il.labCode') }}</label>
          <input v-model="setup.code" class="field-input font-mono uppercase" maxlength="20">
        </div>
        <div class="sm:col-span-3">
          <label class="field-label">{{ t('il.labUrl') }}</label>
          <input v-model="setup.base_url" class="field-input font-mono" maxlength="255">
          <p class="mt-1 text-[12px] text-ink-faint">{{ t('il.labUrlHint') }}</p>
        </div>
      </div>
      <template #footer>
        <button class="btn-ghost" @click="editOpen = false">{{ t('common.cancel') }}</button>
        <button class="btn-primary" :disabled="busy || !setup.name.trim() || !setup.code.trim()" @click="doEdit">{{ t('common.save') }}</button>
      </template>
    </BaseModal>

    <!-- Kod yaratish -->
    <BaseModal v-model="inviteOpen" :title="t('il.inviteTitle')" :subtitle="t('il.inviteHint')" size="md">
      <template v-if="!createdCode">
        <div class="grid sm:grid-cols-3 gap-3">
          <div>
            <label class="field-label">{{ t('il.inviteValid') }}</label>
            <select v-model.number="invite.hours" class="field-input">
              <option v-for="h in net?.invite_hours ?? [1, 8, 24, 72]" :key="h" :value="h">{{ t('il.hoursN', { n: h }) }}</option>
            </select>
          </div>
          <div class="sm:col-span-2">
            <label class="field-label">{{ t('il.inviteNote') }}</label>
            <input v-model="invite.note" class="field-input" :placeholder="t('il.inviteNotePh')" maxlength="191">
          </div>
        </div>
      </template>
      <template v-else>
        <p class="text-[13px] text-ink-muted">{{ t('il.inviteCreated') }}</p>
        <div class="mt-3 rounded-md border border-assay-300 bg-assay-50/60 dark:bg-assay-800/15 p-4 text-center">
          <p class="font-mono text-[22px] sm:text-[24px] font-semibold tracking-wider text-ink select-all break-all" data-invite-code>{{ createdCode.code }}</p>
          <button class="btn-outline btn-sm mt-2" @click="copy(createdCode.code)"><Copy :size="14" /> {{ t('il.copy') }}</button>
        </div>
        <ul class="mt-3 space-y-1 text-[13px] text-ink-muted">
          <li>• {{ t('il.inviteGive', { url: net?.identity?.base_url || '—' }) }}</li>
          <li>• {{ t('il.inviteOnce', { t: formatDateTime(createdCode.invite.expires_at) }) }}</li>
          <li class="text-warn-dark dark:text-warn-light">• {{ t('il.inviteShownOnce') }}</li>
        </ul>
      </template>
      <template #footer>
        <button class="btn-ghost" @click="inviteOpen = false">{{ createdCode ? t('common.close') : t('common.cancel') }}</button>
        <button v-if="!createdCode" class="btn-primary" :disabled="busy" @click="doInvite"><KeyRound :size="16" /> {{ t('il.createInviteShort') }}</button>
      </template>
    </BaseModal>

    <!-- Laboratoriya qo'shish -->
    <BaseModal v-model="addOpen" :title="t('il.discoverTitle')" :subtitle="t('il.connectSub')" size="md">
      <div class="space-y-3">
        <div>
          <label class="field-label">{{ t('il.peerUrl') }}</label>
          <input v-model="connectForm.url" class="field-input font-mono" :placeholder="t('il.urlPh')" maxlength="255">
        </div>
        <div>
          <label class="field-label">{{ t('il.pairCode') }}</label>
          <input
            v-model="connectForm.code" class="field-input font-mono uppercase tracking-wider" placeholder="XXXX-XXXX-XXXX-XXXX-XXXX-XXXX"
            maxlength="64" autocomplete="off" spellcheck="false" @keydown.enter="doConnect"
          >
          <p class="mt-1 text-[12px] text-ink-faint">{{ t('il.pairCodeHint') }}</p>
        </div>
        <p v-if="connectError" class="rounded-md border border-crit/30 bg-crit-light/60 dark:bg-crit-dark/20 px-3 py-2 text-[13px] text-crit-dark dark:text-crit-light" data-connect-error>{{ connectError }}</p>
      </div>
      <template #footer>
        <button class="btn-ghost" @click="addOpen = false">{{ t('common.cancel') }}</button>
        <button class="btn-primary" :disabled="busy || !connectForm.url.trim() || connectForm.code.replace(/[^0-9a-z]/gi, '').length < 24" @click="doConnect">
          <Link2 :size="16" /> {{ t('il.connect') }}
        </button>
      </template>
    </BaseModal>

    <ConfirmDialog
      :model-value="!!removeTarget" :title="t('il.remove')"
      :body="t('il.removeConfirm', { lab: removeTarget?.name ?? '' })"
      :confirm-label="t('il.remove')" :cancel-label="t('common.cancel')" :loading="busy"
      @update:model-value="removeTarget = null" @confirm="doRemove"
    />
  </div>
</template>
