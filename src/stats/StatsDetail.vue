<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<template>
	<div class="totem-detail totem-side">
		<table class="totem-detail__table">
			<caption class="totem-detail__sr">
				Detalle
			</caption>
			<thead>
				<tr>
					<th />
					<th scope="col">
						Preferente
					</th>
					<th scope="col">
						General
					</th>
					<th scope="col">
						Total
					</th>
				</tr>
			</thead>
			<tbody>
				<tr v-for="line in lines" :key="line.name">
					<th scope="row">
						{{ line.label }}
					</th>
					<td>{{ line.preferente }}</td>
					<td>{{ line.general }}</td>
					<td class="totem-detail__total">
						{{ line.total }}
					</td>
				</tr>
			</tbody>
		</table>
		<p class="totem-detail__note">
			Cada ticket termina en una sola fila: emitidos = atendidos + no se presentó + sin atender.
		</p>
	</div>
</template>

<script>
import { count } from './format.js'

const LINES = [
	{ name: 'emitidos', label: 'Emitidos' },
	{ name: 'atendidos', label: 'Atendidos' },
	{ name: 'noShow', label: 'No se presentó' },
	{ name: 'sinAtender', label: 'Sin atender' },
]

/** One row of the board, or one day, with each outcome split into preferente, general and total. */
export default {
	name: 'StatsDetail',
	props: {
		figure: { type: Object, required: true },
	},
	computed: {
		lines() {
			const { preferente, general, total } = this.figure
			return LINES.map(({ name, label }) => ({
				name,
				label,
				preferente: count(preferente[name]),
				general: count(general[name]),
				total: count(total[name]),
			}))
		},
	},
}
</script>

<style scoped>
.totem-detail {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 4);
}

.totem-detail__table {
	width: 100%;
	border-collapse: collapse;
	font-variant-numeric: tabular-nums;
}

.totem-detail__table th,
.totem-detail__table td {
	padding: calc(var(--default-grid-baseline) * 2);
	text-align: end;
	border-bottom: 1px solid var(--color-border);
}

.totem-detail__table th:first-child {
	text-align: start;
}

.totem-detail__table thead th {
	font-size: var(--font-size-small);
	font-weight: normal;
	color: var(--color-text-maxcontrast);
}

.totem-detail__table tbody th {
	font-weight: 600;
}

.totem-detail__total {
	font-weight: 700;
}

.totem-detail__note {
	margin: 0;
	font-size: var(--font-size-small);
	color: var(--color-text-maxcontrast);
}

.totem-detail__sr {
	position: absolute;
	width: 1px;
	height: 1px;
	overflow: hidden;
	clip: rect(0 0 0 0);
	white-space: nowrap;
}
</style>
