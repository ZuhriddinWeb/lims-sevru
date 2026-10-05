<script setup>
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { ArrowDown, ArrowUp, ChevronsUpDown } from 'lucide-vue-next'
import StatusBadge from '@/components/ui/StatusBadge.vue'
import TableSkeleton from '@/components/ui/TableSkeleton.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import { formatDate, formatDateTime, formatNumber } from '@/lib/format'
import { localized } from '@/i18n'

const props = defineProps({
  columns: { type: Array, required: true },
  rows: { type: Array, default: () => [] },
  loading: Boolean,
  sortBy: String,
  sortDir: String,
  searching: Boolean, // qidiruv/filtr qo'llanganmi — bo'sh holat matni shunga qarab
  rowKey: { type: String, default: 'id' },
  clickable: { type: Boolean, default: true },
})
const emit = defineEmits(['sort', 'row-click'])

const { t, locale } = useI18n()

function headerLabel(col) {
  return col.labelKey ? t(col.labelKey) : col.label
}

// Ustun turiga qarab qiymatni ko'rsatishga tayyorlash
function cellValue(row, col) {
  const raw = col.key.split('.').reduce((o, k) => (o ? o[k] : null), row)

  if (col.format) return col.format(row, { t, locale: locale.value })

  switch (col.type) {
    case 'date':      return formatDate(raw)
    case 'datetime':  return formatDateTime(raw)
    case 'number':    return formatNumber(raw, col.digits ?? 2)
    case 'localized': return localized(raw, col.base ?? 'name')
    case 'enum':      return raw ? t(`${col.enumPrefix}.${raw}`) : '—'
    case 'bool':      return raw ? t('common.yes') : t('common.no')
    default:          return raw ?? '—'
  }
}

// Status ustuni: format() berilgan bo'lsa undan, aks holda xom qiymatdan
function statusValue(row, col) {
  if (col.format) return col.format(row, { t, locale: locale.value })
  return col.key.split('.').reduce((o, k) => (o ? o[k] : null), row)
}
</script>

<template>
  <div class="panel overflow-hidden">
    <!-- Yuklanmoqda -->
    <TableSkeleton v-if="loading && !rows.length" :cols="Math.min(columns.length + 1, 6)" />

    <!-- Bo'sh -->
    <EmptyState
      v-else-if="!rows.length"
      :title="searching ? t('common.nothingFound') : t('common.noData')"
      :hint="searching ? t('common.nothingFoundHint') : t('common.noDataHint')"
    >
      <slot name="empty-action" />
    </EmptyState>

    <template v-else>
      <!-- Keng ekran: jadval -->
      <div class="hidden md:block overflow-x-auto">
        <table class="w-full text-left border-collapse">
          <thead>
            <tr class="border-b border-line bg-surface-sunken/50">
              <th
                v-for="col in columns" :key="col.key"
                class="px-4 py-2.5 text-[12.5px] font-semibold text-ink-muted whitespace-nowrap"
                :class="col.align === 'right' ? 'text-right' : ''"
                :style="col.width ? { width: col.width } : null"
              >
                <button
                  v-if="col.sortable"
                  class="inline-flex items-center gap-1.5 hover:text-ink transition-colors"
                  :class="col.align === 'right' ? 'flex-row-reverse' : ''"
                  @click="emit('sort', col.key)"
                >
                  {{ headerLabel(col) }}
                  <component
                    :is="sortBy === col.key ? (sortDir === 'asc' ? ArrowUp : ArrowDown) : ChevronsUpDown"
                    :size="13"
                    :class="sortBy === col.key ? 'text-assay-500' : 'text-ink-faint'"
                  />
                </button>
                <span v-else>{{ headerLabel(col) }}</span>
              </th>
              <th class="px-4 py-2.5 w-px"><span class="sr-only">{{ t('common.actions') }}</span></th>
            </tr>
          </thead>

          <tbody class="divide-y divide-line">
            <tr
              v-for="(row, i) in rows" :key="row[rowKey]"
              class="group transition-colors animate-row-in"
              :class="clickable ? 'cursor-pointer hover:bg-surface-sunken/70' : 'hover:bg-surface-sunken/40'"
              :style="{ animationDelay: `${Math.min(i * 18, 220)}ms` }"
              @click="clickable && emit('row-click', row)"
            >
              <td
                v-for="col in columns" :key="col.key"
                class="px-4 py-3 text-[13.5px] align-middle"
                :class="[
                  col.align === 'right' ? 'text-right tnum' : '',
                  col.type === 'number' || col.type === 'date' || col.type === 'datetime' ? 'tnum' : '',
                  col.primary ? 'font-medium text-ink' : 'text-ink-muted',
                  col.nowrap === false ? '' : 'whitespace-nowrap',
                ]"
              >
                <slot :name="`cell:${col.key}`" :row="row" :value="cellValue(row, col)">
                  <StatusBadge v-if="col.type === 'status'" :status="statusValue(row, col)" size="sm" />
                  <span v-else-if="col.mono" class="font-mono text-[12.5px]">{{ cellValue(row, col) }}</span>
                  <span v-else>{{ cellValue(row, col) }}</span>
                </slot>
              </td>

              <td class="px-4 py-3 text-right whitespace-nowrap" @click.stop>
                <div class="flex items-center justify-end gap-0.5 md:opacity-0 md:group-hover:opacity-100 focus-within:opacity-100 transition-opacity">
                  <slot name="row-actions" :row="row" />
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Mobil: kartochkalar -->
      <div class="md:hidden divide-y divide-line">
        <div
          v-for="(row, i) in rows" :key="row[rowKey]"
          class="p-4 animate-row-in"
          :style="{ animationDelay: `${Math.min(i * 25, 200)}ms` }"
          @click="clickable && emit('row-click', row)"
        >
          <div class="flex items-start justify-between gap-3">
            <div class="min-w-0 flex-1">
              <p class="text-[15px] font-medium text-ink truncate">
                <slot name="mobile-title" :row="row">{{ cellValue(row, columns[0]) }}</slot>
              </p>
              <dl class="mt-2 grid grid-cols-2 gap-x-4 gap-y-1.5">
                <template v-for="col in columns.slice(1, 5)" :key="col.key">
                  <div class="min-w-0">
                    <dt class="text-[11.5px] text-ink-faint truncate">{{ headerLabel(col) }}</dt>
                    <dd class="text-[13px] text-ink-muted truncate tnum">
                      <StatusBadge v-if="col.type === 'status'" :status="statusValue(row, col)" size="sm" />
                      <span v-else>{{ cellValue(row, col) }}</span>
                    </dd>
                  </div>
                </template>
              </dl>
            </div>
            <div class="flex flex-col gap-1 shrink-0" @click.stop>
              <slot name="row-actions" :row="row" />
            </div>
          </div>
        </div>
      </div>
    </template>

    <slot name="footer" />
  </div>
</template>
