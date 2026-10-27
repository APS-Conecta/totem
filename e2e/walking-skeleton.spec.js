// SPDX-License-Identifier: AGPL-3.0-or-later
//
// The walking skeleton: the thinnest path through every layer, written before any of it existed.
// Unit cycles run inside it.
//
//   admin creates servicio + box + pantalla
//   -> an anonymous patient takes a preferente turno on the totem   (PREFIX-P-001)
//   -> the box calls it
//   -> the anonymous waiting-room display shows "PREFIX-P-001 -> BOX n"
//
// Config goes through the admin endpoints (fast; the admin forms have their own component
// tests); the three surfaces under test are driven the way people use them.

const { test, expect } = require('@playwright/test')
const { login, waitOutDoubleTap, world } = require('./helpers')

test('a preferente turno taken on the totem is called from the box and shown on the display', async ({ browser, page }) => {
	await login(page)
	const w = await world(page)
	try {
		// The patient: anonymous, two taps.
		const patient = await (await browser.newContext({ locale: 'es-CL' })).newPage()
		await patient.goto('/apps/totem/totem')
		await patient.getByRole('button', { name: new RegExp(w.servicio.name) }).click()
		await waitOutDoubleTap(patient)
		await patient.getByRole('button', { name: 'Sí', exact: true }).click()
		// The label is on the screen ticket and in the (print-only) paper ticket: the first is the visible one.
		await expect(patient.getByText(`${w.prefix}-P-001`).first()).toBeVisible()

		// The waiting-room display: anonymous, already open before the call.
		const room = await (await browser.newContext({ locale: 'es-CL' })).newPage()
		await room.goto('/apps/totem/display/' + w.screen.slug)

		// The staff member: picks the box, calls the next turno.
		await page.goto('/apps/totem/box')
		await page.getByLabel('Ventanilla', { exact: true }).selectOption({ label: w.box.name })
		await page.getByRole('button', { name: 'Llamar siguiente' }).click()
		await expect(page.getByText(`${w.prefix}-P-001`).first()).toBeVisible()

		// The display polls every 2 s: it must show the call within a few polls.
		const card = room.getByRole('status')
		await expect(card).toContainText(`${w.prefix}-P-001`, { timeout: 8_000 })
		await expect(card).toContainText(w.box.name.toUpperCase())
	} finally {
		await w.cleanup()
	}
})
