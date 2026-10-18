// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * Ask `load` now and then every `every` ms, handing each result to `onData`.
 * On failure it says `onOffline` once, then retries with the wait doubling up
 * to `maxBackoff`; on the first success after that it says `onOnline` once and
 * returns to the normal rhythm. The display and the totem both live on this.
 */
export function poll({ load, onData, onOffline = () => {}, onOnline = () => {}, every = 2000, maxBackoff = 30000 }) {
	let timer = null
	let stopped = false
	let wait = every
	let offline = false

	async function tick() {
		try {
			const result = await load()
			if (stopped) return
			if (offline) {
				offline = false
				onOnline()
			}
			wait = every
			onData(result)
		} catch {
			if (stopped) return
			if (!offline) {
				offline = true
				onOffline()
			}
			wait = Math.min(wait * 2, maxBackoff)
		}
		if (!stopped) timer = setTimeout(tick, wait)
	}

	tick()

	return {
		stop() {
			stopped = true
			clearTimeout(timer)
		},
	}
}
