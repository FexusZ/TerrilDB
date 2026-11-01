import globals from 'globals'
import tseslint from 'typescript-eslint'
import vue from 'eslint-plugin-vue'

export default tseslint.config(
  { ignores: ['public/**', 'node_modules/**', 'vendor/**', 'var/**'] },
  ...tseslint.configs.recommended,
  ...vue.configs['flat/recommended'],
  {
    files: ['**/*.vue'],
    languageOptions: { parserOptions: { parser: tseslint.parser } },
  },
  {
    languageOptions: { globals: { ...globals.browser } },
    rules: {
      // Libellés et textes courts en français : pas de règle de casse sur les noms de composants à un mot.
      'vue/multi-word-component-names': 'off',
      // Mise en forme du gabarit : laissée à l'auteur, pas de formateur automatique dans ce dépôt.
      'vue/max-attributes-per-line': 'off',
      'vue/singleline-html-element-content-newline': 'off',
      'vue/multiline-html-element-content-newline': 'off',
      'vue/html-closing-bracket-newline': 'off',
      'vue/html-indent': 'off',
    },
  },
)
