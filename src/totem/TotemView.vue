<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<script setup>
import { loadState } from '@nextcloud/initial-state'
import { computed, nextTick, onBeforeUnmount, ref } from 'vue'
import { issueTurno, messageFrom, publicServicios, warnError } from '../api.js'
import { readableOn } from '../lib/color.js'
import { poll } from '../lib/poll.js'

const establishment = loadState('totem', 'establishment', 'CESFAM')
// The totem this page is (its own address), or null for the default kiosk: its unidades and its printer.
const slug = loadState('totem', 'slug', null)
const servicios = ref([])
const offline = ref(false)
const step = ref('home')
const servicio = ref(null)
const ticket = ref(null)
const issuedAt = ref(new Date())
const left = ref(0)
const issuing = ref(false)
const problem = ref('')
let countdown = null
let idle = null

// The servicios, kept fresh; the same poll tells whether the server can be reached.
let polling = null
function startPolling() {
	polling?.stop()
	polling = poll({
		load: () => publicServicios(slug),
		onData: (list) => {
			servicios.value = list
			offline.value = false
		},
		onOffline: () => {
			offline.value = true
		},
		every: 60000,
	})
}
startPolling()

// A patient may walk away mid-question: the next one must not find their servicio.
function watchIdle() {
	clearTimeout(idle)
	if (step.value === 'question') idle = setTimeout(goHome, 30000)
}

function goHome() {
	clearInterval(countdown)
	step.value = 'home'
	servicio.value = null
	ticket.value = null
	problem.value = ''
}

function choose(s) {
	servicio.value = s
	problem.value = '' // a refusal that arrived after the last patient left is not for this one
	step.value = 'question'
	watchIdle()
}

async function answer(preferente) {
	if (issuing.value) return
	issuing.value = true
	const chosen = servicio.value // the patient may leave the question before the answer arrives
	try {
		ticket.value = await issueTurno(chosen.id, preferente, slug)
	} catch (e) {
		warnError('no se pudo sacar el turno', e)
		if (!e.response) {
			// No answer at all: the network is down, the request was not refused.
			offline.value = true
			startPolling() // look again now rather than in a minute
			return
		}
		problem.value = await messageFrom(e, 'No pudimos entregar su turno. Intente de nuevo.')
		return
	} finally {
		issuing.value = false
	}
	clearTimeout(idle) // the ticket screen has its own countdown
	issuedAt.value = new Date()
	servicio.value = chosen
	step.value = 'ticket'
	left.value = 8
	countdown = setInterval(() => {
		left.value -= 1
		if (left.value <= 0) goHome()
	}, 1000)
	// Printed by the server (true) or not (false): the browser stays out of it. Only a totem without a network
	// printer prints here, and its paper ticket must be in the page before the browser prints it.
	if (browserPrints.value) {
		await nextTick()
		window.print()
	}
}

/** True when this ticket is for the browser to print: the totem has no network printer (`printed` is null). */
const browserPrints = computed(() => ticket.value !== null && (ticket.value.printed ?? null) === null)

const PAGE_SIZE = 7
const page = ref(0)
const pageCount = computed(() => Math.max(1, Math.ceil(servicios.value.length / PAGE_SIZE)))
// A shrinking list must never leave the patient on a page that no longer exists.
const currentPage = computed(() => Math.min(page.value, pageCount.value - 1))
const shown = computed(() => servicios.value.slice(currentPage.value * PAGE_SIZE, (currentPage.value + 1) * PAGE_SIZE))

const now = ref(new Date())
const pad = (n) => String(n).padStart(2, '0')
const issuedOn = computed(() => {
	const d = issuedAt.value
	return `${pad(d.getDate())}-${pad(d.getMonth() + 1)}-${d.getFullYear()} · ${pad(d.getHours())}:${pad(d.getMinutes())}`
})
const clock = computed(() => `${pad(now.value.getHours())}:${pad(now.value.getMinutes())}`)
const clockTimer = setInterval(() => {
	now.value = new Date()
}, 20000)

onBeforeUnmount(() => {
	polling.stop()
	clearInterval(clockTimer)
	clearInterval(countdown)
	clearTimeout(idle)
})
</script>

<template>
	<div class="t-page" @pointerdown="watchIdle">
		<div class="t-root">
			<div class="screen totem">
				<template v-if="step === 'home'">
					<h1 class="t-head">
						Seleccione la unidad a la que acude.
					</h1>
					<div class="t-bars">
						<button
							v-for="s in shown"
							:key="s.id"
							type="button"
							class="t-bar"
							:style="{ background: s.color, color: readableOn(s.color) }"
							@click="choose(s)">
							<span>{{ s.name }}</span><span class="pre">{{ s.prefix }}</span>
						</button>
					</div>
					<div v-if="pageCount > 1" class="t-pager">
						<button type="button" class="t-pagebtn" :disabled="currentPage === 0" @click="page = currentPage - 1">
							Anterior
						</button>
						<button type="button" class="t-pagebtn" :disabled="currentPage >= pageCount - 1" @click="page = currentPage + 1">
							Siguiente
						</button>
					</div>
					<div class="t-foot">
						<span>{{ establishment }}</span><span>{{ clock }}</span>
					</div>
				</template>
				<template v-else-if="step === 'question'">
					<header class="t-band" :style="{ background: servicio.color, color: readableOn(servicio.color) }">
						<span>{{ servicio.name }}</span>
						<button type="button" class="t-back" @click="goHome">
							‹ Volver
						</button>
					</header>
					<div v-if="problem" class="t-error" role="alert">
						<p>{{ problem }}</p>
						<button type="button" class="t-choice" @click="goHome">
							Volver al inicio
						</button>
					</div>
					<div v-if="!problem" class="t-q">
						¿Tiene 60 años o más, una discapacidad o cuida a una persona con discapacidad?
					</div>
					<div v-if="!problem" class="t-choices">
						<button type="button" class="t-choice yes" :disabled="issuing" @click="answer(true)">
							Sí
						</button>
						<button type="button" class="t-choice" :disabled="issuing" @click="answer(false)">
							No
						</button>
					</div>
				</template>
				<main v-else-if="step === 'ticket'" class="t-ticket" :style="{ background: servicio.color, color: readableOn(servicio.color) }">
					<div v-if="ticket.printed !== false" class="lbl">
						Su turno
					</div>
					<div class="num">
						{{ ticket.label }}
					</div>
					<div class="srv">
						{{ ticket.servicio }}
					</div>
					<div v-if="ticket.preferente" class="t-chip">
						Atención preferente
					</div>
					<div v-if="ticket.printed === false" class="t-chip">
						No se pudo imprimir
					</div>
					<div class="inst">
						{{ ticket.printed === false ? 'Acérquese a SOME.' : 'Retire su ticket impreso y espere su llamado en la sala de espera.' }}
					</div>
					<div class="cnt">
						Volviendo al inicio en {{ left }} s
					</div>
				</main>
				<div v-if="offline" class="t-offline" role="alert">
					<h3>Sin conexión</h3>
					<p>Acérquese a SOME.</p>
					<p class="dot">
						Reintentando…
					</p>
				</div>
			</div>
		</div>
		<div v-if="ticket && browserPrints" class="paper">
			<div class="est">
				{{ establishment }}
			</div>
			<div class="big">
				{{ ticket.label }}
			</div>
			<div class="srv">
				{{ ticket.servicio }}
			</div>
			<div v-if="ticket.preferente" class="pref">
				ATENCIÓN PREFERENTE
			</div>
			<hr>
			<div class="fine">
				{{ issuedOn }}
			</div>
			<div class="fine">
				Espere su llamado en la sala de espera. Mire la pantalla y escuche el aviso.
			</div>
		</div>
	</div>
</template>

<style>
/* Every selector sits under .t-page: Nextcloud's core CSS styles `button:not(.button-vue, ...)` (specificity 0,1,1)
   and would otherwise win over our single-class rules and reset every button's font size.
   The totem page: portrait is designed at 1080x1920 and landscape at 1920x1080 (docs/mockups/index.html,
   ADR 0006). Every size is calc(N * var(--k)), one design pixel fitted to the container, so the screen
   fills any display; the layout switches on the container's aspect ratio, never on a pixel width. */
html, body { margin: 0; }
.t-page {
	--font: "Atkinson Hyperlegible", system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
	--ink: #13202b; --paper: #f6f7f4; --violet: #6a12e0; --gold: #f2b134; --err: #b3261e;
	font-family: var(--font);
}
.t-page *, .t-page *::before, .t-page *::after { box-sizing: border-box; }
/* Fixed over everything: Nextcloud's base layout wraps the page in its own padded, themed container. */
.t-page .t-root { position: fixed; inset: 0; z-index: 10000; container: scr / size; }
.t-page .screen { width: 100%; height: 100%; }
.t-page .totem { --k: min(100cqw / 1080, 100cqh / 1920); background: var(--paper); color: var(--ink); font-family: var(--font); display: flex; flex-direction: column; position: relative; overflow: hidden; touch-action: manipulation; user-select: none; -webkit-user-select: none; -webkit-tap-highlight-color: transparent; }
.t-page .totem button:focus-visible { outline: calc(10 * var(--k)) solid var(--ink); outline-offset: calc(-10 * var(--k)); box-shadow: inset 0 0 0 calc(20 * var(--k)) #fff; }
.t-page .totem button:disabled { opacity: .35; cursor: not-allowed; }
.t-page .t-head { margin: 0; padding: calc(64 * var(--k)) calc(72 * var(--k)) calc(36 * var(--k)); font-size: calc(72 * var(--k)); font-weight: 700; line-height: 1.1; }
.t-page .t-bars { flex: 1; display: flex; flex-direction: column; gap: calc(14 * var(--k)); padding: 0 calc(40 * var(--k)); min-height: 0; }
.t-page .t-bar { flex: 1; min-height: 0; line-height: 1.05; text-wrap: balance; border: 0; display: flex; align-items: center; justify-content: space-between; gap: calc(24 * var(--k)); padding: 0 calc(56 * var(--k)); font: inherit; font-size: calc(72 * var(--k)); font-weight: 700; cursor: pointer; text-align: left; }
.t-page .t-bar .pre { font-size: calc(52 * var(--k)); font-weight: 400; letter-spacing: .06em; font-variant-numeric: tabular-nums; }
.t-pager { display: flex; gap: calc(14 * var(--k)); padding: calc(14 * var(--k)) calc(40 * var(--k)) 0; }
.t-pagebtn { flex: 1; font: inherit; font-size: calc(44 * var(--k)); font-weight: 700; background: transparent; border: calc(4 * var(--k)) solid var(--ink); color: var(--ink); min-height: calc(120 * var(--k)); cursor: pointer; }
.t-page .t-foot { padding: calc(28 * var(--k)) calc(72 * var(--k)) calc(44 * var(--k)); font-size: calc(34 * var(--k)); color: #46525e; display: flex; justify-content: space-between; }
.t-page .t-band { padding: calc(36 * var(--k)) calc(72 * var(--k)); display: flex; align-items: center; justify-content: space-between; gap: calc(24 * var(--k)); font-size: calc(64 * var(--k)); font-weight: 700; }
.t-page .t-back { font: inherit; font-size: calc(44 * var(--k)); font-weight: 700; background: transparent; border: calc(4 * var(--k)) solid currentColor; color: inherit; padding: calc(18 * var(--k)) calc(36 * var(--k)); min-height: calc(120 * var(--k)); cursor: pointer; }
.t-page .t-q { padding: calc(56 * var(--k)) calc(72 * var(--k)) calc(40 * var(--k)); font-size: calc(68 * var(--k)); font-weight: 700; line-height: 1.15; }
.t-page .t-choices { padding: 0 calc(72 * var(--k)) calc(72 * var(--k)); display: flex; flex-direction: column; gap: calc(36 * var(--k)); flex: 1; min-height: 0; }
.t-page .t-choice { flex: 1; font: inherit; font-size: calc(76 * var(--k)); font-weight: 700; line-height: 1.1; border: calc(8 * var(--k)) solid var(--ink); background: transparent; color: var(--ink); cursor: pointer; padding: 0 calc(48 * var(--k)); text-align: left; }
.t-page .t-choice.yes { background: var(--violet); border-color: var(--violet); color: #fff; }
.t-page .t-error { display: flex; flex-direction: column; gap: calc(48 * var(--k)); margin: calc(56 * var(--k)) calc(72 * var(--k)) calc(72 * var(--k)); padding: calc(64 * var(--k)); background: var(--err); color: #fff; }
.t-page .t-error p { margin: 0; font-size: calc(68 * var(--k)); font-weight: 700; line-height: 1.15; }
.t-page .t-error .t-choice { flex: none; min-height: calc(160 * var(--k)); background: #fff; border-color: #fff; color: var(--ink); text-align: center; }
@media (prefers-reduced-motion: no-preference) {
	.t-page .t-bar:active, .t-page .t-choice:active { transform: translateY(calc(6 * var(--k))); }
}
.t-page .t-ticket { flex: 1; display: flex; flex-direction: column; justify-content: center; padding: calc(72 * var(--k)); gap: calc(28 * var(--k)); }
.t-page .t-ticket .lbl { font-size: calc(64 * var(--k)); }
.t-page .t-ticket .num { font-size: calc(146 * var(--k)); font-weight: 700; line-height: 1; letter-spacing: .01em; font-variant-numeric: tabular-nums; white-space: nowrap; }
.t-page .t-ticket .srv { font-size: calc(80 * var(--k)); font-weight: 700; }
.t-page .t-chip { align-self: flex-start; background: var(--gold); color: var(--ink); font-size: calc(52 * var(--k)); font-weight: 700; padding: calc(10 * var(--k)) calc(28 * var(--k)); }
.t-page .t-ticket .inst { font-size: calc(56 * var(--k)); line-height: 1.25; max-width: 24ch; }
.t-page .t-ticket .cnt { font-size: calc(44 * var(--k)); }
.t-page .t-offline { position: absolute; inset: 0; z-index: 1; background: var(--ink); color: #fff; display: flex; flex-direction: column; justify-content: center; gap: calc(36 * var(--k)); padding: calc(96 * var(--k)); }
.t-page .t-offline h3 { margin: 0; font-size: calc(120 * var(--k)); line-height: 1.05; }
.t-page .t-offline p { margin: 0; font-size: calc(60 * var(--k)); line-height: 1.25; }
.t-page .t-offline .dot { font-size: calc(44 * var(--k)); color: var(--gold); }
@container scr (aspect-ratio > 1) {
	.t-page .totem { --k: min(100cqw / 1920, 100cqh / 1080); }
	.t-page .t-head { padding: calc(32 * var(--k)) calc(64 * var(--k)) calc(8 * var(--k)); font-size: calc(60 * var(--k)); }
	.t-page .t-bars { display: grid; grid-template-columns: 1fr 1fr; grid-auto-rows: 1fr; gap: calc(16 * var(--k)); padding: 0 calc(40 * var(--k)); }
	.t-page .t-bar { font-size: calc(54 * var(--k)); padding: 0 calc(40 * var(--k)); }
	.t-page .t-bar:last-child:nth-child(odd) { grid-column: 1 / -1; }
	.t-page .t-bar .pre { font-size: calc(40 * var(--k)); }
	.t-pager { padding: calc(10 * var(--k)) calc(40 * var(--k)) 0; }
	.t-pagebtn { font-size: calc(36 * var(--k)); min-height: calc(96 * var(--k)); }
	.t-page .t-foot { padding: calc(14 * var(--k)) calc(64 * var(--k)) calc(22 * var(--k)); font-size: calc(28 * var(--k)); }
	.t-page .t-band { padding: calc(20 * var(--k)) calc(64 * var(--k)); font-size: calc(52 * var(--k)); }
	.t-page .t-back { font-size: calc(36 * var(--k)); min-height: calc(96 * var(--k)); padding: calc(10 * var(--k)) calc(28 * var(--k)); }
	.t-page .t-q { padding: calc(32 * var(--k)) calc(64 * var(--k)) calc(24 * var(--k)); font-size: calc(54 * var(--k)); }
	.t-page .t-choices { flex-direction: row; padding: 0 calc(64 * var(--k)) calc(48 * var(--k)); gap: calc(32 * var(--k)); }
	.t-page .t-choice { font-size: calc(60 * var(--k)); padding: 0 calc(40 * var(--k)); border-width: calc(6 * var(--k)); }
	.t-page .t-error { margin: calc(32 * var(--k)) calc(64 * var(--k)) calc(48 * var(--k)); padding: calc(40 * var(--k)); gap: calc(32 * var(--k)); }
	.t-page .t-error p { font-size: calc(54 * var(--k)); }
	.t-page .t-error .t-choice { min-height: calc(120 * var(--k)); }
	.t-page .t-ticket { padding: calc(40 * var(--k)) calc(96 * var(--k)); gap: calc(14 * var(--k)); }
	.t-page .t-ticket .lbl { font-size: calc(48 * var(--k)); }
	.t-page .t-ticket .num { font-size: calc(270 * var(--k)); }
	.t-page .t-ticket .srv { font-size: calc(64 * var(--k)); }
	.t-page .t-chip { font-size: calc(42 * var(--k)); }
	.t-page .t-ticket .inst { font-size: calc(44 * var(--k)); max-width: 40ch; }
	.t-page .t-ticket .cnt { font-size: calc(36 * var(--k)); }
	.t-page .t-offline { padding: calc(80 * var(--k)); }
	.t-page .t-offline h3 { font-size: calc(110 * var(--k)); }
	.t-page .t-offline p { font-size: calc(52 * var(--k)); }
}

/* The paper ticket exists only for the printer: the 80 mm roll, designed at 384 units wide (--p is one unit). */
.paper { display: none; }
@page { size: 80mm auto; margin: 0; }
@media print {
	html, body { background: #fff; }
	.t-root { display: none; }
	.paper { display: flex; --p: calc(80mm / 384); width: 80mm; background: #fff; color: #000; text-align: center; padding: calc(28 * var(--p)) calc(22 * var(--p)); flex-direction: column; align-items: center; gap: calc(10 * var(--p)); }
	.paper .est { font-size: calc(18 * var(--p)); }
	.paper .big { font-size: calc(66 * var(--p)); font-weight: 700; line-height: 1; margin-top: calc(12 * var(--p)); font-variant-numeric: tabular-nums; white-space: nowrap; }
	.paper .srv { font-size: calc(34 * var(--p)); font-weight: 700; }
	.paper .pref { border: calc(3 * var(--p)) solid #000; padding: calc(4 * var(--p)) calc(14 * var(--p)); font-size: calc(22 * var(--p)); font-weight: 700; }
	.paper hr { width: 100%; border: 0; border-top: calc(2 * var(--p)) dashed #000; margin: calc(10 * var(--p)) 0; }
	.paper .fine { font-size: calc(18 * var(--p)); }
}
</style>
