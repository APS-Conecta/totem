// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Shared plumbing for the end-to-end tests: log in as the instance's admin, talk to the admin
// endpoints with the session's request token, build (and tear down) a small world of one
// servicio, one box and one pantalla with names that cannot collide with an earlier run, open a
// kiosk page as a patient or a Pantalla that counts its chimes, make a llamado, and wait out the
// totem's double-tap window before the next tap.

const { expect } = require('@playwright/test')

async function login(page, user = 'admin', password = 'admin') {
	await page.goto('/login')
	await page.fill('input[name=user]', user)
	await page.fill('input[name=password]', password)
	await page.click('button[type=submit]')
	await page.waitForURL(/\/apps\//)
	// A fresh instance shows its admin a modal wizard (`make instance-up` disables it; this covers others).
	const wizard = page.getByRole('dialog').getByRole('button', { name: 'Cerrar' })
	if (await wizard.isVisible({ timeout: 1500 }).catch(() => false)) await wizard.click()
}

/** Call an app endpoint from inside the logged-in page, so the request token is the session's own. */
async function call(page, method, url, body) {
	return page.evaluate(async ([m, u, b]) => {
		const res = await fetch(u, {
			method: m,
			headers: { 'Content-Type': 'application/json', requesttoken: window.OC.requestToken },
			body: b === undefined ? undefined : JSON.stringify(b),
		})
		const text = await res.text()
		if (!res.ok) {
			// The app answers a refusal as { message: <a Spanish sentence> }: show the sentence, not escaped JSON.
			let detail = text
			try { detail = JSON.parse(text).message ?? text } catch { /* not JSON */ }
			throw new Error(`${m} ${u} answered ${res.status}: ${detail}`)
		}
		return text ? JSON.parse(text) : null
	}, [method, url, body])
}

/**
 * `servicioName(run)` and `boxName` override the names (the servicio's must stay unique: it gets the run id);
 * `prefix` overrides the servicio's prefix, which cleanup hands back so the next run can take it again.
 * A servicio open all day, a box that covers it and a pantalla that shows it. `pantalla` sets the
 * pantalla's sound; the default is silent so a test run makes no noise.
 */
async function world(page, { pantalla = { volume: 0, voice: 'off' }, servicioName, boxName, prefix } = {}) {
	const run = Date.now().toString(36).toUpperCase()
	prefix ??= run.slice(-4)
	const servicio = await call(page, 'POST', '/apps/totem/servicios', {
		name: servicioName ? servicioName(run) : `Farmacia ${run}`, prefix, color: '#0b6e69', opens: '00:00', closes: '23:59', dailyQuota: null,
	})
	const box = await call(page, 'POST', '/apps/totem/boxes', { name: boxName ?? `Ventanilla ${run.slice(-3)}`, servicioIds: [servicio.id] })
	const screen = await call(page, 'POST', '/apps/totem/pantallas', { name: `Sala ${run}`, servicioIds: [servicio.id], ...pantalla })

	return {
		servicio, box, screen, prefix,
		/** Leave the instance as found: free the box, then remove what can be removed and switch the servicio off. */
		async cleanup() {
			const state = await call(page, 'GET', `/apps/totem/console/${box.id}/state`).catch(() => null)
			if (state?.current?.estado === 'called') await call(page, 'POST', `/apps/totem/console/${box.id}/absent`)
			if (state?.current?.estado === 'in_service') await call(page, 'POST', `/apps/totem/console/${box.id}/finish`)
			await call(page, 'DELETE', `/apps/totem/boxes/${box.id}`).catch(() => {})
			await call(page, 'DELETE', `/apps/totem/pantallas/${screen.id}`).catch(() => {})
			await call(page, 'PUT', `/apps/totem/servicios/${servicio.id}`, { ...servicio, active: false, prefix: run.slice(-4) }).catch(() => {})
		},
	}
}

/** A patient at a kiosk page, with the browser's print counted instead of opening a dialog. */
async function kiosk(browser, path) {
	const patient = await browser.newContext({ locale: 'es-CL', viewport: { width: 1080, height: 1920 } })
	await patient.addInitScript(() => {
		window.__prints = 0
		window.print = () => { window.__prints += 1 }
	})
	const screen = await patient.newPage()
	await screen.goto(path)
	return { patient, screen }
}

/** Sí and No ignore a tap within 500 ms of the servicio bar tap, and servicio bars one within 500 ms of a return home. */
async function waitOutDoubleTap(page) {
	await page.waitForTimeout(500)
}

/** Any Pantalla's feed request, the one the display polls. */
const FEED = /\/apps\/totem\/display\/[^/]+\/feed/

/**
 * The display of `w`'s Pantalla, its AudioContext kept on window.__audio; `locked` suspends it as it is made.
 * Every chime the page rings builds one compressor, so window.__chimes counts the chimes without playing them.
 */
async function openDisplay(browser, w, { locked = false } = {}) {
	const room = await (await browser.newContext({ locale: 'es-CL' })).newPage()
	await room.addInitScript((startLocked) => {
		window.__chimes = 0
		const Native = window.AudioContext
		window.AudioContext = class extends Native {
			constructor(...args) {
				super(...args)
				window.__audio = this
				if (startLocked) this.suspend()
			}

			createDynamicsCompressor() {
				window.__chimes += 1
				return super.createDynamicsCompressor()
			}
		}
	}, locked)
	await room.goto('/apps/totem/display/' + w.screen.slug)
	// The caption names the Pantalla's own sound once the first feed answer is in: from here a llamado is new.
	await expect(room.locator('.d-foot .cap')).toHaveText(/^Sonido (apagado|activado|y voz activados)/)
	return room
}

/** The chimes `room` (from openDisplay) has rung so far. */
const chimes = (room) => room.evaluate(() => window.__chimes)

/** Frees the box of its called turno, takes a new one and calls it; answers its label. */
async function callNew(page, w) {
	const { current } = await call(page, 'GET', `/apps/totem/console/${w.box.id}/state`)
	if (current?.estado === 'called') await call(page, 'POST', `/apps/totem/console/${w.box.id}/absent`)
	const turno = await call(page, 'POST', '/apps/totem/turnos', { servicioId: w.servicio.id, preferente: false })
	await call(page, 'POST', `/apps/totem/console/${w.box.id}/call`)
	return turno.label
}

module.exports = { FEED, call, callNew, chimes, kiosk, login, openDisplay, waitOutDoubleTap, world }
