<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<!--
	The waiting-room display. Public, read-only, no personal data: it polls the feed and shows the
	latest call large, the earlier ones in a list, and announces each new call (chimes + voice) while
	a gold frame breathes for people who cannot hear. Sized from --k, one design pixel fitted to the
	screen, and laid out by aspect ratio (docs/adr/0006); the approved mockup is docs/mockups/index.html.
-->
<template>
	<div class="stage">
		<div class="screen display">
			<div v-if="current"
				class="d-now"
				:class="{ pulse }"
				role="status"
				:style="{ background: current.color, color: readableOn(current.color) }">
				<div class="srv">{{ current.servicio.toUpperCase() }}</div>
				<div class="num">{{ current.label }}</div>
				<div class="box">→ {{ current.box.toUpperCase() }}</div>
				<div v-if="current.preferente" class="chip">Atención preferente</div>
			</div>
			<div v-else class="d-now d-empty" role="status">
				<div class="srv">Esperando el primer llamado.</div>
			</div>

			<div class="d-list">
				<h3>Últimos llamados</h3>
				<div v-for="row in recent" :key="row.label" class="d-row">
					<span class="sw" :style="{ background: row.color }" /><span>{{ row.label }}</span><span class="b">{{ row.box }}</span>
				</div>
			</div>

			<div class="d-foot">
				<span class="clock">{{ clock }}</span>
				<span v-if="offline" class="d-offline">Sin conexión con el servidor. Reintentando…</span>
				<button v-if="!soundReady" class="d-unlock" type="button" @click="unlock">
					Toque aquí para activar el sonido
				</button>
				<span class="cap">{{ caption }}</span>
			</div>
		</div>
	</div>
</template>

<script setup>
import { loadState } from '@nextcloud/initial-state'
import { computed, onBeforeUnmount, ref } from 'vue'
import { readFeed, warnError } from '../api.js'
import { announce, warmUp } from '../lib/announce.js'
import { audioReady, unlockAudio } from '../lib/chime.js'
import { readableOn } from '../lib/color.js'
import { poll } from '../lib/poll.js'

const PULSE_MS = 6000
const ROWS = 6
const KEEP = 200

const slug = loadState('totem', 'slug', '')
const calls = ref([]) // every llamado of the Día the feed last named, oldest first
const pantalla = ref({ name: '', volume: 100, voice: 'mujer' })
const offline = ref(false)
const pulse = ref(false)
const soundReady = ref(audioReady())
const clock = ref(timeNow())

let cursor = 0
let day = null
let opened = false
let pulseTimer = null

function timeNow() {
	const d = new Date()
	return String(d.getHours()).padStart(2, '0') + ':' + String(d.getMinutes()).padStart(2, '0')
}

const current = computed(() => calls.value.at(-1) ?? null)

/** One row per turno, newest first: a re-llamado moves the turno up instead of adding a row. */
const recent = computed(() => {
	const seen = new Set(current.value ? [current.value.label] : [])
	const rows = []
	for (const call of [...calls.value].reverse()) {
		if (seen.has(call.label)) continue
		seen.add(call.label)
		rows.push(call)
		if (rows.length === ROWS) break
	}
	return rows
})

const caption = computed(() => {
	const { volume, voice } = pantalla.value
	const sound = volume <= 0 ? 'Sonido apagado' : voice === 'off' ? 'Sonido activado' : 'Sonido y voz activados'
	return current.value ? `${sound} · ${current.value.spoken}` : sound
})

function onData(data) {
	pantalla.value = data.pantalla
	cursor = data.cursor
	const held = data.day === day ? calls.value : []
	day = data.day
	calls.value = [...held, ...data.llamados].slice(-KEEP)
	// The first answer is the day so far: show it, never announce it (a reload must not repeat the day).
	if (!opened && data.pantalla.volume > 0 && data.pantalla.voice !== 'off') warmUp(data.pantalla.voice)
	if (opened && data.llamados.length > 0) {
		for (const call of data.llamados) {
			announce({ text: call.spoken, volume: data.pantalla.volume, voice: data.pantalla.voice }).catch((e) => warnError('announce', e))
		}
		pulse.value = true
		clearTimeout(pulseTimer)
		pulseTimer = setTimeout(() => { pulse.value = false }, PULSE_MS)
	}
	opened = true
}

const polling = poll({
	load: () => readFeed(slug, cursor),
	onData,
	onOffline: () => { offline.value = true },
	onOnline: () => { offline.value = false },
	every: 2000,
})
const clockTimer = setInterval(() => { clock.value = timeNow() }, 20000)

async function unlock() {
	soundReady.value = await unlockAudio()
}

onBeforeUnmount(() => {
	polling.stop()
	clearInterval(clockTimer)
	clearTimeout(pulseTimer)
})
</script>

<style>
/* The product's own look (not Nextcloud's): the page is chromeless and fills the screen. */
.stage {
	--ink: #13202b; --gold: #f2b134;
	position: fixed; inset: 0; z-index: 10000; container: scr / size; background: var(--ink); overflow: hidden;
	font-family: "Atkinson Hyperlegible", system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
}
.display {
	--k: min(100cqw / 1920, 100cqh / 1080);
	width: 100%; height: 100%; background: var(--ink); color: #fff; overflow: hidden;
	display: grid; grid-template-columns: minmax(0, 66fr) minmax(0, 34fr); grid-template-rows: minmax(0, 1fr) calc(92 * var(--k));
}
.display * { box-sizing: border-box; }
.d-now { grid-row: 1; min-width: 0; min-height: 0; display: flex; flex-direction: column; justify-content: center; padding: 0 calc(56 * var(--k)); gap: calc(8 * var(--k)); }
.d-now .srv { font-size: calc(68 * var(--k)); font-weight: 700; letter-spacing: .04em; text-wrap: balance; }
.d-now .num { font-size: calc(184 * var(--k)); font-weight: 700; line-height: 1; font-variant-numeric: tabular-nums; white-space: nowrap; }
.d-now .box { font-size: calc(150 * var(--k)); font-weight: 700; line-height: 1.05; text-wrap: balance; overflow-wrap: anywhere; }
.d-now .chip { align-self: flex-start; background: var(--gold); color: var(--ink); font-size: calc(52 * var(--k)); font-weight: 700; padding: calc(8 * var(--k)) calc(26 * var(--k)); margin-top: calc(16 * var(--k)); }
/* New-call cue for people who cannot hear: a gold frame breathing at 1 Hz for 6 s (static under reduced motion). */
.d-now.pulse { animation: ring 1s ease-in-out 6; }
@keyframes ring {
	0%, 100% { box-shadow: inset 0 0 0 calc(10 * var(--k)) var(--gold); }
	50% { box-shadow: inset 0 0 0 calc(36 * var(--k)) var(--gold); }
}
.d-list { grid-row: 1; min-width: 0; min-height: 0; overflow: hidden; padding: calc(48 * var(--k)) calc(40 * var(--k)); display: flex; flex-direction: column; gap: calc(4 * var(--k)); }
.d-list h3 { margin: 0 0 calc(16 * var(--k)); font-size: calc(44 * var(--k)); font-weight: 400; color: #9fb0bf; }
.d-row { display: flex; align-items: center; gap: calc(18 * var(--k)); padding: calc(14 * var(--k)) 0; border-top: calc(2 * var(--k)) solid #2a3b4a; font-size: calc(60 * var(--k)); font-weight: 700; font-variant-numeric: tabular-nums; white-space: nowrap; }
.d-row .sw { width: calc(18 * var(--k)); height: calc(60 * var(--k)); flex: none; }
.d-row .b { margin-left: auto; min-width: 0; overflow: hidden; text-overflow: ellipsis; font-weight: 400; font-size: calc(48 * var(--k)); color: #c9d5df; }
.d-foot { grid-column: 1 / -1; background: #0d1720; display: flex; align-items: center; justify-content: space-between; gap: calc(32 * var(--k)); padding: 0 calc(48 * var(--k)); font-size: calc(34 * var(--k)); color: #9fb0bf; min-width: 0; }
.d-foot .cap { margin-left: auto; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; min-width: 0; }
.d-offline { color: var(--gold); white-space: nowrap; }
/* Under .stage: Nextcloud's core CSS styles buttons with higher specificity than a single class. */
.stage .d-unlock { font: inherit; font-weight: 700; color: var(--ink); background: var(--gold); border: 0; padding: calc(10 * var(--k)) calc(28 * var(--k)); cursor: pointer; min-height: calc(64 * var(--k)); }
@container scr (aspect-ratio <= 1) {
	.display { --k: min(100cqw / 1080, 100cqh / 1920); grid-template-columns: minmax(0, 1fr); grid-template-rows: minmax(0, 56fr) minmax(0, 44fr) calc(84 * var(--k)); }
	.d-now { grid-row: 1; padding: 0 calc(56 * var(--k)); }
	.d-now .srv { font-size: calc(56 * var(--k)); }
	.d-now .num { font-size: calc(150 * var(--k)); }
	.d-now .box { font-size: calc(124 * var(--k)); }
	.d-now .chip { font-size: calc(44 * var(--k)); }
	.d-list { grid-row: 2; padding: calc(36 * var(--k)) calc(56 * var(--k)); }
	.d-list h3 { font-size: calc(38 * var(--k)); }
	.d-row { font-size: calc(56 * var(--k)); padding: calc(12 * var(--k)) 0; }
	.d-foot { grid-row: 3; font-size: calc(30 * var(--k)); padding: 0 calc(40 * var(--k)); }
}
@media (prefers-reduced-motion: reduce) {
	.d-now.pulse { animation: none; box-shadow: inset 0 0 0 calc(24 * var(--k)) var(--gold); }
}
</style>
