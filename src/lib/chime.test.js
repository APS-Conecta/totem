import { beforeEach, describe, expect, it, vi } from 'vitest'
import { audioReady, playChime, unlockAudio } from './chime.js'

/** A recording stand-in for the WebAudio graph: the test reads what was scheduled, not how. */
function fakeAudio({ state = 'running' } = {}) {
	const oscillators = []
	const gains = []
	const ctx = {
		state,
		currentTime: 10,
		destination: {},
		resume: vi.fn(() => { ctx.state = 'running'; return Promise.resolve() }),
		createDynamicsCompressor: () => ({ threshold: {}, knee: {}, ratio: {}, attack: {}, release: {}, connect: vi.fn() }),
		createGain: () => {
			const g = { gain: { value: 1, setValueAtTime: vi.fn(), exponentialRampToValueAtTime: vi.fn() }, connect: vi.fn() }
			gains.push(g)
			return g
		},
		createOscillator: () => {
			const o = { type: '', frequency: { value: 0 }, connect: vi.fn(), start: vi.fn(), stop: vi.fn() }
			oscillators.push(o)
			return o
		},
	}
	return { ctx, oscillators, gains }
}

describe('playChime', () => {
	beforeEach(() => vi.restoreAllMocks())

	it('rings two ding-dongs: four tones, each with two overtones', () => {
		const { ctx, oscillators } = fakeAudio()
		playChime(100, () => ctx)

		expect(oscillators).toHaveLength(12)
		const fundamentals = [...new Set(oscillators.map((o) => o.frequency.value).filter((f) => f < 1000))]
		expect(fundamentals.sort()).toEqual([659, 988].sort())
		expect(oscillators.every((o) => o.start.mock.calls.length === 1)).toBe(true)
	})

	it('keeps every tone inside the band people with hearing loss keep longest', () => {
		const { ctx, oscillators } = fakeAudio()
		playChime(100, () => ctx)

		const fundamentals = oscillators.slice(0, 12).filter((_, i) => i % 3 === 0).map((o) => o.frequency.value)
		expect(Math.min(...fundamentals)).toBeGreaterThanOrEqual(650)
		expect(Math.max(...fundamentals)).toBeLessThanOrEqual(1400)
	})

	it('answers how long it rings so the voice can follow', () => {
		const { ctx } = fakeAudio()
		expect(playChime(100, () => ctx)).toBeCloseTo(2.2, 5)
	})

	it('scales the master level by the pantalla volume', () => {
		const loud = fakeAudio()
		const quiet = fakeAudio()
		playChime(100, () => loud.ctx)
		playChime(40, () => quiet.ctx)

		const master = (a) => a.gains.find((g) => g.connect.mock.calls.some(([d]) => d === a.ctx.destination))
		expect(master(loud).gain.value).toBeCloseTo(0.95, 5)
		expect(master(quiet).gain.value).toBeCloseTo(0.38, 5)
	})

	it('is silent at volume 0', () => {
		const { ctx, oscillators } = fakeAudio()

		expect(playChime(0, () => ctx)).toBe(0)
		expect(oscillators).toHaveLength(0)
	})

	it('wakes a suspended audio context', () => {
		const { ctx } = fakeAudio({ state: 'suspended' })
		playChime(100, () => ctx)

		expect(ctx.resume).toHaveBeenCalled()
	})

	it('says it could not ring when audio is unavailable, instead of throwing', () => {
		expect(playChime(100, () => { throw new Error('no audio') })).toBe(0)
	})
})

describe('audio unlocking', () => {
	it('is ready when the audio context is running', () => {
		expect(audioReady(() => fakeAudio({ state: 'running' }).ctx)).toBe(true)
	})

	it('is not ready while the browser holds the context suspended until someone touches the page', () => {
		expect(audioReady(() => fakeAudio({ state: 'suspended' }).ctx)).toBe(false)
	})

	it('is not ready where audio does not exist', () => {
		expect(audioReady(() => { throw new Error('no audio') })).toBe(false)
	})

	it('a touch wakes the context, after which it is ready', async () => {
		const { ctx } = fakeAudio({ state: 'suspended' })

		expect(await unlockAudio(() => ctx)).toBe(true)
		expect(ctx.resume).toHaveBeenCalled()
	})
})
