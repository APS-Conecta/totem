// SPDX-License-Identifier: AGPL-3.0-or-later

import '../lib/public-path.js'
import '@fontsource/atkinson-hyperlegible/400.css'
import '@fontsource/atkinson-hyperlegible/700.css'
import { createApp } from 'vue'
import DisplayView from './DisplayView.vue'

createApp(DisplayView).mount('#totem-display')
