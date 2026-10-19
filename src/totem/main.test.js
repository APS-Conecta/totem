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
		document.body.innerHTML = '<div id="totem-totem"></div>'
	})

	it('mounts the totem into the empty mount point of the page', async () => {
		const mount = document.getElementById('totem-totem')
		expect(mount.textContent).toBe('')

		await import('./main.js')
		await flushPromises()

		expect(mount.textContent).toContain('¿A qué unidad viene?')
		expect(mount.textContent).toContain('Farmacia')
	})
})
