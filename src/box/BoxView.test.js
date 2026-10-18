// @vitest-environment jsdom
// SPDX-License-Identifier: AGPL-3.0-or-later
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import BoxView from './BoxView.vue'
import { box } from '../api.js'

// Only the network boundary is replaced; messageFrom stays real.
vi.mock('../api.js', async (importOriginal) => ({
	...(await importOriginal()),
	box: { boxes: vi.fn(), state: vi.fn(), act: vi.fn() },
	warnError: vi.fn(),
}))

// What GET /console/boxes returns: ids only. The servicio names arrive with each box's state.
const BOXES = [
	{ id: 3, name: 'Box 3', servicioIds: [1, 2] },
	{ id: 4, name: 'Box 4', servicioIds: [5] },
]
// What GET /console/{id}/state returns for `box`.
const STATE_BOXES = [
	{ id: 3, name: 'Box 3', servicios: ['Farmacia', 'Toma de Muestras'] },
	{ id: 4, name: 'Box 4', servicios: ['Vacunatorio'] },
]

let wrapper

/** Let pending promises and zero-delay timers settle. */
const settle = () => vi.advanceTimersByTimeAsync(0)

beforeEach(() => {
	// Nextcloud's page shell provides this; NcContent teleports its skip links into it.
	document.body.innerHTML = '<div id="skip-actions"></div>'
	vi.useFakeTimers()
	vi.setSystemTime(new Date('2026-03-02T10:00:00Z'))
	localStorage.clear()
	box.boxes.mockReset().mockResolvedValue(BOXES)
	box.state.mockReset()
	box.act.mockReset()
})

afterEach(() => {
	wrapper?.unmount()
	wrapper = undefined
	vi.useRealTimers()
})

async function open() {
	wrapper = mount(BoxView, { attachTo: document.body })
	await settle()
	return wrapper
}

describe('the box picker', () => {
	it('offers the boxes in a labeled select with a placeholder', async () => {
		await open()
		const select = wrapper.find('select')
		expect(wrapper.find('label[for="' + select.attributes('id') + '"]').text()).toBe('Box')
		const options = select.findAll('option').map((o) => o.text())
		expect(options).toEqual(['Elija su box', 'Box 3', 'Box 4'])
	})
})

/** A server state with nothing held, overridable per test. */
const idle = (extra = {}) => ({
	box: STATE_BOXES[0],
	current: null,
	waiting: [],
	next: null,
	why: 'No hay turnos en espera en los servicios de este box.',
	...extra,
})

async function choose(label = 'Box 3') {
	await open()
	const select = wrapper.find('select')
	await select.findAll('option').find((o) => o.text() === label).setSelected()
	await settle()
}

describe('choosing a box', () => {
	it('shows the header with the box and its servicios, reading its state', async () => {
		box.state.mockResolvedValue(idle())
		await choose()
		expect(box.state).toHaveBeenCalledWith(3)
		expect(wrapper.text()).toContain('Box 3 · Farmacia, Toma de Muestras')
		expect(wrapper.find('select').exists()).toBe(false)
	})

	it('shows the box name at once and adds its servicios when the state arrives (the list carries ids only)', async () => {
		let answer
		box.state.mockReturnValue(new Promise((resolve) => { answer = resolve }))
		await choose()
		expect(wrapper.text()).toContain('Box 3')
		expect(wrapper.text()).not.toContain('Farmacia')

		answer(idle())
		await settle()
		expect(wrapper.text()).toContain('Box 3 · Farmacia, Toma de Muestras')
	})

	it('remembers the choice in localStorage', async () => {
		box.state.mockResolvedValue(idle())
		await choose()
		expect(localStorage.getItem('totem.box')).toBe('3')
	})
})

describe('restoring the remembered box', () => {
	it('opens straight on the stored box when it still exists', async () => {
		localStorage.setItem('totem.box', '4')
		box.state.mockResolvedValue(idle({ box: STATE_BOXES[1] }))
		await open()
		expect(box.state).toHaveBeenCalledWith(4)
		expect(wrapper.text()).toContain('Box 4 · Vacunatorio')
	})

	it('falls back to the picker when the stored box is gone', async () => {
		localStorage.setItem('totem.box', '99')
		await open()
		expect(box.state).not.toHaveBeenCalled()
		expect(wrapper.find('select').exists()).toBe(true)
	})

	it('works when storage throws', async () => {
		// Only this page's key is blocked: Nextcloud's own components keep their storage.
		const blocking = (original) => function (key, ...rest) {
			if (key === 'totem.box') throw new Error('blocked')
			return original.call(this, key, ...rest)
		}
		const realGet = window.Storage.prototype.getItem
		const realSet = window.Storage.prototype.setItem
		const get = vi.spyOn(window.Storage.prototype, 'getItem').mockImplementation(blocking(realGet))
		const set = vi.spyOn(window.Storage.prototype, 'setItem').mockImplementation(blocking(realSet))
		try {
			box.state.mockResolvedValue(idle())
			await choose()
			expect(wrapper.text()).toContain('Box 3 · Farmacia, Toma de Muestras')
		} finally {
			get.mockRestore()
			set.mockRestore()
		}
	})
})

const button = (name) => wrapper.findAll('button').find((b) => b.text() === name)

describe('Cambiar box', () => {
	it('returns to the picker and stops reading the state', async () => {
		box.state.mockResolvedValue(idle())
		await choose()
		await button('Cambiar box').trigger('click')
		expect(wrapper.find('select').exists()).toBe(true)
		const calls = box.state.mock.calls.length
		await vi.advanceTimersByTimeAsync(9000)
		expect(box.state).toHaveBeenCalledTimes(calls)
	})
})

const unixNow = () => Math.floor(Date.now() / 1000)
const called = (extra = {}) => ({
	id: 41,
	label: 'FAR-P-014',
	servicio: 'Farmacia',
	color: '#1e88e5',
	preferente: false,
	estado: 'called',
	since: unixNow() - 5,
	...extra,
})
/** The text a person reads, whitespace collapsed. */
const seen = () => wrapper.text().replace(/\s+/g, ' ')

describe('the current turno', () => {
	it('shows its label, servicio and a Llamado clock that keeps counting', async () => {
		box.state.mockResolvedValue(idle({ current: called() }))
		await choose()
		expect(seen()).toContain('FAR-P-014')
		expect(seen()).toContain('Farmacia · Llamado 00:05')
		await vi.advanceTimersByTimeAsync(60000)
		expect(seen()).toContain('Llamado 01:05')
	})

	it('marks the atención preferente and counts En atención once in service', async () => {
		box.state.mockResolvedValue(idle({ current: called({ preferente: true, estado: 'in_service', since: unixNow() - 123 }) }))
		await choose()
		expect(seen()).toContain('Farmacia · Atención preferente · En atención 02:03')
	})

	it('says so when nothing is held and explains who is next', async () => {
		box.state.mockResolvedValue(idle({ why: 'Siguiente: FAR-P-015 (Farmacia): toca atención preferente.' }))
		await choose()
		expect(seen()).toContain('Sin turno en atención.')
		expect(wrapper.find('[role="status"]').text()).toBe('Siguiente: FAR-P-015 (Farmacia): toca atención preferente.')
	})
})

describe('before the first state arrives', () => {
	it('claims nothing about the turno', async () => {
		box.state.mockReturnValue(new Promise(() => {}))
		await choose()
		expect(seen()).not.toContain('Sin turno en atención.')
		expect(seen()).toContain('Box 3')
	})
})

const NEXT = { id: 42, label: 'FAR-P-015', servicio: 'Farmacia', color: '#1e88e5', preferente: true }
const isDisabled = (name) => button(name).attributes('disabled') !== undefined

describe('the buttons follow the state', () => {
	it('offers Llamar siguiente when idle with someone waiting', async () => {
		box.state.mockResolvedValue(idle({ next: NEXT, waiting: [{ ...NEXT, waitedMinutes: 3 }] }))
		await choose()
		expect(isDisabled('Llamar siguiente')).toBe(false)
		expect(isDisabled('Llamar de nuevo')).toBe(true)
		expect(isDisabled('No se presentó')).toBe(true)
	})

	it('turns the primary into a disabled No hay turnos en espera when the queue is empty', async () => {
		box.state.mockResolvedValue(idle())
		await choose()
		expect(button('Llamar siguiente')).toBeUndefined()
		expect(isDisabled('No hay turnos en espera')).toBe(true)
	})

	it('offers Iniciar atención and both secondaries once called', async () => {
		box.state.mockResolvedValue(idle({ current: called() }))
		await choose()
		expect(isDisabled('Iniciar atención')).toBe(false)
		expect(isDisabled('Llamar de nuevo')).toBe(false)
		expect(isDisabled('No se presentó')).toBe(false)
	})

	it('offers Finalizar atención, with the secondaries off, while in service', async () => {
		box.state.mockResolvedValue(idle({ current: called({ estado: 'in_service' }) }))
		await choose()
		expect(isDisabled('Finalizar atención')).toBe(false)
		expect(isDisabled('Llamar de nuevo')).toBe(true)
		expect(isDisabled('No se presentó')).toBe(true)
		expect(button('Finalizar atención').classes()).toContain('button-vue--success')
	})
})

describe('pressing a button', () => {
	it.each([
		['Llamar siguiente', 'call', idle({ next: NEXT }), idle({ current: called({ label: 'FAR-P-015' }), next: null })],
		['Iniciar atención', 'start', idle({ current: called() }), idle({ current: called({ estado: 'in_service' }) })],
		['Finalizar atención', 'finish', idle({ current: called({ estado: 'in_service' }) }), idle({ why: 'Nadie más espera.' })],
		['Llamar de nuevo', 'recall', idle({ current: called() }), idle({ current: called({ since: unixNow() }) })],
		['No se presentó', 'absent', idle({ current: called() }), idle({ why: 'Ausente registrado.' })],
	])('%s asks the server for %s and shows the answer', async (label, action, before, after) => {
		box.state.mockResolvedValue(before)
		box.act.mockResolvedValue(after)
		await choose()
		await button(label).trigger('click')
		await settle()
		expect(box.act).toHaveBeenCalledWith(3, action)
		const shown = after.current ? after.current.label : after.why
		expect(seen()).toContain(shown)
	})

	it('disables every button while a request is in flight', async () => {
		let answer
		box.state.mockResolvedValue(idle({ current: called() }))
		box.act.mockReturnValue(new Promise((resolve) => { answer = resolve }))
		await choose()
		await button('Iniciar atención').trigger('click')
		expect(isDisabled('Iniciar atención')).toBe(true)
		expect(isDisabled('Llamar de nuevo')).toBe(true)
		expect(isDisabled('No se presentó')).toBe(true)
		await button('Iniciar atención').trigger('click')
		expect(box.act).toHaveBeenCalledTimes(1)
		answer(idle({ current: called({ estado: 'in_service' }) }))
		await settle()
		expect(isDisabled('Finalizar atención')).toBe(false)
	})
})

const refusal = (message) => Object.assign(new Error('Request failed'), { response: { status: 422, data: { message } } })

describe('a refusal', () => {
	it('shows the server sentence as an alert and re-reads the state', async () => {
		box.state.mockResolvedValueOnce(idle({ next: NEXT }))
		box.act.mockRejectedValue(refusal('Ese turno ya fue llamado por otro box.'))
		await choose()
		box.state.mockResolvedValue(idle({ why: 'Otro box se llevó el turno.' }))
		await button('Llamar siguiente').trigger('click')
		await settle()
		expect(wrapper.find('[role="alert"]').text()).toBe('Ese turno ya fue llamado por otro box.')
		expect(box.state).toHaveBeenCalledTimes(2)
		expect(seen()).toContain('Otro box se llevó el turno.')
	})

	it('clears the alert on the next successful press', async () => {
		box.state.mockResolvedValue(idle({ next: NEXT }))
		box.act.mockRejectedValueOnce(refusal('Ya no hay turnos.'))
		await choose()
		await button('Llamar siguiente').trigger('click')
		await settle()
		expect(wrapper.find('[role="alert"]').exists()).toBe(true)
		box.act.mockResolvedValue(idle({ current: called() }))
		await button('Llamar siguiente').trigger('click')
		await settle()
		expect(wrapper.find('[role="alert"]').exists()).toBe(false)
	})

	it('says something readable when the server cannot be reached at all', async () => {
		box.state.mockResolvedValue(idle({ next: NEXT }))
		box.act.mockRejectedValue(new Error('Network Error'))
		await choose()
		await button('Llamar siguiente').trigger('click')
		await settle()
		expect(wrapper.find('[role="alert"]').text()).toBe('No se pudo completar la acción. Intente de nuevo.')
		expect(isDisabled('Llamar siguiente')).toBe(false)
	})
})

const row = (id, label, preferente, waitedMinutes, color = '#1e88e5') => ({ id, label, servicio: 'Farmacia', color, preferente, waitedMinutes })
const QUEUE = [row(42, 'FAR-P-015', true, 12), row(43, 'MUE-G-007', false, 7, '#e53935'), row(44, 'FAR-G-020', false, 0)]
/** Each row's cells read left to right (flex cells are separate elements, not a sentence). */
const rows = () => wrapper.findAll('li').map((li) => [...li.element.children].map((cell) => cell.textContent.trim()).filter(Boolean).join(' '))

describe('the waiting list', () => {
	it('counts and lists the turnos oldest first, with their kind and minutes waited', async () => {
		box.state.mockResolvedValue(idle({ next: QUEUE[0], waiting: QUEUE }))
		await choose()
		expect(seen()).toContain('En espera (3)')
		expect(rows()).toEqual([
			'P FAR-P-015 SIGUIENTE 12 min',
			'G MUE-G-007 7 min',
			'G FAR-G-020 0 min',
		])
	})

	it('tells preferente from general by the marker and keeps the servicio color', async () => {
		box.state.mockResolvedValue(idle({ next: QUEUE[0], waiting: QUEUE }))
		await choose()
		const items = wrapper.findAll('li')
		expect(items[0].find('[data-kind="P"]').exists()).toBe(true)
		expect(items[1].find('[data-kind="G"]').exists()).toBe(true)
		expect(items[1].find('.totem-box__dot').attributes('style')).toContain('background: rgb(229, 57, 53)')
	})

	it('drops SIGUIENTE while a turno is held', async () => {
		box.state.mockResolvedValue(idle({ current: called(), next: QUEUE[0], waiting: QUEUE }))
		await choose()
		expect(seen()).not.toContain('SIGUIENTE')
	})

	it('explains the P and G markers', async () => {
		box.state.mockResolvedValue(idle({ next: QUEUE[0], waiting: QUEUE }))
		await choose()
		expect(seen()).toContain('P atención preferente · G atención general')
	})

	it('says so when nobody waits', async () => {
		box.state.mockResolvedValue(idle())
		await choose()
		expect(seen()).toContain('En espera (0)')
		expect(seen()).toContain('No hay turnos en espera.')
		expect(rows()).toEqual([])
	})
})

describe('the connection', () => {
	it('keeps the last state in view with a note while the poll fails, and clears it on recovery', async () => {
		box.state.mockResolvedValueOnce(idle({ current: called() }))
		await choose()
		box.state.mockRejectedValue(new Error('Network Error'))
		await vi.advanceTimersByTimeAsync(3000)
		expect(seen()).toContain('Sin conexión con el servidor. Reintentando…')
		expect(seen()).toContain('FAR-P-014')
		box.state.mockResolvedValue(idle({ current: called() }))
		await vi.advanceTimersByTimeAsync(10000)
		expect(seen()).not.toContain('Sin conexión')
	})

	it('polls the state every 3 seconds', async () => {
		box.state.mockResolvedValue(idle())
		await choose()
		expect(box.state).toHaveBeenCalledTimes(1)
		await vi.advanceTimersByTimeAsync(3000)
		expect(box.state).toHaveBeenCalledTimes(2)
		await vi.advanceTimersByTimeAsync(3000)
		expect(box.state).toHaveBeenCalledTimes(3)
	})

	it('stops polling when the page goes away', async () => {
		box.state.mockResolvedValue(idle())
		await choose()
		wrapper.unmount()
		wrapper = undefined
		await vi.advanceTimersByTimeAsync(9000)
		expect(box.state).toHaveBeenCalledTimes(1)
	})
})

describe('leaving a box', () => {
	it('does not carry a refusal over to the next box', async () => {
		box.state.mockResolvedValue(idle({ next: NEXT }))
		box.act.mockRejectedValue(refusal('Ya no hay turnos.'))
		await choose()
		await button('Llamar siguiente').trigger('click')
		await settle()
		await button('Cambiar box').trigger('click')
		await wrapper.find('select').findAll('option').find((o) => o.text() === 'Box 4').setSelected()
		await settle()
		expect(wrapper.find('[role="alert"]').exists()).toBe(false)
	})
})

describe('when the list of boxes cannot be read', () => {
	it('says so instead of showing an empty picker silently', async () => {
		box.boxes.mockRejectedValue(new Error('Network Error'))
		await open()
		expect(wrapper.find('[role="alert"]').text()).toBe('No se pudo cargar la lista de boxes. Recargue la página.')
	})
})

describe('changing box while a request is in flight', () => {
	it('ignores the late answer and leaves the next box usable', async () => {
		let answer
		box.state.mockResolvedValueOnce(idle({ next: NEXT }))
		box.act.mockReturnValue(new Promise((resolve) => { answer = resolve }))
		await choose()
		await button('Llamar siguiente').trigger('click')
		await button('Cambiar box').trigger('click')
		box.state.mockResolvedValue(idle({ box: STATE_BOXES[1], why: 'Nada en espera en Box 4.' }))
		await wrapper.find('select').findAll('option').find((o) => o.text() === 'Box 4').setSelected()
		await settle()
		answer(idle({ current: called({ label: 'FAR-P-099' }) }))
		await settle()
		expect(seen()).toContain('Nada en espera en Box 4.')
		expect(seen()).not.toContain('FAR-P-099')
		expect(isDisabled('No hay turnos en espera')).toBe(true)
	})
})
