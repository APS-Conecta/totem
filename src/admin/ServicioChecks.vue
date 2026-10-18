<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<template>
	<fieldset class="totem-fieldset">
		<legend>{{ legend }}</legend>
		<NcCheckboxRadioSwitch v-for="servicio in servicios"
			:key="servicio.id"
			:model-value="modelValue.includes(servicio.id)"
			@update:model-value="toggle(servicio.id, $event)">
			{{ servicio.name }}
		</NcCheckboxRadioSwitch>
	</fieldset>
</template>

<script>
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'

/** One checkbox per servicio, bound to a list of servicio ids. */
export default {
	name: 'ServicioChecks',
	components: { NcCheckboxRadioSwitch },
	props: {
		legend: { type: String, required: true },
		servicios: { type: Array, required: true },
		modelValue: { type: Array, required: true },
	},
	emits: ['update:modelValue'],
	methods: {
		toggle(servicioId, checked) {
			const others = this.modelValue.filter((id) => id !== servicioId)
			this.$emit('update:modelValue', checked ? [...others, servicioId] : others)
		},
	},
}
</script>
