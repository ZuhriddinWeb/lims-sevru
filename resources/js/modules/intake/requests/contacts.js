/**
 * [Paket 34] Bo'linma xodimlari ma'lumotnomasi — kombobox uchun yordamchilar.
 * Asosiy tekshiruv serverda (CustomerContacts.php); bu yerda — qidiruv va telefonni darhol ko'rsatish uchun.
 */
import api from '@/lib/api'

const CYR = {
  щ: 'sh', ш: 'sh', ч: 'ch', ж: 'j', ц: 'ts', ё: 'yo', ю: 'yu', я: 'ya', ў: 'o', ғ: 'g', қ: 'q', ҳ: 'h', х: 'x',
  ъ: '', ь: '', ы: 'i', э: 'e', й: 'y', а: 'a', б: 'b', в: 'v', г: 'g', д: 'd', е: 'e', з: 'z', и: 'i', к: 'k',
  л: 'l', м: 'm', н: 'n', о: 'o', п: 'p', р: 'r', с: 's', т: 't', у: 'u', ф: 'f',
}

/** Qidiruv uchun so'zlar: lotin, kichik harf, tinish belgilarisiz */
export function searchTokens(s) {
  const low = String(s ?? '').toLowerCase()
  let out = ''
  for (const ch of low) out += CYR[ch] ?? ch
  return out.replace(/['`ʻʼ‘’.]/g, '').split(/[^\p{L}\p{N}]+/u).filter(Boolean)
}

/** Qidiruv: har yozilgan so'z biror so'zning boshi bo'lsin (x/h, q/k farqisiz) */
const loose = (w) => w.replace(/kh|x/g, 'h').replace(/q/g, 'k').replace(/w/g, 'v')
export function matches(contact, query) {
  const q = searchTokens(query).map(loose)
  if (!q.length) return true
  const words = searchTokens(contact.name).map(loose)
  const phone = String(contact.phone ?? '').replace(/\D/g, '')
  return q.every((w) => words.some((x) => x.startsWith(w)) || (/^\d+$/.test(w) && phone.includes(w)))
}

/** Telefon (serverdagi bilan bir xil qoida): mobil → +998 90 123-45-67, 2–7 raqam → ichki, boshqasi → other */
export function phoneInfo(s) {
  const t = String(s ?? '').replace(/\s+/g, ' ').trim()
  if (!t) return { value: '', digits: '', kind: '' }
  const d = t.replace(/\D/g, '')
  let n = null
  if (d.length === 12 && d.startsWith('998')) n = d.slice(3)
  else if (d.length === 9) n = d
  else if (d.length === 10 && d[0] === '8') n = d.slice(1)
  if (n && !/[^\d\s\-+()]/.test(t)) {
    return { value: `+998 ${n.slice(0, 2)} ${n.slice(2, 5)}-${n.slice(5, 7)}-${n.slice(7, 9)}`, digits: `998${n}`, kind: 'mobile' }
  }
  if (d.length >= 2 && d.length <= 7 && /^[\d\s\-()]+$/.test(t)) return { value: t, digits: d, kind: 'internal' }
  return { value: t, digits: d, kind: 'other' }
}

/* ---------- bo'lim ro'yxati keshi (sahifalar orasida) ---------- */
const cache = new Map() // deptId → Promise<{data, can_write}>

export function loadContacts(deptId, force = false) {
  const id = Number(deptId)
  if (!id) return Promise.resolve({ data: [], can_write: false })
  if (force || !cache.has(id)) {
    const p = api.get('/portal/contacts', { params: { department_id: id } })
      .then((r) => ({ data: r.data.data ?? [], can_write: !!r.data.can_write }))
      .catch(() => ({ data: [], can_write: false, failed: true }))
    cache.set(id, p)
    p.then((r) => { if (r.failed) cache.delete(id) })
  }
  return cache.get(id)
}

export function forgetContacts(deptId) {
  if (deptId) cache.delete(Number(deptId))
  else cache.clear()
}

export function checkContact(deptId, name, phone) {
  return api.post('/portal/contacts/check', { department_id: Number(deptId) || null, name, phone }).then((r) => r.data)
}

/**
 * Yuborishdan keyingi xabar uchun: ro'yxatda yangi xodim qo'shildimi yoki telefon yangilandimi
 * (yuborishdan oldingi kesh bilan solishtiriladi, keyin kesh tozalanadi).
 * @return {Promise<{type: 'added'|'phone', name: string}|null>}
 */
export async function contactNotice(deptId, name, phone) {
  const k = searchTokens(name).join(' ')
  if (!Number(deptId) || !k) return null
  const { data, failed } = await loadContacts(deptId)
  forgetContacts(deptId)
  if (failed) return null
  const c = data.find((x) => searchTokens(x.name).join(' ') === k)
  if (!c) return { type: 'added', name }
  const p = phoneInfo(phone)
  if (p.digits && phoneInfo(c.phone).digits !== p.digits) return { type: 'phone', name: c.name }
  return null
}
