// SPDX-License-Identifier: AGPL-3.0-or-later
// @vitest-environment jsdom
import { describe, expect, it, vi } from 'vitest'
import { flushPromises } from '@vue/test-utils'

vi.mock('../lib/public-path.js', () => ({}))
vi.mock('../api.js', async (importActual) => ({
	...(await importActual()),
	admin: { servicios: { list: vi.fn().mockResolvedValue([]), save: vi.fn() } },
}))

describe('admin entry', () => {
	it('replaces the fallback line of the mount point with the page', async () => {
		document.body.innerHTML = '<div id="totem-admin">Si este mensaje no desaparece, la aplicación no pudo cargar: avise al administrador.</div>'

		await import('./main.js')
		await flushPromises()

		const root = document.getElementById('totem-admin')
		expect(root.textContent).not.toContain('Si este mensaje no desaparece')
		expect(root.querySelector('[role="tablist"]')).not.toBeNull()
	})
})
