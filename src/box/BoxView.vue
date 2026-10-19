<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<template>
	<NcContent app-name="totem">
		<NcAppContent>
			<div class="totem-box">
				<NcNoteCard v-if="error" type="error">
					{{ error }}
				</NcNoteCard>

				<div v-if="!chosen" class="totem-box__picker">
					<label for="totem-box-select">Ventanilla</label>
					<select id="totem-box-select" class="totem-box__select" @change="pick($event.target.value)">
						<option value="">
							Elija su ventanilla
						</option>
						<option v-for="b in boxes" :key="b.id" :value="b.id">
							{{ b.name }}
						</option>
					</select>
				</div>

				<template v-else>
					<NcNoteCard v-if="offline" type="warning">
						Sin conexión con el servidor. Reintentando…
					</NcNoteCard>

					<div class="totem-box__grid">
						<section class="totem-box__main">
							<div class="totem-box__who">
								<span>{{ chosen.name }}<template v-if="view"> · {{ view.box.servicios.join(', ') }}</template></span>
								<NcButton class="totem-box__change" @click="change">
									Cambiar ventanilla
								</NcButton>
							</div>

							<template v-if="view">
								<div class="totem-box__current">
									<!-- Live: a new turno is announced. The ticking clock below is not. -->
									<div aria-live="polite">
										<div v-if="view.current" class="totem-box__num">
											{{ view.current.label }}
										</div>
										<div v-else class="totem-box__none">
											Sin turno en atención.
										</div>
									</div>
									<div v-if="view.current" class="totem-box__meta">
										<span class="totem-box__dot" :style="{ background: view.current.color }" />
										<span>{{ view.current.servicio }}<template v-if="view.current.preferente"> · Atención preferente</template> · {{ view.current.estado === 'called' ? 'Llamado' : 'En atención' }} {{ clock }}</span>
									</div>
								</div>
								<p v-if="!view.current" class="totem-box__why" role="status">
									{{ view.why }}
								</p>

								<NcButton class="totem-box__primary"
									:variant="primary.variant"
									:disabled="primary.disabled || busy"
									wide
									@click="act(primary.action)">
									{{ primary.label }}
								</NcButton>
								<div class="totem-box__secondary">
									<NcButton variant="secondary" :disabled="!isCalled || busy" wide @click="act('recall')">
										Llamar de nuevo
									</NcButton>
									<NcButton variant="secondary" :disabled="!isCalled || busy" wide @click="act('absent')">
										No se presentó
									</NcButton>
								</div>
							</template>
						</section>

						<aside v-if="view" class="totem-box__side">
							<h3>En espera ({{ view.waiting.length }})</h3>
							<ul v-if="view.waiting.length" class="totem-box__rows">
								<li v-for="t in view.waiting"
									:key="t.id"
									class="totem-box__row"
									:class="{ 'totem-box__row--next': isNext(t) }">
									<span :data-kind="t.preferente ? 'P' : 'G'"
										class="totem-box__kind"
										:class="{ 'totem-box__kind--p': t.preferente }">{{ t.preferente ? 'P' : 'G' }}</span>
									<span>{{ t.label }}</span>
									<span class="totem-box__dot" :style="{ background: t.color }" />
									<span v-if="isNext(t)" class="totem-box__tag">SIGUIENTE</span>
									<span class="totem-box__waited">{{ t.waitedMinutes }} min</span>
								</li>
							</ul>
							<p v-else>
								No hay turnos en espera.
							</p>
							<p class="totem-box__legend">
								P atención preferente · G atención general
							</p>
						</aside>
					</div>
				</template>
			</div>
		</NcAppContent>
	</NcContent>
</template>

<script>
import NcAppContent from '@nextcloud/vue/components/NcAppContent'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcContent from '@nextcloud/vue/components/NcContent'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import { box, messageFrom, warnError } from '../api.js'
import { poll } from '../lib/poll.js'

const STORAGE_KEY = 'totem.box'

/** Per-viewer convenience only: storage can be blocked, so every access is guarded. */
function remember(id) {
	try {
		if (id === null) localStorage.removeItem(STORAGE_KEY)
		else localStorage.setItem(STORAGE_KEY, String(id))
	} catch { /* the page works without it */ }
}

function remembered() {
	try {
		return localStorage.getItem(STORAGE_KEY)
	} catch {
		return null
	}
}

export default {
	name: 'BoxView',
	components: { NcAppContent, NcButton, NcContent, NcNoteCard },
	data() {
		return { boxes: [], chosen: null, view: null, now: Date.now(), busy: false, error: '', offline: false }
	},
	async mounted() {
		try {
			this.boxes = await box.boxes()
		} catch (exception) {
			warnError('box list failed', exception)
			this.error = 'No se pudo cargar la lista de ventanillas. Recargue la página.'
			return
		}
		const stored = this.boxes.find((b) => String(b.id) === remembered())
		if (stored) this.select(stored)
		this.ticker = setInterval(() => { this.now = Date.now() }, 1000)
	},
	beforeUnmount() {
		this.stopPolling()
		clearInterval(this.ticker)
	},
	computed: {
		isCalled() {
			return this.view?.current?.estado === 'called'
		},
		/** The one big button: its label, look and availability follow the estado. */
		primary() {
			const current = this.view.current
			if (!current) {
				return this.view.next
					? { action: 'call', label: 'Llamar siguiente', variant: 'primary', disabled: false }
					: { action: 'call', label: 'No hay turnos en espera', variant: 'primary', disabled: true }
			}
			return current.estado === 'called'
				? { action: 'start', label: 'Iniciar atención', variant: 'primary', disabled: false }
				: { action: 'finish', label: 'Finalizar atención', variant: 'success', disabled: false }
		},
		clock() {
			const seconds = Math.max(0, Math.floor(this.now / 1000) - this.view.current.since)
			return String(Math.floor(seconds / 60)).padStart(2, '0') + ':' + String(seconds % 60).padStart(2, '0')
		},
	},
	methods: {
		pick(value) {
			const found = this.boxes.find((b) => String(b.id) === value)
			if (found) this.select(found)
		},
		select(found) {
			this.chosen = found
			remember(found.id)
			this.startPolling(found.id)
		},
		async act(action) {
			if (this.busy) return
			const id = this.chosen.id
			this.busy = true
			this.error = ''
			try {
				const answer = await box.act(id, action)
				if (this.chosen?.id === id) this.show(answer)
			} catch (exception) {
				warnError('box action failed', exception)
				const message = await messageFrom(exception, 'No se pudo completar la acción. Intente de nuevo.')
				if (this.chosen?.id !== id) return
				this.error = message
				// Another box may have taken the turno: show what is true now.
				try {
					const fresh = await box.state(id)
					if (this.chosen?.id === id) this.show(fresh)
				} catch { /* the poll reports a lost connection */ }
			} finally {
				if (this.chosen?.id === id) this.busy = false
			}
		},
		change() {
			this.stopPolling()
			this.chosen = null
			this.view = null
			this.offline = false
			this.busy = false
			this.error = ''
		},
		/** SIGUIENTE marks the server's pick, and only while nothing is held. */
		isNext(turno) {
			return !this.view.current && this.view.next?.id === turno.id
		},
		show(state) {
			this.now = Date.now()
			this.view = state
		},
		startPolling(id) {
			this.stopPolling()
			this.poller = poll({
				load: () => box.state(id),
				onData: (state) => this.show(state),
				onOffline: () => { this.offline = true },
				onOnline: () => { this.offline = false },
				every: 3000,
			})
		},
		stopPolling() {
			this.poller?.stop()
			this.poller = null
		},
	},
}
</script>

<style scoped>
/* Container query, not a media query: Nextcloud's own content area sets the width. */
.totem-box {
	container-type: inline-size;
	min-height: 100%;
	padding: 12px;
	box-sizing: border-box;
	color: var(--color-main-text);
}

.totem-box__picker {
	display: flex;
	flex-direction: column;
	gap: 8px;
	max-width: 420px;
	padding: 28px 24px;
	font-size: 18px;
}

.totem-box__select {
	min-height: 44px;
	padding: 0 12px;
	font: inherit;
	color: var(--color-main-text);
	background: var(--color-main-background);
	border: 2px solid var(--color-border-maxcontrast);
	border-radius: var(--border-radius-element, 8px);
}

.totem-box__select:focus-visible {
	outline: 2px solid var(--color-main-text);
	outline-offset: 2px;
}

.totem-box__grid {
	display: grid;
	grid-template-columns: minmax(0, 1fr) minmax(240px, 340px);
	min-height: 100%;
}

.totem-box__main {
	display: flex;
	flex-direction: column;
	gap: 22px;
	min-width: 0;
	padding: 16px 24px;
}

.totem-box__who {
	display: flex;
	flex-wrap: wrap;
	justify-content: space-between;
	align-items: center;
	gap: 8px;
	color: var(--color-text-maxcontrast);
	font-size: 18px;
}

.totem-box__change,
.totem-box__main :deep(.button-vue) {
	min-height: 44px;
}

.totem-box__current {
	display: flex;
	flex: 1;
	flex-direction: column;
	justify-content: center;
	gap: 6px;
	min-height: 120px;
}

.totem-box__num {
	font-size: clamp(48px, 11cqw, 124px);
	font-weight: 700;
	line-height: 1;
	font-variant-numeric: tabular-nums;
	white-space: nowrap;
}

.totem-box__none {
	font-size: 34px;
	color: var(--color-text-maxcontrast);
}

.totem-box__meta {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 4px 12px;
	font-size: 22px;
	color: var(--color-text-maxcontrast);
	font-variant-numeric: tabular-nums;
}

.totem-box__why {
	min-height: 54px;
	margin: 0;
	font-size: 17px;
	line-height: 1.35;
}

.totem-box__dot {
	display: inline-block;
	flex: none;
	width: 14px;
	height: 14px;
	border-radius: 50%;
}

.totem-box__primary {
	min-height: 120px;
	font-size: clamp(24px, 3.4cqw, 40px);
	font-weight: 700;
}

.totem-box__secondary {
	display: flex;
	flex-wrap: wrap;
	gap: 16px;
}

.totem-box__secondary > * {
	flex: 1 1 200px;
	min-height: 72px;
	font-size: 24px;
	font-weight: 700;
}

.totem-box__side {
	display: flex;
	flex-direction: column;
	gap: 10px;
	min-width: 0;
	padding: 16px 24px;
	background: var(--color-background-hover);
	border-inline-start: 1px solid var(--color-border);
}

.totem-box__side h3 {
	margin: 0 0 6px;
	font-size: 20px;
}

.totem-box__rows {
	margin: 0;
	padding: 0;
	list-style: none;
}

.totem-box__row {
	display: flex;
	align-items: center;
	gap: 12px;
	min-height: 44px;
	padding: 10px 0;
	border-top: 1px solid var(--color-border);
	font-size: 18px;
	font-weight: 700;
	font-variant-numeric: tabular-nums;
}

.totem-box__row--next {
	padding-inline: 6px;
	background: var(--color-primary-element-light);
	outline: 2px solid var(--color-primary-element);
	outline-offset: -2px;
}

.totem-box__tag {
	font-size: 12px;
	font-weight: 700;
	color: var(--color-primary-element-light-text);
}

.totem-box__waited {
	margin-inline-start: auto;
	font-weight: 400;
	color: var(--color-text-maxcontrast);
	white-space: nowrap;
}

/* The gold preferente marker is the same literal on every screen of the app. */
.totem-box__kind {
	display: inline-flex;
	flex: none;
	align-items: center;
	justify-content: center;
	width: 26px;
	height: 26px;
	border-radius: 4px;
	font-size: 15px;
	font-weight: 700;
	background: var(--color-background-dark);
	color: var(--color-main-text);
}

.totem-box__kind--p {
	background: #f2b134;
	color: #13202b;
}

.totem-box__legend {
	margin: auto 0 0;
	font-size: 14px;
	color: var(--color-text-maxcontrast);
}

@container (max-width: 900px) {
	.totem-box__grid {
		display: block;
	}

	.totem-box__main {
		padding: 20px 18px;
	}

	.totem-box__side {
		border-inline-start: 0;
		border-top: 1px solid var(--color-border);
	}

	.totem-box__primary {
		min-height: 96px;
	}
}
</style>
