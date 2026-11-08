<script setup lang="ts">
withDefaults(defineProps<{ variant?: 'primary' | 'secondary' | 'ghost' | 'danger'; small?: boolean; type?: 'button' | 'submit'; busy?: boolean; block?: boolean; disabled?: boolean }>(), {
  variant: 'secondary',
  type: 'button',
})
</script>

<template>
  <button class="btn" :class="[variant, { small, block }]" :type="type" :disabled="busy || disabled" :aria-busy="busy">
    <slot />
  </button>
</template>

<style scoped>
.btn { display: inline-flex; align-items: center; justify-content: center; gap: 8px; height: 40px; padding: 0 16px; border-radius: var(--radius-pill); border: 1px solid var(--line); background: var(--s2); color: var(--fg); font: 500 14px var(--font-ui); cursor: pointer; text-decoration: none; white-space: nowrap; }
.btn:hover:not(:disabled) { border-color: var(--line-strong); }
.btn:disabled { opacity: 0.6; cursor: progress; }
.small { height: 34px; padding: 0 14px; font-size: 13px; }
.block { width: 100%; }
.primary { background: var(--acc); border-color: var(--acc); color: var(--acc-ink); font-weight: 600; }
.ghost { background: transparent; }
.danger { background: transparent; color: var(--bad); border-color: var(--bad-line); }
</style>
