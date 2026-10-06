<script setup lang="ts">
  import { useData } from 'vitepress'
  import { computed, onMounted, ref } from 'vue'

  const { theme } = useData()
  const versions = ref<{ latest?: string; versions: string[]; next?: boolean }>({ versions: [] })
  const current = computed(() => theme.value.build.version)

  // The versions that were published are listed in versions.json, at the root of the site.
  onMounted(async () => {
    try {
      const response = await fetch(`${theme.value.root}versions.json`)

      if (response.ok) {
        versions.value = await response.json()
      }
    } catch {
      // Without it (local build) the switcher only shows the current version.
    }
  })

  const link = (version: string) =>
    version === versions.value.latest ? theme.value.root : `${theme.value.root}${version}/`

  function go(event: Event) {
    const value = (event.target as HTMLSelectElement).value

    window.location.href = 'next' === value ? `${theme.value.root}next/` : link(value)
  }
</script>

<template>
  <label class="docs-version">
    <span class="visually-hidden">Version</span>
    <select
      :value="current"
      @change="go"
    >
      <option
        v-if="versions.next"
        value="next"
      >
        next
      </option>
      <option
        v-for="version in versions.versions"
        :key="version"
        :value="version"
      >
        {{ version }}{{ version === versions.latest ? ' (latest)' : '' }}
      </option>
      <option
        v-if="!versions.versions.includes(current) && 'next' !== current"
        :value="current"
      >
        {{ current }}
      </option>
      <option
        v-if="'next' === current && !versions.next"
        value="next"
      >
        next
      </option>
    </select>
  </label>
</template>
