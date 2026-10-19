<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<template>
	<header class="totem-pane__head">
		<div class="totem-pane__titles">
			<h2>{{ title }}</h2>
			<p v-if="subtitle">
				{{ subtitle }}
			</p>
		</div>
		<div v-if="$slots.actions" class="totem-pane__acts">
			<slot name="actions" />
		</div>
	</header>
</template>

<script>
/** The title of a section, an optional line under it, and its actions: the same header in every section. */
export default {
	name: 'PaneHeader',
	props: {
		title: { type: String, required: true },
		subtitle: { type: String, default: '' },
	},
}
</script>
