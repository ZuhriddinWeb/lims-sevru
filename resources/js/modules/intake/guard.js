/**
 * Buyurtmachi (portal) foydalanuvchisi faqat portal sahifalarini ochadi.
 * Server /api/me javobiga user.portal qo'shadi (CustomerPortalFence) — shu bo'yicha aniqlanadi.
 * Server tomonda ham devor bor: boshqa API'lar buyurtmachiga baribir yopiq.
 */
import { useAuthStore } from '@/stores/auth'
import { installSampleDetailPanel } from './sampleDetail'

// Buyurtmachiga ochiq sahifalar
export const PORTAL_PAGES = [
  'portal', 'portal-sample', 'profile', 'forbidden', 'not-found', 'login',
  'portal-requests', 'portal-request-new', 'portal-request', // [P24] Buyurtmalarim
  'portal-svodka', // [P29] Svodka
]

export function isPortalUser(user) {
  return !!user && !user.is_admin && !!user.portal
}

/** Sahifaga o'tish mumkinmi; yo'q bo'lsa — qayerga yo'naltirish */
export function portalRedirect(to, user) {
  if (!isPortalUser(user)) return null
  if (PORTAL_PAGES.includes(to?.name)) return null
  return { name: 'portal' }
}

export function installPortalGuard(router) {
  // Proba kartochkasiga «Qabul va proba miqdori» paneli (SampleDetail.vue ga tegmasdan)
  installSampleDetailPanel(router)

  router.beforeEach((to) => {
    const auth = useAuthStore()
    if (!auth.isAuthenticated) return true
    return portalRedirect(to, auth.user) ?? true
  })
}
