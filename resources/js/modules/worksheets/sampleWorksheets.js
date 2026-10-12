/**
 * Probalar jadvali uchun: har bir qator o'z probasining varaqlarini so'raydi,
 * lekin so'rovlar bitta paketga yig'iladi — sahifaga bitta GET /worksheets/by-samples.
 * Natija 30 soniya keshlanadi; varaq yaratilganda invalidate() chaqiriladi.
 */
import { reactive } from 'vue'
import api from '@/lib/api'

const TTL = 30000
const state = reactive({}) // sampleId → { items: [], at: timestamp }
const pending = new Set()
let timer = null

async function flush() {
  timer = null
  const ids = [...pending]
  pending.clear()
  if (!ids.length) return
  for (let i = 0; i < ids.length; i += 200) {
    const chunk = ids.slice(i, i + 200)
    try {
      const { data } = await api.get('/worksheets/by-samples', { params: { ids: chunk.join(',') } })
      const now = Date.now()
      for (const id of chunk) state[id] = { items: data?.[id] ?? [], at: now }
    } catch {
      // ruxsat yo'q yoki tarmoq xatosi — tugma shunchaki ko'rinmaydi
      for (const id of chunk) state[id] = { items: [], at: Date.now() }
    }
  }
}

export function requestSampleWorksheets(sampleId) {
  const id = Number(sampleId)
  if (!id) return
  const entry = state[id]
  if (entry && Date.now() - entry.at < TTL) return
  pending.add(id)
  if (!timer) timer = setTimeout(flush, 25)
}

export function sampleWorksheets(sampleId) {
  return state[Number(sampleId)]?.items ?? []
}

export function invalidateSampleWorksheets(sampleId) {
  if (sampleId === undefined) {
    Object.keys(state).forEach((k) => delete state[k])
  } else {
    delete state[Number(sampleId)]
  }
}

/* ---------- Faol shablonlar keshi (ro'yxatga olish oynasi uchun, 60 soniya) ---------- */
let tplCache = { items: [], at: 0 }
let tplLoading = null

export async function loadActiveTemplates(force = false) {
  if (!force && tplCache.at && Date.now() - tplCache.at < 60000) return tplCache.items
  if (!tplLoading) {
    tplLoading = api.get('/worksheet-templates', { params: { active: 1 } })
      .then(({ data }) => { tplCache = { items: data, at: Date.now() }; return data })
      .catch(() => tplCache.items)
      .finally(() => { tplLoading = null })
  }
  return tplLoading
}
