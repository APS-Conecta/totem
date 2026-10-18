// SPDX-License-Identifier: AGPL-3.0-or-later

/** The two voices a Pantalla may choose, and the Piper model each one bundles (docs/adr/0005). */
export const VOICES = { mujer: 'es_MX-claude-high', hombre: 'es_ES-davefx-medium' }

/** length_scale multiplier: a little slower than the model's default, for a noisy waiting room. */
export const PACE = 1.1

/**
 * What a Piper VITS model takes as input, as plain data (the worker turns each into an ort.Tensor).
 * `config` is the model's .onnx.json; `ids` are the phoneme ids the phonemizer produced.
 */
export function feedsFor(config, ids) {
	const feeds = {
		input: { type: 'int64', data: ids, dims: [1, ids.length] },
		input_lengths: { type: 'int64', data: [ids.length], dims: [1] },
		scales: { type: 'float32', data: [config.inference.noise_scale, config.inference.length_scale * PACE, config.inference.noise_w], dims: [3] },
	}
	if (Object.keys(config.speaker_id_map ?? {}).length > 0) {
		feeds.sid = { type: 'int64', data: [0], dims: [1] }
	}
	return feeds
}
