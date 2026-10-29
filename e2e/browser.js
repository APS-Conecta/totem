// SPDX-License-Identifier: AGPL-3.0-or-later
//
// The suite's browser shape in one place: playwright.config.js builds the project from it
// and the global setup's warm-up launch (global-setup.js) matches it. The global setup
// runs outside the test runner, where nothing inherits the config's options, so without
// this module it would have to copy the channel, flags, instance URL and locale by hand
// — and could drift from the project.

// The suite runs against the throwaway instance (`make instance-up`): real Nextcloud 34 +
// PostgreSQL, the app enabled, admin / admin. TOTEM_URL points it somewhere else.
const contextOptions = {
	baseURL: process.env.TOTEM_URL || 'http://localhost:8094',
	locale: 'es-CL',
}

// Chromium only: the kiosk and the staff laptops run Chrome.
// PW_CHANNEL=chrome runs the machine's own Chrome (useful where Playwright has no build for the OS).
// The kiosk runs Chrome with --autoplay-policy=no-user-gesture-required: the tests do too.
const channel = process.env.PW_CHANNEL || undefined
const launchOptions = { args: ['--autoplay-policy=no-user-gesture-required'] }

module.exports = { channel, contextOptions, launchOptions }
