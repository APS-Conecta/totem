// The walking skeleton runs against the throwaway instance (`make instance-up`):
// real Nextcloud 34 + PostgreSQL, the app enabled, admin / admin.
const { defineConfig } = require('@playwright/test')

module.exports = defineConfig({
	testDir: 'e2e',
	timeout: 60_000,
	use: { baseURL: process.env.TOTEM_URL || 'http://localhost:8094', locale: 'es-CL' },
	// Chromium only: the kiosk and the staff laptops run Chrome.
	// PW_CHANNEL=chrome runs the machine's own Chrome (useful where Playwright has no build for the OS).
	// The kiosk runs Chrome with --autoplay-policy=no-user-gesture-required: the tests do too.
	projects: [{ name: 'chromium', use: { browserName: 'chromium', channel: process.env.PW_CHANNEL || undefined, launchOptions: { args: ['--autoplay-policy=no-user-gesture-required'] } } }],
})
