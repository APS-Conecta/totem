// SPDX-License-Identifier: AGPL-3.0-or-later

import { generateFilePath } from '@nextcloud/router'
import { sharedContext } from '../lib/chime.js'
import { VOICES } from './feeds.js'

const defaultBase = () => generateFilePath('totem', 'js', 'tts/')
const defaultWorker = () => new Worker(new URL('./worker.js', import.meta.url))

/**
 * The bundled voice: a Piper model run in a Web Worker (so the display never freezes while it
 * synthesizes), offline, from files this app ships (docs/adr/0005). It is an engine in the sense of
 * lib/voice.js: `available()` loads the model once and says whether it could, and `say()` plays a
 * phrase. `prepare()` synthesizes ahead, and the engine keeps the audio of the last `maxPhrases`
 * phrases said: the second saying of a call and a recent re-llamado reuse it, an older phrase is
 * synthesized again.
 */
export function piperEngine(voice, { base, createWorker = defaultWorker, getContext = sharedContext, loadTimeoutMs = 60000, maxPhrases = 50 } = {}) {
	let worker = null
	let ready = null
	let nextId = 1
	const pending = new Map()
	const phrases = new Map()

	function start() {
		return new Promise((resolve) => {
			const timer = setTimeout(() => { worker?.terminate(); resolve(false) }, loadTimeoutMs)
			const settle = (ok) => { clearTimeout(timer); resolve(ok) }
			try {
				worker = createWorker()
			} catch {
				settle(false)
				return
			}
			worker.onerror = () => settle(false)
			worker.onmessage = ({ data }) => {
				if (data.type === 'ready') settle(true)
				else if (data.type === 'failed') settle(false)
				else if (data.type === 'audio') pending.get(data.id)?.resolve({ pcm: data.pcm, sampleRate: data.sampleRate })
				else if (data.type === 'error') pending.get(data.id)?.reject(new Error(data.error))
			}
			worker.postMessage({ type: 'init', base: base ?? defaultBase(), model: VOICES[voice] })
		})
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
			ready ??= start()
			return ready
		},
		prepare,
		async say(text, volume) {
			const { pcm, sampleRate } = await prepare(text)
			const ctx = getContext()
			if (ctx.state === 'suspended') ctx.resume()
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
