<script setup lang="ts">
  import { useData } from 'vitepress'

  const { theme } = useData()
</script>

<template>
  <p
    v-if="theme.badges?.length"
    class="docs-badges"
  >
    <a
      v-for="badge in theme.badges"
      :key="badge.label"
      :href="badge.href"
    >
      <img
        :src="badge.image"
        :alt="badge.label"
        loading="lazy"
      />
    </a>
  </p>
</template>
