// SPDX-License-Identifier: AGPL-3.0-or-later
//
// The kiosk and the display wear the suite's theme: both render on Nextcloud's base layout, so they take their
// colours from Nextcloud's variables, body text from --font-face and headings from the theme's heading font. A
// unidad's colour is data and stays the unidad's. THEME stands in for the suite's theme with values no Nextcloud
// default has, so a colour or font the page sets itself shows up as a mismatch.

const { test, expect } = require('@playwright/test')
const { answerQuestion, callNew, kiosk, login, openDisplay, openQuestion, world } = require('./helpers')

const THEME = `
	:root, body {
		--color-main-background: rgb(251, 249, 255) !important;
		--color-main-text: rgb(26, 16, 51) !important;
		--color-primary-element: rgb(90, 30, 200) !important;
		--color-primary-element-text: rgb(255, 254, 250) !important;
		--font-face: "Probe Body", sans-serif !important;
	}
	h1, h2, h3 { font-family: "Probe Display", serif; }
`
const MAIN_BACKGROUND = 'rgb(251, 249, 255)'
const PRIMARY = 'rgb(90, 30, 200)'
// The world's unidad colour, #0b6e69.
const UNIDAD = 'rgb(11, 110, 105)'

/** The colour a person sees behind the point (x, y) of `page`: the first ancestor with a background of its own. */
function backgroundAt(page, x, y) {
	return page.evaluate(([px, py]) => {
		for (let node = document.elementFromPoint(px, py); node; node = node.parentElement) {
			const colour = getComputedStyle(node).backgroundColor
			if (colour !== 'rgba(0, 0, 0, 0)') return colour
		}
		return null
	}, [x, y])
}

const fontOf = (locator) => locator.evaluate((node) => getComputedStyle(node).fontFamily)
const backgroundOf = (locator) => locator.evaluate((node) => getComputedStyle(node).backgroundColor)

let w

test.beforeEach(async ({ page }) => {
	await login(page)
	w = await world(page)
})

test.afterEach(async () => {
	await w.cleanup()
})

test('a patient takes a turno at the kiosk in the theme\'s colours and fonts, on the unidad\'s colour', async ({ browser }) => {
	const { patient, screen } = await kiosk(browser, '/apps/totem/totem')
	await screen.addStyleTag({ content: THEME })
	try {
		const heading = screen.getByRole('heading', { name: 'Seleccione la unidad a la que acude.' })
		const bar = screen.getByRole('button', { name: new RegExp(w.servicio.name) })
		await expect(bar).toBeVisible()

		expect(await backgroundAt(screen, 5, 5)).toBe(MAIN_BACKGROUND)
		expect(await fontOf(heading)).toContain('Probe Display')
		expect(await fontOf(bar)).toContain('Probe Body')
		expect(await backgroundOf(bar)).toBe(UNIDAD)

		await openQuestion(screen, w.servicio.name)
		expect(await backgroundOf(screen.getByRole('button', { name: 'Sí', exact: true }))).toBe(PRIMARY)

		await answerQuestion(screen, 'No')
		const ticket = screen.getByRole('main')
		await expect(ticket).toContainText(`${w.prefix}-001`)
		expect(await backgroundOf(ticket)).toBe(UNIDAD)
		expect(await fontOf(ticket)).toContain('Probe Body')
	} finally {
		await patient.close()
	}
})

test('the display calls a turno in the theme\'s colours and fonts, on the unidad\'s colour', async ({ browser, page }) => {
	const room = await openDisplay(browser, w)
	await room.addStyleTag({ content: THEME })
	try {
		const label = await callNew(page, w)
		const card = room.getByRole('status')
		await expect(card).toContainText(label, { timeout: 8_000 })

		const viewport = room.viewportSize()
		expect(await backgroundAt(room, viewport.width - 5, 5)).toBe(MAIN_BACKGROUND)
		expect(await fontOf(room.getByRole('heading', { name: 'Últimos llamados' }))).toContain('Probe Display')
		expect(await fontOf(card)).toContain('Probe Body')
		expect(await backgroundOf(card)).toBe(UNIDAD)
	} finally {
		await room.context().close()
	}
})
