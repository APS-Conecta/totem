// The suite's browser build, channel, launch flags, instance URL and locale live in
// e2e/browser.js — the project here, the global setup's warm-up launch and every
// hand-made context in a spec all use that one source.
const { defineConfig } = require('@playwright/test')
const { channel, contextOptions, launchOptions } = require('./e2e/browser')

module.exports = defineConfig({
	testDir: 'e2e',
	timeout: 60_000,
	// The admin's first web login must happen once, alone, before the workers' parallel
	// first logins race in Nextcloud core (#31) — e2e/global-setup.js tells the story.
	globalSetup: './e2e/global-setup.js',
	use: contextOptions,
	// Chromium only: the kiosk and the staff laptops run Chrome.
	projects: [{ name: 'chromium', use: { browserName: 'chromium', channel, launchOptions } }],
})
