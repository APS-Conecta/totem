<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<template>
	<NcContent app-name="totem">
		<NcAppContent>
			<div class="totem-admin">
				<div class="totem-admin__tabs" role="tablist">
					<button v-for="tab in TABS"
						:key="tab.id"
						:id="`totem-tab-${tab.id}`"
						type="button"
						role="tab"
						class="totem-admin__tab"
						:aria-selected="String(current === tab.id)"
						:aria-controls="`totem-panel-${tab.id}`"
						:tabindex="current === tab.id ? 0 : -1"
						@click="current = tab.id"
						@keydown="onKey">
						{{ tab.label }}
					</button>
				</div>
				<div :id="`totem-panel-${current}`"
					role="tabpanel"
					:aria-labelledby="`totem-tab-${current}`">
					<ServiciosTab v-if="current === 'servicios'" />
					<BoxesTab v-else-if="current === 'boxes'" />
					<PantallasTab v-else-if="current === 'pantallas'" />
				</div>
			</div>
		</NcAppContent>
	</NcContent>
</template>

<script>
import NcAppContent from '@nextcloud/vue/components/NcAppContent'
import NcContent from '@nextcloud/vue/components/NcContent'
import BoxesTab from './BoxesTab.vue'
import PantallasTab from './PantallasTab.vue'
import ServiciosTab from './ServiciosTab.vue'

const TABS = [
	{ id: 'servicios', label: 'Servicios' },
	{ id: 'boxes', label: 'Boxes' },
	{ id: 'pantallas', label: 'Pantallas' },
]

export default {
	name: 'AdminView',
	components: { BoxesTab, NcAppContent, NcContent, PantallasTab, ServiciosTab },
	data() {
		return { TABS, current: 'servicios' }
	},
	methods: {
		/** The WAI-ARIA tabs keys: arrows wrap around, Home and End jump to the ends. */
		async onKey(event) {
			const index = TABS.findIndex((tab) => tab.id === this.current)
			const target = {
				ArrowRight: (index + 1) % TABS.length,
				ArrowLeft: (index - 1 + TABS.length) % TABS.length,
				Home: 0,
				End: TABS.length - 1,
			}[event.key]
			if (target === undefined) return
			event.preventDefault()
			this.current = TABS[target].id
			await this.$nextTick()
			this.$el.querySelector(`#totem-tab-${this.current}`).focus()
		},
	},
}
</script>

<style>
/*
 * The page's whole look, in Nextcloud's own variables so it follows the theme and dark mode.
 * Unscoped on purpose: the three tabs and their small pieces share these classes.
 */
.totem-admin {
	--totem-target: var(--default-clickable-area, 44px);
	display: flex;
	flex-direction: column;
	gap: 18px;
	padding: 24px 36px;
	max-width: 1400px;
	color: var(--color-main-text);
}

.totem-admin :focus-visible {
	outline: 2px solid var(--color-main-text);
	outline-offset: 2px;
	box-shadow: 0 0 0 4px var(--color-main-background);
}

.totem-admin__tabs {
	display: flex;
	flex-wrap: wrap;
	gap: 6px;
	border-bottom: 2px solid var(--color-border);
}

.totem-admin__tab {
	min-height: var(--totem-target);
	padding: 0 18px;
	font: inherit;
	font-size: 18px;
	color: var(--color-text-maxcontrast);
	background: transparent;
	border: 0;
	border-bottom: 4px solid transparent;
	margin-bottom: -2px;
	cursor: pointer;
}

.totem-admin__tab[aria-selected="true"] {
	font-weight: 700;
	color: var(--color-main-text);
	border-bottom-color: var(--color-primary-element);
}

.totem-grid {
	display: grid;
	grid-template-columns: minmax(0, 1fr) minmax(300px, 440px);
	gap: 32px;
	align-items: start;
}

.totem-scroll {
	overflow-x: auto;
	min-width: 0;
}

.totem-table {
	width: 100%;
	min-width: 480px;
	border-collapse: collapse;
	font-size: 17px;
}

.totem-table th {
	text-align: start;
	font-weight: 400;
	color: var(--color-text-maxcontrast);
	padding: 6px 8px;
	border-bottom: 1px solid var(--color-border-dark);
}

.totem-table td {
	padding: 4px 8px;
	border-bottom: 1px solid var(--color-border);
}

.totem-table tr.totem-selected td {
	background: var(--color-primary-element-light);
}

.totem-empty {
	margin: 0 0 12px;
	color: var(--color-text-maxcontrast);
}

.totem-dot {
	display: inline-block;
	width: 14px;
	height: 14px;
	border-radius: 50%;
	border: 1px solid var(--color-border-maxcontrast);
}

.totem-form {
	display: flex;
	flex-direction: column;
	gap: 12px;
	min-width: 0;
	padding: 18px 20px;
	border: 1px solid var(--color-border-dark);
	border-radius: var(--border-radius-container, 10px);
}

.totem-form > .button-vue {
	align-self: flex-start;
}

.totem-row {
	display: flex;
	flex-wrap: wrap;
	gap: 12px;
}

.totem-row > * {
	flex: 1 1 120px;
	min-width: 0;
}

.totem-label {
	display: flex;
	flex-direction: column;
	gap: 4px;
	font-size: 14px;
	color: var(--color-text-maxcontrast);
}

.totem-input {
	box-sizing: border-box;
	min-height: var(--totem-target);
	min-width: 0;
	padding: 6px 10px;
	font: inherit;
	font-size: 17px;
	color: var(--color-main-text);
	background: var(--color-main-background);
	border: 2px solid var(--color-border-maxcontrast);
	border-radius: var(--border-radius-element, 8px);
}

.totem-input--color {
	width: 64px;
	padding: 2px;
}

.totem-input--range {
	width: 100%;
	padding: 0;
	accent-color: var(--color-primary-element);
}

.totem-color {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 12px;
}

.totem-chip {
	padding: 6px 14px;
	font-weight: 700;
	letter-spacing: .06em;
	font-variant-numeric: tabular-nums;
	border-radius: var(--border-radius-element, 8px);
	border: 1px solid var(--color-border-maxcontrast);
}

.totem-ratio {
	font-size: 14px;
}

.totem-ratio--good {
	color: var(--color-success-text);
}

.totem-ratio--bad {
	color: var(--color-error-text);
	font-weight: 700;
}

.totem-upper input {
	text-transform: uppercase;
}

.totem-fieldset {
	display: flex;
	flex-wrap: wrap;
	gap: 0 16px;
	margin: 0;
	padding: 8px 12px;
	border: 1px solid var(--color-border-dark);
	border-radius: var(--border-radius-element, 8px);
}

.totem-fieldset legend {
	padding: 0 4px;
	font-size: 14px;
	color: var(--color-text-maxcontrast);
}

.totem-address-row {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 8px;
}

.totem-address {
	flex: 1 1 200px;
	min-width: 0;
	padding: 8px 10px;
	font-size: 15px;
	color: var(--color-main-text);
	background: var(--color-background-dark);
	border-radius: var(--border-radius-element, 8px);
	overflow-wrap: anywhere;
	user-select: all;
}

.totem-confirm__question {
	margin: 0 0 8px;
	font-weight: 700;
}

.totem-confirm__actions {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
}

@media (max-width: 900px) {
	.totem-admin {
		padding: 18px;
	}

	.totem-grid {
		grid-template-columns: minmax(0, 1fr);
		gap: 20px;
	}
}
</style>
