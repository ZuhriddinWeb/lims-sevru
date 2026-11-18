/**
 * [Paket 37] Grafik jadvallari (Excel ko'rinishida) — tarjimalar.
 */
import { i18n } from '@/i18n'

const uz = {
  ssheet: {
    title: 'Grafik jadvallari',
    portalTitle: 'Grafik jadvali',
    hint: "Grafik bo'yicha partiyalar — Excel ko'rinishida. Oq kataklarga qiymat kiritiladi, sariq kataklar formula bo'yicha o'zi hisoblanadi. Qiymat katakdan chiqqanda saqlanadi.",
    portalHint: "Grafik bo'yicha partiyalar natijalari jadval ko'rinishida. Sariq ustunlar formula bo'yicha hisoblanadi.",
    readOnly: "Ko'rish rejimi — qiymatlarni laboratoriya kiritadi.",
    sheet: 'Jadval', n: '№', time: 'Boshlanish soati',
    empty: "Hali jadval yo'q",
    emptyHint: "Administrator «Grafik jadvali konstruktori» da ustunlar va formulalarni belgilaydi.",
    noRows: "Bu kunga grafik bo'yicha partiya yo'q",
    excel: 'Excel', saved: 'Saqlandi', saveError: 'Saqlanmadi', loadError: "Ma'lumot yuklanmadi",
    fromSample: 'Namunadan: {code}', pending: 'Natija hali chiqmagan — qo\'lda kiritish mumkin',
    locked: 'Natija hali chiqarilmagan', formulaError: 'Formula xatosi: {msg}',
    legendInput: 'kiritiladi', legendCalc: 'formula / o\'rtacha', legendSample: 'namuna natijasi',
    builder: {
      title: 'Grafik jadvali konstruktori',
      hint: "Har bir jadval — bitta buyurtmachi bo'linmasi uchun. Ustunlar: «Kiritiladi» (laboratoriya qo'lda), «Namuna natijasi» (partiyadagi N-namunaning ko'rsatkichi), «Formula» (shu qatordagi ustunlardan) va «O'rtacha» (kun bo'yicha yoki shu qatorgacha). Formula va o'rtacha sariq rangda ko'rinadi.",
      list: 'Jadvallar', add: 'Yangi jadval', none: "Jadval hali yo'q",
      department: "Bo'linma", name: 'Nomi', namePh: "Masalan: KSP bo'tana zichligi",
      schedules: 'Grafik qatorlari', schedulesAll: "Hammasi (bo'shi — bo'linmaning barcha grafiklari)",
      active: 'Faol', order: 'Tartib', notEnabled: "Bu bo'linmada grafik rejimi yoqilmagan",
      columns: 'Ustunlar', addColumn: "Ustun qo'shish",
      key: 'Kalit', keyHint: 'formulada ishlatiladi: lotin harf, raqam, _',
      colTitle: 'Sarlavha', unit: 'Birlik', decimals: 'Xona', kind: 'Turi',
      kinds: { input: 'Kiritiladi', result: 'Namuna natijasi', formula: 'Formula', average: "O'rtacha" },
      item: 'Namuna №', itemHint: 'grafik qatoridagi tartib raqami', element: "Ko'rsatkich",
      formula: 'Formula', formulaPh: 'Masalan: round(zich * p015 / 10, 2)',
      formulaHint: "Ustun kalitlari, + - * / ^, qavslar va funksiyalar: {fns}. O'rtacha ustunni formulada ishlatib bo'lmaydi.",
      source: 'Qaysi ustun', mode: "O'rtacha turi",
      modes: { day: "Kun bo'yicha (hamma qatorda bir xil)", running: 'Shu qatorgacha (yig\'ilib boradi)' },
      up: 'Yuqoriga', down: 'Pastga', remove: "O'chirish",
      save: 'Saqlash', saved: 'Saqlandi', removeConfirm: "«{name}» jadvali o'chirilsinmi? Kiritilgan qiymatlar bazada qoladi.",
      removed: "O'chirildi", preview: "Ko'rish", choose: '— tanlang —',
    },
  },
}

const ru = {
  ssheet: {
    title: 'Таблицы по графику',
    portalTitle: 'Таблица графика',
    hint: 'Партии по графику в виде Excel. В белые ячейки вводятся значения, жёлтые считаются по формуле. Значение сохраняется при выходе из ячейки.',
    portalHint: 'Результаты партий по графику в виде таблицы. Жёлтые столбцы считаются по формуле.',
    readOnly: 'Режим просмотра — значения вводит лаборатория.',
    sheet: 'Таблица', n: '№', time: 'Время начала',
    empty: 'Таблиц пока нет',
    emptyHint: 'Администратор задаёт столбцы и формулы в «Конструкторе таблиц графика».',
    noRows: 'На этот день партий по графику нет',
    excel: 'Excel', saved: 'Сохранено', saveError: 'Не сохранено', loadError: 'Не удалось загрузить',
    fromSample: 'Из пробы: {code}', pending: 'Результата ещё нет — можно ввести вручную',
    locked: 'Результат ещё не выпущен', formulaError: 'Ошибка формулы: {msg}',
    legendInput: 'ввод', legendCalc: 'формула / среднее', legendSample: 'результат пробы',
    builder: {
      title: 'Конструктор таблиц графика',
      hint: 'Каждая таблица — для одного подразделения-заказчика. Столбцы: «Ввод» (лаборатория вручную), «Результат пробы» (показатель N-й пробы партии), «Формула» (из столбцов этой строки) и «Среднее» (за день или нарастающее). Формулы и средние показываются жёлтым.',
      list: 'Таблицы', add: 'Новая таблица', none: 'Таблиц пока нет',
      department: 'Подразделение', name: 'Название', namePh: 'Например: плотность пульпы КСП',
      schedules: 'Строки графика', schedulesAll: 'Все (пусто — все графики подразделения)',
      active: 'Активна', order: 'Порядок', notEnabled: 'Для подразделения график не включён',
      columns: 'Столбцы', addColumn: 'Добавить столбец',
      key: 'Ключ', keyHint: 'для формул: латиница, цифры, _',
      colTitle: 'Заголовок', unit: 'Ед.', decimals: 'Знаки', kind: 'Тип',
      kinds: { input: 'Ввод', result: 'Результат пробы', formula: 'Формула', average: 'Среднее' },
      item: '№ пробы', itemHint: 'порядковый номер в строке графика', element: 'Показатель',
      formula: 'Формула', formulaPh: 'Например: round(zich * p015 / 10, 2)',
      formulaHint: 'Ключи столбцов, + - * / ^, скобки и функции: {fns}. Средние в формулах не используются.',
      source: 'Какой столбец', mode: 'Вид среднего',
      modes: { day: 'За день (одинаково во всех строках)', running: 'Нарастающее (до этой строки)' },
      up: 'Вверх', down: 'Вниз', remove: 'Удалить',
      save: 'Сохранить', saved: 'Сохранено', removeConfirm: 'Удалить таблицу «{name}»? Введённые значения останутся в базе.',
      removed: 'Удалено', preview: 'Открыть', choose: '— выберите —',
    },
  },
}

const en = {
  ssheet: {
    title: 'Schedule sheets',
    portalTitle: 'Schedule sheet',
    hint: 'Scheduled batches as a spreadsheet. Type values into white cells; yellow cells are calculated by formula. A value is saved when you leave the cell.',
    portalHint: 'Results of scheduled batches as a table. Yellow columns are calculated by formula.',
    readOnly: 'View only — the lab enters the values.',
    sheet: 'Sheet', n: '#', time: 'Start time',
    empty: 'No sheets yet',
    emptyHint: 'An administrator sets the columns and formulas in «Schedule sheet builder».',
    noRows: 'No scheduled batches on this day',
    excel: 'Excel', saved: 'Saved', saveError: 'Not saved', loadError: 'Could not load data',
    fromSample: 'From sample: {code}', pending: 'No result yet — you can type it in',
    locked: 'Result not released yet', formulaError: 'Formula error: {msg}',
    legendInput: 'entered', legendCalc: 'formula / average', legendSample: 'sample result',
    builder: {
      title: 'Schedule sheet builder',
      hint: 'Each sheet belongs to one customer department. Columns: «Entered» (by the lab), «Sample result» (an element of the batch\'s Nth sample), «Formula» (from columns of the same row) and «Average» (per day or running). Formulas and averages show in yellow.',
      list: 'Sheets', add: 'New sheet', none: 'No sheets yet',
      department: 'Department', name: 'Name', namePh: 'e.g. KSP slurry density',
      schedules: 'Schedule rows', schedulesAll: 'All (empty — every schedule of the department)',
      active: 'Active', order: 'Order', notEnabled: 'Scheduling is not enabled for this department',
      columns: 'Columns', addColumn: 'Add column',
      key: 'Key', keyHint: 'used in formulas: latin letters, digits, _',
      colTitle: 'Title', unit: 'Unit', decimals: 'Decimals', kind: 'Type',
      kinds: { input: 'Entered', result: 'Sample result', formula: 'Formula', average: 'Average' },
      item: 'Sample #', itemHint: 'position in the schedule row', element: 'Element',
      formula: 'Formula', formulaPh: 'e.g. round(zich * p015 / 10, 2)',
      formulaHint: 'Column keys, + - * / ^, brackets and functions: {fns}. Averages cannot be used in formulas.',
      source: 'Of column', mode: 'Average type',
      modes: { day: 'Per day (same on every row)', running: 'Running (up to this row)' },
      up: 'Up', down: 'Down', remove: 'Remove',
      save: 'Save', saved: 'Saved', removeConfirm: 'Delete sheet «{name}»? Entered values stay in the database.',
      removed: 'Deleted', preview: 'Open', choose: '— choose —',
    },
  },
}

export function ensureSheetI18n() {
  i18n.global.mergeLocaleMessage('uz', uz)
  i18n.global.mergeLocaleMessage('ru', ru)
  i18n.global.mergeLocaleMessage('en', en)
}
ensureSheetI18n()
