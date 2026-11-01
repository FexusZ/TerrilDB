<script setup lang="ts">
withDefaults(defineProps<{ size?: number; wordmark?: boolean }>(), { size: 28, wordmark: true })
</script>

<template>
  <span class="brand">
    <svg :width="size" :height="size" viewBox="0 0 28 28" aria-hidden="true">
      <path d="M2 24 L14 4 L26 24 Z" fill="#12312f" stroke="#5cc8be" stroke-width="1.6" stroke-linejoin="round" />
      <path d="M7 17h14M10 11.5h8" stroke="#5cc8be" stroke-width="1.6" stroke-linecap="round" />
    </svg>
    <span v-if="wordmark" class="word">TerrilDB</span>
  </span>
</template>

<style scoped>
.brand { display: inline-flex; align-items: center; gap: 10px; }
.word { font-weight: 700; font-size: 1.15em; letter-spacing: -0.01em; }
</style>
