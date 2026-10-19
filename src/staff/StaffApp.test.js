// @vitest-environment jsdom
// SPDX-License-Identifier: AGPL-3.0-or-later
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { loadState } from '@nextcloud/initial-state'
import StaffApp from './StaffApp.vue'

vi.mock('@nextcloud/initial-state', () => ({ loadState: vi.fn() }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => '/index.php' + path }))

// The sections have their own tests; here they only say which one is showing.
const stub = (id) => ({ template: `<div data-section="${id}" />` })
const STUBS = { BoxView: stub('box'), StatsView: stub('estadisticas'), ServiciosView: stub('servicios'), BoxesView: stub('boxes'), PantallasView: stub('pantallas') }

let wrapper

function open(section = 'box', url = '/index.php/apps/totem/box') {
	window.history.replaceState(null, '', url)
	loadState.mockReturnValue(section)
	// Nextcloud's page shell provides this; NcContent teleports its skip links into it.
	document.body.innerHTML = '<div id="skip-actions"></div>'
	wrapper = mount(StaffApp, { attachTo: document.body, global: { stubs: STUBS } })
	return wrapper
}

const showing = () => wrapper.find('[data-section]').attributes('data-section')
const item = (name) => wrapper.findAll('.app-navigation-entry').find((e) => e.text() === name)
const link = (name) => item(name).find('a')

beforeEach(() => vi.clearAllMocks())
afterEach(() => {
	wrapper?.unmount()
	wrapper = undefined
	window.history.replaceState(null, '', '/')
})

describe('the menu', () => {
	it('has two labelled groups, Operación and Configuración, with their sections in order', () => {
		open()

		const groups = wrapper.findAll('ul[aria-labelledby]')
		expect(groups.map((g) => document.getElementById(g.attributes('aria-labelledby')).textContent.trim())).toEqual(['Operación', 'Configuración'])
		expect(groups.map((g) => g.findAll('.app-navigation-entry').map((e) => e.text()))).toEqual([
			['Box', 'Estadísticas'],
			['Servicios', 'Boxes', 'Pantallas'],
		])
	})

	it('is the same for everybody: configuration is there without any admin flag', () => {
		open()
		expect(item('Servicios')).toBeDefined()
		expect(item('Pantallas')).toBeDefined()
	})

	it('offers a link that opens the totem in a new tab', () => {
		open()

		const a = wrapper.findAll('a').find((candidate) => candidate.text() === 'Abrir tótem')
		expect(a.attributes('href')).toBe('/index.php/apps/totem/totem')
		expect(a.attributes('target')).toBe('_blank')
		expect(a.attributes('rel')).toContain('noopener')
	})
})

describe('the menu entries', () => {
	it('are real links to the address of their section, so they can be opened in a new tab', () => {
		open()

		expect(['Box', 'Estadísticas', 'Servicios', 'Boxes', 'Pantallas'].map((name) => link(name).attributes('href'))).toEqual([
			'/index.php/apps/totem/box',
			'/index.php/apps/totem/estadisticas',
			'/index.php/apps/totem/admin#servicios',
			'/index.php/apps/totem/admin#boxes',
			'/index.php/apps/totem/admin#pantallas',
		])
	})

	it('add one history entry when clicked, not two: the click does not also follow the link', async () => {
		open()
		const before = window.history.length

		await link('Estadísticas').trigger('click')

		expect(window.history.length).toBe(before + 1)
	})

	it('are left to the browser when clicked with a modifier key, so ctrl-click opens a new tab', async () => {
		open()

		await link('Estadísticas').trigger('click', { ctrlKey: true })

		expect(showing()).toBe('box')
		expect(window.location.pathname).toBe('/index.php/apps/totem/box')
	})
})

describe('the section showing', () => {
	it.each(['box', 'estadisticas', 'servicios', 'boxes', 'pantallas'])('opens %s when the server says so', (section) => {
		open(section)
		expect(showing()).toBe(section)
		expect(wrapper.findAll('[data-section]')).toHaveLength(1)
	})

	it('marks the menu entry of that section as the current page', () => {
		open('estadisticas', '/index.php/apps/totem/estadisticas')

		expect(link('Estadísticas').attributes('aria-current')).toBe('page')
		expect(link('Box').attributes('aria-current')).toBeUndefined()
	})

	it('opens the configuration section the address names after the #', () => {
		open('servicios', '/index.php/apps/totem/admin#pantallas')
		expect(showing()).toBe('pantallas')
	})

	it('opens Box when the server gave nothing to go on', () => {
		window.history.replaceState(null, '', '/index.php/apps/totem/')
		loadState.mockImplementation(() => { throw new Error('no initial state') })
		document.body.innerHTML = '<div id="skip-actions"></div>'
		wrapper = mount(StaffApp, { attachTo: document.body, global: { stubs: STUBS } })

		expect(showing()).toBe('box')
	})
})

describe('moving between sections', () => {
	it('shows the section chosen, without reloading, and puts its address in the history', async () => {
		open()

		await link('Estadísticas').trigger('click')

		expect(showing()).toBe('estadisticas')
		expect(window.location.pathname).toBe('/index.php/apps/totem/estadisticas')
		expect(link('Estadísticas').attributes('aria-current')).toBe('page')
		expect(link('Box').attributes('aria-current')).toBeUndefined()
	})

	it('gives each configuration section its own # on the shared address', async () => {
		open()

		await link('Boxes').trigger('click')

		expect(window.location.pathname + window.location.hash).toBe('/index.php/apps/totem/admin#boxes')
		expect(showing()).toBe('boxes')
	})

	it('follows the back button to the section before', async () => {
		open()
		await link('Estadísticas').trigger('click')
		await link('Servicios').trigger('click')

		window.history.back()
		await vi.waitFor(() => expect(showing()).toBe('estadisticas'))
		expect(link('Estadísticas').attributes('aria-current')).toBe('page')
	})
})
