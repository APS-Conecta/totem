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
 * phrase. `prepare()` synthesizes ahead, and a phrase is synthesized once however often it is
 * said (the second saying of a call, and any repeat of it, reuse the audio).
 */
export function piperEngine(voice, { base, createWorker = defaultWorker, getContext = sharedContext, loadTimeoutMs = 60000 } = {}) {
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
		if (!phrases.has(text)) {
			const id = nextId++
			const audio = engine.available().then((ok) => {
				if (!ok) throw new Error('la voz incluida no está disponible')
				return new Promise((resolve, reject) => {
					pending.set(id, { resolve, reject })
					worker.postMessage({ type: 'say', id, text })
				}).finally(() => pending.delete(id))
			})
			phrases.set(text, audio)
			audio.catch(() => phrases.delete(text)) // a failed phrase may be tried again
		}
		return phrases.get(text)
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
