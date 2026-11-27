// SPDX-License-Identifier: AGPL-3.0-or-later
//
// The browser's own voice, the fallback when the bundled voice cannot load (docs/adr/0005), speaks the
// first llamado after a page load (#43). Chromium-based browsers commonly answer the first
// speechSynthesis.getVoices() with an empty list, load the voices in the background and then fire
// voiceschanged. The suite's Chrome has no voices of its own, so the page gets a speechSynthesis that
// behaves that way, with one Spanish voice, and counts what it is asked to say. The bundled voice's
// model requests are refused, so every Aviso falls back to the browser's voice.

const { test, expect } = require('@playwright/test')
const { callNew, login, openDisplay, world } = require('./helpers')

test('the first llamado after a page load is spoken by the browser voice whose list loads late', async ({ browser, page }) => {
	await login(page)
	const w = await world(page, { pantalla: { volume: 100, voice: 'mujer' } })
	try {
		const room = await openDisplay(browser, w)
		await room.context().route('**/models/*.onnx.json', (route) => route.abort())
		await room.addInitScript(() => {
			window.__spoken = []
			const voices = [{ lang: 'es-CL', name: 'Español (Chile)' }]
			const synth = new EventTarget()
			let loaded = false
			let loading = false
			synth.getVoices = () => {
				if (!loaded && !loading) {
					loading = true
					setTimeout(() => {
						loaded = true
						synth.dispatchEvent(new Event('voiceschanged'))
					}, 100)
				}
				return loaded ? voices : []
			}
			synth.cancel = () => {}
			synth.speak = (utterance) => {
				window.__spoken.push(utterance.text)
				setTimeout(() => utterance.onend?.(), 0)
			}
			Object.defineProperty(window, 'speechSynthesis', { configurable: true, value: synth })
			window.SpeechSynthesisUtterance = class { constructor(text) { this.text = text } }
		})
		await room.reload()
		await expect(room.locator('.d-foot .cap')).toHaveText(/^Sonido y voz activados/)

		const label = await callNew(page, w)
		await expect(room.getByRole('status')).toContainText(label, { timeout: 8_000 })
		// Said twice, after the chime.
		await expect.poll(() => room.evaluate(() => window.__spoken.length), { timeout: 15_000 }).toBe(2)
	} finally {
		await w.cleanup()
	}
})
