// SPDX-License-Identifier: AGPL-3.0-or-later
//
// The bundled voice, for real: a call on a display whose pantalla has a voice must be spoken by
// the Piper model running in the browser from this app's own files (docs/adr/0005), not by the
// operating system's voice. The proof is the audio itself: a buffer a few seconds long played
// through WebAudio after the chime. Needs `make tts-assets`; the model's first load takes a while.
//
// The length bound holds for one phrase only, so the run id the world puts in its names stays out of it
// (#108): the phrase is fixed per Playwright worker, servicio «Farmacia N» after the worker and box «Ventanilla».

const { test, expect } = require('@playwright/test')
const { call, callNew, chimes, login, openDisplay, world } = require('./helpers')

// No number in it: said as itself, or as «ventanilla» while it is the instance's only box: one word either way.
const BOX = 'Ventanilla'

/** Servicios are never deleted: an earlier test's servicio keeps the name, so rename it out of the way. */
async function freeServicioName(page, name) {
	for (const s of await call(page, 'GET', '/apps/totem/servicios')) {
		if (s.name === name) await call(page, 'PUT', `/apps/totem/servicios/${s.id}`, { ...s, name: `${name} ${s.id}` })
	}
}

/** Record the duration of every audio buffer the room plays in window.__voiceBuffers: the chime is oscillators, the voice is a buffer. */
async function recordVoiceBuffers(room) {
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
}

/** Record every bundled-voice worker the room starts in window.__voiceWorkers: its voice, whether it loaded, what it was asked to say, whether it answered with audio, whether it was ended. */
async function recordVoiceWorkers(room) {
	await room.addInitScript(() => {
		window.__voiceWorkers = []
		const Native = window.Worker
		window.Worker = class extends Native {
			constructor(...args) {
				super(...args)
				this.record = { voice: null, ready: false, said: 0, spoke: false, terminated: false }
				window.__voiceWorkers.push(this.record)
				this.addEventListener('message', ({ data }) => {
					if (data?.type === 'ready') this.record.ready = true
					if (data?.type === 'audio') this.record.spoke = true
				})
			}

			postMessage(message, ...rest) {
				if (message?.type === 'init') this.record.voice = message.voice
				if (message?.type === 'say') this.record.said += 1
				return super.postMessage(message, ...rest)
			}

			terminate() {
				this.record.terminated = true
				return super.terminate()
			}
		}
	})
}

for (const voice of ['hombre', 'mujer']) {
	test(`a call is spoken aloud by the bundled ${voice} voice`, async ({ browser, page }, testInfo) => {
		test.setTimeout(120_000)
		await login(page)
		// One name per worker: a test running at the same time in another worker never takes it.
		const servicioName = `Farmacia ${testInfo.parallelIndex + 1}`
		await freeServicioName(page, servicioName)
		const w = await world(page, { pantalla: { volume: 100, voice }, servicioName: () => servicioName, boxName: BOX })
		try {
			const room = await (await browser.newContext({ locale: 'es-CL' })).newPage()
			await recordVoiceBuffers(room)
			await room.goto('/apps/totem/display/' + w.screen.slug)

			const turno = await call(page, 'POST', '/apps/totem/turnos', { servicioId: w.servicio.id, preferente: true })
			await call(page, 'POST', `/apps/totem/console/${w.box.id}/call`)
			expect(turno.label).toBe(`${w.prefix}-P-001`)
			await expect(room.getByText(`${servicioName}, preferente 1. Diríjase a ventanilla.`)).toBeVisible()

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

test('a phrase the bundled voice never answers leaves the next llamado its Aviso', async ({ browser, page }) => {
	test.setTimeout(150_000)
	await login(page)
	const w = await world(page, { pantalla: { volume: 100, voice: 'mujer' } })
	try {
		const room = await openDisplay(browser, w)
		// The espeak-ng data the phonemizer downloads for a phrase fails: the loaded worker never answers it (#35).
		let refused = 0
		await room.context().route('**/piper_phonemize.data', (route) => { refused += 1; return route.abort() })
		const card = room.getByRole('status')

		const unanswered = await callNew(page, w)
		await expect(card).toContainText(unanswered, { timeout: 8_000 })
		await expect.poll(() => refused, { timeout: 90_000, intervals: [1000] }).toBeGreaterThan(0)
		expect(await chimes(room)).toBe(1)

		const next = await callNew(page, w)
		await expect(card).toContainText(next, { timeout: 8_000 })
		// The unanswered phrase fails after its time limit, so the queue moves on and the next llamado rings.
		await expect.poll(() => chimes(room), { timeout: 60_000, intervals: [1000] }).toBe(2)
	} finally {
		await w.cleanup()
	}
})

test('a Pantalla whose voice changes keeps only the new voice loaded, and speaks with it', async ({ browser, page }) => {
	test.setTimeout(180_000)
	await login(page)
	const w = await world(page, { pantalla: { volume: 100, voice: 'mujer' } })
	try {
		const room = await openDisplay(browser, w)
		await recordVoiceWorkers(room)
		await room.reload()
		const workers = () => room.evaluate(() => window.__voiceWorkers.map(({ voice, ready, said, spoke, terminated }) => ({ voice, ready, said: said > 0, spoke, terminated })))
		await expect.poll(workers, { timeout: 90_000, intervals: [1000] }).toEqual([{ voice: 'mujer', ready: true, said: false, spoke: false, terminated: false }])

		await call(page, 'PUT', `/apps/totem/pantallas/${w.screen.id}`, { ...w.screen, voice: 'hombre' })
		await expect.poll(workers, { timeout: 90_000, intervals: [1000] }).toEqual([
			{ voice: 'mujer', ready: true, said: false, spoke: false, terminated: true },
			{ voice: 'hombre', ready: true, said: false, spoke: false, terminated: false },
		])

		await callNew(page, w)
		await expect.poll(workers, { timeout: 60_000, intervals: [1000] }).toEqual([
			{ voice: 'mujer', ready: true, said: false, spoke: false, terminated: true },
			{ voice: 'hombre', ready: true, said: true, spoke: true, terminated: false },
		])
	} finally {
		await w.cleanup()
	}
})

test('a model that downloads for longer than the load time limit still loads, and a later llamado is spoken by it', async ({ browser, page }) => {
	test.setTimeout(240_000)
	await login(page)
	const w = await world(page, { pantalla: { volume: 100, voice: 'mujer' } })
	try {
		const room = await openDisplay(browser, w)
		// A slow link: the model's download is held past the 60 s a llamado waits for the load (#65), then let through.
		let release
		const held = new Promise((resolve) => { release = resolve })
		let requested = 0
		await room.context().route('**/*.onnx', async (route) => {
			requested += 1
			await held
			await route.continue().catch(() => {}) // the request is gone if its worker was ended
		})
		await recordVoiceWorkers(room)
		await recordVoiceBuffers(room)
		await room.reload()
		await expect(room.locator('.d-foot .cap')).toHaveText(/^Sonido y voz activados/)
		await expect.poll(() => requested, { timeout: 30_000, intervals: [500] }).toBe(1)
		const card = room.getByRole('status')
		const buffers = () => room.evaluate(() => window.__voiceBuffers)

		// Past the time limit, with the model still downloading: the llamado rings and falls back.
		await room.waitForTimeout(62_000)
		const meanwhile = await callNew(page, w)
		await expect(card).toContainText(meanwhile, { timeout: 8_000 })
		await expect.poll(() => chimes(room), { timeout: 10_000, intervals: [500] }).toBe(1)
		expect(await buffers()).toEqual([])

		release()
		const workers = () => room.evaluate(() => window.__voiceWorkers.map(({ ready, terminated }) => ({ ready, terminated })))
		await expect.poll(workers, { timeout: 90_000, intervals: [1000] }).toEqual([{ ready: true, terminated: false }])

		const later = await callNew(page, w)
		await expect(card).toContainText(later, { timeout: 8_000 })
		await expect.poll(async () => (await buffers()).length, { timeout: 60_000, intervals: [1000] }).toBeGreaterThanOrEqual(2)
		expect((await buffers())[0]).toBeGreaterThan(2)
		expect(await workers()).toEqual([{ ready: true, terminated: false }])
	} finally {
		await w.cleanup()
	}
})
