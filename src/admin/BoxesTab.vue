<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<template>
	<div class="totem-grid">
		<div class="totem-scroll">
			<ListNotice plural="boxes" :failed="loadFailed" :empty="loaded && !boxes.length" />
			<table class="totem-table">
				<thead>
					<tr>
						<th>Box</th>
						<th>Atiende</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="box in boxes"
						:key="box.id"
						:class="{ 'totem-selected': box.id === form.id }">
						<td>
							<NcButton variant="tertiary" :aria-pressed="String(box.id === form.id)" @click="pick(box)">
								{{ box.name }}
							</NcButton>
						</td>
						<td>{{ namesOf(box.servicioIds) }}</td>
					</tr>
				</tbody>
			</table>
		</div>
		<form class="totem-form" @submit.prevent="save">
			<NcButton type="button" variant="secondary" @click="startNew">
				Nuevo box
			</NcButton>
			<NcTextField v-model="form.name" label="Nombre" maxlength="14" />
			<ServicioChecks v-model="form.servicioIds" legend="Atiende" :servicios="servicios" />
			<FormOutcome :error="error" :notice="notice" />
			<NcButton type="submit" variant="primary">
				Guardar box
			</NcButton>
			<ConfirmDelete v-if="form.id !== null"
				:key="form.id"
				label="Eliminar box"
				:question="`¿Eliminar ${savedName}?`"
				@confirm="remove" />
		</form>
	</div>
</template>

<script>
import NcButton from '@nextcloud/vue/components/NcButton'
import NcTextField from '@nextcloud/vue/components/NcTextField'

import { admin, warnError } from '../api.js'
import ConfirmDelete from './ConfirmDelete.vue'
import FormOutcome from './FormOutcome.vue'
import ListNotice from './ListNotice.vue'
import ServicioChecks from './ServicioChecks.vue'
import { isGone, reasonFor } from './reason.js'
import withServicios from './withServicios.js'

const blankForm = () => ({ id: null, name: '', servicioIds: [] })

export default {
	name: 'BoxesTab',
	mixins: [withServicios],
	components: { ConfirmDelete, NcButton, FormOutcome, ListNotice, NcTextField, ServicioChecks },
	data() {
		return { boxes: [], form: blankForm(), error: '', notice: '', loaded: false, loadFailed: false }
	},
	async created() {
		await this.load()
	},
	computed: {
		/** The name the box has on the server: the question must not follow what is half-typed. */
		savedName() {
			return this.boxes.find((box) => box.id === this.form.id)?.name ?? this.form.name
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
				this.error = await reasonFor(error, { gone: 'Este box ya no existe.', fallback: 'No se pudo guardar el box.' })
				if (isGone(error)) await this.load()
				return
			}
			this.form = { ...stored, servicioIds: [...stored.servicioIds] }
			this.notice = 'Guardado.'
			await this.load()
		},
		pick(box) {
			this.form = { ...box, servicioIds: [...box.servicioIds] }
			this.forgetOutcome()
		},
		startNew() {
			this.form = blankForm()
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
				this.error = await reasonFor(error, { gone: 'Este box ya no existe.', fallback: 'No se pudo eliminar el box.' })
				if (isGone(error)) await this.load()
				return
			}
			this.form = blankForm()
			this.notice = 'Box eliminado.'
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
