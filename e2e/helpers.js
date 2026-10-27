// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Shared plumbing for the end-to-end tests: log in as the instance's admin, talk to the admin
// endpoints with the session's request token, build (and tear down) a small world of one
// servicio, one box and one pantalla with names that cannot collide with an earlier run, and
// wait out the totem's double-tap window before answering its question.

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
 * `servicioName(run)` and `boxName` override the names (the servicio's must stay unique: it gets the run id).
 * A servicio open all day, a box that covers it and a pantalla that shows it. `pantalla` sets the
 * pantalla's sound; the default is silent so a test run makes no noise.
 */
async function world(page, { pantalla = { volume: 0, voice: 'off' }, servicioName, boxName } = {}) {
	const run = Date.now().toString(36).toUpperCase()
	const prefix = run.slice(-4)
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
			await call(page, 'PUT', `/apps/totem/servicios/${servicio.id}`, { ...servicio, active: false }).catch(() => {})
		},
	}
}

/** Sí and No ignore taps for 500 ms after the question appears, so a double tap on a servicio bar cannot answer it. */
async function waitOutDoubleTap(page) {
	await page.waitForTimeout(500)
}

module.exports = { call, login, waitOutDoubleTap, world }
