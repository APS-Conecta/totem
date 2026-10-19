<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<template>
	<div class="totem-stats__figures" role="group" aria-label="Totales del período">
		<div v-for="(figure, index) in figures"
			:key="figure.label"
			class="totem-stats__figure"
			:class="{ 'totem-stats__figure--main': index === 0 }">
			<div class="totem-stats__figure-n">
				{{ figure.n }}
			</div>
			<div class="totem-stats__figure-label">
				{{ figure.label }}
			</div>
		</div>
	</div>
</template>

<script>
import { count } from './format.js'

/** The six numbers of a period, the first one the headline. `total` is the board's whole-period figure. */
export default {
	name: 'StatsFigures',
	props: {
		total: { type: Object, required: true },
	},
	computed: {
		figures() {
			const { preferente, general, total } = this.total
			return [
				['Tickets emitidos', total.emitidos],
				['Preferentes', preferente.emitidos],
				['Generales', general.emitidos],
				['Atendidos', total.atendidos],
				['No se presentó', total.noShow],
				['Sin atender', total.sinAtender],
			].map(([label, n]) => ({ label, n: count(n) }))
		},
	},
}
</script>

<style scoped>
.totem-stats__figures {
	display: grid;
	grid-template-columns: repeat(6, minmax(0, 1fr));
	border-top: 3px solid var(--color-main-text);
}

.totem-stats__figure {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 0.5);
	min-width: 0;
	padding: calc(var(--default-grid-baseline) * 3) calc(var(--default-grid-baseline) * 3) var(--default-grid-baseline);
	border-inline-start: 1px solid var(--color-border);
}

.totem-stats__figure:first-child {
	padding-inline-start: 0;
	border-inline-start: 0;
}

.totem-stats__figure-n {
	font-size: 2.5em;
	font-weight: 700;
	line-height: 1.05;
	font-variant-numeric: tabular-nums;
}

.totem-stats__figure--main .totem-stats__figure-n {
	font-size: 3.5em;
}

.totem-stats__figure-label {
	font-size: var(--font-size-small);
	color: var(--color-text-maxcontrast);
}

@container (max-width: 900px) {
	.totem-stats__figures {
		grid-template-columns: repeat(3, minmax(0, 1fr));
	}

	.totem-stats__figure:nth-child(4) {
		padding-inline-start: 0;
		border-inline-start: 0;
	}

	.totem-stats__figure:nth-child(n + 4) {
		margin-top: calc(var(--default-grid-baseline) * 2);
		padding-top: calc(var(--default-grid-baseline) * 3);
		border-top: 1px solid var(--color-border);
	}
}

@container (max-width: 560px) {
	.totem-stats__figures {
		grid-template-columns: repeat(2, minmax(0, 1fr));
	}

	.totem-stats__figure:nth-child(n) {
		padding-inline-start: calc(var(--default-grid-baseline) * 3);
		border-inline-start: 1px solid var(--color-border);
	}

	.totem-stats__figure:nth-child(odd) {
		padding-inline-start: 0;
		border-inline-start: 0;
	}

	.totem-stats__figure:nth-child(n + 3) {
		margin-top: calc(var(--default-grid-baseline) * 2);
		padding-top: calc(var(--default-grid-baseline) * 3);
		border-top: 1px solid var(--color-border);
	}
}
</style>
