import { describe, expect, it, vi } from 'vitest'
import { createAnnouncer, enginesFor } from './announce.js'

function setup({ chimeMs = 2200 } = {}) {
	const log = []
	const chime = vi.fn((volume) => { log.push(['chime', volume]); return volume === 0 ? 0 : chimeMs / 1000 })
	const speak = vi.fn(async (text, opts) => { log.push(['speak', text, opts.volume]); return 'piper' })
	const wait = vi.fn(async () => {})
	return { announcer: createAnnouncer({ chime, speak, wait, engines: () => ['e'] }), log, chime, speak, wait }
}

describe('createAnnouncer', () => {
	it('rings the chime, waits for it to end, then speaks', async () => {
		const { announcer, log, wait } = setup()
		await announcer.announce({ text: 'Turno Farmacia, preferente 12. Diríjase al Box 2.', volume: 80, voice: 'mujer' })

		expect(log).toEqual([['chime', 80], ['speak', 'Turno Farmacia, preferente 12. Diríjase al Box 2.', 80]])
		expect(wait).toHaveBeenCalledWith(2200 + 200)
	})

	it('only rings when the pantalla has no voice', async () => {
		const { announcer, speak, chime } = setup()
		await announcer.announce({ text: 'x', volume: 100, voice: 'off' })

		expect(chime).toHaveBeenCalled()
		expect(speak).not.toHaveBeenCalled()
	})

	it('is silent at volume 0', async () => {
		const { announcer, speak, chime } = setup()
		await announcer.announce({ text: 'x', volume: 0, voice: 'mujer' })

		expect(chime).not.toHaveBeenCalled()
		expect(speak).not.toHaveBeenCalled()
	})

	it('announces calls that arrive together one after another, never over each other', async () => {
		const { announcer, log } = setup()
		await Promise.all([
			announcer.announce({ text: 'primero', volume: 100, voice: 'mujer' }),
			announcer.announce({ text: 'segundo', volume: 100, voice: 'mujer' }),
		])

		expect(log.map((l) => l[0] + ':' + (l[1] ?? ''))).toEqual(['chime:100', 'speak:primero', 'chime:100', 'speak:segundo'])
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

describe('enginesFor', () => {
	it('tries the bundled voice first, and the browser voice after it', () => {
		expect(enginesFor('mujer').map((e) => e.name)).toEqual(['piper', 'browser'])
	})

	it('gives the same engine for the same voice, so its model is loaded once', () => {
		expect(enginesFor('hombre')[0]).toBe(enginesFor('hombre')[0])
		expect(enginesFor('hombre')[0]).not.toBe(enginesFor('mujer')[0])
	})
})

