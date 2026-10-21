/**
 * Buyurtmachi bo'limlar / buyurtmachi portali / proba qoldiqlari — marshrutlar.
 * resources/js/router/index.js ga 3 joyda qo'shiladi (aniq qatorlar — OQISH.txt da):
 *   1) yuqorida: intakeRoutes (default) va installPortalGuard ni shu fayldan olish;
 *   2) AppLayout children ichida: ...intakeRoutes (not-found qatoridan oldin);
 *   3) oxirida, asosiy router.beforeEach'dan keyin: installPortalGuard(router)
 */
import './i18n'
import { installSampleIntakeForm } from './sampleForm'
export { installPortalGuard } from './guard'

// Proba ro'yxatga olish formasiga «Buyurtmachi va miqdor» bo'limi (lab.js ga tegmasdan)
installSampleIntakeForm()

// Buyurtmachi (portal.view) yoki ko'rib chiquvchi xodim (customer_departments.view)
const PORTAL = ['portal.view', 'customer_departments.view']
// [P24] Buyurtmalar: buyurtmachi (portal.request), faqat ko'ruvchi buyurtmachi, ko'rib chiquvchi xodim
const PORTAL_REQ = ['portal.request', 'portal.view', 'customer_departments.view']

export default [
  {
    path: 'portal', name: 'portal',
    component: () => import('./views/PortalHome.vue'),
    meta: { permission: PORTAL },
  },
  {
    path: 'portal/samples/:id', name: 'portal-sample',
    component: () => import('./views/PortalSample.vue'),
    meta: { permission: PORTAL },
  },
  /* ---- [P24] Buyurtma so'rovlari (qoralama buyurtma) ---- */
  {
    path: 'portal/requests', name: 'portal-requests',
    component: () => import('./requests/views/PortalRequests.vue'),
    meta: { permission: PORTAL_REQ },
  },
  {
    path: 'portal/requests/new', name: 'portal-request-new',
    component: () => import('./requests/views/RequestEditor.vue'),
    meta: { permission: 'portal.request', mode: 'portal' },
  },
  {
    path: 'portal/requests/:id(\\d+)', name: 'portal-request',
    component: () => import('./requests/views/RequestEditor.vue'),
    meta: { permission: PORTAL_REQ, mode: 'portal' },
  },
  {
    path: 'sample-requests', name: 'sample-requests',
    component: () => import('./requests/views/LabRequests.vue'),
    meta: { permission: 'sample_requests.view' },
  },
  {
    path: 'sample-requests/new', name: 'sample-request-new',
    component: () => import('./requests/views/RequestEditor.vue'),
    meta: { permission: 'sample_requests.create', mode: 'lab' },
  },
  {
    path: 'sample-requests/:id(\\d+)', name: 'sample-request',
    component: () => import('./requests/views/LabRequestDetail.vue'),
    meta: { permission: ['sample_requests.view', 'sample_requests.create'] },
  },
  {
    path: 'sample-requests/:id(\\d+)/edit', name: 'sample-request-edit',
    component: () => import('./requests/views/RequestEditor.vue'),
    meta: { permission: 'sample_requests.create', mode: 'lab' },
  },
  {
    path: 'customer-departments', name: 'customer-departments',
    component: () => import('./views/CustomerDepartmentList.vue'),
    meta: { permission: 'customer_departments.view' },
  },
  {
    path: 'sample-balances', name: 'sample-balances',
    component: () => import('./views/SampleBalanceReport.vue'),
    meta: { permission: 'samples.view' },
  },
]
