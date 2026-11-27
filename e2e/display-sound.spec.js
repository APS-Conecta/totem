// SPDX-License-Identifier: AGPL-3.0-or-later
//
// The Pantalla's sound state, in a real browser (#49): the button «Toque aquí para activar el sonido»
// and the Aviso of a new llamado follow the page's AudioContext as it is now, not as it was when the
// page opened. The suite's Chrome may play sound without a tap (e2e/browser.js), so the test moves the
// context itself: suspend() is what an audio-session interruption or a screen lock does to it, and
// resume() without a tap is a context that starts running by itself.
//
// The Pantalla has sound but no voice, so an Aviso is the chime alone, counted by openDisplay.

const { test, expect } = require('@playwright/test')
const { callNew, chimes, login, openDisplay, world } = require('./helpers')

const UNLOCK = 'Toque aquí para activar el sonido'

test.describe('the Pantalla follows its sound state', () => {
	let w

	test.beforeEach(async ({ page }) => {
		await login(page)
		w = await world(page, { pantalla: { volume: 100, voice: 'off' } })
	})
	test.afterEach(async () => { await w?.cleanup() })

	test('sound that stops after the page opened asks for a touch and plays no Aviso until the touch', async ({ browser, page }) => {
		const room = await openDisplay(browser, w)
		const card = room.getByRole('status')
		const unlock = room.getByRole('button', { name: UNLOCK })
		await expect(unlock).toBeHidden()

		await room.evaluate(() => window.__audio.suspend())
		// No llamado needed: the next feed answer (every 2 s) shows the button.
		await expect(unlock).toBeVisible({ timeout: 6_000 })

		const silent = await callNew(page, w)
		await expect(card).toContainText(silent, { timeout: 8_000 })
		expect(await chimes(room)).toBe(0)

		await unlock.click()
		await expect(unlock).toBeHidden()
		// The llamado that came while the sound was held back is never replayed after the touch.
		expect(await chimes(room)).toBe(0)

		const heard = await callNew(page, w)
		await expect(card).toContainText(heard, { timeout: 8_000 })
		await expect.poll(() => chimes(room)).toBe(1)
	})

	test('sound that starts by itself on a locked page hides the button and plays the next Aviso', async ({ browser, page }) => {
		const room = await openDisplay(browser, w, { locked: true })
		const card = room.getByRole('status')
		const unlock = room.getByRole('button', { name: UNLOCK })
		await expect(unlock).toBeVisible()

		await room.evaluate(() => window.__audio.resume())
		// No tap and no llamado: the next feed answer hides the button.
		await expect(unlock).toBeHidden({ timeout: 6_000 })

		const heard = await callNew(page, w)
		await expect(card).toContainText(heard, { timeout: 8_000 })
		await expect.poll(() => chimes(room)).toBe(1)
	})
})
