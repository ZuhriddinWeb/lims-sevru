import { localized } from '@/i18n'

/* ======================= LABORATORIYALAR ======================= */
export const laboratoryResource = {
  key: 'laboratories',
  endpoint: '/laboratories',
  permission: 'catalog',
  permissionOverrides: { create: 'catalog.manage', update: 'catalog.manage', delete: 'catalog.manage' },
  titleKey: 'catalog.laboratories',
  subtitleKey: 'catalog.laboratoriesSubtitle',
  defaultSort: { by: 'sort_order', dir: 'asc' },

  columns: [
    { key: 'code', labelKey: 'catalog.code', mono: true, primary: true },
    { key: 'name_ru', labelKey: 'catalog.nameRu', nowrap: false },
    { key: 'name_uz', labelKey: 'catalog.nameUz', nowrap: false },
    { key: 'equipment_count', labelKey: 'equipment.title', align: 'right' },
    { key: 'is_active', labelKey: 'catalog.isActive', type: 'bool' },
  ],

  form: {
    size: 'md',
    sections: [
      {
        fields: [
          { key: 'code', type: 'text', labelKey: 'catalog.code', required: true },
          { key: 'sort_order', type: 'number', labelKey: 'catalog.sortOrder', default: 0 },
          { key: 'name_ru', type: 'text', labelKey: 'catalog.nameRu', required: true, col: 2 },
          { key: 'name_uz', type: 'text', labelKey: 'catalog.nameUz', required: true, col: 2 },
          { key: 'name_en', type: 'text', labelKey: 'catalog.nameEn', required: true, col: 2 },
          { key: 'description', type: 'textarea', labelKey: 'catalog.description', col: 2, rows: 2 },
          { key: 'is_active', type: 'switch', labelKey: 'catalog.isActive', default: true },
        ],
      },
    ],
  },
}

/* ======================= LABORATORIYA GURUHLARI ======================= */
export const laboratoryGroupResource = {
  key: 'laboratory_groups',
  endpoint: '/laboratory-groups',
  permission: 'catalog',
  titleKey: 'catalog.groups',
  subtitleKey: 'catalog.groupsSubtitle',
  searchable: false,
  defaultSort: { by: 'sort_order', dir: 'asc' },

  columns: [
    { key: 'code', labelKey: 'catalog.code', mono: true, primary: true },
    { key: 'name_ru', labelKey: 'catalog.nameRu', nowrap: false },
    { key: 'laboratory', labelKey: 'catalog.laboratory', type: 'localized', base: 'name', nowrap: false },
    { key: 'kind', labelKey: 'catalog.kind',
      format: (r, { t }) => t(`catalog.kind${r.kind.charAt(0).toUpperCase() + r.kind.slice(1)}`) },
    { key: 'is_active', labelKey: 'catalog.isActive', type: 'bool' },
  ],

  filters: [
    { key: 'laboratory_id', type: 'relation', labelKey: 'catalog.laboratory', source: 'laboratories', nullLabelKey: 'common.all' },
  ],

  form: {
    size: 'md',
    sections: [
      {
        fields: [
          { key: 'laboratory_id', type: 'relation', labelKey: 'catalog.laboratory', source: 'laboratories', required: true },
          { key: 'code', type: 'text', labelKey: 'catalog.code', required: true },
          { key: 'name_ru', type: 'text', labelKey: 'catalog.nameRu', required: true, col: 2 },
          { key: 'name_uz', type: 'text', labelKey: 'catalog.nameUz', required: true, col: 2 },
          { key: 'name_en', type: 'text', labelKey: 'catalog.nameEn', required: true, col: 2 },
          { key: 'kind', type: 'select', labelKey: 'catalog.kind', required: true, default: 'both',
            options: [
              { value: 'customer', labelKey: 'catalog.kindCustomer' },
              { value: 'executor', labelKey: 'catalog.kindExecutor' },
              { value: 'both', labelKey: 'catalog.kindBoth' },
            ] },
          { key: 'sort_order', type: 'number', labelKey: 'catalog.sortOrder', default: 0 },
          { key: 'is_active', type: 'switch', labelKey: 'catalog.isActive', default: true },
        ],
      },
    ],
  },
}

/* ======================= ELEMENTLAR ======================= */
export const elementResource = {
  key: 'elements',
  endpoint: '/elements',
  permission: 'catalog',
  titleKey: 'catalog.elements',
  subtitleKey: 'catalog.elementsSubtitle',
  defaultSort: { by: 'sort_order', dir: 'asc' },

  columns: [
    { key: 'symbol', labelKey: 'catalog.symbol', mono: true, primary: true, width: '90px' },
    { key: 'name_ru', labelKey: 'catalog.nameRu', nowrap: false },
    { key: 'name_uz', labelKey: 'catalog.nameUz', nowrap: false },
    { key: 'name_en', labelKey: 'catalog.nameEn', nowrap: false },
    { key: 'default_unit', labelKey: 'catalog.defaultUnit' },
    { key: 'is_active', labelKey: 'catalog.isActive', type: 'bool' },
  ],

  form: {
    size: 'md',
    sections: [
      {
        fields: [
          { key: 'symbol', type: 'formula', labelKey: 'catalog.symbol' },        // [P25] SO4 → SO₄
          { key: 'default_unit', type: 'unit', labelKey: 'catalog.defaultUnit' }, // [P25] dm3 → dm³
          { key: 'name_ru', type: 'text', labelKey: 'catalog.nameRu', required: true, col: 2 },
          { key: 'name_uz', type: 'text', labelKey: 'catalog.nameUz', required: true, col: 2 },
          { key: 'name_en', type: 'text', labelKey: 'catalog.nameEn', required: true, col: 2 },
          { key: 'sort_order', type: 'number', labelKey: 'catalog.sortOrder', default: 0 },
          { key: 'is_active', type: 'switch', labelKey: 'catalog.isActive', default: true },
        ],
      },
    ],
  },
}

/* ======================= FOYDALANUVCHILAR ======================= */
export const userResource = {
  key: 'users',
  endpoint: '/users',
  permission: 'users',
  titleKey: 'user.title',
  subtitleKey: 'user.subtitle',
  itemTitleKey: 'user.one',
  rowTitle: (r) => r?.name,
  defaultSort: { by: 'created_at', dir: 'desc' },

  columns: [
    { key: 'name', labelKey: 'user.name', primary: true, sortable: true, nowrap: false },
    { key: 'username', labelKey: 'user.username', mono: true },
    { key: 'position', labelKey: 'user.position', nowrap: false },
    { key: 'role', labelKey: 'user.role', type: 'localized', base: 'display_name' },
    { key: 'laboratory', labelKey: 'user.laboratory', type: 'localized', base: 'name', nowrap: false },
    { key: 'laboratory_group', labelKey: 'user.group', type: 'localized', base: 'name', nowrap: false },
    { key: 'is_active', labelKey: 'status.label', type: 'status',
      format: (r) => (r.is_active ? 'active' : 'inactive') },
  ],

  filters: [
    { key: 'role_id', type: 'relation', labelKey: 'user.role', source: 'roles',
      optionLabel: (o, locale) => o[`display_name_${locale}`] || o.name, nullLabelKey: 'common.all' },
    { key: 'laboratory_id', type: 'relation', labelKey: 'user.laboratory', source: 'laboratories', nullLabelKey: 'common.all' },
  ],

  form: {
    size: 'lg',
    sections: [
      {
        titleKey: 'user.one',
        fields: [
          { key: 'name', type: 'text', labelKey: 'user.name', required: true, col: 2 },
          { key: 'username', type: 'text', labelKey: 'user.username', required: true },
          { key: 'position', type: 'text', labelKey: 'user.position' },
          { key: 'email', type: 'email', labelKey: 'user.email' },
          { key: 'phone', type: 'tel', labelKey: 'user.phone' },
          { key: 'password', type: 'password', labelKey: 'user.password', hintKey: 'user.passwordHint', col: 2 },
        ],
      },
      {
        titleKey: 'role.permissions',
        fields: [
          { key: 'role_id', type: 'relation', labelKey: 'user.role', source: 'roles', required: true,
            optionLabel: (o, locale) => o[`display_name_${locale}`] || o.name },
          { key: 'is_active', type: 'switch', labelKey: 'user.isActive', default: true },
          { key: 'laboratory_id', type: 'relation', labelKey: 'user.laboratory', source: 'laboratories' },
          { key: 'laboratory_group_id', type: 'relation', labelKey: 'user.group', source: 'laboratoryGroups',
            filterOptions: (o, form) => !form.laboratory_id || o.laboratory_id === form.laboratory_id },
        ],
      },
    ],
  },
}

/* ======================= MENYU BANDLARI ======================= */
export const menuItemResource = {
  key: 'menu_items',
  endpoint: '/menu-items',
  permission: 'menu',
  permissionAbility: 'manage',
  titleKey: 'menuAdmin.title',
  subtitleKey: 'menuAdmin.subtitle',
  itemTitleKey: 'menuAdmin.one',
  rowTitle: (r) => r?.title_ru,
  searchable: false,
  defaultSort: { by: 'sort_order', dir: 'asc' },

  columns: [
    { key: 'key', labelKey: 'menuAdmin.key', mono: true, primary: true },
    { key: 'title_ru', labelKey: 'catalog.nameRu', nowrap: false },
    { key: 'title_uz', labelKey: 'catalog.nameUz', nowrap: false },
    { key: 'route', labelKey: 'menuAdmin.route', mono: true },
    { key: 'permission', labelKey: 'menuAdmin.permission', mono: true },
    { key: 'sort_order', labelKey: 'menuAdmin.sortOrder', align: 'right' },
    { key: 'is_active', labelKey: 'menuAdmin.isActive', type: 'bool' },
  ],

  form: {
    size: 'md',
    sections: [
      {
        fields: [
          { key: 'key', type: 'text', labelKey: 'menuAdmin.key', required: true },
          { key: 'icon', type: 'text', labelKey: 'menuAdmin.icon', placeholder: 'flask-conical' },
          { key: 'title_ru', type: 'text', labelKey: 'catalog.nameRu', required: true, col: 2 },
          { key: 'title_uz', type: 'text', labelKey: 'catalog.nameUz', required: true, col: 2 },
          { key: 'title_en', type: 'text', labelKey: 'catalog.nameEn', required: true, col: 2 },
          { key: 'route', type: 'text', labelKey: 'menuAdmin.route' },
          { key: 'permission', type: 'text', labelKey: 'menuAdmin.permission', hintKey: 'menuAdmin.permissionHint' },
          { key: 'parent_id', type: 'relation', labelKey: 'menuAdmin.parent', source: 'menuItems',
            optionLabel: (o) => `${o.key} · ${o.title_ru}` },
          { key: 'sort_order', type: 'number', labelKey: 'menuAdmin.sortOrder', default: 0 },
          { key: 'is_active', type: 'switch', labelKey: 'menuAdmin.isActive', default: true },
        ],
      },
    ],
  },
}

/* ======================= SINOV PROFILLARI (TZ 2.10) ======================= */
export const testProfileResource = {
  key: 'test_profiles',
  endpoint: '/test-profiles',
  permission: 'catalog',
  titleKey: 'profile.title',
  subtitleKey: 'profile.subtitle',
  itemTitleKey: 'profile.one',
  rowTitle: (r) => r?.name_ru,
  defaultSort: { by: 'sort_order', dir: 'asc' },

  columns: [
    { key: 'code', labelKey: 'profile.code', mono: true, primary: true },
    { key: 'name_ru', labelKey: 'catalog.nameRu', nowrap: false },
    { key: 'object_type', labelKey: 'profile.objectType',
      format: (r, { t }) => (r.object_type ? t(`objectType.${r.object_type}`) : t('common.all')) },
    { key: 'elements', labelKey: 'profile.elements', nowrap: false,
      format: (r) => (r.elements ?? []).map((e) => e.symbol).join(', ') || '—' },
    { key: 'is_default', labelKey: 'profile.isDefault', type: 'bool' },
    { key: 'is_active', labelKey: 'catalog.isActive', type: 'bool' },
  ],

  filters: [
    { key: 'object_type', type: 'select', labelKey: 'profile.objectType', nullLabelKey: 'common.all',
      options: [
        { value: 'raw_material', labelKey: 'objectType.raw_material' },
        { value: 'product', labelKey: 'objectType.product' },
        { value: 'water', labelKey: 'objectType.water' },
        { value: 'air', labelKey: 'objectType.air' },
        { value: 'biomaterial', labelKey: 'objectType.biomaterial' },
        { value: 'other', labelKey: 'objectType.other' },
      ] },
  ],

  form: {
    size: 'lg',
    sections: [
      {
        fields: [
          { key: 'code', type: 'text', labelKey: 'profile.code', required: true },
          { key: 'object_type', type: 'select', labelKey: 'profile.objectType', hintKey: 'profile.objectTypeHint',
            nullLabelKey: 'common.all',
            options: [
              { value: 'raw_material', labelKey: 'objectType.raw_material' },
              { value: 'product', labelKey: 'objectType.product' },
              { value: 'water', labelKey: 'objectType.water' },
              { value: 'air', labelKey: 'objectType.air' },
              { value: 'biomaterial', labelKey: 'objectType.biomaterial' },
              { value: 'other', labelKey: 'objectType.other' },
            ] },
          { key: 'name_ru', type: 'text', labelKey: 'catalog.nameRu', required: true, col: 2 },
          { key: 'name_uz', type: 'text', labelKey: 'catalog.nameUz', required: true, col: 2 },
          { key: 'name_en', type: 'text', labelKey: 'catalog.nameEn', required: true, col: 2 },
          { key: 'element_ids', type: 'multiselect', labelKey: 'profile.elements',
            source: 'elements', fromRelation: 'elements', col: 2 },
          { key: 'laboratory_id', type: 'relation', labelKey: 'profile.laboratory', source: 'laboratories' },
          { key: 'sort_order', type: 'number', labelKey: 'catalog.sortOrder', default: 0 },
          { key: 'is_default', type: 'switch', labelKey: 'profile.isDefault', hintKey: 'profile.isDefaultHint' },
          { key: 'is_active', type: 'switch', labelKey: 'catalog.isActive', default: true },
        ],
      },
    ],
  },
}
