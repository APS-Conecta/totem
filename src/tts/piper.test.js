import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { piperEngine } from './piper.js'

/** A worker that answers the way tts/worker.js does: ready after init (`initAfterMs` later, if given), one PCM buffer per say. */
function fakeWorker({ initError = null, initAfterMs = null, never = false } = {}) {
	const sent = []
	const worker = {
		sent,
		onmessage: null,
		onerror: null,
		terminate: vi.fn(),
		postMessage(message) {
			sent.push(message)
			if (never) return
			const initAnswer = () => worker.onmessage({ data: initError ? { type: 'failed', error: initError } : { type: 'ready' } })
			if (message.type === 'init' && initAfterMs !== null) return void setTimeout(initAnswer, initAfterMs)
			queueMicrotask(() => {
				if (message.type === 'init') initAnswer()
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

/** The `say` messages a fake worker received, only those for `text` when given. */
const says = (worker, text) => worker.sent.filter((m) => m.type === 'say' && (text === undefined || m.text === text))

describe('piperEngine', () => {
	let audio
	beforeEach(() => { audio = fakeAudio() })

	const engine = (voice, worker, extra = {}) => piperEngine(voice, { createWorker: () => worker, getContext: () => audio.ctx, ...extra })
	/** An engine for the Mujer voice whose every load takes the next of `workers`. */
	const retrying = (workers, extra = {}) => {
		const createWorker = vi.fn(() => workers.shift())
		return { createWorker, e: piperEngine('mujer', { createWorker, getContext: () => audio.ctx, ...extra }) }
	}

	it('is available once the worker has loaded the model for the pantalla voice', async () => {
		const worker = fakeWorker()

		expect(await engine('mujer', worker).available()).toBe(true)
		expect(worker.sent[0]).toEqual({ type: 'init', voice: 'mujer' })
	})

	it('loads the model of the voice it was asked for: Hombre is Davefx', async () => {
		const worker = fakeWorker()
		await engine('hombre', worker).available()

		expect(worker.sent[0].voice).toBe('hombre')
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

	it('fails a phrase with an English error when the model could not be loaded', async () => {
		await expect(engine('mujer', fakeWorker({ initError: '404' })).prepare('hola')).rejects.toThrow(new Error('the bundled voice is not available'))
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

		expect(says(worker)[0].text).toBe('Turno Farmacia, preferente 12. Diríjase al Box 2.')
		expect(audio.played).toHaveLength(1)
		expect(audio.played[0].buffer.rate).toBe(22050)
	})

	it('synthesizes a phrase once: the second saying of a call reuses it', async () => {
		const worker = fakeWorker()
		const e = engine('mujer', worker)
		await e.available()
		await e.say('hola', 100)
		await e.say('hola', 100)

		expect(says(worker)).toHaveLength(1)
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

		expect(says(worker, 'a')).toHaveLength(2)
	})

	it('keeps at most 50 phrases by default', async () => {
		const worker = fakeWorker()
		const e = engine('mujer', worker)
		await e.available()
		for (let i = 0; i <= 50; i++) e.prepare(`p${i}`)
		await e.say('p0', 100)

		expect(says(worker, 'p0')).toHaveLength(2)
	})

	it('keeps a phrase said again among the most recent, so a re-llamado reuses it', async () => {
		const worker = fakeWorker()
		const e = engine('mujer', worker, { maxPhrases: 2 })
		await e.available()
		for (const text of ['a', 'b', 'a', 'c', 'a']) await e.say(text, 100)

		expect(says(worker, 'a')).toHaveLength(1)
	})

	it('plays a phrase dropped while it was still being synthesized', async () => {
		const worker = fakeWorker()
		const e = engine('mujer', worker, { maxPhrases: 1 })
		await e.available()
		const held = []
		worker.postMessage = (m) => { worker.sent.push(m); held.push(m) }
		const saying = e.say('a', 100)
		await vi.waitFor(() => expect(says(worker, 'a')).toHaveLength(1))
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

		expect(says(worker)).toHaveLength(1)
	})

	it('fails the saying, so the chain can fall back, when the worker reports a synthesis error', async () => {
		const worker = fakeWorker()
		const e = engine('mujer', worker)
		await e.available()
		worker.postMessage = (m) => { worker.sent.push(m); queueMicrotask(() => worker.onmessage({ data: { type: 'error', id: m.id, error: 'phonemizer failed' } })) }

		await expect(e.say('hola', 100)).rejects.toThrow('phonemizer failed')
	})

	it('keeps a loaded worker running when it reports an error later, outside the load', async () => {
		const worker = fakeWorker()
		const e = engine('mujer', worker)
		expect(await e.available()).toBe(true)

		worker.onerror()
		expect(worker.terminate).not.toHaveBeenCalled()
		await e.say('hola', 100)
		expect(audio.played).toHaveLength(1)
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

	it('keeps the newer audio of a phrase when an older synthesis of it, dropped while running, fails', async () => {
		const worker = fakeWorker()
		const e = engine('mujer', worker, { maxPhrases: 1 })
		await e.available()
		worker.postMessage = (m) => { worker.sent.push(m) } // answered by hand below
		const answer = (m) => worker.onmessage({ data: { type: 'audio', id: m.id, pcm: new Float32Array([0, 0.5]), sampleRate: 22050 } })

		const older = e.prepare('a')
		await vi.waitFor(() => expect(says(worker, 'a')).toHaveLength(1))
		e.prepare('b')
		await vi.waitFor(() => expect(says(worker, 'b')).toHaveLength(1))
		answer(says(worker, 'b')[0])
		const newer = e.prepare('a')
		await vi.waitFor(() => expect(says(worker, 'a')).toHaveLength(2))
		worker.onmessage({ data: { type: 'error', id: says(worker, 'a')[0].id, error: 'boom' } })
		await expect(older).rejects.toThrow()
		answer(says(worker, 'a')[1])
		await newer
		worker.postMessage = (m) => { worker.sent.push(m); queueMicrotask(() => answer(m)) } // a further say would be answered

		await e.say('a', 100)
		expect(says(worker, 'a')).toHaveLength(2)
		expect(audio.played).toHaveLength(1)
	})

	describe('disposed, as when the Pantalla changes its voice', () => {
		it('ends its worker, fails a phrase still being synthesized, drops the phrases it kept, and stays unavailable', async () => {
			const worker = fakeWorker()
			const e = engine('mujer', worker)
			await e.say('hola', 100)
			const answer = worker.postMessage.bind(worker)
			worker.postMessage = (m) => { if (m.type !== 'say') answer(m) } // never answers a phrase from here on
			const synthesizing = e.prepare('adiós')
			await Promise.resolve() // the phrase reaches the worker

			e.dispose()

			await expect(synthesizing).rejects.toThrow()
			expect(worker.terminate).toHaveBeenCalledTimes(1)
			await expect(e.say('hola', 100)).rejects.toThrow()
			expect(audio.played).toHaveLength(1)
			expect(await e.available()).toBe(false)
		})

		it('while its model loads: a phrase waiting for the load fails at once and nothing loads again', async () => {
			let created = 0
			const worker = fakeWorker({ never: true })
			const e = piperEngine('mujer', { createWorker: () => { created += 1; return worker }, getContext: () => audio.ctx, retryIntervalMs: 0 })
			const waiting = e.prepare('hola')

			e.dispose()

			await expect(waiting).rejects.toThrow()
			expect(worker.terminate).toHaveBeenCalled()
			expect(await e.available()).toBe(false)
			expect(created).toBe(1)
		})
	})

	describe('a phrase the worker never answers', () => {
		beforeEach(() => vi.useFakeTimers())
		afterEach(() => vi.useRealTimers())

		const sayTimeoutMs = 20000
		const retryIntervalMs = 5 * 60000
		const flush = () => vi.advanceTimersByTimeAsync(0)
		/** Loads as tts/worker.js does but never answers a `say`, as when the phonemizer's data fails to download. */
		function silentWorker() {
			const worker = fakeWorker()
			const answer = worker.postMessage.bind(worker)
			worker.postMessage = (message) => (message.type === 'say' ? worker.sent.push(message) : answer(message))
			return worker
		}
		const outcome = (promise) => {
			const state = { now: 'pending' }
			promise.then(() => { state.now = 'played' }, () => { state.now = 'failed' })
			return state
		}

		it('fails once the time limit has passed since the phrase was sent', async () => {
			const e = engine('mujer', silentWorker(), { sayTimeoutMs })
			await e.available()
			const saying = outcome(e.say('hola', 100))

			await vi.advanceTimersByTimeAsync(sayTimeoutMs - 1)
			expect(saying.now).toBe('pending')
			await vi.advanceTimersByTimeAsync(1)
			expect(saying.now).toBe('failed')
		})

		it('ends the worker, answers unavailable, and loads a fresh one after the retry interval that speaks the phrase again', async () => {
			const first = silentWorker()
			const second = fakeWorker()
			const { createWorker, e } = retrying([first, second], { sayTimeoutMs, retryIntervalMs })
			await e.available()
			const saying = outcome(e.say('hola', 100))
			await vi.advanceTimersByTimeAsync(sayTimeoutMs)

			expect(saying.now).toBe('failed')
			expect(first.terminate).toHaveBeenCalled()
			expect(await e.available()).toBe(false)

			await vi.advanceTimersByTimeAsync(retryIntervalMs)
			expect(await e.available()).toBe(false) // the retry runs; until it has loaded, llamados fall back
			expect(createWorker).toHaveBeenCalledTimes(2)
			await flush()
			expect(await e.available()).toBe(true)
			await e.say('hola', 100)

			expect(says(second, 'hola')).toHaveLength(1)
			expect(audio.played).toHaveLength(1)
		})

		it('starts the clock when the phrase is sent, not while the model is still loading', async () => {
			const worker = fakeWorker({ never: true })
			const e = engine('mujer', worker, { sayTimeoutMs })
			const saying = outcome(e.say('hola', 100))
			await vi.advanceTimersByTimeAsync(15000)
			worker.onmessage({ data: { type: 'ready' } })
			await flush()
			const [{ id }] = says(worker)

			await vi.advanceTimersByTimeAsync(sayTimeoutMs - 1)
			worker.onmessage({ data: { type: 'audio', id, pcm: new Float32Array([0, 0.5]), sampleRate: 22050 } })
			await flush()
			expect(saying.now).toBe('played')
		})

		it('leaves no timer behind once a phrase is answered in time', async () => {
			const e = engine('mujer', fakeWorker(), { sayTimeoutMs })
			await e.available()
			await e.say('hola', 100)

			expect(vi.getTimerCount()).toBe(0)
			await vi.advanceTimersByTimeAsync(sayTimeoutMs)
			expect(audio.played).toHaveLength(1)
		})
	})

	describe('after a failed load', () => {
		beforeEach(() => vi.useFakeTimers())
		afterEach(() => vi.useRealTimers())

		const retryIntervalMs = 5 * 60000
		const flush = () => vi.advanceTimersByTimeAsync(0) // runs every queued microtask; no time passes

		/** After the first load failed: the retry interval passes, `second` loads and speaks, `first` is gone, and no load follows. */
		async function retriesAndSpeaks({ createWorker, e }, first, second) {
			await vi.advanceTimersByTimeAsync(retryIntervalMs)
			expect(await e.available()).toBe(false)
			expect(createWorker).toHaveBeenCalledTimes(2)

			await flush()
			expect(await e.available()).toBe(true)
			await e.say('hola', 100)
			expect(audio.played).toHaveLength(1)
			expect(says(second)).toHaveLength(1)
			expect(says(first)).toHaveLength(0)
			expect(first.terminate).toHaveBeenCalled()

			await vi.advanceTimersByTimeAsync(retryIntervalMs)
			expect(await e.available()).toBe(true)
			expect(createWorker).toHaveBeenCalledTimes(2)
		}

		it('loads again with a fresh worker once the retry interval has passed, and then speaks', async () => {
			const first = fakeWorker({ initError: '404' })
			const second = fakeWorker()
			const subject = retrying([first, second], { retryIntervalMs })
			expect(await subject.e.available()).toBe(false)

			await retriesAndSpeaks(subject, first, second)
		})

		it('keeps a load past its time limit running, and ends it only once the retry interval has passed, for a fresh worker', async () => {
			const first = fakeWorker({ never: true })
			const second = fakeWorker()
			const subject = retrying([first, second], { retryIntervalMs, loadTimeoutMs: 20000 })
			const result = subject.e.available()
			await vi.advanceTimersByTimeAsync(20000)
			expect(await result).toBe(false)
			expect(first.terminate).not.toHaveBeenCalled()

			await vi.advanceTimersByTimeAsync(retryIntervalMs - 1)
			expect(await subject.e.available()).toBe(false)
			expect(first.terminate).not.toHaveBeenCalled()
			expect(subject.createWorker).toHaveBeenCalledTimes(1)

			await retriesAndSpeaks(subject, first, second)
		})

		it('becomes available when a load past its time limit answers ready later, with no second worker', async () => {
			const slow = fakeWorker({ initAfterMs: 90000 })
			const subject = retrying([slow, fakeWorker()], { retryIntervalMs, loadTimeoutMs: 60000 })
			const result = subject.e.available()
			await vi.advanceTimersByTimeAsync(60000)
			expect(await result).toBe(false)

			await vi.advanceTimersByTimeAsync(30000)
			expect(await subject.e.available()).toBe(true)
			await subject.e.say('hola', 100)
			expect(audio.played).toHaveLength(1)

			await vi.advanceTimersByTimeAsync(retryIntervalMs)
			expect(await subject.e.available()).toBe(true)
			expect(subject.createWorker).toHaveBeenCalledTimes(1)
			expect(slow.terminate).not.toHaveBeenCalled()
		})

		it('ends a load past its time limit that then fails, and loads again with a fresh worker after the retry interval', async () => {
			const first = fakeWorker({ initError: 'network', initAfterMs: 30000 })
			const second = fakeWorker()
			const subject = retrying([first, second], { retryIntervalMs, loadTimeoutMs: 20000 })
			const result = subject.e.available()
			await vi.advanceTimersByTimeAsync(20000)
			expect(await result).toBe(false)
			expect(first.terminate).not.toHaveBeenCalled()

			await vi.advanceTimersByTimeAsync(10000)
			expect(first.terminate).toHaveBeenCalled()
			await vi.advanceTimersByTimeAsync(retryIntervalMs - 1)
			expect(await subject.e.available()).toBe(false)
			expect(subject.createWorker).toHaveBeenCalledTimes(1)

			await retriesAndSpeaks(subject, first, second)
		})

		it('creates no new worker however often it is asked within the ten-minute retry interval', async () => {
			const { createWorker, e } = retrying([fakeWorker({ initError: '404' }), fakeWorker()])
			expect(await e.available()).toBe(false)

			const answers = []
			for (let minute = 0; minute < 10; minute++) {
				await vi.advanceTimersByTimeAsync(minute < 9 ? 60000 : 59999)
				answers.push(await e.available(), await e.prepare('x').catch(() => 'rejected'))
			}
			expect(createWorker).toHaveBeenCalledTimes(1)
			expect(answers.every((answer) => answer === false || answer === 'rejected')).toBe(true)

			await vi.advanceTimersByTimeAsync(1)
			e.available()
			expect(createWorker).toHaveBeenCalledTimes(2)
		})

		it('answers unavailable at once while a retry is still loading, and runs one load at a time', async () => {
			const { createWorker, e } = retrying([fakeWorker({ initError: '404' }), fakeWorker({ never: true })], { retryIntervalMs })
			expect(await e.available()).toBe(false)
			await vi.advanceTimersByTimeAsync(retryIntervalMs)

			const settled = []
			e.available().then((ok) => settled.push(ok))
			e.available().then((ok) => settled.push(ok))
			await flush()

			expect(settled).toEqual([false, false])
			expect(createWorker).toHaveBeenCalledTimes(2)
		})

		it('counts a worker that throws when sent the model as a failed load, and loads again later', async () => {
			const first = fakeWorker()
			first.postMessage = (message) => { if (message.type === 'init') throw new Error('DataCloneError') }
			const second = fakeWorker()
			const subject = retrying([first, second], { retryIntervalMs })
			expect(await subject.e.available()).toBe(false)
			expect(first.terminate).toHaveBeenCalled()

			await retriesAndSpeaks(subject, first, second)
		})
	})
})
