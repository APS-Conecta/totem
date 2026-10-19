// SPDX-License-Identifier: AGPL-3.0-or-later

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { loggers, messageFrom } from './aps/api.js'

// The app binding for aps-common's shared error shape (make aps-sync → src/aps/).
const { safeError, warnError } = loggers('totem')
export { messageFrom, safeError, warnError }

const url = (path) => generateUrl('/apps/totem' + path)
const data = (response) => response.data

/** The totem's calls: anonymous. */
export const publicServicios = () => axios.get(url('/public/servicios')).then(data)
export const issueTurno = (servicioId, preferente) => axios.post(url('/turnos'), { servicioId, preferente }).then(data)

/** The display's poll: the llamados after `since`. */
export const readFeed = (slug, since) => axios.get(url(`/display/${slug}/feed`), { params: { since } }).then(data)

const ACTIONS = ['call', 'recall', 'start', 'finish', 'absent']

/** The box console: any logged-in user. */
export const box = {
	boxes: () => axios.get(url('/console/boxes')).then(data),
	state: (boxId) => axios.get(url(`/console/${boxId}/state`)).then(data),
	act(boxId, action) {
		if (!ACTIONS.includes(action)) throw new Error('Acción desconocida: ' + action)
		return axios.post(url(`/console/${boxId}/${action}`)).then(data)
	},
}

/** The statistics board: any logged-in user. The CSV is a plain link, so the browser downloads it. */
export const stats = {
	board: (kind, date) => axios.get(url('/stats'), { params: { kind, date } }).then(data),
	csvUrl: (kind, date) => url('/stats/csv') + '?' + new URLSearchParams({ kind, date }),
}

/** One shape for the three admin lists: save(null, body) creates, save(id, body) updates. */
function resource(name, { deletable }) {
	return {
		list: () => axios.get(url('/' + name)).then(data),
		save: (id, body) => (id == null ? axios.post(url('/' + name), body) : axios.put(url(`/${name}/${id}`), body)).then(data),
		...(deletable ? { remove: (id) => axios.delete(url(`/${name}/${id}`)).then(data) } : {}),
	}
}

/** Configuration: Nextcloud admins. A servicio is deactivated, never deleted. */
export const admin = {
	servicios: resource('servicios', { deletable: false }),
	boxes: resource('boxes', { deletable: true }),
	pantallas: resource('pantallas', { deletable: true }),
}
