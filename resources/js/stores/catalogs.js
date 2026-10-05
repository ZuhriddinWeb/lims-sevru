import { defineStore } from 'pinia'
import api from '@/lib/api'

// Formalarda ishlatiladigan ro'yxatlar — bir marta yuklanadi va keshlanadi
const SOURCES = {
  laboratories:      { url: '/laboratories',      params: { all: 1 } },
  laboratoryGroups:  { url: '/laboratory-groups', params: { all: 1 } },
  customerGroups:    { url: '/laboratory-groups', params: { all: 1, kind: 'customer' } },
  executorGroups:    { url: '/laboratory-groups', params: { all: 1, kind: 'executor' } },
  elements:          { url: '/elements',          params: { all: 1 } },
  testProfiles:      { url: '/test-profiles',     params: { all: 1 } },
  usableEquipment:   { url: '/equipment',         params: { all: 1, usable: 1 } },
  roles:             { url: '/roles',             params: { all: 1 } },
  monitoringPoints:  { url: '/monitoring-points', params: { per_page: 200 } },
  menuItems:         { url: '/menu-items',        params: {} },
  permissions:       { url: '/permissions',       params: {} },
}

export const useCatalogStore = defineStore('catalogs', {
  state: () => ({
    data: {},
    loading: {},
  }),

  actions: {
    async load(name, force = false) {
      if (!SOURCES[name]) return []
      if (!force && this.data[name]) return this.data[name]
      if (this.loading[name]) return this.loading[name]

      const { url, params } = SOURCES[name]
      const promise = api.get(url, { params })
        .then(({ data }) => {
          const list = Array.isArray(data) ? data : (data.data ?? [])
          this.data[name] = list
          return list
        })
        .catch(() => {
          this.data[name] = []
          return []
        })
        .finally(() => { delete this.loading[name] })

      this.loading[name] = promise
      return promise
    },

    get(name) {
      return this.data[name] ?? []
    },

    invalidate(name) {
      if (name) delete this.data[name]
      else this.data = {}
    },
  },
})
