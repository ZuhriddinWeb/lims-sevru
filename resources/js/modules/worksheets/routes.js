/**
 * Ishchi varaqlar moduli marshrutlari.
 * resources/js/router/index.js da AppLayout children ichiga qo'shiladi:
 *
 *   import worksheetRoutes from '@/modules/worksheets/routes'
 *   ...
 *   children: [
 *     ...
 *     ...worksheetRoutes,
 *     { path: ':pathMatch(.*)*', ... }   // not-found doim oxirida
 *   ]
 */
export default [
  {
    path: 'worksheets', name: 'worksheets',
    component: () => import('./views/WorksheetList.vue'),
    meta: { permission: 'worksheets.view' },
  },
  {
    path: 'worksheets/:id', name: 'worksheet-detail',
    component: () => import('./views/WorksheetEntry.vue'),
    meta: { permission: 'worksheets.view' },
  },
  {
    path: 'worksheets/:id/print', name: 'worksheet-print',
    component: () => import('./views/WorksheetPrint.vue'),
    meta: { permission: 'worksheets.view' },
  },
  {
    path: 'worksheet-templates', name: 'worksheet-templates',
    component: () => import('./views/TemplateList.vue'),
    meta: { permission: 'worksheet_templates.manage' },
  },
  {
    path: 'worksheet-templates/:id', name: 'worksheet-template-edit',
    component: () => import('./views/TemplateEditor.vue'),
    meta: { permission: 'worksheet_templates.manage' },
  },
]
