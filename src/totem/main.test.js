// SPDX-License-Identifier: AGPL-3.0-or-later
// @vitest-environment jsdom

import { flushPromises } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('../api.js', async (importOriginal) => ({
	...(await importOriginal()),
	publicServicios: vi.fn().mockResolvedValue([{ id: 1, name: 'Farmacia', prefix: 'FAR', color: '#1b6ec2' }]),
	issueTurno: vi.fn(),
}))

describe('the totem entry', () => {
	beforeEach(() => {
		document.body.innerHTML = '<div id="totem-totem">Si este mensaje no desaparece, la aplicación no pudo cargar: avise al administrador.</div>'
	})

	it('replaces the fallback line of the page with the totem', async () => {
		await import('./main.js')
		await flushPromises()

		const mount = document.getElementById('totem-totem')
		expect(mount.textContent).not.toContain('la aplicación no pudo cargar')
		expect(mount.textContent).toContain('¿A qué servicio viene?')
		expect(mount.textContent).toContain('Farmacia')
	})
})
