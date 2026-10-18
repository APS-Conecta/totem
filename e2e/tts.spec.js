// SPDX-License-Identifier: AGPL-3.0-or-later
//
// The bundled voice, for real: a call on a display whose pantalla has a voice must be spoken by
// the Piper model running in the browser from this app's own files (docs/adr/0005), not by the
// operating system's voice. The proof is the audio itself: a buffer a few seconds long played
// through WebAudio after the chime. Needs `make tts-assets`; the model's first load takes a while.

const { test, expect } = require('@playwright/test')
const { call, login, world } = require('./helpers')

for (const voice of ['hombre', 'mujer']) {
	test(`a call is spoken aloud by the bundled ${voice} voice`, async ({ browser, page }) => {
		test.setTimeout(120_000)
		await login(page)
		const w = await world(page, { pantalla: { volume: 100, voice } })
		try {
			const room = await (await browser.newContext({ locale: 'es-CL' })).newPage()
			// Record every audio buffer the page plays: the chime is oscillators, the voice is a buffer.
			await room.addInitScript(() => {
				window.__voiceBuffers = []
				const create = AudioContext.prototype.createBufferSource
				AudioContext.prototype.createBufferSource = function () {
					const source = create.call(this)
					const start = source.start.bind(source)
					source.start = (...args) => { window.__voiceBuffers.push(source.buffer.duration); return start(...args) }
					return source
				}
			})
			await room.goto('/apps/totem/display/' + w.screen.slug)

			const turno = await call(page, 'POST', '/apps/totem/turnos', { servicioId: w.servicio.id, preferente: true })
			await call(page, 'POST', `/apps/totem/console/${w.box.id}/call`)
			expect(turno.label).toBe(`${w.prefix}-P-001`)

			// Two sayings of a ~3-5 s phrase, after the model has loaded and synthesized.
			await expect.poll(() => room.evaluate(() => window.__voiceBuffers.length), { timeout: 90_000, intervals: [1000] }).toBeGreaterThanOrEqual(2)
			const durations = await room.evaluate(() => window.__voiceBuffers)
			expect(durations[0]).toBeGreaterThan(2)
			expect(durations[0]).toBeLessThan(8)
		} finally {
			await w.cleanup()
		}
	})
}
