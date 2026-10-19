// @vitest-environment jsdom
// SPDX-License-Identifier: AGPL-3.0-or-later
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import StatsView from './StatsView.vue'
import { stats } from '../api.js'

// Only the network boundary is replaced; messageFrom stays real.
vi.mock('../api.js', async (importOriginal) => ({
	...(await importOriginal()),
	stats: { board: vi.fn(), csvUrl: (kind, date) => `/csv?kind=${kind}&date=${date}` },
	warnError: vi.fn(),
}))

const counts = (emitidos, atendidos = emitidos, noShow = 0, sinAtender = 0) => ({ emitidos, atendidos, noShow, sinAtender })
const figure = (p, g) => ({
	preferente: p,
	general: g,
	total: counts(p.emitidos + g.emitidos, p.atendidos + g.atendidos, p.noShow + g.noShow, p.sinAtender + g.sinAtender),
})
const row = (id, name, p, g, extra = {}) => ({ id, name, prefix: name.slice(0, 3).toUpperCase(), color: '#2b6cb0', active: true, ...figure(p, g), ...extra })
const block = (rows) => ({
	rows,
	total: figure(
		rows.reduce((a, r) => counts(a.emitidos + r.preferente.emitidos, a.atendidos + r.preferente.atendidos, a.noShow + r.preferente.noShow, a.sinAtender + r.preferente.sinAtender), counts(0)),
		rows.reduce((a, r) => counts(a.emitidos + r.general.emitidos, a.atendidos + r.general.atendidos, a.noShow + r.general.noShow, a.sinAtender + r.general.sinAtender), counts(0)),
	),
})

/** What GET /stats answers for the week of Wednesday 2026-10-21 (Mon 19 to Sun 25). */
const WEEK = {
	period: { kind: 'week', from: '2026-10-19', to: '2026-10-25', today: '2026-10-21', previous: '2026-10-12', next: null },
	sectores: block([row(1, 'Sector Azul', counts(25), counts(77, 70, 2, 5)), row(2, 'Sector Verde', counts(0), counts(0))]),
	servicios: block([row(5, 'Farmacia', counts(24), counts(1200, 1190, 6, 4)), row(8, 'Dental', counts(1), counts(2), { active: false })]),
	days: [
		{ day: '2026-10-19', ...figure(counts(60), counts(218, 212, 4, 2)) },
		{ day: '2026-10-20', ...figure(counts(54), counts(160, 150, 8, 2)) },
		{ day: '2026-10-21', ...figure(counts(30), counts(107, 52, 6, 49)) },
		{ day: '2026-10-22', ...figure(counts(0), counts(0)) },
		{ day: '2026-10-23', ...figure(counts(0), counts(0)) },
		{ day: '2026-10-24', ...figure(counts(0), counts(0)) },
		{ day: '2026-10-25', ...figure(counts(0), counts(0)) },
	],
}
const plus = (a, b) => counts(a.emitidos + b.emitidos, a.atendidos + b.atendidos, a.noShow + b.noShow, a.sinAtender + b.sinAtender)
WEEK.total = figure(plus(WEEK.sectores.total.preferente, WEEK.servicios.total.preferente), plus(WEEK.sectores.total.general, WEEK.servicios.total.general))

const LAST_WEEK = { ...WEEK, period: { ...WEEK.period, from: '2026-10-12', to: '2026-10-18', previous: '2026-10-05', next: '2026-10-19' } }
const DAY = { ...WEEK, period: { kind: 'day', from: '2026-10-21', to: '2026-10-21', today: '2026-10-21', previous: '2026-10-20', next: null }, days: [WEEK.days[2]] }
const MONTH = { ...WEEK, period: { kind: 'month', from: '2026-10-01', to: '2026-10-31', today: '2026-10-21', previous: '2026-09-01', next: null } }

let wrapper

const settle = () => vi.advanceTimersByTimeAsync(0)

beforeEach(() => {
	// Nextcloud's page shell provides this; NcContent teleports its skip links into it.
	document.body.innerHTML = '<div id="skip-actions"></div>'
	vi.useFakeTimers()
	stats.board.mockReset().mockResolvedValue(WEEK)
})

afterEach(() => {
	wrapper?.unmount()
	wrapper = undefined
	vi.useRealTimers()
})

async function open() {
	wrapper = mount(StatsView, { attachTo: document.body })
	await settle()
	return wrapper
}

const button = (name) => wrapper.findAll('button, a').find((b) => b.text() === name)
const click = async (name) => {
	await button(name).trigger('click')
	await settle()
}
const tableOf = (caption) => wrapper.findAll('table').find((t) => t.find('caption').text() === caption)
const cells = (tr) => tr.findAll('th, td').map((c) => c.text())
const bodyRows = (table) => table.findAll('tbody tr').map(cells)

describe('opening the board', () => {
	it('asks for the period that holds today, a week, and says it is loading meanwhile', async () => {
		let resolve
		stats.board.mockReturnValue(new Promise((r) => { resolve = r }))
		wrapper = mount(StatsView, { attachTo: document.body })

		expect(wrapper.text()).toContain('Cargando')
		expect(stats.board).toHaveBeenCalledWith('week', undefined)

		resolve(WEEK)
		await settle()
		expect(wrapper.text()).not.toContain('Cargando')
	})

	it('names the period it shows', async () => {
		await open()
		expect(wrapper.find('.totem-stats__label').text()).toBe('Semana del 19 al 25 de octubre de 2026')
	})

	it('shows six absolute figures, thousands with a dot', async () => {
		await open()
		const figures = wrapper.findAll('.totem-stats__figure').map((f) => [f.find('.totem-stats__figure-label').text(), f.find('.totem-stats__figure-n').text()])
		expect(figures).toEqual([
			['Tickets emitidos', '1.329'],
			['Preferentes', '50'],
			['Generales', '1.279'],
			['Atendidos', '1.312'],
			['No se presentó', '8'],
			['Sin atender', '9'],
		])
	})

	it('says plainly that it counts tickets, not people', async () => {
		await open()
		expect(wrapper.text()).toContain('Se cuentan tickets, no personas')
	})
})

describe('the tables', () => {
	it('lists each sector with its preferente, general and total, then the totals of the block', async () => {
		await open()
		const sectores = tableOf('Tickets por sector')
		expect(sectores.findAll('thead th').map((th) => th.text())).toEqual(['', 'Tickets emitidos', '', 'Sector', 'Preferente', 'General', 'Total', 'Atendidos', 'No se presentó', 'Sin atender'])
		expect(bodyRows(sectores)).toEqual([
			['Sector Azul', '25', '77', '102', '95', '2', '5'],
			['Sector Verde', '0', '0', '0', '0', '0', '0'],
		])
		expect(cells(sectores.find('tfoot tr'))).toEqual(['Total sectores', '25', '77', '102', '95', '2', '5'])
	})

	it('lists the other servicios apart, marking one that is switched off', async () => {
		await open()
		const servicios = tableOf('Tickets por unidad')
		expect(bodyRows(servicios)).toEqual([
			['Farmacia', '24', '1.200', '1.224', '1.214', '6', '4'],
			['Dental desactivado', '1', '2', '3', '3', '0', '0'],
		])
		expect(cells(servicios.find('tfoot tr'))[0]).toBe('Total otras unidades')
	})

	it('shows each row with its servicio colour', async () => {
		await open()
		expect(tableOf('Tickets por sector').find('tbody .totem-stats__dot').attributes('style')).toContain('background')
	})

	it('adds a day-by-day table for a week and shows the days not yet reached as dashes', async () => {
		await open()
		const days = tableOf('Tickets por día')
		const rows = bodyRows(days)
		expect(rows).toHaveLength(7)
		expect(rows[0]).toEqual(['lun, 19 oct', '60', '218', '278', '272', '4', '2'])
		expect(rows[2]).toEqual(['mié, 21 oct', '30', '107', '137', '82', '6', '49'])
		expect(rows[3]).toEqual(['jue, 22 oct', '–', '–', '–', '–', '–', '–'])
		expect(cells(days.find('tfoot tr'))[0]).toBe('Total del período')
	})

	it('has no day-by-day table for a single day', async () => {
		stats.board.mockResolvedValue(DAY)
		await open()
		expect(tableOf('Tickets por día')).toBeUndefined()
	})

	it('says so when no servicio is marked as a sector', async () => {
		stats.board.mockResolvedValue({ ...WEEK, sectores: block([]) })
		await open()
		expect(wrapper.text()).toContain('Ninguna unidad está marcada como sector. Márquela en Configuración')
		expect(tableOf('Tickets por sector')).toBeUndefined()
	})

	it('says so when the period had no tickets, and still shows the zeros', async () => {
		const empty = { ...WEEK, sectores: block([row(1, 'Sector Azul', counts(0), counts(0))]), servicios: block([row(5, 'Farmacia', counts(0), counts(0))]) }
		empty.total = figure(counts(0), counts(0))
		stats.board.mockResolvedValue(empty)
		await open()
		expect(wrapper.text()).toContain('Sin tickets en este período.')
		expect(wrapper.findAll('.totem-stats__figure-n').map((n) => n.text())).toEqual(['0', '0', '0', '0', '0', '0'])
	})

	it('does not claim an empty period when there are tickets', async () => {
		await open()
		expect(wrapper.text()).not.toContain('Sin tickets en este período.')
	})
})

describe('choosing the period', () => {
	it('marks the kind in use and moves to the period of today when another is chosen', async () => {
		await open()
		expect(button('Semana').attributes('aria-pressed')).toBe('true')
		expect(button('Día').attributes('aria-pressed')).toBe('false')

		stats.board.mockResolvedValue(DAY)
		await click('Día')

		expect(stats.board).toHaveBeenLastCalledWith('day', undefined)
		expect(button('Día').attributes('aria-pressed')).toBe('true')
		expect(button('Semana').attributes('aria-pressed')).toBe('false')
		expect(wrapper.find('.totem-stats__label').text()).toBe('miércoles 21 de octubre de 2026')
	})

	it('shows a month', async () => {
		await open()
		stats.board.mockResolvedValue(MONTH)
		await click('Mes')

		expect(stats.board).toHaveBeenLastCalledWith('month', undefined)
		expect(wrapper.find('.totem-stats__label').text()).toBe('octubre de 2026')
	})

	it('goes back with the previous arrow and forward again with the next one', async () => {
		await open()
		stats.board.mockResolvedValue(LAST_WEEK)
		await wrapper.find('[aria-label="Período anterior"]').trigger('click')
		await settle()
		expect(stats.board).toHaveBeenLastCalledWith('week', '2026-10-12')

		stats.board.mockResolvedValue(WEEK)
		await wrapper.find('[aria-label="Período siguiente"]').trigger('click')
		await settle()
		expect(stats.board).toHaveBeenLastCalledWith('week', '2026-10-19')
	})

	it('cannot go past the period that holds today', async () => {
		await open()
		const next = wrapper.find('[aria-label="Período siguiente"]')
		expect(next.attributes('disabled')).toBeDefined()
		stats.board.mockClear()
		await next.trigger('click')
		expect(stats.board).not.toHaveBeenCalled()
	})

	it('reloads the same period with Actualizar', async () => {
		stats.board.mockResolvedValue(LAST_WEEK)
		await open()
		await click('Actualizar')
		expect(stats.board).toHaveBeenLastCalledWith('week', '2026-10-12')
	})

	it('shows the answer of the last choice when an earlier one arrives late', async () => {
		await open()
		let late
		stats.board.mockReturnValueOnce(new Promise((r) => { late = r })).mockResolvedValueOnce(DAY)
		await button('Mes').trigger('click')
		await click('Día')

		late(MONTH)
		await settle()

		expect(wrapper.find('.totem-stats__label').text()).toBe('miércoles 21 de octubre de 2026')
		expect(button('Día').attributes('aria-pressed')).toBe('true')
	})
})

describe('the CSV', () => {
	it('links the download of the period on screen', async () => {
		await open()
		const link = button('Descargar CSV')
		expect(link.element.tagName).toBe('A')
		expect(link.attributes('href')).toBe('/csv?kind=week&date=2026-10-19')
	})

	it('follows the period when it changes', async () => {
		await open()
		stats.board.mockResolvedValue(LAST_WEEK)
		await wrapper.find('[aria-label="Período anterior"]').trigger('click')
		await settle()
		expect(button('Descargar CSV').attributes('href')).toBe('/csv?kind=week&date=2026-10-12')
	})
})

describe('when the board cannot be loaded', () => {
	it('shows the sentence the server gave, and no numbers', async () => {
		stats.board.mockRejectedValue({ response: { status: 422, data: { message: 'Todavía no hay datos de ese período.' } } })
		await open()

		expect(wrapper.find('[role="alert"], .notecard--error').text()).toContain('Todavía no hay datos de ese período.')
		expect(wrapper.findAll('.totem-stats__figure')).toHaveLength(0)
		expect(button('Semana')).toBeDefined()
	})

	it('says to check the connection when there was no answer, and recovers with Actualizar', async () => {
		stats.board.mockRejectedValueOnce(new Error('Network Error'))
		await open()
		expect(wrapper.text()).toContain('No se pudieron cargar las estadísticas')

		await click('Actualizar')

		expect(wrapper.text()).not.toContain('No se pudieron cargar las estadísticas')
		expect(wrapper.findAll('.totem-stats__figure')).toHaveLength(6)
	})
})
