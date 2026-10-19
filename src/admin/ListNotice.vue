<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<template>
	<NcNoteCard v-if="failed" type="error">
		No se pudo cargar la lista de {{ plural }}.
	</NcNoteCard>
	<p v-else-if="empty" class="totem-empty">
		Todavía no hay {{ plural }}. Complete el formulario para crear la primera.
	</p>
</template>

<script>
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'

/** What replaces a list that is missing: it failed to load, or there is nothing in it yet. */
export default {
	name: 'ListNotice',
	components: { NcNoteCard },
	props: {
		/** The plural noun the sentences use ("unidades", "ventanillas", "pantallas"), all feminine. */
		plural: { type: String, required: true },
		failed: { type: Boolean, default: false },
		empty: { type: Boolean, default: false },
	},
}
</script>
