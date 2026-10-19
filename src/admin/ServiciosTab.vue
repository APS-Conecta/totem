<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<template>
	<div class="totem-grid">
		<div class="totem-scroll">
			<ListNotice plural="servicios" :failed="loadFailed" :empty="loaded && !servicios.length" />
			<table class="totem-table">
				<thead>
					<tr>
						<th>Servicio</th>
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
						:class="{ 'totem-selected': servicio.id === form.id }">
						<td>
							<NcButton variant="tertiary" :aria-pressed="String(servicio.id === form.id)" @click="pick(servicio)">
								<template #icon>
									<span class="totem-dot" :style="{ background: servicio.color }" />
								</template>
								{{ servicio.name }}
							</NcButton>
						</td>
						<td>{{ servicio.prefix }}</td>
						<td>{{ servicio.opens }}–{{ servicio.closes }}</td>
						<td>{{ servicio.dailyQuota ?? 'Sin límite' }}</td>
						<td>{{ servicio.active ? 'Activo' : 'Inactivo' }}</td>
						<td>{{ servicio.isSector ? 'Sector' : 'Servicio' }}</td>
					</tr>
				</tbody>
			</table>
		</div>
		<form class="totem-form" @submit.prevent="save">
			<NcButton type="button" variant="secondary" @click="startNew">
				Nuevo servicio
			</NcButton>
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
				Activo
			</NcCheckboxRadioSwitch>
			<NcCheckboxRadioSwitch v-model="form.isSector">
				Es un sector
			</NcCheckboxRadioSwitch>
			<FormOutcome :error="error" :notice="notice" />
			<NcButton type="submit" variant="primary">
				Guardar servicio
			</NcButton>
		</form>
	</div>
</template>

<script>
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcTextField from '@nextcloud/vue/components/NcTextField'

import { admin, warnError } from '../api.js'
import { contrastRatio, hasLegibleText, readableOn } from '../lib/color.js'
import FormOutcome from './FormOutcome.vue'
import ListNotice from './ListNotice.vue'
import { isGone, reasonFor } from './reason.js'

const blankForm = () => ({ id: null, name: '', prefix: '', dailyQuota: '', opens: '08:00', closes: '17:00', color: '#0b6e4f', active: true, isSector: false })

export default {
	name: 'ServiciosTab',
	components: { NcButton, FormOutcome, ListNotice, NcCheckboxRadioSwitch, NcTextField },
	data() {
		return { servicios: [], form: blankForm(), error: '', notice: '', loaded: false, loadFailed: false }
	},
	async created() {
		await this.load()
	},
	computed: {
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
		async save() {
			this.forgetOutcome()
			const { id, dailyQuota, ...rest } = this.form
			let stored
			try {
				stored = await admin.servicios.save(id, { ...rest, dailyQuota: dailyQuota === '' ? null : Number(dailyQuota) })
			} catch (error) {
				this.error = await reasonFor(error, { gone: 'Este servicio ya no existe.', fallback: 'No se pudo guardar el servicio.' })
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
