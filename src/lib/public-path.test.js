import { readFileSync } from 'node:fs'
import { expect, it, vi } from 'vitest'
import webpackConfig from '../../webpack.config.js'

vi.mock('@nextcloud/router', () => ({ generateFilePath: (app, type, file) => `/custom_apps/${app}/${type ? type + '/' : ''}${file}` }))

it('points webpack at this app\'s own js folder, wherever Nextcloud keeps the app (custom_apps, not /apps)', async () => {
	globalThis.__webpack_public_path__ = '/apps/totem/js/'
	await import('./public-path.js')

	expect(globalThis.__webpack_public_path__).toBe('/custom_apps/totem/js/')
})

// Every entry the build bundles, read from the build's own config, so a page added there is checked too.
it.each(Object.entries(webpackConfig.entry))('is imported first by the %s entry', (page, entryFile) => {
	const source = readFileSync(entryFile, 'utf8')
	expect(source.match(/^import\b.*$/m)?.[0]).toBe('import \'../lib/public-path.js\'')
})
