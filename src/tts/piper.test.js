import { beforeEach, describe, expect, it, vi } from 'vitest'
import { piperEngine } from './piper.js'

/** A worker that answers the way tts/worker.js does: ready after init, one PCM buffer per say. */
function fakeWorker({ initError = null, never = false } = {}) {
	const sent = []
	const worker = {
		sent,
		onmessage: null,
		onerror: null,
		terminate: vi.fn(),
		postMessage(message) {
			sent.push(message)
			if (never) return
			queueMicrotask(() => {
				if (message.type === 'init') worker.onmessage({ data: initError ? { type: 'failed', error: initError } : { type: 'ready' } })
				if (message.type === 'say') worker.onmessage({ data: { type: 'audio', id: message.id, pcm: new Float32Array([0, 0.5, -0.5, 0]), sampleRate: 22050 } })
			})
		},
	}
	return worker
}

/** A WebAudio stand-in that records what was played and ends the sound by itself, but only while it runs. */
function fakeAudio({ state = 'running' } = {}) {
	const played = []
	const ctx = {
		state,
		destination: {},
		resume: vi.fn(),
		createBuffer: (channels, length, rate) => ({ channels, length, rate, getChannelData: () => new Float32Array(length) }),
		createGain: () => ({ gain: { value: 1 }, connect: vi.fn() }),
		createBufferSource: () => {
			const source = { buffer: null, connect: vi.fn(), start: vi.fn(() => { played.push(source); if (ctx.state === 'running') queueMicrotask(() => source.onended?.()) }) }
			return source
		},
	}
	return { ctx, played }
}

describe('piperEngine', () => {
	let audio
	beforeEach(() => { audio = fakeAudio() })

	const engine = (voice, worker, extra = {}) => piperEngine(voice, { base: '/apps/totem/js/tts/', createWorker: () => worker, getContext: () => audio.ctx, ...extra })

	it('is available once the worker has loaded the model for the pantalla voice', async () => {
		const worker = fakeWorker()

		expect(await engine('mujer', worker).available()).toBe(true)
		expect(worker.sent[0]).toEqual({ type: 'init', base: '/apps/totem/js/tts/', model: 'es_MX-claude-high' })
	})

	it('loads the model of the voice it was asked for: Hombre is Davefx', async () => {
		const worker = fakeWorker()
		await engine('hombre', worker).available()

		expect(worker.sent[0].model).toBe('es_ES-davefx-medium')
	})

	it('loads the model only once however often it is asked', async () => {
		const worker = fakeWorker()
		const e = engine('mujer', worker)
		await e.available()
		await e.available()

		expect(worker.sent.filter((m) => m.type === 'init')).toHaveLength(1)
	})

	it('is unavailable when the worker cannot load the model (files missing, no WASM)', async () => {
		expect(await engine('mujer', fakeWorker({ initError: '404' })).available()).toBe(false)
	})

	it('is unavailable when loading takes too long, so the browser voice can take over', async () => {
		vi.useFakeTimers()
		const e = engine('mujer', fakeWorker({ never: true }), { loadTimeoutMs: 20000 })
		const result = e.available()
		await vi.advanceTimersByTimeAsync(20000)

		expect(await result).toBe(false)
		vi.useRealTimers()
	})

	it('speaks by synthesizing in the worker and playing the audio at the pantalla volume', async () => {
		const worker = fakeWorker()
		const e = engine('mujer', worker)
		await e.available()
		await e.say('Turno Farmacia, preferente 12. Diríjase al Box 2.', 60)

		expect(worker.sent.find((m) => m.type === 'say').text).toBe('Turno Farmacia, preferente 12. Diríjase al Box 2.')
		expect(audio.played).toHaveLength(1)
		expect(audio.played[0].buffer.rate).toBe(22050)
	})

	it('synthesizes a phrase once: the second saying of a call reuses it', async () => {
		const worker = fakeWorker()
		const e = engine('mujer', worker)
		await e.available()
		await e.say('hola', 100)
		await e.say('hola', 100)

		expect(worker.sent.filter((m) => m.type === 'say')).toHaveLength(1)
		expect(audio.played).toHaveLength(2)
	})

	it('synthesizes again a phrase no longer among the last ones said', async () => {
		const worker = fakeWorker()
		const e = engine('mujer', worker, { maxPhrases: 2 })
		await e.available()
		e.prepare('a')
		e.prepare('b')
		e.prepare('c')
		await e.say('a', 100)

		expect(worker.sent.filter((m) => m.type === 'say' && m.text === 'a')).toHaveLength(2)
	})

	it('keeps at most 50 phrases by default', async () => {
		const worker = fakeWorker()
		const e = engine('mujer', worker)
		await e.available()
		for (let i = 0; i <= 50; i++) e.prepare(`p${i}`)
		await e.say('p0', 100)

		expect(worker.sent.filter((m) => m.type === 'say' && m.text === 'p0')).toHaveLength(2)
	})

	it('keeps a phrase said again among the most recent, so a re-llamado reuses it', async () => {
		const worker = fakeWorker()
		const e = engine('mujer', worker, { maxPhrases: 2 })
		await e.available()
		for (const text of ['a', 'b', 'a', 'c', 'a']) await e.say(text, 100)

		expect(worker.sent.filter((m) => m.type === 'say' && m.text === 'a')).toHaveLength(1)
	})

	it('plays a phrase dropped while it was still being synthesized', async () => {
		const worker = fakeWorker()
		const e = engine('mujer', worker, { maxPhrases: 1 })
		await e.available()
		const held = []
		worker.postMessage = (m) => { worker.sent.push(m); held.push(m) }
		const saying = e.say('a', 100)
		await vi.waitFor(() => expect(worker.sent.some((m) => m.type === 'say' && m.text === 'a')).toBe(true))
		e.prepare('b')
		const { id } = held.find((m) => m.text === 'a')
		worker.onmessage({ data: { type: 'audio', id, pcm: new Float32Array([0, 0.5]), sampleRate: 22050 } })
		await saying

		expect(audio.played).toHaveLength(1)
	})

	it('can synthesize ahead, so the voice is ready when the chime ends', async () => {
		const worker = fakeWorker()
		const e = engine('mujer', worker)
		await e.available()
		await e.prepare('hola')
		await e.say('hola', 100)

		expect(worker.sent.filter((m) => m.type === 'say')).toHaveLength(1)
	})

	it('fails the saying, so the chain can fall back, when the worker reports a synthesis error', async () => {
		const worker = fakeWorker()
		const e = engine('mujer', worker)
		await e.available()
		worker.postMessage = (m) => { worker.sent.push(m); queueMicrotask(() => worker.onmessage({ data: { type: 'error', id: m.id, error: 'phonemizer failed' } })) }

		await expect(e.say('hola', 100)).rejects.toThrow('phonemizer failed')
	})

	it('fails the saying at once and plays nothing while the browser holds the sound back', async () => {
		audio = fakeAudio({ state: 'suspended' })
		const e = engine('mujer', fakeWorker())
		await e.available()

		await expect(e.say('hola', 100)).rejects.toThrow()
		expect(audio.played).toHaveLength(0)
	})

	it('forgets a failed phrase so the next call may try again', async () => {
		const worker = fakeWorker()
		const e = engine('mujer', worker)
		await e.available()
		const ok = worker.postMessage.bind(worker)
		worker.postMessage = (m) => { worker.sent.push(m); queueMicrotask(() => worker.onmessage({ data: { type: 'error', id: m.id, error: 'boom' } })) }
		await expect(e.say('hola', 100)).rejects.toThrow()
		worker.postMessage = ok

		await e.say('hola', 100)
		expect(audio.played).toHaveLength(1)
	})
})
