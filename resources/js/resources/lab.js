import { localized } from '@/i18n'
import { monthOptions } from '@/lib/format'

/* Takrorlanadigan variantlar */
export const EQUIPMENT_STATUSES = [
  { value: 'working', labelKey: 'status.working' },
  { value: 'maintenance', labelKey: 'status.maintenance' },
  { value: 'out_of_service', labelKey: 'status.out_of_service' },
  { value: 'awaiting_calibration', labelKey: 'status.awaiting_calibration' },
]

export const SAMPLE_STATUSES = [
  { value: 'registered', labelKey: 'status.registered' },
  { value: 'assigned', labelKey: 'status.assigned' },
  { value: 'in_progress', labelKey: 'status.in_progress' },
  { value: 'completed', labelKey: 'status.completed' },
  { value: 'archived', labelKey: 'status.archived' },
]

export const OBJECT_STATUSES = [
  { value: 'new', labelKey: 'status.new' },
  { value: 'in_testing', labelKey: 'status.in_testing' },
  { value: 'tested', labelKey: 'status.tested' },
  { value: 'defect', labelKey: 'status.defect' },
  { value: 'disposed', labelKey: 'status.disposed' },
]

export const OBJECT_TYPES = [
  { value: 'raw_material', labelKey: 'objectType.raw_material' },
  { value: 'product', labelKey: 'objectType.product' },
  { value: 'water', labelKey: 'objectType.water' },
  { value: 'air', labelKey: 'objectType.air' },
  { value: 'biomaterial', labelKey: 'objectType.biomaterial' },
  { value: 'other', labelKey: 'objectType.other' },
]

/* ======================= USKUNALAR ======================= */
export const equipmentResource = {
  key: 'equipment',
  endpoint: '/equipment',
  permission: 'equipment',
  exportEndpoint: '/reports/equipment',
  titleKey: 'equipment.title',
  subtitleKey: 'equipment.subtitle',
  itemTitleKey: 'equipment.one',
  rowTitle: (r) => r?.name,
  defaultSort: { by: 'name', dir: 'asc' },

  columns: [
    { key: 'name', labelKey: 'equipment.name', sortable: true, primary: true, nowrap: false },
    { key: 'model', labelKey: 'equipment.model' },
    { key: 'serial_number', labelKey: 'equipment.serialNumber', mono: true, sortable: true },
    { key: 'laboratory', labelKey: 'catalog.laboratory', type: 'localized', base: 'name', nowrap: false },
    { key: 'location', labelKey: 'equipment.location' },
    { key: 'next_calibration_at', labelKey: 'equipment.nextCalibration', type: 'date', sortable: true },
    { key: 'status', labelKey: 'equipment.status', type: 'status' },
  ],

  filters: [
    { key: 'laboratory_id', type: 'relation', labelKey: 'catalog.laboratory', source: 'laboratories', nullLabelKey: 'common.all' },
    { key: 'status', type: 'select', labelKey: 'equipment.status', options: EQUIPMENT_STATUSES, nullLabelKey: 'common.all' },
    { key: 'calibration_within_days', type: 'select', labelKey: 'equipment.nextCalibration', nullLabelKey: 'common.all',
      options: [{ value: 7, label: '7' }, { value: 30, label: '30' }, { value: 90, label: '90' }] },
    { key: 'only_blocked', type: 'switch', labelKey: 'equipment.blocked' },
  ],

  form: {
    size: 'lg',
    sections: [
      {
        titleKey: 'equipment.one',
        fields: [
          { key: 'name', type: 'text', labelKey: 'equipment.name', required: true, col: 2 },
          { key: 'model', type: 'text', labelKey: 'equipment.model' },
          { key: 'serial_number', type: 'text', labelKey: 'equipment.serialNumber', required: true },
          { key: 'inventory_number', type: 'text', labelKey: 'equipment.inventoryNumber' },
          { key: 'status', type: 'select', labelKey: 'equipment.status', options: EQUIPMENT_STATUSES, default: 'working' },
          { key: 'laboratory_id', type: 'relation', labelKey: 'catalog.laboratory', source: 'laboratories', required: true },
          { key: 'laboratory_group_id', type: 'relation', labelKey: 'catalog.groups', source: 'laboratoryGroups',
            filterOptions: (o, form) => !form.laboratory_id || o.laboratory_id === form.laboratory_id },
          { key: 'manufacturer', type: 'text', labelKey: 'equipment.manufacturer' },
          { key: 'supplier', type: 'text', labelKey: 'equipment.supplier' },
          { key: 'location', type: 'text', labelKey: 'equipment.location' },
          { key: 'commissioned_at', type: 'date', labelKey: 'equipment.commissionedAt' },
        ],
      },
      {
        titleKey: 'equipment.calibration',
        fields: [
          { key: 'calibration_interval_days', type: 'number', labelKey: 'equipment.calibrationInterval', min: 0 },
          { key: 'last_calibration_at', type: 'date', labelKey: 'equipment.lastCalibration' },
          { key: 'next_calibration_at', type: 'date', labelKey: 'equipment.nextCalibration' },
          { key: 'max_usage_before_calibration', type: 'number', labelKey: 'equipment.maxUsage', min: 0 },
          { key: 'custom_condition', type: 'text', labelKey: 'equipment.customCondition', hintKey: 'equipment.customConditionHint' },
          { key: 'custom_condition_due', type: 'date', labelKey: 'equipment.customConditionDue' },
        ],
      },
      {
        titleKey: 'equipment.maintenance',
        fields: [
          { key: 'maintenance_interval_days', type: 'number', labelKey: 'equipment.maintenanceInterval', min: 0 },
          { key: 'last_maintenance_at', type: 'date', labelKey: 'equipment.lastMaintenance' },
          { key: 'next_maintenance_at', type: 'date', labelKey: 'equipment.nextMaintenance' },
          { key: 'notes', type: 'textarea', labelKey: 'equipment.notes', col: 2, rows: 2 },
        ],
      },
    ],
  },
}

/* ======================= SINOV OBYEKTLARI ======================= */
export const testObjectResource = {
  key: 'test_objects',
  endpoint: '/test-objects',
  permission: 'test_objects',
  exportEndpoint: '/reports/test-objects',
  titleKey: 'testObject.title',
  subtitleKey: 'testObject.subtitle',
  itemTitleKey: 'testObject.one',
  rowTitle: (r) => r?.name ?? r?.identifier,
  defaultSort: { by: 'created_at', dir: 'desc' },

  columns: [
    { key: 'identifier', labelKey: 'testObject.identifier', mono: true, primary: true, sortable: true },
    { key: 'name', labelKey: 'testObject.name', nowrap: false },
    { key: 'type', labelKey: 'testObject.type', type: 'enum', enumPrefix: 'objectType' },
    { key: 'batch', labelKey: 'testObject.batch' },
    { key: 'storage_location', labelKey: 'testObject.storageLocation', nowrap: false },
    { key: 'storage_expiry', labelKey: 'testObject.storageExpiry', type: 'date', sortable: true },
    { key: 'status', labelKey: 'status.label', type: 'status' },
  ],

  filters: [
    { key: 'type', type: 'select', labelKey: 'testObject.type', options: OBJECT_TYPES, nullLabelKey: 'common.all' },
    { key: 'status', type: 'select', labelKey: 'status.label', options: OBJECT_STATUSES, nullLabelKey: 'common.all' },
    { key: 'laboratory_id', type: 'relation', labelKey: 'catalog.laboratory', source: 'laboratories', nullLabelKey: 'common.all' },
    { key: 'expiring_soon', type: 'switch', labelKey: 'testObject.expiringSoon' },
  ],

  form: {
    size: 'lg',
    sections: [
      {
        titleKey: 'testObject.one',
        fields: [
          { key: 'identifier', type: 'text', labelKey: 'testObject.identifier', hintKey: 'testObject.identifierHint' },
          { key: 'type', type: 'select', labelKey: 'testObject.type', options: OBJECT_TYPES, required: true, default: 'raw_material' },
          { key: 'name', type: 'text', labelKey: 'testObject.name', required: true, col: 2 },
          { key: 'code', type: 'text', labelKey: 'testObject.code' },
          { key: 'batch', type: 'text', labelKey: 'testObject.batch' },
          { key: 'status', type: 'select', labelKey: 'status.label', options: OBJECT_STATUSES, default: 'new' },
          { key: 'laboratory_id', type: 'relation', labelKey: 'catalog.laboratory', source: 'laboratories' },
          { key: 'test_profile_id', type: 'relation', labelKey: 'profile.one', hintKey: 'profile.applied',
            source: 'testProfiles',
            filterOptions: (o, form) => !form.type || !o.object_type || o.object_type === form.type },
          { key: 'description', type: 'textarea', labelKey: 'testObject.description', col: 2, rows: 2 },
        ],
      },
      {
        titleKey: 'testObject.physical',
        fields: [
          { key: 'physical_characteristics', type: 'textarea', labelKey: 'testObject.physical', col: 2, rows: 2 },
          { key: 'chemical_composition', type: 'textarea', labelKey: 'testObject.chemical', col: 2, rows: 2 },
          { key: 'element_ids', type: 'multiselect', labelKey: 'sample.elements', source: 'elements', fromRelation: 'elements', col: 2 },
        ],
      },
      {
        titleKey: 'testObject.storageConditions',
        fields: [
          { key: 'source_supplier', type: 'text', labelKey: 'testObject.supplier' },
          { key: 'received_at', type: 'date', labelKey: 'testObject.receivedAt' },
          { key: 'storage_location', type: 'text', labelKey: 'testObject.storageLocation', hintKey: 'testObject.storageLocationHint' },
          { key: 'storage_expiry', type: 'date', labelKey: 'testObject.storageExpiry' },
          { key: 'storage_conditions', type: 'textarea', labelKey: 'testObject.storageConditions', rows: 2 },
          { key: 'transport_conditions', type: 'textarea', labelKey: 'testObject.transportConditions', rows: 2 },
          { key: 'accompanying_documents', type: 'textarea', labelKey: 'testObject.documents', col: 2, rows: 2 },
        ],
      },
    ],
  },
}

/* ======================= PROBALAR ======================= */
export const sampleResource = {
  key: 'samples',
  endpoint: '/samples',
  permission: 'samples',
  exportEndpoint: '/reports/samples',
  titleKey: 'sample.title',
  subtitleKey: 'sample.subtitle',
  itemTitleKey: 'sample.one',
  createLabelKey: 'sample.register',
  rowTitle: (r) => r?.code,
  defaultSort: { by: 'registered_at', dir: 'desc' },

  columns: [
    { key: 'code', labelKey: 'sample.code', mono: true, primary: true, sortable: true },
    { key: 'sample_number', labelKey: 'sample.number', sortable: true },
    { key: 'customer_group', labelKey: 'sample.customerGroup', type: 'localized', base: 'name', nowrap: false },
    { key: 'assigned_group', labelKey: 'sample.assignedGroup', type: 'localized', base: 'name', nowrap: false },
    { key: 'state_of_matter', labelKey: 'sample.stateOfMatter',
      format: (r, { t }) => t(`sample.${r.state_of_matter}`) },
    { key: 'registered_at', labelKey: 'sample.registeredAt', type: 'datetime', sortable: true },
    { key: 'progress', labelKey: 'sample.progress', align: 'right' },
    { key: 'status', labelKey: 'status.label', type: 'status' },
  ],

  filters: [
    { key: 'period_month', type: 'select', labelKey: 'sample.month', options: monthOptions(12), nullLabelKey: 'common.all' },
    { key: 'status', type: 'select', labelKey: 'status.label', options: SAMPLE_STATUSES, nullLabelKey: 'common.all' },
    { key: 'customer_group_id', type: 'relation', labelKey: 'sample.customerGroup', source: 'customerGroups', nullLabelKey: 'common.all' },
    { key: 'assigned_group_id', type: 'relation', labelKey: 'sample.assignedGroup', source: 'executorGroups', nullLabelKey: 'common.all' },
    { key: 'state_of_matter', type: 'select', labelKey: 'sample.stateOfMatter', nullLabelKey: 'common.all',
      options: [{ value: 'solid', labelKey: 'sample.solid' }, { value: 'liquid', labelKey: 'sample.liquid' }] },
    { key: 'date_from', type: 'date', labelKey: 'common.dateFrom' },
    { key: 'date_to', type: 'date', labelKey: 'common.dateTo' },
    { key: 'my_group_only', type: 'switch', labelKey: 'sample.myGroupOnly' },
  ],

  form: {
    size: 'lg',
    sections: [
      {
        titleKey: 'sample.one',
        fields: [
          { key: 'sample_number', type: 'text', labelKey: 'sample.number', required: true },
          { key: 'state_of_matter', type: 'select', labelKey: 'sample.stateOfMatter', required: true, default: 'solid',
            options: [{ value: 'solid', labelKey: 'sample.solid' }, { value: 'liquid', labelKey: 'sample.liquid' }] },
          { key: 'source_document', type: 'text', labelKey: 'sample.sourceDocument' },
          { key: 'source_division', type: 'text', labelKey: 'sample.sourceDivision' },
          { key: 'customer_group_id', type: 'relation', labelKey: 'sample.customerGroup', source: 'customerGroups', required: true },
          { key: 'assigned_group_id', type: 'relation', labelKey: 'sample.assignedGroup', source: 'executorGroups' },
          { key: 'test_profile_id', type: 'relation', labelKey: 'profile.one', hintKey: 'profile.applied',
            source: 'testProfiles', col: 2 },
          { key: 'element_ids', type: 'multiselect', labelKey: 'sample.elements', hintKey: 'sample.elementsHint',
            source: 'elements', fromRelation: null, col: 2 },
          { key: 'notes', type: 'textarea', labelKey: 'equipment.notes', col: 2, rows: 2 },
        ],
      },
    ],
  },

  // Tahrirlashda elementlar ro'yxati o'zgarmaydi (natijalar bog'langan)
  beforeSave: (payload, editing) => {
    if (editing) delete payload.element_ids
  },
}

/* ======================= SUV LABORATORIYASI ======================= */
export const waterOrderResource = {
  key: 'water_orders',
  endpoint: '/water-orders',
  permission: 'water_orders',
  exportEndpoint: '/reports/water-orders',
  titleKey: 'water.title',
  subtitleKey: 'water.subtitle',
  itemTitleKey: 'water.one',
  rowTitle: (r) => r?.order_number,
  defaultSort: { by: 'registered_at', dir: 'desc' },

  columns: [
    { key: 'order_number', labelKey: 'water.orderNumber', mono: true, primary: true, sortable: true },
    { key: 'division', labelKey: 'water.division', nowrap: false },
    { key: 'assigned_group', labelKey: 'water.assignedGroup', type: 'localized', base: 'name', nowrap: false },
    { key: 'monitoring_point.name', labelKey: 'water.monitoringPoint', nowrap: false },
    { key: 'registered_at', labelKey: 'water.registeredAt', type: 'datetime', sortable: true },
    { key: 'results_count', labelKey: 'water.results', align: 'right' },
    { key: 'status', labelKey: 'status.label', type: 'status' },
  ],

  filters: [
    { key: 'period_month', type: 'select', labelKey: 'sample.month', options: monthOptions(12), nullLabelKey: 'common.all' },
    { key: 'status', type: 'select', labelKey: 'status.label', nullLabelKey: 'common.all',
      options: [
        { value: 'registered', labelKey: 'status.registered' },
        { value: 'in_progress', labelKey: 'status.in_progress' },
        { value: 'completed', labelKey: 'status.completed' },
        { value: 'archived', labelKey: 'status.archived' },
      ] },
    { key: 'assigned_group_id', type: 'relation', labelKey: 'water.assignedGroup', source: 'executorGroups', nullLabelKey: 'common.all' },
    { key: 'monitoring_point_id', type: 'relation', labelKey: 'water.monitoringPoint', source: 'monitoringPoints',
      optionLabel: (o) => `${o.code} · ${o.name}`, nullLabelKey: 'common.all' },
  ],

  form: {
    size: 'md',
    sections: [
      {
        titleKey: 'water.one',
        fields: [
          { key: 'source_document', type: 'text', labelKey: 'water.sourceDocument' },
          { key: 'division', type: 'text', labelKey: 'water.division' },
          { key: 'assigned_group_id', type: 'relation', labelKey: 'water.assignedGroup', source: 'executorGroups', required: true },
          { key: 'monitoring_point_id', type: 'relation', labelKey: 'water.monitoringPoint', source: 'monitoringPoints',
            optionLabel: (o) => `${o.code} · ${o.name}` },
          { key: 'copy_point_parameters', type: 'switch', labelKey: 'water.copyParams',
            hintKey: 'water.copyParamsHint', default: true, col: 2 },
          { key: 'notes', type: 'textarea', labelKey: 'equipment.notes', col: 2, rows: 2 },
        ],
      },
    ],
  },
}

/* ======================= MONITORING NUQTALARI ======================= */
export const monitoringPointResource = {
  key: 'monitoring_points',
  endpoint: '/monitoring-points',
  permission: 'monitoring_points',
  titleKey: 'monitoring.title',
  subtitleKey: 'monitoring.subtitle',
  itemTitleKey: 'monitoring.one',
  rowTitle: (r) => r?.name,
  defaultSort: { by: 'name', dir: 'asc' },

  columns: [
    { key: 'code', labelKey: 'monitoring.code', mono: true, primary: true },
    { key: 'name', labelKey: 'monitoring.name', nowrap: false },
    { key: 'type', labelKey: 'monitoring.type', format: (r, { t }) => t(`monitoring.${r.type}`) },
    { key: 'location', labelKey: 'monitoring.location', nowrap: false },
    { key: 'group', labelKey: 'monitoring.group', type: 'localized', base: 'name', nowrap: false },
    { key: 'is_active', labelKey: 'catalog.isActive', type: 'bool' },
  ],

  filters: [
    { key: 'type', type: 'select', labelKey: 'monitoring.type', nullLabelKey: 'common.all',
      options: [
        { value: 'water', labelKey: 'monitoring.water' },
        { value: 'air', labelKey: 'monitoring.air' },
        { value: 'radiation', labelKey: 'monitoring.radiation' },
      ] },
  ],

  form: {
    size: 'md',
    sections: [
      {
        fields: [
          { key: 'code', type: 'text', labelKey: 'monitoring.code', required: true },
          { key: 'type', type: 'select', labelKey: 'monitoring.type', required: true, default: 'water',
            options: [
              { value: 'water', labelKey: 'monitoring.water' },
              { value: 'air', labelKey: 'monitoring.air' },
              { value: 'radiation', labelKey: 'monitoring.radiation' },
            ] },
          { key: 'name', type: 'text', labelKey: 'monitoring.name', required: true, col: 2 },
          { key: 'location', type: 'text', labelKey: 'monitoring.location', col: 2 },
          { key: 'laboratory_group_id', type: 'relation', labelKey: 'monitoring.group', source: 'executorGroups' },
          { key: 'is_active', type: 'switch', labelKey: 'catalog.isActive', default: true },
          { key: 'latitude', type: 'number', labelKey: 'monitoring.latitude', step: '0.0000001' },
          { key: 'longitude', type: 'number', labelKey: 'monitoring.longitude', step: '0.0000001' },
        ],
      },
    ],
  },
}
