<template>
  <div class="space-y-6">
    <!-- Sarlavha -->
    <div>
      <h1 class="text-2xl font-semibold text-slate-800 dark:text-slate-100">
        {{ t('manual.title') }}
      </h1>
      <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
        {{ t('manual.subtitle') }}
      </p>
    </div>

    <!-- Video qo'llanma (public/manuals/LIMS_video_uz.mp4 + .vtt subtitr). Fayl bo'lmasa — bo'lim ko'rinmaydi -->
    <div
      v-if="video.state !== 'missing'"
      class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-slate-700 dark:bg-slate-800"
      data-manual-video
    >
      <div class="flex flex-wrap items-center gap-2 border-b border-slate-200 px-5 py-3 dark:border-slate-700">
        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor"
             stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-rose-600">
          <rect x="2" y="5" width="15" height="14" rx="2" /><path d="m17 10 5-3v10l-5-3" />
        </svg>
        <h2 class="text-base font-semibold text-slate-800 dark:text-slate-100">{{ t('manualVideo.title') }}</h2>
        <span class="text-sm text-slate-500 dark:text-slate-400">· {{ t('manualVideo.meta') }}</span>
        <span class="flex-1" />
        <a :href="VIDEO" download="LIMS_video_qollanma.mp4"
           class="inline-flex items-center rounded-lg border border-slate-200 px-3 py-1.5 text-sm font-medium text-slate-600 transition hover:bg-slate-50 dark:border-slate-600 dark:text-slate-300 dark:hover:bg-slate-700">
          {{ t('manual.download') }}
        </a>
      </div>

      <div class="grid grid-cols-1 lg:grid-cols-[minmax(0,1fr)_300px]">
        <div class="bg-black">
          <video
            ref="player" class="aspect-video w-full" controls preload="metadata" playsinline
            @timeupdate="onTime" @error="video.state = 'error'"
          >
            <source :src="VIDEO" type="video/mp4" />
            <track kind="subtitles" :src="SUBS" srclang="uz" :label="t('manualVideo.subs')" default />
          </video>
        </div>

        <!-- Bo'limlar (bosilsa shu joyidan boshlanadi) -->
        <div class="max-h-[420px] overflow-y-auto border-t border-slate-200 p-2 lg:border-l lg:border-t-0 dark:border-slate-700">
          <p class="px-3 pb-1 pt-2 text-xs font-medium uppercase tracking-wide text-slate-400">{{ t('manualVideo.chapters') }}</p>
          <button
            v-for="(c, i) in chapters" :key="c.at" type="button"
            class="flex w-full items-start gap-3 rounded-lg px-3 py-2 text-left text-sm transition"
            :class="i === current ? 'bg-indigo-50 text-indigo-700 dark:bg-indigo-500/15 dark:text-indigo-200' : 'text-slate-600 hover:bg-slate-50 dark:text-slate-300 dark:hover:bg-slate-700/50'"
            :data-chapter="c.at" @click="seek(c.at)"
          >
            <span class="w-10 shrink-0 font-mono text-xs leading-5 text-slate-400">{{ clock(c.at) }}</span>
            <span class="leading-5">{{ t(`manualVideo.ch.${c.key}`) }}</span>
          </button>
        </div>
      </div>
      <p v-if="video.state === 'error'" class="px-5 py-3 text-sm text-amber-700 dark:text-amber-300">{{ t('manualVideo.playError') }}</p>
    </div>

    <!-- Kartochkalar: har bir til uchun -->
    <div class="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-3">
      <div
        v-for="m in manuals"
        :key="m.key"
        class="group relative overflow-hidden rounded-2xl border border-slate-200 bg-white p-6 shadow-sm transition hover:shadow-lg dark:border-slate-700 dark:bg-slate-800"
      >
        <!-- Bezak -->
        <div
          class="absolute -right-6 -top-6 h-24 w-24 rounded-full opacity-10 transition group-hover:opacity-20"
          :class="m.accent"
        ></div>

        <!-- Ikonka -->
        <div
          class="mb-4 inline-flex h-12 w-12 items-center justify-center rounded-xl text-white"
          :class="m.badge"
        >
          <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"
               fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20" />
            <path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z" />
          </svg>
        </div>

        <h3 class="text-lg font-semibold text-slate-800 dark:text-slate-100">
          {{ m.name }}
        </h3>
        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
          {{ m.note }}
        </p>

        <!-- Tugmalar -->
        <div class="mt-5 flex items-center gap-2">
          <a
            :href="m.file"
            target="_blank"
            rel="noopener"
            class="inline-flex flex-1 items-center justify-center gap-2 rounded-lg bg-indigo-600 px-3 py-2 text-sm font-medium text-white transition hover:bg-indigo-700"
          >
            {{ t('manual.open') }}
          </a>
          <a
            :href="m.file"
            :download="m.download"
            class="inline-flex items-center justify-center gap-2 rounded-lg border border-slate-200 px-3 py-2 text-sm font-medium text-slate-600 transition hover:bg-slate-50 dark:border-slate-600 dark:text-slate-300 dark:hover:bg-slate-700"
          >
            {{ t('manual.download') }}
          </a>
        </div>
      </div>
    </div>

    <!-- Eslatma -->
    <div class="rounded-xl border border-slate-200 bg-slate-50 p-4 text-sm text-slate-500 dark:border-slate-700 dark:bg-slate-800/50 dark:text-slate-400">
      {{ t('manual.hint') }}
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { i18n } from '@/i18n'

const { t } = useI18n()

/* ---------- video qo'llanma ---------- */
// Fayllar public/manuals/ da: LIMS_video_uz.mp4 (subtitrsiz «toza» video) va LIMS_video_uz.vtt (o'zbekcha subtitr).
const VIDEO = '/manuals/LIMS_video_uz.mp4'
const SUBS = '/manuals/LIMS_video_uz.vtt'
const video = reactive({ state: 'checking' }) // checking | ok | missing | error
const player = ref(null)
const time = ref(0)

const chapters = [
  { at: 0, key: 'intro' },
  { at: 44, key: 'order' },
  { at: 122, key: 'receive' },
  { at: 184, key: 'worksheet' },
  { at: 240, key: 'templates' },
  { at: 279, key: 'approve' },
  { at: 310, key: 'sample' },
  { at: 337, key: 'release' },
  { at: 365, key: 'customerReport' },
  { at: 423, key: 'myProbes' },
  { at: 445, key: 'executive' },
  { at: 496, key: 'equipment' },
  { at: 507, key: 'labHome' },
  { at: 532, key: 'admin' },
  { at: 573, key: 'help' },
]
const current = computed(() => {
  let i = 0
  chapters.forEach((c, n) => { if (time.value + 0.5 >= c.at) i = n })
  return i
})
const clock = (s) => `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}`
const onTime = (e) => { time.value = e.target.currentTime || 0 }
function seek(at) {
  const v = player.value
  if (!v) return
  v.currentTime = at
  v.play?.().catch(() => {})
}

onMounted(async () => {
  try {
    const r = await fetch(VIDEO, { method: 'HEAD', cache: 'no-store' })
    const type = r.headers.get('content-type') || ''
    // fayl yo'q bo'lsa server SPA sahifasini (text/html) qaytaradi
    video.state = r.ok && !type.includes('text/html') ? 'ok' : 'missing'
  } catch {
    video.state = 'missing'
  }
})

const msg = {
  uz: { manualVideo: {
    title: "Video qo'llanma", meta: "10 daqiqa, o'zbekcha subtitr bilan", subs: "O'zbekcha", chapters: "Bo'limlar",
    playError: "Videoni ochib bo'lmadi — faylni yuklab olib, kompyuterda ko'ring.",
    ch: { intro: 'Kirish: namuna zanjiri', order: 'Buyurtmachi: yangi buyurtma', receive: 'Laboratoriya: buyurtmani qabul qilish',
      worksheet: "Ishchi varaq: o'lchovlarni kiritish", templates: 'Ishchi varaq shablonlari (konstruktor)', approve: 'Boshliq: tasdiqlash',
      sample: 'Namuna kartochkasi va tarixi', release: 'Jamlanma hisobotni chiqarish', customerReport: 'Buyurtmachi: jamlanma hisobot, Excel',
      myProbes: 'Mening probalarim', executive: 'Rahbariyat paneli, xodimlar, hisobotlar', equipment: 'Uskunalar',
      labHome: "Laboratoriya boshlig'i bosh sahifasi, buyurtmalar", admin: 'Administrator: foydalanuvchilar, rollar, audit',
      help: 'Yordamchi, qorong\'i mavzu, tillar' } } },
  ru: { manualVideo: {
    title: 'Видеоинструкция', meta: '10 минут, субтитры на узбекском', subs: 'Узбекский', chapters: 'Разделы',
    playError: 'Не удалось воспроизвести видео — скачайте файл и откройте на компьютере.',
    ch: { intro: 'Введение: путь пробы', order: 'Заказчик: новая заявка', receive: 'Лаборатория: приём заявки',
      worksheet: 'Рабочий лист: ввод измерений', templates: 'Шаблоны рабочих листов (конструктор)', approve: 'Начальник: утверждение',
      sample: 'Карточка пробы и история', release: 'Выпуск сводки', customerReport: 'Заказчик: сводка, Excel',
      myProbes: 'Мои пробы', executive: 'Панель руководства, сотрудники, отчёты', equipment: 'Оборудование',
      labHome: 'Главная начальника лаборатории, заявки', admin: 'Администратор: пользователи, роли, аудит',
      help: 'Помощник, тёмная тема, языки' } } },
  en: { manualVideo: {
    title: 'Video guide', meta: '10 minutes, Uzbek subtitles', subs: 'Uzbek', chapters: 'Chapters',
    playError: 'Could not play the video — download the file and open it on your computer.',
    ch: { intro: 'Intro: the sample chain', order: 'Customer: new request', receive: 'Laboratory: receiving a request',
      worksheet: 'Worksheet: entering measurements', templates: 'Worksheet templates (designer)', approve: 'Head: approval',
      sample: 'Sample card and history', release: 'Releasing the summary report', customerReport: 'Customer: summary report, Excel',
      myProbes: 'My samples', executive: 'Executive panel, staff, reports', equipment: 'Equipment',
      labHome: 'Lab head home page, requests', admin: 'Administrator: users, roles, audit',
      help: 'Assistant, dark theme, languages' } } },
}
Object.entries(msg).forEach(([l, m]) => i18n.global.mergeLocaleMessage(l, m))

// PDF fayllar public/manuals/ papkasida turadi (static).
const manuals = computed(() => [
  {
    key: 'ru',
    name: 'Русский',
    note: t('manual.ru_note'),
    file: '/manuals/LIMS_rukovodstvo_RU.pdf',
    download: 'LIMS_rukovodstvo_RU.pdf',
    badge: 'bg-indigo-600',
    accent: 'bg-indigo-500',
  },
  {
    key: 'uz_latin',
    name: "O'zbekcha (lotin)",
    note: t('manual.uz_latin_note'),
    file: '/manuals/LIMS_yoriqnoma_UZ_lotin.pdf',
    download: 'LIMS_yoriqnoma_UZ_lotin.pdf',
    badge: 'bg-emerald-600',
    accent: 'bg-emerald-500',
  },
  {
    key: 'uz_kiril',
    name: "Ўзбекча (кирил)",
    note: t('manual.uz_kiril_note'),
    file: '/manuals/LIMS_qollanma_UZ_kiril.pdf',
    download: 'LIMS_qollanma_UZ_kiril.pdf',
    badge: 'bg-amber-600',
    accent: 'bg-amber-500',
  },
])
</script>
