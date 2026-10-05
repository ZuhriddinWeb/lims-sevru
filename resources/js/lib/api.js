import axios from 'axios'

const api = axios.create({
  baseURL: '/api',
  withCredentials: true,
  withXSRFToken: true,
  headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
})

let csrfReady = null

// CSRF cookie olish. force = true bo'lsa, keshni tashlab yangisini oladi.
export function ensureCsrf(force = false) {
  if (force) csrfReady = null
  if (!csrfReady) {
    csrfReady = axios.get('/sanctum/csrf-cookie', { withCredentials: true })
  }
  return csrfReady
}

// Sessiya qayta yaratilganda (login/logout) tokenni majburan yangilash
export function resetCsrf() {
  csrfReady = null
}

api.interceptors.request.use(async (config) => {
  if (['post', 'put', 'patch', 'delete'].includes(config.method)) {
    await ensureCsrf()
  }
  return config
})

api.interceptors.response.use(
  (r) => r,
  async (error) => {
    const status = error.response?.status
    const config = error.config

    // Token eskirgan — cookie'ni yangilab, so'rovni bir marta qaytarish
    if (status === 419 && config && !config._csrfRetry) {
      config._csrfRetry = true
      await ensureCsrf(true)
      return api(config)
    }

    if (status === 401 && !window.location.pathname.startsWith('/login')) {
      window.dispatchEvent(new CustomEvent('lims:unauthenticated'))
    }
    if (status === 403) {
      window.dispatchEvent(new CustomEvent('lims:forbidden', { detail: error.response?.data }))
    }
    return Promise.reject(error)
  }
)

export default api