<script setup>
/**
 * Buyurtma formasi: sarlavha (bo'lim, sana, kim olib keladi) + namunalar ro'yxati.
 * form — reactive obyekt (joyida o'zgartiriladi). errors — serverning 422 xatolari.
 */
import { computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { Copy, EyeOff, KeyRound, Plus, Trash2 } from 'lucide-vue-next'
import AnalytePicker from './AnalytePicker.vue'
import { blankItem, fieldError, nameOf } from '../shared'
import '../i18n'
import '../../svodka/i18n' // [P29]

const props = defineProps({
  form: { type: Object, required: true },
  options: { type: Object, required: true },   // { elements, profiles, units, departments, shifts, svodka }
  errors: { type: Object, default: () => ({}) },
  showDepartment: { type: Boolean, default: true },
  fresh: { type: Boolean, default: false },     // [P29] yangi buyurtma (tahrirlash uchun yuklash emas)
})
const { t, locale } = useI18n()

const err = (k) => fieldError(props.errors, k)
const groups = computed(() => props.options.groups ?? []) // [P30]

/* [P29] Svodka: bo'lim svodka rejimida bo'lsa — sana/navbatchilik va har namunaga nuqta (faqat buyurtmachiga keladi) */
const sv = computed(() => props.options.svodka?.[props.form.department_id] ?? null)
const svPoints = computed(() => Object.fromEntries((sv.value?.sections ?? []).flatMap((s) => s.points.map((p) => [p.id, s]))))
watch(() => props.form.department_id, (v, old) => {
  if (v === old || (!old && !props.fresh)) return   // tahrirlash uchun yuklash — tegilmaydi
  const s = props.options.svodka?.[v]
  if (old) props.form.items.forEach((i) => { i.svodka_point_id = '' })
  if (old || !props.form.svodka_date) {
    props.form.svodka_date = s ? s.current.date : ''
    props.form.svodka_shift = s ? s.current.shift : ''
  }
})
/** Nuqta tanlanganda: «nima aniqlanadi» bo'sh bo'lsa — blok ko'rsatkichlari */
function onPoint(item) {
  const sec = svPoints.value[item.svodka_point_id]
  if (sec && !item.element_ids.length && !item.test_profile_id) item.element_ids = [...sec.element_ids]
}

function addItem() {
  const last = props.form.items[props.form.items.length - 1]
  // yangi qator oldingisining «nima aniqlanadi» qismini oladi — odatda bir xil namunalar ketma-ket
  props.form.items.push(blankItem(last ? {
    state_of_matter: last.state_of_matter, test_profile_id: last.test_profile_id,
    element_ids: [...last.element_ids], quantity_unit: last.quantity_unit,
  } : {}))
}

function duplicate(i) {
  const src = props.form.items[i]
  props.form.items.splice(i + 1, 0, blankItem({ ...src, customer_code: '', group_code: '', element_ids: [...src.element_ids] }))
}

function remove(i) {
  props.form.items.splice(i, 1)
}
</script>

<template>
  <div class="space-y-4">
    <!-- Sarlavha -->
    <div class="panel p-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
      <div v-if="showDepartment" class="sm:col-span-2 lg:col-span-2">
        <label class="field-label">{{ t('sreq.form.department') }} *</label>
        <select v-model="form.department_id" class="field-input" :class="err('department_id') && 'field-input-error'" data-f="department">
          <option value="">{{ t('sreq.form.chooseDepartment') }}</option>
          <option v-for="d in options.departments" :key="d.id" :value="d.id">{{ d.name }}<template v-if="d.code"> ({{ d.code }})</template></option>
        </select>
        <p v-if="err('department_id')" class="mt-1.5 text-[12.5px] text-crit">{{ err('department_id') }}</p>
      </div>
      <!-- [P30] qaysi laboratoriya guruhiga -->
      <div v-if="groups.length" class="sm:col-span-2 lg:col-span-2">
        <label class="field-label">{{ t('sreq.group.label') }}<template v-if="options.group_required"> *</template></label>
        <select
          v-model="form.laboratory_group_id" class="field-input"
          :class="[err('laboratory_group_id') && 'field-input-error', !form.laboratory_group_id && 'text-ink-faint']" data-f="group"
        >
          <option value="">{{ t('sreq.group.choose') }}</option>
          <option v-for="g in groups" :key="g.id" :value="g.id" class="text-ink">{{ nameOf(g, locale) }}</option>
        </select>
        <p v-if="err('laboratory_group_id')" class="mt-1.5 text-[12.5px] text-crit" data-group-error>{{ err('laboratory_group_id') }}</p>
        <p v-else class="mt-1 text-[12px] text-ink-faint">{{ t('sreq.group.hint') }}</p>
      </div>
      <div :class="options.shifts?.length && 'sm:col-span-2 lg:col-span-2'">
        <label class="field-label">{{ t('sreq.form.delivery') }}</label>
        <!-- [P28] sana + smena -->
        <div class="flex gap-2">
          <input v-model="form.delivery_date" type="date" class="field-input min-w-0 flex-1" data-f="delivery">
          <select
            v-if="options.shifts?.length"
            v-model="form.delivery_shift_id" class="field-input w-auto min-w-[200px]"
            :class="err('delivery_shift_id') && 'field-input-error'" :title="t('sreq.form.shift')" :aria-label="t('sreq.form.shift')"
            data-f="shift"
          >
            <option value="">{{ t('sreq.form.anyShift') }}</option>
            <option v-for="s in options.shifts" :key="s.id" :value="s.id">{{ s.name }} · {{ s.start_time }}–{{ s.end_time }}</option>
          </select>
        </div>
        <p v-if="err('delivery_shift_id')" class="mt-1.5 text-[12.5px] text-crit">{{ err('delivery_shift_id') }}</p>
      </div>
      <div>
        <label class="field-label">{{ t('sreq.form.contact') }}</label>
        <input v-model="form.contact_name" class="field-input" maxlength="191" data-f="contact">
      </div>
      <div>
        <label class="field-label">{{ t('sreq.form.phone') }}</label>
        <input v-model="form.contact_phone" class="field-input" maxlength="64" inputmode="tel">
      </div>
      <div class="sm:col-span-2" :class="showDepartment && options.shifts?.length ? 'lg:col-span-2' : 'lg:col-span-3'">
        <label class="field-label">{{ t('sreq.form.notes') }}</label>
        <input v-model="form.notes" class="field-input" maxlength="2000" :placeholder="t('sreq.form.notesHint')">
      </div>

      <!-- [P29] Svodka: qaysi kun va navbatchilik (laboratoriya ko'rmaydi) -->
      <div v-if="sv" class="sm:col-span-2 lg:col-span-4 flex flex-wrap items-end gap-x-4 gap-y-3 rounded-md border border-aqua-300/70 bg-aqua-50/50 dark:bg-aqua-800/15 px-3 py-3" data-svodka-head>
        <div class="min-w-[220px] flex-1">
          <p class="text-[13px] font-semibold text-ink">{{ t('svodka.form.title') }}</p>
          <p class="flex items-center gap-1.5 text-[12px] text-ink-muted"><EyeOff :size="13" class="shrink-0" /> {{ t('svodka.form.hint') }}</p>
        </div>
        <div class="w-[170px]">
          <label class="field-label">{{ t('svodka.form.date') }}</label>
          <input v-model="form.svodka_date" type="date" class="field-input h-9" :class="err('svodka_date') && 'field-input-error'" data-f="svodka-date">
        </div>
        <div class="w-[240px]">
          <label class="field-label">{{ t('svodka.form.shift') }}</label>
          <select v-model="form.svodka_shift" class="field-input h-9" :class="err('svodka_shift') && 'field-input-error'" data-f="svodka-shift">
            <option value="">{{ t('svodka.form.noShift') }}</option>
            <option :value="1">{{ sv.shift1_label }} · {{ sv.shift1_hours }}</option>
            <option :value="2">{{ sv.shift2_label }} · {{ sv.shift2_hours }}</option>
          </select>
        </div>
        <p v-if="err('svodka_date') || err('svodka_shift')" class="w-full text-[12.5px] text-crit">{{ err('svodka_date') || err('svodka_shift') }}</p>
      </div>
    </div>

    <!-- Namunalar -->
    <div class="panel">
      <div class="flex items-center justify-between gap-3 px-4 py-3 border-b border-line">
        <h2 class="text-[14.5px] font-semibold text-ink">
          {{ t('sreq.form.items') }} <span class="ml-1 text-ink-faint font-normal tnum">{{ form.items.length }}</span>
        </h2>
        <button type="button" class="btn-outline btn-sm" data-add-item @click="addItem">
          <Plus :size="15" /> {{ t('sreq.form.addItem') }}
        </button>
      </div>
      <p v-if="err('items')" class="px-4 pt-3 text-[12.5px] text-crit">{{ err('items') }}</p>

      <p v-if="!form.items.length" class="px-4 py-8 text-center text-[13.5px] text-ink-muted" data-no-items>{{ t('sreq.form.noItems') }}</p>

      <div v-else class="divide-y divide-line">
        <div
          v-for="(item, i) in form.items" :key="i"
          class="grid gap-3 px-4 py-3 grid-cols-[28px_1fr] items-start"
          :class="sv ? 'lg:grid-cols-[28px_190px_118px_minmax(200px,1fr)_160px_minmax(110px,.6fr)_64px]' : 'lg:grid-cols-[28px_150px_118px_minmax(220px,1fr)_170px_minmax(120px,.6fr)_64px]'"
          :data-item="i"
        >
          <span class="pt-2 text-[13px] font-semibold text-ink-faint tnum">{{ i + 1 }}</span>

          <div class="min-w-0">
            <label class="field-label lg:sr-only">{{ t('sreq.form.code') }}</label>
            <input
              v-model="item.customer_code" class="field-input h-[34px] font-mono" maxlength="64"
              :class="err(`items.${i}.customer_code`) && 'field-input-error'" :placeholder="t('sreq.form.codeHint')" data-f="code"
            >
            <p v-if="err(`items.${i}.customer_code`)" class="mt-1 text-[12px] text-crit">{{ err(`items.${i}.customer_code`) }}</p>
            <!-- [P29] svodka nuqtasi — faqat buyurtmachiga -->
            <template v-if="sv">
              <label class="field-label lg:sr-only mt-2">{{ t('svodka.form.point') }}</label>
              <select
                v-model="item.svodka_point_id" class="field-input h-[34px] mt-0 lg:mt-1.5 text-[13px]"
                :class="[err(`items.${i}.svodka_point_id`) && 'field-input-error', !item.svodka_point_id && 'text-ink-faint']"
                :title="t('svodka.form.pointHint')" data-f="point" @change="onPoint(item)"
              >
                <option value="">{{ t('svodka.form.noPoint') }}</option>
                <optgroup v-for="s in sv.sections" :key="s.id" :label="s.title">
                  <option v-for="pt in s.points" :key="pt.id" :value="pt.id" class="text-ink">{{ pt.name }}</option>
                </optgroup>
              </select>
              <p v-if="err(`items.${i}.svodka_point_id`)" class="mt-1 text-[12px] text-crit">{{ err(`items.${i}.svodka_point_id`) }}</p>
            </template>
            <!-- [P30] guruh shifri — faqat buyurtmachi va tanlangan guruhga ko'rinadi -->
            <div v-if="groups.length" class="relative mt-1.5">
              <label class="field-label lg:sr-only">{{ t('sreq.group.code') }}</label>
              <KeyRound :size="13" class="absolute left-2.5 bottom-[10px] text-ink-faint pointer-events-none" />
              <input
                v-model="item.group_code" class="field-input h-[34px] pl-7 font-mono text-[13px]" maxlength="64"
                :class="err(`items.${i}.group_code`) && 'field-input-error'"
                :placeholder="t('sreq.group.code')" :title="t('sreq.group.codeHint')" data-f="group-code"
              >
            </div>
          </div>

          <div class="col-start-2 lg:col-start-auto">
            <label class="field-label lg:sr-only">{{ t('sreq.form.state') }}</label>
            <select v-model="item.state_of_matter" class="field-input h-[34px]" data-f="state">
              <option value="solid">{{ t('sreq.form.states.solid') }}</option>
              <option value="liquid">{{ t('sreq.form.states.liquid') }}</option>
            </select>
          </div>

          <div class="col-start-2 lg:col-start-auto min-w-0">
            <label class="field-label lg:sr-only">{{ t('sreq.form.what') }}</label>
            <AnalytePicker
              v-model:profile-id="item.test_profile_id" v-model:element-ids="item.element_ids"
              :elements="options.elements" :profiles="options.profiles" :error="err(`items.${i}.element_ids`)"
            />
          </div>

          <div class="col-start-2 lg:col-start-auto flex gap-1.5">
            <div class="flex-1 min-w-0">
              <label class="field-label lg:sr-only">{{ t('sreq.form.quantity') }}</label>
              <input v-model="item.quantity" inputmode="decimal" class="field-input h-[34px] tnum" :placeholder="t('sreq.form.quantity')" data-f="qty">
            </div>
            <div class="w-[68px]">
              <label class="field-label lg:sr-only">{{ t('sreq.form.unit') }}</label>
              <select v-model="item.quantity_unit" class="field-input h-[34px] px-2">
                <option v-for="u in options.units" :key="u" :value="u">{{ u }}</option>
              </select>
            </div>
          </div>

          <div class="col-start-2 lg:col-start-auto min-w-0">
            <label class="field-label lg:sr-only">{{ t('sreq.form.itemNotes') }}</label>
            <input v-model="item.notes" class="field-input h-[34px]" maxlength="500" :placeholder="t('sreq.form.itemNotes')">
          </div>

          <div class="col-start-2 lg:col-start-auto flex gap-0.5 lg:pt-0.5">
            <button type="button" class="btn-ghost btn-icon h-8 w-8" :title="t('sreq.form.duplicate')" :aria-label="t('sreq.form.duplicate')" data-dup @click="duplicate(i)">
              <Copy :size="15" />
            </button>
            <button type="button" class="btn-ghost btn-icon h-8 w-8 hover:text-crit" :title="t('sreq.form.remove')" :aria-label="t('sreq.form.remove')" data-remove @click="remove(i)">
              <Trash2 :size="15" />
            </button>
          </div>
        </div>
      </div>

      <div v-if="form.items.length" class="px-4 py-2.5 border-t border-line">
        <button type="button" class="btn-ghost btn-sm" @click="addItem"><Plus :size="15" /> {{ t('sreq.form.addItem') }}</button>
      </div>
    </div>
  </div>
</template>
