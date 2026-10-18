<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<template>
	<div v-if="asking" class="totem-confirm" role="group" :aria-labelledby="questionId">
		<p :id="questionId" class="totem-confirm__question">
			{{ question }}
		</p>
		<div class="totem-confirm__actions">
			<NcButton variant="error" @click="confirm">
				Sí, eliminar
			</NcButton>
			<NcButton ref="cancel" variant="tertiary" @click="asking = false">
				Cancelar
			</NcButton>
		</div>
	</div>
	<NcButton v-else variant="secondary" @click="ask">
		{{ label }}
	</NcButton>
</template>

<script>
import NcButton from '@nextcloud/vue/components/NcButton'

let instances = 0

/** The inline two-step delete: the button, then "¿…?" with "Sí, eliminar" / "Cancelar". Never a window.confirm. */
export default {
	name: 'ConfirmDelete',
	components: { NcButton },
	props: {
		/** The first-step button ("Eliminar box"). */
		label: { type: String, required: true },
		/** The question the second step asks ("¿Eliminar Box 3?"). */
		question: { type: String, required: true },
	},
	emits: ['confirm'],
	data() {
		return { asking: false, questionId: `totem-confirm-${++instances}` }
	},
	methods: {
		async ask() {
			this.asking = true
			await this.$nextTick()
			// The safe answer gets the focus, so a stray Enter cancels.
			this.$refs.cancel?.$el.focus()
		},
		confirm() {
			this.asking = false
			this.$emit('confirm')
		},
	},
}
</script>
