// @vitest-environment jsdom
// SPDX-License-Identifier: AGPL-3.0-or-later
import { expect, it, vi } from 'vitest'

vi.mock('../api.js', () => ({ readFeed: vi.fn().mockResolvedValue({ pantalla: { name: 'Sala', volume: 0, voice: 'off' }, llamados: [], cursor: 0 }), warnError: vi.fn(), messageFrom: vi.fn() }))
vi.mock('../lib/public-path.js', () => ({}))

it('replaces the fallback line in #totem-display with the screen', async () => {
	document.body.innerHTML = '<div id="totem-display">Si este mensaje no desaparece</div>'
	await import('./main.js')
	const mount = document.getElementById('totem-display')

	expect(mount.textContent).not.toContain('Si este mensaje no desaparece')
	expect(mount.textContent).toContain('Últimos llamados')
})
