// SPDX-License-Identifier: AGPL-3.0-or-later
//
// One warm-up login, before any worker starts. The suite's parallel workers all log the
// admin in for their first test at the same moment; on a fresh instance those are the
// admin's first web logins ever, and every login POST saves the login form's timezone as
// the admin's `core`/`timezone` user setting. Concurrent first-time writers of that one
// row race in Nextcloud core: all but one of the logins get core's Internal Server Error
// page (an OCP\Config\Exceptions\UnknownKeyException in the instance log) and time out in
// login(). Logging in once, alone, here — through the same login() steps, in the project's
// own build, channel, instance URL and locale (e2e/browser.js) — writes the setting first,
// so every worker's login, concurrent or not, finds it already saved (#31).
// login.spec.js keeps this guard honest.

const { chromium } = require('@playwright/test')
const { channel, contextOptions, launchOptions } = require('./browser')
const { login } = require('./helpers')

module.exports = async () => {
	const browser = await chromium.launch({ channel, ...launchOptions })
	try {
		await login(await (await browser.newContext(contextOptions)).newPage())
	} finally {
		await browser.close()
	}
}
