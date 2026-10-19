<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<template>
	<ConfigSection title="Tótems"
		subtitle="Las pantallas táctiles donde el paciente saca su turno. Cada tótem tiene su dirección, las unidades que ofrece y, si quiere, su impresora de red."
		new-label="Nuevo tótem"
		:editing="editing"
		:sidebar-name="sidebarName"
		:sidebar-subname="form.id === null ? 'Complete el formulario' : 'Editar tótem'"
		:sidebar-key="form.id ?? 'new'"
		@new="startNew"
		@close="closeForm">
		<template #list>
			<ListNotice plural="tótems" first="el primero" :failed="loadFailed" :empty="loaded && !totems.length" />
			<div class="totem-scroll">
				<table class="totem-table">
					<caption class="totem-sr">
						Tótems
					</caption>
					<thead>
						<tr>
							<th>Tótem</th>
							<th>Ofrece</th>
							<th>Impresora</th>
							<th>Última impresión</th>
							<th><span class="totem-sr">Abrir</span></th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="totem in totems"
							:key="totem.id"
							:class="{ 'totem-selected': editing && totem.id === form.id }">
							<th scope="row">
								<NcButton variant="tertiary" alignment="start" :aria-pressed="String(editing && totem.id === form.id)" @click="pick(totem)">
									{{ totem.name }}
								</NcButton>
							</th>
							<td>{{ totem.servicioIds.length ? namesOf(totem.servicioIds) : 'Todas las unidades' }}</td>
							<td>{{ totem.printerHost ? printerOf(totem) : 'Imprime el navegador' }}</td>
							<td>
								<span :class="{ 'totem-status--bad': statusOf(totem).bad }">{{ statusOf(totem).text }}</span>
							</td>
							<td>
								<a class="totem-link" :href="kioskUrl(totem.slug)" target="_blank" rel="noopener noreferrer">Abrir tótem</a>
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
					legend="Unidades que ofrece (ninguna marcada = todas)"
					:servicios="servicios" />
				<div class="totem-row">
					<NcTextField v-model="form.printerHost"
						label="Dirección de la impresora"
						placeholder="192.168.1.50" />
					<NcTextField v-model="form.printerPort"
						type="number"
						label="Puerto"
						min="9100"
						max="9109" />
				</div>
				<p class="totem-pane__note">
					Una impresora de tickets de 80 mm en la red de la clínica (10.x, 172.16 a 172.31 o 192.168.x). Sin dirección, imprime el navegador del tótem.
				</p>
				<div v-if="saved?.printerHost" class="totem-label">
					<span>Última impresión</span>
					<span :class="{ 'totem-status--bad': status.bad }">{{ status.text }}</span>
				</div>
				<NcButton v-if="saved?.printerHost" type="button" variant="secondary" @click="testPrint">
					Probar impresión
				</NcButton>
				<div v-if="form.slug" class="totem-label">
					<span id="totem-address">Dirección del tótem</span>
					<code class="totem-address" aria-labelledby="totem-address">{{ address }}</code>
					<div class="totem-address-row">
						<NcButton :href="kioskUrl(form.slug)" target="_blank" rel="noopener noreferrer">
							Abrir tótem
						</NcButton>
						<NcButton type="button" variant="tertiary" @click="copyAddress">
							Copiar dirección
						</NcButton>
					</div>
				</div>
				<FormOutcome :error="error" :notice="notice" />
				<NcButton type="submit" variant="primary">
					Guardar tótem
				</NcButton>
				<ConfirmDelete v-if="form.id !== null"
					:key="form.id"
					label="Eliminar tótem"
					:question="`¿Eliminar ${sidebarName}?`"
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
import ConfigSection from './ConfigSection.vue'
import ConfirmDelete from './ConfirmDelete.vue'
import FormOutcome from './FormOutcome.vue'
import ListNotice from './ListNotice.vue'
import { printerStatus } from './printerStatus.js'
import { isGone, reasonFor } from './reason.js'
import ServicioChecks from './ServicioChecks.vue'
import withServicios from './withServicios.js'

const blankForm = () => ({ id: null, slug: '', name: '', servicioIds: [], printerHost: '', printerPort: 9100 })

export default {
	name: 'TotemsView',
	mixins: [withServicios],
	components: { ConfigSection, ConfirmDelete, FormOutcome, ListNotice, NcButton, NcTextField, ServicioChecks },
	data() {
		return { totems: [], form: blankForm(), editing: false, error: '', notice: '', loaded: false, loadFailed: false }
	},
	async created() {
		await this.load()
	},
	computed: {
		/** The tótem as the server holds it: what the title, the delete question and the test print follow, not what is half-typed. */
		saved() {
			return this.totems.find((totem) => totem.id === this.form.id)
		},
		sidebarName() {
			return this.form.id === null ? 'Nuevo tótem' : (this.saved?.name ?? this.form.name)
		},
		status() {
			return this.saved ? printerStatus(this.saved) : { text: '–', bad: false }
		},
		/** Where this tótem opens: absolute, so it can be pasted into a kiosk. */
		address() {
			return window.location.origin + this.kioskUrl(this.form.slug)
		},
	},
	methods: {
		kioskUrl(slug) {
			return generateUrl(`/apps/totem/totem/${slug}`)
		},
		printerOf(totem) {
			return totem.printerPort === 9100 ? totem.printerHost : `${totem.printerHost}:${totem.printerPort}`
		},
		statusOf: printerStatus,
		formOf(totem) {
			return { id: totem.id, slug: totem.slug, name: totem.name, servicioIds: [...totem.servicioIds], printerHost: totem.printerHost ?? '', printerPort: totem.printerPort }
		},
		pick(totem) {
			this.form = this.formOf(totem)
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
			const { id, name, servicioIds, printerHost, printerPort } = this.form
			let stored
			try {
				stored = await admin.totems.save(id, { name, servicioIds, printerHost, printerPort: Number(printerPort) })
			} catch (error) {
				this.error = await reasonFor(error, { gone: 'Este tótem ya no existe.', fallback: 'No se pudo guardar el tótem.' })
				if (isGone(error)) await this.load()
				return
			}
			this.form = this.formOf(stored)
			this.notice = 'Guardado.'
			await this.load()
		},
		async remove() {
			this.forgetOutcome()
			try {
				await admin.totems.remove(this.form.id)
			} catch (error) {
				this.error = await reasonFor(error, { gone: 'Este tótem ya no existe.', fallback: 'No se pudo eliminar el tótem.' })
				if (isGone(error)) await this.load()
				return
			}
			this.form = blankForm()
			this.notice = 'Tótem eliminado.'
			await this.load()
		},
		/** Print a sample ticket, so the admin sees the printer works before a patient needs it. */
		async testPrint() {
			this.forgetOutcome()
			try {
				const { printed, totem } = await admin.totems.testPrint(this.form.id)
				if (printed) {
					this.notice = `Ticket de prueba enviado a ${totem.printerHost}:${totem.printerPort}.`
				} else {
					this.error = 'No se pudo imprimir: la impresora no respondió. Revise que esté encendida y conectada a la red.'
				}
			} catch (error) {
				this.error = await reasonFor(error, { gone: 'Este tótem ya no existe.', fallback: 'No se pudo probar la impresión.' })
			}
			await this.load()
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
		async load() {
			try {
				this.totems = await admin.totems.list()
				this.loadFailed = false
			} catch (error) {
				warnError('totems list', error)
				this.loadFailed = true
			}
			this.loaded = true
		},
	},
}
</script>
