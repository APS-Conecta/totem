// SPDX-License-Identifier: AGPL-3.0-or-later
//
// A refusal can arrive after the patient who asked has left the question. It is not for the next patient: whoever
// stands at the totem then still sees their own unidad and its question, never the earlier patient's refusal.

const { test, expect } = require('@playwright/test')
const { call, kiosk, login, waitOutDoubleTap, world } = require('./helpers')

let w
let run
let other
let kioskTotem

test.beforeEach(async ({ page }) => {
	await login(page)
	w = await world(page, { servicioName: (id) => { run = id; return `Farmacia ${id}` } })
	other = await call(page, 'POST', '/apps/totem/servicios', {
		name: `Dental ${run}`, prefix: `${run.slice(-3)}D`, color: '#0b6e69', opens: '00:00', closes: '23:59', dailyQuota: null,
	})
	// A totem that offers exactly these two, so another run's unidades never land on its page.
	kioskTotem = await call(page, 'POST', '/apps/totem/totems', {
		name: `Tótem ${run}`, servicioIds: [w.servicio.id, other.id], printerHost: '', printerPort: 9100,
	})
})

test.afterEach(async ({ page }) => {
	await call(page, 'DELETE', `/apps/totem/totems/${kioskTotem.id}`).catch(() => {})
	await call(page, 'PUT', `/apps/totem/servicios/${other.id}`, { ...other, active: false }).catch(() => {})
	await w.cleanup()
})

const unidad = (screen, name) => screen.getByRole('button', { name: new RegExp(`^${name} ${run}`) })
const back = (screen) => screen.getByRole('button', { name: '‹ Volver' })

/** Answer «No» for Farmacia while its turno request is held back; resolves with the held route. */
async function askFarmaciaAndHold(screen) {
	let held
	const holding = new Promise((resolve) => { held = resolve })
	await screen.route('**/apps/totem/turnos', (route) => held(route))
	await unidad(screen, 'Farmacia').click()
	await waitOutDoubleTap(screen)
	await screen.getByRole('button', { name: 'No', exact: true }).click()
	return holding
}

async function refuseAndExpectQuestion(screen, route, name) {
	await route.fulfill({ status: 422, contentType: 'application/json', body: JSON.stringify({ message: 'Hoy ya no quedan turnos para Farmacia.' }) })

	await expect(screen.locator('.t-band')).toContainText(`${name} ${run}`)
	// The buttons come back enabled once the refusal has been handled, so the alert check below is not early.
	await expect(screen.getByRole('button', { name: 'Sí', exact: true })).toBeEnabled()
	await expect(screen.getByRole('button', { name: 'No', exact: true })).toBeEnabled()
	await expect(screen.locator('[role="alert"]')).toHaveCount(0)
}

const atTotem = (browser) => kiosk(browser, `/apps/totem/totem/${kioskTotem.slug}`)

test('a refusal that arrives after the patient went back is not shown to the next patient at another unidad', async ({ browser }) => {
	const { patient, screen } = await atTotem(browser)
	try {
		const route = await askFarmaciaAndHold(screen)
		await back(screen).click()
		await waitOutDoubleTap(screen)
		await unidad(screen, 'Dental').click()

		await refuseAndExpectQuestion(screen, route, 'Dental')
	} finally {
		await patient.close()
	}
})

test('a refusal that arrives after the patient went back is not shown to the next patient at the same unidad', async ({ browser }) => {
	const { patient, screen } = await atTotem(browser)
	try {
		const route = await askFarmaciaAndHold(screen)
		await back(screen).click()
		await waitOutDoubleTap(screen)
		await unidad(screen, 'Farmacia').click()

		await refuseAndExpectQuestion(screen, route, 'Farmacia')
	} finally {
		await patient.close()
	}
})

test('a refusal that arrives after the question timed out is not shown to the next patient', async ({ browser }) => {
	const { patient, screen } = await atTotem(browser)
	try {
		await screen.clock.install()
		const route = await askFarmaciaAndHold(screen)
		await screen.clock.runFor(30_000)
		await waitOutDoubleTap(screen)
		await unidad(screen, 'Dental').click()

		await refuseAndExpectQuestion(screen, route, 'Dental')
	} finally {
		await patient.close()
	}
})
