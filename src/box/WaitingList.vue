<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<template>
	<div class="totem-waiting totem-side">
		<ul v-if="waiting.length" class="totem-waiting__rows">
			<li v-for="turno in waiting"
				:key="turno.id"
				class="totem-waiting__row"
				:class="{ 'totem-waiting__row--next': turno.id === nextId }">
				<span :data-kind="turno.preferente ? 'P' : 'G'"
					class="totem-waiting__kind"
					:class="{ 'totem-waiting__kind--p': turno.preferente }">{{ turno.preferente ? 'P' : 'G' }}</span>
				<span>{{ turno.label }}</span>
				<span class="totem-waiting__dot" :style="{ background: turno.color }" />
				<span v-if="turno.id === nextId" class="totem-waiting__tag">SIGUIENTE</span>
				<span class="totem-waiting__waited">{{ turno.waitedMinutes }} min</span>
			</li>
		</ul>
		<p v-else>
			No hay turnos en espera.
		</p>
		<p class="totem-waiting__legend">
			P atención preferente · G atención general
		</p>
	</div>
</template>

<script>
/** The turnos waiting at a ventanilla, oldest first; `nextId` marks the one the server will call next. */
export default {
	name: 'WaitingList',
	props: {
		waiting: { type: Array, required: true },
		nextId: { type: Number, default: null },
	},
}
</script>

<style scoped>
.totem-waiting {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 3);
}

.totem-waiting__rows {
	margin: 0;
	padding: 0;
	list-style: none;
}

.totem-waiting__row {
	display: flex;
	align-items: center;
	gap: calc(var(--default-grid-baseline) * 3);
	min-height: var(--default-clickable-area);
	padding-inline: calc(var(--default-grid-baseline) * 2);
	border-top: 1px solid var(--color-border);
	font-weight: 600;
	font-variant-numeric: tabular-nums;
}

.totem-waiting__row--next {
	border-radius: var(--border-radius-element);
	background: var(--color-primary-element-light);
}

.totem-waiting__kind {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	flex: none;
	width: 1.75em;
	height: 1.75em;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-small, 4px);
	font-size: var(--font-size-small);
	background: var(--color-background-hover);
}

.totem-waiting__kind--p {
	border-color: transparent;
	background: var(--color-warning);
	color: var(--color-warning-text, var(--color-main-text));
}

.totem-waiting__dot {
	flex: none;
	width: 0.75em;
	height: 0.75em;
	border-radius: 50%;
	border: 1px solid var(--color-border-maxcontrast);
}

.totem-waiting__tag {
	font-size: var(--font-size-small);
	color: var(--color-primary-element-text, var(--color-primary-element));
}

.totem-waiting__waited {
	margin-inline-start: auto;
	font-weight: normal;
	color: var(--color-text-maxcontrast);
}

.totem-waiting__legend {
	margin: 0;
	font-size: var(--font-size-small);
	color: var(--color-text-maxcontrast);
}
</style>
