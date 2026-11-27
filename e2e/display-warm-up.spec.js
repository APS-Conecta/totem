// SPDX-License-Identifier: AGPL-3.0-or-later
//
// The Pantalla loads its bundled voice ahead of the first llamado (#41): on every feed answer that
// names a voice it will speak, so a voice or volume changed after the page opened is loaded before
// its first Aviso. The proof is the browser asking for that voice's model, with no llamado made.
// The model requests are refused, so no test waits for a full model load: a refused load fails at
// once, and the bundled voice may then load again only after its retry interval (src/tts/piper.js),
// which the last test passes with page.clock.

const { test, expect } = require('@playwright/test')
const { FEED, call, login, openDisplay, world } = require('./helpers')

const MODELS = { mujer: 'es_MX-claude-high.onnx.json', hombre: 'es_ES-davefx-medium.onnx.json' }
// The display polls its feed every 2 s: a change of the Pantalla shows on the next answer.
const NEXT_ANSWER = { timeout: 6_000 }

/** Refuses `room`'s requests for a voice model and answers the models asked for so far, in order. */
async function refuseModels(room) {
	const asked = []
	await room.context().route('**/models/*.onnx.json', (route) => {
		asked.push(route.request().url().split('/').pop())
		return route.abort()
	})
	return asked
}

test.describe('the Pantalla warms up the voice it will speak', () => {
	let w

	test.beforeEach(async ({ page }) => { await login(page) })
	test.afterEach(async () => { await w?.cleanup() })

	const setPantalla = (page, change) => call(page, 'PUT', `/apps/totem/pantallas/${w.screen.id}`, { ...w.screen, ...change })

	// A Pantalla with no voice has no model to ask for: the proof is that the page starts no worker.
	test('a Pantalla with no voice loads no voice', async ({ browser, page }) => {
		w = await world(page, { pantalla: { volume: 100, voice: 'off' } })
		const room = await openDisplay(browser, w)
		const workers = []
		room.on('worker', (worker) => workers.push(worker.url()))

		// Reloaded with the listener on, so the first answer's warm-up is seen too.
		await Promise.all([room.waitForResponse(FEED), room.reload()])
		await room.waitForResponse(FEED)
		expect(workers).toEqual([])
	})

	test('a voice chosen after the page opened', async ({ browser, page }) => {
		w = await world(page, { pantalla: { volume: 100, voice: 'off' } })
		const room = await openDisplay(browser, w)
		const asked = await refuseModels(room)

		await setPantalla(page, { voice: 'mujer' })
		await expect.poll(() => asked, NEXT_ANSWER).toEqual([MODELS.mujer])
	})

	test('a change to the other voice', async ({ browser, page }) => {
		w = await world(page, { pantalla: { volume: 100, voice: 'mujer' } })
		const room = await openDisplay(browser, w)
		// The caption reads the same before and after the first answer here: wait for a later one.
		await room.waitForResponse(FEED)
		const asked = await refuseModels(room)

		await setPantalla(page, { voice: 'hombre' })
		// The first answer's own warm-up may reach the route too: only the new voice counts here.
		await expect.poll(() => asked, NEXT_ANSWER).toContain(MODELS.hombre)
	})

	test('sound turned on after the page opened', async ({ browser, page }) => {
		w = await world(page, { pantalla: { volume: 0, voice: 'mujer' } })
		const room = await openDisplay(browser, w)
		const asked = await refuseModels(room)

		await setPantalla(page, { volume: 80 })
		await expect.poll(() => asked, NEXT_ANSWER).toEqual([MODELS.mujer])
	})

	test('a voice the Pantalla keeps is warmed up once, so a failed load waits for a llamado to retry', async ({ browser, page }) => {
		w = await world(page, { pantalla: { volume: 100, voice: 'off' } })
		const room = await openDisplay(browser, w)
		await room.clock.install()
		const asked = await refuseModels(room)

		await setPantalla(page, { voice: 'mujer' })
		await expect.poll(() => asked, NEXT_ANSWER).toEqual([MODELS.mujer])

		// Past the bundled voice's retry interval, the answers that keep naming the voice load nothing.
		await room.clock.fastForward('11:00')
		await room.waitForResponse(FEED)
		await room.waitForResponse(FEED)
		expect(asked).toEqual([MODELS.mujer])
	})
})
