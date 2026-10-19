// SPDX-License-Identifier: AGPL-3.0-or-later
import { describe, expect, it } from 'vitest'
import { GROUPS, SECTIONS } from './sections.js'

describe('the sections of the staff app', () => {
	it('are the five a staff member uses, in the order the menu shows them', () => {
		expect(SECTIONS.map((s) => [s.group, s.id, s.name])).toEqual([
			['Operación', 'box', 'Box'],
			['Operación', 'estadisticas', 'Estadísticas'],
			['Configuración', 'servicios', 'Servicios'],
			['Configuración', 'boxes', 'Boxes'],
			['Configuración', 'pantallas', 'Pantallas'],
		])
	})

	it('have unique ids', () => {
		expect(new Set(SECTIONS.map((s) => s.id)).size).toBe(SECTIONS.length)
	})

	it('are grouped under two headings, each with at least one section', () => {
		expect(GROUPS).toEqual(['Operación', 'Configuración'])
		for (const group of GROUPS) expect(SECTIONS.some((s) => s.group === group)).toBe(true)
	})
})
