// SPDX-License-Identifier: AGPL-3.0-or-later

import { piperEngine } from '../tts/piper.js'
import { playChime } from './chime.js'
import { browserEngine, speak } from './voice.js'

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms))

let bundled = null // { voice, engine }: the one bundled voice the page keeps loaded
let browser = null

/**
 * The voices to try for a Pantalla's choice, best first: the bundled model, then the browser's own
 * voice (docs/adr/0005). The page keeps one bundled model, the voice asked for last: the same voice
 * gets the same engine, so its model loads once, and another voice disposes of the previous one.
 */
export function enginesFor(voice) {
	if (bundled?.voice !== voice) {
		bundled?.engine.dispose()
		bundled = { voice, engine: piperEngine(voice) }
	}
	return [bundled.engine, (browser ??= browserEngine())]
}

/**
 * How a llamado is announced: the chime, then the voice twice. Announcements queue, so two calls
 * that arrive in one poll are announced one after the other, never over each other, and a failed
 * one never blocks the next. The voice is synthesized WHILE the chime rings, so it is ready when
 * the chime ends.
 */
export function createAnnouncer({ chime = playChime, speak: say = speak, wait = sleep, engines = enginesFor } = {}) {
	let tail = Promise.resolve()

	async function run({ text, volume, voice }) {
		if (volume <= 0) return
		const list = voice === 'off' ? [] : engines(voice)
		list[0]?.prepare?.(text)?.catch(() => {}) // a failure here surfaces at speaking time, where the chain falls back
		const seconds = chime(volume)
		await wait(seconds * 1000 + 200)
		if (voice === 'off') return
		await say(text, { engines: list, volume, pauseMs: 700 })
	}

	return {
		announce(call) {
			const result = tail.catch(() => {}).then(() => run(call))
			tail = result
			return result
		},
	}
}

const shared = createAnnouncer()
export const announce = (call) => shared.announce(call)

/** Load the bundled voice ahead of the first call, so that call is not slowed by loading the model. */
export function warmUp(voice) {
	enginesFor(voice)[0].available()
}

/** Play the chimes and the chosen voice once, as a Pantalla would, so an admin can hear its settings. */
export function testAnnouncement({ volume, voice }) {
	return announce({ text: 'Farmacia, preferente 12. Diríjase a ventanilla 2.', volume, voice })
}
