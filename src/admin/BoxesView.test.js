// SPDX-License-Identifier: AGPL-3.0-or-later
// @vitest-environment jsdom
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

vi.mock('../api.js', async (importActual) => ({
	...(await importActual()),
	admin: {
		servicios: { list: vi.fn() },
		boxes: { list: vi.fn(), save: vi.fn(), remove: vi.fn() },
	},
}))

import { admin } from '../api.js'
import BoxesView from './BoxesView.vue'
import { field } from './test-helpers.js'

const FARMACIA = { id: 1, name: 'Farmacia', prefix: 'FAR', color: '#0b6e4f', opens: '07:30', closes: '16:30', dailyQuota: null, active: true }
const MUESTRAS = { id: 2, name: 'Toma de Muestras', prefix: 'MUE', color: '#7a3e9d', opens: '07:30', closes: '10:30', dailyQuota: 80, active: false }
const BOX2 = { id: 20, name: 'Box 2', servicioIds: [1] }
const BOX3 = { id: 30, name: 'Box 3', servicioIds: [1, 2] }

async function mountView() {
	const wrapper = mount(BoxesView, { attachTo: document.body })
	await flushPromises()
	return wrapper
}

const openNew = (wrapper) => wrapper.findAll('button').find((button) => button.text() === 'Nueva ventanilla').trigger('click')

beforeEach(() => {
	vi.clearAllMocks()
	document.body.innerHTML = ''
	admin.servicios.list.mockResolvedValue([FARMACIA, MUESTRAS])
	admin.boxes.list.mockResolvedValue([BOX2, BOX3])
})

describe('BoxesView list', () => {
	it('lists each box with the servicios it serves', async () => {
		const wrapper = await mountView()

		expect(wrapper.findAll('tbody tr').map((row) => row.findAll('th, td').map((cell) => cell.text()))).toEqual([
			['Box 2', 'Farmacia'],
			['Box 3', 'Farmacia, Toma de Muestras'],
		])
	})
})

describe('BoxesView loading', () => {
	it('tells the admin when the list could not be loaded', async () => {
		vi.spyOn(console, 'warn').mockImplementation(() => {})
		admin.boxes.list.mockRejectedValue(new Error('Network Error'))

		const wrapper = await mountView()

		expect(wrapper.find('[role="alert"]').text()).toBe('No se pudo cargar la lista de ventanillas.')
	})

	it('invites the admin to create the first box when there are none', async () => {
		admin.boxes.list.mockResolvedValue([])

		const wrapper = await mountView()

		expect(wrapper.text()).toContain('Todavía no hay ventanillas. Complete el formulario para crear la primera.')
	})
})

describe('BoxesView section', () => {
	it('titles the page Ventanillas and keeps the form closed until one is picked or a new one is asked for', async () => {
		const wrapper = await mountView()

		expect(wrapper.find('h2').text()).toBe('Ventanillas')
		expect(wrapper.find('aside').exists()).toBe(false)
	})

	it('opens an empty form in the sidebar for "Nueva ventanilla"', async () => {
		const wrapper = await mountView()

		await openNew(wrapper)

		expect(wrapper.find('aside h2').text()).toBe('Nueva ventanilla')
		expect(field(wrapper, 'Nombre').value).toBe('')
	})

	it('opens the picked ventanilla in the sidebar, named for it', async () => {
		const wrapper = await mountView()

		await wrapper.findAll('tbody button').find((button) => button.text() === 'Box 3').trigger('click')

		expect(wrapper.find('aside h2').text()).toBe('Box 3')
		expect(wrapper.find('aside').text()).toContain('Editar ventanilla')
	})

	it('closes the sidebar with its own close button', async () => {
		const wrapper = await mountView()
		await openNew(wrapper)

		await wrapper.find('aside button[aria-label]').trigger('click')

		expect(wrapper.find('aside').exists()).toBe(false)
	})
})

describe('BoxesView form', () => {
	it('offers a name capped at 14 and a checkbox for every servicio, inactive ones included', async () => {
		const wrapper = await mountView()
		await openNew(wrapper)

		expect(field(wrapper, 'Nombre').maxLength).toBe(14)
		expect(wrapper.find('fieldset legend').text()).toBe('Atiende')
		const boxes = wrapper.findAll('fieldset input[type="checkbox"]')
		expect(boxes).toHaveLength(2)
		expect(field(wrapper, 'Farmacia').checked).toBe(false)
		expect(field(wrapper, 'Toma de Muestras').checked).toBe(false)
		expect(wrapper.find('button[type="submit"]').text()).toBe('Guardar ventanilla')
	})
})

async function pick(wrapper, name) {
	await wrapper.findAll('tbody button').find((button) => button.text() === name).trigger('click')
}

describe('BoxesView picking', () => {
	it('loads a picked box into the form, with the servicios it serves checked', async () => {
		const wrapper = await mountView()

		await pick(wrapper, 'Box 3')

		expect(field(wrapper, 'Nombre').value).toBe('Box 3')
		expect(field(wrapper, 'Farmacia').checked).toBe(true)
		expect(field(wrapper, 'Toma de Muestras').checked).toBe(true)
	})

	it('"Nueva ventanilla" clears the form', async () => {
		const wrapper = await mountView()
		await pick(wrapper, 'Box 3')

		await wrapper.findAll('button').find((button) => button.text() === 'Nueva ventanilla').trigger('click')

		expect(field(wrapper, 'Nombre').value).toBe('')
		expect(field(wrapper, 'Farmacia').checked).toBe(false)
	})
})

describe('BoxesView saving', () => {
	async function submit(wrapper) {
		await wrapper.find('form').trigger('submit')
		await flushPromises()
	}

	it('creates a box with the checked servicios, refreshes the list and says "Guardado."', async () => {
		admin.boxes.save.mockResolvedValue({ id: 40, name: 'Box 4', servicioIds: [2] })
		const wrapper = await mountView()
		await openNew(wrapper)
		await wrapper.find('input[maxlength="14"]').setValue('Box 4')
		await field(wrapper, 'Toma de Muestras').click()
		admin.boxes.list.mockClear()

		await submit(wrapper)

		expect(admin.boxes.save).toHaveBeenCalledWith(null, { name: 'Box 4', servicioIds: [2] })
		expect(admin.boxes.list).toHaveBeenCalledTimes(1)
		expect(wrapper.text()).toContain('Guardado.')
	})

	it('shows the server sentence when it refuses a box that serves no servicio', async () => {
		admin.boxes.save.mockRejectedValue({ response: { status: 422, data: { message: 'Un box debe atender al menos un servicio.' } } })
		const wrapper = await mountView()
		await openNew(wrapper)
		await wrapper.find('input[maxlength="14"]').setValue('Box 4')

		await submit(wrapper)

		expect(wrapper.find('[role="alert"]').text()).toBe('Un box debe atender al menos un servicio.')
		expect(wrapper.text()).not.toContain('Guardado.')
	})

	it('updates a picked box under its id', async () => {
		admin.boxes.save.mockResolvedValue(BOX3)
		const wrapper = await mountView()
		await pick(wrapper, 'Box 3')
		await field(wrapper, 'Toma de Muestras').click()

		await submit(wrapper)

		expect(admin.boxes.save).toHaveBeenLastCalledWith(30, { name: 'Box 3', servicioIds: [1] })
	})

	it('says the box no longer exists on a 404, and refreshes the list', async () => {
		admin.boxes.save.mockRejectedValue({ response: { status: 404 } })
		const wrapper = await mountView()
		await pick(wrapper, 'Box 3')
		admin.boxes.list.mockClear()

		await submit(wrapper)

		expect(wrapper.find('[role="alert"]').text()).toBe('Esta ventanilla ya no existe.')
		expect(admin.boxes.list).toHaveBeenCalledTimes(1)
	})

	it('saves again under the new id after creating', async () => {
		admin.boxes.save.mockResolvedValue({ id: 40, name: 'Box 4', servicioIds: [1] })
		const wrapper = await mountView()
		await openNew(wrapper)
		await wrapper.find('input[maxlength="14"]').setValue('Box 4')
		await submit(wrapper)

		await submit(wrapper)

		expect(admin.boxes.save).toHaveBeenNthCalledWith(2, 40, { name: 'Box 4', servicioIds: [1] })
	})
})

describe('BoxesView deleting', () => {
	const button = (wrapper, text) => wrapper.findAll('button').find((candidate) => candidate.text() === text)

	it('offers "Eliminar ventanilla" only for a box that already exists', async () => {
		const wrapper = await mountView()
		await openNew(wrapper)
		expect(button(wrapper, 'Eliminar ventanilla')).toBeUndefined()

		await pick(wrapper, 'Box 3')

		expect(button(wrapper, 'Eliminar ventanilla')).toBeDefined()
	})

	it('asks inline first, with a question, "Sí, eliminar" and "Cancelar", and deletes nothing yet', async () => {
		const confirm = vi.spyOn(window, 'confirm')
		const wrapper = await mountView()
		await pick(wrapper, 'Box 3')

		await button(wrapper, 'Eliminar ventanilla').trigger('click')

		expect(wrapper.text()).toContain('¿Eliminar Box 3?')
		expect(button(wrapper, 'Sí, eliminar')).toBeDefined()
		expect(button(wrapper, 'Cancelar')).toBeDefined()
		expect(admin.boxes.remove).not.toHaveBeenCalled()
		expect(confirm).not.toHaveBeenCalled()
	})

	it('goes back to "Eliminar ventanilla" on "Cancelar", deleting nothing', async () => {
		const wrapper = await mountView()
		await pick(wrapper, 'Box 3')
		await button(wrapper, 'Eliminar ventanilla').trigger('click')

		await button(wrapper, 'Cancelar').trigger('click')

		expect(wrapper.text()).not.toContain('¿Eliminar Box 3?')
		expect(button(wrapper, 'Eliminar ventanilla')).toBeDefined()
		expect(admin.boxes.remove).not.toHaveBeenCalled()
	})

	it('names the saved box in the question, not what is half-typed in the name field', async () => {
		const wrapper = await mountView()
		await pick(wrapper, 'Box 3')
		await wrapper.find('input[maxlength="14"]').setValue('Otro nombre')

		await button(wrapper, 'Eliminar ventanilla').trigger('click')

		expect(wrapper.text()).toContain('¿Eliminar Box 3?')
	})

	it('moves focus to "Cancelar" when it asks, so a stray Enter does not delete', async () => {
		const wrapper = await mountView()
		await pick(wrapper, 'Box 3')

		await button(wrapper, 'Eliminar ventanilla').trigger('click')
		await flushPromises()

		expect(document.activeElement.textContent.trim()).toBe('Cancelar')
	})

	async function confirmDeletion(wrapper) {
		await pick(wrapper, 'Box 3')
		await button(wrapper, 'Eliminar ventanilla').trigger('click')
		await button(wrapper, 'Sí, eliminar').trigger('click')
		await flushPromises()
	}

	it('deletes on "Sí, eliminar", refreshes the list, clears the form and says so', async () => {
		admin.boxes.remove.mockResolvedValue({})
		const wrapper = await mountView()
		admin.boxes.list.mockResolvedValue([BOX2])

		await confirmDeletion(wrapper)

		expect(admin.boxes.remove).toHaveBeenCalledWith(30)
		expect(wrapper.findAll('tbody tr')).toHaveLength(1)
		expect(field(wrapper, 'Nombre').value).toBe('')
		expect(button(wrapper, 'Eliminar ventanilla')).toBeUndefined()
		expect(wrapper.find('[role="status"]').text()).toBe('Ventanilla eliminada.')
	})

	it('shows the sentence when the server refuses the delete (a box in use)', async () => {
		admin.boxes.remove.mockRejectedValue({ response: { status: 422, data: { message: 'El Box 3 tiene turnos en atención.' } } })
		const wrapper = await mountView()

		await confirmDeletion(wrapper)

		expect(wrapper.find('[role="alert"]').text()).toBe('El Box 3 tiene turnos en atención.')
		expect(field(wrapper, 'Nombre').value).toBe('Box 3')
	})

	it('says the box no longer exists on a 404, and refreshes the list', async () => {
		admin.boxes.remove.mockRejectedValue({ response: { status: 404 } })
		const wrapper = await mountView()
		admin.boxes.list.mockClear()

		await confirmDeletion(wrapper)

		expect(wrapper.find('[role="alert"]').text()).toBe('Esta ventanilla ya no existe.')
		expect(admin.boxes.list).toHaveBeenCalledTimes(1)
	})
})
