// @vitest-environment jsdom
// SPDX-License-Identifier: AGPL-3.0-or-later
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { navigate, onBack, sectionFrom, urlFor } from './location.js'

vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => '/index.php' + path }))

describe('the address of a section', () => {
	it('is the address the section has always had, with the sub-section of Configuración after a #', () => {
		expect(urlFor('box')).toBe('/index.php/apps/totem/box')
		expect(urlFor('estadisticas')).toBe('/index.php/apps/totem/estadisticas')
		expect(urlFor('servicios')).toBe('/index.php/apps/totem/admin#servicios')
		expect(urlFor('boxes')).toBe('/index.php/apps/totem/admin#boxes')
		expect(urlFor('pantallas')).toBe('/index.php/apps/totem/admin#pantallas')
	})
})

describe('the section an address opens', () => {
	it.each([
		['/index.php/apps/totem/box', '', 'box'],
		['/apps/totem/box/', '', 'box'],
		['/apps/totem/estadisticas', '', 'estadisticas'],
		['/apps/totem/admin', '', 'servicios'],
		['/apps/totem/admin', '#servicios', 'servicios'],
		['/apps/totem/admin', '#boxes', 'boxes'],
		['/apps/totem/admin', '#pantallas', 'pantallas'],
		['/nextcloud/index.php/apps/totem/estadisticas', '', 'estadisticas'],
	])('%s%s opens %s', (pathname, hash, expected) => {
		expect(sectionFrom({ pathname, hash })).toBe(expected)
	})

	it('falls back to what the server said, then to Box, when the address says nothing', () => {
		expect(sectionFrom({ pathname: '/apps/totem/', hash: '' }, 'estadisticas')).toBe('estadisticas')
		expect(sectionFrom({ pathname: '/apps/totem/', hash: '' })).toBe('box')
	})

	it('ignores an unknown # after /admin', () => {
		expect(sectionFrom({ pathname: '/apps/totem/admin', hash: '#nada' })).toBe('servicios')
	})
})

describe('moving between sections', () => {
	beforeEach(() => window.history.replaceState(null, '', '/index.php/apps/totem/box'))
	afterEach(() => window.history.replaceState(null, '', '/'))

	it('puts the new address in the history without reloading', () => {
		const before = window.history.length

		navigate('estadisticas')

		expect(window.location.pathname).toBe('/index.php/apps/totem/estadisticas')
		expect(window.history.length).toBe(before + 1)
	})

	it('does not add an entry for the section already shown', () => {
		navigate('box')
		const before = window.history.length

		navigate('box')

		expect(window.history.length).toBe(before)
	})

	it('tells the app which section the back button returned to', () => {
		const seen = vi.fn()
		const stop = onBack(seen)
		navigate('boxes')
		navigate('pantallas')

		window.history.back()
		return vi.waitFor(() => expect(seen).toHaveBeenCalledWith('boxes')).finally(stop)
	})

	it('stops listening when told to', async () => {
		const seen = vi.fn()
		onBack(seen)()
		navigate('boxes')
		navigate('pantallas')

		window.history.back()
		await new Promise((resolve) => setTimeout(resolve, 50))

		expect(seen).not.toHaveBeenCalled()
	})
})
