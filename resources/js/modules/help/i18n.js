/**
 * Yordamchi (lokal AI) tarjimalari. Asosiy locale fayllarga tegmasdan, import qilinganda qo'shiladi.
 */
import { i18n } from '@/i18n'

const uz = {
  help: {
    title: 'Yordam',
    subtitle: 'Dastur bo‘yicha savollarga javob',
    open: 'Yordamni ochish',
    close: 'Yopish',
    clear: 'Suhbatni tozalash',
    welcome: 'Salom! Men dastur bo‘yicha yordamchiman. Savolingizni yozing yoki quyidagilardan birini tanlang.',
    hi: 'Salom! Qanday yordam bera olaman? Savolingizni yozing.',
    thanks: 'Marhamat! Yana savol bo‘lsa, yozing.',
    placeholder: 'Savolingizni yozing…',
    send: 'Yuborish',
    thisPage: 'Shu sahifa bo‘yicha',
    popular: 'Ko‘p so‘raladigan',
    suggestIntro: 'Aniq javobni topa olmadim. Shulardan birini nazarda tutdingizmi?',
    related: 'Shu haqida ham:',
    none: 'Bu savolga javob topa olmadim. Boshqacha yozib ko‘ring (masalan, asosiy so‘zlar bilan) yoki quyidagilardan tanlang. Savolingiz administratorga yuborildi — javob qo‘shiladi.',
    openPage: 'Sahifani ochish',
    helpful: 'Foydali',
    notHelpful: 'Foydasiz',
    thanksFeedback: 'Fikringiz uchun rahmat',
    error: 'Yordamchi hozir javob bera olmadi. Keyinroq urinib ko‘ring.',
    typing: 'Javob qidirilmoqda…',
  },
}

const ru = {
  help: {
    title: 'Помощь',
    subtitle: 'Ответы на вопросы по программе',
    open: 'Открыть помощь',
    close: 'Закрыть',
    clear: 'Очистить диалог',
    welcome: 'Здравствуйте! Я помощник по программе. Напишите вопрос или выберите один из вариантов ниже.',
    hi: 'Здравствуйте! Чем могу помочь? Напишите вопрос.',
    thanks: 'Пожалуйста! Если будут вопросы — пишите.',
    placeholder: 'Напишите вопрос…',
    send: 'Отправить',
    thisPage: 'По этой странице',
    popular: 'Часто спрашивают',
    suggestIntro: 'Точного ответа не нашёл. Вы имели в виду что-то из этого?',
    related: 'Также об этом:',
    none: 'Не нашёл ответа на этот вопрос. Попробуйте сформулировать иначе (например, ключевыми словами) или выберите из списка ниже. Ваш вопрос передан администратору — ответ будет добавлен.',
    openPage: 'Открыть страницу',
    helpful: 'Полезно',
    notHelpful: 'Не помогло',
    thanksFeedback: 'Спасибо за отзыв',
    error: 'Помощник сейчас не смог ответить. Повторите позже.',
    typing: 'Ищу ответ…',
  },
}

const en = {
  help: {
    title: 'Help',
    subtitle: 'Answers about the program',
    open: 'Open help',
    close: 'Close',
    clear: 'Clear conversation',
    welcome: 'Hello! I am the program assistant. Type your question or pick one below.',
    hi: 'Hello! How can I help? Type your question.',
    thanks: 'You are welcome! Ask again if you need anything.',
    placeholder: 'Type your question…',
    send: 'Send',
    thisPage: 'About this page',
    popular: 'Frequently asked',
    suggestIntro: 'I could not find an exact answer. Did you mean one of these?',
    related: 'Also about this:',
    none: 'I could not find an answer to this. Try rewording it (for example with key words) or pick from the list below. Your question was sent to the administrator — an answer will be added.',
    openPage: 'Open the page',
    helpful: 'Helpful',
    notHelpful: 'Not helpful',
    thanksFeedback: 'Thanks for your feedback',
    error: 'The assistant could not answer right now. Please try again later.',
    typing: 'Looking for an answer…',
  },
}

let merged = false
export function ensureHelpI18n() {
  if (merged) return
  i18n.global.mergeLocaleMessage('uz', uz)
  i18n.global.mergeLocaleMessage('ru', ru)
  i18n.global.mergeLocaleMessage('en', en)
  merged = true
}

ensureHelpI18n()
