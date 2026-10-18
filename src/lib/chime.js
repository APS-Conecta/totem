// SPDX-License-Identifier: AGPL-3.0-or-later

// Two ding-dongs: four tones between 650 and 1400 Hz (the band people with hearing loss keep
// longest), each with two overtones for bite, through a compressor to lift the average level.
// The ceiling is still the volume of the device: the Pantalla's volume only scales below it.

const NOTES = [[659, 0], [988, 0.42], [659, 1.1], [988, 1.52]] // [Hz, start s]
const OVERTONES = [[1, 1], [2, 0.5], [3, 0.25]] // [multiple, level]
const LENGTH = 2.2
const MASTER = 0.95

let shared = null
const defaultContext = () => (shared ??= new (window.AudioContext || window.webkitAudioContext)())

/** The page's one AudioContext, shared with the voice so a single touch unlocks both. */
export const sharedContext = defaultContext

/**
 * Ring the chime at `volume` (0–100). Answers how many seconds it rings, 0 when it did not
 * (silent volume, or audio unavailable: the visual cue and the caption still announce).
 */
export function playChime(volume, getContext = defaultContext) {
	if (volume <= 0) return 0
	try {
		const ctx = getContext()
		if (ctx.state === 'suspended') ctx.resume()

		const compressor = ctx.createDynamicsCompressor()
		compressor.threshold.value = -24
		compressor.knee.value = 8
		compressor.ratio.value = 10
		compressor.attack.value = 0.003
		compressor.release.value = 0.2
		const master = ctx.createGain()
		master.gain.value = Math.min(1, volume / 100) * MASTER
		compressor.connect(master)
		master.connect(ctx.destination)

		const t0 = ctx.currentTime + 0.05
		for (const [hz, at] of NOTES) {
			for (const [multiple, level] of OVERTONES) {
				const osc = ctx.createOscillator()
				const gain = ctx.createGain()
				const t = t0 + at
				osc.type = 'sine'
				osc.frequency.value = hz * multiple
				gain.gain.setValueAtTime(0.0001, t)
				gain.gain.exponentialRampToValueAtTime(0.5 * level, t + 0.012)
				gain.gain.exponentialRampToValueAtTime(0.0001, t + 0.62)
				osc.connect(gain)
				gain.connect(compressor)
				osc.start(t)
				osc.stop(t + 0.65)
			}
		}
		return LENGTH
	} catch {
		return 0
	}
}

/** Whether the browser lets us make sound yet: a kiosk started with the autoplay flag always can. */
export function audioReady(getContext = defaultContext) {
	try {
		return getContext().state === 'running'
	} catch {
		return false
	}
}

/** Called from a tap: browsers only allow sound after one. Answers whether sound is now possible. */
export async function unlockAudio(getContext = defaultContext) {
	try {
		await getContext().resume()
	} catch {
		// reported by audioReady below
	}
	return audioReady(getContext)
}
