/**
 * [Paket 27] Buyurtmachi portali: nima ko'rinadi (admin sozlaydi) + natijalarni Excel'ga yuklash.
 * Tarjimalar intake/i18n.js ga tegmasdan qo'shiladi.
 */
import { i18n } from '@/i18n'
import api from '@/lib/api'

/** Server javobida visibility bo'lmasa (eski backend) — hammasi ochiq, avvalgidek */
export const DEFAULT_VISIBILITY = Object.freeze({
  steps: true,
  final_results: true,
  preliminary: true,
  quantity: true,
  analyses: true,
  analysis_details: true,
  comparison: true,
  totals: true, // [P28]
  export: true,
})

export function visibilityOf(obj) {
  return { ...DEFAULT_VISIBILITY, ...(obj?.visibility ?? {}) }
}

/** Sozlamalar sahifasidagi guruhlar; requires — qaysi sozlama yoqilgan bo'lishi kerak */
export const VISIBILITY_GROUPS = [
  { key: 'status', items: [{ key: 'steps' }] },
  { key: 'results', items: [{ key: 'final_results' }, { key: 'preliminary' }] },
  {
    key: 'analyses',
    items: [
      { key: 'analyses' },
      { key: 'analysis_details', requires: ['analyses'] },
      { key: 'comparison', requires: ['analyses', 'final_results'] },
      { key: 'totals', requires: ['analyses'] }, // [P28]
    ],
  },
  { key: 'other', items: [{ key: 'quantity' }, { key: 'export' }] },
]

/**
 * Excel'ni yuklab olish: sessiya cookie bilan (axios, blob). Xato bo'lsa — server xabari bilan Error.
 * params: { search, status, department_id, date_from, date_to }
 */
export async function downloadPortalExport(params, locale) {
  const clean = { locale }
  for (const [k, v] of Object.entries(params || {})) {
    if (v !== '' && v !== null && v !== undefined && !(k === 'status' && v === 'all')) clean[k] = v
  }
  let res
  try {
    res = await api.get('/portal/samples/export', { params: clean, responseType: 'blob' })
  } catch (e) {
    let message = ''
    const blob = e?.response?.data
    if (blob instanceof Blob) {
      try {
        const j = JSON.parse(await blob.text())
        message = j?.message || ''
      } catch { /* JSON emas */ }
    }
    const err = new Error(message || 'export failed')
    err.status = e?.response?.status
    err.serverMessage = message
    throw err
  }
  const cd = res.headers?.['content-disposition'] || ''
  const m = /filename\*?=(?:UTF-8'')?"?([^";]+)"?/i.exec(cd)
  const name = m ? decodeURIComponent(m[1]) : 'natijalar.xlsx'
  const url = URL.createObjectURL(res.data)
  const a = document.createElement('a')
  a.href = url
  a.download = name
  document.body.appendChild(a)
  a.click()
  a.remove()
  setTimeout(() => URL.revokeObjectURL(url), 4000)
  return name
}

const uz = {
  portalVis: {
    excel: 'Excel',
    excelHint: "Ro'yxatdagi namunalar natijalari (joriy filtr bo'yicha) — Excel fayl",
    excelBusy: 'Tayyorlanmoqda…',
    excelDone: 'Fayl yuklandi: {name}',
    excelError: "Faylni tayyorlab bo'lmadi",
    configure: 'Sozlash',
    configureHint: "Buyurtmachi nimani ko'rishini belgilash",
    hidden: 'yopiq',
    hiddenHint: "Natija tayyor, lekin laboratoriya uni portalda ko'rsatmaydi",
    // [P28] ro'yxatdagi natijalar va varaq jamisi
    resultsCol: 'Natijalar',
    progressCol: 'Tayyorligi',
    legendFinal: 'tasdiqlangan',
    legendPrelim: 'dastlabki',
    legendWaiting: 'kutilmoqda',
    chipFinal: 'Tasdiqlangan natija',
    chipPrelim: "Dastlabki — ishchi varaq {n} hali tasdiqlanmagan, qiymat o'zgarishi mumkin",
    chipWaiting: 'Natija hali yo\'q',
    totals: {
      title: "Ishchi varaqlar bo'yicha jami",
      hint: "Ro'yxatdagi namunalar o'tgan varaqlar. Jami faqat sizning namunalaringiz qatorlaridan hisoblanadi.",
      block: "Varaq bo'yicha jami",
      samples: '{n} namuna',
      partial: '{own} / {all} namuna',
      partialHint: "Varaqdagi {all} ta namunadan {own} tasi sizniki — jami faqat ular bo'yicha",
      partialNote: "Bo'limingizning {own} ta namunasi bo'yicha (varaqda jami {all} ta namuna)",
      approved: 'Tasdiqlangan',
      preliminary: 'Dastlabki',
      control: 'nazorat',
      worksheet: '№ {n}',
    },
    settings: {
      title: 'Portal sozlamalari',
      subtitle: "Buyurtmachi bo'limlar portalda nimani ko'radi. O'chirilgan ma'lumot buyurtmachiga umuman yuborilmaydi.",
      groups: {
        status: 'Namuna holati', results: 'Natijalar', analyses: "Tahlil ma'lumoti", other: 'Boshqa',
      },
      items: {
        steps: { title: 'Bosqichlar', hint: 'Qabul qilindi → Tahlilga uzatildi → Tahlil jarayonida → Tekshirilmoqda → Tayyor' },
        final_results: { title: 'Tasdiqlangan natijalar', hint: 'Probaga yozilgan yakuniy natija qiymatlari' },
        preliminary: { title: 'Dastlabki natijalar', hint: "Ishchi varaq hali tasdiqlanmagan paytdagi qiymatlar («dastlabki» belgisi bilan)" },
        analyses: { title: "O'tkazilgan tahlillar", hint: 'Ishchi varaq raqami, shablon (usul), sana va holati' },
        analysis_details: { title: 'Ishchi varaq ichidagi ma\'lumot', hint: "Parallellar bo'yicha o'lchov qiymatlari, parametrlar, bajarilgan bosqichlar va imzo sanalari" },
        comparison: { title: 'Nazorat tahlili bilan solishtirish', hint: 'Asosiy va nazorat natijasi, farq va ruxsat etilgan chegara' },
        totals: { title: "Varaq bo'yicha jami", hint: "Korotochkalar yig'indisi va boshqa jami ustunlar. Faqat buyurtmachining o'z namunalari qatorlaridan hisoblanadi — boshqa bo'limlar ma'lumoti kirmaydi" },
        quantity: { title: 'Namuna miqdori', hint: 'Topshirilgan, tahlilga sarflangan va qolgan miqdor' },
        export: { title: "Excel'ga yuklab olish", hint: "«Mening probalarim» sahifasida natijalarni Excel fayl qilib olish (fayl ham shu sozlamalarga bo'ysunadi)" },
      },
      requires: "«{names}» yoqilganda ishlaydi",
      save: 'Saqlash',
      saved: 'Sozlamalar saqlandi',
      cancel: 'Bekor qilish',
      reset: 'Hammasini ochish',
      preview: "Portalni ko'rish",
      lastChange: "Oxirgi o'zgarish: {d} · {who}",
      never: "Hali o'zgartirilmagan — hammasi ochiq (standart)",
      unsaved: "Saqlanmagan o'zgarishlar bor",
      leave: "Saqlanmagan o'zgarishlar bor. Chiqib ketilsinmi?",
      summary: 'Buyurtmachi ko\'radi: {n} / {total}',
      loadError: "Sozlamalarni yuklab bo'lmadi",
      saveError: "Saqlab bo'lmadi",
      note: "Laboratoriya xodimi «Portalni ko'rish» rejimida ham aynan shu holatni ko'radi.",
    },
  },
}

const ru = {
  portalVis: {
    excel: 'Excel',
    excelHint: 'Результаты проб из списка (по текущему фильтру) — файл Excel',
    excelBusy: 'Готовится…',
    excelDone: 'Файл загружен: {name}',
    excelError: 'Не удалось подготовить файл',
    configure: 'Настроить',
    configureHint: 'Что видит заказчик',
    hidden: 'скрыто',
    hiddenHint: 'Результат готов, но лаборатория не показывает его в портале',
    resultsCol: 'Результаты',
    progressCol: 'Готовность',
    legendFinal: 'утверждён',
    legendPrelim: 'предварительный',
    legendWaiting: 'ожидается',
    chipFinal: 'Утверждённый результат',
    chipPrelim: 'Предварительный — рабочий лист {n} ещё не утверждён, значение может измениться',
    chipWaiting: 'Результата пока нет',
    totals: {
      title: 'Итоги по рабочим листам',
      hint: 'Листы, в которые вошли пробы из списка. Итог считается только по строкам ваших проб.',
      block: 'Итог по листу',
      samples: 'проб: {n}',
      partial: 'проб: {own} / {all}',
      partialHint: 'Из {all} проб листа ваших {own} — итог только по ним',
      partialNote: 'По {own} пробам вашего подразделения (всего в листе проб: {all})',
      approved: 'Утверждён',
      preliminary: 'Предварительный',
      control: 'контроль',
      worksheet: '№ {n}',
    },
    settings: {
      title: 'Настройки портала',
      subtitle: 'Что видят подразделения-заказчики в портале. Отключённые данные заказчику вообще не передаются.',
      groups: {
        status: 'Состояние пробы', results: 'Результаты', analyses: 'Сведения об анализах', other: 'Прочее',
      },
      items: {
        steps: { title: 'Этапы', hint: 'Принята → Передана на анализ → Идёт анализ → Проверяется → Готово' },
        final_results: { title: 'Утверждённые результаты', hint: 'Итоговые значения, записанные в пробу' },
        preliminary: { title: 'Предварительные результаты', hint: 'Значения из ещё не утверждённого рабочего листа (с пометкой «предварительный»)' },
        analyses: { title: 'Проведённые анализы', hint: 'Номер рабочего листа, шаблон (методика), дата и статус' },
        analysis_details: { title: 'Данные внутри рабочего листа', hint: 'Значения измерений по параллелям, параметры, выполненные этапы и даты подписей' },
        comparison: { title: 'Сравнение с контрольным анализом', hint: 'Основной и контрольный результат, расхождение и допуск' },
        totals: { title: 'Итог по листу', hint: 'Сумма корточек и другие итоговые столбцы. Считается только по строкам проб самого заказчика — данные других подразделений не входят' },
        quantity: { title: 'Количество пробы', hint: 'Сдано, израсходовано на анализ и остаток' },
        export: { title: 'Выгрузка в Excel', hint: 'Файл с результатами на странице «Мои пробы» (файл тоже подчиняется этим настройкам)' },
      },
      requires: 'Работает, когда включено: «{names}»',
      save: 'Сохранить',
      saved: 'Настройки сохранены',
      cancel: 'Отмена',
      reset: 'Открыть всё',
      preview: 'Просмотр портала',
      lastChange: 'Последнее изменение: {d} · {who}',
      never: 'Ещё не менялось — всё открыто (по умолчанию)',
      unsaved: 'Есть несохранённые изменения',
      leave: 'Есть несохранённые изменения. Уйти со страницы?',
      summary: 'Заказчик видит: {n} / {total}',
      loadError: 'Не удалось загрузить настройки',
      saveError: 'Не удалось сохранить',
      note: 'Сотрудник лаборатории в режиме «Просмотр портала» видит ровно то же самое.',
    },
  },
}

const en = {
  portalVis: {
    excel: 'Excel',
    excelHint: 'Results of the listed samples (current filter) as an Excel file',
    excelBusy: 'Preparing…',
    excelDone: 'Downloaded: {name}',
    excelError: 'Could not prepare the file',
    configure: 'Configure',
    configureHint: 'What customers see',
    hidden: 'hidden',
    hiddenHint: 'The result is ready, but the lab does not show it in the portal',
    resultsCol: 'Results',
    progressCol: 'Done',
    legendFinal: 'approved',
    legendPrelim: 'preliminary',
    legendWaiting: 'pending',
    chipFinal: 'Approved result',
    chipPrelim: 'Preliminary — worksheet {n} is not approved yet; the value may change',
    chipWaiting: 'No result yet',
    totals: {
      title: 'Worksheet totals',
      hint: 'Worksheets that include the listed samples. Totals use only the rows of your samples.',
      block: 'Worksheet total',
      samples: '{n} samples',
      partial: '{own} / {all} samples',
      partialHint: '{own} of the {all} samples on this worksheet are yours — the total covers only them',
      partialNote: 'Based on {own} samples of your department ({all} samples on the worksheet)',
      approved: 'Approved',
      preliminary: 'Preliminary',
      control: 'control',
      worksheet: 'No. {n}',
    },
    settings: {
      title: 'Portal settings',
      subtitle: 'What customer departments see in the portal. Disabled data is never sent to the customer.',
      groups: {
        status: 'Sample status', results: 'Results', analyses: 'Analysis details', other: 'Other',
      },
      items: {
        steps: { title: 'Stages', hint: 'Received → Sent for analysis → In analysis → Under review → Ready' },
        final_results: { title: 'Approved results', hint: 'Final values recorded on the sample' },
        preliminary: { title: 'Preliminary results', hint: 'Values from a worksheet that is not approved yet (marked “preliminary”)' },
        analyses: { title: 'Analyses performed', hint: 'Worksheet number, template (method), date and status' },
        analysis_details: { title: 'Worksheet contents', hint: 'Measured values per parallel, parameters, completed stages and sign-off dates' },
        comparison: { title: 'Comparison with control analysis', hint: 'Main vs control result, difference and tolerance' },
        totals: { title: 'Worksheet total', hint: 'Sum of beads and other total columns. Calculated only from the rows of the customer’s own samples — other departments’ data is never included' },
        quantity: { title: 'Sample quantity', hint: 'Submitted, used for analysis and remaining' },
        export: { title: 'Excel download', hint: 'Results file on the “My samples” page (the file follows these settings too)' },
      },
      requires: 'Works when “{names}” is on',
      save: 'Save',
      saved: 'Settings saved',
      cancel: 'Cancel',
      reset: 'Show everything',
      preview: 'View portal',
      lastChange: 'Last change: {d} · {who}',
      never: 'Not changed yet — everything is visible (default)',
      unsaved: 'You have unsaved changes',
      leave: 'You have unsaved changes. Leave the page?',
      summary: 'Customers see: {n} / {total}',
      loadError: 'Could not load settings',
      saveError: 'Could not save',
      note: 'Lab staff using “View portal” see exactly the same.',
    },
  },
}

let merged = false
export function ensurePortalVisI18n() {
  if (merged) return
  i18n.global.mergeLocaleMessage('uz', uz)
  i18n.global.mergeLocaleMessage('ru', ru)
  i18n.global.mergeLocaleMessage('en', en)
  merged = true
}
ensurePortalVisI18n()
