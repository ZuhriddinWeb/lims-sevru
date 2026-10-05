<script setup>
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { ShieldOff } from 'lucide-vue-next'
import EmptyState from '@/components/ui/EmptyState.vue'

const router = useRouter()
const { t } = useI18n()
</script>

<template>
  <div class="panel">
    <EmptyState :icon="ShieldOff" :title="t('error.forbiddenTitle')" :hint="t('error.forbiddenBody')">
      <button class="btn-outline" @click="router.push({ name: 'dashboard' })">{{ t('error.toDashboard') }}</button>
    </EmptyState>
  </div>
</template>
