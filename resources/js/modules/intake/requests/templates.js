/**
 * [Paket 33] Buyurtma shabloni — forma ↔ shablon (Vue'siz yordamchilar).
 * Shablonga sanalar (olib kelish sanasi, svodka sanasi va navbatchiligi) saqlanmaydi.
 */
import { blankItem, toPayload } from './shared'

/** Joriy buyurtma formasidan shablon payload'i */
export function templateFromForm(form) {
  const p = toPayload(form)
  return {
    laboratory_group_id: p.laboratory_group_id,
    delivery_shift_id: p.delivery_shift_id ? Number(p.delivery_shift_id) : null,
    contact_name: p.contact_name,
    contact_phone: p.contact_phone,
    notes: p.notes,
    items: p.items.map((i) => ({
      customer_code: i.customer_code,
      state_of_matter: i.state_of_matter,
      test_profile_id: i.test_profile_id,
      element_ids: i.element_ids,
      quantity: i.quantity,
      quantity_unit: i.quantity_unit,
      notes: i.notes,
      svodka_point_id: i.svodka_point_id,
      group_code: i.group_code,
    })),
  }
}

/** Shablondan tahrirlash formasi (shablon muharriri uchun) */
export function formFromTemplate(tpl) {
  const p = tpl?.payload ?? {}
  return {
    department_id: tpl?.department_id ?? '',
    delivery_date: '', svodka_date: '', svodka_shift: '',
    delivery_shift_id: p.delivery_shift_id ?? '',
    laboratory_group_id: p.laboratory_group_id ?? '',
    contact_name: p.contact_name ?? '',
    contact_phone: p.contact_phone ?? '',
    notes: p.notes ?? '',
    items: (p.items ?? []).map((i) => blankItem({
      customer_code: i.customer_code ?? '',
      state_of_matter: i.state_of_matter || 'solid',
      test_profile_id: i.test_profile_id ?? null,
      element_ids: [...(i.element_ids ?? [])],
      quantity: i.quantity ?? '',
      quantity_unit: i.quantity_unit || 'g',
      notes: i.notes ?? '',
      svodka_point_id: i.svodka_point_id ?? '',
      group_code: i.group_code ?? '',
    })),
  }
}
