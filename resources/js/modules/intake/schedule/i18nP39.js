/**
 * [Paket 39] «Grafigim»: buyurtma avtomatik yaratiladi — havola va vaqti; yangilangan izoh.
 */
import { i18n } from '@/i18n'

const uz = {
  sched: {
    hint: "Doimiy, kunning aniq vaqtida keladigan namunalar. Grafik vaqtidan biroz oldin har bir qatordan oddiy buyurtma o'zi yaratiladi va laboratoriyaga yuboriladi — natijalar «Mening probalarim» da. Buyurtma yaratilguncha shu kun namunalarini o'zgartirish mumkin; kerak bo'lmasa — «Bugun yo'q» (sabab bilan).",
    p39: { order: 'Buyurtma {n}', orderAt: 'Buyurtma {time} da yaratiladi' },
  },
}
const ru = {
  sched: {
    hint: 'Постоянные пробы, которые приходят в определённое время. Незадолго до времени графика из каждой строки сама создаётся обычная заявка и отправляется в лабораторию — результаты в «Мои пробы». До создания заявки пробы на день можно изменить; если не нужно — «Сегодня нет» (с причиной).',
    p39: { order: 'Заявка {n}', orderAt: 'Заявка будет создана в {time}' },
  },
}
const en = {
  sched: {
    hint: 'Recurring samples delivered at fixed times. Shortly before the scheduled time a regular request is created from each row and sent to the lab — results appear in «My samples». Until it is created you can change the day\'s samples; if not needed — «Not today» (with a reason).',
    p39: { order: 'Request {n}', orderAt: 'Request will be created at {time}' },
  },
}

export function ensureSchedP39I18n() {
  i18n.global.mergeLocaleMessage('uz', uz)
  i18n.global.mergeLocaleMessage('ru', ru)
  i18n.global.mergeLocaleMessage('en', en)
}

ensureSchedP39I18n()
