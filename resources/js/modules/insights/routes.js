/**
 * Ish stoli, rahbariyat paneli 2.0, smenalar, xodimlar faoliyati — marshrutlar.
 * resources/js/router/index.js ga 2 joyda qo'shiladi (aniq qatorlar — OQISH.txt da):
 *   1) yuqorida: insightsRoutes ni shu fayldan olish;
 *   2) AppLayout children ichida: ...insightsRoutes (asosiy «executive» qatoridan KEYIN, not-found dan oldin).
 *
 * «dashboard» va «executive» nomli marshrutlar shu yerda qayta e'lon qilingan: vue-router bir xil nomli
 * marshrutning oldingisini olib tashlaydi, shuning uchun menyudagi «Bosh sahifa» — Ish stolini,
 * «Rahbariyat paneli» — yangi panelni ochadi. Eski sahifalar o'chirilmaydi: /dashboard/classic, /executive/classic.
 */
import './i18n'

export default [
  /* [P18] Bosh sahifa — «Ish stoli» (rolga qarab). Eski bosh sahifa: /dashboard/classic */
  {
    path: 'dashboard', name: 'dashboard',
    component: () => import('./views/WorkDesk.vue'),
  },
  {
    path: 'dashboard/classic', name: 'dashboard-classic',
    component: () => import('@/views/dashboard/Dashboard.vue'),
  },
  {
    path: 'executive', name: 'executive',
    component: () => import('./views/ExecutiveDashboard.vue'),
    meta: { permission: 'executive.view' },
  },
  {
    path: 'executive/classic', name: 'executive-classic',
    component: () => import('@/views/dashboard/Executive.vue'),
    meta: { permission: 'executive.view' },
  },
  /* [P17] Xodimlar faoliyati — hamma kiradi: boshliq/rahbariyat — jadval, boshqalar — o'z sahifasi */
  {
    path: 'staff-activity', name: 'staff-activity',
    component: () => import('./views/StaffActivity.vue'),
  },
  {
    path: 'staff-activity/:id', name: 'staff-activity-user',
    component: () => import('./views/StaffDetail.vue'),
  },
  {
    path: 'lab-shifts', name: 'lab-shifts',
    component: () => import('./views/ShiftList.vue'),
    meta: { permission: 'shifts.manage' },
  },
]
