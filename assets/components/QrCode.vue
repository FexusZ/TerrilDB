<script setup lang="ts">
import QRCode from 'qrcode'
import { ref, watchEffect } from 'vue'

const props = defineProps<{ value: string; label: string }>()
const svg = ref('')

// Le SVG vient de la bibliothèque, calculé ici à partir de l'URI d'enrôlement : rien n'est envoyé à un service tiers.
watchEffect(() => {
  void QRCode.toString(props.value, { type: 'svg', margin: 1, errorCorrectionLevel: 'M', color: { dark: '#e6edea', light: '#00000000' } }).then((markup) => {
    svg.value = markup.replace('<svg', '<svg class="qr" role="img" aria-label="' + props.label.replaceAll('"', '&quot;') + '"')
  })
})
</script>

<template>
  <!-- eslint-disable-next-line vue/no-v-html -->
  <div class="qr-box" v-html="svg" />
</template>

<style scoped>
.qr-box { width: 200px; height: 200px; padding: 8px; border-radius: var(--radius); border: 1px solid var(--line); background: #0b1013; }
.qr-box :deep(svg) { width: 100%; height: 100%; display: block; }
</style>
