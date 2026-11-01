<script setup lang="ts">
import { onMounted, ref, useId } from 'vue'
import AppButton from './AppButton.vue'

withDefaults(defineProps<{ title: string; message: string; confirmLabel?: string; cancelLabel?: string; danger?: boolean; busy?: boolean }>(), {
  confirmLabel: 'Confirmer',
  cancelLabel: 'Annuler',
})
const emit = defineEmits<{ confirm: []; cancel: [] }>()

const titleId = useId()
const cancelButton = ref<{ $el: HTMLElement } | null>(null)

// Le focus va d'abord au choix sans danger : Entrée ne doit pas confirmer une suppression par réflexe.
onMounted(() => cancelButton.value?.$el.focus())
</script>

<template>
  <div class="backdrop" @click.self="emit('cancel')">
    <div class="dialog" role="dialog" aria-modal="true" :aria-labelledby="titleId" @keydown.esc="emit('cancel')">
      <h2 :id="titleId">{{ title }}</h2>
      <p>{{ message }}</p>
      <slot />
      <div class="actions">
        <AppButton ref="cancelButton" class="cancel" variant="ghost" @click="emit('cancel')">{{ cancelLabel }}</AppButton>
        <AppButton class="confirm" :class="{ danger }" :variant="danger ? 'danger' : 'primary'" :busy="busy" @click="emit('confirm')">{{ confirmLabel }}</AppButton>
      </div>
    </div>
  </div>
</template>

<style scoped>
.backdrop { position: fixed; inset: 0; z-index: 50; display: flex; align-items: center; justify-content: center; padding: 16px; background: rgba(5, 8, 10, 0.72); }
.dialog { width: 100%; max-width: 460px; display: flex; flex-direction: column; gap: 14px; padding: 24px; border-radius: 8px; border: 1px solid var(--line-strong); background: var(--s1); }
h2 { font-size: 18px; font-weight: 600; }
p { color: var(--mut); }
.actions { display: flex; justify-content: flex-end; gap: 10px; flex-wrap: wrap; }
</style>
