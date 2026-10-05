import { reactive, ref, computed, watch } from 'vue'
import api from '@/lib/api'
import { useUiStore } from '@/stores/ui'
import { useI18n } from 'vue-i18n'

/**
 * Har qanday resurs uchun ro'yxat + CRUD holatini boshqaradi.
 * Barcha modullar shu bitta kompozable orqali ishlaydi.
 */
export function useCrud(resource) {
  const ui = useUiStore()
  const { t } = useI18n()

  const rows = ref([])
  const meta = ref({})
  const loading = ref(false)
  const saving = ref(false)
  const errors = ref({})

  const params = reactive({
    page: 1,
    per_page: 15,
    search: '',
    sort_by: resource.defaultSort?.by ?? 'created_at',
    sort_dir: resource.defaultSort?.dir ?? 'desc',
    ...(resource.defaultFilters ?? {}),
  })

  const activeFilterCount = computed(() => {
    const ignore = ['page', 'per_page', 'search', 'sort_by', 'sort_dir']
    return Object.entries(params).filter(([k, v]) =>
      !ignore.includes(k) && v !== '' && v !== null && v !== undefined && v !== false
    ).length
  })

  async function fetch() {
    loading.value = true
    try {
      const clean = Object.fromEntries(
        Object.entries(params).filter(([, v]) => v !== '' && v !== null && v !== undefined && v !== false)
      )
      const { data } = await api.get(resource.endpoint, { params: clean })
      if (Array.isArray(data)) {
        rows.value = data
        meta.value = { total: data.length, current_page: 1, last_page: 1, from: 1, to: data.length }
      } else {
        rows.value = data.data ?? []
        meta.value = {
          current_page: data.current_page, last_page: data.last_page,
          from: data.from, to: data.to, total: data.total,
        }
      }
    } catch (e) {
      if (e.response?.status !== 403) ui.error(t('toast.error'))
    } finally {
      loading.value = false
    }
  }

  async function save(payload, id = null) {
    saving.value = true
    errors.value = {}
    try {
      const { data } = id
        ? await api.put(`${resource.endpoint}/${id}`, payload)
        : await api.post(resource.endpoint, payload)
      ui.success(t(id ? 'toast.updated' : 'toast.created'))
      await fetch()
      return data
    } catch (e) {
      if (e.response?.status === 422) {
        errors.value = e.response.data.errors ?? {}
        ui.error(t('toast.validationError'))
      } else if (e.response?.status === 403) {
        ui.error(t('toast.forbidden'))
      } else {
        ui.error(e.response?.data?.message || t('toast.error'))
      }
      throw e
    } finally {
      saving.value = false
    }
  }

  async function remove(id) {
    saving.value = true
    try {
      await api.delete(`${resource.endpoint}/${id}`)
      ui.success(t('toast.deleted'))
      // Oxirgi qator o'chirilsa — oldingi sahifaga qaytish
      if (rows.value.length === 1 && params.page > 1) params.page -= 1
      await fetch()
    } catch (e) {
      ui.error(e.response?.data?.message || t('toast.error'))
      throw e
    } finally {
      saving.value = false
    }
  }

  function setSort(key) {
    if (params.sort_by === key) {
      params.sort_dir = params.sort_dir === 'asc' ? 'desc' : 'asc'
    } else {
      params.sort_by = key
      params.sort_dir = 'asc'
    }
  }

  function resetFilters() {
    const ignore = ['page', 'per_page', 'sort_by', 'sort_dir']
    Object.keys(params).forEach((k) => {
      if (!ignore.includes(k)) params[k] = k === 'search' ? '' : null
    })
    Object.assign(params, resource.defaultFilters ?? {})
    params.page = 1
  }

  // Qidiruv — kechiktirib (debounce)
  let searchTimer = null
  watch(() => params.search, () => {
    clearTimeout(searchTimer)
    searchTimer = setTimeout(() => { params.page = 1; fetch() }, 350)
  })

  watch(
    () => [params.page, params.per_page, params.sort_by, params.sort_dir],
    () => fetch()
  )

  return {
    rows, meta, loading, saving, errors, params,
    activeFilterCount, fetch, save, remove, setSort, resetFilters,
  }
}
