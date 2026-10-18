// @vitest-environment jsdom
// SPDX-License-Identifier: AGPL-3.0-or-later
import { expect, it, vi } from 'vitest'

vi.mock('../api.js', async (importOriginal) => ({
	...(await importOriginal()),
	box: { boxes: vi.fn().mockResolvedValue([]), state: vi.fn(), act: vi.fn() },
	warnError: vi.fn(),
}))

it('replaces the fallback line in #totem-box with the console', async () => {
	document.body.innerHTML = '<div id="skip-actions"></div><div id="totem-box">Si este mensaje no desaparece</div>'
	await import('./main.js')
	const mount = document.getElementById('totem-box')
	expect(mount.textContent).not.toContain('Si este mensaje no desaparece')
	expect(mount.textContent).toContain('Elija su box')
})
