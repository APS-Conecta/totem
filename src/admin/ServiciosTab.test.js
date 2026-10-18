// SPDX-License-Identifier: AGPL-3.0-or-later
// @vitest-environment jsdom
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

vi.mock('../api.js', async (importActual) => ({
	...(await importActual()),
	admin: { servicios: { list: vi.fn(), save: vi.fn() } },
}))

import { admin } from '../api.js'
import ServiciosTab from './ServiciosTab.vue'
import { field } from './test-helpers.js'

const FARMACIA = { id: 1, name: 'Farmacia', prefix: 'FAR', color: '#0b6e4f', opens: '07:30', closes: '16:30', dailyQuota: null, active: true }
const MUESTRAS = { id: 2, name: 'Toma de Muestras', prefix: 'MUE', color: '#7a3e9d', opens: '07:30', closes: '10:30', dailyQuota: 80, active: false }

async function mountTab() {
	const wrapper = mount(ServiciosTab, { attachTo: document.body })
	await flushPromises()
	return wrapper
}

beforeEach(() => {
	vi.clearAllMocks()
	document.body.innerHTML = ''
	admin.servicios.list.mockResolvedValue([FARMACIA, MUESTRAS])
})

describe('ServiciosTab list', () => {
	it('lists each servicio with prefix, hours, daily quota and state', async () => {
		const wrapper = await mountTab()

		const rows = wrapper.findAll('tbody tr')
		expect(rows.map((row) => row.findAll('td').map((cell) => cell.text()))).toEqual([
			['Farmacia', 'FAR', '07:30–16:30', 'Sin límite', 'Activo'],
			['Toma de Muestras', 'MUE', '07:30–10:30', '80', 'Inactivo'],
		])
	})
})

describe('ServiciosTab loading', () => {
	it('tells the admin when the list could not be loaded', async () => {
		vi.spyOn(console, 'warn').mockImplementation(() => {})
		admin.servicios.list.mockRejectedValue(new Error('Network Error'))

		const wrapper = await mountTab()

		expect(wrapper.find('[role="alert"]').text()).toBe('No se pudo cargar la lista de servicios.')
	})

	it('invites the admin to create the first servicio when there are none', async () => {
		admin.servicios.list.mockResolvedValue([])

		const wrapper = await mountTab()

		expect(wrapper.text()).toContain('Todavía no hay servicios. Complete el formulario para crear el primero.')
	})
})

describe('ServiciosTab form', () => {
	it('offers the fields of a new servicio, each with a visible label and the capped lengths', async () => {
		const wrapper = await mountTab()

		expect(field(wrapper, 'Nombre').maxLength).toBe(28)
		expect(field(wrapper, 'Prefijo').maxLength).toBe(4)
		expect(field(wrapper, 'Cupo diario').type).toBe('number')
		expect(field(wrapper, 'Abre').type).toBe('time')
		expect(field(wrapper, 'Cierra').type).toBe('time')
		expect(field(wrapper, 'Color').type).toBe('color')
		expect(wrapper.find('button[type="submit"]').text()).toBe('Guardar servicio')
	})
})

describe('ServiciosTab live color preview', () => {
	it('previews the prefix on the chosen color with readable text and reports a legible contrast', async () => {
		const wrapper = await mountTab()

		await wrapper.find('input[type="color"]').setValue('#1d5a8a')
		await wrapper.find('input[maxlength="4"]').setValue('far')

		const chip = wrapper.find('.totem-chip')
		expect(chip.text()).toBe('FAR')
		expect(window.getComputedStyle(chip.element).backgroundColor).toBe('rgb(29, 90, 138)')
		expect(window.getComputedStyle(chip.element).color).toBe('rgb(255, 255, 255)')
		expect(wrapper.find('[role="status"]').text()).toBe('Contraste 7,3:1')
		expect(wrapper.find('[role="alert"]').exists()).toBe(false)
	})

	it('says so, and how to fix it, when the best text color is not legible on the chosen color', async () => {
		const wrapper = await mountTab()

		await wrapper.find('input[type="color"]').setValue('#6f7f8f')

		expect(wrapper.find('[role="alert"]').text()).toBe('Contraste 4,1:1. Elija un color más oscuro o más claro.')
	})
})

describe('ServiciosTab picking', () => {
	async function pick(wrapper, name) {
		await wrapper.findAll('tbody button').find((button) => button.text() === name).trigger('click')
	}

	it('loads a picked servicio into the form, state included', async () => {
		const wrapper = await mountTab()

		await pick(wrapper, 'Toma de Muestras')

		expect(field(wrapper, 'Nombre').value).toBe('Toma de Muestras')
		expect(field(wrapper, 'Prefijo').value).toBe('MUE')
		expect(field(wrapper, 'Cupo diario').value).toBe('80')
		expect(field(wrapper, 'Abre').value).toBe('07:30')
		expect(field(wrapper, 'Cierra').value).toBe('10:30')
		expect(field(wrapper, 'Color').value).toBe('#7a3e9d')
		expect(field(wrapper, 'Activo').checked).toBe(false)
	})

	it('"Nuevo servicio" clears the form back to a new one, active by default', async () => {
		const wrapper = await mountTab()
		await pick(wrapper, 'Toma de Muestras')

		await wrapper.findAll('button').find((button) => button.text() === 'Nuevo servicio').trigger('click')

		expect(field(wrapper, 'Nombre').value).toBe('')
		expect(field(wrapper, 'Prefijo').value).toBe('')
		expect(field(wrapper, 'Cupo diario').value).toBe('')
		expect(field(wrapper, 'Activo').checked).toBe(true)
	})
})

describe('ServiciosTab saving', () => {
	async function submit(wrapper) {
		await wrapper.find('form').trigger('submit')
		await flushPromises()
	}

	it('creates a servicio with an empty quota sent as null, refreshes the list and says "Guardado."', async () => {
		admin.servicios.save.mockResolvedValue({ ...FARMACIA, id: 3 })
		const wrapper = await mountTab()
		await wrapper.find('input[maxlength="28"]').setValue('Vacunatorio')
		await wrapper.find('input[maxlength="4"]').setValue('vac')
		await wrapper.find('input[type="color"]').setValue('#1d5a8a')
		admin.servicios.list.mockClear()

		await submit(wrapper)

		expect(admin.servicios.save).toHaveBeenCalledWith(null, {
			name: 'Vacunatorio', prefix: 'vac', dailyQuota: null, opens: '08:00', closes: '17:00', color: '#1d5a8a', active: true,
		})
		expect(admin.servicios.list).toHaveBeenCalledTimes(1)
		expect(wrapper.text()).toContain('Guardado.')
	})

	it('updates a picked servicio under its id, sending the quota as a number', async () => {
		admin.servicios.save.mockResolvedValue(MUESTRAS)
		const wrapper = await mountTab()
		await wrapper.findAll('tbody button').find((button) => button.text() === 'Toma de Muestras').trigger('click')
		await wrapper.find('input[type="number"]').setValue('90')

		await submit(wrapper)

		expect(admin.servicios.save).toHaveBeenCalledWith(2, expect.objectContaining({ dailyQuota: 90, active: false, prefix: 'MUE' }))
	})

	it('keeps editing the servicio it just created, so saving again updates instead of duplicating', async () => {
		admin.servicios.save.mockResolvedValue({ ...FARMACIA, id: 3 })
		const wrapper = await mountTab()
		await submit(wrapper)

		await submit(wrapper)

		expect(admin.servicios.save).toHaveBeenNthCalledWith(1, null, expect.anything())
		expect(admin.servicios.save).toHaveBeenNthCalledWith(2, 3, expect.anything())
	})

	it('shows the sentence of a refusal next to the form and does not claim it saved', async () => {
		admin.servicios.save.mockRejectedValue({ response: { status: 422, data: { message: 'Ya existe un servicio con el prefijo FAR.' } } })
		const wrapper = await mountTab()

		await submit(wrapper)

		expect(wrapper.find('[role="alert"]').text()).toBe('Ya existe un servicio con el prefijo FAR.')
		expect(wrapper.text()).not.toContain('Guardado.')
	})

	it('says the servicio no longer exists on a 404, and refreshes the list', async () => {
		admin.servicios.save.mockRejectedValue({ response: { status: 404 } })
		const wrapper = await mountTab()
		await wrapper.findAll('tbody button').find((button) => button.text() === 'Farmacia').trigger('click')
		admin.servicios.list.mockClear()

		await submit(wrapper)

		expect(wrapper.find('[role="alert"]').text()).toBe('Este servicio ya no existe.')
		expect(admin.servicios.list).toHaveBeenCalledTimes(1)
	})

	it('drops the previous outcome when another servicio is picked or a new one is started', async () => {
		admin.servicios.save.mockResolvedValue(FARMACIA)
		const wrapper = await mountTab()
		await submit(wrapper)
		expect(wrapper.text()).toContain('Guardado.')

		await wrapper.findAll('tbody button').find((button) => button.text() === 'Toma de Muestras').trigger('click')

		expect(wrapper.text()).not.toContain('Guardado.')
	})
})
