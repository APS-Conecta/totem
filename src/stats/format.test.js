// SPDX-License-Identifier: AGPL-3.0-or-later
import { describe, expect, it } from 'vitest'
import { count, dayLabel, periodLabel } from './format.js'

describe('count', () => {
	it('writes whole numbers with a dot between thousands, the way es-CL does', () => {
		expect(count(0)).toBe('0')
		expect(count(999)).toBe('999')
		expect(count(1000)).toBe('1.000')
		expect(count(12345)).toBe('12.345')
		expect(count(1234567)).toBe('1.234.567')
	})
})

describe('dayLabel', () => {
	it('names the weekday, the day and the month, short', () => {
		expect(dayLabel('2026-10-19')).toBe('lun, 19 oct')
		expect(dayLabel('2026-10-21')).toBe('mié, 21 oct')
		expect(dayLabel('2026-10-25')).toBe('dom, 25 oct')
		expect(dayLabel('2027-01-01')).toBe('vie, 1 ene')
	})
})

describe('periodLabel', () => {
	it('names a day in full', () => {
		expect(periodLabel({ kind: 'day', from: '2026-10-21', to: '2026-10-21' })).toBe('miércoles 21 de octubre de 2026')
	})

	it('names a week by its first and last day', () => {
		expect(periodLabel({ kind: 'week', from: '2026-10-19', to: '2026-10-25' })).toBe('Semana del 19 al 25 de octubre de 2026')
	})

	it('names both months when a week crosses one, and both years when it crosses the new year', () => {
		expect(periodLabel({ kind: 'week', from: '2026-10-26', to: '2026-11-01' })).toBe('Semana del 26 de octubre al 1 de noviembre de 2026')
		expect(periodLabel({ kind: 'week', from: '2026-12-28', to: '2027-01-03' })).toBe('Semana del 28 de diciembre de 2026 al 3 de enero de 2027')
	})

	it('names a month and its year', () => {
		expect(periodLabel({ kind: 'month', from: '2026-10-01', to: '2026-10-31' })).toBe('octubre de 2026')
	})
})
