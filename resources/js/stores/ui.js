import { defineStore } from 'pinia'

let toastId = 0

export const useUiStore = defineStore('ui', {
  state: () => ({
    theme: localStorage.getItem('lims.theme') || (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light'),
    locale: localStorage.getItem('lims.locale') || 'ru',
    sidebarCollapsed: localStorage.getItem('lims.sidebar') === '1',
    sidebarMobileOpen: false,
    toasts: [],
  }),

  actions: {
    applyTheme() {
      document.documentElement.classList.toggle('dark', this.theme === 'dark')
      localStorage.setItem('lims.theme', this.theme)
    },

    setTheme(theme) {
      this.theme = theme
      this.applyTheme()
    },

    toggleTheme() {
      this.setTheme(this.theme === 'dark' ? 'light' : 'dark')
    },

    setLocale(locale) {
      this.locale = locale
      localStorage.setItem('lims.locale', locale)
      document.documentElement.lang = locale
    },

    toggleSidebar() {
      this.sidebarCollapsed = !this.sidebarCollapsed
      localStorage.setItem('lims.sidebar', this.sidebarCollapsed ? '1' : '0')
    },

    toast(message, type = 'success', timeout = 4000) {
      const id = ++toastId
      this.toasts.push({ id, message, type })
      setTimeout(() => this.dismiss(id), timeout)
      return id
    },

    success(m) { return this.toast(m, 'success') },
    error(m)   { return this.toast(m, 'error', 6000) },
    info(m)    { return this.toast(m, 'info') },

    dismiss(id) {
      this.toasts = this.toasts.filter((t) => t.id !== id)
    },
  },
})
