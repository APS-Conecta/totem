// SPDX-License-Identifier: AGPL-3.0-or-later
// @vitest-environment jsdom
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

vi.mock('../api.js', async (importActual) => ({
	...(await importActual()),
	admin: {
		servicios: { list: vi.fn() },
		pantallas: { list: vi.fn(), save: vi.fn(), remove: vi.fn() },
	},
}))
vi.mock('../lib/announce.js', () => ({ testAnnouncement: vi.fn() }))

import { admin } from '../api.js'
import { testAnnouncement } from '../lib/announce.js'
import PantallasView from './PantallasView.vue'
import { field } from './test-helpers.js'

const FARMACIA = { id: 1, name: 'Farmacia', prefix: 'FAR', color: '#0b6e4f', opens: '07:30', closes: '16:30', dailyQuota: null, active: true }
const MUESTRAS = { id: 2, name: 'Toma de Muestras', prefix: 'MUE', color: '#7a3e9d', opens: '07:30', closes: '10:30', dailyQuota: 80, active: false }
const SALA = { id: 5, slug: 'sala-farmacia', name: 'Sala Farmacia', volume: 60, voice: 'hombre', servicioIds: [1] }
const CENTRAL = { id: 6, slug: 'central', name: 'Central', volume: 100, voice: 'mujer', servicioIds: [] }
const SILENCIOSA = { id: 7, slug: 'silenciosa', name: 'Silenciosa', volume: 40, voice: 'off', servicioIds: [1, 2] }

async function mountView() {
	const wrapper = mount(PantallasView, { attachTo: document.body })
	await flushPromises()
	return wrapper
}

async function pick(wrapper, name) {
	await wrapper.findAll('tbody button').find((button) => button.text() === name).trigger('click')
}

const button = (wrapper, text) => wrapper.findAll('button').find((candidate) => candidate.text() === text)

beforeEach(() => {
	vi.clearAllMocks()
	document.body.innerHTML = ''
	admin.servicios.list.mockResolvedValue([FARMACIA, MUESTRAS])
	admin.pantallas.list.mockResolvedValue([SALA, CENTRAL, SILENCIOSA])
})

describe('PantallasView list', () => {
	it('lists each pantalla with the servicios it shows and its aviso settings', async () => {
		const wrapper = await mountView()

		expect(wrapper.findAll('tbody tr').map((row) => row.findAll('td').map((cell) => cell.text()))).toEqual([
			['Sala Farmacia', 'Farmacia', 'Volumen 60 %, voz de hombre'],
			['Central', 'Todas las unidades', 'Volumen 100 %, voz de mujer'],
			['Silenciosa', 'Farmacia, Toma de Muestras', 'Volumen 40 %, sin voz'],
		])
	})
})

describe('PantallasView form', () => {
	it('offers the fields of a new pantalla, none of them the slug', async () => {
		const wrapper = await mountView()

		expect(field(wrapper, 'Nombre').maxLength).toBe(32)
		expect(wrapper.find('fieldset legend').text()).toBe('Unidades que muestra (ninguna marcada = todas)')
		expect(field(wrapper, 'Farmacia').checked).toBe(false)
		expect(field(wrapper, 'Toma de Muestras').checked).toBe(false)
		const volume = field(wrapper, 'Volumen del aviso')
		expect([volume.type, volume.min, volume.max, volume.step, volume.value]).toEqual(['range', '0', '100', '5', '100'])
		const voice = field(wrapper, 'Voz del aviso')
		expect([...voice.options].map((option) => [option.value, option.text])).toEqual([
			['mujer', 'Mujer (Claude, México)'],
			['hombre', 'Hombre (Davefx, España)'],
			['off', 'Sin voz, solo tonos'],
		])
		expect(voice.value).toBe('mujer')
		expect(button(wrapper, 'Probar aviso')).toBeDefined()
		expect(wrapper.find('button[type="submit"]').text()).toBe('Guardar pantalla')
		expect(wrapper.text()).not.toMatch(/slug/i)
	})

	it('shows the volume readout live as the range moves', async () => {
		const wrapper = await mountView()

		await wrapper.find('input[type="range"]').setValue('35')

		expect(wrapper.text()).toContain('Volumen del aviso: 35 %')
	})
})

describe('PantallasView picking', () => {
	it('loads a picked pantalla into the form', async () => {
		const wrapper = await mountView()

		await pick(wrapper, 'Silenciosa')

		expect(field(wrapper, 'Nombre').value).toBe('Silenciosa')
		expect(field(wrapper, 'Farmacia').checked).toBe(true)
		expect(field(wrapper, 'Toma de Muestras').checked).toBe(true)
		expect(field(wrapper, 'Volumen del aviso').value).toBe('40')
		expect(field(wrapper, 'Voz del aviso').value).toBe('off')
	})

	it('"Nueva pantalla" clears the form back to a new one', async () => {
		const wrapper = await mountView()
		await pick(wrapper, 'Silenciosa')

		await button(wrapper, 'Nueva pantalla').trigger('click')

		expect(field(wrapper, 'Nombre').value).toBe('')
		expect(field(wrapper, 'Farmacia').checked).toBe(false)
		expect(field(wrapper, 'Volumen del aviso').value).toBe('100')
		expect(field(wrapper, 'Voz del aviso').value).toBe('mujer')
	})
})

describe('PantallasView saving', () => {
	async function submit(wrapper) {
		await wrapper.find('form').trigger('submit')
		await flushPromises()
	}

	it('creates a pantalla from the form, without any slug, refreshes the list and says "Guardado."', async () => {
		admin.pantallas.save.mockResolvedValue({ id: 8, slug: 'sala-azul', name: 'Sala Azul', volume: 35, voice: 'hombre', servicioIds: [2] })
		const wrapper = await mountView()
		await wrapper.find('input[maxlength="32"]').setValue('Sala Azul')
		await field(wrapper, 'Toma de Muestras').click()
		await wrapper.find('input[type="range"]').setValue('35')
		await wrapper.find('select').setValue('hombre')
		admin.pantallas.list.mockClear()

		await submit(wrapper)

		expect(admin.pantallas.save).toHaveBeenCalledWith(null, { name: 'Sala Azul', servicioIds: [2], volume: 35, voice: 'hombre' })
		expect(admin.pantallas.list).toHaveBeenCalledTimes(1)
		expect(wrapper.text()).toContain('Guardado.')
	})

	it('updates a picked pantalla under its id and never sends the slug', async () => {
		admin.pantallas.save.mockResolvedValue(SALA)
		const wrapper = await mountView()
		await pick(wrapper, 'Sala Farmacia')

		await submit(wrapper)

		expect(admin.pantallas.save).toHaveBeenCalledWith(5, { name: 'Sala Farmacia', servicioIds: [1], volume: 60, voice: 'hombre' })
	})

	it('shows the sentence of a refusal next to the form and does not claim it saved', async () => {
		admin.pantallas.save.mockRejectedValue({ response: { status: 422, data: { message: 'Ya existe una pantalla con ese nombre.' } } })
		const wrapper = await mountView()

		await submit(wrapper)

		expect(wrapper.find('[role="alert"]').text()).toBe('Ya existe una pantalla con ese nombre.')
		expect(wrapper.text()).not.toContain('Guardado.')
	})

	it('says the pantalla no longer exists on a 404, and refreshes the list', async () => {
		admin.pantallas.save.mockRejectedValue({ response: { status: 404 } })
		const wrapper = await mountView()
		await pick(wrapper, 'Sala Farmacia')
		admin.pantallas.list.mockClear()

		await submit(wrapper)

		expect(wrapper.find('[role="alert"]').text()).toBe('Esta pantalla ya no existe.')
		expect(admin.pantallas.list).toHaveBeenCalledTimes(1)
	})

	it('keeps editing the pantalla it just created, so saving again updates', async () => {
		admin.pantallas.save.mockResolvedValue({ id: 8, slug: 'sala-azul', name: 'Sala Azul', volume: 100, voice: 'mujer', servicioIds: [] })
		const wrapper = await mountView()
		await submit(wrapper)

		await submit(wrapper)

		expect(admin.pantallas.save).toHaveBeenNthCalledWith(2, 8, expect.anything())
	})
})

describe('PantallasView display address', () => {
	function stubClipboard(writeText) {
		Object.defineProperty(window.navigator, 'clipboard', { value: { writeText }, configurable: true })
	}

	it('shows no address for a pantalla that is not saved yet', async () => {
		const wrapper = await mountView()

		expect(wrapper.text()).not.toContain('/apps/totem/display/')
		expect(button(wrapper, 'Copiar dirección')).toBeUndefined()
	})

	it('shows the display address of a saved pantalla as selectable text', async () => {
		const wrapper = await mountView()

		await pick(wrapper, 'Sala Farmacia')

		const address = wrapper.find('.totem-address')
		expect(address.text()).toContain('/apps/totem/display/sala-farmacia')
		expect(address.element.tagName).toBe('CODE')
	})

	it('copies the address and says so', async () => {
		const writeText = vi.fn().mockResolvedValue()
		stubClipboard(writeText)
		const wrapper = await mountView()
		await pick(wrapper, 'Sala Farmacia')

		await button(wrapper, 'Copiar dirección').trigger('click')
		await flushPromises()

		expect(writeText).toHaveBeenCalledWith(wrapper.find('.totem-address').text())
		expect(wrapper.find('[role="status"]').text()).toBe('Dirección copiada.')
	})

	it('asks the admin to copy it by hand when the browser refuses', async () => {
		stubClipboard(vi.fn().mockRejectedValue(new Error('denied')))
		const wrapper = await mountView()
		await pick(wrapper, 'Sala Farmacia')

		await button(wrapper, 'Copiar dirección').trigger('click')
		await flushPromises()

		expect(wrapper.find('[role="alert"]').text()).toBe('No se pudo copiar. Seleccione la dirección y cópiela.')
	})
})

describe('PantallasView "Probar aviso"', () => {
	it('plays the announcement with the form\'s current values, not the saved ones', async () => {
		testAnnouncement.mockResolvedValue()
		const wrapper = await mountView()
		await pick(wrapper, 'Sala Farmacia')
		await wrapper.find('input[type="range"]').setValue('35')
		await wrapper.find('select').setValue('off')

		await button(wrapper, 'Probar aviso').trigger('click')

		expect(testAnnouncement).toHaveBeenCalledWith({ volume: 35, voice: 'off' })
		expect(admin.pantallas.save).not.toHaveBeenCalled()
	})

	it('says so when the announcement cannot be played', async () => {
		vi.spyOn(console, 'warn').mockImplementation(() => {})
		testAnnouncement.mockRejectedValue(new Error('no audio'))
		const wrapper = await mountView()

		await button(wrapper, 'Probar aviso').trigger('click')
		await flushPromises()

		expect(wrapper.find('[role="alert"]').text()).toBe('No se pudo reproducir el aviso.')
	})
})

describe('PantallasView loading', () => {
	it('tells the admin when the list could not be loaded', async () => {
		vi.spyOn(console, 'warn').mockImplementation(() => {})
		admin.pantallas.list.mockRejectedValue(new Error('Network Error'))

		const wrapper = await mountView()

		expect(wrapper.find('[role="alert"]').text()).toBe('No se pudo cargar la lista de pantallas.')
	})

	it('invites the admin to create the first pantalla when there are none', async () => {
		admin.pantallas.list.mockResolvedValue([])

		const wrapper = await mountView()

		expect(wrapper.text()).toContain('Todavía no hay pantallas. Complete el formulario para crear la primera.')
	})
})

describe('PantallasView deleting', () => {
	async function confirmDeletion(wrapper) {
		await pick(wrapper, 'Sala Farmacia')
		await button(wrapper, 'Eliminar pantalla').trigger('click')
		await button(wrapper, 'Sí, eliminar').trigger('click')
		await flushPromises()
	}

	it('offers "Eliminar pantalla" only for a pantalla that already exists, and asks inline first', async () => {
		const confirm = vi.spyOn(window, 'confirm')
		const wrapper = await mountView()
		expect(button(wrapper, 'Eliminar pantalla')).toBeUndefined()
		await pick(wrapper, 'Sala Farmacia')

		await button(wrapper, 'Eliminar pantalla').trigger('click')

		expect(wrapper.text()).toContain('¿Eliminar Sala Farmacia?')
		expect(button(wrapper, 'Sí, eliminar')).toBeDefined()
		expect(button(wrapper, 'Cancelar')).toBeDefined()
		expect(admin.pantallas.remove).not.toHaveBeenCalled()
		expect(confirm).not.toHaveBeenCalled()
	})

	it('deletes on "Sí, eliminar", refreshes the list, clears the form and says so', async () => {
		admin.pantallas.remove.mockResolvedValue({})
		const wrapper = await mountView()
		admin.pantallas.list.mockResolvedValue([CENTRAL, SILENCIOSA])

		await confirmDeletion(wrapper)

		expect(admin.pantallas.remove).toHaveBeenCalledWith(5)
		expect(wrapper.findAll('tbody tr')).toHaveLength(2)
		expect(field(wrapper, 'Nombre').value).toBe('')
		expect(wrapper.text()).not.toContain('/apps/totem/display/')
		expect(wrapper.find('[role="status"]').text()).toBe('Pantalla eliminada.')
	})

	it('shows the sentence when the server refuses the delete', async () => {
		admin.pantallas.remove.mockRejectedValue({ response: { status: 422, data: { message: 'No se puede eliminar la última pantalla.' } } })
		const wrapper = await mountView()

		await confirmDeletion(wrapper)

		expect(wrapper.find('[role="alert"]').text()).toBe('No se puede eliminar la última pantalla.')
		expect(field(wrapper, 'Nombre').value).toBe('Sala Farmacia')
	})

	it('says the pantalla no longer exists on a 404, and refreshes the list', async () => {
		admin.pantallas.remove.mockRejectedValue({ response: { status: 404 } })
		const wrapper = await mountView()
		admin.pantallas.list.mockClear()

		await confirmDeletion(wrapper)

		expect(wrapper.find('[role="alert"]').text()).toBe('Esta pantalla ya no existe.')
		expect(admin.pantallas.list).toHaveBeenCalledTimes(1)
	})
})
