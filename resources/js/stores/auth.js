import { defineStore } from 'pinia'
// import api from '@/lib/api'
import api, { ensureCsrf, resetCsrf } from '@/lib/api'

export const useAuthStore = defineStore('auth', {
  state: () => ({
    user: null,
    permissions: [],
    menu: [],
    loading: false,
    booted: false,
  }),

  getters: {
    isAuthenticated: (s) => !!s.user,
    isAdmin: (s) => !!s.user?.is_admin,
    roleName: (s) => s.user?.role?.name ?? null,
  },

  actions: {
    // Ruxsat tekshiruvi — UI shu orqali tugma/menyularni yashiradi
    can(permission) {
      if (!this.user) return false
      if (this.user.is_admin) return true
      if (Array.isArray(permission)) return permission.some((p) => this.permissions.includes(p))
      return this.permissions.includes(permission)
    },

    canAny(prefix, abilities = ['view', 'create', 'update', 'delete']) {
      return abilities.some((a) => this.can(`${prefix}.${a}`))
    },

async login(credentials) {
  const { data } = await api.post('/login', credentials)

  // Login'da sessiya qayta yaratildi — CSRF tokenni yangilash shart
  await ensureCsrf(true)

  this.setUser(data.user)
  await this.fetchMenu()
  return data.user
},
    async fetchUser() {
      this.loading = true
      try {
        const { data } = await api.get('/me')
        this.setUser(data.user)
        await this.fetchMenu()
      } catch {
        this.user = null
        this.permissions = []
      } finally {
        this.loading = false
        this.booted = true
      }
    },

    async fetchMenu() {
      try {
        const { data } = await api.get('/menu')
        this.menu = data
      } catch {
        this.menu = []
      }
    },
    setUser(user) {
      this.user = user
      this.permissions = user?.permissions ?? []
    },

async logout() {
  try { await api.post('/logout') } catch {}
  resetCsrf()
  this.user = null
  this.permissions = []
  this.menu = []
},
    async savePreferences(prefs) {
      if (!this.user) return
      const { data } = await api.put('/preferences', prefs)
      this.user.locale = data.locale
      this.user.theme = data.theme
    },
  },
})
