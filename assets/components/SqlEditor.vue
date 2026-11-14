<script setup lang="ts">
import { autocompletion, closeBrackets, closeBracketsKeymap, completionKeymap, snippetCompletion, type CompletionContext } from '@codemirror/autocomplete'
import { defaultKeymap, history, historyKeymap, indentWithTab } from '@codemirror/commands'
import { MariaSQL, MySQL, PostgreSQL, StandardSQL, sql } from '@codemirror/lang-sql'
import { bracketMatching, HighlightStyle, syntaxHighlighting } from '@codemirror/language'
import { Compartment, EditorState, Prec } from '@codemirror/state'
import { drawSelection, EditorView, highlightActiveLine, keymap, lineNumbers } from '@codemirror/view'
import { tags } from '@lezer/highlight'
import { onBeforeUnmount, onMounted, ref, watch } from 'vue'
import type { Engine } from '../api/types'
import type { Snippet } from '../utils/snippets'

const props = withDefaults(defineProps<{ modelValue: string; dialect: Engine; tables: string[]; snippets?: Snippet[] }>(), { snippets: () => [] })
const emit = defineEmits<{ 'update:modelValue': [value: string]; run: [] }>()

const host = ref<HTMLElement | null>(null)
const language = new Compartment()
const snippetSource = new Compartment()
let view: EditorView | null = null
let applyingExternalChange = false

const DIALECTS = { postgresql: PostgreSQL, mysql: MySQL, mariadb: MariaSQL, clickhouse: StandardSQL, redis: StandardSQL }

function languageFor() {
  return sql({ dialect: DIALECTS[props.dialect], upperCaseKeywords: true, schema: Object.fromEntries(props.tables.map((table) => [table, []])) })
}

/**
 * Les extraits déjà filtrés par moteur et par capacité (voir `utils/snippets`) : choisir l'un d'eux INSÈRE le squelette avec ses
 * champs à remplir (Tab passe au suivant), rien n'est exécuté.
 */
function snippetsExtension(snippets: Snippet[]) {
  const options = snippets.map((snippet) => snippetCompletion(snippet.body, { label: snippet.label, detail: snippet.detail, type: 'text', boost: -1 }))

  return EditorState.languageData.of(() => [{
    autocomplete: (context: CompletionContext) => {
      const word = context.matchBefore(/[\w ]*/)
      if (options.length === 0 || word === null || (word.from === word.to && !context.explicit)) {
        return null
      }

      return { from: word.from, options, validFor: /^[\w ]*$/ }
    },
  }])
}

const theme = EditorView.theme(
  {
    '&': { color: 'var(--fg)', backgroundColor: 'var(--bg)', fontSize: '13.5px', height: '100%' },
    '.cm-content': { fontFamily: 'var(--font-mono)', caretColor: 'var(--acc)', padding: '14px 0' },
    '.cm-scroller': { lineHeight: '22px', overflow: 'auto' },
    '.cm-gutters': { backgroundColor: 'var(--bg)', color: 'var(--code-gutter)', border: 'none' },
    '.cm-activeLine': { backgroundColor: 'var(--hover)' },
    '.cm-activeLineGutter': { backgroundColor: 'transparent', color: 'var(--mut)' },
    '&.cm-focused': { outline: 'none' },
    '.cm-selectionBackground, &.cm-focused .cm-selectionBackground': { backgroundColor: 'var(--selection)' },
    '.cm-cursor': { borderLeftColor: 'var(--acc)' },
    '.cm-tooltip': { backgroundColor: 'var(--s2)', border: '1px solid var(--line-strong)', color: 'var(--fg)' },
    '.cm-tooltip-autocomplete ul li[aria-selected]': { backgroundColor: 'var(--acc-bg)', color: 'var(--acc)' },
  },
  { dark: true },
)

const highlight = HighlightStyle.define([
  { tag: tags.keyword, color: 'var(--code-keyword)' },
  { tag: [tags.string, tags.special(tags.string)], color: 'var(--code-string)' },
  { tag: [tags.number, tags.bool, tags.null], color: 'var(--code-number)' },
  { tag: [tags.function(tags.variableName), tags.standard(tags.name)], color: 'var(--code-function)' },
  { tag: [tags.lineComment, tags.blockComment], color: 'var(--code-comment)', fontStyle: 'italic' },
  { tag: tags.operator, color: 'var(--code-operator)' },
])

onMounted(() => {
  if (host.value === null) {
    return
  }
  view = new EditorView({
    parent: host.value,
    state: EditorState.create({
      doc: props.modelValue,
      extensions: [
        lineNumbers(),
        history(),
        drawSelection(),
        highlightActiveLine(),
        bracketMatching(),
        closeBrackets(),
        autocompletion(),
        language.of(languageFor()),
        snippetSource.of(snippetsExtension(props.snippets)),
        theme,
        syntaxHighlighting(highlight),
        EditorView.contentAttributes.of({ 'aria-label': 'Éditeur de requête SQL' }),
        Prec.highest(keymap.of([{ key: 'Mod-Enter', run: () => { emit('run'); return true } }])),
        keymap.of([...closeBracketsKeymap, ...defaultKeymap, ...historyKeymap, ...completionKeymap, indentWithTab]),
        EditorView.updateListener.of((update) => {
          if (update.docChanged && !applyingExternalChange) {
            emit('update:modelValue', update.state.doc.toString())
          }
        }),
      ],
    }),
  })
})

onBeforeUnmount(() => view?.destroy())

watch(
  () => props.modelValue,
  (value) => {
    // Une valeur changée de l'extérieur (nouvel onglet, requête pré-remplie) remplace le texte sans le renvoyer.
    if (view && value !== view.state.doc.toString()) {
      applyingExternalChange = true
      view.dispatch({ changes: { from: 0, to: view.state.doc.length, insert: value } })
      applyingExternalChange = false
    }
  },
)

watch(
  () => props.snippets,
  (snippets) => view?.dispatch({ effects: snippetSource.reconfigure(snippetsExtension(snippets)) }),
)

watch(
  () => [props.dialect, props.tables],
  () => view?.dispatch({ effects: language.reconfigure(languageFor()) }),
)
</script>

<template>
  <div ref="host" class="editor" />
</template>

<style scoped>
.editor { height: 100%; min-height: 220px; overflow: hidden; }
</style>
