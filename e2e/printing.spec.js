// SPDX-License-Identifier: AGPL-3.0-or-later
//
// A ticket printed by the server, end to end: a totem is configured with a network printer, a patient takes a
// ticket anonymously, and the bytes arrive at the printer. The printer is e2e/fake-printer.cjs (the `printer`
// service of compose.dev.yaml, started by `make instance-up`); a test reads what it received over HTTP.
//
// The screens that configure a totem and show a failed print are covered by their own component tests; this
// file is about the server path and its guards.

const { test, expect } = require('@playwright/test')
const { call, login, world } = require('./helpers')

const PRINTER = process.env.TOTEM_PRINTER_URL || 'http://127.0.0.1:9191'
const ESC_POS_CUT = '\x1D\x56\x42\x03'

async function printerReceived() {
	const answer = await (await fetch(PRINTER + '/')).json()
	return { count: answer.count, text: answer.bytes === null ? null : Buffer.from(answer.bytes, 'base64').toString('latin1') }
}

const forgetTickets = () => fetch(PRINTER + '/reset')

let w
let totems

test.beforeEach(async ({ page }) => {
	await login(page)
	w = await world(page)
	totems = []
	await forgetTickets()
})

test.afterEach(async ({ page }) => {
	for (const totem of totems) await call(page, 'DELETE', `/apps/totem/totems/${totem.id}`).catch(() => {})
	await w.cleanup()
})

/** A totem for this run's unidad, with the fake printer (or another address) as its printer. */
async function totem(page, over = {}) {
	const created = await call(page, 'POST', '/apps/totem/totems', {
		name: `Tótem ${w.prefix}`, servicioIds: [w.servicio.id], printerHost: 'printer', printerPort: 9100, ...over,
	})
	totems.push(created)
	return created
}

/** The patient: anonymous, taking a ticket at a totem. The body is read before the visitor goes away. */
async function takeTicket(browser, servicioId, preferente, slug) {
	const patient = await browser.newContext({ locale: 'es-CL' })
	try {
		const response = await patient.request.post('/apps/totem/turnos', { data: { servicioId, preferente, totem: slug } })
		return { status: response.status(), body: await response.json() }
	} finally {
		await patient.close()
	}
}

test('a ticket taken at a totem with a printer is printed there, and the answer says so', async ({ page, browser }) => {
	const kiosk = await totem(page)

	const answer = await takeTicket(browser, w.servicio.id, true, kiosk.slug)

	expect(answer.status).toBe(200)
	const issued = answer.body
	expect(issued.printed).toBe(true)
	expect(issued.label).toBe(`${w.prefix}-P-001`)
	await expect.poll(async () => (await printerReceived()).count).toBe(1)
	const { text } = await printerReceived()
	expect(text).toContain(`${w.prefix}-P-001`)
	expect(text).toContain(w.servicio.name)
	expect(text).toContain('ATENCI\xE0N PREFERENTE')
	expect(text.endsWith(ESC_POS_CUT)).toBe(true)
	const saved = (await call(page, 'GET', '/apps/totem/totems')).find((t) => t.id === kiosk.id)
	expect(saved.printerOk).toBe(true)
	expect(saved.printerCheckedAt).toBeGreaterThan(0)
})

test('a general ticket does not say atención preferente', async ({ page, browser }) => {
	const kiosk = await totem(page)

	const issued = (await takeTicket(browser, w.servicio.id, false, kiosk.slug)).body

	expect(issued.label).toBe(`${w.prefix}-G-001`)
	await expect.poll(async () => (await printerReceived()).count).toBe(1)
	expect((await printerReceived()).text).not.toContain('PREFERENTE')
})

test('a printer that does not answer never stops the ticket, and the totem remembers it', async ({ page, browser }) => {
	const kiosk = await totem(page, { printerPort: 9109 }) // nothing listens there

	const answer = await takeTicket(browser, w.servicio.id, false, kiosk.slug)

	expect(answer.status).toBe(200)
	const issued = answer.body
	expect(issued.printed).toBe(false)
	expect(issued.label).toBe(`${w.prefix}-G-001`)
	const saved = (await call(page, 'GET', '/apps/totem/totems')).find((t) => t.id === kiosk.id)
	expect(saved.printerOk).toBe(false)
})

test('a totem without a printer leaves printing to the browser', async ({ page, browser }) => {
	const kiosk = await totem(page, { printerHost: '' })

	const issued = (await takeTicket(browser, w.servicio.id, false, kiosk.slug)).body

	expect(issued.printed).toBeNull()
	expect((await printerReceived()).count).toBe(0)
})

test('the default kiosk prints nothing on the server', async ({ browser }) => {
	const issued = (await takeTicket(browser, w.servicio.id, false, undefined)).body

	expect(issued.printed).toBeNull()
	expect((await printerReceived()).count).toBe(0)
})

test('a totem that lists unidades refuses a ticket for another one', async ({ page, browser }) => {
	const other = await call(page, 'POST', '/apps/totem/servicios', {
		name: `Otra ${w.prefix}`, prefix: w.prefix.startsWith('Z') ? 'Y' + w.prefix.slice(1) : 'Z' + w.prefix.slice(1), color: '#6b46c1', opens: '00:00', closes: '23:59', dailyQuota: null,
	})
	try {
		const kiosk = await totem(page)

		const answer = await takeTicket(browser, other.id, false, kiosk.slug)

		expect(answer.status).toBe(422)
		expect(answer.body.message).toBe('Este tótem no ofrece esa unidad.')
		expect((await printerReceived()).count).toBe(0)
	} finally {
		await call(page, 'PUT', `/apps/totem/servicios/${other.id}`, { ...other, active: false }).catch(() => {})
	}
})

test('"Probar impresión" prints a sample ticket and reports it', async ({ page }) => {
	const kiosk = await totem(page)

	const result = await call(page, 'POST', `/apps/totem/totems/${kiosk.id}/test-print`)

	expect(result.printed).toBe(true)
	expect(result.totem.printerOk).toBe(true)
	await expect.poll(async () => (await printerReceived()).count).toBe(1)
	expect((await printerReceived()).text).toContain('PRUEBA')
})

test('"Probar impresión" says so when the totem has no network printer', async ({ page }) => {
	const kiosk = await totem(page, { printerHost: '' })

	await expect(call(page, 'POST', `/apps/totem/totems/${kiosk.id}/test-print`)).rejects.toThrow(/impresora de red/)
})

test.describe('where a printer may be', () => {
	for (const [host, why] of [['127.0.0.1', 'loopback'], ['8.8.8.8', 'a public address'], ['169.254.169.254', 'the cloud metadata address']]) {
		test(`refuses ${why} as a printer address`, async ({ page }) => {
			await expect(totem(page, { printerHost: host })).rejects.toThrow(/red de la clínica/)
		})
	}

	test('refuses a port that is not a printer\'s', async ({ page }) => {
		await expect(totem(page, { printerPort: 22 })).rejects.toThrow(/entre 9100 y 9109/)
	})
})

test('a kiosk lists only the unidades its totem offers, and the default one lists them all', async ({ page, browser }) => {
	const kiosk = await totem(page)
	const patient = await browser.newContext({ locale: 'es-CL' })
	try {
		const own = await (await patient.request.get(`/apps/totem/public/servicios?totem=${kiosk.slug}`)).json()
		expect(own.map((s) => s.id)).toEqual([w.servicio.id])

		const all = await (await patient.request.get('/apps/totem/public/servicios')).json()
		expect(all.map((s) => s.id)).toContain(w.servicio.id)

		expect((await patient.request.get('/apps/totem/public/servicios?totem=borrado')).status()).toBe(404)
	} finally {
		await patient.close()
	}
})
