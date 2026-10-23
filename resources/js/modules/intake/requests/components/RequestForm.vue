<script setup>
/**
 * Buyurtma formasi: sarlavha (bo'lim, sana, kim olib keladi) + namunalar ro'yxati.
 * form — reactive obyekt (joyida o'zgartiriladi). errors — serverning 422 xatolari.
 */
import { useI18n } from 'vue-i18n'
import { Copy, Plus, Trash2 } from 'lucide-vue-next'
import AnalytePicker from './AnalytePicker.vue'
import { blankItem, fieldError } from '../shared'
import '../i18n'

const props = defineProps({
  form: { type: Object, required: true },
  options: { type: Object, required: true },   // { elements, profiles, units, departments, shifts }
  errors: { type: Object, default: () => ({}) },
  showDepartment: { type: Boolean, default: true },
})
const { t } = useI18n()

const err = (k) => fieldError(props.errors, k)

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
  props.form.items.splice(i + 1, 0, blankItem({ ...src, customer_code: '', element_ids: [...src.element_ids] }))
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
          class="grid gap-3 px-4 py-3 grid-cols-[28px_1fr] lg:grid-cols-[28px_150px_118px_minmax(220px,1fr)_170px_minmax(120px,.6fr)_64px] items-start"
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
