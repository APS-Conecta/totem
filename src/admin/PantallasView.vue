<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<template>
	<ConfigSection title="Pantallas"
		subtitle="Las pantallas de la sala de espera: muestran las llamadas y las anuncian con sonido y voz."
		new-label="Nueva pantalla"
		:editing="editing"
		:sidebar-name="sidebarName"
		:sidebar-subname="form.id === null ? 'Complete el formulario' : 'Editar pantalla'"
		:sidebar-key="form.id ?? 'new'"
		@new="startNew"
		@close="closeForm">
		<template #list>
			<ListNotice plural="pantallas" :failed="loadFailed" :empty="loaded && !pantallas.length" />
			<div class="totem-scroll">
				<table class="totem-table">
					<caption class="totem-sr">
						Pantallas
					</caption>
					<thead>
						<tr>
							<th>Pantalla</th>
							<th>Muestra</th>
							<th>Aviso</th>
							<th><span class="totem-sr">Abrir</span></th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="pantalla in pantallas"
							:key="pantalla.id"
							:class="{ 'totem-selected': editing && pantalla.id === form.id }">
							<th scope="row">
								<NcButton variant="tertiary" alignment="start" :aria-pressed="String(editing && pantalla.id === form.id)" @click="pick(pantalla)">
									{{ pantalla.name }}
								</NcButton>
							</th>
							<td>{{ pantalla.servicioIds.length ? namesOf(pantalla.servicioIds) : 'Todas las unidades' }}</td>
							<td>Volumen {{ pantalla.volume }} %, {{ VOICE_SUMMARY[pantalla.voice] }}</td>
							<td>
								<a class="totem-link" :href="displayUrl(pantalla.slug)" target="_blank" rel="noopener noreferrer">Abrir pantalla</a>
							</td>
						</tr>
					</tbody>
				</table>
			</div>
		</template>

		<template #form>
			<form class="totem-form" @submit.prevent="save">
				<NcTextField v-model="form.name" label="Nombre" maxlength="32" />
				<ServicioChecks v-model="form.servicioIds"
					legend="Unidades que muestra (ninguna marcada = todas)"
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
					<code class="totem-address" aria-labelledby="totem-pantalla-address">{{ address }}</code>
					<div class="totem-address-row">
						<NcButton :href="displayUrl(form.slug)" target="_blank" rel="noopener noreferrer">
							Abrir pantalla
						</NcButton>
						<NcButton type="button" variant="tertiary" @click="copyAddress">
							Copiar dirección
						</NcButton>
					</div>
				</div>
				<FormOutcome :error="error" :notice="notice" />
				<NcButton type="button" variant="secondary" @click="tryAnnouncement">
					Probar aviso
				</NcButton>
				<NcButton type="submit" variant="primary">
					Guardar pantalla
				</NcButton>
				<ConfirmDelete v-if="form.id !== null"
					:key="form.id"
					label="Eliminar pantalla"
					:question="`¿Eliminar ${savedName}?`"
					@confirm="remove" />
			</form>
		</template>
	</ConfigSection>
</template>

<script>
import { generateUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcTextField from '@nextcloud/vue/components/NcTextField'

import { admin, warnError } from '../api.js'
import { testAnnouncement } from '../lib/announce.js'
import ConfigSection from './ConfigSection.vue'
import ConfirmDelete from './ConfirmDelete.vue'
import FormOutcome from './FormOutcome.vue'
import ListNotice from './ListNotice.vue'
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
	name: 'PantallasView',
	mixins: [withServicios],
	components: { ConfigSection, ConfirmDelete, FormOutcome, ListNotice, NcButton, NcTextField, ServicioChecks },
	data() {
		return { VOICES, VOICE_SUMMARY, pantallas: [], form: blankForm(), editing: false, error: '', notice: '', loaded: false, loadFailed: false }
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
			return window.location.origin + this.displayUrl(this.form.slug)
		},
		sidebarName() {
			return this.form.id === null ? 'Nueva pantalla' : this.savedName
		},
	},
	methods: {
		/** Where a pantalla's TV opens, relative to this server. */
		displayUrl(slug) {
			return generateUrl(`/apps/totem/display/${slug}`)
		},
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
