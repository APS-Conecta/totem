// SPDX-License-Identifier: AGPL-3.0-or-later

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms))

/**
 * Say `text` twice (a room is noisy and a voice is gone once spoken) with the first engine that
 * can. An engine is `{ name, available(), say(text, volume) }`; an unavailable or failing one
 * hands over to the next. Answers the name of the engine that spoke, or null when none could:
 * the chime, the gold frame and the caption still announce, so this never throws.
 */
export async function speak(text, { engines, volume, pauseMs = 700, times = 2 }) {
	if (volume <= 0) return null
	for (const engine of engines) {
		try {
			if (!(await engine.available())) continue
			for (let i = 0; i < times; i++) {
				if (i > 0) await sleep(pauseMs)
				await engine.say(text, volume)
			}
			return engine.name
		} catch {
			// try the next engine
		}
	}
	return null
}

const spanish = (voices) => voices.find((v) => /^es[-_]CL/i.test(v.lang)) ?? voices.find((v) => /^es/i.test(v.lang)) ?? null

/**
 * The browser's own voice: the last resort. It depends on what the operating system has
 * installed, which on a kiosk is often no Spanish voice at all (docs/adr/0005).
 */
export function browserEngine() {
	const synth = () => globalThis.window?.speechSynthesis
	return {
		name: 'browser',
		async available() {
			return synth() != null && spanish(synth().getVoices()) !== null
		},
		say(text, volume) {
			return new Promise((resolve, reject) => {
				const utterance = new SpeechSynthesisUtterance(text)
				utterance.lang = 'es-CL'
				utterance.voice = spanish(synth().getVoices())
				utterance.rate = 0.85
				utterance.volume = Math.min(1, volume / 100)
				utterance.onend = resolve
				utterance.onerror = (event) => reject(new Error(event.error))
				synth().speak(utterance)
			})
		},
	}
}
