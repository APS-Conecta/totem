// SPDX-License-Identifier: AGPL-3.0-or-later

import { generateFilePath } from '@nextcloud/router'
import { audioReady, sharedContext } from '../lib/chime.js'
import { VOICES } from './feeds.js'

const defaultBase = () => generateFilePath('totem', 'js', 'tts/')
const defaultWorker = () => new Worker(new URL('./worker.js', import.meta.url))

/**
 * The bundled voice: a Piper model run in a Web Worker (so the display never freezes while it
 * synthesizes), offline, from files this app ships (docs/adr/0005). It is an engine in the sense of
 * lib/voice.js: `available()` says whether the bundled voice can speak now, and `say()` plays a
 * phrase. After a failed load, `available()` loads again with a fresh worker, at most once per retry
 * interval and only when asked, without waiting for that load. `prepare()` synthesizes ahead, and
 * the engine keeps the audio of the last `maxPhrases` phrases said: the second saying of a call and
 * a recent re-llamado reuse it, an older phrase is synthesized again.
 */
export function piperEngine(voice, { base, createWorker = defaultWorker, getContext = sharedContext, loadTimeoutMs = 60000, maxPhrases = 50, retryIntervalMs = 10 * 60000 } = {}) {
	let worker = null
	let ready = null // the first load, replaced only by a later load that succeeds
	let retryAt = Infinity // when a failed load may run again: never while one runs, or once one has succeeded
	let nextId = 1
	const pending = new Map()
	const phrases = new Map()

	function start() {
		return new Promise((resolve) => {
			const timer = setTimeout(() => settle(false), loadTimeoutMs)
			let done = false
			const settle = (ok) => {
				if (done) return // only the load phase may end a worker: a later error or stray `failed` changes nothing
				done = true
				clearTimeout(timer)
				if (!ok) worker?.terminate()
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
				worker.postMessage({ type: 'init', base: base ?? defaultBase(), model: VOICES[voice] })
			} catch {
				settle(false)
			}
		})
	}

	function load() {
		retryAt = Infinity
		const attempt = start().then((ok) => {
			if (ok) ready = attempt
			else retryAt = performance.now() + retryIntervalMs
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
				return new Promise((resolve, reject) => {
					pending.set(id, { resolve, reject })
					worker.postMessage({ type: 'say', id, text })
				}).finally(() => pending.delete(id))
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
			if (ready === null) ready = load() // the first load (warmUp) is awaited, as today
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
