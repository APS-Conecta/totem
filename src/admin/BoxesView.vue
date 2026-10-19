<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<template>
	<ConfigSection title="Ventanillas"
		subtitle="Cada ventanilla atiende una o más unidades y llama al ticket que lleva más tiempo esperando."
		new-label="Nueva ventanilla"
		:editing="editing"
		:sidebar-name="sidebarName"
		:sidebar-subname="form.id === null ? 'Complete el formulario' : 'Editar ventanilla'"
		:sidebar-key="form.id ?? 'new'"
		@new="startNew"
		@close="closeForm">
		<template #list>
			<ListNotice plural="ventanillas" :failed="loadFailed" :empty="loaded && !boxes.length" />
			<div class="totem-scroll">
				<table class="totem-table">
					<caption class="totem-sr">
						Ventanillas
					</caption>
					<thead>
						<tr>
							<th>Ventanilla</th>
							<th>Atiende</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="box in boxes"
							:key="box.id"
							:class="{ 'totem-selected': editing && box.id === form.id }">
							<th scope="row">
								<NcButton variant="tertiary" alignment="start" :aria-pressed="String(editing && box.id === form.id)" @click="pick(box)">
									{{ box.name }}
								</NcButton>
							</th>
							<td>{{ namesOf(box.servicioIds) }}</td>
						</tr>
					</tbody>
				</table>
			</div>
		</template>

		<template #form>
			<form class="totem-form" @submit.prevent="save">
				<NcTextField v-model="form.name" label="Nombre" maxlength="14" />
				<ServicioChecks v-model="form.servicioIds" legend="Atiende" :servicios="servicios" />
				<FormOutcome :error="error" :notice="notice" />
				<NcButton type="submit" variant="primary">
					Guardar ventanilla
				</NcButton>
				<ConfirmDelete v-if="form.id !== null"
					:key="form.id"
					label="Eliminar ventanilla"
					:question="`¿Eliminar ${savedName}?`"
					@confirm="remove" />
			</form>
		</template>
	</ConfigSection>
</template>

<script>
import NcButton from '@nextcloud/vue/components/NcButton'
import NcTextField from '@nextcloud/vue/components/NcTextField'

import { admin, warnError } from '../api.js'
import ConfigSection from './ConfigSection.vue'
import ConfirmDelete from './ConfirmDelete.vue'
import FormOutcome from './FormOutcome.vue'
import ListNotice from './ListNotice.vue'
import ServicioChecks from './ServicioChecks.vue'
import { isGone, reasonFor } from './reason.js'
import withServicios from './withServicios.js'

const blankForm = () => ({ id: null, name: '', servicioIds: [] })

export default {
	name: 'BoxesView',
	mixins: [withServicios],
	components: { ConfigSection, ConfirmDelete, FormOutcome, ListNotice, NcButton, NcTextField, ServicioChecks },
	data() {
		return { boxes: [], form: blankForm(), editing: false, error: '', notice: '', loaded: false, loadFailed: false }
	},
	async created() {
		await this.load()
	},
	computed: {
		/** The name the box has on the server: the question must not follow what is half-typed. */
		savedName() {
			return this.boxes.find((box) => box.id === this.form.id)?.name ?? this.form.name
		},
		sidebarName() {
			return this.form.id === null ? 'Nueva ventanilla' : this.savedName
		},
	},
	methods: {
		async save() {
			this.forgetOutcome()
			const { id, ...body } = this.form
			let stored
			try {
				stored = await admin.boxes.save(id, body)
			} catch (error) {
				this.error = await reasonFor(error, { gone: 'Esta ventanilla ya no existe.', fallback: 'No se pudo guardar la ventanilla.' })
				if (isGone(error)) await this.load()
				return
			}
			this.form = { ...stored, servicioIds: [...stored.servicioIds] }
			this.notice = 'Guardado.'
			await this.load()
		},
		pick(box) {
			this.form = { ...box, servicioIds: [...box.servicioIds] }
			this.editing = true
			this.forgetOutcome()
		},
		startNew() {
			this.form = blankForm()
			this.editing = true
			this.forgetOutcome()
		},
		closeForm() {
			this.editing = false
			this.forgetOutcome()
		},
		forgetOutcome() {
			this.error = ''
			this.notice = ''
		},
		async remove() {
			this.forgetOutcome()
			try {
				await admin.boxes.remove(this.form.id)
			} catch (error) {
				this.error = await reasonFor(error, { gone: 'Esta ventanilla ya no existe.', fallback: 'No se pudo eliminar la ventanilla.' })
				if (isGone(error)) await this.load()
				return
			}
			this.form = blankForm()
			this.notice = 'Ventanilla eliminada.'
			await this.load()
		},
		async load() {
			try {
				this.boxes = await admin.boxes.list()
				this.loadFailed = false
			} catch (error) {
				warnError('boxes list', error)
				this.loadFailed = true
			}
			this.loaded = true
		},
	},
}
</script>
