<script setup>
/**
 * [Paket 33] «Yangi buyurtma» tepasidagi shablon tugmalari (faqat buyurtmachi).
 *   - tugma bosilsa forma shablon bo'yicha to'ladi; formada ma'lumot bo'lsa — «Almashtirish» yoki «Oxiriga qo'shish»;
 *   - «Shablon sifatida saqlash» — joriy formadan yangi shablon (sanalarsiz);
 *   - ?template=ID bilan ochilsa — shablon darhol qo'llanadi («Shablonlarim» → «Ishlatish»).
 * form — RequestEditor'ning reactive formasi (joyida o'zgartiriladi).
 */
import { computed, nextTick, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { LayoutTemplate, Save, Settings2 } from 'lucide-vue-next'
import api from '@/lib/api'
import BaseModal from '@/components/ui/BaseModal.vue'
import { useUiStore } from '@/stores/ui'
import { apiErrorMessage, blankItem, fieldError } from '../shared'
import { templateFromForm } from '../templates'
import '../i18n'
import '../i18nP33'

const props = defineProps({
  form: { type: Object, required: true },
  options: { type: Object, required: true },
})
const { t } = useI18n()
const ui = useUiStore()
const route = useRoute()
const router = useRouter()

const list = ref([])
const loaded = ref(false)
const choice = ref(null)        // qo'llash so'rovi: shablon
const saveOpen = ref(false)
const saveName = ref('')
const saveErrors = ref({})
const busy = ref(false)
let autoApplied = false

const dept = computed(() => Number(props.form.department_id) || null)
const shown = computed(() => list.value.filter((x) => !dept.value || x.department_id === dept.value))

async function load() {
  try {
    const { data } = await api.get('/portal/request-templates')
    list.value = data.data ?? []
  } catch {
    list.value = []
  } finally {
    loaded.value = true
  }
  // «Shablonlarim» → «Ishlatish»: /portal/requests/new?template=ID
  const want = Number(route.query.template)
  if (want && !autoApplied) {
    autoApplied = true
    const tpl = list.value.find((x) => x.id === want)
    if (tpl) {
      if (dept.value !== tpl.department_id) {
        props.form.department_id = tpl.department_id
        await nextTick() // bo'lim kuzatuvchisi (svodka sanasi) avval ishlasin — keyin namunalar
      }
      apply(tpl, 'replace')
    }
    router.replace({ query: { ...route.query, template: undefined } })
  }
}
load()

/* ---------- qo'llash ---------- */

function hasData() {
  const f = props.form
  if (f.items.length > 1) return true
  return f.items.some((i) => (i.customer_code || '').trim() || i.element_ids?.length || i.test_profile_id
    || (i.quantity !== '' && i.quantity !== null) || (i.notes || '').trim() || i.svodka_point_id || (i.group_code || '').trim())
}

function pick(tpl) {
  if (hasData()) choice.value = tpl
  else apply(tpl, 'replace')
}

const ids = (arr) => new Set((arr ?? []).map((x) => Number(x.id)))

function apply(tpl, how) {
  const p = tpl.payload ?? {}
  const f = props.form
  const elements = ids(props.options.elements)
  const profiles = ids(props.options.profiles)
  const groups = ids(props.options.groups)
  const shifts = ids(props.options.shifts)
  const sv = props.options.svodka?.[f.department_id] ?? props.options.svodka?.[tpl.department_id]
  const points = new Set((sv?.sections ?? []).flatMap((s) => s.points.map((x) => Number(x.id))))
  const units = props.options.units ?? ['g']

  // sarlavha: almashtirishda — shablon qiymati (bo'lsa); qo'shishda — faqat bo'sh maydonlar to'ldiriladi
  const head = (key, value, ok = true) => {
    if (value === null || value === undefined || value === '' || !ok) return
    if (how === 'replace' || !f[key]) f[key] = value
  }
  head('laboratory_group_id', p.laboratory_group_id, !groups.size || groups.has(Number(p.laboratory_group_id)))
  head('delivery_shift_id', p.delivery_shift_id, shifts.has(Number(p.delivery_shift_id)))
  head('contact_name', p.contact_name)
  head('contact_phone', p.contact_phone)
  head('notes', p.notes)

  const items = (p.items ?? []).map((i) => blankItem({
    customer_code: i.customer_code ?? '',
    state_of_matter: i.state_of_matter || 'solid',
    test_profile_id: i.test_profile_id && profiles.has(Number(i.test_profile_id)) ? i.test_profile_id : null,
    element_ids: (i.element_ids ?? []).map(Number).filter((id) => elements.has(id)),
    quantity: i.quantity ?? '',
    quantity_unit: units.includes(i.quantity_unit) ? i.quantity_unit : (units[0] || 'g'),
    notes: i.notes ?? '',
    svodka_point_id: i.svodka_point_id && points.has(Number(i.svodka_point_id)) ? i.svodka_point_id : '',
    group_code: i.group_code ?? '',
  }))
  if (how === 'replace') f.items.splice(0, f.items.length, ...items)
  else {
    // bitta bo'sh qator bo'lsa — uning o'rniga
    if (!hasData()) f.items.splice(0, f.items.length)
    f.items.push(...items)
  }
  choice.value = null
  ui.success(t('sreq.tpl.applied', { name: tpl.name }))
}

/* ---------- shablon sifatida saqlash ---------- */

function openSave() {
  saveName.value = ''
  saveErrors.value = {}
  saveOpen.value = true
}

async function saveTemplate() {
  busy.value = true
  saveErrors.value = {}
  try {
    const { data } = await api.post('/portal/request-templates', {
      department_id: props.form.department_id || null,
      name: saveName.value.trim(),
      payload: templateFromForm(props.form),
    })
    list.value.push(data)
    saveOpen.value = false
    ui.success(t('sreq.tpl.saved'))
  } catch (e) {
    saveErrors.value = e?.response?.data?.errors ?? {}
    if (!Object.keys(saveErrors.value).length) ui.error(apiErrorMessage(e, t('sreq.tpl.loadError')))
  } finally {
    busy.value = false
  }
}

const saveError = computed(() => fieldError(saveErrors.value, 'name') || fieldError(saveErrors.value, 'payload.items')
  || fieldError(saveErrors.value, 'department_id'))

watch(() => props.form.department_id, () => { choice.value = null })
</script>

<template>
  <div class="panel mb-4 px-4 py-3 flex flex-wrap items-center gap-2" data-template-bar>
    <span class="inline-flex items-center gap-1.5 text-[13px] font-medium text-ink-muted mr-1">
      <LayoutTemplate :size="15" class="text-assay-500" /> {{ t('sreq.tpl.bar') }}
    </span>

    <template v-if="shown.length">
      <button
        v-for="tpl in shown" :key="tpl.id" type="button"
        class="inline-flex items-center gap-1.5 rounded-md border border-assay-300/70 bg-assay-50/60 dark:bg-assay-800/20 px-3 py-1.5 text-[13px] font-medium text-ink hover:border-assay-500 hover:bg-assay-50 dark:hover:bg-assay-800/40 transition-colors"
        :title="t('sreq.tpl.items', { n: tpl.items_count })" :data-template="tpl.id" @click="pick(tpl)"
      >
        {{ tpl.name }} <span class="text-ink-faint tnum text-[12px]">{{ tpl.items_count }}</span>
      </button>
    </template>
    <span v-else-if="loaded" class="text-[12.5px] text-ink-faint">{{ t('sreq.tpl.barEmpty') }}</span>

    <span class="flex-1" />
    <button type="button" class="btn-ghost btn-sm" data-save-template @click="openSave">
      <Save :size="15" /> {{ t('sreq.tpl.saveAs') }}
    </button>
    <router-link :to="{ name: 'portal-templates' }" class="btn-ghost btn-sm" data-manage-templates>
      <Settings2 :size="15" /> {{ t('sreq.tpl.manage') }}
    </router-link>

    <!-- Qo'llash: formada ma'lumot bor -->
    <BaseModal :model-value="!!choice" :title="choice ? t('sreq.tpl.applyTitle', { name: choice.name }) : ''" size="sm" @update:model-value="!$event && (choice = null)">
      <p class="text-[13.5px] leading-relaxed text-ink-muted">{{ t('sreq.tpl.applyBody') }}</p>
      <template #footer>
        <button class="btn-ghost" @click="choice = null">{{ t('common.cancel') }}</button>
        <button class="btn-outline" data-apply-append @click="apply(choice, 'append')">{{ t('sreq.tpl.append') }}</button>
        <button class="btn-primary" data-apply-replace @click="apply(choice, 'replace')">{{ t('sreq.tpl.replace') }}</button>
      </template>
    </BaseModal>

    <!-- Shablon sifatida saqlash -->
    <BaseModal v-model="saveOpen" :title="t('sreq.tpl.saveAsTitle')" size="sm">
      <p class="text-[13px] leading-relaxed text-ink-muted mb-3">{{ t('sreq.tpl.saveAsBody') }}</p>
      <label class="field-label">{{ t('sreq.tpl.name') }} *</label>
      <input
        v-model="saveName" class="field-input" maxlength="120" :placeholder="t('sreq.tpl.namePlaceholder')"
        :class="saveError && 'field-input-error'" data-template-name @keyup.enter="saveName.trim() && saveTemplate()"
      >
      <p v-if="saveError" class="mt-1.5 text-[12.5px] text-crit">{{ saveError }}</p>
      <template #footer>
        <button class="btn-ghost" @click="saveOpen = false">{{ t('common.cancel') }}</button>
        <button class="btn-primary" :disabled="busy || !saveName.trim()" data-template-save @click="saveTemplate">{{ t('sreq.tpl.save') }}</button>
      </template>
    </BaseModal>
  </div>
</template>
