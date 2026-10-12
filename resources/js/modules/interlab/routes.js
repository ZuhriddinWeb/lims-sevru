/**
 * Laboratoriyalararo tekshiruv moduli marshrutlari.
 * resources/js/router/index.js da AppLayout children ichiga qo'shiladi:
 *
 *   import interlabRoutes from '@/modules/interlab/routes'
 *   ...
 *   children: [
 *     ...
 *     ...interlabRoutes,
 *     { path: ':pathMatch(.*)*', ... }   // not-found doim oxirida
 *   ]
 */
export default [
  {
    // :id — eslatmadan kelganda shu laboratoriya ajratib ko'rsatiladi
    path: 'interlab/network/:id?', name: 'interlab-network',
    component: () => import('./views/InterlabNetwork.vue'),
    meta: { permission: 'interlab.manage' },
  },
  {
    path: 'interlab', name: 'interlab',
    component: () => import('./views/InterlabList.vue'),
    meta: { permission: 'interlab.view' },
  },
  {
    path: 'interlab/:id(\\d+)', name: 'interlab-detail',
    component: () => import('./views/InterlabDetail.vue'),
    meta: { permission: 'interlab.view' },
  },
]
