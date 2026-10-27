// SPDX-License-Identifier: AGPL-3.0-or-later
import '../lib/public-path.js'
import { createApp } from 'vue'
import StaffApp from './StaffApp.vue'
import './staff.css'

createApp(StaffApp).mount('#totem-staff')
