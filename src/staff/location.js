// SPDX-License-Identifier: AGPL-3.0-or-later

import { generateUrl } from '@nextcloud/router'

// Where each section lives in the address bar. The three addresses are the ones the pages always had
// (/box, /estadisticas, /admin), so old links keep working; the three configuration sections share /admin
// and tell themselves apart by the # that follows. There is no router: switching sections is a state change,
// and this file keeps the address and the back button in step with it.

const CONFIGURATION = ['servicios', 'boxes', 'pantallas', 'totems']

/** The address of a section. */
export function urlFor(section) {
	if (section === 'box') return generateUrl('/apps/totem/box')
	if (section === 'estadisticas') return generateUrl('/apps/totem/estadisticas')
	return generateUrl('/apps/totem/admin') + '#' + section
}

/** The section an address opens; `fallback` (what the server said) when it names none. */
export function sectionFrom({ pathname, hash }, fallback = 'box') {
	const path = pathname.replace(/\/+$/, '')
	if (path.endsWith('/box')) return 'box'
	if (path.endsWith('/estadisticas')) return 'estadisticas'
	if (path.endsWith('/admin')) {
		const asked = hash.replace('#', '')
		return CONFIGURATION.includes(asked) ? asked : 'servicios'
	}
	return fallback
}

/** Put the section's address in the history, unless it is already the one showing. */
export function navigate(section) {
	const url = urlFor(section)
	if (url === window.location.pathname + window.location.hash) return
	window.history.pushState({ section }, '', url)
}

/** Call `callback(section)` when the back or forward button lands on another section. Returns the stop function. */
export function onBack(callback) {
	const handler = () => callback(sectionFrom(window.location))
	window.addEventListener('popstate', handler)
	return () => window.removeEventListener('popstate', handler)
}
