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
