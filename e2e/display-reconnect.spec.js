// SPDX-License-Identifier: AGPL-3.0-or-later
//
// The Pantalla after a feed outage (#36): the first answer when the feed comes back carries every
// llamado made meanwhile. It shows them all but plays one Aviso, for the newest; later answers
// announce each new llamado. The outage is the page's own feed requests failing (page.route), the
// llamados are made through the console API, and the Avisos are counted as chimes (openDisplay).
//
// The Pantalla has sound but no voice, so an Aviso is the chime alone. The announcer rings a queued
// chime 2.4 s after the one before it (the chime's 2.2 s plus 200 ms), so a count still unchanged
// after AVISO_GAP_MS has no Aviso waiting behind it.

const { test, expect } = require('@playwright/test')
const { FEED, callNew, chimes, login, openDisplay, world } = require('./helpers')

const OFFLINE = 'Sin conexión con el servidor. Reintentando…'
const UNLOCK = 'Toque aquí para activar el sonido'
const AVISO_GAP_MS = 3_000
// poll's backoff doubles from 2 s while the feed fails, so the answer after an outage can take a while.
const BACK_ONLINE = { timeout: 20_000 }

/** Fails `room`'s feed requests until restoreFeed, and waits for the Pantalla to say so. */
async function cutFeed(room, { times } = {}) {
	await room.route(FEED, (route) => route.abort(), { times })
	await expect(room.getByText(OFFLINE)).toBeVisible({ timeout: 10_000 })
}

const restoreFeed = (room) => room.unroute(FEED)

/** Waits out the announcer's queue and answers the chimes rung by then. */
async function settledChimes(room) {
	await room.waitForTimeout(AVISO_GAP_MS)
	return chimes(room)
}

test.describe('the Pantalla after a feed outage', () => {
	let w

	test.beforeEach(async ({ page }) => {
		await login(page)
		w = await world(page, { pantalla: { volume: 100, voice: 'off' } })
	})
	test.afterEach(async () => { await w?.cleanup() })

	test('the llamados made during an outage play one Aviso, for the newest', async ({ browser, page }) => {
		const before = await callNew(page, w)
		const room = await openDisplay(browser, w)
		const card = room.getByRole('status')
		await expect(card).toContainText(before)
		await expect(room.getByRole('button', { name: UNLOCK })).toBeHidden()

		await cutFeed(room)
		const missed = [await callNew(page, w), await callNew(page, w), await callNew(page, w)]
		await restoreFeed(room)

		await expect(card).toContainText(missed[2], BACK_ONLINE)
		await expect(room.getByText(OFFLINE)).toBeHidden()
		const list = room.locator('.d-list')
		await expect(list).toContainText(missed[0])
		await expect(list).toContainText(missed[1])
		await expect(list).toContainText(before)
		await expect.poll(() => chimes(room)).toBe(1)
		expect(await settledChimes(room)).toBe(1)

		// Back to normal: the next llamado is heard.
		const next = await callNew(page, w)
		await expect(card).toContainText(next, { timeout: 8_000 })
		await expect.poll(() => chimes(room)).toBe(2)
		expect(await settledChimes(room)).toBe(2)
	})

	test('a llamado made during one failed poll plays its Aviso', async ({ browser, page }) => {
		const room = await openDisplay(browser, w)
		const card = room.getByRole('status')

		await cutFeed(room, { times: 1 })
		const heard = await callNew(page, w)

		await expect(card).toContainText(heard, BACK_ONLINE)
		await expect.poll(() => chimes(room)).toBe(1)
		expect(await settledChimes(room)).toBe(1)
	})

	test('opening a Pantalla on llamados already made plays no Aviso', async ({ browser, page }) => {
		const shown = await callNew(page, w)
		const room = await openDisplay(browser, w)

		await expect(room.getByRole('status')).toContainText(shown)
		expect(await settledChimes(room)).toBe(0)
	})

	test('the llamados made during an outage play nothing while the sound is locked', async ({ browser, page }) => {
		const room = await openDisplay(browser, w, { locked: true })
		await expect(room.getByRole('button', { name: UNLOCK })).toBeVisible()

		await cutFeed(room)
		const missed = [await callNew(page, w), await callNew(page, w)]
		await restoreFeed(room)

		await expect(room.getByRole('status')).toContainText(missed[1], BACK_ONLINE)
		expect(await settledChimes(room)).toBe(0)
	})
})
