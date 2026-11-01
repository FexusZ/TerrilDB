<script setup lang="ts">
import LockIcon from './LockIcon.vue'
</script>

<template>
  <div class="notice" role="note">
    <LockIcon :size="18" />
    <p><slot /></p>
  </div>
</template>

<style scoped>
.notice { display: flex; gap: 12px; align-items: flex-start; padding: 14px; border-radius: var(--radius); background: var(--sec-bg); border: 1px solid var(--sec-line); color: var(--sec); }
.notice svg { flex: none; margin-top: 2px; }
p { font-size: 13px; color: var(--sec-ink); }
</style>
