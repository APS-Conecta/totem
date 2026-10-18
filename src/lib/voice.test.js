import { describe, expect, it, vi } from 'vitest'
import { browserEngine, speak } from './voice.js'

const engine = (name, { available = true, fails = false } = {}) => ({
	name,
	available: vi.fn(async () => available),
	say: vi.fn(async () => { if (fails) throw new Error(name + ' failed') }),
})

describe('speak', () => {
	it('says the phrase twice, because a room is noisy and a voice is gone once spoken', async () => {
		const piper = engine('piper')
		await speak('Turno Farmacia, preferente 12. Diríjase al Box 2.', { engines: [piper], volume: 100, pauseMs: 0 })

		expect(piper.say).toHaveBeenCalledTimes(2)
		expect(piper.say).toHaveBeenCalledWith('Turno Farmacia, preferente 12. Diríjase al Box 2.', 100)
	})

	it('falls back to the next engine when the first is unavailable', async () => {
		const piper = engine('piper', { available: false })
		const browser = engine('browser')
		const used = await speak('hola', { engines: [piper, browser], volume: 80, pauseMs: 0 })

		expect(piper.say).not.toHaveBeenCalled()
		expect(browser.say).toHaveBeenCalledTimes(2)
		expect(used).toBe('browser')
	})

	it('falls back when an engine starts and then fails', async () => {
		const piper = engine('piper', { fails: true })
		const browser = engine('browser')
		const used = await speak('hola', { engines: [piper, browser], volume: 80, pauseMs: 0 })

		expect(used).toBe('browser')
		expect(browser.say).toHaveBeenCalledTimes(2)
	})

	it('answers null, without throwing, when no engine can speak (the chime and the frame still announce)', async () => {
		const used = await speak('hola', { engines: [engine('a', { available: false }), engine('b', { fails: true })], volume: 80, pauseMs: 0 })

		expect(used).toBeNull()
	})

	it('says nothing at volume 0', async () => {
		const piper = engine('piper')
		await speak('hola', { engines: [piper], volume: 0, pauseMs: 0 })

		expect(piper.say).not.toHaveBeenCalled()
	})

	it('waits between the two sayings', async () => {
		vi.useFakeTimers()
		const piper = engine('piper')
		const done = speak('hola', { engines: [piper], volume: 100, pauseMs: 700 })

		await vi.advanceTimersByTimeAsync(0)
		expect(piper.say).toHaveBeenCalledTimes(1)
		await vi.advanceTimersByTimeAsync(699)
		expect(piper.say).toHaveBeenCalledTimes(1)
		await vi.advanceTimersByTimeAsync(1)
		await done
		expect(piper.say).toHaveBeenCalledTimes(2)
		vi.useRealTimers()
	})
})

describe('browserEngine', () => {
	function fakeSynth({ voices = [], fail = false } = {}) {
		const spoken = []
		const synth = {
			getVoices: () => voices,
			cancel: vi.fn(),
			speak: vi.fn((u) => { spoken.push(u); queueMicrotask(() => (fail ? u.onerror?.({ error: 'synthesis-failed' }) : u.onend?.())) }),
		}
		return { synth, spoken }
	}
	function withSynth(synth) {
		globalThis.window = { speechSynthesis: synth }
		globalThis.SpeechSynthesisUtterance = class { constructor(text) { this.text = text } }
	}

	it('is unavailable where the browser has no speech synthesis', async () => {
		globalThis.window = {}
		expect(await browserEngine().available()).toBe(false)
	})

	it('speaks with the Chilean Spanish voice when there is one, else any Spanish voice', async () => {
		const { synth, spoken } = fakeSynth({ voices: [{ lang: 'en-US', name: 'en' }, { lang: 'es-ES', name: 'es' }, { lang: 'es-CL', name: 'cl' }] })
		withSynth(synth)
		await browserEngine().say('hola', 70)

		expect(spoken[0].voice.name).toBe('cl')
		expect(spoken[0].volume).toBeCloseTo(0.7, 5)
		expect(spoken[0].lang).toBe('es-CL')
		expect(spoken[0].rate).toBeLessThan(1)
	})

	it('is unavailable when the machine has no Spanish voice at all (a kiosk Linux often has none)', async () => {
		withSynth(fakeSynth({ voices: [{ lang: 'en-US', name: 'en' }] }).synth)
		expect(await browserEngine().available()).toBe(false)
	})

	it('rejects when the browser fails to speak, so the chain can move on', async () => {
		withSynth(fakeSynth({ voices: [{ lang: 'es-ES', name: 'es' }], fail: true }).synth)
		await expect(browserEngine().say('hola', 100)).rejects.toThrow('synthesis-failed')
	})
})
