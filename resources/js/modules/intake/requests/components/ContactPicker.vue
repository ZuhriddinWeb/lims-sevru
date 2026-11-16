<script setup>
/**
 * [Paket 34] «Kim olib keladi» + «Telefon» — bo'linma xodimlari ma'lumotnomasidan kombobox.
 *   - yozilganda mos xodimlar chiqadi (lotin/kirill, ism yoki familiya boshi, telefon raqami bo'yicha);
 *   - tanlansa telefon avtomatik to'ladi (o'zgartirish mumkin — yangi raqam yuborilganda ma'lumotnomada yangilanadi);
 *   - qo'lda yozilsa: ortiqcha probel/harf kattaligi o'zi tuzatiladi; ma'lumotnomadagiga o'xshasa
 *     («Rahimov A.» ↔ «Rahimov Akmal», kirill/lotin, 1–2 harf) — «Bu … emasmi?» deb so'raladi;
 *   - yangi xodim buyurtma yuborilganda (yoki qabulda) ma'lumotnomaga o'zi qo'shiladi.
 * Ildiz «contents» — ikki maydon ota-ona grid/flex ichida alohida joylashadi.
 */
import { computed, nextTick, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { Phone, UserPlus, UserRound, X } from 'lucide-vue-next'
import api from '@/lib/api'
import { useUiStore } from '@/stores/ui'
import { checkContact, forgetContacts, loadContacts, matches, phoneInfo, searchTokens } from '../contacts'
import '../i18nP34'

const props = defineProps({
  departmentId: { type: [Number, String], default: null },
  name: { type: String, default: '' },
  phone: { type: String, default: '' },
  nameLabel: { type: String, required: true },
  phoneLabel: { type: String, required: true },
  nameClass: { type: [String, Array, Object], default: '' },
  phoneClass: { type: [String, Array, Object], default: '' },
  placeholder: { type: String, default: '' },
  dataName: { type: String, default: 'contact' },      // data-* nomlari (eski testlar/skriptlar uchun)
  dataPhone: { type: String, default: 'contact-phone' },
})
const emit = defineEmits(['update:name', 'update:phone', 'update:pending'])
const { t } = useI18n()
const ui = useUiStore()

const list = ref([])
const canWrite = ref(false)
const open = ref(false)
const active = ref(0)
const chosen = ref(null)     // ma'lumotnomadagi xodim (tanlangan yoki aniq mos kelgan)
const prompt = ref(null)     // { type: 'exact', contact } | { type: 'similar', items }
const isNew = ref(false)
const inputEl = ref(null)
let checkSeq = 0

const keyOf = (s) => searchTokens(s).join(' ')
const dept = computed(() => Number(props.departmentId) || null)

async function reload(force = false) {
  const r = await loadContacts(dept.value, force)
  list.value = r.data
  canWrite.value = r.can_write
  syncChosen()
}
function syncChosen() {
  const k = keyOf(props.name)
  chosen.value = k ? (list.value.find((c) => keyOf(c.name) === k) ?? null) : null
}
watch(dept, () => {
  prompt.value = null
  isNew.value = false
  reload()
}, { immediate: true })
watch(prompt, (v) => emit('update:pending', !!v))
// tashqaridan o'zgarsa (qoralama yuklandi, shablon qo'llandi) — ro'yxatdagi xodimni topamiz
watch(() => props.name, () => {
  if (!chosen.value || keyOf(chosen.value.name) !== keyOf(props.name)) syncChosen()
})

/* ---------- ro'yxat ---------- */
const suggestions = computed(() => list.value.filter((c) => matches(c, props.name)).slice(0, 8))
const showNewRow = computed(() => {
  const k = keyOf(props.name)
  return !!k && !list.value.some((c) => keyOf(c.name) === k)
})
const rowsCount = computed(() => suggestions.value.length + (showNewRow.value ? 1 : 0))

function onInput(e) {
  emit('update:name', e.target.value)
  chosen.value = null
  prompt.value = null
  isNew.value = false
  open.value = true
  active.value = 0
}

function choose(c) {
  emit('update:name', c.name)
  if (c.phone) emit('update:phone', c.phone)
  chosen.value = c
  prompt.value = null
  isNew.value = false
  open.value = false
}

function onKey(e) {
  if (!open.value && (e.key === 'ArrowDown' || e.key === 'ArrowUp')) {
    open.value = true
    return
  }
  if (!open.value) return
  if (e.key === 'ArrowDown') {
    e.preventDefault()
    active.value = (active.value + 1) % Math.max(1, rowsCount.value)
  } else if (e.key === 'ArrowUp') {
    e.preventDefault()
    active.value = (active.value - 1 + rowsCount.value) % Math.max(1, rowsCount.value)
  } else if (e.key === 'Enter') {
    if (!rowsCount.value) return
    e.preventDefault()
    if (active.value < suggestions.value.length) choose(suggestions.value[active.value])
    else {
      open.value = false
      check()
    }
  } else if (e.key === 'Escape') {
    open.value = false
  }
}

function onBlur() {
  // ro'yxatdagi tugma bosilishi ulgursin
  setTimeout(() => {
    open.value = false
    // ro'yxatdan tanlanmagan (yoki yozilishi ro'yxatdagidan farq qiladi) — server tekshiradi
    if (!prompt.value && (props.name || '').trim() && (!chosen.value || chosen.value.name !== props.name)) check()
  }, 160)
}

/* ---------- tekshirish (server): tozalash, aniq mos, o'xshashlar ---------- */
async function check() {
  if (!dept.value || !(props.name || '').trim()) return
  const seq = ++checkSeq
  const sent = props.name
  let res
  try {
    res = await checkContact(dept.value, sent, props.phone)
  } catch {
    return
  }
  if (seq !== checkSeq || sent !== props.name) return // bu orada o'zgargan
  if (res.name && res.name !== props.name) emit('update:name', res.name)
  if (res.exact && res.same_format) {
    acceptPrompt(res.exact) // faqat probel/harf farqi — so'ramasdan
    return
  }
  if (res.exact) {
    prompt.value = { type: 'exact', contact: res.exact, typed: res.name }
  } else if (res.similar?.length) {
    prompt.value = { type: 'similar', items: res.similar, typed: res.name }
  } else {
    isNew.value = true
  }
}

function acceptPrompt(c) {
  // telefon bo'sh bo'lsa — xodimnikini qo'yamiz; yozilgan bo'lsa — saqlanadi (yangi raqam bo'lishi mumkin)
  choose(props.phone ? { ...c, phone: null } : c)
  chosen.value = c
}
function rejectPrompt() {
  const p = prompt.value
  prompt.value = null
  isNew.value = p?.type === 'similar'
  nextTick(() => inputEl.value?.focus())
}

/* ---------- ma'lumotnomadan o'chirish (noto'g'ri yozuv) ---------- */
async function remove(c) {
  if (!window.confirm(t('sreq.p34.removeConfirm', { name: c.name }))) return
  try {
    await api.delete(`/portal/contacts/${c.id}`)
    list.value = list.value.filter((x) => x.id !== c.id)
    forgetContacts(dept.value)
    if (chosen.value?.id === c.id) chosen.value = null
    ui.success(t('sreq.p34.removed'))
  } catch (e) {
    ui.error(e?.response?.data?.message || t('sreq.p34.loadError'))
  }
}

/* ---------- telefon ---------- */
const ph = computed(() => phoneInfo(props.phone))
function onPhoneBlur() {
  if (ph.value.value !== (props.phone ?? '')) emit('update:phone', ph.value.value)
}
const phoneChanged = computed(() => {
  const old = chosen.value?.phone
  return !!old && !!ph.value.digits && phoneInfo(old).digits !== ph.value.digits
})
const nameAttrs = computed(() => ({ [`data-${props.dataName}`]: '' }))
const phoneAttrs = computed(() => ({ [`data-${props.dataPhone}`]: '' }))

defineExpose({ reload: () => reload(true) })
</script>

<template>
  <div class="contents">
    <!-- F.I.Sh. — kombobox -->
    <div class="relative" :class="nameClass">
      <label class="field-label">{{ nameLabel }}</label>
      <div class="relative">
        <UserRound :size="15" class="pointer-events-none absolute left-2.5 top-1/2 -translate-y-1/2 text-ink-faint" />
        <input
          ref="inputEl" :value="name" class="field-input pl-8" maxlength="191" autocomplete="off"
          :placeholder="placeholder || t('sreq.p34.namePlaceholder')" role="combobox" :aria-expanded="open"
          :class="prompt && 'border-warn'" v-bind="nameAttrs"
          @input="onInput" @focus="open = true; active = 0" @keydown="onKey" @blur="onBlur"
        >
      </div>

      <div
        v-if="open && rowsCount"
        class="absolute left-0 right-0 z-30 mt-1 max-h-72 overflow-auto rounded-md border border-line bg-surface shadow-pop py-1"
        role="listbox" data-contact-list
      >
        <div
          v-for="(c, i) in suggestions" :key="c.id" role="option" :aria-selected="i === active"
          class="group flex items-center gap-2 px-3 py-1.5 cursor-pointer text-[13.5px]"
          :class="i === active ? 'bg-assay-50 dark:bg-assay-800/30' : 'hover:bg-surface-sunken'"
          :data-contact-option="c.id" @mousedown.prevent="choose(c)" @mouseenter="active = i"
        >
          <span class="flex-1 min-w-0 truncate text-ink">{{ c.name }}</span>
          <span v-if="c.phone" class="shrink-0 font-mono text-[12px] text-ink-muted">{{ c.phone }}</span>
          <button
            v-if="canWrite" type="button" class="shrink-0 rounded p-0.5 text-ink-faint opacity-0 group-hover:opacity-100 hover:text-crit"
            :title="t('sreq.p34.remove')" :aria-label="t('sreq.p34.remove')" @mousedown.prevent.stop="remove(c)"
          >
            <X :size="14" />
          </button>
        </div>
        <div
          v-if="showNewRow" role="option" :aria-selected="active === suggestions.length"
          class="flex items-center gap-2 px-3 py-1.5 cursor-pointer text-[13px] text-assay-700 dark:text-assay-200 border-t border-line"
          :class="active === suggestions.length ? 'bg-assay-50 dark:bg-assay-800/30' : 'hover:bg-surface-sunken'"
          data-contact-new @mousedown.prevent="open = false; check()"
        >
          <UserPlus :size="14" /> {{ t('sreq.p34.newRow', { name: (name || '').trim() }) }}
        </div>
      </div>

      <!-- o'xshash yozuv: so'rov -->
      <div v-if="prompt" class="mt-1.5 rounded-md border border-warn/50 bg-warn-light/30 dark:bg-warn-dark/15 px-2.5 py-2 text-[12.5px]" data-contact-prompt>
        <template v-if="prompt.type === 'exact'">
          <p class="text-ink">{{ t('sreq.p34.exactAsk', { name: prompt.contact.name }) }}</p>
          <div class="mt-1.5 flex flex-wrap gap-1.5">
            <button type="button" class="btn-outline btn-sm" data-prompt-accept @click="acceptPrompt(prompt.contact)">{{ t('sreq.p34.yesFix') }}</button>
            <button type="button" class="btn-ghost btn-sm" data-prompt-reject @click="rejectPrompt">{{ t('sreq.p34.keepMine') }}</button>
          </div>
        </template>
        <template v-else>
          <p class="text-ink">{{ t('sreq.p34.similarAsk', { name: prompt.typed }) }}</p>
          <div class="mt-1.5 flex flex-wrap gap-1.5">
            <button
              v-for="c in prompt.items" :key="c.id" type="button" class="btn-outline btn-sm" :data-prompt-accept="c.id"
              @click="acceptPrompt(c)"
            >
              {{ c.name }}<span v-if="c.phone" class="ml-1 font-mono text-[11.5px] text-ink-muted">{{ c.phone }}</span>
            </button>
            <button type="button" class="btn-ghost btn-sm" data-prompt-reject @click="rejectPrompt">{{ t('sreq.p34.newPerson') }}</button>
          </div>
        </template>
      </div>
      <p v-else-if="isNew" class="mt-1 text-[12px] text-ink-faint" data-contact-newnote>{{ t('sreq.p34.newNote') }}</p>
    </div>

    <!-- Telefon -->
    <div :class="phoneClass">
      <label class="field-label">{{ phoneLabel }}</label>
      <div class="relative">
        <Phone :size="14" class="pointer-events-none absolute left-2.5 top-1/2 -translate-y-1/2 text-ink-faint" />
        <input
          :value="phone" class="field-input pl-8" maxlength="64" inputmode="tel" autocomplete="off"
          :placeholder="t('sreq.p34.phonePlaceholder')" :class="ph.kind === 'other' && 'border-warn'" v-bind="phoneAttrs"
          @input="emit('update:phone', $event.target.value)" @blur="onPhoneBlur"
        >
      </div>
      <p v-if="ph.kind === 'other'" class="mt-1 text-[12px] text-warn-dark dark:text-warn-light" data-phone-warn>{{ t('sreq.p34.phoneOdd') }}</p>
      <p v-else-if="phoneChanged" class="mt-1 text-[12px] text-ink-faint" data-phone-changed>{{ t('sreq.p34.phoneChanged', { old: chosen.phone }) }}</p>
      <p v-else-if="ph.kind === 'internal'" class="mt-1 text-[12px] text-ink-faint">{{ t('sreq.p34.internal') }}</p>
    </div>
  </div>
</template>
