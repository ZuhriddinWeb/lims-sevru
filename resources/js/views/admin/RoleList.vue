<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { Check, KeyRound, Pencil, Plus, Trash2 } from 'lucide-vue-next'
import api from '@/lib/api'
import PageHeader from '@/components/ui/PageHeader.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import ConfirmDialog from '@/components/ui/ConfirmDialog.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import TableSkeleton from '@/components/ui/TableSkeleton.vue'
import { useAuthStore } from '@/stores/auth'
import { useUiStore } from '@/stores/ui'
import { localized } from '@/i18n'

const { t, te, locale } = useI18n()
const auth = useAuthStore()
const ui = useUiStore()

const roles = ref([])
const permissionGroups = ref([])
const loading = ref(true)
const saving = ref(false)

async function load() {
  loading.value = true
  try {
    const [r, p] = await Promise.all([
      api.get('/roles', { params: { per_page: 100 } }),
      api.get('/permissions'),
    ])
    roles.value = r.data.data ?? r.data
    permissionGroups.value = p.data
  } catch {
    ui.error(t('toast.error'))
  } finally {
    loading.value = false
  }
}
onMounted(load)

/*
 * Bo'lim × amal matritsasi.
 * Asosiy amallar — ustunlarda; qolganlari (barcha guruhlar, yuklab olish, tasdiqlash, jo'natish, tiklash ...)
 * «Boshqa amallar» ustunida. Avval bular umuman ko'rinmas edi — rolga berib ham, olib ham bo'lmasdi.
 * Bo'lim nomi: menyu tarjimasi → shu fayldagi lug'at → bazadagi ruxsat nomi (label_uz/ru/en, « — » gacha).
 */
const ABILITIES = ['view', 'create', 'update', 'delete', 'result', 'manage']

const TXT = {
  uz: {
    ability: { view: 'Ko‘rish', create: 'Qo‘shish', update: 'Tahrirlash', delete: 'O‘chirish', result: 'Natija kiritish', manage: 'Boshqarish' },
    extra: { view_all: 'Barcha guruhlar', export: 'Yuklab olish', approve: 'Tasdiqlash', send: 'Tekshiruvga jo‘natish', process: 'Kiruvchini bajarish', restore: 'Tiklash' },
    other: 'Boshqa amallar',
    section: { lab: 'Laboratoriya', admin: 'Administratsiya', other: 'Boshqa' },
    module: {
      catalog: 'Kataloglar', customer_departments: 'Buyurtmachi bo‘limlar', documents: 'Hujjatlar', equipment: 'Uskunalar',
      executive: 'Rahbariyat paneli', interlab: 'Laboratoriyalararo tekshiruv', logs: 'Audit jurnali', menu: 'Menyu',
      monitoring_points: 'Monitoring nuqtalari', portal: 'Buyurtmachi portali', reports: 'Hisobotlar', roles: 'Rollar va ruxsatlar',
      sample_quantity: 'Namuna miqdori', samples: 'Namunalar', shifts: 'Smenalar', staff_activity: 'Xodimlar faoliyati',
      test_objects: 'Sinov obyektlari', trash: 'Savat', users: 'Foydalanuvchilar', water_orders: 'Suv laboratoriyasi',
      worksheet_templates: 'Ishchi varaq shablonlari', worksheets: 'Ishchi varaqlar',
    },
    note: {
      portal: 'Faqat buyurtmachi bo‘lim xodimlari uchun: bunday foydalanuvchi faqat o‘z namunalarini ko‘radi, laboratoriya sahifalari unga yopiladi',
      staff_activity: 'Ko‘rish — barcha xodimlar faoliyati; o‘z faoliyatini har kim ko‘radi',
      sample_quantity: 'Qabul, sarf va qaytarish hisobi',
      customer_departments: 'Boshqarish — bo‘limlar va portal foydalanuvchilari',
      shifts: 'Smena vaqtlarini sozlash',
      worksheet_templates: 'Shablonlar konstruktori',
    },
  },
  ru: {
    ability: { view: 'Просмотр', create: 'Создание', update: 'Изменение', delete: 'Удаление', result: 'Ввод результатов', manage: 'Управление' },
    extra: { view_all: 'Все группы', export: 'Выгрузка', approve: 'Утверждение', send: 'Отправка на проверку', process: 'Выполнение входящих', restore: 'Восстановление' },
    other: 'Другие действия',
    section: { lab: 'Лаборатория', admin: 'Администрирование', other: 'Прочее' },
    module: {
      catalog: 'Справочники', customer_departments: 'Подразделения-заказчики', documents: 'Документы', equipment: 'Оборудование',
      executive: 'Панель руководства', interlab: 'Межлабораторная проверка', logs: 'Журнал действий', menu: 'Меню',
      monitoring_points: 'Точки мониторинга', portal: 'Портал заказчика', reports: 'Отчёты', roles: 'Роли и права',
      sample_quantity: 'Количество пробы', samples: 'Пробы', shifts: 'Смены', staff_activity: 'Деятельность сотрудников',
      test_objects: 'Объекты испытания', trash: 'Корзина', users: 'Пользователи', water_orders: 'Водная лаборатория',
      worksheet_templates: 'Шаблоны рабочих листов', worksheets: 'Рабочие листы',
    },
    note: {
      portal: 'Только для сотрудников подразделений-заказчиков: такой пользователь видит лишь свои пробы, страницы лаборатории ему закрыты',
      staff_activity: 'Просмотр — деятельность всех сотрудников; свою видит каждый',
      sample_quantity: 'Учёт приёма, расхода и возврата',
      customer_departments: 'Управление — подразделения и пользователи портала',
      shifts: 'Настройка времени смен',
      worksheet_templates: 'Конструктор шаблонов',
    },
  },
  en: {
    ability: { view: 'View', create: 'Create', update: 'Edit', delete: 'Delete', result: 'Enter results', manage: 'Manage' },
    extra: { view_all: 'All groups', export: 'Export', approve: 'Approve', send: 'Send for check', process: 'Process incoming', restore: 'Restore' },
    other: 'Other actions',
    section: { lab: 'Laboratory', admin: 'Administration', other: 'Other' },
    module: {
      catalog: 'Catalog', customer_departments: 'Customer departments', documents: 'Documents', equipment: 'Equipment',
      executive: 'Executive dashboard', interlab: 'Inter-lab check', logs: 'Activity log', menu: 'Menu',
      monitoring_points: 'Monitoring points', portal: 'Customer portal', reports: 'Reports', roles: 'Roles & permissions',
      sample_quantity: 'Sample quantity', samples: 'Samples', shifts: 'Shifts', staff_activity: 'Staff activity',
      test_objects: 'Test objects', trash: 'Trash', users: 'Users', water_orders: 'Water lab',
      worksheet_templates: 'Worksheet templates', worksheets: 'Worksheets',
    },
    note: {
      portal: 'For customer-department staff only: such a user sees only their own samples, lab pages are closed to them',
      staff_activity: 'View — activity of all staff; everyone sees their own',
      sample_quantity: 'Receipt, usage and return accounting',
      customer_departments: 'Manage — departments and portal users',
      shifts: 'Shift time settings',
      worksheet_templates: 'Template builder',
    },
  },
}
const txt = computed(() => TXT[locale.value] ?? TXT.ru)

// Menyuda boshqa kalit bilan nomlangan bo'limlar
const LABEL_KEYS = { menu: ['menu.menu_admin'], trash: ['trash.title'] }

/** Bazadagi nomni « — » bo'yicha bo'lish: «Uskunalar — Ko'rish» → ['Uskunalar', "Ko'rish"] */
function labelParts(p) {
  const s = localized(p, 'label') || ''
  const i = s.indexOf(' — ')
  return i < 0 ? [s.trim(), ''] : [s.slice(0, i).trim(), s.slice(i + 3).trim()]
}

function moduleLabel(module, perms) {
  for (const key of [`menu.${module}`, ...(LABEL_KEYS[module] ?? [])]) {
    if (te(key)) return t(key)
  }
  return txt.value.module[module] || labelParts(perms[0])[0] || module
}

function extraLabel(p) {
  return txt.value.extra[p.ability] || labelParts(p)[1] || p.ability || p.name
}

/** Katakcha ustiga olib borilganda — ruxsatning to'liq nomi */
function permTitle(p) {
  return localized(p, 'label') || p.name
}

const SECTION_ORDER = ['lab', 'admin']

const sections = computed(() => {
  const rows = permissionGroups.value.map((g) => {
    const perms = [...g.permissions].sort((a, b) => (a.sort_order ?? 0) - (b.sort_order ?? 0))
    return {
      module: g.module,
      label: moduleLabel(g.module, perms),
      note: txt.value.note[g.module] ?? '',
      group: SECTION_ORDER.includes(perms[0]?.group) ? perms[0].group : 'other',
      order: perms[0]?.sort_order ?? 0,
      cells: ABILITIES.map((ability) => ({
        ability,
        permission: perms.find((p) => p.ability === ability) ?? null,
      })),
      extras: perms.filter((p) => !ABILITIES.includes(p.ability)),
    }
  })
  return [...SECTION_ORDER, 'other']
    .map((key) => ({
      key,
      label: txt.value.section[key],
      rows: rows.filter((r) => r.group === key).sort((a, b) => a.order - b.order),
    }))
    .filter((s) => s.rows.length)
})

/* Forma */
const formOpen = ref(false)
const editing = ref(null)
const selected = ref(new Set())
const form = reactive({
  name: '', display_name_ru: '', display_name_uz: '', display_name_en: '', description: '',
})

function openCreate() {
  editing.value = null
  Object.assign(form, { name: '', display_name_ru: '', display_name_uz: '', display_name_en: '', description: '' })
  selected.value = new Set()
  formOpen.value = true
}

function openEdit(role) {
  editing.value = role
  Object.assign(form, {
    name: role.name,
    display_name_ru: role.display_name_ru ?? '',
    display_name_uz: role.display_name_uz ?? '',
    display_name_en: role.display_name_en ?? '',
    description: role.description ?? '',
  })
  selected.value = new Set((role.permissions ?? []).map((p) => p.id))
  formOpen.value = true
}

function toggle(id) {
  const next = new Set(selected.value)
  next.has(id) ? next.delete(id) : next.add(id)
  selected.value = next
}

function toggleModule(row) {
  const ids = [...row.cells.filter((c) => c.permission).map((c) => c.permission), ...row.extras].map((p) => p.id)
  const allOn = ids.every((id) => selected.value.has(id))
  const next = new Set(selected.value)
  ids.forEach((id) => (allOn ? next.delete(id) : next.add(id)))
  selected.value = next
}

function setAll(on) {
  selected.value = on
    ? new Set(permissionGroups.value.flatMap((g) => g.permissions.map((p) => p.id)))
    : new Set()
}

async function save() {
  saving.value = true
  try {
    const payload = { ...form, permission_ids: [...selected.value] }
    if (editing.value) {
      await api.put(`/roles/${editing.value.id}`, payload)
    } else {
      await api.post('/roles', payload)
    }
    ui.success(t('role.savedPermissions'))
    formOpen.value = false
    await load()
    await auth.fetchUser() // o'z ruxsatlari o'zgargan bo'lishi mumkin
  } catch (e) {
    ui.error(e.response?.data?.message || t('toast.error'))
  } finally {
    saving.value = false
  }
}

/* O'chirish */
const confirmOpen = ref(false)
const target = ref(null)

async function remove() {
  saving.value = true
  try {
    await api.delete(`/roles/${target.value.id}`)
    ui.success(t('toast.deleted'))
    confirmOpen.value = false
    await load()
  } catch (e) {
    ui.error(e.response?.data?.message || t('toast.error'))
  } finally {
    saving.value = false
  }
}

function roleLabel(role) {
  return role[`display_name_${locale.value}`] || role.display_name_ru || role.name
}

const canCreate = computed(() => auth.can('roles.create'))
const canUpdate = computed(() => auth.can('roles.update'))
const canDelete = computed(() => auth.can('roles.delete'))
</script>

<template>
  <div>
    <PageHeader :title="t('role.title')" :subtitle="t('role.subtitle')">
      <template #actions>
        <button v-if="canCreate" class="btn-primary" @click="openCreate">
          <Plus :size="17" /> {{ t('common.create') }}
        </button>
      </template>
    </PageHeader>

    <div class="panel overflow-hidden">
      <TableSkeleton v-if="loading" :cols="4" />
      <EmptyState v-else-if="!roles.length" :icon="KeyRound" :title="t('common.noData')" />

      <table v-else class="w-full text-left">
        <thead>
          <tr class="border-b border-line bg-surface-sunken/50 text-[12.5px] font-semibold text-ink-muted">
            <th class="px-4 py-2.5">{{ t('role.displayName') }}</th>
            <th class="px-4 py-2.5">{{ t('role.name') }}</th>
            <th class="px-4 py-2.5">{{ t('role.permissions') }}</th>
            <th class="px-4 py-2.5 text-right">{{ t('role.usersCount') }}</th>
            <th class="px-4 py-2.5 w-px" />
          </tr>
        </thead>
        <tbody class="divide-y divide-line">
          <tr v-for="(role, i) in roles" :key="role.id"
              class="group hover:bg-surface-sunken/60 transition-colors animate-row-in"
              :style="{ animationDelay: `${i * 30}ms` }">
            <td class="px-4 py-3">
              <span class="flex items-center gap-2">
                <span class="text-[13.5px] font-medium text-ink">{{ roleLabel(role) }}</span>
                <span v-if="role.is_system"
                      class="text-[10.5px] px-1.5 py-0.5 rounded bg-surface-sunken text-ink-faint"
                      :title="t('role.systemRoleHint')">{{ t('role.systemRole') }}</span>
              </span>
              <span v-if="role.description" class="block text-[12px] text-ink-faint mt-0.5">{{ role.description }}</span>
            </td>
            <td class="px-4 py-3 text-[13px] text-ink-muted font-mono">{{ role.name }}</td>
            <td class="px-4 py-3 text-[13px] text-ink-muted tnum">
              {{ role.name === 'admin' ? t('common.all') : (role.permissions?.length ?? 0) }}
            </td>
            <td class="px-4 py-3 text-right text-[13px] text-ink-muted tnum">{{ role.users_count ?? 0 }}</td>
            <td class="px-4 py-3 text-right whitespace-nowrap">
              <div class="flex items-center justify-end gap-0.5 md:opacity-0 md:group-hover:opacity-100 transition-opacity">
                <button v-if="canUpdate" class="btn-ghost btn-sm btn-icon" :title="t('common.edit')" @click="openEdit(role)">
                  <Pencil :size="15" />
                </button>
                <button v-if="canDelete && !role.is_system" class="btn-ghost btn-sm btn-icon hover:text-crit"
                        :title="t('common.delete')" @click="target = role; confirmOpen = true">
                  <Trash2 :size="15" />
                </button>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Rol formasi + ruxsat matritsasi -->
    <BaseModal
      v-model="formOpen"
      size="xl"
      :title="editing ? `${t('common.edit')} · ${roleLabel(editing)}` : t('common.create')"
      :subtitle="t('role.one')"
    >
      <div class="grid gap-4 sm:grid-cols-2 mb-6">
        <div>
          <label class="field-label">{{ t('role.name') }} <span class="text-crit">*</span></label>
          <input v-model="form.name" class="field-input font-mono" :disabled="editing?.is_system" />
          <p class="mt-1.5 text-[12.5px] text-ink-faint">{{ t('role.nameHint') }}</p>
        </div>
        <div>
          <label class="field-label">{{ t('role.displayName') }} (RU)</label>
          <input v-model="form.display_name_ru" class="field-input" />
        </div>
        <div>
          <label class="field-label">{{ t('role.displayName') }} (UZ)</label>
          <input v-model="form.display_name_uz" class="field-input" />
        </div>
        <div>
          <label class="field-label">{{ t('role.displayName') }} (EN)</label>
          <input v-model="form.display_name_en" class="field-input" />
        </div>
        <div class="sm:col-span-2">
          <label class="field-label">{{ t('role.description') }}</label>
          <input v-model="form.description" class="field-input" />
        </div>
      </div>

      <div class="flex items-center justify-between gap-3 pb-2.5 border-b border-line">
        <div>
          <h3 class="text-[13px] font-semibold text-ink">{{ t('role.permissions') }}</h3>
          <p class="text-[12.5px] text-ink-faint mt-0.5">{{ t('role.permissionsHint') }}</p>
        </div>
        <div class="flex items-center gap-1.5 shrink-0">
          <button class="btn-ghost btn-sm" @click="setAll(true)">{{ t('role.selectAll') }}</button>
          <button class="btn-ghost btn-sm" @click="setAll(false)">{{ t('role.clearAll') }}</button>
        </div>
      </div>

      <div class="overflow-x-auto -mx-5 px-5">
        <table class="w-full text-left min-w-[860px]">
          <thead>
            <tr class="text-[11.5px] font-semibold text-ink-faint">
              <th class="py-2.5 pr-4">{{ t('role.moduleAccess') }}</th>
              <th v-for="a in ABILITIES" :key="a" class="py-2.5 px-2 text-center w-[78px]" :data-col="a">
                {{ txt.ability[a] }}
              </th>
              <th class="py-2.5 pl-3 min-w-[190px]" data-col="other">{{ txt.other }}</th>
            </tr>
          </thead>
          <tbody v-for="sec in sections" :key="sec.key" :data-section="sec.key">
            <tr>
              <th :colspan="ABILITIES.length + 2" class="pt-4 pb-1.5 text-[11px] font-semibold uppercase tracking-wide text-ink-faint text-left">
                {{ sec.label }}
              </th>
            </tr>
            <tr v-for="row in sec.rows" :key="row.module" class="border-t border-line hover:bg-surface-sunken/50 transition-colors" :data-row="row.module">
              <td class="py-2.5 pr-4 align-top">
                <button class="text-[13.5px] text-ink hover:text-assay-600 dark:hover:text-assay-300 transition-colors text-left"
                        data-row-label @click="toggleModule(row)">
                  {{ row.label }}
                </button>
                <span v-if="row.note" class="block max-w-[260px] text-[11.5px] leading-snug mt-0.5"
                      :class="row.module === 'portal' ? 'text-warn' : 'text-ink-faint'" data-note>{{ row.note }}</span>
              </td>
              <td v-for="cell in row.cells" :key="cell.ability" class="py-2.5 px-2 text-center align-top">
                <button
                  v-if="cell.permission"
                  type="button"
                  class="h-5 w-5 rounded border inline-grid place-items-center align-middle transition-all active:scale-90"
                  :class="selected.has(cell.permission.id)
                    ? 'bg-assay-500 border-assay-500 text-white'
                    : 'border-line hover:border-assay-400'"
                  :title="permTitle(cell.permission)"
                  :aria-label="permTitle(cell.permission)"
                  :aria-pressed="selected.has(cell.permission.id)"
                  :data-perm="cell.permission.name"
                  @click="toggle(cell.permission.id)"
                >
                  <Check v-if="selected.has(cell.permission.id)" :size="12" :stroke-width="3" />
                </button>
                <span v-else class="text-ink-faint/40 text-[13px]">·</span>
              </td>
              <td class="py-2.5 pl-3 align-top">
                <div v-if="row.extras.length" class="flex flex-col gap-1.5">
                  <button
                    v-for="p in row.extras" :key="p.id"
                    type="button"
                    class="group/x inline-flex items-center gap-2 text-left"
                    :title="permTitle(p)"
                    :aria-pressed="selected.has(p.id)"
                    :data-perm="p.name"
                    @click="toggle(p.id)"
                  >
                    <span
                      class="h-5 w-5 shrink-0 rounded border grid place-items-center transition-all group-active/x:scale-90"
                      :class="selected.has(p.id)
                        ? 'bg-assay-500 border-assay-500 text-white'
                        : 'border-line group-hover/x:border-assay-400'"
                    >
                      <Check v-if="selected.has(p.id)" :size="12" :stroke-width="3" />
                    </span>
                    <span class="text-[12.5px] text-ink-muted">{{ extraLabel(p) }}</span>
                  </button>
                </div>
                <span v-else class="text-ink-faint/40 text-[13px]">·</span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <template #footer>
        <button class="btn-ghost" @click="formOpen = false">{{ t('common.cancel') }}</button>
        <button class="btn-primary" :disabled="saving || !form.name" @click="save">
          {{ saving ? t('common.loading') : t('common.save') }}
        </button>
      </template>
    </BaseModal>

    <ConfirmDialog
      v-model="confirmOpen"
      :title="t('confirm.deleteTitle')"
      :body="t('confirm.deleteBody', { name: target ? roleLabel(target) : '' })"
      :confirm-label="t('confirm.deleteAction')"
      :cancel-label="t('common.cancel')"
      :loading="saving"
      @confirm="remove"
    />
  </div>
</template>
