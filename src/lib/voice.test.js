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
		await speak('Turno Farmacia, preferente 12. Diríjase a Ventanilla 2.', { engines: [piper], volume: 100, pauseMs: 0 })

		expect(piper.say).toHaveBeenCalledTimes(2)
		expect(piper.say).toHaveBeenCalledWith('Turno Farmacia, preferente 12. Diríjase a Ventanilla 2.', 100)
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
		const listeners = new Set()
		const synth = {
			getVoices: () => voices,
			cancel: vi.fn(),
			speak: vi.fn((u) => { spoken.push(u); queueMicrotask(() => (fail ? u.onerror?.({ error: 'synthesis-failed' }) : u.onend?.())) }),
			addEventListener: vi.fn((type, listener) => { if (type === 'voiceschanged') listeners.add(listener) }),
			removeEventListener: vi.fn((type, listener) => { if (type === 'voiceschanged') listeners.delete(listener) }),
		}
		/** The list a Chromium-based browser loads after its first, empty answer, then announces. */
		const load = (loaded) => { voices = loaded; listeners.forEach((listener) => listener()) }
		return { synth, spoken, load }
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

	it('waits for a voice list that is still loading, and finds its Spanish voice (#43)', async () => {
		const { synth, load } = fakeSynth()
		withSynth(synth)
		const available = browserEngine().available()
		load([{ lang: 'en-US', name: 'en' }, { lang: 'es-CL', name: 'cl' }])
		expect(await available).toBe(true)
	})

	it('is unavailable when the loaded list has no Spanish voice', async () => {
		const { synth, load } = fakeSynth()
		withSynth(synth)
		const available = browserEngine().available()
		load([{ lang: 'en-US', name: 'en' }])
		expect(await available).toBe(false)
	})

	it('is unavailable within about a second when the list stays empty and never announces a change', async () => {
		vi.useFakeTimers()
		try {
			withSynth(fakeSynth().synth)
			let answer
			browserEngine().available().then((ok) => { answer = ok })
			await vi.advanceTimersByTimeAsync(999)
			expect(answer).toBeUndefined()
			await vi.advanceTimersByTimeAsync(1)
			expect(answer).toBe(false)
			expect(vi.getTimerCount()).toBe(0)
		} finally {
			vi.useRealTimers()
		}
	})

	it('answers at once when the voices are already loaded', async () => {
		vi.useFakeTimers()
		try {
			const { synth } = fakeSynth({ voices: [{ lang: 'es-ES', name: 'es' }] })
			withSynth(synth)
			let answer
			browserEngine().available().then((ok) => { answer = ok })
			await vi.advanceTimersByTimeAsync(0)
			expect(answer).toBe(true)
			expect(synth.addEventListener).not.toHaveBeenCalled()
		} finally {
			vi.useRealTimers()
		}
	})

	it('stops listening once the wait ends', async () => {
		const { synth, load } = fakeSynth()
		withSynth(synth)
		const available = browserEngine().available()
		load([{ lang: 'es-CL', name: 'cl' }])
		await available
		expect(synth.removeEventListener).toHaveBeenCalledWith('voiceschanged', synth.addEventListener.mock.calls[0][1])
	})

	it('rejects when the browser fails to speak, so the chain can move on', async () => {
		withSynth(fakeSynth({ voices: [{ lang: 'es-ES', name: 'es' }], fail: true }).synth)
		await expect(browserEngine().say('hola', 100)).rejects.toThrow('synthesis-failed')
	})
})
