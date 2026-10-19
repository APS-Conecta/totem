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
