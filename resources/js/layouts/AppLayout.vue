<script setup>
import { computed, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import * as icons from 'lucide-vue-next'
import {
  ChevronDown, ChevronsLeft, Languages, LogOut, Menu as MenuIcon,
  Moon, Sun, User as UserIcon, X,
} from 'lucide-vue-next'
import { useAuthStore } from '@/stores/auth'
import { useUiStore } from '@/stores/ui'
import { SUPPORTED_LOCALES, setI18nLocale, localized } from '@/i18n'
import ToastHost from '@/components/ui/ToastHost.vue'
import NotificationBell from '@/components/ui/NotificationBell.vue'
import HelpAssistant from '@/components/help/HelpAssistant.vue'

const auth = useAuthStore()
const ui = useUiStore()
const route = useRoute()
const router = useRouter()
const { t, locale } = useI18n()

/* ---------- Menyu: serverdan ruxsatlarga mos keladi ---------- */
const openGroups = ref(new Set(['catalog', 'administration']))

function menuTitle(item) {
  return item[`title_${locale.value}`] || item.title_ru
}

function iconFor(name) {
  if (!name) return icons.Circle
  // kebab-case -> PascalCase (lucide eksport nomi)
  const pascal = name.split('-').map((s) => s.charAt(0).toUpperCase() + s.slice(1)).join('')
  return icons[pascal] ?? icons.Circle
}

function toggleGroup(key) {
  const next = new Set(openGroups.value)
  next.has(key) ? next.delete(key) : next.add(key)
  openGroups.value = next
}

function isActive(item) {
  return item.route && route.name === item.route
}

function groupHasActive(item) {
  return (item.children ?? []).some(isActive)
}

function go(item) {
  if (item.route) {
    router.push({ name: item.route })
    ui.sidebarMobileOpen = false
  }
}

/* ---------- Til va mavzu ---------- */
const langOpen = ref(false)
const userOpen = ref(false)

async function changeLocale(code) {
  setI18nLocale(code)
  ui.setLocale(code)
  langOpen.value = false
  if (auth.isAuthenticated) {
    try { await auth.savePreferences({ locale: code }) } catch {}
  }
}

async function toggleTheme() {
  ui.toggleTheme()
  if (auth.isAuthenticated) {
    try { await auth.savePreferences({ theme: ui.theme }) } catch {}
  }
}

async function signOut() {
  await auth.logout()
  router.push({ name: 'login' })
}

const currentLocale = computed(() => SUPPORTED_LOCALES.find((l) => l.code === locale.value))
const userLab = computed(() => localized(auth.user?.laboratory_group ?? auth.user?.laboratory, 'name'))
const initials = computed(() => {
  const parts = (auth.user?.name ?? '').trim().split(/\s+/).slice(0, 2)
  return parts.map((p) => p[0]?.toUpperCase() ?? '').join('') || '·'
})
</script>

<template>
  <div class="min-h-screen bg-surface-sunken">
    <!-- Mobil fon -->
    <Transition enter-active-class="transition-opacity duration-150" enter-from-class="opacity-0"
                leave-active-class="transition-opacity duration-150" leave-to-class="opacity-0">
      <div v-if="ui.sidebarMobileOpen" class="fixed inset-0 z-40 bg-ink/40 lg:hidden" @click="ui.sidebarMobileOpen = false" />
    </Transition>

    <!-- Yon panel -->
    <aside
      class="fixed inset-y-0 left-0 z-50 flex flex-col bg-surface border-r border-line transition-[width,transform] duration-200 ease-out
             lg:translate-x-0"
      :class="[
        ui.sidebarCollapsed ? 'w-[68px]' : 'w-[262px]',
        ui.sidebarMobileOpen ? 'translate-x-0' : '-translate-x-full',
      ]"
    >
      <!-- Logotip: oltin probirlash tigli -->
      <div class="h-14 flex items-center gap-2.5 px-4 border-b border-line shrink-0">
       <div class="h-8 w-8 shrink-0 rounded-md overflow-hidden grid place-items-center">
          <img src="/images/logo.png" alt="Logo" class="h-full w-full object-contain" />
        </div>
        <div v-if="!ui.sidebarCollapsed" class="min-w-0 flex-1">
          <p class="text-[13.5px] font-semibold text-ink leading-tight truncate">{{ t('app.name') }}</p>
          <p class="text-[11px] text-ink-faint leading-tight truncate">{{ t('auth.signInSubtitle') }}</p>
        </div>
        <button class="btn-ghost btn-icon h-8 w-8 lg:hidden" @click="ui.sidebarMobileOpen = false">
          <X :size="17" />
        </button>
      </div>

      <!-- Navigatsiya -->
      <nav class="flex-1 overflow-y-auto py-2.5 px-2.5 space-y-0.5">
        <template v-for="item in auth.menu" :key="item.id">
          <!-- Bolalari bo'lgan guruh -->
          <div v-if="item.children?.length">
            <button
              class="w-full flex items-center gap-2.5 h-9 px-2.5 rounded-md text-[13.5px] font-medium transition-colors"
              :class="groupHasActive(item)
                ? 'text-ink bg-surface-sunken'
                : 'text-ink-muted hover:bg-surface-sunken hover:text-ink'"
              :title="ui.sidebarCollapsed ? menuTitle(item) : null"
              @click="toggleGroup(item.key)"
            >
              <component :is="iconFor(item.icon)" :size="17" class="shrink-0" />
              <template v-if="!ui.sidebarCollapsed">
                <span class="flex-1 text-left truncate">{{ menuTitle(item) }}</span>
                <ChevronDown :size="14" class="shrink-0 transition-transform duration-200"
                             :class="openGroups.has(item.key) ? 'rotate-180' : ''" />
              </template>
            </button>

            <Transition
              enter-active-class="transition-all duration-200 ease-out overflow-hidden"
              enter-from-class="opacity-0 max-h-0" enter-to-class="opacity-100 max-h-64"
              leave-active-class="transition-all duration-150 ease-in overflow-hidden"
              leave-from-class="opacity-100 max-h-64" leave-to-class="opacity-0 max-h-0"
            >
              <div v-if="openGroups.has(item.key) && !ui.sidebarCollapsed" class="mt-0.5 ml-[17px] pl-3 border-l border-line space-y-0.5">
                <button
                  v-for="child in item.children" :key="child.id"
                  class="w-full flex items-center gap-2.5 h-8 px-2.5 rounded-md text-[13px] transition-colors text-left"
                  :class="isActive(child)
                    ? 'text-assay-700 dark:text-assay-200 bg-assay-50 dark:bg-assay-900/35 font-medium'
                    : 'text-ink-muted hover:bg-surface-sunken hover:text-ink'"
                  @click="go(child)"
                >
                  <span class="truncate">{{ menuTitle(child) }}</span>
                </button>
              </div>
            </Transition>
          </div>

          <!-- Oddiy band -->
          <button
            v-else
            class="w-full flex items-center gap-2.5 h-9 px-2.5 rounded-md text-[13.5px] transition-colors relative"
            :class="isActive(item)
              ? 'text-assay-700 dark:text-assay-200 bg-assay-50 dark:bg-assay-900/35 font-medium'
              : 'text-ink-muted hover:bg-surface-sunken hover:text-ink'"
            :title="ui.sidebarCollapsed ? menuTitle(item) : null"
            @click="go(item)"
          >
            <span v-if="isActive(item)" class="absolute left-0 top-1.5 bottom-1.5 w-[3px] rounded-r bg-assay-500" />
            <component :is="iconFor(item.icon)" :size="17" class="shrink-0" />
            <span v-if="!ui.sidebarCollapsed" class="truncate">{{ menuTitle(item) }}</span>
          </button>
        </template>
      </nav>

      <!-- Yig'ish tugmasi -->
      <div class="border-t border-line p-2.5 shrink-0 hidden lg:block">
        <button class="btn-ghost w-full justify-start gap-2.5 h-9 px-2.5 text-[13px]" @click="ui.toggleSidebar">
          <ChevronsLeft :size="17" class="shrink-0 transition-transform duration-200" :class="ui.sidebarCollapsed ? 'rotate-180' : ''" />
          <span v-if="!ui.sidebarCollapsed">{{ t('common.close') }}</span>
        </button>
      </div>
    </aside>

    <!-- Asosiy qism -->
    <div class="transition-[padding] duration-200" :class="ui.sidebarCollapsed ? 'lg:pl-[68px]' : 'lg:pl-[262px]'">
      <header class="sticky top-0 z-30 h-14 bg-surface/85 backdrop-blur border-b border-line flex items-center gap-2 px-4 sm:px-6">
        <button class="btn-ghost btn-icon lg:hidden" @click="ui.sidebarMobileOpen = true">
          <MenuIcon :size="19" />
        </button>

        <div class="flex-1" />

        <!-- Eslatmalar -->
        <NotificationBell />

        <!-- Til -->
        <div class="relative">
          <button class="btn-ghost h-9 px-2.5 gap-1.5 text-[13px]" @click="langOpen = !langOpen">
            <Languages :size="17" />
            <span class="font-medium">{{ currentLocale?.short }}</span>
          </button>
          <div v-if="langOpen" class="fixed inset-0 z-10" @click="langOpen = false" />
          <Transition
            enter-active-class="transition duration-150 ease-out" enter-from-class="opacity-0 -translate-y-1"
            leave-active-class="transition duration-100 ease-in" leave-to-class="opacity-0"
          >
            <div v-if="langOpen" class="absolute right-0 mt-1 z-20 w-44 panel shadow-pop py-1">
              <button
                v-for="l in SUPPORTED_LOCALES" :key="l.code"
                class="w-full flex items-center justify-between px-3 py-2 text-[13.5px] hover:bg-surface-sunken transition-colors"
                :class="l.code === locale ? 'text-assay-600 dark:text-assay-300 font-medium' : 'text-ink'"
                @click="changeLocale(l.code)"
              >
                {{ l.label }}
                <span class="text-[11px] text-ink-faint font-mono">{{ l.short }}</span>
              </button>
            </div>
          </Transition>
        </div>

        <!-- Tun / kun -->
        <button
          class="btn-ghost btn-icon"
          :title="ui.theme === 'dark' ? t('theme.light') : t('theme.dark')"
          @click="toggleTheme"
        >
          <Transition
            mode="out-in"
            enter-active-class="transition duration-200" enter-from-class="opacity-0 rotate-90 scale-75"
            leave-active-class="transition duration-150" leave-to-class="opacity-0 -rotate-90 scale-75"
          >
            <Moon v-if="ui.theme === 'light'" :size="18" key="moon" />
            <Sun v-else :size="18" key="sun" />
          </Transition>
        </button>

        <!-- Foydalanuvchi -->
        <div class="relative ml-1">
          <button class="flex items-center gap-2.5 h-9 pl-1 pr-2 rounded-md hover:bg-surface-sunken transition-colors"
                  @click="userOpen = !userOpen">
            <span class="h-7 w-7 rounded-full bg-assay-100 dark:bg-assay-900/50 text-assay-700 dark:text-assay-200 grid place-items-center text-[11.5px] font-semibold">
              {{ initials }}
            </span>
            <span class="hidden sm:block text-left min-w-0 max-w-[150px]">
              <span class="block text-[13px] font-medium text-ink leading-tight truncate">{{ auth.user?.name }}</span>
              <span class="block text-[11px] text-ink-faint leading-tight truncate">{{ userLab || auth.user?.position }}</span>
            </span>
            <ChevronDown :size="14" class="text-ink-faint hidden sm:block" />
          </button>

          <div v-if="userOpen" class="fixed inset-0 z-10" @click="userOpen = false" />
          <Transition
            enter-active-class="transition duration-150 ease-out" enter-from-class="opacity-0 -translate-y-1"
            leave-active-class="transition duration-100 ease-in" leave-to-class="opacity-0"
          >
            <div v-if="userOpen" class="absolute right-0 mt-1 z-20 w-56 panel shadow-pop py-1">
              <div class="px-3 py-2.5 border-b border-line">
                <p class="text-[13.5px] font-medium text-ink truncate">{{ auth.user?.name }}</p>
                <p class="text-[12px] text-ink-muted truncate">
                  {{ localized(auth.user?.role, 'display_name') || auth.user?.role?.name }}
                </p>
              </div>
              <button class="w-full flex items-center gap-2.5 px-3 py-2 text-[13.5px] text-ink hover:bg-surface-sunken transition-colors"
                      @click="userOpen = false; router.push({ name: 'profile' })">
                <UserIcon :size="16" class="text-ink-muted" /> {{ t('auth.profile') }}
              </button>
              <button class="w-full flex items-center gap-2.5 px-3 py-2 text-[13.5px] text-crit hover:bg-crit-light dark:hover:bg-crit-dark/20 transition-colors"
                      @click="signOut">
                <LogOut :size="16" /> {{ t('auth.signOut') }}
              </button>
            </div>
          </Transition>
        </div>
      </header>

      <main class="p-4 sm:p-6 max-w-[1600px]">
        <RouterView v-slot="{ Component }">
          <Transition
            mode="out-in"
            enter-active-class="transition duration-200 ease-out"
            enter-from-class="opacity-0 translate-y-1"
            leave-active-class="transition duration-100 ease-in"
            leave-to-class="opacity-0"
          >
            <component :is="Component" />
          </Transition>
        </RouterView>
      </main>
    </div>

    <ToastHost />
    <HelpAssistant v-if="auth.isAuthenticated" />
  </div>
</template>
