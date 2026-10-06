import type { Theme } from 'vitepress'
import DefaultTheme from 'vitepress/theme'
import { h } from 'vue'
import Badges from './Badges.vue'
import BuildInfo from './BuildInfo.vue'
import Layout from './Layout.vue'
import './style.css'

export default {
  extends: DefaultTheme,
  Layout: () =>
    h(Layout, null, {
      'home-hero-after': () => h(Badges),
      'doc-after': () => h(BuildInfo),
    }),
} satisfies Theme
