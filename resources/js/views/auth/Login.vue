<script setup>
import { computed, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { Eye, EyeOff, Languages, LogIn, Moon, Sun } from 'lucide-vue-next'
import { useAuthStore } from '@/stores/auth'
import { useUiStore } from '@/stores/ui'
import { SUPPORTED_LOCALES, setI18nLocale } from '@/i18n'

const auth = useAuthStore()
const ui = useUiStore()
const router = useRouter()
const { t, locale } = useI18n()

const heroStats = computed(() => [
  { value: '4', label: t('auth.heroLabs') },
  { value: '8', label: t('auth.heroGroups') },
  { value: '24/7', label: t('auth.heroJournal') },
])

const form = ref({ username: '', password: '' })
const showPassword = ref(false)
const error = ref('')
const loading = ref(false)

async function submit() {
  error.value = ''
  loading.value = true
  try {
    const user = await auth.login(form.value)
    if (user.locale) { setI18nLocale(user.locale); ui.setLocale(user.locale) }
    if (user.theme) ui.setTheme(user.theme)
    router.push({ name: 'dashboard' })
  } catch (e) {
    error.value = e.response?.data?.errors?.username?.[0]
      || e.response?.data?.message
      || t('auth.invalidCredentials')
  } finally {
    loading.value = false
  }
}

function cycleLocale() {
  const i = SUPPORTED_LOCALES.findIndex((l) => l.code === locale.value)
  const next = SUPPORTED_LOCALES[(i + 1) % SUPPORTED_LOCALES.length]
  setI18nLocale(next.code)
  ui.setLocale(next.code)
}
</script>

<template>
  <div class="min-h-screen grid lg:grid-cols-[1.1fr_1fr] bg-surface">
    <!-- Chap: kontekst paneli -->
    <div class="hidden lg:flex flex-col justify-between p-10 bg-[#101A22] text-white relative overflow-hidden">
      <!-- Shlif qatlamlari: ruda kesimi metaforasi -->
      <svg class="absolute inset-0 w-full h-full opacity-[.12]" preserveAspectRatio="none" viewBox="0 0 400 600"
        aria-hidden="true">
        <path d="M0 140 Q100 110 200 145 T400 130 L400 190 Q300 170 200 200 T0 185 Z" fill="#D1A03F" />
        <path d="M0 230 Q110 200 200 240 T400 220 L400 270 Q290 250 200 285 T0 265 Z" fill="#2E7D8A" />
        <path d="M0 330 Q120 300 200 335 T400 315 L400 380 Q280 355 200 390 T0 370 Z" fill="#D1A03F" opacity=".7" />
      </svg>

      <div class="relative">
        <div class="flex items-center gap-3">
          <div class="h-10 w-10 rounded-lg overflow-hidden grid place-items-center bg-white/5">
            <img src="/images/logo.png" alt="Logo" class="h-full w-full object-contain" />
          </div>
          <div>
            <p class="text-[15px] font-semibold leading-tight">{{ t('app.name') }}</p>
            <p class="text-[12.5px] text-white/60 leading-tight">{{ t('auth.signInSubtitle') }}</p>
          </div>
        </div>
      </div>

      <div class="relative max-w-sm">
        <h2 class="text-[26px] font-semibold leading-snug tracking-[-.01em]">
          {{ t('auth.heroTitle') }}
        </h2>
        <p class="mt-3.5 text-[14px] leading-relaxed text-white/65">
          {{ t('auth.heroBody') }}
        </p>
      </div>

      <dl class="relative grid grid-cols-3 gap-6 max-w-sm">
        <div v-for="stat in heroStats" :key="stat.value">
          <dt class="text-[22px] font-semibold tnum">{{ stat.value }}</dt>
          <dd class="text-[12.5px] text-white/55">{{ stat.label }}</dd>
        </div>
      </dl>
    </div>

    <!-- O'ng: forma -->
    <div class="flex flex-col">
      <div class="flex items-center justify-end gap-1 p-4">
        <button class="btn-ghost h-9 px-2.5 gap-1.5 text-[13px]" @click="cycleLocale">
          <Languages :size="17" />
          <span class="font-medium">{{SUPPORTED_LOCALES.find(l => l.code === locale)?.short}}</span>
        </button>
        <button class="btn-ghost btn-icon" @click="ui.toggleTheme">
          <Moon v-if="ui.theme === 'light'" :size="18" />
          <Sun v-else :size="18" />
        </button>
      </div>

      <div class="flex-1 grid place-items-center px-6 pb-16">
        <div class="w-full max-w-[360px]">
          <div class="lg:hidden flex items-center gap-2.5 mb-8">
            <div class="h-9 w-9 rounded-md bg-assay-500 grid place-items-center text-white font-semibold text-[13px]">Au
            </div>
            <p class="text-[15px] font-semibold text-ink">{{ t('app.name') }}</p>
          </div>

          <h1 class="text-[24px] font-semibold tracking-[-.01em] text-ink">{{ t('auth.signIn') }}</h1>
          <p class="mt-1.5 text-[14px] text-ink-muted">{{ t('auth.signInSubtitle') }}</p>

          <form class="mt-7 space-y-4" @submit.prevent="submit">
            <div>
              <label class="field-label" for="username">{{ t('auth.username') }}</label>
              <input id="username" v-model="form.username" class="field-input" autocomplete="username" autofocus
                required />
            </div>

            <div>
              <label class="field-label" for="password">{{ t('auth.password') }}</label>
              <div class="relative">
                <input id="password" v-model="form.password" :type="showPassword ? 'text' : 'password'"
                  class="field-input pr-10" autocomplete="current-password" required />
                <button type="button"
                  class="absolute right-2.5 top-1/2 -translate-y-1/2 text-ink-faint hover:text-ink transition-colors"
                  @click="showPassword = !showPassword" tabindex="-1">
                  <component :is="showPassword ? EyeOff : Eye" :size="17" />
                </button>
              </div>
            </div>

            <Transition enter-active-class="transition duration-200 ease-out"
              enter-from-class="opacity-0 -translate-y-1" leave-active-class="transition duration-150"
              leave-to-class="opacity-0">
              <p v-if="error" class="text-[13px] text-crit bg-crit-light dark:bg-crit-dark/25 rounded-md px-3 py-2.5">
                {{ error }}
              </p>
            </Transition>

            <button type="submit" class="btn-primary w-full" :disabled="loading">
              <LogIn :size="17" />
              {{ loading ? t('common.loading') : t('auth.signIn') }}
            </button>
          </form>
        </div>
      </div>
    </div>
  </div>
</template>
