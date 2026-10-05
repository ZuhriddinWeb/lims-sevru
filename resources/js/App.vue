<script setup>
import { onMounted, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useUiStore } from '@/stores/ui'
import { setI18nLocale } from '@/i18n'

const auth = useAuthStore()
const ui = useUiStore()
const router = useRouter()
const { t } = useI18n()

onMounted(() => {
  ui.applyTheme()
  document.documentElement.lang = ui.locale

  // Sessiya tugaganda login sahifasiga qaytarish
  window.addEventListener('lims:unauthenticated', () => {
    if (auth.isAuthenticated) {
      auth.user = null
      auth.permissions = []
      ui.error(t('auth.sessionExpired'))
    }
    router.push({ name: 'login' })
  })

  window.addEventListener('lims:forbidden', () => {
    ui.error(t('toast.forbidden'))
  })
})

// Foydalanuvchi kirganda uning saqlangan sozlamalarini qo'llash
watch(() => auth.user, (user) => {
  if (!user) return
  if (user.locale) { setI18nLocale(user.locale); ui.setLocale(user.locale) }
  if (user.theme) ui.setTheme(user.theme)
})
</script>

<template>
  <RouterView />
</template>
