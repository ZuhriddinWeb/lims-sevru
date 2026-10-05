<script setup>
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import CrudPage from '@/components/crud/CrudPage.vue'
import { getResource } from '@/resources'

// Universal sahifa: marshrut meta'sidagi resurs ta'rifini oladi va CRUD ni quradi
const route = useRoute()
const resource = computed(() => getResource(route.meta.resource))
</script>

<template>
  <CrudPage v-if="resource" :key="resource.key" :resource="resource" />
</template>
