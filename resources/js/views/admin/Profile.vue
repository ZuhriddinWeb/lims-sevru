<script setup>
import { reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { Moon, Sun } from 'lucide-vue-next'
import api from '@/lib/api'
import PageHeader from '@/components/ui/PageHeader.vue'
import { useAuthStore } from '@/stores/auth'
import { useUiStore } from '@/stores/ui'
import { SUPPORTED_LOCALES, setI18nLocale, localized } from '@/i18n'

const { t, locale } = useI18n()
const auth = useAuthStore()
const ui = useUiStore()

const saving = ref(false)
const errors = ref({})

const form = reactive({
  name: auth.user?.name ?? '',
  email: auth.user?.email ?? '',
  phone: auth.user?.phone ?? '',
  password: '',
  password_confirmation: '',
})

async function save() {
  saving.value = true
  errors.value = {}
  try {
    const { data } = await api.put('/profile', form)
    auth.setUser(data.user)
    form.password = ''
    form.password_confirmation = ''
    ui.success(t('toast.updated'))
  } catch (e) {
    if (e.response?.status === 422) {
      errors.value = e.response.data.errors ?? {}
      ui.error(t('toast.validationError'))
    } else {
      ui.error(t('toast.error'))
    }
  } finally {
    saving.value = false
  }
}

async function changeLocale(code) {
  setI18nLocale(code)
  ui.setLocale(code)
  try { await auth.savePreferences({ locale: code }) } catch {}
}

async function changeTheme(theme) {
  ui.setTheme(theme)
  try { await auth.savePreferences({ theme }) } catch {}
}
</script>

<template>
  <div class="max-w-3xl">
    <PageHeader :title="t('auth.profile')" :subtitle="auth.user?.position" />

    <div class="grid gap-5 lg:grid-cols-[1fr_260px]">
      <section class="panel p-5">
        <h2 class="text-[14px] font-semibold text-ink mb-4">{{ t('user.one') }}</h2>

        <div class="grid gap-4 sm:grid-cols-2">
          <div class="sm:col-span-2">
            <label class="field-label">{{ t('user.name') }}</label>
            <input v-model="form.name" class="field-input" :class="{ 'field-input-error': errors.name }" />
            <p v-if="errors.name" class="mt-1.5 text-[12.5px] text-crit">{{ errors.name[0] }}</p>
          </div>
          <div>
            <label class="field-label">{{ t('user.email') }}</label>
            <input v-model="form.email" type="email" class="field-input" />
          </div>
          <div>
            <label class="field-label">{{ t('user.phone') }}</label>
            <input v-model="form.phone" class="field-input" />
          </div>
          <div>
            <label class="field-label">{{ t('user.password') }}</label>
            <input v-model="form.password" type="password" class="field-input" autocomplete="new-password" />
            <p class="mt-1.5 text-[12.5px] text-ink-faint">{{ t('user.passwordHint') }}</p>
          </div>
          <div>
            <label class="field-label">{{ t('user.passwordConfirm') }}</label>
            <input v-model="form.password_confirmation" type="password" class="field-input" autocomplete="new-password" />
            <p v-if="errors.password" class="mt-1.5 text-[12.5px] text-crit">{{ errors.password[0] }}</p>
          </div>
        </div>

        <div class="flex justify-end mt-5">
          <button class="btn-primary" :disabled="saving" @click="save">
            {{ saving ? t('common.loading') : t('common.saveChanges') }}
          </button>
        </div>
      </section>

      <aside class="space-y-5">
        <section class="panel p-4">
          <h2 class="text-[13px] font-semibold text-ink mb-3">{{ t('auth.settings') }}</h2>

          <p class="text-[11.5px] text-ink-faint mb-1.5">{{ t('language.label') }}</p>
          <div class="grid grid-cols-3 gap-1 mb-4">
            <button
              v-for="l in SUPPORTED_LOCALES" :key="l.code"
              class="h-9 rounded-md text-[13px] font-medium transition-colors"
              :class="l.code === locale
                ? 'bg-assay-500 text-white'
                : 'text-ink-muted hover:bg-surface-sunken'"
              @click="changeLocale(l.code)"
            >{{ l.short }}</button>
          </div>

          <p class="text-[11.5px] text-ink-faint mb-1.5">{{ t('theme.light') }} / {{ t('theme.dark') }}</p>
          <div class="grid grid-cols-2 gap-1">
            <button
              class="h-9 rounded-md text-[13px] font-medium flex items-center justify-center gap-1.5 transition-colors"
              :class="ui.theme === 'light' ? 'bg-assay-500 text-white' : 'text-ink-muted hover:bg-surface-sunken'"
              @click="changeTheme('light')"
            ><Sun :size="15" /> {{ t('theme.light').split(' ')[0] }}</button>
            <button
              class="h-9 rounded-md text-[13px] font-medium flex items-center justify-center gap-1.5 transition-colors"
              :class="ui.theme === 'dark' ? 'bg-assay-500 text-white' : 'text-ink-muted hover:bg-surface-sunken'"
              @click="changeTheme('dark')"
            ><Moon :size="15" /> {{ t('theme.dark').split(' ')[0] }}</button>
          </div>
        </section>

        <section class="panel p-4">
          <dl class="space-y-3">
            <div>
              <dt class="text-[11.5px] text-ink-faint">{{ t('user.username') }}</dt>
              <dd class="text-[13.5px] text-ink font-mono mt-0.5">{{ auth.user?.username }}</dd>
            </div>
            <div>
              <dt class="text-[11.5px] text-ink-faint">{{ t('user.role') }}</dt>
              <dd class="text-[13.5px] text-ink mt-0.5">
                {{ localized(auth.user?.role, 'display_name') || auth.user?.role?.name }}
              </dd>
            </div>
            <div v-if="auth.user?.laboratory">
              <dt class="text-[11.5px] text-ink-faint">{{ t('user.laboratory') }}</dt>
              <dd class="text-[13.5px] text-ink mt-0.5">{{ localized(auth.user.laboratory, 'name') }}</dd>
            </div>
            <div v-if="auth.user?.laboratory_group">
              <dt class="text-[11.5px] text-ink-faint">{{ t('user.group') }}</dt>
              <dd class="text-[13.5px] text-ink mt-0.5">{{ localized(auth.user.laboratory_group, 'name') }}</dd>
            </div>
          </dl>
        </section>
      </aside>
    </div>
  </div>
</template>
