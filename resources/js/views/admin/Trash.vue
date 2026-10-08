<template>
  <div class="space-y-6">
    <!-- Sarlavha -->
    <div class="flex items-center justify-between">
      <div>
        <h1 class="text-2xl font-semibold text-slate-800 dark:text-slate-100">
          {{ t('trash.title') }}
        </h1>
        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
          {{ t('trash.subtitle') }}
        </p>
      </div>
      <button
        class="inline-flex items-center gap-2 rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-medium text-slate-600 transition hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300 dark:hover:bg-slate-700"
        :disabled="loading"
        @click="refreshAll"
      >
        <span>{{ t('trash.refresh') }}</span>
      </button>
    </div>

    <!-- Kartochkalar: har bir tur -->
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5">
      <button
        v-for="c in cards"
        :key="c.type"
        class="group rounded-xl border bg-white p-4 text-left shadow-sm transition hover:shadow-md dark:bg-slate-800"
        :class="activeType === c.type
          ? 'border-indigo-500 ring-1 ring-indigo-500 dark:border-indigo-400'
          : 'border-slate-200 dark:border-slate-700'"
        @click="selectType(c.type)"
      >
        <div class="flex items-start justify-between">
          <span class="text-sm font-medium text-slate-600 dark:text-slate-300">
            {{ labelOf(c) }}
          </span>
          <span
            class="rounded-full px-2 py-0.5 text-xs font-semibold"
            :class="c.count > 0
              ? 'bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-300'
              : 'bg-slate-100 text-slate-500 dark:bg-slate-700 dark:text-slate-400'"
          >
            {{ c.count }}
          </span>
        </div>
        <div class="mt-3 text-2xl font-semibold text-slate-800 dark:text-slate-100">
          {{ c.count }}
        </div>
        <div class="mt-1 text-xs text-slate-400 dark:text-slate-500">
          {{ t('trash.deleted_records') }}
        </div>
      </button>
    </div>

    <!-- Ro'yxat -->
    <div class="rounded-xl border border-slate-200 bg-white shadow-sm dark:border-slate-700 dark:bg-slate-800">
      <div class="flex flex-col gap-3 border-b border-slate-200 p-4 dark:border-slate-700 sm:flex-row sm:items-center sm:justify-between">
        <h2 class="text-sm font-semibold text-slate-700 dark:text-slate-200">
          {{ activeLabel }}
        </h2>
        <input
          v-model="search"
          type="text"
          :placeholder="t('trash.search')"
          class="w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-700 outline-none transition focus:border-indigo-500 dark:border-slate-600 dark:bg-slate-900 dark:text-slate-200 sm:w-64"
          @input="onSearch"
        />
      </div>

      <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-slate-200 dark:divide-slate-700">
          <thead class="bg-slate-50 dark:bg-slate-900/40">
            <tr>
              <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">
                {{ t('trash.col_record') }}
              </th>
              <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">
                {{ t('trash.col_info') }}
              </th>
              <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">
                {{ t('trash.col_deleted_at') }}
              </th>
              <th class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">
                {{ t('trash.col_actions') }}
              </th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100 dark:divide-slate-700/60">
            <tr v-if="loading">
              <td colspan="4" class="px-4 py-8 text-center text-sm text-slate-400">
                {{ t('trash.loading') }}
              </td>
            </tr>
            <tr v-else-if="rows.length === 0">
              <td colspan="4" class="px-4 py-8 text-center text-sm text-slate-400">
                {{ t('trash.empty') }}
              </td>
            </tr>
            <tr
              v-for="row in rows"
              v-else
              :key="row.id"
              class="transition hover:bg-slate-50 dark:hover:bg-slate-700/30"
            >
              <td class="px-4 py-3 text-sm font-medium text-slate-800 dark:text-slate-100">
                {{ row.title }}
              </td>
              <td class="px-4 py-3 text-sm text-slate-500 dark:text-slate-400">
                {{ row.subtitle || '—' }}
              </td>
              <td class="px-4 py-3 text-sm text-slate-500 dark:text-slate-400">
                {{ row.deleted_at || '—' }}
              </td>
              <td class="px-4 py-3 text-right">
                <div class="inline-flex items-center gap-2">
                  <button
                    v-if="canRestore"
                    class="rounded-lg bg-emerald-50 px-3 py-1.5 text-xs font-medium text-emerald-700 transition hover:bg-emerald-100 dark:bg-emerald-500/15 dark:text-emerald-300 dark:hover:bg-emerald-500/25"
                    :disabled="busyId === row.id"
                    @click="confirmRestore(row)"
                  >
                    {{ t('trash.restore') }}
                  </button>
                  <!-- "Butunlay o'chirish" tugmasi ataylab berkitilgan (ISO 17025). -->
                  <!-- Kerak bo'lsa pastdagi button blokidagi comment belgilarini oling. -->
                  <!--
                  <button
                    v-if="canForceDelete"
                    class="rounded-lg bg-rose-50 px-3 py-1.5 text-xs font-medium text-rose-700 transition hover:bg-rose-100 dark:bg-rose-500/15 dark:text-rose-300 dark:hover:bg-rose-500/25"
                    :disabled="busyId === row.id"
                    @click="confirmForce(row)"
                  >
                    {{ t('trash.delete_forever') }}
                  </button>
                  -->
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Paginatsiya -->
      <div
        v-if="meta.last_page > 1"
        class="flex items-center justify-between border-t border-slate-200 p-4 dark:border-slate-700"
      >
        <span class="text-xs text-slate-400">
          {{ t('trash.page') }} {{ meta.current_page }} / {{ meta.last_page }}
        </span>
        <div class="flex gap-2">
          <button
            class="rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-medium text-slate-600 transition hover:bg-slate-50 disabled:opacity-40 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-700"
            :disabled="meta.current_page <= 1"
            @click="goPage(meta.current_page - 1)"
          >
            {{ t('trash.prev') }}
          </button>
          <button
            class="rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-medium text-slate-600 transition hover:bg-slate-50 disabled:opacity-40 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-700"
            :disabled="meta.current_page >= meta.last_page"
            @click="goPage(meta.current_page + 1)"
          >
            {{ t('trash.next') }}
          </button>
        </div>
      </div>
    </div>

    <!-- Tasdiqlash modali -->
    <div
      v-if="dialog.open"
      class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 p-4"
      @click.self="closeDialog"
    >
      <div class="w-full max-w-md rounded-2xl bg-white p-6 shadow-xl dark:bg-slate-800">
        <h3 class="text-lg font-semibold text-slate-800 dark:text-slate-100">
          {{ dialog.mode === 'restore' ? t('trash.confirm_restore_title') : t('trash.confirm_force_title') }}
        </h3>
        <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">
          <template v-if="dialog.mode === 'restore'">
            {{ t('trash.confirm_restore_text') }}
          </template>
          <template v-else>
            {{ t('trash.confirm_force_text') }}
          </template>
        </p>
        <p class="mt-3 rounded-lg bg-slate-50 px-3 py-2 text-sm font-medium text-slate-700 dark:bg-slate-900 dark:text-slate-200">
          {{ dialog.row?.title }}
        </p>
        <div class="mt-6 flex justify-end gap-3">
          <button
            class="rounded-lg border border-slate-200 px-4 py-2 text-sm font-medium text-slate-600 transition hover:bg-slate-50 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-700"
            @click="closeDialog"
          >
            {{ t('trash.cancel') }}
          </button>
          <button
            v-if="dialog.mode === 'restore'"
            class="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-emerald-700 disabled:opacity-50"
            :disabled="dialog.busy"
            @click="doRestore"
          >
            {{ t('trash.restore') }}
          </button>
          <button
            v-else
            class="rounded-lg bg-rose-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-rose-700 disabled:opacity-50"
            :disabled="dialog.busy"
            @click="doForce"
          >
            {{ t('trash.delete_forever') }}
          </button>
        </div>
      </div>
    </div>

    <!-- Ichki toast (tashqi store'ga bog'liq emas) -->
    <transition name="fade">
      <div
        v-if="toast.show"
        class="fixed bottom-6 right-6 z-50 max-w-sm rounded-lg px-4 py-3 text-sm font-medium text-white shadow-lg"
        :class="toast.type === 'error' ? 'bg-rose-600' : 'bg-emerald-600'"
      >
        {{ toast.message }}
      </div>
    </transition>
  </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import api from '@/lib/api'
import { useAuthStore } from '@/stores/auth'

const { t, locale } = useI18n()
const auth = useAuthStore()

const cards = ref([])
const activeType = ref('samples')
const rows = ref([])
const meta = reactive({ current_page: 1, last_page: 1, per_page: 20, total: 0 })
const loading = ref(false)
const busyId = ref(null)
const search = ref('')
let searchTimer = null

const dialog = reactive({ open: false, mode: 'restore', row: null, busy: false })

// Ichki toast — hech qanday tashqi store kerak emas.
const toast = reactive({ show: false, message: '', type: 'success' })
let toastTimer = null
function showToast(message, type = 'success') {
  toast.message = message
  toast.type = type
  toast.show = true
  clearTimeout(toastTimer)
  toastTimer = setTimeout(() => (toast.show = false), 3000)
}

/** Ruxsatlar — auth store'da can() bo'lsa ishlatamiz, aks holda backend himoya qiladi. */
function can(ability) {
  if (typeof auth.can === 'function') return auth.can(ability)
  if (Array.isArray(auth.permissions)) return auth.permissions.includes(ability)
  return true
}
const canRestore = computed(() => can('trash.restore'))
const canForceDelete = computed(() => can('trash.delete'))

function labelOf(c) {
  const l = locale.value
  if (l === 'uz' && c.label_uz) return c.label_uz
  if (l === 'en' && c.label_en) return c.label_en
  return c.label_ru || c.label_uz || c.label_en || c.type
}

const activeLabel = computed(() => {
  const c = cards.value.find((x) => x.type === activeType.value)
  return c ? labelOf(c) : ''
})

async function loadCounts() {
  try {
    const { data } = await api.get('/trash/counts')
    cards.value = data.data || []
  } catch (e) {
    showToast(t('trash.load_error'), 'error')
  }
}

async function loadRows(page = 1) {
  loading.value = true
  try {
    const { data } = await api.get(`/trash/${activeType.value}`, {
      params: { page, search: search.value, per_page: meta.per_page },
    })
    rows.value = data.data || []
    Object.assign(meta, data.meta || {})
  } catch (e) {
    rows.value = []
    showToast(t('trash.load_error'), 'error')
  } finally {
    loading.value = false
  }
}

function selectType(type) {
  if (activeType.value === type) return
  activeType.value = type
  search.value = ''
  meta.current_page = 1
  loadRows(1)
}

function onSearch() {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(() => loadRows(1), 350)
}

function goPage(page) {
  if (page < 1 || page > meta.last_page) return
  loadRows(page)
}

async function refreshAll() {
  await loadCounts()
  await loadRows(meta.current_page)
}

function confirmRestore(row) {
  dialog.mode = 'restore'
  dialog.row = row
  dialog.open = true
}
function confirmForce(row) {
  dialog.mode = 'force'
  dialog.row = row
  dialog.open = true
}
function closeDialog() {
  if (dialog.busy) return
  dialog.open = false
  dialog.row = null
}

async function doRestore() {
  if (!dialog.row) return
  dialog.busy = true
  busyId.value = dialog.row.id
  try {
    const { data } = await api.post(`/trash/${activeType.value}/${dialog.row.id}/restore`)
    showToast(data.message || t('trash.restored'), 'success')
    dialog.open = false
    await refreshAll()
  } catch (e) {
    showToast(e?.response?.data?.message || t('trash.restore_error'), 'error')
  } finally {
    dialog.busy = false
    busyId.value = null
  }
}

async function doForce() {
  if (!dialog.row) return
  dialog.busy = true
  busyId.value = dialog.row.id
  try {
    const { data } = await api.delete(`/trash/${activeType.value}/${dialog.row.id}`)
    showToast(data.message || t('trash.deleted'), 'success')
    dialog.open = false
    await refreshAll()
  } catch (e) {
    showToast(e?.response?.data?.message || t('trash.delete_error'), 'error')
  } finally {
    dialog.busy = false
    busyId.value = null
  }
}

onMounted(async () => {
  await loadCounts()
  await loadRows(1)
})
</script>

<style scoped>
.fade-enter-active,
.fade-leave-active {
  transition: opacity 0.25s ease;
}
.fade-enter-from,
.fade-leave-to {
  opacity: 0;
}
</style>