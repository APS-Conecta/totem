// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Puts everything the bundled voice needs under js/tts/, so the display runs it with no internet
// (docs/adr/0005): the onnxruntime-web runtime, the espeak-ng phonemizer, and the two Piper
// voices. Idempotent: a file already there with the right checksum is left alone. The result is
// The result IS committed (about 160 MB), so a release is self-contained; re-run this only to upgrade.

import { createHash } from 'node:crypto'
import { copyFile, mkdir, readFile, stat, writeFile } from 'node:fs/promises'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'

const root = join(dirname(fileURLToPath(import.meta.url)), '..')
const out = join(root, 'js', 'tts')
const VOICES = 'https://huggingface.co/rhasspy/piper-voices/resolve/main/es'

// Copied from node_modules (versions pinned in package.json).
const RUNTIME = [
	['onnxruntime-web/dist/ort.min.js', 'ort.min.js'],
	['onnxruntime-web/dist/ort-wasm-simd.wasm', 'ort-wasm-simd.wasm'],
	['onnxruntime-web/dist/ort-wasm.wasm', 'ort-wasm.wasm'],
	['@diffusionstudio/piper-wasm/build/piper_phonemize.js', 'piper_phonemize.js'],
	['@diffusionstudio/piper-wasm/build/piper_phonemize.wasm', 'piper_phonemize.wasm'],
	['@diffusionstudio/piper-wasm/build/piper_phonemize.data', 'piper_phonemize.data'],
]

// Downloaded, and verified against the sha256 of the file we listened to and approved.
const MODELS = [
	['es_MX/claude/high/es_MX-claude-high.onnx', '3ef40a71ea63852cd8ab7e6fa7d2ecdcfa67a0b47c9c48e3f10e02ee02083ea0'],
	['es_MX/claude/high/es_MX-claude-high.onnx.json', '1afc81f703c0e4cb3b4d7c0dca096b8b54a98806807f0170cf5eb5557723c12d'],
	['es_ES/davefx/medium/es_ES-davefx-medium.onnx', '6658b03b1a6c316ee4c265a9896abc1393353c2d9e1bca7d66c2c442e222a917'],
	['es_ES/davefx/medium/es_ES-davefx-medium.onnx.json', '0e0dda87c732f6f38771ff274a6380d9252f327dca77aa2963d5fbdf9ec54842'],
]

const sha256 = (buffer) => createHash('sha256').update(buffer).digest('hex')
const exists = (path) => stat(path).then(() => true, () => false)

await mkdir(join(out, 'models'), { recursive: true })

for (const [from, to] of RUNTIME) {
	await copyFile(join(root, 'node_modules', from), join(out, to))
}

for (const [path, expected] of MODELS) {
	const file = join(out, 'models', path.split('/').pop())
	if (await exists(file) && sha256(await readFile(file)) === expected) continue
	const response = await fetch(`${VOICES}/${path}`)
	if (!response.ok) throw new Error(`${path}: HTTP ${response.status}`)
	const bytes = Buffer.from(await response.arrayBuffer())
	if (sha256(bytes) !== expected) throw new Error(`${path}: checksum mismatch, refusing to install it`)
	await writeFile(file, bytes)
	console.log('downloaded', path)
}
console.log('voice assets ready in js/tts/')
