/**
 * [Paket 39] Tarjimalar: grafik buyurtmalari, kechiktirib kiritish, sabab bilan yopish,
 * «Buyurtma sozlamalari» va «Buyurtmalar nazorati».
 */
import { i18n } from '@/i18n'

const uz = {
  sreq: {
    p39: {
      reasonPick: 'Sabab', reasonChoose: "— ro'yxatdan tanlang —",
      noteRequired: 'Izoh', noteOptional: "Izoh (ixtiyoriy)",
      source: 'Manba', sourceAll: 'Hammasi', sourceRequest: 'Odatiy buyurtma', sourceSchedule: "Grafik bo'yicha",
      schedBadge: 'Grafik {time}',
      lateEntry: 'Kechiktirib kiritilgan', lateShort: 'kechikib', lateOnly: 'Faqat kechiktirib kiritilganlar',
      actualTime: 'Haqiqiy qabul vaqti (avval kelgan)',
      actualHint: "Namuna aslida qachon kelganini kiriting. {h} soatdan eskisini faqat laboratoriya boshlig'i kiritadi.",
      actualHintHead: 'Namuna aslida qachon kelganini kiriting. Probalar shu vaqt bilan yoziladi.',
      scheduledAt: 'Grafik vaqti', enteredAt: 'Dasturga kiritilgan', autoClosed: 'Avtomatik yopilgan (sababsiz)',
      closeTitle: 'Buyurtmani yopish', close: 'Yopish (sabab bilan)',
      closeBody: "Namuna olib kelinmagan sababini ro'yxatdan tanlang. Laboratoriya va hisobotda shu sabab ko'rinadi.",
      expiredNote: "Muddati o'tdi. Namuna olib kelinmagan bo'lsa — «Yopish (sabab bilan)». Olib kelingan bo'lsa — laboratoriya haqiqiy vaqt bilan qabul qiladi.",
      codePreview: 'Buyurtmada: {code}',
      modes: { day_end: 'Kun oxirida', shift_end: 'Smena oxirida', next_run: 'Keyingi grafik vaqtida', hours: 'N soatdan keyin' },
      set: {
        title: 'Buyurtma sozlamalari',
        hint: "Grafik buyurtmalari qachon yaratilishi va qachon «Muddati o'tdi» bo'lishi, kechiktirib kiritish chegarasi, avtomatik yopish va yopish sabablari. Bo'linma qatorida bo'sh qoldirilgan qiymat — umumiy qiymat.",
        global: 'Umumiy qiymatlar', save: 'Saqlash', saved: 'Saqlandi',
        lead: 'Buyurtma necha daqiqa oldin yaratiladi', leadHint: 'Grafik vaqtidan oldin (0 — aynan grafik vaqtida)',
        expire: "«Muddati o'tdi» qachon", hours: 'Soat', noShifts: "Faol smena yo'q — kun oxiri ishlatiladi",
        lateHours: 'Kechiktirib kiritish chegarasi, soat', lateHoursHint: "Shundan eski qabulni faqat laboratoriya boshlig'i kiritadi",
        autoClose: 'Avtomatik yopish, kun', autoCloseHint: "Muddati o'tib, shuncha kun sababsiz qolsa — avtomatik yopiladi (0 — yo'q)",
        depts: "Bo'linmalar — grafik rejimi",
        deptsHint: "Grafikni yoqish va bo'linmaga xos qiymatlar. Bo'sh — umumiy qiymat ishlatiladi. Kechikish — grafik vaqtidan necha daqiqa o'tib «Kechikmoqda» xabari boradi.",
        enabled: 'Grafik', lateMin: 'Kechikish, daq.', rows: 'Grafik qatorlari', useGlobal: 'Umumiy ({v})',
        reasons: 'Yopish sabablari',
        reasonsHint: "Buyurtmachi «Bugun yo'q» yoki muddati o'tgan buyurtmani yopganda va laboratoriya rad etganda tanlanadi. «Izoh majburiy» — masalan «Boshqa».",
        addReason: "Sabab qo'shish", reasonPh: "Masalan: Texnologik jarayon to'xtagan",
        needsNote: 'Izoh majburiy', active: 'Faol', used: 'Ishlatilgan', remove: "O'chirish",
        reasonRemoveConfirm: "«{name}» sababi o'chirilsinmi? Ishlatilgan bo'lsa — nofaol qilinadi.",
        reasonDeactivated: "Sabab ishlatilgan — o'chirilmadi, nofaol qilindi",
      },
      ctl: {
        title: 'Buyurtmalar nazorati',
        hint: "Davr bo'yicha: qancha buyurtma o'z vaqtida qabul qilingan, qanchasi kechiktirib kiritilgan, qanchasi sabab bilan yoki avtomatik yopilgan, qanchasi hali yopilmagan. Grafik buyurtmasi — grafik vaqti bo'yicha, odatiysi — yuborilgan sana bo'yicha.",
        from: 'Dan', to: 'Gacha', empty: "Bu davrda buyurtma yo'q",
        c: { total: 'Jami', received: 'Qabul qilingan', on_time: "O'z vaqtida", late_entry: 'Kechiktirib kiritilgan', closed: 'Sabab bilan yopilgan', auto_closed: 'Avtomatik yopilgan', unclosed: 'Yopilmagan', waiting: 'Kutilmoqda' },
        byDept: "Bo'linmalar bo'yicha", schedN: 'grafik: {n}', reasons: 'Yopish sabablari', late: 'Kechiktirib kiritilganlar',
        autoReason: 'Avtomatik yopilgan (sababsiz)', textReason: "Ro'yxatsiz (matn bilan)", actual: 'Haqiqiy qabul', delay: 'Kechikish, soat',
      },
    },
  },
}

const ru = {
  sreq: {
    p39: {
      reasonPick: 'Причина', reasonChoose: '— выберите из списка —',
      noteRequired: 'Комментарий', noteOptional: 'Комментарий (необязательно)',
      source: 'Источник', sourceAll: 'Все', sourceRequest: 'Обычная заявка', sourceSchedule: 'По графику',
      schedBadge: 'График {time}',
      lateEntry: 'Внесено с опозданием', lateShort: 'поздно', lateOnly: 'Только внесённые с опозданием',
      actualTime: 'Фактическое время приёма (пришла раньше)',
      actualHint: 'Укажите, когда проба фактически пришла. Старше {h} ч — только начальник лаборатории.',
      actualHintHead: 'Укажите, когда проба фактически пришла. Пробы будут записаны этим временем.',
      scheduledAt: 'Время по графику', enteredAt: 'Внесено в программу', autoClosed: 'Закрыта автоматически (без причины)',
      closeTitle: 'Закрыть заявку', close: 'Закрыть (с причиной)',
      closeBody: 'Выберите из списка, почему проба не была доставлена. Причина видна лаборатории и в отчёте.',
      expiredNote: 'Срок истёк. Если проба не доставлялась — «Закрыть (с причиной)». Если доставлена — лаборатория примет её с фактическим временем.',
      codePreview: 'В заявке: {code}',
      modes: { day_end: 'В конце дня', shift_end: 'В конце смены', next_run: 'К следующему времени графика', hours: 'Через N часов' },
      set: {
        title: 'Настройки заявок',
        hint: 'Когда создаются заявки по графику и когда они становятся «Срок истёк», порог позднего ввода, автозакрытие и причины закрытия. Пустое значение в строке подразделения — общее значение.',
        global: 'Общие значения', save: 'Сохранить', saved: 'Сохранено',
        lead: 'За сколько минут создаётся заявка', leadHint: 'До времени графика (0 — ровно во время графика)',
        expire: 'Когда «Срок истёк»', hours: 'Часов', noShifts: 'Нет активных смен — используется конец дня',
        lateHours: 'Порог позднего ввода, ч', lateHoursHint: 'Более старый приём вносит только начальник лаборатории',
        autoClose: 'Автозакрытие, дней', autoCloseHint: 'Если просроченная заявка столько дней без причины — закрывается автоматически (0 — нет)',
        depts: 'Подразделения — режим графика',
        deptsHint: 'Включение графика и значения для подразделения. Пусто — общее значение. Опоздание — через сколько минут после времени графика уходит уведомление «Опаздывает».',
        enabled: 'График', lateMin: 'Опоздание, мин', rows: 'Строк графика', useGlobal: 'Общее ({v})',
        reasons: 'Причины закрытия',
        reasonsHint: 'Выбираются, когда заказчик отмечает «Сегодня нет» или закрывает просроченную заявку и когда лаборатория отклоняет. «Комментарий обязателен» — например, «Другое».',
        addReason: 'Добавить причину', reasonPh: 'Например: Технологический процесс остановлен',
        needsNote: 'Комментарий обязателен', active: 'Активна', used: 'Использована', remove: 'Удалить',
        reasonRemoveConfirm: 'Удалить причину «{name}»? Если она использовалась — станет неактивной.',
        reasonDeactivated: 'Причина использовалась — не удалена, а отключена',
      },
      ctl: {
        title: 'Контроль заявок',
        hint: 'За период: сколько заявок принято вовремя, сколько внесено с опозданием, сколько закрыто с причиной или автоматически, сколько ещё не закрыто. Заявки по графику — по времени графика, обычные — по дате отправки.',
        from: 'С', to: 'По', empty: 'За период заявок нет',
        c: { total: 'Всего', received: 'Принято', on_time: 'Вовремя', late_entry: 'Внесено с опозданием', closed: 'Закрыто с причиной', auto_closed: 'Закрыто автоматически', unclosed: 'Не закрыто', waiting: 'Ожидается' },
        byDept: 'По подразделениям', schedN: 'по графику: {n}', reasons: 'Причины закрытия', late: 'Внесённые с опозданием',
        autoReason: 'Закрыто автоматически (без причины)', textReason: 'Без списка (текстом)', actual: 'Фактический приём', delay: 'Задержка, ч',
      },
    },
  },
}

const en = {
  sreq: {
    p39: {
      reasonPick: 'Reason', reasonChoose: '— pick from the list —',
      noteRequired: 'Note', noteOptional: 'Note (optional)',
      source: 'Source', sourceAll: 'All', sourceRequest: 'Regular request', sourceSchedule: 'By schedule',
      schedBadge: 'Schedule {time}',
      lateEntry: 'Entered late', lateShort: 'late', lateOnly: 'Entered late only',
      actualTime: 'Actual receipt time (arrived earlier)',
      actualHint: 'Enter when the sample actually arrived. Older than {h} h — lab head only.',
      actualHintHead: 'Enter when the sample actually arrived. Samples are recorded with this time.',
      scheduledAt: 'Scheduled time', enteredAt: 'Entered into the system', autoClosed: 'Closed automatically (no reason)',
      closeTitle: 'Close request', close: 'Close (with reason)',
      closeBody: 'Pick from the list why the sample was not delivered. The lab and the report will show this reason.',
      expiredNote: 'Expired. If the sample was not delivered — «Close (with reason)». If it was delivered — the lab receives it with the actual time.',
      codePreview: 'In the request: {code}',
      modes: { day_end: 'At the end of the day', shift_end: 'At the end of the shift', next_run: 'At the next scheduled time', hours: 'After N hours' },
      set: {
        title: 'Request settings',
        hint: 'When scheduled requests are created and when they expire, the late-entry limit, auto-closing and close reasons. An empty value in a department row means the general value.',
        global: 'General values', save: 'Save', saved: 'Saved',
        lead: 'Create request minutes before', leadHint: 'Before the scheduled time (0 — exactly at it)',
        expire: 'When it expires', hours: 'Hours', noShifts: 'No active shifts — end of day is used',
        lateHours: 'Late-entry limit, h', lateHoursHint: 'Older receipts are entered by the lab head only',
        autoClose: 'Auto-close, days', autoCloseHint: 'An expired request left without a reason this long is closed automatically (0 — never)',
        depts: 'Departments — schedule mode',
        deptsHint: 'Enable schedules and department-specific values. Empty — the general value. Lateness — minutes after the scheduled time before the «Late» notice.',
        enabled: 'Schedule', lateMin: 'Lateness, min', rows: 'Schedule rows', useGlobal: 'General ({v})',
        reasons: 'Close reasons',
        reasonsHint: 'Picked when the customer marks «Not today» or closes an expired request, and when the lab rejects. «Note required» — e.g. «Other».',
        addReason: 'Add reason', reasonPh: 'e.g. Process stopped',
        needsNote: 'Note required', active: 'Active', used: 'Used', remove: 'Delete',
        reasonRemoveConfirm: 'Delete reason «{name}»? If it was used it is deactivated instead.',
        reasonDeactivated: 'The reason was used — deactivated instead of deleted',
      },
      ctl: {
        title: 'Request control',
        hint: 'For the period: how many requests were received on time, entered late, closed with a reason or automatically, and how many are still open. Scheduled requests count by scheduled time, regular ones by the date sent.',
        from: 'From', to: 'To', empty: 'No requests in this period',
        c: { total: 'Total', received: 'Received', on_time: 'On time', late_entry: 'Entered late', closed: 'Closed with reason', auto_closed: 'Auto-closed', unclosed: 'Not closed', waiting: 'Waiting' },
        byDept: 'By department', schedN: 'scheduled: {n}', reasons: 'Close reasons', late: 'Entered late',
        autoReason: 'Auto-closed (no reason)', textReason: 'Free text (no list)', actual: 'Actual receipt', delay: 'Delay, h',
      },
    },
  },
}

export function ensureP39I18n() {
  i18n.global.mergeLocaleMessage('uz', uz)
  i18n.global.mergeLocaleMessage('ru', ru)
  i18n.global.mergeLocaleMessage('en', en)
}

ensureP39I18n()
