import { expect, it, vi } from 'vitest'

vi.mock('@nextcloud/router', () => ({ generateFilePath: (app, type, file) => `/custom_apps/${app}/${type ? type + '/' : ''}${file}` }))

it('points webpack at this app\'s own js folder, wherever Nextcloud keeps the app (custom_apps, not /apps)', async () => {
	globalThis.__webpack_public_path__ = '/apps/totem/js/'
	await import('./public-path.js')

	expect(globalThis.__webpack_public_path__).toBe('/custom_apps/totem/js/')
})
