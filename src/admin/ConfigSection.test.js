// @vitest-environment jsdom
// SPDX-License-Identifier: AGPL-3.0-or-later
import { afterEach, describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import ConfigSection from './ConfigSection.vue'

const SLOTS = { list: '<table id="the-list" />', form: '<form id="the-form" />' }
let wrapper

function section(props = {}) {
	wrapper = mount(ConfigSection, {
		attachTo: document.body,
		props: { title: 'Unidades', subtitle: 'Las unidades del CESFAM.', newLabel: 'Nueva unidad', ...props },
		slots: SLOTS,
	})
	return wrapper
}

afterEach(() => {
	wrapper?.unmount()
	wrapper = undefined
	document.body.innerHTML = ''
})

describe('a configuration section', () => {
	it('titles the page and says what the list is for', () => {
		section()

		expect(wrapper.find('h2').text()).toBe('Unidades')
		expect(wrapper.find('.totem-pane__titles p').text()).toBe('Las unidades del CESFAM.')
	})

	it('shows the list, and asks for a new record with one primary button', async () => {
		section()

		expect(wrapper.find('#the-list').exists()).toBe(true)
		const add = wrapper.find('.totem-pane__acts button')
		expect(add.text()).toBe('Nueva unidad')
		expect(add.classes()).toContain('button-vue--primary')

		await add.trigger('click')
		expect(wrapper.emitted('new')).toHaveLength(1)
	})
})

describe('the sidebar of a configuration section', () => {
	it('is closed until a record is being edited', () => {
		section()

		expect(wrapper.find('aside').exists()).toBe(false)
		expect(wrapper.find('#the-form').exists()).toBe(false)
	})

	it('holds the form, named for the record, while one is being edited', () => {
		section({ editing: true, sidebarName: 'Farmacia', sidebarSubname: 'Editar unidad' })

		const aside = wrapper.find('aside')
		expect(aside.find('h2').text()).toBe('Farmacia')
		expect(aside.text()).toContain('Editar unidad')
		expect(aside.find('#the-form').exists()).toBe(true)
	})

	it('asks to close from its own close button', async () => {
		section({ editing: true, sidebarName: 'Farmacia' })

		await wrapper.find('aside button[aria-label]').trigger('click')

		expect(wrapper.emitted('close')).toHaveLength(1)
	})
})
