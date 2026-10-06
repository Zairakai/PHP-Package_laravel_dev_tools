<script setup lang="ts">
  import { useData } from 'vitepress'
  import { computed } from 'vue'

  const { theme } = useData()
  const build = computed(() => theme.value.build)
  const date = computed(() => new Date(build.value.date).toISOString().slice(0, 10))
</script>

<template>
  <p class="docs-build">
    Documentation of <strong>{{ build.version }}</strong>
    <template v-if="build.commit">
      , built from
      <a :href="build.commitUrl">{{ build.commit }}</a>
      on {{ build.ref }}
    </template>
    <template v-if="build.pipeline">
      by <a :href="build.pipelineUrl">pipeline #{{ build.pipeline }}</a>
    </template>
    on {{ date }}.
  </p>
</template>
