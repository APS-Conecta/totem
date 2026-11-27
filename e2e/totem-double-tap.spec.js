// SPDX-License-Identifier: AGPL-3.0-or-later
//
// A double tap on the totem: the second tap lands on the screen the first one opened. After a servicio bar it lands on
// Sí or No, after a way home ("Volver al inicio" after a refusal, "‹ Volver" on the question) on a servicio bar. The
// window is measured between the taps themselves, by their click events' own time stamps, so these tests dispatch
// clicks that carry a chosen time stamp: the gap between two taps is then exact however slow the run is, and the
// page's wall clock can be moved apart from it.

const { test, expect } = require('@playwright/test')
const { call, kiosk, login, waitOutDoubleTap, world } = require('./helpers')

const HOME = 'Seleccione la unidad a la que acude.'
const QUESTION = '¿Tiene 60 años o más, una discapacidad o cuida a una persona con discapacidad?'

/**
 * Clicks each [locator, timeStamp] in turn within one task of the page, as the browser does for taps it dispatches
 * late: each click event carries the time its tap happened on the page's performance timeline, whenever it runs.
 */
async function tapAt(page, ...taps) {
	const clicks = await Promise.all(taps.map(async ([locator, at]) => [await locator.elementHandle(), at]))
	await page.evaluate((list) => {
		for (const [target, at] of list) {
			const click = new MouseEvent('click', { bubbles: true, cancelable: true })
			Object.defineProperty(click, 'timeStamp', { value: at })
			target.dispatchEvent(click)
		}
	}, clicks)
}

/** A fresh totem with the world's servicio on it; its page's wall clock held at the time it returns. */
async function freshTotem(browser, page) {
	await login(page)
	const w = await world(page)
	const { patient, screen: totem } = await kiosk(browser, '/apps/totem/totem')
	const bar = totem.getByRole('button', { name: new RegExp(w.servicio.name) })
	await expect(bar).toBeVisible()
	const now = await totem.evaluate(() => Date.now())
	await patient.clock.setFixedTime(now)
	const tapped = await totem.evaluate(() => performance.now())
	return { w, patient, totem, bar, now, tapped }
}

const choice = (totem, name) => totem.getByRole('button', { name, exact: true })

test('Sí tapped 150 ms after a servicio bar is ignored though its handler runs 600 ms later', async ({ browser, page }) => {
	const { w, patient, totem, bar, now, tapped } = await freshTotem(browser, page)
	try {
		await tapAt(totem, [bar, tapped])
		await expect(totem.getByText(QUESTION)).toBeVisible()

		await patient.clock.setFixedTime(now + 600) // the page was busy: the second tap's handler runs this late
		await tapAt(totem, [choice(totem, 'Sí'), tapped + 150], [choice(totem, 'No'), tapped + 500])

		await expect(totem.locator('.t-ticket')).toContainText(`${w.prefix}-G-001`)
		await expect(totem.locator('.t-ticket')).not.toContainText('Atención preferente')
	} finally {
		await patient.close()
		await w.cleanup()
	}
})

test('Sí tapped 1 s after a servicio bar is answered though the wall clock stepped back 60 s', async ({ browser, page }) => {
	const { w, patient, totem, bar, now, tapped } = await freshTotem(browser, page)
	try {
		await tapAt(totem, [bar, tapped])
		await expect(totem.getByText(QUESTION)).toBeVisible()

		await patient.clock.setFixedTime(now - 60000 + 1000)
		await tapAt(totem, [choice(totem, 'Sí'), tapped + 1000])

		await expect(totem.locator('.t-ticket')).toContainText(`${w.prefix}-P-001`)
		await expect(totem.locator('.t-ticket')).toContainText('Atención preferente')
	} finally {
		await patient.close()
		await w.cleanup()
	}
})

// Each way home, reached from a servicio bar tapped on a fresh totem.
const WAYS_HOME = [
	['Volver al inicio', async (totem, page, w) => {
		await waitOutDoubleTap(totem)
		// Switched off after the totem loaded its list: the bar stays until the next poll, the server refuses.
		await call(page, 'PUT', `/apps/totem/servicios/${w.servicio.id}`, { ...w.servicio, active: false })
		await choice(totem, 'No').click()
		await expect(totem.getByRole('alert')).toContainText('Esta unidad no está disponible.')
	}],
	['‹ Volver', (totem) => waitOutDoubleTap(totem)],
]

for (const [way, reach] of WAYS_HOME) {
	test(`a servicio bar tapped at once after "${way}" is ignored, and answers after the window`, async ({ browser, page }) => {
		await login(page)
		const w = await world(page)
		const { patient, screen: totem } = await kiosk(browser, '/apps/totem/totem')
		try {
			const bar = totem.getByRole('button', { name: new RegExp(w.servicio.name) })
			await bar.click()
			await reach(totem, page, w)

			const tapped = await totem.evaluate(() => performance.now())
			await tapAt(totem, [choice(totem, way), tapped])
			await expect(totem.getByRole('heading', { name: HOME })).toBeVisible()
			await tapAt(totem, [bar, tapped + 499])

			await expect(totem.getByRole('heading', { name: HOME })).toBeVisible()
			await expect(totem.getByText(QUESTION)).toHaveCount(0)

			await tapAt(totem, [bar, tapped + 500])

			await expect(totem.getByText(QUESTION)).toBeVisible()
			await expect(totem.locator('.t-band')).toContainText(w.servicio.name)
		} finally {
			await patient.close()
			await w.cleanup()
		}
	})
}
