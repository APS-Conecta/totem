// SPDX-License-Identifier: AGPL-3.0-or-later
//
// The statistics board, end to end: tickets are issued and called through the real endpoints, then the
// board is read the way staff read it (period selector, arrows, the CSV link). The instance keeps what
// earlier runs left, so every assertion is about rows with this run's own names.
//
//   a sector (a servicio flagged as one) with 3 tickets: P1 served, G1 absent, G2 still waiting
//   an ordinary servicio with 1 general ticket, still waiting

const fs = require('node:fs')
const { test, expect } = require('@playwright/test')
const { call, login, world } = require('./helpers')

const row = (page, caption, name) => page
	.locator('table', { has: page.locator('caption', { hasText: caption }) })
	.locator('tbody tr', { has: page.locator('th', { hasText: name }) })
const cells = (r) => r.locator('th, td').allInnerTexts().then((texts) => texts.map((t) => t.trim()))

let w
let other
let sectorName
let otherName

test.beforeEach(async ({ page }) => {
	await login(page)
	w = await world(page, { servicioName: (run) => `Sector ${run}` })
	sectorName = w.servicio.name
	await call(page, 'PUT', `/apps/totem/servicios/${w.servicio.id}`, { ...w.servicio, isSector: true })
	const run = sectorName.replace('Sector ', '')
	otherName = `Vacunatorio ${run}`
	const prefix = (w.prefix.startsWith('V') ? 'W' : 'V') + w.prefix.slice(1)
	other = await call(page, 'POST', '/apps/totem/servicios', { name: otherName, prefix, color: '#6b46c1', opens: '00:00', closes: '23:59', dailyQuota: null })

	for (const [servicio, preferente] of [[w.servicio, true], [w.servicio, false], [w.servicio, false], [other, false]]) {
		await call(page, 'POST', '/apps/totem/turnos', { servicioId: servicio.id, preferente })
	}
	const box = `/apps/totem/console/${w.box.id}`
	await call(page, 'POST', `${box}/call`) // the preferente goes first
	await call(page, 'POST', `${box}/start`)
	await call(page, 'POST', `${box}/finish`)
	await call(page, 'POST', `${box}/call`) // the first general
	await call(page, 'POST', `${box}/absent`)
})

test.afterEach(async ({ page }) => {
	if (other) await call(page, 'PUT', `/apps/totem/servicios/${other.id}`, { ...other, active: false }).catch(() => {})
	await w.cleanup()
})

test('the board counts the tickets of the day, the week and the month by sector, servicio and preferente', async ({ page }) => {
	await page.goto('/apps/totem/estadisticas')

	// Semana is the opening view. Columns: Preferente, General, Total, Atendidos, No se presentó, Sin atender.
	await expect(row(page, 'Tickets por sector', sectorName)).toBeVisible()
	expect(await cells(row(page, 'Tickets por sector', sectorName))).toEqual([sectorName, '1', '2', '3', '1', '1', '1'])
	expect(await cells(row(page, 'Tickets por servicio', otherName))).toEqual([otherName, '0', '1', '1', '0', '0', '1'])
	await expect(page.getByRole('button', { name: 'Semana' })).toHaveAttribute('aria-pressed', 'true')

	for (const kind of ['Día', 'Mes']) {
		await page.getByRole('button', { name: kind, exact: true }).click()
		await expect(page.getByRole('button', { name: kind, exact: true })).toHaveAttribute('aria-pressed', 'true')
		await expect(row(page, 'Tickets por sector', sectorName)).toBeVisible()
		expect(await cells(row(page, 'Tickets por sector', sectorName))).toEqual([sectorName, '1', '2', '3', '1', '1', '1'])
	}

	// The period that holds today is the last one: there is nothing after it.
	await expect(page.getByRole('button', { name: 'Período siguiente' })).toBeDisabled()
	await page.getByRole('button', { name: 'Día', exact: true }).click()
	await page.getByRole('button', { name: 'Período anterior' }).click()
	await expect(page.getByRole('button', { name: 'Período siguiente' })).toBeEnabled()
	await expect(row(page, 'Tickets por sector', sectorName)).toBeVisible()
	expect(await cells(row(page, 'Tickets por sector', sectorName))).toEqual([sectorName, '0', '0', '0', '0', '0', '0'])
})

test('the CSV carries the day-by-day detail of the period and nothing that identifies anybody', async ({ page }) => {
	await page.goto('/apps/totem/estadisticas')
	await expect(row(page, 'Tickets por sector', sectorName)).toBeVisible()

	const [download] = await Promise.all([page.waitForEvent('download'), page.getByRole('link', { name: 'Descargar CSV' }).click()])

	expect(download.suggestedFilename()).toMatch(/^tickets-semana-\d{4}-\d{2}-\d{2}\.csv$/)
	const csv = fs.readFileSync(await download.path(), 'utf8')
	expect(csv.startsWith('﻿Fecha;Bloque;Servicio;Atención;Emitidos;Atendidos;No se presentó;Sin atender\n')).toBe(true)
	const line = (block, name, atencion, counts) => new RegExp(`^\\d{4}-\\d{2}-\\d{2};${block};${name};${atencion};${counts}$`, 'm')
	expect(csv).toMatch(line('Sector', sectorName, 'Preferente', '1;1;0;0'))
	expect(csv).toMatch(line('Sector', sectorName, 'General', '2;0;1;1'))
	expect(csv).toMatch(line('Servicio', otherName, 'General', '1;0;0;1'))
})

test('only a logged-in user can read the board', async ({ browser }) => {
	const anonymous = await browser.newContext()
	const page = await anonymous.newPage()

	await page.goto('/apps/totem/estadisticas')
	await expect(page).toHaveURL(/\/login/)

	for (const url of ['/apps/totem/stats', '/apps/totem/stats/csv']) {
		const answer = await anonymous.request.get(url, { maxRedirects: 0 })
		expect(answer.status(), url).not.toBe(200)
	}
	await anonymous.close()
})

test('the board fits a phone', async ({ page }) => {
	await page.setViewportSize({ width: 390, height: 844 })
	await page.goto('/apps/totem/estadisticas')
	await expect(row(page, 'Tickets por sector', sectorName)).toBeVisible()

	const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth)
	expect(overflow).toBeLessThanOrEqual(0)
})
