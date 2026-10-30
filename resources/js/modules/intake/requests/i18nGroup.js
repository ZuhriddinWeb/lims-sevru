/**
 * [Paket 30] Buyurtma guruhga: guruh tanlash, guruh shifri, guruh bo'yicha navbat — tarjimalar.
 * requests/i18n.js ga tegmasdan qo'shiladi (mergeLocaleMessage).
 */
import { i18n } from '@/i18n'

const uz = {
  sreq: {
    group: {
      label: 'Laboratoriya guruhi',
      choose: 'Guruhni tanlang',
      hint: 'Namunani shu guruh qabul qiladi va tahlil qiladi',
      code: 'Guruh shifri',
      codeHint: "Faqat siz va tanlangan guruh ko'radi",
      codeShort: 'Guruh shifri',
      col: 'Guruh',
      all: 'Barcha guruhlar',
      none: 'Guruhsiz (eski buyurtmalar)',
      mine: 'Guruhingiz: {name}',
      onlyMine: "Faqat guruhingizga yuborilgan buyurtmalar ko'rinadi",
      sumPending: 'Kutilmoqda: {n} ta namuna ({r} ta buyurtma)',
      sumReceived: 'Qabul qilingan: {n} ta namuna ({r} ta buyurtma)',
      sumItems: 'Jami: {n} ta namuna ({r} ta buyurtma)',
    },
  },
  intake: { panel: { groupCode: 'Guruh shifri' } },
}

const ru = {
  sreq: {
    group: {
      label: 'Группа лаборатории',
      choose: 'Выберите группу',
      hint: 'Пробу примет и проанализирует эта группа',
      code: 'Шифр группы',
      codeHint: 'Видят только вы и выбранная группа',
      codeShort: 'Шифр группы',
      col: 'Группа',
      all: 'Все группы',
      none: 'Без группы (старые заявки)',
      mine: 'Ваша группа: {name}',
      onlyMine: 'Видны только заявки, отправленные вашей группе',
      sumPending: 'Ожидается: проб {n} (заявок {r})',
      sumReceived: 'Принято: проб {n} (заявок {r})',
      sumItems: 'Всего: проб {n} (заявок {r})',
    },
  },
  intake: { panel: { groupCode: 'Шифр группы' } },
}

const en = {
  sreq: {
    group: {
      label: 'Laboratory group',
      choose: 'Choose a group',
      hint: 'This group receives and analyses the samples',
      code: 'Group code',
      codeHint: 'Visible only to you and the chosen group',
      codeShort: 'Group code',
      col: 'Group',
      all: 'All groups',
      none: 'No group (old requests)',
      mine: 'Your group: {name}',
      onlyMine: 'Only requests sent to your group are shown',
      sumPending: 'Pending: {n} samples ({r} requests)',
      sumReceived: 'Received: {n} samples ({r} requests)',
      sumItems: 'Total: {n} samples ({r} requests)',
    },
  },
  intake: { panel: { groupCode: 'Group code' } },
}

let merged = false
export function ensureGroupI18n() {
  if (merged) return
  i18n.global.mergeLocaleMessage('uz', uz)
  i18n.global.mergeLocaleMessage('ru', ru)
  i18n.global.mergeLocaleMessage('en', en)
  merged = true
}
ensureGroupI18n()
