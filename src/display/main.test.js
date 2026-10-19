// @vitest-environment jsdom
// SPDX-License-Identifier: AGPL-3.0-or-later
import { expect, it, vi } from 'vitest'

vi.mock('../api.js', () => ({ readFeed: vi.fn().mockResolvedValue({ pantalla: { name: 'Sala', volume: 0, voice: 'off' }, llamados: [], cursor: 0 }), warnError: vi.fn(), messageFrom: vi.fn() }))
vi.mock('../lib/public-path.js', () => ({}))

it('mounts the screen into the empty #totem-display', async () => {
	document.body.innerHTML = '<div id="totem-display"></div>'
	const mount = document.getElementById('totem-display')
	expect(mount.textContent).toBe('')

	await import('./main.js')

	expect(mount.textContent).toContain('Últimos llamados')
})
