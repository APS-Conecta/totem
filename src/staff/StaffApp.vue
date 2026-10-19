<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<template>
	<NcContent app-name="totem" class="totem-staff">
		<!--
			The default slot, not `#list`: `#list` wraps its content in one <ul>, so several captioned lists
			inside it would nest <ul> in <ul> (epidemiologia's ADR-0005). Each caption labels its own list
			through aria-labelledby; it is not a heading, so the navigation adds no heading level.
		-->
		<NcAppNavigation aria-label="Totem">
			<NcAppNavigationList v-for="(group, index) in GROUPS"
				:key="group"
				:aria-labelledby="`totem-nav-${index}`">
				<NcAppNavigationCaption :name="group" :heading-id="`totem-nav-${index}`" />
				<NcAppNavigationItem v-for="section in inGroup(group)"
					:key="section.id"
					:name="section.name"
					:href="urlFor(section.id)"
					:active="current === section.id"
					@click="go(section.id, $event)">
					<template #icon>
						<component :is="ICONS[section.id]" :size="20" />
					</template>
				</NcAppNavigationItem>
			</NcAppNavigationList>

			<template #footer>
				<NcButton variant="tertiary"
					wide
					:href="totemUrl"
					target="_blank"
					rel="noopener noreferrer">
					<template #icon>
						<TabletCellphone :size="20" />
					</template>
					Abrir tótem
				</NcButton>
			</template>
		</NcAppNavigation>

		<component :is="VIEWS[current]" :key="current" />
	</NcContent>
</template>

<script>
import { loadState } from '@nextcloud/initial-state'
import { generateUrl } from '@nextcloud/router'
import NcAppNavigation from '@nextcloud/vue/components/NcAppNavigation'
import NcAppNavigationCaption from '@nextcloud/vue/components/NcAppNavigationCaption'
import NcAppNavigationItem from '@nextcloud/vue/components/NcAppNavigationItem'
import NcAppNavigationList from '@nextcloud/vue/components/NcAppNavigationList'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcContent from '@nextcloud/vue/components/NcContent'
import ChartBar from 'vue-material-design-icons/ChartBar.vue'
import Desk from 'vue-material-design-icons/Desk.vue'
import FormatListBulleted from 'vue-material-design-icons/FormatListBulleted.vue'
import Monitor from 'vue-material-design-icons/Monitor.vue'
import TabletCellphone from 'vue-material-design-icons/TabletCellphone.vue'
import ViewGridOutline from 'vue-material-design-icons/ViewGridOutline.vue'
import { markRaw } from 'vue'
import BoxesView from '../admin/BoxesView.vue'
import PantallasView from '../admin/PantallasView.vue'
import ServiciosView from '../admin/ServiciosView.vue'
import BoxView from '../box/BoxView.vue'
import StatsView from '../stats/StatsView.vue'
import { navigate, onBack, sectionFrom, urlFor } from './location.js'
import { GROUPS, SECTIONS } from './sections.js'

const ICONS = {
	box: markRaw(Desk),
	estadisticas: markRaw(ChartBar),
	servicios: markRaw(FormatListBulleted),
	boxes: markRaw(ViewGridOutline),
	pantallas: markRaw(Monitor),
}
const VIEWS = {
	box: markRaw(BoxView),
	estadisticas: markRaw(StatsView),
	servicios: markRaw(ServiciosView),
	boxes: markRaw(BoxesView),
	pantallas: markRaw(PantallasView),
}

/**
 * The section the page was opened at: the one the server's route said, refined by the # when that is
 * the configuration (/admin#boxes). If the server said nothing, the address does.
 */
function initialSection() {
	let told = null
	try {
		told = loadState('totem', 'section')
	} catch {
		// No initial state: read the address instead.
	}
	if (!SECTIONS.some((s) => s.id === told)) return sectionFrom(window.location)
	const refined = sectionFrom(window.location, told)
	const inConfiguration = SECTIONS.find((s) => s.id === refined)?.group === 'Configuración'
	return told === 'servicios' && inConfiguration ? refined : told
}

export default {
	name: 'StaffApp',
	components: { NcAppNavigation, NcAppNavigationCaption, NcAppNavigationItem, NcAppNavigationList, NcButton, NcContent, TabletCellphone },
	data() {
		return { GROUPS, ICONS, VIEWS, current: initialSection() }
	},
	computed: {
		totemUrl() {
			return generateUrl('/apps/totem/totem')
		},
	},
	mounted() {
		this.stopListening = onBack((section) => { this.current = section })
	},
	beforeUnmount() {
		this.stopListening?.()
	},
	methods: {
		inGroup(group) {
			return SECTIONS.filter((s) => s.group === group)
		},
		urlFor,
		/** A plain click switches section here; a click with a modifier key is the browser's (a new tab). */
		go(section, event) {
			if (event && (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey || event.button > 0)) return
			event?.preventDefault()
			this.current = section
			navigate(section)
		},
	},
}
</script>

<style>
/*
 * The page's whole look, in Nextcloud's own variables so it follows the theme and dark mode.
 * Unscoped on purpose: the three tabs and their small pieces share these classes.
 */
.totem-staff {
	--totem-target: var(--default-clickable-area, 44px);
	display: flex;
	flex-direction: column;
	gap: 18px;
	padding: 24px 36px;
	max-width: 1400px;
	color: var(--color-main-text);
}

.totem-staff :focus-visible {
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
	.totem-staff {
		padding: 18px;
	}

	.totem-grid {
		grid-template-columns: minmax(0, 1fr);
		gap: 20px;
	}
}
</style>
