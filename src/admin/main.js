// SPDX-License-Identifier: AGPL-3.0-or-later

import '../lib/public-path.js' // first: "Probar aviso" loads the voice's worker chunk
import { createApp } from 'vue'

import AdminView from './AdminView.vue'

// Vue replaces the mount point's content, so the "could not load" fallback line goes away on a clean boot.
createApp(AdminView).mount('#totem-admin')
