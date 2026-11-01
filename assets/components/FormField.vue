<script setup lang="ts">
import { computed } from 'vue'

const props = withDefaults(
  defineProps<{
    id: string
    label: string
    type?: string
    hint?: string
    mono?: boolean
    autocomplete?: string
    placeholder?: string
    inputmode?: 'numeric' | 'text' | 'email'
    required?: boolean
    maxlength?: number
  }>(),
  { type: 'text', hint: undefined, autocomplete: undefined, placeholder: undefined, inputmode: undefined, maxlength: undefined },
)
const model = defineModel<string>({ required: true })
const hintId = computed(() => (props.hint ? `${props.id}-hint` : undefined))
</script>

<template>
  <div class="field">
    <label :for="id">{{ label }}</label>
    <input
      :id="id"
      v-model="model"
      :class="{ mono }"
      :type="type"
      :autocomplete="autocomplete"
      :placeholder="placeholder"
      :inputmode="inputmode"
      :required="required"
      :maxlength="maxlength"
      :aria-describedby="hintId"
    >
    <p v-if="hint" :id="hintId" class="hint">{{ hint }}</p>
  </div>
</template>

<style scoped>
.field { min-width: 0; }
label { display: block; font-size: 12px; font-weight: 500; color: var(--mut); margin-bottom: 6px; letter-spacing: 0.02em; }
input { width: 100%; height: 42px; padding: 0 12px; border-radius: var(--radius); border: 1px solid var(--line); background: var(--s1); color: var(--fg); font: 400 14px var(--font-ui); }
input.mono { font-family: var(--font-mono); font-size: 13px; }
input::placeholder { color: var(--mut); opacity: 0.7; }
.hint { font-size: 12px; color: var(--mut); margin-top: 6px; }
</style>
