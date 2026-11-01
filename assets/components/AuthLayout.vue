<script setup lang="ts">
import BrandMark from './BrandMark.vue'

defineProps<{ headline: string; text: string; facts?: string }>()
</script>

<template>
  <div class="auth">
    <aside class="brand-panel">
      <BrandMark :size="32" />
      <div class="pitch">
        <h2>{{ headline }}</h2>
        <p>{{ text }}</p>
        <p v-if="facts" class="facts">{{ facts }}</p>
      </div>
    </aside>
    <main class="form-panel">
      <div class="form-box"><slot /></div>
    </main>
  </div>
</template>

<style scoped>
.auth { display: flex; flex-wrap: wrap; min-height: 100vh; }
.brand-panel {
  flex: 1 1 480px; min-height: 360px; padding: 48px; display: flex; flex-direction: column; justify-content: space-between; gap: 48px;
  background-color: #101a1d;
  background-image:
    repeating-linear-gradient(176deg, rgba(92, 200, 190, 0.07) 0 2px, transparent 2px 38px),
    repeating-linear-gradient(176deg, rgba(227, 168, 87, 0.1) 0 2px, transparent 2px 190px);
  border-right: 1px solid var(--line);
}
.pitch { display: flex; flex-direction: column; gap: 20px; max-width: 460px; }
h2 { font-size: 40px; line-height: 1.08; font-weight: 700; letter-spacing: -0.02em; text-wrap: balance; }
.pitch p { color: var(--mut); font-size: 16px; }
.pitch .facts { font: 400 12px var(--font-mono); color: var(--acc); letter-spacing: 0.04em; }
.form-panel { flex: 1 1 440px; display: flex; align-items: center; justify-content: center; padding: 48px 24px; }
.form-box { width: 100%; max-width: 400px; display: flex; flex-direction: column; gap: 20px; }
</style>
