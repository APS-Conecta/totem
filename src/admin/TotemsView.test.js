// SPDX-License-Identifier: AGPL-3.0-or-later
// @vitest-environment jsdom
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

vi.mock('../api.js', async (importActual) => ({
	...(await importActual()),
	admin: {
		servicios: { list: vi.fn() },
		totems: { list: vi.fn(), save: vi.fn(), remove: vi.fn(), testPrint: vi.fn() },
	},
}))

import { admin } from '../api.js'
import TotemsView from './TotemsView.vue'
import { field } from './test-helpers.js'

const FARMACIA = { id: 1, name: 'Farmacia', prefix: 'FAR', color: '#0b6e4f', opens: '07:30', closes: '16:30', dailyQuota: null, active: true }
const MUESTRAS = { id: 2, name: 'Toma de Muestras', prefix: 'MUE', color: '#7a3e9d', opens: '07:30', closes: '10:30', dailyQuota: 80, active: false }
const at = (hour, minute) => Math.floor(new Date(2026, 9, 19, hour, minute).getTime() / 1000)
const ENTRADA = { id: 4, slug: 'entrada', name: 'Entrada', servicioIds: [], printerHost: '192.168.1.50', printerPort: 9100, printerOk: true, printerCheckedAt: at(10, 32) }
const SUR = { id: 5, slug: 'sala-sur', name: 'Sala sur', servicioIds: [1], printerHost: '192.168.1.51', printerPort: 9101, printerOk: false, printerCheckedAt: at(9, 58) }
const PASILLO = { id: 6, slug: 'pasillo', name: 'Pasillo', servicioIds: [1, 2], printerHost: null, printerPort: 9100, printerOk: null, printerCheckedAt: null }
const NUEVA = { id: 7, slug: 'nueva', name: 'Nueva', servicioIds: [], printerHost: '192.168.1.60', printerPort: 9100, printerOk: null, printerCheckedAt: null }

async function mountView() {
	const wrapper = mount(TotemsView, { attachTo: document.body })
	await flushPromises()
	return wrapper
}

async function pick(wrapper, name) {
	await wrapper.findAll('tbody button').find((button) => button.text() === name).trigger('click')
}

const button = (wrapper, text) => wrapper.findAll('button').find((candidate) => candidate.text() === text)
const openNew = (wrapper) => button(wrapper, 'Nuevo tótem').trigger('click')
const cells = (row) => row.findAll('th, td').map((cell) => cell.text())
/** Type into the control a visible label names. */
const fill = (wrapper, label, value) => wrapper.find(`#${field(wrapper, label).id}`).setValue(value)

beforeEach(() => {
	vi.clearAllMocks()
	document.body.innerHTML = ''
	admin.servicios.list.mockResolvedValue([FARMACIA, MUESTRAS])
	admin.totems.list.mockResolvedValue([ENTRADA, SUR, PASILLO])
})

describe('TotemsView list', () => {
	it('lists each tótem with the unidades it offers, its printer and how the last print went', async () => {
		const wrapper = await mountView()

		expect(wrapper.findAll('tbody tr').map(cells)).toEqual([
			['Entrada', 'Todas las unidades', '192.168.1.50', 'Imprimió a las 10:32', 'Abrir tótem'],
			['Sala sur', 'Farmacia', '192.168.1.51:9101', 'No respondió a las 09:58', 'Abrir tótem'],
			['Pasillo', 'Farmacia, Toma de Muestras', 'Imprime el navegador', '–', 'Abrir tótem'],
		])
	})

	it('says a printer was never tried, and marks one that did not answer', async () => {
		admin.totems.list.mockResolvedValue([NUEVA, SUR])

		const wrapper = await mountView()

		const rows = wrapper.findAll('tbody tr')
		expect(cells(rows[0])[3]).toBe('Sin probar')
		expect(rows[1].find('.totem-status--bad').text()).toBe('No respondió a las 09:58')
		expect(rows[0].find('.totem-status--bad').exists()).toBe(false)
	})

	it('offers "Abrir tótem" on every row, in a new tab, so nobody has to guess the address', async () => {
		const wrapper = await mountView()

		const links = wrapper.findAll('tbody a').filter((a) => a.text() === 'Abrir tótem')
		expect(links.map((a) => a.attributes('href').split('/').pop())).toEqual(['entrada', 'sala-sur', 'pasillo'])
		for (const a of links) {
			expect(a.attributes('target')).toBe('_blank')
			expect(a.attributes('rel')).toContain('noopener')
		}
	})
})

describe('TotemsView loading', () => {
	it('tells the admin when the list could not be loaded', async () => {
		vi.spyOn(console, 'warn').mockImplementation(() => {})
		admin.totems.list.mockRejectedValue(new Error('Network Error'))

		const wrapper = await mountView()

		expect(wrapper.find('[role="alert"]').text()).toBe('No se pudo cargar la lista de tótems.')
	})

	it('invites the admin to create the first one, in the masculine', async () => {
		admin.totems.list.mockResolvedValue([])

		const wrapper = await mountView()

		expect(wrapper.text()).toContain('Todavía no hay tótems. Complete el formulario para crear el primero.')
	})
})

describe('TotemsView section', () => {
	it('titles the page Tótems and keeps the form closed until one is picked or a new one is asked for', async () => {
		const wrapper = await mountView()

		expect(wrapper.find('h2').text()).toBe('Tótems')
		expect(wrapper.find('aside').exists()).toBe(false)
	})

	it('opens an empty form in the sidebar for "Nuevo tótem"', async () => {
		const wrapper = await mountView()

		await openNew(wrapper)

		expect(wrapper.find('aside h2').text()).toBe('Nuevo tótem')
		expect(field(wrapper, 'Nombre').value).toBe('')
	})

	it('opens the picked tótem in the sidebar, named for it', async () => {
		const wrapper = await mountView()

		await pick(wrapper, 'Sala sur')

		expect(wrapper.find('aside h2').text()).toBe('Sala sur')
		expect(wrapper.find('aside').text()).toContain('Editar tótem')
	})

	it('closes the sidebar with its own close button', async () => {
		const wrapper = await mountView()
		await openNew(wrapper)

		await wrapper.find('aside button[aria-label]').trigger('click')

		expect(wrapper.find('aside').exists()).toBe(false)
	})
})

describe('TotemsView form', () => {
	it('offers the name, a checkbox for every unidad, and the printer address and port', async () => {
		const wrapper = await mountView()
		await openNew(wrapper)

		expect(field(wrapper, 'Nombre').maxLength).toBe(32)
		expect(wrapper.find('fieldset legend').text()).toBe('Unidades que ofrece (ninguna marcada = todas)')
		expect(wrapper.findAll('fieldset input[type="checkbox"]')).toHaveLength(2)
		expect(field(wrapper, 'Dirección de la impresora').type).toBe('text')
		expect(field(wrapper, 'Dirección de la impresora').value).toBe('')
		expect(field(wrapper, 'Puerto').type).toBe('number')
		expect(field(wrapper, 'Puerto').value).toBe('9100')
		expect(wrapper.find('button[type="submit"]').text()).toBe('Guardar tótem')
	})

	it('explains where a printer may be and what no address means', async () => {
		const wrapper = await mountView()
		await openNew(wrapper)

		expect(wrapper.find('aside').text()).toContain('red de la clínica')
		expect(wrapper.find('aside').text()).toContain('Sin dirección, imprime el navegador del tótem.')
	})

	it('loads a picked tótem into the form, printer included', async () => {
		const wrapper = await mountView()

		await pick(wrapper, 'Sala sur')

		expect(field(wrapper, 'Nombre').value).toBe('Sala sur')
		expect(field(wrapper, 'Dirección de la impresora').value).toBe('192.168.1.51')
		expect(field(wrapper, 'Puerto').value).toBe('9101')
		expect(field(wrapper, 'Farmacia').checked).toBe(true)
		expect(field(wrapper, 'Toma de Muestras').checked).toBe(false)
	})

	it('shows an empty address for a tótem without a printer', async () => {
		const wrapper = await mountView()

		await pick(wrapper, 'Pasillo')

		expect(field(wrapper, 'Dirección de la impresora').value).toBe('')
	})

	it('"Nuevo tótem" clears the form back to a new one', async () => {
		const wrapper = await mountView()
		await pick(wrapper, 'Sala sur')

		await openNew(wrapper)

		expect(field(wrapper, 'Nombre').value).toBe('')
		expect(field(wrapper, 'Dirección de la impresora').value).toBe('')
		expect(field(wrapper, 'Puerto').value).toBe('9100')
	})
})

describe('TotemsView saving', () => {
	async function submit(wrapper) {
		await wrapper.find('form').trigger('submit')
		await flushPromises()
	}

	it('creates a tótem with its unidades and printer, refreshes the list and says "Guardado."', async () => {
		admin.totems.save.mockResolvedValue({ ...NUEVA, name: 'Nueva' })
		const wrapper = await mountView()
		await openNew(wrapper)
		await fill(wrapper, 'Nombre', 'Nueva')
		await fill(wrapper, 'Dirección de la impresora', '192.168.1.60')
		await field(wrapper, 'Farmacia').click()
		admin.totems.list.mockClear()

		await submit(wrapper)

		expect(admin.totems.save).toHaveBeenCalledWith(null, { name: 'Nueva', servicioIds: [1], printerHost: '192.168.1.60', printerPort: 9100 })
		expect(admin.totems.list).toHaveBeenCalledTimes(1)
		expect(wrapper.text()).toContain('Guardado.')
	})

	it('sends no printer address when the field is left empty, which means the browser prints', async () => {
		admin.totems.save.mockResolvedValue(PASILLO)
		const wrapper = await mountView()
		await openNew(wrapper)
		await fill(wrapper, 'Nombre', 'Pasillo')

		await submit(wrapper)

		expect(admin.totems.save).toHaveBeenCalledWith(null, expect.objectContaining({ printerHost: '' }))
	})

	it('updates a picked tótem under its id', async () => {
		admin.totems.save.mockResolvedValue(SUR)
		const wrapper = await mountView()
		await pick(wrapper, 'Sala sur')
		await fill(wrapper, 'Puerto', '9102')

		await submit(wrapper)

		expect(admin.totems.save).toHaveBeenCalledWith(5, { name: 'Sala sur', servicioIds: [1], printerHost: '192.168.1.51', printerPort: 9102 })
	})

	it('shows the sentence of a refusal next to the form and does not claim it saved', async () => {
		admin.totems.save.mockRejectedValue({ response: { status: 422, data: { message: 'La impresora debe estar en la red de la clínica (direcciones 10.x, 172.16 a 172.31 o 192.168.x).' } } })
		const wrapper = await mountView()
		await openNew(wrapper)
		await fill(wrapper, 'Nombre', 'Entrada')
		await fill(wrapper, 'Dirección de la impresora', '8.8.8.8')

		await submit(wrapper)

		expect(wrapper.find('[role="alert"]').text()).toContain('red de la clínica')
		expect(wrapper.text()).not.toContain('Guardado.')
	})

	it('says the tótem no longer exists on a 404, and refreshes the list', async () => {
		admin.totems.save.mockRejectedValue({ response: { status: 404 } })
		const wrapper = await mountView()
		await pick(wrapper, 'Entrada')
		admin.totems.list.mockClear()

		await submit(wrapper)

		expect(wrapper.find('[role="alert"]').text()).toBe('Este tótem ya no existe.')
		expect(admin.totems.list).toHaveBeenCalledTimes(1)
	})

	it('keeps editing the tótem it just created, so saving again updates', async () => {
		admin.totems.save.mockResolvedValue(NUEVA)
		const wrapper = await mountView()
		await openNew(wrapper)
		await fill(wrapper, 'Nombre', 'Nueva')
		await submit(wrapper)

		await submit(wrapper)

		expect(admin.totems.save).toHaveBeenNthCalledWith(1, null, expect.anything())
		expect(admin.totems.save).toHaveBeenNthCalledWith(2, 7, expect.anything())
	})
})

describe('TotemsView printer status and test print', () => {
	it('shows how the last print went for a tótem with a printer', async () => {
		const wrapper = await mountView()

		await pick(wrapper, 'Sala sur')

		expect(wrapper.find('aside').text()).toContain('No respondió a las 09:58')
	})

	it('offers "Probar impresión" only for a saved tótem that has a printer', async () => {
		const wrapper = await mountView()
		await openNew(wrapper)
		expect(button(wrapper, 'Probar impresión')).toBeUndefined()

		await pick(wrapper, 'Pasillo')
		expect(button(wrapper, 'Probar impresión')).toBeUndefined()

		await pick(wrapper, 'Entrada')
		expect(button(wrapper, 'Probar impresión')).toBeDefined()
	})

	it('prints a sample ticket and says where it went', async () => {
		admin.totems.testPrint.mockResolvedValue({ printed: true, totem: { ...ENTRADA, printerOk: true } })
		const wrapper = await mountView()
		await pick(wrapper, 'Entrada')
		admin.totems.list.mockClear()

		await button(wrapper, 'Probar impresión').trigger('click')
		await flushPromises()

		expect(admin.totems.testPrint).toHaveBeenCalledWith(4)
		expect(wrapper.find('[role="status"]').text()).toBe('Ticket de prueba enviado a 192.168.1.50:9100.')
		expect(admin.totems.list).toHaveBeenCalledTimes(1)
	})

	it('says what to check when the printer did not answer, and refreshes the status in the list', async () => {
		admin.totems.testPrint.mockResolvedValue({ printed: false, totem: { ...ENTRADA, printerOk: false, printerCheckedAt: at(11, 5) } })
		const wrapper = await mountView()
		await pick(wrapper, 'Entrada')

		await button(wrapper, 'Probar impresión').trigger('click')
		await flushPromises()

		expect(wrapper.find('[role="alert"]').text()).toBe('No se pudo imprimir: la impresora no respondió. Revise que esté encendida y conectada a la red.')
		expect(wrapper.text()).not.toContain('Ticket de prueba enviado')
	})

	it('shows the server sentence when the test print is refused', async () => {
		admin.totems.testPrint.mockRejectedValue({ response: { status: 422, data: { message: 'Este tótem no tiene impresora de red. Escriba su dirección y guarde.' } } })
		const wrapper = await mountView()
		await pick(wrapper, 'Entrada')

		await button(wrapper, 'Probar impresión').trigger('click')
		await flushPromises()

		expect(wrapper.find('[role="alert"]').text()).toContain('no tiene impresora de red')
	})
})

describe('TotemsView address', () => {
	const stubClipboard = (writeText) => Object.defineProperty(window.navigator, 'clipboard', { value: { writeText }, configurable: true })

	it('shows no address for a tótem that is not saved yet', async () => {
		const wrapper = await mountView()
		await openNew(wrapper)

		expect(wrapper.find('aside code').exists()).toBe(false)
	})

	it('shows the address of a saved tótem as selectable text, with a link that opens it in a new tab', async () => {
		const wrapper = await mountView()
		await pick(wrapper, 'Sala sur')

		expect(wrapper.find('aside code').text()).toBe(`${window.location.origin}${wrapper.find('aside code').text().replace(window.location.origin, '')}`)
		expect(wrapper.find('aside code').text()).toContain('/apps/totem/totem/sala-sur')
		const open = wrapper.findAll('aside a').find((a) => a.text() === 'Abrir tótem')
		expect(open.attributes('href')).toContain('/apps/totem/totem/sala-sur')
		expect(open.attributes('target')).toBe('_blank')
	})

	it('copies the address and says so', async () => {
		const writeText = vi.fn().mockResolvedValue()
		stubClipboard(writeText)
		const wrapper = await mountView()
		await pick(wrapper, 'Sala sur')

		await button(wrapper, 'Copiar dirección').trigger('click')
		await flushPromises()

		expect(writeText).toHaveBeenCalledWith(expect.stringContaining('/apps/totem/totem/sala-sur'))
		expect(wrapper.text()).toContain('Dirección copiada.')
	})

	it('asks the admin to copy it by hand when the browser refuses', async () => {
		stubClipboard(vi.fn().mockRejectedValue(new Error('denied')))
		const wrapper = await mountView()
		await pick(wrapper, 'Sala sur')

		await button(wrapper, 'Copiar dirección').trigger('click')
		await flushPromises()

		expect(wrapper.find('[role="alert"]').text()).toBe('No se pudo copiar. Seleccione la dirección y cópiela.')
	})
})

describe('TotemsView deleting', () => {
	it('offers "Eliminar tótem" only for one that already exists, and asks inline first', async () => {
		const wrapper = await mountView()
		await openNew(wrapper)
		expect(button(wrapper, 'Eliminar tótem')).toBeUndefined()

		await pick(wrapper, 'Sala sur')
		await button(wrapper, 'Eliminar tótem').trigger('click')

		expect(wrapper.text()).toContain('¿Eliminar Sala sur?')
		expect(button(wrapper, 'Sí, eliminar')).toBeDefined()
		expect(admin.totems.remove).not.toHaveBeenCalled()
	})

	it('deletes on "Sí, eliminar", refreshes the list, clears the form and says so', async () => {
		admin.totems.remove.mockResolvedValue({})
		const wrapper = await mountView()
		await pick(wrapper, 'Sala sur')
		await button(wrapper, 'Eliminar tótem').trigger('click')
		admin.totems.list.mockClear()

		await button(wrapper, 'Sí, eliminar').trigger('click')
		await flushPromises()

		expect(admin.totems.remove).toHaveBeenCalledWith(5)
		expect(admin.totems.list).toHaveBeenCalledTimes(1)
		expect(wrapper.text()).toContain('Tótem eliminado.')
		expect(field(wrapper, 'Nombre').value).toBe('')
	})

	it('says the tótem no longer exists on a 404, and refreshes the list', async () => {
		admin.totems.remove.mockRejectedValue({ response: { status: 404 } })
		const wrapper = await mountView()
		await pick(wrapper, 'Sala sur')
		await button(wrapper, 'Eliminar tótem').trigger('click')
		admin.totems.list.mockClear()

		await button(wrapper, 'Sí, eliminar').trigger('click')
		await flushPromises()

		expect(wrapper.find('[role="alert"]').text()).toBe('Este tótem ya no existe.')
		expect(admin.totems.list).toHaveBeenCalledTimes(1)
	})
})
