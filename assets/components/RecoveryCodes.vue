<script setup lang="ts">
import AppButton from './AppButton.vue'

defineProps<{ codes: string[] }>()
defineEmits<{ noted: [] }>()
</script>

<template>
  <div class="recovery-codes">
    <p class="codes-notice" role="note">Conservez ces codes en lieu sûr. Ils ne seront plus affichés. Chacun remplace un code de l’application, une seule fois.</p>
    <ul class="codes">
      <li v-for="code in codes" :key="code">{{ code }}</li>
    </ul>
    <AppButton class="noted" variant="primary" small @click="$emit('noted')">J’ai noté mes codes</AppButton>
  </div>
</template>

<style scoped>
.recovery-codes { display: flex; flex-direction: column; gap: 12px; }
.codes-notice { padding: 10px 12px; border-radius: var(--radius); background: var(--sec-bg); border: 1px solid var(--sec-line); color: var(--sec-ink); font-size: 13px; }
.codes { list-style: none; margin: 0; padding: 0; display: grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); gap: 8px; }
li { font: 400 13px var(--font-mono); padding: 9px 12px; border-radius: 5px; background: var(--bg); border: 1px solid var(--line); }
</style>
