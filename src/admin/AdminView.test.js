// SPDX-License-Identifier: AGPL-3.0-or-later
// @vitest-environment jsdom
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

vi.mock('../api.js', async (importActual) => ({
	...(await importActual()),
	admin: {
		servicios: { list: vi.fn(), save: vi.fn() },
		boxes: { list: vi.fn(), save: vi.fn(), remove: vi.fn() },
		pantallas: { list: vi.fn(), save: vi.fn(), remove: vi.fn() },
	},
}))
vi.mock('../lib/announce.js', () => ({ testAnnouncement: vi.fn() }))

import { admin } from '../api.js'
import AdminView from './AdminView.vue'

beforeEach(() => {
	// Nextcloud's page shell provides this; NcContent teleports its skip links into it.
	document.body.innerHTML = '<div id="skip-actions"></div>'
	vi.clearAllMocks()
	admin.servicios.list.mockResolvedValue([])
	admin.boxes.list.mockResolvedValue([])
	admin.pantallas.list.mockResolvedValue([])
})

describe('AdminView', () => {
	it('sits in Nextcloud\'s own content panel, so it never floats over the theme background', async () => {
		const wrapper = mount(AdminView)
		await flushPromises()

		expect(wrapper.find('main.app-content').exists()).toBe(true)
		expect(wrapper.find('main.app-content [role="tablist"]').exists()).toBe(true)
	})

	it('offers the three tabs, with Servicios selected first', async () => {
		const wrapper = mount(AdminView)
		await flushPromises()

		const tabs = wrapper.findAll('[role="tablist"] [role="tab"]')
		expect(tabs.map((tab) => tab.text())).toEqual(['Servicios', 'Boxes', 'Pantallas'])
		expect(tabs.map((tab) => tab.attributes('aria-selected'))).toEqual(['true', 'false', 'false'])
	})

	it('loads and shows the Servicios list on first show', async () => {
		admin.servicios.list.mockResolvedValue([{ id: 1, name: 'Farmacia', prefix: 'FAR', color: '#0b6e4f', opens: '07:30', closes: '16:30', dailyQuota: null, active: true }])

		const wrapper = mount(AdminView)
		await flushPromises()

		const panel = wrapper.find('[role="tabpanel"]')
		expect(panel.text()).toContain('Farmacia')
		expect(panel.attributes('aria-labelledby')).toBe('totem-tab-servicios')
		expect(admin.boxes.list).not.toHaveBeenCalled()
		expect(admin.pantallas.list).not.toHaveBeenCalled()
	})

	it('loads the Boxes list only when its tab is first shown', async () => {
		admin.boxes.list.mockResolvedValue([{ id: 20, name: 'Box 2', servicioIds: [] }])
		const wrapper = mount(AdminView)
		await flushPromises()
		expect(admin.boxes.list).not.toHaveBeenCalled()

		await wrapper.findAll('[role="tab"]')[1].trigger('click')
		await flushPromises()

		expect(wrapper.findAll('[role="tab"]').map((tab) => tab.attributes('aria-selected'))).toEqual(['false', 'true', 'false'])
		expect(wrapper.find('[role="tabpanel"]').attributes('aria-labelledby')).toBe('totem-tab-boxes')
		expect(wrapper.find('[role="tabpanel"]').text()).toContain('Box 2')
		expect(admin.boxes.list).toHaveBeenCalledTimes(1)
	})

	it('loads the Pantallas list only when its tab is first shown', async () => {
		admin.pantallas.list.mockResolvedValue([{ id: 5, slug: 'central', name: 'Central', volume: 100, voice: 'mujer', servicioIds: [] }])
		const wrapper = mount(AdminView)
		await flushPromises()
		expect(admin.pantallas.list).not.toHaveBeenCalled()

		await wrapper.findAll('[role="tab"]')[2].trigger('click')
		await flushPromises()

		expect(wrapper.find('[role="tabpanel"]').attributes('aria-labelledby')).toBe('totem-tab-pantallas')
		expect(wrapper.find('[role="tabpanel"]').text()).toContain('Central')
		expect(admin.pantallas.list).toHaveBeenCalledTimes(1)
	})

	it('moves between tabs with the arrow keys, only the selected tab being a tab stop', async () => {
		const wrapper = mount(AdminView, { attachTo: document.body })
		await flushPromises()
		const tabs = () => wrapper.findAll('[role="tab"]')
		expect(tabs().map((tab) => tab.attributes('tabindex'))).toEqual(['0', '-1', '-1'])

		await tabs()[0].trigger('keydown', { key: 'ArrowRight' })
		await flushPromises()
		expect(tabs().map((tab) => tab.attributes('aria-selected'))).toEqual(['false', 'true', 'false'])
		expect(document.activeElement).toBe(tabs()[1].element)

		await tabs()[1].trigger('keydown', { key: 'ArrowLeft' })
		await tabs()[0].trigger('keydown', { key: 'ArrowLeft' })
		await flushPromises()
		expect(tabs().map((tab) => tab.attributes('aria-selected'))).toEqual(['false', 'false', 'true'])
		wrapper.unmount()
	})
})
