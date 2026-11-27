import { describe, expect, it, vi } from 'vitest'
import { piperEngine } from '../tts/piper.js'
import { createAnnouncer, enginesFor, warmUp } from './announce.js'
import { speak } from './voice.js'

function setup({ chimeMs = 2200 } = {}) {
	const log = []
	const chime = vi.fn((volume) => { log.push(['chime', volume]); return volume === 0 ? 0 : chimeMs / 1000 })
	// As the real speak(): an empty engine list speaks nothing.
	const speak = vi.fn(async (text, { engines, volume }) => {
		if (engines.length === 0) return null
		log.push(['speak', text, volume])
		return 'piper'
	})
	const wait = vi.fn(async () => {})
	return { announcer: createAnnouncer({ chime, speak, wait, engines: () => ['e'] }), log, chime, speak, wait }
}

describe('createAnnouncer', () => {
	it('rings the chime, waits for it to end, then speaks', async () => {
		const { announcer, log, wait } = setup()
		await announcer.announce({ text: 'Turno Farmacia, preferente 12. Diríjase a Ventanilla 2.', volume: 80, voice: 'mujer' })

		expect(log).toEqual([['chime', 80], ['speak', 'Turno Farmacia, preferente 12. Diríjase a Ventanilla 2.', 80]])
		expect(wait).toHaveBeenCalledWith(2200 + 200)
	})

	it('only rings when the pantalla has no voice', async () => {
		const { announcer, log } = setup()
		await announcer.announce({ text: 'x', volume: 100, voice: 'off' })

		expect(log).toEqual([['chime', 100]])
	})

	it('is silent at volume 0', async () => {
		const { announcer, speak, chime, wait } = setup()
		await announcer.announce({ text: 'x', volume: 0, voice: 'mujer' })

		expect(chime).not.toHaveBeenCalled()
		expect(speak).not.toHaveBeenCalled()
		expect(wait).not.toHaveBeenCalled()
	})

	it('announces calls that arrive together one after another, never over each other', async () => {
		const { announcer, log } = setup()
		await Promise.all([
			announcer.announce({ text: 'primero', volume: 100, voice: 'mujer' }),
			announcer.announce({ text: 'segundo', volume: 100, voice: 'mujer' }),
		])

		expect(log.map((l) => l[0] + ':' + (l[1] ?? ''))).toEqual(['chime:100', 'speak:primero', 'chime:100', 'speak:segundo'])
	})

	it('rings calls that arrive together one after another when the pantalla has no voice, each after its chime ends', async () => {
		const { announcer, log, wait } = setup()
		let release
		const gate = new Promise((r) => { release = r })
		wait.mockImplementation((ms) => { log.push(['wait', ms]); return gate })

		const both = Promise.all([
			announcer.announce({ text: 'x', volume: 100, voice: 'off' }),
			announcer.announce({ text: 'x', volume: 100, voice: 'off' }),
		])
		await new Promise((r) => setTimeout(r))

		expect(log).toEqual([['chime', 100], ['wait', 2400]])

		release()
		await both

		expect(log).toEqual([['chime', 100], ['wait', 2400], ['chime', 100], ['wait', 2400]])
	})

	it('keeps going after a failed announcement', async () => {
		const { announcer, speak } = setup()
		speak.mockRejectedValueOnce(new Error('boom'))
		await announcer.announce({ text: 'primero', volume: 100, voice: 'mujer' }).catch(() => {})
		await announcer.announce({ text: 'segundo', volume: 100, voice: 'mujer' })

		expect(speak).toHaveBeenCalledTimes(2)
	})
})

describe('synthesis overlaps the chime', () => {
	it('starts preparing the voice the moment the call arrives, before the chime has finished', async () => {
		const log = []
		const engine = { name: 'piper', prepare: vi.fn((text) => { log.push('prepare:' + text); return Promise.resolve() }) }
		const announcer = createAnnouncer({
			chime: () => { log.push('chime'); return 2.2 },
			speak: async () => { log.push('speak') },
			wait: async () => { log.push('wait') },
			engines: () => [engine],
		})
		await announcer.announce({ text: 'Turno 1', volume: 100, voice: 'mujer' })

		expect(log).toEqual(['prepare:Turno 1', 'chime', 'wait', 'speak'])
	})

	it('does not fail the announcement when preparing fails: the chain falls back at speaking time', async () => {
		const engine = { name: 'piper', prepare: () => Promise.reject(new Error('no model')) }
		const speak = vi.fn(async () => 'browser')
		const announcer = createAnnouncer({ chime: () => 2.2, speak, wait: async () => {}, engines: () => [engine] })

		await announcer.announce({ text: 'x', volume: 100, voice: 'mujer' })

		expect(speak).toHaveBeenCalled()
	})

	it('does not prepare anything when there is no sound or no voice', async () => {
		const engine = { name: 'piper', prepare: vi.fn(() => Promise.resolve()) }
		const announcer = createAnnouncer({ chime: () => 2.2, speak: async () => {}, wait: async () => {}, engines: () => [engine] })

		await announcer.announce({ text: 'x', volume: 0, voice: 'mujer' })
		await announcer.announce({ text: 'x', volume: 100, voice: 'off' })

		expect(engine.prepare).not.toHaveBeenCalled()
	})
})

describe('a bundled voice that stops answering', () => {
	it('hands the llamado to the browser voice, and the next llamado still rings', async () => {
		vi.useFakeTimers()
		// Loads, then never answers a phrase, as when the phonemizer's data fails to download (#35).
		const worker = { terminate: vi.fn(), postMessage: (m) => { if (m.type === 'init') queueMicrotask(() => worker.onmessage({ data: { type: 'ready' } })) } }
		const piper = piperEngine('mujer', { createWorker: () => worker, sayTimeoutMs: 20000 })
		const browser = { name: 'browser', available: async () => true, say: vi.fn(async () => {}) }
		const chime = vi.fn(() => 1)
		const announcer = createAnnouncer({ chime, speak, wait: async () => {}, engines: () => [piper, browser] })

		const first = announcer.announce({ text: 'Farmacia, 1. Diríjase a ventanilla 2.', volume: 100, voice: 'mujer' })
		const second = announcer.announce({ text: 'Farmacia, 2. Diríjase a ventanilla 2.', volume: 100, voice: 'mujer' })
		await vi.advanceTimersByTimeAsync(30000)
		vi.useRealTimers()

		await first
		await second
		expect(browser.say.mock.calls.map(([text]) => text)).toEqual(['Farmacia, 1. Diríjase a ventanilla 2.', 'Farmacia, 1. Diríjase a ventanilla 2.', 'Farmacia, 2. Diríjase a ventanilla 2.', 'Farmacia, 2. Diríjase a ventanilla 2.'])
		expect(chime).toHaveBeenCalledTimes(2)
	})
})

/** Stubs the global Worker for `run`; answers the voices of the workers it started, and which still run. */
async function withWorkers(run) {
	const workers = []
	vi.stubGlobal('Worker', class {
		constructor() { this.terminated = false; workers.push(this) }
		postMessage(message) { if (message.type === 'init') { this.voice = message.voice; queueMicrotask(() => this.onmessage({ data: { type: 'ready' } })) } }
		terminate() { this.terminated = true }
	})
	try {
		await run(workers)
	} finally {
		vi.unstubAllGlobals()
	}
}

describe('enginesFor', () => {
	it('tries the bundled voice first, and the browser voice after it', () => {
		expect(enginesFor('mujer').map((e) => e.name)).toEqual(['piper', 'browser'])
	})

	it('gives the same engine for the same voice, so its model is loaded once', () => {
		expect(enginesFor('hombre')[0]).toBe(enginesFor('hombre')[0])
		expect(enginesFor('hombre')[0]).not.toBe(enginesFor('mujer')[0])
	})

	it('keeps only the model of the voice asked for last: a voice change ends the previous one\'s worker', () => withWorkers(async (workers) => {
		expect(await enginesFor('mujer')[0].available()).toBe(true)
		expect(await enginesFor('hombre')[0].available()).toBe(true)
		expect(await enginesFor('hombre')[0].available()).toBe(true)

		expect(workers.filter((w) => !w.terminated).map((w) => w.voice)).toEqual(['hombre'])
		expect(workers.filter((w) => w.voice === 'hombre')).toHaveLength(1)
	}))
})

describe('warmUp', () => {
	it('loads the bundled model of the pantalla\'s voice', () => withWorkers(async (workers) => {
		warmUp('mujer')
		await Promise.resolve()

		expect(workers.map((w) => w.voice)).toEqual(['mujer'])
	}))

	it('loads no bundled model for a pantalla with no voice', () => withWorkers(async (workers) => {
		warmUp('off')
		await Promise.resolve()

		expect(workers).toEqual([])
	}))
})

