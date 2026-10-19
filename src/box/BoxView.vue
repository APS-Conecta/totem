<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<template>
	<NcAppContent>
		<div class="totem-pane totem-box">
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

				<PaneHeader :title="chosen.name" :subtitle="view ? view.box.servicios.join(', ') : ''">
					<template #actions>
						<NcButton v-if="view" @click="showWaiting = true">
							En espera ({{ view.waiting.length }})
						</NcButton>
						<NcButton variant="tertiary" @click="change">
							Cambiar ventanilla
						</NcButton>
					</template>
				</PaneHeader>

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
			</template>
		</div>
	</NcAppContent>

	<NcAppSidebar v-if="chosen && view && showWaiting"
		name="En espera"
		:subname="`${view.waiting.length} ${view.waiting.length === 1 ? 'turno' : 'turnos'}`"
		@close="showWaiting = false">
		<WaitingList :waiting="view.waiting" :next-id="nextId" />
	</NcAppSidebar>
</template>

<script>
import NcAppContent from '@nextcloud/vue/components/NcAppContent'
import NcAppSidebar from '@nextcloud/vue/components/NcAppSidebar'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import { box, messageFrom, warnError } from '../api.js'
import { poll } from '../lib/poll.js'
import PaneHeader from '../staff/PaneHeader.vue'
import WaitingList from './WaitingList.vue'

const STORAGE_KEY = 'totem.box'

/** The sidebar opens by itself where there is room beside the content; on a phone it waits to be asked. */
const isWide = () => !window.matchMedia || window.matchMedia('(min-width: 1024px)').matches

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
	components: { NcAppContent, NcAppSidebar, NcButton, NcNoteCard, PaneHeader, WaitingList },
	data() {
		return { boxes: [], chosen: null, view: null, now: Date.now(), busy: false, error: '', offline: false, showWaiting: isWide() }
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
		/** SIGUIENTE marks the server's pick, and only while nothing is held. */
		nextId() {
			return this.view.current ? null : (this.view.next?.id ?? null)
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
.totem-box__picker {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 2);
	max-width: 60ch;
}

.totem-box__picker label {
	font-size: var(--font-size-small);
	color: var(--color-text-maxcontrast);
}

.totem-box__select {
	box-sizing: border-box;
	width: 100%;
	min-height: var(--default-clickable-area);
	padding-inline: calc(var(--default-grid-baseline) * 3);
	font: inherit;
	color: var(--color-main-text);
	background: var(--color-main-background);
	border: 2px solid var(--color-border-maxcontrast);
	border-radius: var(--border-radius-element);
}

.totem-box__current {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 2);
	padding-block: calc(var(--default-grid-baseline) * 2);
}

.totem-box__num {
	font-size: clamp(3em, 11cqw, 7.5em);
	font-weight: 700;
	line-height: 1;
	font-variant-numeric: tabular-nums;
	white-space: nowrap;
}

.totem-box__none {
	font-size: 2em;
	line-height: 1.2;
	color: var(--color-text-maxcontrast);
}

.totem-box__meta {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: var(--default-grid-baseline) calc(var(--default-grid-baseline) * 3);
	font-size: 1.25em;
	color: var(--color-text-maxcontrast);
}

.totem-box__meta > :last-child {
	flex: 1 1 12em;
	min-width: 0;
}

.totem-box__why {
	margin: 0;
	max-width: 60ch;
	color: var(--color-text-maxcontrast);
}

.totem-box__dot {
	flex: none;
	width: 0.75em;
	height: 0.75em;
	border-radius: 50%;
	border: 1px solid var(--color-border-maxcontrast);
}

.totem-box__primary {
	min-height: calc(var(--default-clickable-area) * 2);
	font-size: clamp(1.25em, 3cqw, 2em);
	font-weight: 700;
}

.totem-box__secondary {
	display: flex;
	flex-wrap: wrap;
	gap: calc(var(--default-grid-baseline) * 3);
}

.totem-box__secondary > * {
	flex: 1 1 12em;
	min-height: calc(var(--default-clickable-area) * 1.5);
	font-size: 1.125em;
}
</style>
