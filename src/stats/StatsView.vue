<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<template>
	<NcAppContent>
		<div class="totem-pane totem-stats">
			<PaneHeader title="Estadísticas">
				<template #actions>
					<NcButton @click="reload">
						Actualizar
					</NcButton>
					<NcButton v-if="board" variant="primary" :href="csvUrl">
						Descargar CSV
					</NcButton>
				</template>
			</PaneHeader>

			<div class="totem-stats__bar">
				<div class="totem-stats__seg" role="group" aria-label="Período">
					<NcButton v-for="option in KINDS"
						:key="option.value"
						:pressed="kind === option.value"
						:variant="kind === option.value ? 'primary' : 'secondary'"
						@click="choose(option.value)">
						{{ option.label }}
					</NcButton>
				</div>

				<div class="totem-stats__when">
					<NcButton aria-label="Período anterior" :disabled="!board" @click="go(board.period.previous)">
						‹
					</NcButton>
					<span class="totem-stats__label" aria-live="polite">{{ board ? label : '' }}</span>
					<NcButton aria-label="Período siguiente" :disabled="!board || board.period.next === null" @click="go(board.period.next)">
						›
					</NcButton>
				</div>
			</div>

			<NcNoteCard v-if="error" type="error">
				{{ error }}
			</NcNoteCard>
			<p v-else-if="!board" class="totem-pane__state" role="status">
				Cargando…
			</p>

			<template v-else>
				<p v-if="isEmpty" class="totem-pane__state" role="status">
					Sin tickets en este período.
				</p>

				<StatsFigures :total="board.total" />

				<section>
					<h3>Sectores <small>Unidades marcadas como sector</small></h3>
					<StatsTable v-if="sectorRows.length"
						caption="Tickets por sector"
						first-heading="Sector"
						:rows="sectorRows"
						:foot="{ label: 'Total sectores', figure: board.sectores.total }"
						:selected-key="selectedKey"
						@select="selectedKey = $event" />
					<p v-else class="totem-pane__state">
						Ninguna unidad está marcada como sector. Márquela en Configuración, en Unidades.
					</p>
				</section>

				<section>
					<h3>Otras unidades</h3>
					<StatsTable v-if="unidadRows.length"
						caption="Tickets por unidad"
						first-heading="Unidad"
						:rows="unidadRows"
						:foot="{ label: 'Total otras unidades', figure: board.servicios.total }"
						:selected-key="selectedKey"
						@select="selectedKey = $event" />
					<p v-else class="totem-pane__state">
						No hay otras unidades.
					</p>
				</section>

				<section v-if="board.period.kind !== 'day'">
					<h3>Por día</h3>
					<StatsTable caption="Tickets por día"
						first-heading="Día"
						:rows="dayRows"
						:foot="{ label: 'Total del período', figure: board.total }"
						:selected-key="selectedKey"
						@select="selectedKey = $event" />
				</section>
			</template>

			<p class="totem-pane__note">
				Se cuentan tickets, no personas: quien saca dos tickets cuenta dos veces. No se guarda ningún dato personal.
				“Sin atender” son los tickets que quedaron esperando, llamados o en atención al terminar el día.
			</p>
		</div>
	</NcAppContent>

	<NcAppSidebar v-if="selected"
		:key="selected.key"
		:name="selected.label"
		:subname="label"
		@close="selectedKey = null">
		<StatsDetail :figure="selected.figure" />
	</NcAppSidebar>
</template>

<script>
import NcAppContent from '@nextcloud/vue/components/NcAppContent'
import NcAppSidebar from '@nextcloud/vue/components/NcAppSidebar'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import { messageFrom, stats, warnError } from '../api.js'
import PaneHeader from '../staff/PaneHeader.vue'
import { dayLabel, periodLabel } from './format.js'
import StatsDetail from './StatsDetail.vue'
import StatsFigures from './StatsFigures.vue'
import StatsTable from './StatsTable.vue'

const KINDS = [
	{ value: 'day', label: 'Día' },
	{ value: 'week', label: 'Semana' },
	{ value: 'month', label: 'Mes' },
]

export default {
	name: 'StatsView',
	components: { NcAppContent, NcAppSidebar, NcButton, NcNoteCard, PaneHeader, StatsDetail, StatsFigures, StatsTable },
	data() {
		return { KINDS, kind: 'week', board: null, error: '', selectedKey: null }
	},
	computed: {
		label() {
			return periodLabel(this.board.period)
		},
		csvUrl() {
			return stats.csvUrl(this.board.period.kind, this.board.period.from)
		},
		isEmpty() {
			return this.board.total.total.emitidos === 0
		},
		sectorRows() {
			return this.unidadRowsOf(this.board.sectores)
		},
		unidadRows() {
			return this.unidadRowsOf(this.board.servicios)
		},
		dayRows() {
			const { days, period } = this.board
			return days.map((d) => ({ key: `d-${d.day}`, label: dayLabel(d.day), future: d.day > period.today, figure: d }))
		},
		/** The row whose detail is open: still shown when the new period has it, gone when it has not (a day). */
		selected() {
			if (!this.board) return null
			return [...this.sectorRows, ...this.unidadRows, ...this.dayRows].find((row) => row.key === this.selectedKey && !row.future) ?? null
		},
	},
	mounted() {
		this.load('week', undefined)
	},
	methods: {
		unidadRowsOf(block) {
			return block.rows.map((r) => ({ key: `u-${r.id}`, label: r.name, color: r.color, off: !r.active, figure: r }))
		},
		choose(kind) {
			this.kind = kind
			this.load(kind, undefined)
		},
		go(date) {
			this.load(this.board.period.kind, date)
		},
		reload() {
			this.load(this.kind, this.board ? this.board.period.from : undefined)
		},
		/** The answer to the latest question is the one shown: an earlier one that arrives late is dropped. */
		async load(kind, date) {
			const ticket = (this.ticket = (this.ticket ?? 0) + 1)
			try {
				const board = await stats.board(kind, date)
				if (ticket !== this.ticket) return
				this.board = board
				this.error = ''
			} catch (exception) {
				if (ticket !== this.ticket) return
				warnError('statistics failed', exception)
				this.board = null
				this.error = await messageFrom(exception, 'No se pudieron cargar las estadísticas. Revise la conexión e intente de nuevo.')
			}
		},
	},
}
</script>
<style scoped>
.totem-stats__bar {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	justify-content: space-between;
	gap: calc(var(--default-grid-baseline) * 3) calc(var(--default-grid-baseline) * 5);
}

.totem-stats__seg,
.totem-stats__when {
	display: inline-flex;
	align-items: center;
	gap: calc(var(--default-grid-baseline) * 2);
	min-width: 0;
}

.totem-stats__label {
	padding-inline: calc(var(--default-grid-baseline) * 2);
	font-size: 1.125em;
	font-weight: 700;
	text-wrap: balance;
}

.totem-stats h3 {
	display: flex;
	flex-wrap: wrap;
	align-items: baseline;
	gap: var(--default-grid-baseline) calc(var(--default-grid-baseline) * 3);
	margin: 0 0 calc(var(--default-grid-baseline) * 2);
	font-size: 1.125em;
}

.totem-stats h3 small {
	font-size: var(--font-size-small);
	font-weight: normal;
	color: var(--color-text-maxcontrast);
}

@container (max-width: 560px) {
	.totem-stats__seg,
	.totem-stats__when {
		width: 100%;
	}

	.totem-stats__seg > * {
		flex: 1;
	}

	.totem-stats__when {
		justify-content: space-between;
	}

	.totem-stats__label {
		flex: 1;
		text-align: center;
		font-size: 1em;
	}
}
</style>
