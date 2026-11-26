// SPDX-License-Identifier: AGPL-3.0-or-later
//
// The totem shows seven unidades a page. A patient who leaves it on a later page, after a ticket or after going
// back from the question, must not hand the next patient that page: the totem returns to the first one.

const { test, expect } = require('@playwright/test')
const { call, kiosk, login, waitOutDoubleTap, world } = require('./helpers')

let w
let run
let extra
let kioskTotem

test.beforeEach(async ({ page }) => {
	await login(page)
	w = await world(page, { servicioName: (id) => { run = id; return `Servicio 1 ${id}` } })
	extra = []
	for (let n = 2; n <= 9; n++) {
		extra.push(await call(page, 'POST', '/apps/totem/servicios', {
			name: `Servicio ${n} ${run}`, prefix: `${run.slice(-3)}${n}`, color: '#0b6e69', opens: '00:00', closes: '23:59', dailyQuota: null,
		}))
	}
	// A totem that offers exactly these nine, so another run's unidades never land on its pages.
	kioskTotem = await call(page, 'POST', '/apps/totem/totems', {
		name: `Tótem ${run}`, servicioIds: [w.servicio.id, ...extra.map((s) => s.id)], printerHost: '', printerPort: 9100,
	})
})

test.afterEach(async ({ page }) => {
	await call(page, 'DELETE', `/apps/totem/totems/${kioskTotem.id}`).catch(() => {})
	for (const s of extra) await call(page, 'PUT', `/apps/totem/servicios/${s.id}`, { ...s, active: false }).catch(() => {})
	await w.cleanup()
})

const bars = (screen) => screen.locator('.t-bar')
const previous = (screen) => screen.getByRole('button', { name: 'Anterior' })

const atTotem = (browser) => kiosk(browser, `/apps/totem/totem/${kioskTotem.slug}`)

async function turnToSecondPage(screen) {
	await screen.getByRole('button', { name: 'Siguiente' }).click()
	await expect(bars(screen).first()).toContainText(`Servicio 8 ${run}`)
	await expect(previous(screen)).toBeEnabled()
}

async function expectFirstPage(screen, wait = {}) {
	await expect(bars(screen).first()).toContainText(`Servicio 1 ${run}`, wait)
	await expect(previous(screen)).toBeDisabled(wait)
}

test('a totem left on its second page returns to the first after 30 seconds without a touch', async ({ browser }) => {
	const { patient, screen } = await atTotem(browser)
	try {
		await screen.clock.install()
		await turnToSecondPage(screen)
		await screen.clock.runFor(30_000)

		await expectFirstPage(screen)
	} finally {
		await patient.close()
	}
})

test('going back from a unidad chosen on the second page shows the first page', async ({ browser }) => {
	const { patient, screen } = await atTotem(browser)
	try {
		await turnToSecondPage(screen)
		await screen.getByRole('button', { name: new RegExp(`Servicio 8 ${run}`) }).click()
		await screen.getByRole('button', { name: '‹ Volver' }).click()

		await expectFirstPage(screen)
	} finally {
		await patient.close()
	}
})

test('the next patient after a ticket taken on the second page sees the first page', async ({ browser }) => {
	const { patient, screen } = await atTotem(browser)
	try {
		await turnToSecondPage(screen)
		await screen.getByRole('button', { name: new RegExp(`Servicio 8 ${run}`) }).click()
		await waitOutDoubleTap(screen)
		await screen.getByRole('button', { name: 'No', exact: true }).click()
		await expect(screen.locator('.t-ticket')).toContainText(`${run.slice(-3)}8-G-001`)

		// The ticket screen counts down 8 seconds on its own, then gives the totem back.
		await expectFirstPage(screen, { timeout: 12_000 })
	} finally {
		await patient.close()
	}
})
