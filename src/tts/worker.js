// SPDX-License-Identifier: AGPL-3.0-or-later

// The Piper voice's worker: owns the ONNX model, onnxruntime-web and the espeak-ng phonemizer, so
// the display page never freezes while a phrase is synthesized. Everything is loaded from the files
// the app ships under js/tts/ (nothing from the internet; docs/adr/0005). Not unit-tested: it is
// glue over those libraries, driven for real by e2e/tts.spec.js. The pure parts live in feeds.js.
import { feedsFor, ttsBase, VOICES } from './feeds.js'

let session = null
let config = null

async function load({ voice }) {
	const model = VOICES[voice]
	if (!model) throw new Error(`unknown voice: ${voice}`)
	const base = ttsBase(self.location.href) // from where the worker itself runs, never from a message
	self.base = base // every later `say` phonemizes from here too
	self.importScripts(base + 'ort.min.js', base + 'piper_phonemize.js')
	config = await (await fetch(`${base}models/${model}.onnx.json`)).json()
	self.ort.env.wasm.wasmPaths = base
	self.ort.env.wasm.numThreads = 1 // no SharedArrayBuffer, so no special response headers are needed
	const bytes = new Uint8Array(await (await fetch(`${base}models/${model}.onnx`)).arrayBuffer())
	session = await self.ort.InferenceSession.create(bytes, { executionProviders: ['wasm'] })
}

/** Text to the model's phoneme ids, through the espeak-ng voice the model was trained with. */
function phonemize(text, base) {
	return new Promise((resolve, reject) => {
		self.createPiperPhonemize({
			print: (line) => resolve(JSON.parse(line).phoneme_ids),
			printErr: (line) => reject(new Error(line)),
			locateFile: (file) => (file.endsWith('.wasm') ? base + 'piper_phonemize.wasm' : file.endsWith('.data') ? base + 'piper_phonemize.data' : file),
		}).then((module) => module.callMain(['-l', config.espeak.voice, '--input', JSON.stringify([{ text: text.trim() }]), '--espeak_data', '/espeak-ng-data']))
			.catch(reject)
	})
}

async function synthesize(text, base) {
	const ids = await phonemize(text, base)
	const feeds = {}
	for (const [name, { type, data, dims }] of Object.entries(feedsFor(config, ids))) {
		feeds[name] = new self.ort.Tensor(type, data, dims)
	}
	const { output } = await session.run(feeds)
	return output.data
}

self.onmessage = async ({ data }) => {
	if (data.type === 'init') {
		try {
			await load(data)
			self.postMessage({ type: 'ready' })
		} catch (e) {
			self.postMessage({ type: 'failed', error: String(e) })
		}
	} else if (data.type === 'say') {
		try {
			const pcm = await synthesize(data.text, self.base)
			self.postMessage({ type: 'audio', id: data.id, pcm, sampleRate: config.audio.sample_rate }, [pcm.buffer])
		} catch (e) {
			self.postMessage({ type: 'error', id: data.id, error: String(e) })
		}
	}
}
