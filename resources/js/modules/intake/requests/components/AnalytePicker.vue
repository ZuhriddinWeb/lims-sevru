<script setup>
/**
 * Nima aniqlanadi: profil (ixtiyoriy) + ko'rsatkichlar (chiplar).
 * Profil tanlansa — uning ko'rsatkichlari qo'yiladi, keyin qo'shish/olib tashlash mumkin.
 */
import { computed, nextTick, onBeforeUnmount, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { Plus, X } from 'lucide-vue-next'
import { nameOf } from '../shared'
import '../i18n'

const props = defineProps({
  profileId: { type: [Number, String, null], default: null },
  elementIds: { type: Array, default: () => [] },
  elements: { type: Array, default: () => [] },
  profiles: { type: Array, default: () => [] },
  disabled: Boolean,
  error: { type: String, default: '' },
})
const emit = defineEmits(['update:profileId', 'update:elementIds'])
const { t, locale } = useI18n()

const byId = computed(() => new Map(props.elements.map((e) => [Number(e.id), e])))
const selected = computed(() => props.elementIds.map((id) => byId.value.get(Number(id))).filter(Boolean))

const open = ref(false)
const query = ref('')
const root = ref(null)
const input = ref(null)

const candidates = computed(() => {
  const q = query.value.trim().toLowerCase()
  const chosen = new Set(props.elementIds.map(Number))
  return props.elements
    .filter((e) => !chosen.has(Number(e.id)))
    .filter((e) => !q || [e.symbol, e.name_uz, e.name_ru, e.name_en].some((s) => (s || '').toLowerCase().includes(q)))
    .slice(0, 40)
})

function setProfile(value) {
  const id = value ? Number(value) : null
  emit('update:profileId', id)
  const p = props.profiles.find((x) => Number(x.id) === id)
  if (p) emit('update:elementIds', [...p.element_ids])
}

function add(e) {
  emit('update:elementIds', [...props.elementIds, Number(e.id)])
  query.value = ''
  nextTick(() => input.value?.focus())
}

function remove(id) {
  emit('update:elementIds', props.elementIds.filter((x) => Number(x) !== Number(id)))
}

function toggle() {
  if (props.disabled) return
  open.value = !open.value
  if (open.value) nextTick(() => input.value?.focus())
}

function onDoc(e) {
  if (open.value && root.value && !root.value.contains(e.target)) open.value = false
}
document.addEventListener('mousedown', onDoc)
onBeforeUnmount(() => document.removeEventListener('mousedown', onDoc))

function onKey(e) {
  if (e.key === 'Escape') open.value = false
  if (e.key === 'Enter' && candidates.value.length) {
    e.preventDefault()
    add(candidates.value[0])
  }
}
</script>

<template>
  <div ref="root" class="space-y-1.5">
    <select
      v-if="profiles.length"
      :value="profileId ?? ''" class="field-input h-8 text-[13px]" :disabled="disabled" data-profile
      @change="setProfile($event.target.value)"
    >
      <option value="">{{ t('sreq.form.noProfile') }}</option>
      <option v-for="p in profiles" :key="p.id" :value="p.id">{{ nameOf(p, locale) }}</option>
    </select>

    <div class="relative">
      <div
        class="flex flex-wrap items-center gap-1 min-h-[34px] rounded-md border px-1.5 py-1 bg-surface"
        :class="error ? 'border-crit' : 'border-line'"
      >
        <span
          v-for="e in selected" :key="e.id"
          class="inline-flex items-center gap-1 rounded bg-assay-50 dark:bg-assay-800/30 px-1.5 py-0.5 text-[12px] text-ink"
          :title="nameOf(e, locale)" :data-chip="e.symbol"
        >
          <span class="font-mono font-semibold">{{ e.symbol }}</span>
          <button v-if="!disabled" type="button" class="text-ink-faint hover:text-crit" :aria-label="t('sreq.form.remove')" @click="remove(e.id)">
            <X :size="12" />
          </button>
        </span>
        <span v-if="!selected.length && disabled" class="px-1 text-[12.5px] text-ink-faint">{{ t('sreq.form.noElements') }}</span>
        <button
          v-if="!disabled" type="button" data-add-element
          class="inline-flex items-center gap-1 rounded px-1.5 py-0.5 text-[12px] text-assay-700 dark:text-assay-200 hover:bg-surface-sunken"
          @click="toggle"
        >
          <Plus :size="13" /> {{ selected.length ? '' : t('sreq.form.addElement') }}
        </button>
      </div>

      <div
        v-if="open"
        class="absolute z-30 mt-1 w-full min-w-[240px] rounded-md border border-line bg-surface shadow-pop"
      >
        <input
          ref="input" v-model="query" class="field-input h-8 rounded-b-none border-0 border-b text-[13px]"
          :placeholder="t('sreq.form.searchElement')" data-element-search @keydown="onKey"
        >
        <div class="max-h-56 overflow-y-auto py-1">
          <button
            v-for="e in candidates" :key="e.id" type="button" :data-option="e.symbol"
            class="w-full flex items-center gap-2 px-3 py-1.5 text-left text-[13px] hover:bg-surface-sunken"
            @click="add(e)"
          >
            <span class="font-mono font-semibold w-14 shrink-0 text-ink">{{ e.symbol }}</span>
            <span class="flex-1 truncate text-ink-muted">{{ nameOf(e, locale) }}</span>
            <span v-if="e.default_unit" class="text-[11.5px] text-ink-faint">{{ e.default_unit }}</span>
          </button>
          <p v-if="!candidates.length" class="px-3 py-2 text-[12.5px] text-ink-faint">—</p>
        </div>
      </div>
    </div>
    <p v-if="error" class="text-[12px] text-crit">{{ error }}</p>
  </div>
</template>
