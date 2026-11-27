// SPDX-License-Identifier: AGPL-3.0-or-later
//
// The Pantalla's sound state, in a real browser (#49): the button «Toque aquí para activar el sonido»
// and the Aviso of a new llamado follow the page's AudioContext as it is now, not as it was when the
// page opened. The suite's Chrome may play sound without a tap (e2e/browser.js), so the test moves the
// context itself: suspend() is what an audio-session interruption or a screen lock does to it, and
// resume() without a tap is a context that starts running by itself.
//
// The Pantalla has sound but no voice, so an Aviso is the chime alone; the page counts the chimes it
// rings (each one builds one compressor) instead of playing them through the speakers.

const { test, expect } = require('@playwright/test')
const { call, login, world } = require('./helpers')

const UNLOCK = 'Toque aquí para activar el sonido'

/** The display of `w`'s Pantalla, its AudioContext kept on window.__audio; `locked` suspends it as it is made. */
async function openDisplay(browser, w, { locked = false } = {}) {
	const room = await (await browser.newContext({ locale: 'es-CL' })).newPage()
	await room.addInitScript((startLocked) => {
		window.__chimes = 0
		const Native = window.AudioContext
		window.AudioContext = class extends Native {
			constructor(...args) {
				super(...args)
				window.__audio = this
				if (startLocked) this.suspend()
			}

			createDynamicsCompressor() {
				window.__chimes += 1
				return super.createDynamicsCompressor()
			}
		}
	}, locked)
	await room.goto('/apps/totem/display/' + w.screen.slug)
	// The caption names the Pantalla's own sound once the first feed answer is in: from here a llamado is new.
	await expect(room.locator('.d-foot .cap')).toHaveText('Sonido activado')
	return room
}

/** Frees the box of its called turno, takes a new one and calls it; answers its label. */
async function callNew(page, w) {
	const { current } = await call(page, 'GET', `/apps/totem/console/${w.box.id}/state`)
	if (current?.estado === 'called') await call(page, 'POST', `/apps/totem/console/${w.box.id}/absent`)
	const turno = await call(page, 'POST', '/apps/totem/turnos', { servicioId: w.servicio.id, preferente: false })
	await call(page, 'POST', `/apps/totem/console/${w.box.id}/call`)
	return turno.label
}

const chimes = (room) => room.evaluate(() => window.__chimes)

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
