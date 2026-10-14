/**
 * Probani ro'yxatga olish formasiga «Buyurtmachi va miqdor» bo'limini modulning O'ZI qo'shadi:
 *   - forma: Buyurtmachi bo'lim, Buyurtmachi shifri, Qabul qilingan miqdor, Birlik;
 *   - «Buyurtmachi guruh» endi majburiy emas (ichki guruh YOKI zavod bo'limi);
 *   - probalar jadvalida «Buyurtmachi bo'lim» ustuni va filtri.
 *
 * resources/js/resources/lab.js va stores/catalogs.js ga qo'lda tegish SHART EMAS.
 * Agar bu maydonlar lab.js ga qo'lda qo'shilgan bo'lsa — hech narsa takrorlanmaydi.
 * Chaqiriladi: routes.js (router ishga tushganda, sahifalar ochilishidan oldin).
 */
import { reactive } from 'vue'
import api from '@/lib/api'
import * as lab from '@/resources/lab'
import './i18n'

const UNITS = ['g', 'kg', 'mg', 'ml', 'l']

/* ---------- Bo'limlar ro'yxati: forma birinchi marta ochilganda yuklanadi ---------- */
const state = reactive({ options: [], loaded: false })
let loading = false
let lastTry = 0

function ensureDepartments() {
  if (state.loaded || loading || Date.now() - lastTry < 10000) return
  loading = true
  lastTry = Date.now()
  api.get('/customer-departments/options')
    .then(({ data }) => {
      const list = (Array.isArray(data) ? data : []).map((d) => ({
        value: Number(d.id),
        label: d.code ? `${d.name} (${d.code})` : d.name,
      }))
      // Joyida almashtiramiz — ro'yxatga oldindan havola olgan komponentlar ham yangilansin
      state.options.splice(0, state.options.length, ...list)
      state.loaded = true
    })
    .catch(() => { /* login'dan oldin yoki ruxsat yo'q — keyinroq qayta urinadi */ })
    .finally(() => { loading = false })
}

/** Bo'limlar o'zgarganda (katalogda qo'shish/tahrirlash) — ro'yxatni qayta yuklash */
export function refreshDepartmentOptions() {
  state.loaded = false
  lastTry = 0
  ensureDepartments()
}

// «options» — getter: FormField uni o'qiganda ro'yxat yuklanadi va kelganda o'zi yangilanadi
function withDepartmentOptions(field) {
  if (Object.getOwnPropertyDescriptor(field, 'options')?.get) return field
  field.type = 'select'
  delete field.source // catalogs.js dagi manbaga bog'liq bo'lmasin
  Object.defineProperty(field, 'options', {
    enumerable: true,
    configurable: true,
    get() {
      ensureDepartments()
      return state.options
    },
  })
  return field
}

function departmentField(extra = {}) {
  return withDepartmentOptions({ key: 'customer_department_id', type: 'select', labelKey: 'intake.department', ...extra })
}

const keyOf = (x) => x?.key

/**
 * @param  {object} resource  sampleResource (sinov uchun boshqa obyekt berish mumkin)
 * @return {boolean} forma o'zgartirildimi
 */
export function installSampleIntakeForm(resource = lab.sampleResource) {
  const sections = resource?.form?.sections
  if (!Array.isArray(sections)) return false

  const fields = sections.flatMap((s) => s.fields ?? [])
  let changed = false

  // «Buyurtmachi guruh» — endi majburiy emas
  const group = fields.find((f) => keyOf(f) === 'customer_group_id')
  if (group?.required) {
    group.required = false
    changed = true
  }

  // lab.js ga qo'lda qo'shilgan bo'lim maydoni/filtri bo'lsa — ro'yxatni shu modul beradi
  // (catalogs.js ga «customerDepartments» manbasini qo'shish shart emas)
  fields.filter((f) => keyOf(f) === 'customer_department_id').forEach(withDepartmentOptions)
  ;(resource.filters ?? []).filter((f) => keyOf(f) === 'customer_department_id').forEach(withDepartmentOptions)

  // Forma bo'limi
  if (!fields.some((f) => keyOf(f) === 'customer_department_id')) {
    sections.push({
      titleKey: 'intake.section',
      fields: [
        departmentField({ hintKey: 'intake.departmentHint' }),
        { key: 'customer_code', type: 'text', labelKey: 'intake.customerCode', hintKey: 'intake.customerCodeHint' },
        { key: 'received_quantity', type: 'number', labelKey: 'intake.quantity', hintKey: 'intake.quantityHint', step: 'any', min: 0 },
        { key: 'quantity_unit', type: 'select', labelKey: 'intake.unit', default: 'g',
          options: UNITS.map((u) => ({ value: u, labelKey: `intake.units.${u}` })) },
      ],
    })
    changed = true
  }

  // Jadval ustuni — «Buyurtmachi guruh» dan keyin
  if (Array.isArray(resource.columns) && !resource.columns.some((c) => keyOf(c) === 'customer_department')) {
    const at = resource.columns.findIndex((c) => keyOf(c) === 'customer_group')
    resource.columns.splice(at >= 0 ? at + 1 : resource.columns.length, 0, {
      key: 'customer_department', labelKey: 'intake.department', nowrap: false,
      format: (r) => (r.customer_department ? r.customer_department.name + (r.customer_code ? ` · ${r.customer_code}` : '') : ''),
    })
    changed = true
  }

  // Filtrlar: bo'lim va buyurtmachi shifri
  if (Array.isArray(resource.filters) && !resource.filters.some((f) => keyOf(f) === 'customer_department_id')) {
    const at = resource.filters.findIndex((f) => keyOf(f) === 'customer_group_id')
    resource.filters.splice(at >= 0 ? at + 1 : resource.filters.length, 0, departmentField({ nullLabelKey: 'common.all' }))
    changed = true
  }
  if (Array.isArray(resource.filters) && !resource.filters.some((f) => keyOf(f) === 'customer_code')) {
    const at = resource.filters.findIndex((f) => keyOf(f) === 'customer_department_id')
    resource.filters.splice(at >= 0 ? at + 1 : resource.filters.length, 0,
      { key: 'customer_code', type: 'text', labelKey: 'intake.customerCode' })
    changed = true
  }

  return changed
}
