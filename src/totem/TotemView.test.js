// SPDX-License-Identifier: AGPL-3.0-or-later
// @vitest-environment jsdom

import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { issueTurno, publicServicios } from '../api.js'
import TotemView from './TotemView.vue'
import source from './TotemView.vue?raw'

// Only the network boundary is replaced; the error-shape helpers stay real.
vi.mock('../api.js', async (importOriginal) => ({
	...(await importOriginal()),
	publicServicios: vi.fn(),
	issueTurno: vi.fn(),
}))

const FARMACIA = { id: 1, name: 'Farmacia', prefix: 'FAR', color: '#1b6ec2' }
const VACUNAS = { id: 2, name: 'Vacunatorio', prefix: 'VAC', color: '#f2b134' }

/** What the Nextcloud server puts in the page for loadState to read. */
function provideState(key, value) {
	const input = document.createElement('input')
	input.type = 'hidden'
	input.id = `initial-state-totem-${key}`
	input.value = window.btoa(JSON.stringify(value))
	document.body.append(input)
}

function provideEstablishment(name, { slug } = {}) {
	document.body.innerHTML = ''
	window._nc_initial_state = undefined
	if (name !== undefined) provideState('establishment', name)
	if (slug !== undefined) provideState('slug', slug)
}

async function mountTotem() {
	const wrapper = mount(TotemView, { attachTo: document.body })
	await flushPromises()
	return wrapper
}

const buttons = (wrapper) => wrapper.findAll('button').map((b) => b.text())
const button = (wrapper, text) => wrapper.findAll('button').find((b) => b.text() === text)
const pastDoubleTap = () => vi.advanceTimersByTimeAsync(500)

beforeEach(() => {
	vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout', 'setInterval', 'clearInterval', 'Date'] })
	vi.setSystemTime(new Date(2026, 9, 18, 14, 30, 0))
	vi.clearAllMocks()
	provideEstablishment(undefined)
	publicServicios.mockResolvedValue([FARMACIA, VACUNAS])
	window.print = vi.fn()
	vi.spyOn(console, 'warn').mockImplementation(() => {})
})

afterEach(() => {
	vi.useRealTimers()
	document.body.innerHTML = ''
})

describe('home screen', () => {
	it('offers one bar per servicio, with its name and prefix, under the question', async () => {
		const wrapper = await mountTotem()

		expect(wrapper.text()).toContain('Seleccione la unidad a la que acude.')
		expect(wrapper.find('.t-sub').exists()).toBe(false)
		expect(wrapper.text()).not.toContain('Toque')
		expect(buttons(wrapper)).toEqual(['FarmaciaFAR', 'VacunatorioVAC'])
	})

	it('paints each bar in its servicio color with a text color that can be read on it', async () => {
		const wrapper = await mountTotem()
		const [farmacia, vacunas] = wrapper.findAll('button')

		expect(farmacia.element.style.backgroundColor).toBe('rgb(27, 110, 194)')
		expect(farmacia.element.style.color).toBe('rgb(255, 255, 255)') // white on blue
		expect(vacunas.element.style.backgroundColor).toBe('rgb(242, 177, 52)')
		expect(vacunas.element.style.color).toBe('rgb(19, 32, 43)') // ink on gold
	})
})

describe('keeping the servicios fresh', () => {
	it('loads the servicios on mount and again every 60 seconds, showing the newest list', async () => {
		const wrapper = await mountTotem()
		expect(publicServicios).toHaveBeenCalledTimes(1)

		publicServicios.mockResolvedValue([VACUNAS])
		await vi.advanceTimersByTimeAsync(59999)
		expect(publicServicios).toHaveBeenCalledTimes(1)
		await vi.advanceTimersByTimeAsync(1)
		expect(publicServicios).toHaveBeenCalledTimes(2)
		expect(buttons(wrapper)).toEqual(['VacunatorioVAC'])
	})

	it('stops asking once the page is gone', async () => {
		const wrapper = await mountTotem()
		wrapper.unmount()

		await vi.advanceTimersByTimeAsync(180000)
		expect(publicServicios).toHaveBeenCalledTimes(1)
	})
})

describe('home footer', () => {
	it('is a plain div: Nextcloud\'s core CSS pins every <footer> element to the bottom of the window, out of the layout', async () => {
		const wrapper = await mountTotem()
		expect(wrapper.element.querySelector('footer')).toBeNull()
	})

	it('names the establishment the server provides', async () => {
		provideEstablishment('CESFAM Ejemplo')
		const wrapper = await mountTotem()

		expect(wrapper.find('.t-foot').text()).toContain('CESFAM Ejemplo')
	})

	it('falls back to "CESFAM" when the server provides no name', async () => {
		const wrapper = await mountTotem()

		expect(wrapper.find('.t-foot').text()).toContain('CESFAM')
	})

	it('shows a clock that follows the time of day', async () => {
		const wrapper = await mountTotem()
		expect(wrapper.find('.t-foot').text()).toContain('14:30')

		await vi.advanceTimersByTimeAsync(60000)
		expect(wrapper.find('.t-foot').text()).toContain('14:31')
	})
})

describe('paging many servicios', () => {
	const many = (n) => Array.from({ length: n }, (_, i) => ({ id: i + 1, name: `Servicio ${i + 1}`, prefix: `S${String(i + 1).padStart(2, '0')}`, color: '#1b6ec2' }))
	const names = (wrapper) => wrapper.findAll('button').map((b) => b.text()).filter((t) => t.startsWith('Servicio'))

	it('shows up to seven servicios and no pager when they fit', async () => {
		publicServicios.mockResolvedValue(many(7))
		const wrapper = await mountTotem()

		expect(names(wrapper)).toHaveLength(7)
		expect(button(wrapper, 'Siguiente')).toBeUndefined()
		expect(button(wrapper, 'Anterior')).toBeUndefined()
	})

	it('pages by seven with Anterior and Siguiente', async () => {
		publicServicios.mockResolvedValue(many(9))
		const wrapper = await mountTotem()

		expect(names(wrapper)).toHaveLength(7)
		expect(button(wrapper, 'Anterior').attributes('disabled')).toBeDefined()
		await button(wrapper, 'Siguiente').trigger('click')

		expect(names(wrapper)).toEqual(['Servicio 8S08', 'Servicio 9S09'])
		expect(button(wrapper, 'Siguiente').attributes('disabled')).toBeDefined()
		await button(wrapper, 'Anterior').trigger('click')
		expect(names(wrapper)).toHaveLength(7)
		expect(names(wrapper)[0]).toBe('Servicio 1S01')
	})

	it('never shows an empty page when the list shrinks under the patient', async () => {
		publicServicios.mockResolvedValue(many(9))
		const wrapper = await mountTotem()
		await button(wrapper, 'Siguiente').trigger('click')

		publicServicios.mockResolvedValue(many(3))
		await vi.advanceTimersByTimeAsync(60000)

		expect(names(wrapper)).toHaveLength(3)
	})
})

describe('question screen', () => {
	async function openFarmacia() {
		const wrapper = await mountTotem()
		await button(wrapper, 'FarmaciaFAR').trigger('click')
		return wrapper
	}

	it('asks the one preferente question under the servicio band', async () => {
		const wrapper = await openFarmacia()

		expect(wrapper.text()).toContain('Farmacia')
		expect(wrapper.text()).toContain('¿Tiene 60 años o más, una discapacidad o cuida a una persona con discapacidad?')
		expect(buttons(wrapper)).toEqual(['‹ Volver', 'Sí', 'No'])
		expect(wrapper.text()).not.toContain('Seleccione la unidad a la que acude.')
		expect(issueTurno).not.toHaveBeenCalled()
	})

	it('colors the band with the servicio color and a readable text color', async () => {
		const wrapper = await openFarmacia()
		const band = wrapper.find('header')

		expect(band.element.style.backgroundColor).toBe('rgb(27, 110, 194)')
		expect(band.element.style.color).toBe('rgb(255, 255, 255)')
	})

	it('goes back to the home screen with "‹ Volver"', async () => {
		const wrapper = await openFarmacia()
		await button(wrapper, '‹ Volver').trigger('click')

		expect(wrapper.text()).toContain('Seleccione la unidad a la que acude.')
		expect(issueTurno).not.toHaveBeenCalled()
	})
})

describe('a double tap on a servicio bar', () => {
	it.each(['Sí', 'No'])('ignores "%s" tapped before the double-tap window ends', async (choice) => {
		issueTurno.mockResolvedValue({ id: 41, label: 'FAR-G-007', servicio: 'Farmacia', preferente: false })
		const wrapper = await mountTotem()
		await button(wrapper, 'FarmaciaFAR').trigger('click')
		await button(wrapper, choice).trigger('click')
		await flushPromises()

		expect(issueTurno).not.toHaveBeenCalled()
		expect(wrapper.text()).toContain('¿Tiene 60 años o más')
		expect(buttons(wrapper)).toEqual(['‹ Volver', 'Sí', 'No'])
		expect(button(wrapper, 'Sí').attributes('disabled')).toBeUndefined()
		expect(button(wrapper, 'No').attributes('disabled')).toBeUndefined()
	})

	it('answers on a tap after the window, even after a tap inside it was ignored', async () => {
		issueTurno.mockResolvedValue({ id: 41, label: 'FAR-P-014', servicio: 'Farmacia', preferente: true })
		const wrapper = await mountTotem()
		await button(wrapper, 'FarmaciaFAR').trigger('click')
		await vi.advanceTimersByTimeAsync(499)
		await button(wrapper, 'Sí').trigger('click')
		await flushPromises()
		expect(issueTurno).not.toHaveBeenCalled()

		await vi.advanceTimersByTimeAsync(1)
		await button(wrapper, 'Sí').trigger('click')
		await flushPromises()

		expect(issueTurno).toHaveBeenCalledTimes(1)
		expect(issueTurno).toHaveBeenCalledWith(1, true, null)
		expect(wrapper.find('main').text()).toContain('FAR-P-014')
	})

	it('starts the window again for a servicio chosen after "‹ Volver"', async () => {
		issueTurno.mockResolvedValue({ id: 42, label: 'VAC-P-001', servicio: 'Vacunatorio', preferente: true })
		const wrapper = await mountTotem()
		await button(wrapper, 'FarmaciaFAR').trigger('click')
		await pastDoubleTap()
		await button(wrapper, '‹ Volver').trigger('click')
		await button(wrapper, 'VacunatorioVAC').trigger('click')
		await button(wrapper, 'Sí').trigger('click')
		await flushPromises()
		expect(issueTurno).not.toHaveBeenCalled()

		await pastDoubleTap()
		await button(wrapper, 'Sí').trigger('click')
		await flushPromises()

		expect(issueTurno).toHaveBeenCalledWith(2, true, null)
	})
})

describe('issuing a turno', () => {
	const issued = (label, preferente) => ({ id: 41, label, servicio: 'Farmacia', preferente })

	async function answer(choice) {
		const wrapper = await mountTotem()
		await button(wrapper, 'FarmaciaFAR').trigger('click')
		await pastDoubleTap()
		await button(wrapper, choice).trigger('click')
		await flushPromises()
		return wrapper
	}

	it('issues a preferente turno on "Sí" and shows the ticket screen', async () => {
		issueTurno.mockResolvedValue(issued('FAR-P-014', true))
		const wrapper = await answer('Sí')
		const screen = wrapper.find('main')

		expect(issueTurno).toHaveBeenCalledTimes(1)
		expect(issueTurno).toHaveBeenCalledWith(1, true, null)
		expect(screen.text()).toContain('Su turno')
		expect(screen.text()).toContain('FAR-P-014')
		expect(screen.text()).toContain('Farmacia')
		expect(screen.text()).toContain('Atención preferente')
		expect(screen.text()).toContain('Retire su ticket impreso y espere su llamado en la sala de espera.')
		expect(screen.text()).toContain('Volviendo al inicio en 8 s')
	})

	it('still shows and prints the ticket when the patient left the question while it was being issued', async () => {
		let arrive
		issueTurno.mockReturnValue(new Promise((resolve) => (arrive = resolve)))
		const wrapper = await mountTotem()
		await button(wrapper, 'FarmaciaFAR').trigger('click')
		await pastDoubleTap()
		await button(wrapper, 'No').trigger('click')
		await button(wrapper, '‹ Volver').trigger('click')
		arrive(issued('FAR-G-007', false))
		await flushPromises()

		expect(wrapper.find('main').text()).toContain('FAR-G-007')
		expect(window.print).toHaveBeenCalledTimes(1)
	})

	it('issues a general turno on "No", without the preferente chip', async () => {
		issueTurno.mockResolvedValue(issued('FAR-G-007', false))
		const wrapper = await answer('No')
		const screen = wrapper.find('main')

		expect(issueTurno).toHaveBeenCalledWith(1, false, null)
		expect(screen.text()).toContain('FAR-G-007')
		expect(screen.text()).not.toContain('Atención preferente')
	})

	it('colors the ticket screen in the servicio color', async () => {
		issueTurno.mockResolvedValue(issued('FAR-G-007', false))
		const wrapper = await answer('No')

		expect(wrapper.find('main').element.style.backgroundColor).toBe('rgb(27, 110, 194)')
		expect(wrapper.find('main').element.style.color).toBe('rgb(255, 255, 255)')
	})

	it('counts down from 8 and returns home', async () => {
		issueTurno.mockResolvedValue(issued('FAR-G-007', false))
		const wrapper = await answer('No')

		await vi.advanceTimersByTimeAsync(1000)
		expect(wrapper.text()).toContain('Volviendo al inicio en 7 s')
		await vi.advanceTimersByTimeAsync(6000)
		expect(wrapper.text()).toContain('Volviendo al inicio en 1 s')
		await vi.advanceTimersByTimeAsync(1000)

		expect(wrapper.text()).toContain('Seleccione la unidad a la que acude.')
		expect(wrapper.text()).not.toContain('FAR-G-007')
	})

	it('takes one turno even when the choice is tapped twice before the answer arrives', async () => {
		let arrive
		issueTurno.mockReturnValue(new Promise((resolve) => (arrive = resolve)))
		const wrapper = await mountTotem()
		await button(wrapper, 'FarmaciaFAR').trigger('click')
		await pastDoubleTap()
		await button(wrapper, 'No').trigger('click')
		await button(wrapper, 'No').trigger('click')
		await button(wrapper, 'Sí').trigger('click')
		arrive(issued('FAR-G-007', false))
		await flushPromises()

		expect(issueTurno).toHaveBeenCalledTimes(1)
		expect(wrapper.find('main').text()).toContain('FAR-G-007')
	})
})

describe('a totem with its own address', () => {
	it('asks for the unidades of its own totem and says which totem the ticket is taken at', async () => {
		provideEstablishment('CESFAM Ejemplo', { slug: 'sala-sur' })
		issueTurno.mockResolvedValue({ id: 41, label: 'FAR-G-001', servicio: 'Farmacia', preferente: false, printed: true })
		const wrapper = await mountTotem()

		expect(publicServicios).toHaveBeenCalledWith('sala-sur')
		await button(wrapper, 'FarmaciaFAR').trigger('click')
		await pastDoubleTap()
		await button(wrapper, 'No').trigger('click')
		await flushPromises()

		expect(issueTurno).toHaveBeenCalledWith(1, false, 'sala-sur')
	})

	it('is the default kiosk when the page names no totem', async () => {
		await mountTotem()

		expect(publicServicios).toHaveBeenCalledWith(null)
	})
})

describe('printing the ticket', () => {
	async function issue(result, choice) {
		issueTurno.mockResolvedValue(result)
		provideEstablishment('CESFAM Ejemplo')
		const wrapper = await mountTotem()
		await button(wrapper, 'FarmaciaFAR').trigger('click')
		expect(window.print).not.toHaveBeenCalled()
		await pastDoubleTap()
		await button(wrapper, choice).trigger('click')
		await flushPromises()
		return wrapper
	}

	it('asks the browser to print once the turno is issued', async () => {
		await issue({ id: 41, label: 'FAR-P-014', servicio: 'Farmacia', preferente: true }, 'Sí')

		expect(window.print).toHaveBeenCalledTimes(1)
	})

	it('prints through the browser when the server has no network printer for this totem (printed: null)', async () => {
		await issue({ id: 41, label: 'FAR-P-014', servicio: 'Farmacia', preferente: true, printed: null }, 'Sí')

		expect(window.print).toHaveBeenCalledTimes(1)
	})

	it('does not print through the browser when the server printed the ticket, and says to take it', async () => {
		const wrapper = await issue({ id: 41, label: 'FAR-P-014', servicio: 'Farmacia', preferente: true, printed: true }, 'Sí')

		expect(window.print).not.toHaveBeenCalled()
		expect(wrapper.find('.paper').exists()).toBe(false)
		expect(wrapper.find('.t-ticket').text()).toContain('Retire su ticket impreso y espere su llamado en la sala de espera.')
		expect(wrapper.find('.t-ticket .lbl').text()).toBe('Su turno')
	})

	it('when the printer did not answer, shows the number and sends the patient to SOME, without printing', async () => {
		const wrapper = await issue({ id: 41, label: 'FAR-P-014', servicio: 'Farmacia', preferente: true, printed: false }, 'Sí')
		const screen = wrapper.find('.t-ticket')

		expect(window.print).not.toHaveBeenCalled()
		expect(wrapper.find('.paper').exists()).toBe(false)
		expect(screen.find('.num').text()).toBe('FAR-P-014')
		expect(screen.text()).toContain('No se pudo imprimir')
		expect(screen.find('.inst').text()).toBe('Acérquese a SOME.')
		expect(screen.find('.lbl').exists()).toBe(false)
		expect(screen.text()).not.toContain('Retire su ticket')
	})

	it('still goes back to the start after the countdown when the printer did not answer', async () => {
		const wrapper = await issue({ id: 41, label: 'FAR-P-014', servicio: 'Farmacia', preferente: false, printed: false }, 'No')

		await vi.advanceTimersByTimeAsync(8000)

		expect(wrapper.text()).toContain('Seleccione la unidad a la que acude.')
	})

	it('prepares the paper ticket with the establishment, the label, the servicio, the date and the instruction', async () => {
		const wrapper = await issue({ id: 41, label: 'FAR-P-014', servicio: 'Farmacia', preferente: true }, 'Sí')
		const paper = wrapper.find('.paper')

		expect(paper.text()).toContain('CESFAM Ejemplo')
		expect(paper.text()).toContain('FAR-P-014')
		expect(paper.text()).toContain('Farmacia')
		expect(paper.text()).toContain('ATENCIÓN PREFERENTE')
		expect(paper.text()).toContain('18-10-2026 · 14:30')
		expect(paper.text()).toContain('Espere su llamado en la sala de espera. Mire la pantalla y escuche el aviso.')
	})

	it('leaves the preferente box off a general ticket', async () => {
		const wrapper = await issue({ id: 42, label: 'FAR-G-007', servicio: 'Farmacia', preferente: false }, 'No')

		expect(wrapper.find('.paper').text()).toContain('FAR-G-007')
		expect(wrapper.find('.paper').text()).not.toContain('ATENCIÓN PREFERENTE')
	})
})

describe('when the server refuses the turno', () => {
	const refusal = (status, message) => Object.assign(new Error('Request failed'), { response: { status, data: message ? { message } : {} } })

	async function refused(error) {
		issueTurno.mockRejectedValue(error)
		const wrapper = await mountTotem()
		await button(wrapper, 'FarmaciaFAR').trigger('click')
		await pastDoubleTap()
		await button(wrapper, 'No').trigger('click')
		await flushPromises()
		return wrapper
	}

	it('shows the server sentence in an alert on the question screen, with a way home', async () => {
		const wrapper = await refused(refusal(422, 'Hoy ya no quedan turnos para Farmacia.'))
		const alert = wrapper.find('[role="alert"]')

		expect(alert.text()).toContain('Hoy ya no quedan turnos para Farmacia.')
		expect(wrapper.text()).toContain('Farmacia') // still under the servicio band
		expect(window.print).not.toHaveBeenCalled()
		expect(wrapper.text()).not.toContain('Sin conexión')

		await button(wrapper, 'Volver al inicio').trigger('click')
		expect(wrapper.text()).toContain('Seleccione la unidad a la que acude.')
		expect(wrapper.find('[role="alert"]').exists()).toBe(false)
	})

	it('says that the servicio no longer exists when the server does (404)', async () => {
		const wrapper = await refused(refusal(404, 'Esa unidad ya no existe.'))

		expect(wrapper.find('[role="alert"]').text()).toContain('Esa unidad ya no existe.')
	})

	it('still says something when the server gave no sentence', async () => {
		const wrapper = await refused(refusal(500))

		expect(wrapper.find('[role="alert"]').text()).toContain('No pudimos entregar su turno. Intente de nuevo.')
	})

	it('does not show a late refusal to the next patient', async () => {
		let fail
		issueTurno.mockReturnValue(new Promise((resolve, reject) => (fail = reject)))
		const wrapper = await mountTotem()
		await button(wrapper, 'FarmaciaFAR').trigger('click')
		await pastDoubleTap()
		await button(wrapper, 'No').trigger('click')
		await button(wrapper, '‹ Volver').trigger('click')
		fail(refusal(422, 'Hoy ya no quedan turnos para Farmacia.'))
		await flushPromises()
		await button(wrapper, 'VacunatorioVAC').trigger('click')

		expect(wrapper.find('[role="alert"]').exists()).toBe(false)
	})

	it('does not carry the error into the next patient', async () => {
		const wrapper = await refused(refusal(422, 'Hoy ya no quedan turnos para Farmacia.'))
		await button(wrapper, 'Volver al inicio').trigger('click')
		await button(wrapper, 'FarmaciaFAR').trigger('click')

		expect(wrapper.find('[role="alert"]').exists()).toBe(false)
		expect(button(wrapper, 'No').attributes('disabled')).toBeUndefined()
	})
})

describe('when the network is down', () => {
	const noAnswer = () => new Error('Network Error') // an axios network failure has no `response`
	const overlay = (wrapper) => wrapper.find('[role="alert"]')

	it('covers the screen with the offline notice when the servicios cannot be loaded', async () => {
		publicServicios.mockRejectedValue(noAnswer())
		const wrapper = await mountTotem()

		expect(overlay(wrapper).text()).toContain('Sin conexión')
		expect(overlay(wrapper).text()).toContain('Acérquese a SOME.')
		expect(overlay(wrapper).text()).toContain('Reintentando…')
	})

	it('keeps retrying and clears the notice as soon as the servicios load again', async () => {
		publicServicios.mockRejectedValue(noAnswer())
		const wrapper = await mountTotem()
		expect(overlay(wrapper).exists()).toBe(true)

		publicServicios.mockResolvedValue([VACUNAS])
		await vi.advanceTimersByTimeAsync(30000)

		expect(overlay(wrapper).exists()).toBe(false)
		expect(buttons(wrapper)).toEqual(['VacunatorioVAC'])
	})

	it('shows the notice, not a refusal, when taking the turno fails without an answer', async () => {
		const wrapper = await mountTotem()
		await button(wrapper, 'FarmaciaFAR').trigger('click')
		issueTurno.mockRejectedValue(noAnswer())
		publicServicios.mockRejectedValue(noAnswer()) // the server really is unreachable
		await pastDoubleTap()
		await button(wrapper, 'No').trigger('click')
		await flushPromises()

		expect(overlay(wrapper).text()).toContain('Sin conexión')
		expect(wrapper.text()).not.toContain('No pudimos entregar su turno')
		expect(window.print).not.toHaveBeenCalled()
	})

	it('checks again at once after such a failure, and clears the notice if the server is back', async () => {
		const wrapper = await mountTotem()
		await button(wrapper, 'FarmaciaFAR').trigger('click')
		issueTurno.mockRejectedValue(noAnswer())
		await pastDoubleTap()
		await button(wrapper, 'No').trigger('click')
		await flushPromises()
		expect(publicServicios).toHaveBeenCalledTimes(2)

		expect(overlay(wrapper).exists()).toBe(false)
	})
})

describe('a patient who walks away', () => {
	async function openFarmacia() {
		const wrapper = await mountTotem()
		await button(wrapper, 'FarmaciaFAR').trigger('click')
		return wrapper
	}

	it('returns to the home screen after 30 seconds without a tap on the question', async () => {
		const wrapper = await openFarmacia()

		await vi.advanceTimersByTimeAsync(29999)
		expect(wrapper.text()).toContain('¿Tiene 60 años o más')
		await vi.advanceTimersByTimeAsync(1)

		expect(wrapper.text()).toContain('Seleccione la unidad a la que acude.')
		expect(issueTurno).not.toHaveBeenCalled()
	})

	it('waits another 30 seconds after each tap on the screen', async () => {
		const wrapper = await openFarmacia()

		await vi.advanceTimersByTimeAsync(20000)
		await wrapper.find('header').trigger('pointerdown')
		await vi.advanceTimersByTimeAsync(20000)
		expect(wrapper.text()).toContain('¿Tiene 60 años o más')
		await vi.advanceTimersByTimeAsync(10000)

		expect(wrapper.text()).toContain('Seleccione la unidad a la que acude.')
	})

	it('does not cut the ticket screen short when the answer was slow to arrive', async () => {
		let arrive
		issueTurno.mockReturnValue(new Promise((resolve) => (arrive = resolve)))
		const wrapper = await openFarmacia()
		await pastDoubleTap()
		await button(wrapper, 'No').trigger('pointerdown')
		await button(wrapper, 'No').trigger('click')
		await vi.advanceTimersByTimeAsync(28000)
		arrive({ id: 43, label: 'FAR-G-008', servicio: 'Farmacia', preferente: false })
		await flushPromises()

		await vi.advanceTimersByTimeAsync(5000) // 33 s after the tap
		expect(wrapper.find('main').text()).toContain('Volviendo al inicio en 3 s')
	})

	it('does not pull the next patient back home with the previous one\'s wait', async () => {
		const wrapper = await openFarmacia()
		await vi.advanceTimersByTimeAsync(25000)
		await button(wrapper, '‹ Volver').trigger('click')
		await button(wrapper, 'VacunatorioVAC').trigger('click')

		await vi.advanceTimersByTimeAsync(10000) // 35 s after the first patient arrived, 10 s after this one
		expect(wrapper.text()).toContain('¿Tiene 60 años o más')
	})
})

// jsdom lays nothing out, so the stylesheet's contract is read from the source itself.
describe('stylesheet contract', () => {
	const css = source.slice(source.indexOf('<style'))
	const flat = css.replace(/\s+/g, ' ')

	it('prints only the 80 mm paper ticket', () => {
		expect(flat).toContain('@page { size: 80mm auto; margin: 0; }')
		const print = flat.slice(flat.indexOf('@media print'))
		expect(print).toMatch(/\.t-root \{ display: none;/)
		expect(print).toMatch(/\.paper \{ display: flex;/)
	})

	it('sizes the screens from one fitted unit inside a container, switching to landscape by aspect ratio', () => {
		expect(flat).toContain('container: scr / size')
		expect(flat).toContain('.t-page .t-root { position: fixed; inset: 0;') // over Nextcloud's own padded container
		expect(flat).toContain('--k: min(100cqw / 1080, 100cqh / 1920)')
		expect(flat).toContain('@container scr (aspect-ratio > 1)')
		expect(flat).toContain('--k: min(100cqw / 1920, 100cqh / 1080)')
	})

	it('puts every screen rule under .t-page, so Nextcloud\'s `button:not(.button-vue)` reset (0,1,1) cannot win', () => {
		const screenCss = css.slice(0, css.indexOf('/* The paper ticket')).replace(/\/\*[\s\S]*?\*\//g, '')
		const selectors = [...screenCss.matchAll(/([^{}]+)\{/g)].map((m) => m[1].trim()).filter((sel) => !sel.startsWith('@') && !sel.startsWith('<style') && sel !== '')
		const loose = selectors.flatMap((list) => list.split(',')).map((sel) => sel.trim()).filter((sel) => /\.(t-|totem|screen)/.test(sel) && !sel.startsWith('.t-page'))
		expect(loose).toEqual([])
	})

	it('has no fixed-pixel sizes', () => {
		expect(flat).not.toMatch(/\d\s?px/)
	})
})
