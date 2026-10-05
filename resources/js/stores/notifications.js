import { defineStore } from 'pinia'
import api from '@/lib/api'

export const useNotificationStore = defineStore('notifications', {
  state: () => ({
    items: [],
    unreadCount: 0,
    loading: false,
    timer: null,
  }),

  actions: {
    async fetch() {
      this.loading = true
      try {
        const { data } = await api.get('/notifications')
        this.items = data.items ?? []
        this.unreadCount = data.unread_count ?? 0
      } catch {
        this.items = []
        this.unreadCount = 0
      } finally {
        this.loading = false
      }
    },

    async markRead(id) {
      const item = this.items.find((n) => n.id === id)
      if (!item || item.read_at) return
      item.read_at = new Date().toISOString()
      this.unreadCount = Math.max(0, this.unreadCount - 1)
      try { await api.patch(`/notifications/${id}/read`) } catch {}
    },

    async markAllRead() {
      this.items.forEach((n) => { n.read_at = n.read_at ?? new Date().toISOString() })
      this.unreadCount = 0
      try { await api.patch('/notifications/read-all') } catch {}
    },

    // Har 2 daqiqada yangilab turish
    startPolling() {
      this.fetch()
      if (this.timer) return
      this.timer = setInterval(() => this.fetch(), 120000)
    },

    stopPolling() {
      if (this.timer) {
        clearInterval(this.timer)
        this.timer = null
      }
    },
  },
})
