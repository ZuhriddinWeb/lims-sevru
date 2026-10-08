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
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

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
