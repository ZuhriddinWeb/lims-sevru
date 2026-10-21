/**
 * Buyurtma so'rovlari — umumiy yordamchilar (Vue'siz). [Paket 24]
 */
import { formatDate, formatDateTime } from '@/lib/format'

export const STATUS_TONE = {
  draft: 'bg-surface-sunken text-ink-muted dark:bg-white/5',
  submitted: 'bg-aqua-50 text-aqua-700 dark:bg-aqua-800/30 dark:text-aqua-200',
  partial: 'bg-warn-light text-warn-dark dark:bg-warn-dark/25 dark:text-warn-light',
  received: 'bg-ok-light text-ok-dark dark:bg-ok-dark/25 dark:text-ok-light',
  rejected: 'bg-crit-light text-crit-dark dark:bg-crit-dark/25 dark:text-crit-light',
  cancelled: 'bg-surface-sunken text-ink-faint line-through dark:bg-white/5',
  expired: 'bg-crit-light text-crit-dark dark:bg-crit-dark/25 dark:text-crit-light',
}

export const ITEM_TONE = {
  pending: 'text-ink-muted',
  received: 'text-ok-dark dark:text-ok-light',
  rejected: 'text-crit line-through',
}

/** Joriy tildagi nom: name_uz / name_ru / name_en */
export function nameOf(item, locale) {
  if (!item) return ''
  return item[`name_${locale}`] || item.name_uz || item.name_ru || item.code || ''
}

export function blankItem(o = {}) {
  return {
    customer_code: '', state_of_matter: 'solid', test_profile_id: null, element_ids: [],
    quantity: '', quantity_unit: 'g', notes: '', ...o,
  }
}

/** Server javobidan tahrirlash formasi */
export function toForm(r) {
  return {
    department_id: r?.department?.id ?? '',
    delivery_date: r?.delivery_date ?? '',
    contact_name: r?.contact_name ?? '',
    contact_phone: r?.contact_phone ?? '',
    notes: r?.notes ?? '',
    items: (r?.items ?? []).map((i) => blankItem({
      customer_code: i.customer_code ?? '',
      state_of_matter: i.state_of_matter || 'solid',
      test_profile_id: i.test_profile_id ?? null,
      element_ids: [...(i.element_ids ?? [])],
      quantity: i.quantity ?? '',
      quantity_unit: i.quantity_unit || 'g',
      notes: i.notes ?? '',
    })),
  }
}

/** Formadan API ga */
export function toPayload(form) {
  return {
    department_id: form.department_id || null,
    delivery_date: form.delivery_date || null,
    contact_name: form.contact_name || null,
    contact_phone: form.contact_phone || null,
    notes: form.notes || null,
    items: form.items.map((i) => ({
      customer_code: (i.customer_code || '').trim() || null,
      state_of_matter: i.state_of_matter || 'solid',
      test_profile_id: i.test_profile_id || null,
      element_ids: i.element_ids ?? [],
      quantity: i.quantity === '' || i.quantity === null ? null : String(i.quantity).replace(',', '.'),
      quantity_unit: i.quantity === '' || i.quantity === null ? null : i.quantity_unit,
      notes: (i.notes || '').trim() || null,
    })),
  }
}

/** Validatsiya xatosi: items.2.element_ids → qatorga */
export function fieldError(errors, key) {
  const v = errors?.[key]
  return Array.isArray(v) ? v[0] : v || ''
}

export function apiErrorMessage(e, fallback) {
  const d = e?.response?.data
  if (d?.errors) {
    const first = Object.values(d.errors).flat()[0]
    if (d.message && String(d.message).startsWith('Qoralama')) return d.message
    if (first) return first
  }
  return d?.message || fallback
}

const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]))

/**
 * Namuna topshirish varaqasi — alohida oynada chop etiladi (asosiy sahifa joylashuviga bog'liq emas).
 * Buyurtmachi namuna bilan birga olib keladi; laborant raqam bo'yicha topadi.
 */
export function printSlip(r, t, locale) {
  const w = window.open('', '_blank', 'width=900,height=700')
  if (!w) return false
  const rows = (r.items ?? []).map((i) => `
    <tr>
      <td class="c">${i.position}</td>
      <td><b>${esc(i.customer_code || '—')}</b></td>
      <td>${esc(t(`sreq.form.states.${i.state_of_matter}`))}</td>
      <td>${esc([i.profile ? nameOf(i.profile, locale) : '', (i.elements ?? []).map((e) => e.symbol).join(', ')].filter(Boolean).join(' · '))}</td>
      <td class="r">${i.quantity !== null && i.quantity !== undefined ? esc(i.quantity) + ' ' + esc(i.quantity_unit || '') : ''}</td>
      <td>${esc(i.notes || '')}</td>
      <td class="c">${i.sample ? esc(i.sample.code) : '☐'}</td>
    </tr>`).join('')
  const meta = [
    [t('sreq.col.department'), r.department ? `${r.department.name}${r.department.code ? ` (${r.department.code})` : ''}` : ''],
    [t('sreq.info.submitted'), formatDateTime(r.submitted_at)],
    [t('sreq.col.delivery'), r.delivery_date ? formatDate(r.delivery_date) : '—'],
    [t('sreq.col.contact'), [r.contact_name, r.contact_phone].filter(Boolean).join(', ') || '—'],
    [t('sreq.form.notes'), r.notes || ''],
  ].filter(([, v]) => v)
  w.document.write(`<!doctype html><html lang="${locale}"><head><meta charset="utf-8">
<title>${esc(r.number || '')}</title>
<style>
  @page { size: A4; margin: 14mm; }
  body { font: 12.5px/1.45 system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif; color: #111; margin: 0; padding: 20px; }
  .head { display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; border-bottom: 2px solid #111; padding-bottom: 10px; }
  h1 { font-size: 17px; margin: 0 0 4px; }
  .num { font: 700 26px/1 ui-monospace, Menlo, Consolas, monospace; letter-spacing: .02em; border: 2px solid #111; padding: 8px 12px; }
  dl { display: grid; grid-template-columns: max-content 1fr; gap: 3px 14px; margin: 12px 0; }
  dt { color: #555; } dd { margin: 0; }
  table { width: 100%; border-collapse: collapse; margin-top: 8px; }
  th, td { border: 1px solid #999; padding: 5px 7px; vertical-align: top; text-align: left; }
  th { background: #f2f2f2; font-weight: 600; font-size: 11.5px; }
  .c { text-align: center; } .r { text-align: right; white-space: nowrap; }
  .sign { display: grid; grid-template-columns: 1fr 1fr; gap: 40px; margin-top: 34px; }
  .sign div { border-top: 1px solid #111; padding-top: 4px; font-size: 11.5px; color: #444; }
  .foot { margin-top: 18px; font-size: 11.5px; color: #555; }
  @media print { body { padding: 0; } }
</style></head><body>
  <div class="head">
    <div><h1>${esc(t('sreq.slip.title'))}</h1><div>${esc(r.department?.name || '')}</div></div>
    <div class="num">${esc(r.number || '')}</div>
  </div>
  <dl>${meta.map(([k, v]) => `<dt>${esc(k)}</dt><dd>${esc(v)}</dd>`).join('')}</dl>
  <table>
    <thead><tr>
      <th class="c">№</th><th>${esc(t('sreq.form.code'))}</th><th>${esc(t('sreq.form.state'))}</th>
      <th>${esc(t('sreq.form.what'))}</th><th class="r">${esc(t('sreq.form.quantity'))}</th>
      <th>${esc(t('sreq.form.itemNotes'))}</th><th class="c">✓</th>
    </tr></thead>
    <tbody>${rows}</tbody>
  </table>
  <div class="sign">
    <div>${esc(t('sreq.slip.handed'))}: ${esc(r.contact_name || '')} &nbsp; ____________ (${esc(t('sreq.slip.sign'))})</div>
    <div>${esc(t('sreq.slip.accepted'))}: ______________________ (${esc(t('sreq.slip.sign'))})</div>
  </div>
  <p class="foot">${esc(t('sreq.slip.footer'))}</p>
  <script>window.onload = function () { window.focus(); window.print(); }<\/script>
</body></html>`)
  w.document.close()
  return true
}
