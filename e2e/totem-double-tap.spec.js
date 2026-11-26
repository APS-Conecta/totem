// SPDX-License-Identifier: AGPL-3.0-or-later
//
// A double tap on the totem's way home: "Volver al inicio" sits where the home screen puts its servicio bars, so
// the second tap of a double tap must not choose a unidad the patient did not pick. The page's clock is held still
// for the two taps, so they land inside the double-tap window however slow the run is.

const { test, expect } = require('@playwright/test')
const { call, login, waitOutDoubleTap, world } = require('./helpers')

const HOME = 'Seleccione la unidad a la que acude.'
const QUESTION = '¿Tiene 60 años o más, una discapacidad o cuida a una persona con discapacidad?'

test('a servicio bar tapped at once after a refusal\'s "Volver al inicio" is ignored, and answers after the window', async ({ browser, page }) => {
	await login(page)
	const w = await world(page)
	const patient = await browser.newContext({ locale: 'es-CL', viewport: { width: 1080, height: 1920 } })
	try {
		const totem = await patient.newPage()
		await totem.goto('/apps/totem/totem')
		const bar = totem.getByRole('button', { name: new RegExp(w.servicio.name) })
		await bar.click()
		await waitOutDoubleTap(totem)
		// Switched off after the totem loaded its list: the bar stays until the next poll, the server refuses.
		await call(page, 'PUT', `/apps/totem/servicios/${w.servicio.id}`, { ...w.servicio, active: false })
		await totem.getByRole('button', { name: 'No', exact: true }).click()
		await expect(totem.getByRole('alert')).toContainText('Esta unidad no está disponible.')

		const now = await totem.evaluate(() => Date.now())
		await patient.clock.setFixedTime(now)
		await totem.getByRole('button', { name: 'Volver al inicio' }).click()
		await expect(totem.getByRole('heading', { name: HOME })).toBeVisible()
		await bar.click()

		await expect(totem.getByRole('heading', { name: HOME })).toBeVisible()
		await expect(totem.getByText(QUESTION)).toHaveCount(0)

		await patient.clock.setFixedTime(now + 500)
		await bar.click()

		await expect(totem.getByText(QUESTION)).toBeVisible()
		await expect(totem.locator('.t-band')).toContainText(w.servicio.name)
	} finally {
		await patient.close()
		await w.cleanup()
	}
})
