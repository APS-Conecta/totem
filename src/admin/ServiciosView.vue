<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<template>
	<ConfigSection title="Unidades"
		subtitle="Las unidades del CESFAM a las que un paciente puede ir, como Farmacia o un sector. Una unidad marcada como sector se cuenta aparte en Estadísticas."
		new-label="Nueva unidad"
		:editing="editing"
		:sidebar-name="sidebarName"
		:sidebar-subname="form.id === null ? 'Complete el formulario' : 'Editar unidad'"
		:sidebar-key="form.id ?? 'new'"
		@new="startNew"
		@close="closeForm">
		<template #list>
			<ListNotice plural="unidades" :failed="loadFailed" :empty="loaded && !servicios.length" />
			<div class="totem-scroll">
				<table class="totem-table">
					<caption class="totem-sr">
						Unidades
					</caption>
					<thead>
						<tr>
							<th>Unidad</th>
							<th>Prefijo</th>
							<th>Horario</th>
							<th>Cupo</th>
							<th>Estado</th>
							<th>Tipo</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="servicio in servicios"
							:key="servicio.id"
							:class="{ 'totem-selected': editing && servicio.id === form.id }">
							<th scope="row">
								<NcButton variant="tertiary" alignment="start" :aria-pressed="String(editing && servicio.id === form.id)" @click="pick(servicio)">
									<template #icon>
										<span class="totem-dot" :style="{ background: servicio.color }" />
									</template>
									{{ servicio.name }}
								</NcButton>
							</th>
							<td>{{ servicio.prefix }}</td>
							<td>{{ servicio.opens }}–{{ servicio.closes }}</td>
							<td>{{ servicio.dailyQuota ?? 'Sin límite' }}</td>
							<td>{{ servicio.active ? 'Activa' : 'Inactiva' }}</td>
							<td>{{ servicio.isSector ? 'Sector' : 'Unidad' }}</td>
						</tr>
					</tbody>
				</table>
			</div>
		</template>

		<template #form>
			<form class="totem-form" @submit.prevent="save">
				<NcTextField v-model="form.name" label="Nombre" maxlength="28" />
				<div class="totem-row">
					<NcTextField v-model="form.prefix"
						class="totem-upper"
						label="Prefijo"
						maxlength="4" />
					<NcTextField v-model="form.dailyQuota"
						type="number"
						label="Cupo diario"
						placeholder="Sin límite" />
				</div>
				<div class="totem-row">
					<label class="totem-label">
						<span>Abre</span>
						<input v-model="form.opens" type="time" class="totem-input">
					</label>
					<label class="totem-label">
						<span>Cierra</span>
						<input v-model="form.closes" type="time" class="totem-input">
					</label>
				</div>
				<div class="totem-label">
					<label for="totem-servicio-color">Color</label>
					<span class="totem-color">
						<input id="totem-servicio-color" v-model="form.color" type="color" class="totem-input totem-input--color">
						<span class="totem-chip" :style="{ background: form.color, color: readableOn(form.color) }">{{ form.prefix.toUpperCase() || '—' }}</span>
						<span v-if="legible" role="status" class="totem-ratio totem-ratio--good">{{ contrastText }}</span>
						<span v-else role="alert" class="totem-ratio totem-ratio--bad">{{ contrastText }}. Elija un color más oscuro o más claro.</span>
					</span>
				</div>
				<NcCheckboxRadioSwitch v-model="form.active" type="switch">
					Activa
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch v-model="form.isSector">
					Es un sector
				</NcCheckboxRadioSwitch>
				<FormOutcome :error="error" :notice="notice" />
				<NcButton type="submit" variant="primary">
					Guardar unidad
				</NcButton>
			</form>
		</template>
	</ConfigSection>
</template>

<script>
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcTextField from '@nextcloud/vue/components/NcTextField'

import { admin, warnError } from '../api.js'
import { contrastRatio, hasLegibleText, readableOn } from '../lib/color.js'
import ConfigSection from './ConfigSection.vue'
import FormOutcome from './FormOutcome.vue'
import ListNotice from './ListNotice.vue'
import { isGone, reasonFor } from './reason.js'

const blankForm = () => ({ id: null, name: '', prefix: '', dailyQuota: '', opens: '08:00', closes: '17:00', color: '#0b6e4f', active: true, isSector: false })

export default {
	name: 'ServiciosView',
	components: { ConfigSection, FormOutcome, ListNotice, NcButton, NcCheckboxRadioSwitch, NcTextField },
	data() {
		return { servicios: [], form: blankForm(), editing: false, error: '', notice: '', loaded: false, loadFailed: false }
	},
	async created() {
		await this.load()
	},
	computed: {
		/** The name the unidad has on the server, so the title does not follow what is half-typed. */
		sidebarName() {
			return this.form.id === null ? 'Nueva unidad' : (this.servicios.find((s) => s.id === this.form.id)?.name ?? this.form.name)
		},
		legible() {
			return hasLegibleText(this.form.color)
		},
		contrastText() {
			const best = contrastRatio(this.form.color, readableOn(this.form.color))
			return `Contraste ${best.toFixed(1).replace('.', ',')}:1`
		},
	},
	methods: {
		readableOn,
		formOf(servicio) {
			return { ...servicio, dailyQuota: servicio.dailyQuota ?? '' }
		},
		pick(servicio) {
			this.form = this.formOf(servicio)
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
		async save() {
			this.forgetOutcome()
			const { id, dailyQuota, ...rest } = this.form
			let stored
			try {
				stored = await admin.servicios.save(id, { ...rest, dailyQuota: dailyQuota === '' ? null : Number(dailyQuota) })
			} catch (error) {
				this.error = await reasonFor(error, { gone: 'Esta unidad ya no existe.', fallback: 'No se pudo guardar la unidad.' })
				if (isGone(error)) await this.load()
				return
			}
			this.form = this.formOf(stored)
			this.notice = 'Guardado.'
			await this.load()
		},
		async load() {
			try {
				this.servicios = await admin.servicios.list()
				this.loadFailed = false
			} catch (error) {
				warnError('servicios list', error)
				this.loadFailed = true
			}
			this.loaded = true
		},
	},
}
</script>
