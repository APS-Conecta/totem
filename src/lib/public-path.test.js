import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { expect, it, vi } from 'vitest'

vi.mock('@nextcloud/router', () => ({ generateFilePath: (app, type, file) => `/custom_apps/${app}/${type ? type + '/' : ''}${file}` }))

it('points webpack at this app\'s own js folder, wherever Nextcloud keeps the app (custom_apps, not /apps)', async () => {
	globalThis.__webpack_public_path__ = '/apps/totem/js/'
	await import('./public-path.js')

	expect(globalThis.__webpack_public_path__).toBe('/custom_apps/totem/js/')
})

it.each(['display', 'staff'])('is imported first by the %s entry, which loads the voice worker', (entry) => {
	const source = readFileSync(fileURLToPath(new URL(`../${entry}/main.js`, import.meta.url)), 'utf8')
	expect(source.match(/^import\b.*$/m)?.[0]).toBe('import \'../lib/public-path.js\'')
})
