<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<template>
	<div class="totem-outcome">
		<NcNoteCard v-if="error" type="error">
			{{ error }}
		</NcNoteCard>
		<div role="status">
			<NcNoteCard v-if="notice && !error" type="success">
				{{ notice }}
			</NcNoteCard>
		</div>
	</div>
</template>

<script>
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'

/** What the last action did, next to the form: the refusal sentence, or a short confirmation ("Guardado."). */
export default {
	name: 'FormOutcome',
	components: { NcNoteCard },
	props: {
		error: { type: String, default: '' },
		notice: { type: String, default: '' },
	},
}
</script>
