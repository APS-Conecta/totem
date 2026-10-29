import { describe, expect, it } from 'vitest'
import { PACE, VOICES, feedsFor, ttsBase } from './feeds.js'

const config = {
	audio: { sample_rate: 22050 },
	inference: { noise_scale: 0.667, length_scale: 1, noise_w: 0.8 },
	speaker_id_map: {},
}

describe('feedsFor', () => {
	it('gives the model its phoneme ids, their count and the three scales, slowed a little for a noisy room', () => {
		const feeds = feedsFor(config, [1, 20, 3])

		expect(feeds.input).toEqual({ type: 'int64', data: [1, 20, 3], dims: [1, 3] })
		expect(feeds.input_lengths).toEqual({ type: 'int64', data: [3], dims: [1] })
		expect(feeds.scales.type).toBe('float32')
		expect(feeds.scales.data[0]).toBeCloseTo(0.667, 5)
		expect(feeds.scales.data[1]).toBeCloseTo(1 * PACE, 5)
		expect(feeds.scales.data[2]).toBeCloseTo(0.8, 5)
		expect(PACE).toBeGreaterThan(1)
	})

	it('names no speaker for a single-speaker voice', () => {
		expect(feedsFor(config, [1]).sid).toBeUndefined()
	})

	it('names speaker 0 for a multi-speaker voice', () => {
		const feeds = feedsFor({ ...config, speaker_id_map: { a: 0, b: 1 } }, [1])

		expect(feeds.sid).toEqual({ type: 'int64', data: [0], dims: [1] })
	})
})

describe('VOICES', () => {
	it('maps the two choices of a Pantalla to the models we bundle', () => {
		expect(VOICES).toEqual({ mujer: 'es_MX-claude-high', hombre: 'es_ES-davefx-medium' })
	})
})

describe('ttsBase', () => {
	it('points at the tts/ directory beside whatever bundle the worker runs from', () => {
		expect(ttsBase('https://cloud.example/apps/totem/js/totem-src_tts_worker_js.js')).toBe('https://cloud.example/apps/totem/js/tts/')
	})

	it('survives a Nextcloud installed under a subfolder', () => {
		expect(ttsBase('https://example.org/nextcloud/apps/totem/js/totem-src_tts_worker_js.js')).toBe('https://example.org/nextcloud/apps/totem/js/tts/')
	})
})
