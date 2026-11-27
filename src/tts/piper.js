// SPDX-License-Identifier: AGPL-3.0-or-later

import { audioReady, sharedContext } from '../lib/chime.js'

const defaultWorker = () => new Worker(new URL('./worker.js', import.meta.url))

/**
 * The bundled voice: a Piper model run in a Web Worker (so the display never freezes while it
 * synthesizes), offline, from files this app ships (docs/adr/0005). It is an engine in the sense of
 * lib/voice.js: `available()` says whether the bundled voice can speak now, and `say()` plays a
 * phrase. After a failed load, `available()` loads again with a fresh worker, at most once per retry
 * interval and only when asked, without waiting for that load. `prepare()` synthesizes ahead, and
 * the engine keeps the audio of the last `maxPhrases` phrases said: the second saying of a call and
 * a recent re-llamado reuse it, an older phrase is synthesized again. A phrase the worker does not
 * answer within `sayTimeoutMs` of being sent fails, and that worker counts as lost: it is ended and
 * the voice is unavailable until a later load succeeds, under the same retry rule as a failed load.
 *
 * The worker owns its URLs: init carries only the voice key, and the worker derives the model
 * directory from its own location (code-scanning #2 and #3) — nothing a message carries can
 * choose what code or model the worker loads.
 */
export function piperEngine(voice, { createWorker = defaultWorker, getContext = sharedContext, loadTimeoutMs = 60000, maxPhrases = 50, retryIntervalMs = 10 * 60000, sayTimeoutMs = 20000 } = {}) {
	let worker = null
	let ready = null // the first load, replaced by a later load that succeeds or by a lost worker's false
	let retryAt = Infinity // when a failed load may run again: never while one runs, or once one has succeeded
	let nextId = 1
	const pending = new Map()
	const phrases = new Map()

	function start() {
		return new Promise((resolve) => {
			const timer = setTimeout(() => settle(false), loadTimeoutMs)
			let done = false
			const settle = (ok) => {
				if (done) return // past the load, a worker error or stray `failed` ends nothing: only a phrase's time limit does
				done = true
				clearTimeout(timer)
				resolve(ok)
			}
			try {
				worker = createWorker()
				worker.onerror = () => settle(false)
				worker.onmessage = ({ data }) => {
					if (data.type === 'ready') settle(true)
					else if (data.type === 'failed') settle(false)
					else if (data.type === 'audio') pending.get(data.id)?.resolve({ pcm: data.pcm, sampleRate: data.sampleRate })
					else if (data.type === 'error') pending.get(data.id)?.reject(new Error(data.error))
				}
				worker.postMessage({ type: 'init', voice })
			} catch {
				settle(false)
			}
		})
	}

	/** A worker that failed to load or stopped answering is ended; a load after the retry interval replaces it. */
	function lose() {
		worker?.terminate()
		ready = Promise.resolve(false)
		retryAt = performance.now() + retryIntervalMs
	}

	function load() {
		retryAt = Infinity
		const attempt = start().then((ok) => {
			if (ok) ready = attempt
			else lose()
			return ok
		})
		return attempt
	}

	function prepare(text) {
		let audio = phrases.get(text)
		if (audio) phrases.delete(text) // said again: now the most recent
		else {
			const id = nextId++
			audio = engine.available().then((ok) => {
				if (!ok) throw new Error('la voz incluida no está disponible')
				let timer
				return new Promise((resolve, reject) => {
					pending.set(id, { resolve, reject })
					worker.postMessage({ type: 'say', id, text })
					timer = setTimeout(() => {
						lose()
						reject(new Error('the bundled voice did not answer'))
					}, sayTimeoutMs) // from the sending, not from prepare(): the first load may still have been running
				}).finally(() => {
					clearTimeout(timer)
					pending.delete(id)
				})
			})
			audio.catch(() => phrases.delete(text)) // a failed phrase may be tried again
		}
		phrases.set(text, audio)
		if (phrases.size > maxPhrases) phrases.delete(phrases.keys().next().value)
		return audio
	}

	const engine = {
		name: 'piper',
		available() {
			if (ready === null) ready = load() // the first load, which warmUp starts, is awaited by every caller
			else if (performance.now() >= retryAt) load() // a retry is not awaited: until it succeeds, llamados fall back
			return ready
		},
		prepare,
		async say(text, volume) {
			const { pcm, sampleRate } = await prepare(text)
			if (!audioReady(getContext)) throw new Error('sound is locked')
			const ctx = getContext()
			const buffer = ctx.createBuffer(1, pcm.length, sampleRate)
			buffer.getChannelData(0).set(pcm)
			const source = ctx.createBufferSource()
			const gain = ctx.createGain()
			gain.gain.value = Math.min(1, volume / 100)
			source.buffer = buffer
			source.connect(gain)
			gain.connect(ctx.destination)
			await new Promise((resolve) => {
				source.onended = resolve
				source.start()
			})
		},
	}
	return engine
}
