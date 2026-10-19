// SPDX-License-Identifier: AGPL-3.0-or-later
//
// The staff app, end to end: one Nextcloud app with a menu (Operación and Configuración), the section in the
// middle and the sidebar on the right. Names carry the run's id so nothing collides with an earlier run.

const { test, expect } = require('@playwright/test')
const { call, login, world } = require('./helpers')

const SECTIONS = [
	{ link: 'Ventanilla', path: '/apps/totem/box', title: null },
	{ link: 'Estadísticas', path: '/apps/totem/estadisticas', title: 'Estadísticas' },
	{ link: 'Unidades', path: '/apps/totem/admin#servicios', title: 'Unidades' },
	{ link: 'Ventanillas', path: '/apps/totem/admin#boxes', title: 'Ventanillas' },
	{ link: 'Pantallas', path: '/apps/totem/admin#pantallas', title: 'Pantallas' },
	{ link: 'Tótems', path: '/apps/totem/admin#totems', title: 'Tótems' },
]
const here = (page) => new URL(page.url()).pathname + new URL(page.url()).hash
const menu = (page) => page.getByRole('navigation', { name: 'Totem' })

test.describe('the menu', () => {
	test('reaches every section, keeps the address in step and follows the back button', async ({ page }) => {
		await login(page)
		await page.goto('/apps/totem/box')
		await expect(page.getByLabel('Ventanilla', { exact: true })).toBeVisible()

		// Two labelled groups, the same entries for everyone.
		await expect(menu(page).getByText('Operación', { exact: true })).toBeVisible()
		await expect(menu(page).getByText('Configuración', { exact: true })).toBeVisible()

		for (const section of SECTIONS.slice(1)) {
			await menu(page).getByRole('link', { name: section.link, exact: true }).click()
			await expect(page.getByRole('heading', { level: 2, name: section.title, exact: true }).first()).toBeVisible()
			expect(here(page)).toBe(section.path)
			await expect(menu(page).getByRole('link', { name: section.link, exact: true })).toHaveAttribute('aria-current', 'page')
		}

		await page.goBack()
		await expect(page.getByRole('heading', { level: 2, name: 'Ventanillas', exact: true }).first()).toBeVisible()
		expect(here(page)).toBe('/apps/totem/admin#boxes')
	})

	test('opens the section its old address names', async ({ page }) => {
		await login(page)

		await page.goto('/apps/totem/estadisticas')
		await expect(page.getByRole('heading', { level: 2, name: 'Estadísticas', exact: true }).first()).toBeVisible()

		await page.goto('/apps/totem/admin#pantallas')
		await expect(page.getByRole('heading', { level: 2, name: 'Pantallas', exact: true }).first()).toBeVisible()
	})

	test('opens the totem in a new tab', async ({ page, context }) => {
		await login(page)
		await page.goto('/apps/totem/box')

		const [kiosk] = await Promise.all([context.waitForEvent('page'), page.getByRole('link', { name: 'Abrir tótem' }).click()])

		await expect(kiosk).toHaveURL(/\/apps\/totem\/totem$/)
	})
})

test.describe('configuration in the sidebar', () => {
	test('a unidad is created in the sidebar and shows in the list', async ({ page }) => {
		await login(page)
		await page.goto('/apps/totem/admin#servicios')
		const run = Date.now().toString(36).toUpperCase()
		const name = `Unidad ${run}`
		await expect(page.getByRole('heading', { level: 2, name: 'Unidades', exact: true }).first()).toBeVisible()

		await page.getByRole('button', { name: 'Nueva unidad' }).click()
		const sidebar = page.getByRole('complementary')
		await expect(sidebar.getByRole('heading', { name: 'Nueva unidad' })).toBeVisible()
		await sidebar.getByLabel('Nombre').fill(name)
		await sidebar.getByLabel('Prefijo').fill(run.slice(-4))
		await sidebar.getByText('Es un sector').click()
		await sidebar.getByRole('button', { name: 'Guardar unidad' }).click()

		await expect(sidebar.getByText('Guardado.')).toBeVisible()
		const row = page.getByRole('row', { name: new RegExp(name) })
		await expect(row).toContainText('Sector')

		const created = await call(page, 'GET', '/apps/totem/servicios')
		const saved = created.find((s) => s.name === name)
		await call(page, 'PUT', `/apps/totem/servicios/${saved.id}`, { ...saved, active: false })
	})

	test('"Abrir pantalla" opens the display in a new tab', async ({ page, context }) => {
		await login(page)
		const w = await world(page)
		try {
			await page.goto('/apps/totem/admin#pantallas')
			const row = page.getByRole('row', { name: new RegExp(w.screen.name) })

			const [display] = await Promise.all([context.waitForEvent('page'), row.getByRole('link', { name: 'Abrir pantalla' }).click()])

			await expect(display).toHaveURL(new RegExp(`/apps/totem/display/${w.screen.slug}$`))
			await expect(display.getByRole('status')).toBeVisible()
		} finally {
			await w.cleanup()
		}
	})

	test('is open to a logged-in user who is not an admin', async ({ browser, page }) => {
		// Creating a user's first session and deleting the user afterwards are both slow in Nextcloud.
		test.setTimeout(180_000)
		await login(page)
		const run = Date.now().toString(36)
		const user = `staff-${run}`
		const password = `Totem-${run}-Clinic-2026!`
		const created = await page.request.post('/ocs/v2.php/cloud/users', {
			headers: { 'OCS-APIRequest': 'true', Authorization: 'Basic ' + Buffer.from('admin:admin').toString('base64') },
			form: { userid: user, password },
		})
		expect(created.ok()).toBe(true)
		try {
			const staff = await (await browser.newContext({ locale: 'es-CL' })).newPage()
			await login(staff, user, password)

			await staff.goto('/apps/totem/admin#servicios')
			await expect(staff.getByRole('heading', { level: 2, name: 'Unidades', exact: true }).first()).toBeVisible()
			await expect(menu(staff).getByRole('link', { name: 'Pantallas', exact: true })).toBeVisible()
			expect(await call(staff, 'GET', '/apps/totem/servicios')).toBeInstanceOf(Array)
			await staff.context().close()
		} finally {
			await page.request.delete(`/ocs/v2.php/cloud/users/${user}`, {
				headers: { 'OCS-APIRequest': 'true', Authorization: 'Basic ' + Buffer.from('admin:admin').toString('base64') },
				timeout: 120_000,
			})
		}
	})
})

test.describe('the staff app fits any screen', () => {
	// A laptop with a mouse keeps Nextcloud's standard control size; tablets and phones are touch and get the large one.
	const SCREENS = [
		{ width: 1366, height: 768, touch: false, minimum: 34 },
		{ width: 768, height: 900, touch: true, minimum: 48 },
		{ width: 360, height: 740, touch: true, minimum: 48 },
	]

	for (const { width, height, touch, minimum } of SCREENS) {
		test(`at ${width}x${height} (${touch ? 'touch' : 'mouse'}) no section scrolls sideways and every control is at least ${minimum}px`, async ({ browser }) => {
			const page = await (await browser.newContext({ viewport: { width, height }, hasTouch: touch, locale: 'es-CL' })).newPage()
			await login(page)

			for (const section of SECTIONS) {
				await page.goto(section.path)
				await expect(page.locator('.totem-pane')).toBeVisible()
				await page.waitForTimeout(400)

				const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth)
				expect(overflow, `${section.link} overflows the page`).toBeLessThanOrEqual(0)

				const small = await page.evaluate((least) => [...document.querySelectorAll('.totem-pane button, .totem-pane a.button-vue')]
					.filter((el) => el.offsetParent !== null && getComputedStyle(el).visibility !== 'hidden')
					.map((el) => ({ text: el.textContent.trim().slice(0, 24), height: Math.round(el.getBoundingClientRect().height) }))
					.filter((el) => el.height > 0 && el.height < least - 0.5), minimum)
				expect(small, `${section.link} has controls under ${minimum}px`).toEqual([])
			}
			await page.context().close()
		})
	}
})
