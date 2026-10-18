<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<template>
	<div class="totem-grid">
		<div class="totem-scroll">
			<ListNotice plural="pantallas"
				feminine
				:failed="loadFailed"
				:empty="loaded && !pantallas.length" />
			<table class="totem-table">
				<thead>
					<tr>
						<th>Pantalla</th>
						<th>Muestra</th>
						<th>Aviso</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="pantalla in pantallas"
						:key="pantalla.id"
						:class="{ 'totem-selected': pantalla.id === form.id }">
						<td>
							<NcButton variant="tertiary" :aria-pressed="String(pantalla.id === form.id)" @click="pick(pantalla)">
								{{ pantalla.name }}
							</NcButton>
						</td>
						<td>{{ pantalla.servicioIds.length ? namesOf(pantalla.servicioIds) : 'Todos los servicios' }}</td>
						<td>Volumen {{ pantalla.volume }} %, {{ VOICE_SUMMARY[pantalla.voice] }}</td>
					</tr>
				</tbody>
			</table>
		</div>
		<form class="totem-form" @submit.prevent="save">
			<NcButton type="button" variant="secondary" @click="startNew">
				Nueva pantalla
			</NcButton>
			<NcTextField v-model="form.name" label="Nombre" maxlength="32" />
			<ServicioChecks v-model="form.servicioIds"
				legend="Servicios que muestra (ninguno marcado = todos)"
				:servicios="servicios" />
			<label class="totem-label">
				<span>Volumen del aviso: <b>{{ form.volume }} %</b></span>
				<input v-model.number="form.volume"
					type="range"
					min="0"
					max="100"
					step="5"
					class="totem-input totem-input--range">
			</label>
			<label class="totem-label">
				<span>Voz del aviso</span>
				<select v-model="form.voice" class="totem-input">
					<option v-for="voice in VOICES" :key="voice.value" :value="voice.value">
						{{ voice.label }}
					</option>
				</select>
			</label>
			<div v-if="form.slug" class="totem-label">
				<span id="totem-pantalla-address">Dirección de la pantalla</span>
				<div class="totem-address-row">
					<code class="totem-address" aria-labelledby="totem-pantalla-address">{{ address }}</code>
					<NcButton type="button" variant="secondary" @click="copyAddress">
						Copiar dirección
					</NcButton>
				</div>
			</div>
			<FormOutcome :error="error" :notice="notice" />
			<div class="totem-row">
				<NcButton type="button" variant="secondary" @click="tryAnnouncement">
					Probar aviso
				</NcButton>
				<NcButton type="submit" variant="primary">
					Guardar pantalla
				</NcButton>
			</div>
			<ConfirmDelete v-if="form.id !== null"
				:key="form.id"
				label="Eliminar pantalla"
				:question="`¿Eliminar ${savedName}?`"
				@confirm="remove" />
		</form>
	</div>
</template>

<script>
import { generateUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcTextField from '@nextcloud/vue/components/NcTextField'

import { admin, warnError } from '../api.js'
import { testAnnouncement } from '../lib/announce.js'
import FormOutcome from './FormOutcome.vue'
import ListNotice from './ListNotice.vue'
import ConfirmDelete from './ConfirmDelete.vue'
import { isGone, reasonFor } from './reason.js'
import ServicioChecks from './ServicioChecks.vue'
import withServicios from './withServicios.js'

const VOICES = [
	{ value: 'mujer', label: 'Mujer (Claude, México)' },
	{ value: 'hombre', label: 'Hombre (Davefx, España)' },
	{ value: 'off', label: 'Sin voz, solo tonos' },
]
const blankForm = () => ({ id: null, slug: '', name: '', servicioIds: [], volume: 100, voice: 'mujer' })
const VOICE_SUMMARY = { mujer: 'voz de mujer', hombre: 'voz de hombre', off: 'sin voz' }

export default {
	name: 'PantallasTab',
	mixins: [withServicios],
	components: { ConfirmDelete, FormOutcome, ListNotice, NcButton, NcTextField, ServicioChecks },
	data() {
		return { VOICES, VOICE_SUMMARY, pantallas: [], form: blankForm(), error: '', notice: '', loaded: false, loadFailed: false }
	},
	async created() {
		await this.load()
	},
	computed: {
		/** The name the pantalla has on the server: the question must not follow what is half-typed. */
		savedName() {
			return this.pantallas.find((pantalla) => pantalla.id === this.form.id)?.name ?? this.form.name
		},
		/** Where this pantalla's TV opens: absolute, so it can be pasted into a kiosk. */
		address() {
			return window.location.origin + generateUrl(`/apps/totem/display/${this.form.slug}`)
		},
	},
	methods: {
		async remove() {
			this.forgetOutcome()
			try {
				await admin.pantallas.remove(this.form.id)
			} catch (error) {
				this.error = await reasonFor(error, { gone: 'Esta pantalla ya no existe.', fallback: 'No se pudo eliminar la pantalla.' })
				if (isGone(error)) await this.load()
				return
			}
			this.form = blankForm()
			this.notice = 'Pantalla eliminada.'
			await this.load()
		},
		async tryAnnouncement() {
			this.forgetOutcome()
			try {
				await testAnnouncement({ volume: this.form.volume, voice: this.form.voice })
			} catch (error) {
				warnError('probar aviso', error)
				this.error = 'No se pudo reproducir el aviso.'
			}
		},
		async copyAddress() {
			this.forgetOutcome()
			try {
				await window.navigator.clipboard.writeText(this.address)
				this.notice = 'Dirección copiada.'
			} catch {
				this.error = 'No se pudo copiar. Seleccione la dirección y cópiela.'
			}
		},
		formOf(pantalla) {
			return { id: pantalla.id, slug: pantalla.slug, name: pantalla.name, servicioIds: [...pantalla.servicioIds], volume: pantalla.volume, voice: pantalla.voice }
		},
		pick(pantalla) {
			this.form = this.formOf(pantalla)
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
			const { id, name, servicioIds, volume, voice } = this.form
			const body = { name, servicioIds, volume, voice }
			let stored
			try {
				stored = await admin.pantallas.save(id, body)
			} catch (error) {
				this.error = await reasonFor(error, { gone: 'Esta pantalla ya no existe.', fallback: 'No se pudo guardar la pantalla.' })
				if (isGone(error)) await this.load()
				return
			}
			this.form = this.formOf(stored)
			this.notice = 'Guardado.'
			await this.load()
		},
		async load() {
			try {
				this.pantallas = await admin.pantallas.list()
				this.loadFailed = false
			} catch (error) {
				warnError('pantallas list', error)
				this.loadFailed = true
			}
			this.loaded = true
		},
	},
}
</script>
