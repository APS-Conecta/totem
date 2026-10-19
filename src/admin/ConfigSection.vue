<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<template>
	<NcAppContent>
		<div class="totem-pane" :class="{ 'totem-pane--docked': editing }">
			<PaneHeader :title="title" :subtitle="subtitle">
				<template #actions>
					<NcButton variant="primary" @click="$emit('new')">
						{{ newLabel }}
					</NcButton>
				</template>
			</PaneHeader>
			<slot name="list" />
		</div>
	</NcAppContent>

	<NcAppSidebar v-if="editing"
		:key="sidebarKey"
		:name="sidebarName"
		:subname="sidebarSubname"
		@close="$emit('close')">
		<div class="totem-side">
			<slot name="form" />
		</div>
	</NcAppSidebar>
</template>

<script>
import NcAppContent from '@nextcloud/vue/components/NcAppContent'
import NcAppSidebar from '@nextcloud/vue/components/NcAppSidebar'
import NcButton from '@nextcloud/vue/components/NcButton'
import PaneHeader from '../staff/PaneHeader.vue'

/**
 * The chrome the three configuration lists share: the title, the "new" button, the list, and the sidebar
 * that holds the form of the record being edited. The view owns the data; this owns the layout.
 */
export default {
	name: 'ConfigSection',
	components: { NcAppContent, NcAppSidebar, NcButton, PaneHeader },
	props: {
		title: { type: String, required: true },
		subtitle: { type: String, default: '' },
		newLabel: { type: String, required: true },
		/** True while a record (new or existing) is open in the sidebar. */
		editing: { type: Boolean, default: false },
		sidebarName: { type: String, default: '' },
		sidebarSubname: { type: String, default: '' },
		/** Changes with the record, so the sidebar remounts and a half-armed confirmation cannot cross over. */
		sidebarKey: { type: [String, Number], default: 'new' },
	},
	emits: ['new', 'close'],
}
</script>
