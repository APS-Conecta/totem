<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<template>
	<div class="totem-stats__scroll">
		<table class="totem-stats__table">
			<caption class="totem-stats__sr">
				{{ caption }}
			</caption>
			<thead>
				<tr class="totem-stats__group">
					<th />
					<th class="totem-stats__span" colspan="3" scope="colgroup">
						Tickets emitidos
					</th>
					<th colspan="3" />
				</tr>
				<tr>
					<th scope="col">
						{{ firstHeading }}
					</th>
					<th scope="col">
						Preferente
					</th>
					<th scope="col">
						General
					</th>
					<th scope="col">
						Total
					</th>
					<th scope="col">
						Atendidos
					</th>
					<th scope="col">
						No se presentó
					</th>
					<th scope="col">
						Sin atender
					</th>
				</tr>
			</thead>
			<tbody>
				<tr v-for="row in rows"
					:key="row.key"
					:class="{ 'totem-stats__future': row.future, 'totem-stats__selected': row.key === selectedKey }">
					<th scope="row">
						<template v-if="row.future">
							{{ row.label }}
						</template>
						<NcButton v-else
							variant="tertiary"
							alignment="start"
							:aria-pressed="String(row.key === selectedKey)"
							@click="$emit('select', row.key)">
							<template v-if="row.color" #icon>
								<span class="totem-stats__dot" :style="{ background: row.color }" aria-hidden="true" />
							</template>
							{{ row.label }}
							<span v-if="row.off" class="totem-stats__off">desactivado</span>
						</NcButton>
					</th>
					<td v-for="cell in cellsOf(row)" :key="cell.name" :class="cell.classes">
						{{ cell.text }}
					</td>
				</tr>
			</tbody>
			<tfoot>
				<tr>
					<th scope="row">
						{{ foot.label }}
					</th>
					<td v-for="cell in cellsOf(foot)" :key="cell.name">
						{{ cell.text }}
					</td>
				</tr>
			</tfoot>
		</table>
	</div>
</template>

<script>
import NcButton from '@nextcloud/vue/components/NcButton'
import { count } from './format.js'

/** One row of the board: its figure as the six numbers a person reads, in column order. */
export default {
	name: 'StatsTable',
	components: { NcButton },
	emits: ['select'],
	props: {
		caption: { type: String, required: true },
		firstHeading: { type: String, required: true },
		/** @type {{ key: string|number, label: string, color?: string, off?: boolean, future?: boolean, figure: object }[]} */
		rows: { type: Array, required: true },
		/** @type {{ label: string, figure: object }} */
		foot: { type: Object, required: true },
		/** The key of the row whose detail is open, if any. */
		selectedKey: { type: String, default: null },
	},
	methods: {
		cellsOf({ figure, future }) {
			const total = figure.total
			const numbers = [
				['preferente', figure.preferente.emitidos],
				['general', figure.general.emitidos],
				['total', total.emitidos],
				['atendidos', total.atendidos],
				['noShow', total.noShow],
				['sinAtender', total.sinAtender],
			]
			return numbers.map(([name, n]) => ({
				name,
				// A day that has not happened yet has nothing to count: a dash, not a zero.
				text: future ? '–' : count(n),
				classes: { 'totem-stats__total': name === 'total', 'totem-stats__zero': !future && n === 0 },
			}))
		},
	},
}
</script>

<style scoped>
.totem-stats__scroll {
	overflow-x: auto;
	min-width: 0;
}

.totem-stats__table {
	width: 100%;
	min-width: 40em;
	border-collapse: collapse;
	font-variant-numeric: tabular-nums;
}

.totem-stats__table th,
.totem-stats__table td {
	padding: calc(var(--default-grid-baseline) * 2) calc(var(--default-grid-baseline) * 3);
	text-align: end;
	white-space: nowrap;
	border-bottom: 1px solid var(--color-border);
}

.totem-stats__table thead th {
	font-weight: normal;
	font-size: var(--font-size-small);
	vertical-align: bottom;
	color: var(--color-text-maxcontrast);
}

.totem-stats__table th:first-child,
.totem-stats__table td:first-child {
	text-align: start;
	position: sticky;
	inset-inline-start: 0;
	background: var(--color-main-background);
}

.totem-stats__group th {
	text-align: center;
	border-bottom: 0;
	padding-bottom: 0;
}

.totem-stats__group .totem-stats__span {
	border-bottom: 1px solid var(--color-border);
	padding-bottom: var(--default-grid-baseline);
}

.totem-stats__table tbody th {
	font-weight: 600;
	color: var(--color-main-text);
	white-space: normal;
}

.totem-stats__dot {
	display: inline-block;
	width: 0.75em;
	height: 0.75em;
	border-radius: 50%;
	border: 1px solid var(--color-border-maxcontrast);
}

.totem-stats__off {
	margin-inline-start: calc(var(--default-grid-baseline) * 1.5);
	font-size: var(--font-size-small);
	font-weight: normal;
	color: var(--color-text-maxcontrast);
}

.totem-stats__total {
	font-weight: 700;
}

.totem-stats__selected th,
.totem-stats__selected td {
	background: var(--color-primary-element-light);
}

.totem-stats__zero,
.totem-stats__future th,
.totem-stats__future td {
	color: var(--color-text-maxcontrast);
}

.totem-stats__table tfoot th,
.totem-stats__table tfoot td {
	font-weight: 700;
	color: var(--color-main-text);
	border-top: 2px solid var(--color-main-text);
	border-bottom: 0;
}

.totem-stats__sr {
	position: absolute;
	width: 1px;
	height: 1px;
	overflow: hidden;
	clip: rect(0 0 0 0);
	white-space: nowrap;
}

@container totem-stats (max-width: 520px) {
	.totem-stats__table th,
	.totem-stats__table td {
		padding: calc(var(--default-grid-baseline) * 2);
	}

	.totem-stats__table tbody th {
		min-width: 9em;
	}
}
</style>
