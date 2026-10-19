<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<template>
	<NcContent app-name="totem">
		<NcAppContent>
			<div class="totem-stats">
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

					<div class="totem-stats__acts">
						<NcButton @click="reload">
							Actualizar
						</NcButton>
						<NcButton v-if="board" variant="primary" :href="csvUrl">
							Descargar CSV
						</NcButton>
					</div>
				</div>

				<NcNoteCard v-if="error" type="error">
					{{ error }}
				</NcNoteCard>
				<p v-else-if="!board" class="totem-stats__state" role="status">
					Cargando…
				</p>

				<template v-else>
					<p v-if="isEmpty" class="totem-stats__state" role="status">
						Sin tickets en este período.
					</p>

					<div class="totem-stats__figures" role="group" aria-label="Totales del período">
						<div v-for="(f, index) in figures" :key="f.label" class="totem-stats__figure" :class="{ 'totem-stats__figure--main': index === 0 }">
							<div class="totem-stats__figure-n">
								{{ f.n }}
							</div>
							<div class="totem-stats__figure-label">
								{{ f.label }}
							</div>
						</div>
					</div>

					<section>
						<h2>Sectores <small>Servicios marcados como sector</small></h2>
						<StatsTable v-if="board.sectores.rows.length"
							caption="Tickets por sector"
							first-heading="Sector"
							:rows="serviciosOf(board.sectores)"
							:foot="{ label: 'Total sectores', figure: board.sectores.total }" />
						<p v-else class="totem-stats__state">
							Ningún servicio está marcado como sector. Márquelo en Administración, en la pestaña Servicios.
						</p>
					</section>

					<section>
						<h2>Otros servicios</h2>
						<StatsTable v-if="board.servicios.rows.length"
							caption="Tickets por servicio"
							first-heading="Servicio"
							:rows="serviciosOf(board.servicios)"
							:foot="{ label: 'Total otros servicios', figure: board.servicios.total }" />
						<p v-else class="totem-stats__state">
							No hay otros servicios.
						</p>
					</section>

					<section v-if="board.period.kind !== 'day'">
						<h2>Por día</h2>
						<StatsTable caption="Tickets por día"
							first-heading="Día"
							:rows="dayRows"
							:foot="{ label: 'Total del período', figure: board.total }" />
					</section>
				</template>

				<p class="totem-stats__note">
					Se cuentan tickets, no personas: quien saca dos tickets cuenta dos veces. No se guarda ningún dato personal.
					“Sin atender” son los tickets que quedaron esperando, llamados o en atención al terminar el día.
				</p>
			</div>
		</NcAppContent>
	</NcContent>
</template>

<script>
import NcAppContent from '@nextcloud/vue/components/NcAppContent'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcContent from '@nextcloud/vue/components/NcContent'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import { messageFrom, stats, warnError } from '../api.js'
import { count, dayLabel, periodLabel } from './format.js'
import StatsTable from './StatsTable.vue'

const KINDS = [
	{ value: 'day', label: 'Día' },
	{ value: 'week', label: 'Semana' },
	{ value: 'month', label: 'Mes' },
]

export default {
	name: 'StatsView',
	components: { NcAppContent, NcButton, NcContent, NcNoteCard, StatsTable },
	data() {
		return { KINDS, kind: 'week', board: null, error: '' }
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
		figures() {
			const { preferente, general, total } = this.board.total
			return [
				['Tickets emitidos', total.emitidos],
				['Preferentes', preferente.emitidos],
				['Generales', general.emitidos],
				['Atendidos', total.atendidos],
				['No se presentó', total.noShow],
				['Sin atender', total.sinAtender],
			].map(([label, n]) => ({ label, n: count(n) }))
		},
		dayRows() {
			const { days, period } = this.board
			return days.map((d) => ({ key: d.day, label: dayLabel(d.day), future: d.day > period.today, figure: d }))
		},
	},
	mounted() {
		this.load('week', undefined)
	},
	methods: {
		serviciosOf(block) {
			return block.rows.map((r) => ({ key: r.id, label: r.name, color: r.color, off: !r.active, figure: r }))
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
.totem-stats {
	container: totem-stats / inline-size;
	padding: 24px 28px 36px;
	display: flex;
	flex-direction: column;
	gap: 26px;
	min-width: 0;
	color: var(--color-main-text);
}

.totem-stats h2 {
	margin: 0 0 4px;
	font-size: 1.25rem;
	display: flex;
	flex-wrap: wrap;
	align-items: baseline;
	gap: 4px 12px;
}

.totem-stats h2 small {
	font-weight: normal;
	font-size: 14px;
	color: var(--color-text-maxcontrast);
}

.totem-stats__bar {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 12px 20px;
}

.totem-stats__seg,
.totem-stats__when,
.totem-stats__acts {
	display: inline-flex;
	align-items: center;
	gap: 8px;
	min-width: 0;
}

.totem-stats__acts {
	margin-inline-start: auto;
	flex-wrap: wrap;
}

.totem-stats__label {
	padding: 0 8px;
	font-size: 1.25rem;
	font-weight: 700;
	text-wrap: balance;
}

.totem-stats__figures {
	display: grid;
	grid-template-columns: repeat(6, minmax(0, 1fr));
	border-top: 3px solid var(--color-main-text);
}

.totem-stats__figure {
	display: flex;
	flex-direction: column;
	gap: 2px;
	min-width: 0;
	padding: 12px 14px 4px;
	border-inline-start: 1px solid var(--color-border);
}

.totem-stats__figure:first-child {
	padding-inline-start: 0;
	border-inline-start: 0;
}

.totem-stats__figure-n {
	font-size: clamp(1.6rem, 4.2cqw, 3rem);
	font-weight: 700;
	line-height: 1.05;
	font-variant-numeric: tabular-nums;
}

.totem-stats__figure--main .totem-stats__figure-n {
	font-size: clamp(2rem, 6cqw, 4rem);
}

.totem-stats__figure-label {
	font-size: 14px;
	color: var(--color-text-maxcontrast);
}

.totem-stats__state {
	margin: 0;
	padding: 18px 20px;
	border: 1px dashed var(--color-border-maxcontrast);
	border-radius: var(--border-radius-element, 8px);
	background: var(--color-background-hover);
	color: var(--color-text-maxcontrast);
}

.totem-stats__note {
	margin: 0;
	max-width: 80ch;
	font-size: 14px;
	color: var(--color-text-maxcontrast);
}

@container totem-stats (max-width: 900px) {
	.totem-stats__acts {
		margin-inline-start: 0;
	}

	.totem-stats__figures {
		grid-template-columns: repeat(3, minmax(0, 1fr));
	}

	.totem-stats__figure:nth-child(4) {
		padding-inline-start: 0;
		border-inline-start: 0;
	}

	.totem-stats__figure:nth-child(n + 4) {
		margin-top: 8px;
		padding-top: 14px;
		border-top: 1px solid var(--color-border);
	}
}

@container totem-stats (max-width: 520px) {
	.totem-stats__figures {
		grid-template-columns: repeat(2, minmax(0, 1fr));
	}

	.totem-stats__figure:nth-child(n) {
		padding-inline-start: 14px;
		border-inline-start: 1px solid var(--color-border);
	}

	.totem-stats__figure:nth-child(odd) {
		padding-inline-start: 0;
		border-inline-start: 0;
	}

	.totem-stats__figure:nth-child(n + 3) {
		margin-top: 8px;
		padding-top: 14px;
		border-top: 1px solid var(--color-border);
	}

	.totem-stats__seg,
	.totem-stats__when,
	.totem-stats__acts {
		width: 100%;
	}

	.totem-stats__seg > *,
	.totem-stats__acts > * {
		flex: 1;
	}

	.totem-stats__when {
		justify-content: space-between;
	}

	.totem-stats__label {
		flex: 1;
		text-align: center;
		font-size: 1.05rem;
	}
}
</style>
