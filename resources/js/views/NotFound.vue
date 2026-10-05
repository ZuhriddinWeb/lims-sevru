<script setup>
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { FileQuestion } from 'lucide-vue-next'
import EmptyState from '@/components/ui/EmptyState.vue'

const router = useRouter()
const { t } = useI18n()
</script>

<template>
  <div class="panel">
    <EmptyState :icon="FileQuestion" :title="t('error.pageTitle')" :hint="t('error.pageBody')">
      <button class="btn-primary" @click="router.push({ name: 'dashboard' })">{{ t('error.toDashboard') }}</button>
    </EmptyState>
  </div>
</template>
