/**
 * [Paket 34] Tarjimalar: bo'linma xodimlari ma'lumotnomasi (kim olib keladi + telefon kombobox).
 */
import { i18n } from '@/i18n'

const uz = {
  sreq: {
    p34: {
      namePlaceholder: 'F.I.Sh. — yozing yoki ro\'yxatdan tanlang',
      phonePlaceholder: '+998 90 123-45-67 yoki ichki raqam',
      newRow: "Yangi xodim: «{name}»",
      newNote: "Yangi xodim — buyurtma yuborilganda bo'linma ro'yxatiga qo'shiladi",
      exactAsk: "Ro'yxatda «{name}» deb yozilgan. Shunday yozilsinmi?",
      similarAsk: "«{name}» — ro'yxatdagi shu xodim emasmi?",
      yesFix: "Ha, ro'yxatdagidek",
      keepMine: "Yo'q, yozganimcha qolsin",
      newPerson: "Yo'q, bu yangi xodim",
      answerFirst: "«Kim olib keladi» maydonidagi savolga javob bering",
      phoneOdd: "Raqam formati notanish — tekshiring (masalan +998 90 123-45-67 yoki ichki 2-34-56)",
      phoneChanged: "Yangi raqam ro'yxatda saqlanadi (avvalgisi: {old})",
      internal: 'Ichki raqam',
      remove: "Ro'yxatdan o'chirish",
      removeConfirm: "«{name}» bo'linma xodimlari ro'yxatidan o'chirilsinmi? Buyurtmalarga ta'sir qilmaydi.",
      removed: "Ro'yxatdan o'chirildi",
      added: "«{name}» bo'linma xodimlari ro'yxatiga qo'shildi",
      phoneUpdated: "{name}: telefon ro'yxatda yangilandi",
      loadError: "Xodimlar ro'yxatini yuklab bo'lmadi",
      // «Shablonlarim» → «Bo'linma xodimlari»
      title: "Bo'linma xodimlari",
      hint: "«Kim olib keladi» maydoni uchun ro'yxat. Alohida to'ldirish shart emas — buyurtma yuborilganda o'zi to'planadi. Bu yerda faqat xatolarni tuzatasiz yoki ortiqchasini o'chirasiz.",
      colName: 'F.I.Sh.',
      colPhone: 'Telefon',
      colUsed: 'Necha marta',
      colLast: 'Oxirgi marta',
      edit: 'Tahrirlash',
      save: 'Saqlash',
      cancel: 'Bekor',
      saved: 'Saqlandi',
      empty: "Hali hech kim yo'q — birinchi buyurtma yuborilganda paydo bo'ladi",
      search: 'Qidirish…',
    },
  },
}

const ru = {
  sreq: {
    p34: {
      namePlaceholder: 'Ф.И.О. — введите или выберите из списка',
      phonePlaceholder: '+998 90 123-45-67 или внутренний',
      newRow: 'Новый сотрудник: «{name}»',
      newNote: 'Новый сотрудник — будет добавлен в список подразделения при отправке заявки',
      exactAsk: 'В списке записано «{name}». Записать так?',
      similarAsk: '«{name}» — это не сотрудник из списка?',
      yesFix: 'Да, как в списке',
      keepMine: 'Нет, оставить как ввёл',
      newPerson: 'Нет, это новый сотрудник',
      answerFirst: 'Ответьте на вопрос в поле «Кто доставит»',
      phoneOdd: 'Необычный формат номера — проверьте (например +998 90 123-45-67 или внутренний 2-34-56)',
      phoneChanged: 'Новый номер сохранится в списке (прежний: {old})',
      internal: 'Внутренний номер',
      remove: 'Удалить из списка',
      removeConfirm: 'Удалить «{name}» из списка сотрудников подразделения? На заявки не влияет.',
      removed: 'Удалено из списка',
      added: '«{name}» добавлен(а) в список сотрудников подразделения',
      phoneUpdated: '{name}: телефон обновлён в списке',
      loadError: 'Не удалось загрузить список сотрудников',
      title: 'Сотрудники подразделения',
      hint: 'Список для поля «Кто доставит». Заполнять отдельно не нужно — он собирается сам при отправке заявок. Здесь можно исправить ошибки или удалить лишнее.',
      colName: 'Ф.И.О.',
      colPhone: 'Телефон',
      colUsed: 'Раз',
      colLast: 'Последний раз',
      edit: 'Изменить',
      save: 'Сохранить',
      cancel: 'Отмена',
      saved: 'Сохранено',
      empty: 'Пока никого нет — появятся после первой отправленной заявки',
      search: 'Поиск…',
    },
  },
}

const en = {
  sreq: {
    p34: {
      namePlaceholder: 'Full name — type or pick from the list',
      phonePlaceholder: '+998 90 123-45-67 or extension',
      newRow: 'New person: «{name}»',
      newNote: 'New person — added to the department list when the request is sent',
      exactAsk: 'The list has «{name}». Use that spelling?',
      similarAsk: 'Is «{name}» one of these people?',
      yesFix: 'Yes, as in the list',
      keepMine: 'No, keep mine',
      newPerson: 'No, a new person',
      answerFirst: 'Answer the question in the courier field first',
      phoneOdd: 'Unusual number format — please check (e.g. +998 90 123-45-67 or extension 2-34-56)',
      phoneChanged: 'The new number will be saved to the list (was: {old})',
      internal: 'Extension',
      remove: 'Remove from list',
      removeConfirm: 'Remove «{name}» from the department list? Requests are not affected.',
      removed: 'Removed from list',
      added: '«{name}» added to the department list',
      phoneUpdated: '{name}: phone updated in the list',
      loadError: 'Could not load the people list',
      title: 'Department people',
      hint: 'The list behind the courier field. No separate entry needed — it fills itself as requests are sent. Fix mistakes or remove extras here.',
      colName: 'Full name',
      colPhone: 'Phone',
      colUsed: 'Times',
      colLast: 'Last used',
      edit: 'Edit',
      save: 'Save',
      cancel: 'Cancel',
      saved: 'Saved',
      empty: 'Nobody yet — people appear after the first sent request',
      search: 'Search…',
    },
  },
}

export function ensureP34I18n() {
  i18n.global.mergeLocaleMessage('uz', uz)
  i18n.global.mergeLocaleMessage('ru', ru)
  i18n.global.mergeLocaleMessage('en', en)
}

ensureP34I18n()
