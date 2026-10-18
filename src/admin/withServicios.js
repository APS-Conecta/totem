// SPDX-License-Identifier: AGPL-3.0-or-later

import { admin, warnError } from '../api.js'

/** For the tabs that refer to servicios (Boxes, Pantallas): loads the list once and names ids from it. */
export default {
	data() {
		return { servicios: [] }
	},
	created() {
		this.loadServicios()
	},
	methods: {
		namesOf(ids) {
			return this.servicios.filter((servicio) => ids.includes(servicio.id)).map((servicio) => servicio.name).join(', ')
		},
		async loadServicios() {
			try {
				this.servicios = await admin.servicios.list()
			} catch (error) {
				warnError('servicios list', error)
			}
		},
	},
}
