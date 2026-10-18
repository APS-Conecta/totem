// SPDX-License-Identifier: AGPL-3.0-or-later
//
// The screens must adapt (docs/adr/0006): nobody knows what screen a clinic will have. For each
// screen size and each state of the totem and the display, no text may leave the screen, no text
// may be wider than its box, and no list row may overflow. Includes a stress case at the layouts'
// design limits: a 4-letter prefix, a 28-character servicio and a 13-character box.

const { test, expect } = require('@playwright/test')
const { call, login, world } = require('./helpers')

const TOTEM_SIZES = [[1080, 1920], [720, 1280], [768, 1024], [1920, 1080], [1366, 768], [1024, 1024], [3840, 2160]]
const DISPLAY_SIZES = [[1920, 1080], [1366, 768], [1024, 768], [2560, 1080], [1080, 1920], [800, 480], [3840, 2160]]

/** Problems on the current page, as short strings: empty means the layout holds. */
function audit() {
	const bad = []
	const W = window.innerWidth
	const H = window.innerHeight
	for (const el of document.body.querySelectorAll('*')) {
		if (![...el.childNodes].some((n) => n.nodeType === 3 && n.textContent.trim())) continue
		const style = getComputedStyle(el)
		if (style.display === 'none' || style.visibility === 'hidden') continue
		const r = el.getBoundingClientRect()
		if (r.width === 0 || r.height === 0) continue
		if (el.closest('.paper, [hidden]')) continue // the print-only ticket is not on the screen
		const label = (el.className || el.tagName) + ':' + el.textContent.trim().slice(0, 16)
		if (r.left < -1.5 || r.top < -1.5 || r.right > W + 1.5 || r.bottom > H + 1.5) bad.push('outside ' + label)
		if (el.scrollWidth > el.clientWidth + 2 && style.overflowX === 'visible' && el.clientWidth > 0) bad.push('wider ' + label)
	}
	for (const row of document.querySelectorAll('.d-list')) {
		const box = row.getBoundingClientRect()
		for (const child of row.children) if (child.getBoundingClientRect().bottom > box.bottom + 1) bad.push('list overflow')
	}
	// A footer is a strip at the bottom: nothing else may sit under it (the totem's bars once did).
	for (const foot of document.querySelectorAll('.t-foot, .d-foot')) {
		const f = foot.getBoundingClientRect()
		for (const other of document.querySelectorAll('.t-bar, .t-choice, .t-pager, .d-now, .d-list, .t-ticket')) {
			if (foot.contains(other)) continue
			const o = other.getBoundingClientRect()
			if (o.bottom > f.top + 1.5 && o.top < f.bottom - 1.5 && o.right > f.left && o.left < f.right) bad.push('overlaps footer: ' + other.className.toString().slice(0, 20))
		}
	}
	for (const bar of document.querySelectorAll('button, [role=button]')) {
		if (bar.scrollHeight > bar.clientHeight + 2) bad.push('clipped ' + bar.textContent.trim().slice(0, 16))
	}
	return [...new Set(bad)]
}

test.describe('screens adapt to any size', () => {
	test.setTimeout(240_000)
	let page
	let w

	test.beforeAll(async ({ browser }) => {
		page = await (await browser.newContext({ locale: 'es-CL' })).newPage()
		await login(page)
		// 28 characters, the longest a servicio name may be; the box name is 13 of its 14.
		w = await world(page, { servicioName: (run) => ('Atención Odontológica ' + run).slice(0, 28), boxName: 'Ventanilla 12' })
		// A few calls so the display has a current card and a list.
		for (let i = 0; i < 4; i++) {
			await call(page, 'POST', '/apps/totem/turnos', { servicioId: w.servicio.id, preferente: i % 2 === 0 })
			await call(page, 'POST', `/apps/totem/console/${w.box.id}/call`)
			await call(page, 'POST', `/apps/totem/console/${w.box.id}/start`)
			await call(page, 'POST', `/apps/totem/console/${w.box.id}/finish`)
		}
		await call(page, 'POST', '/apps/totem/turnos', { servicioId: w.servicio.id, preferente: true })
		await call(page, 'POST', `/apps/totem/console/${w.box.id}/call`)
	})
	test.afterAll(async () => { await w?.cleanup() })

	for (const [width, height] of TOTEM_SIZES) {
		test(`totem at ${width}x${height}: home, question and ticket`, async ({ browser }) => {
			const patient = await (await browser.newContext({ locale: 'es-CL', viewport: { width, height } })).newPage()
			await patient.addInitScript(() => { window.print = () => {} })
			await patient.goto('/apps/totem/totem')
			const bar = patient.getByRole('button', { name: new RegExp(w.servicio.name) })
			await expect(bar).toBeVisible()
			expect(await patient.evaluate(audit), 'home').toEqual([])

			await bar.click()
			await expect(patient.getByRole('button', { name: 'Sí, atención preferente' })).toBeVisible()
			expect(await patient.evaluate(audit), 'question').toEqual([])

			await patient.getByRole('button', { name: 'Sí, atención preferente' }).click()
			await expect(patient.getByText('Su turno')).toBeVisible()
			expect(await patient.evaluate(audit), 'ticket').toEqual([])
		})
	}

	for (const [width, height] of DISPLAY_SIZES) {
		test(`display at ${width}x${height}`, async ({ browser }) => {
			const room = await (await browser.newContext({ locale: 'es-CL', viewport: { width, height } })).newPage()
			await room.goto('/apps/totem/display/' + w.screen.slug)
			await expect(room.getByRole('status')).toContainText(w.prefix)
			expect(await room.evaluate(audit)).toEqual([])
		})
	}
})
