/**
 * [Paket 41] «Buyurtma sozlamalari»: grafik shifriga soat qo'shilishini yoqish/o'chirish.
 */
import { i18n } from '@/i18n'

const uz = {
  sreq: {
    p41: {
      codeTime: "Grafik shifriga soat qo'shilsin",
      codeTimeOn: "Yoqilgan: grafik buyurtmasida shifr «9999-17:00» ko'rinishida yoziladi.",
      codeTimeOff: "O'chirilgan: bazaga faqat grafikdagi shifr yoziladi («9999»). Shifr bo'sh bo'lsa — proba raqami buyurtma raqamidan beriladi.",
    },
  },
}
const ru = {
  sreq: {
    p41: {
      codeTime: 'Добавлять время к шифру графика',
      codeTimeOn: 'Включено: в заявке по графику шифр записывается как «9999-17:00».',
      codeTimeOff: 'Выключено: в базу записывается только шифр графика («9999»). Если шифр пустой — номер пробы формируется из номера заявки.',
    },
  },
}
const en = {
  sreq: {
    p41: {
      codeTime: 'Add the time to schedule codes',
      codeTimeOn: 'On: in a schedule request the code is saved as «9999-17:00».',
      codeTimeOff: 'Off: only the schedule code is saved («9999»). If the code is empty, the sample number is built from the request number.',
    },
  },
}

export function ensureSreqP41I18n() {
  i18n.global.mergeLocaleMessage('uz', uz)
  i18n.global.mergeLocaleMessage('ru', ru)
  i18n.global.mergeLocaleMessage('en', en)
}

ensureSreqP41I18n()
