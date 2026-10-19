// @vitest-environment jsdom
// SPDX-License-Identifier: AGPL-3.0-or-later
import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import PaneHeader from './PaneHeader.vue'

describe('the header of a section', () => {
	it('is the title of the page, as the one h2', () => {
		const wrapper = mount(PaneHeader, { props: { title: 'Estadísticas' } })

		expect(wrapper.findAll('h2').map((h) => h.text())).toEqual(['Estadísticas'])
	})

	it('shows a subtitle only when there is one', () => {
		expect(mount(PaneHeader, { props: { title: 'Ventanilla 3' } }).find('p').exists()).toBe(false)

		const wrapper = mount(PaneHeader, { props: { title: 'Ventanilla 3', subtitle: 'Farmacia, Toma de Muestras' } })
		expect(wrapper.find('p').text()).toBe('Farmacia, Toma de Muestras')
	})

	it('holds the actions of the section beside the title', () => {
		const wrapper = mount(PaneHeader, { props: { title: 'Unidades' }, slots: { actions: '<button>Nueva unidad</button>' } })

		expect(wrapper.find('button').text()).toBe('Nueva unidad')
	})

	it('has no actions area when no action is given', () => {
		expect(mount(PaneHeader, { props: { title: 'Unidades' } }).find('.totem-pane__acts').exists()).toBe(false)
	})
})
