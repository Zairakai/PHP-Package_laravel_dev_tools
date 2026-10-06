<script setup lang="ts">
  import { useData } from 'vitepress'
  import DefaultTheme from 'vitepress/theme'
  import { watchEffect } from 'vue'
  import VersionSwitcher from './VersionSwitcher.vue'

  const { isDark } = useData()

  // The component styles follow data-theme: keep it in step with the theme of the site.
  watchEffect(() => {
    if ('undefined' !== typeof document) {
      document.documentElement.dataset.theme = isDark.value ? 'dark' : 'light'
    }
  })
</script>

<template>
  <DefaultTheme.Layout>
    <template #nav-bar-content-after>
      <VersionSwitcher />
    </template>
    <template
      v-for="(_, name) in $slots"
      #[name]="slotData"
    >
      <slot
        :name="name"
        v-bind="slotData"
      />
    </template>
  </DefaultTheme.Layout>
</template>
