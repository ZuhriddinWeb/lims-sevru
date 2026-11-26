/**
 * [Paket 44] Administratsiya → «Markaziy portal» (central.manage — admin).
 */
export default [
  {
    path: 'central-link', name: 'central-link',
    component: () => import('./views/CentralLink.vue'),
    meta: { permission: 'central.manage' },
  },
]
