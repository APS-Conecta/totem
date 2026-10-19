// SPDX-License-Identifier: AGPL-3.0-or-later
import { readdirSync, readFileSync } from 'node:fs'
import { join } from 'node:path'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'

// The staff pages are ordinary Nextcloud pages, so they take their look from Nextcloud's variables: that is
// what makes theming and dark mode work without a line of code here. Two things would quietly break it, and
// this test reads every style the staff pages ship to keep them out:
//   - a font size in pixels (it ignores the user's own text size; use em, rem, clamp() or --font-size-*);
//   - a literal colour (it ignores the theme; use --color-*). A unidad's colour is data, never CSS.
// The kiosk (src/totem) and the display (src/display) are signage with their own palette and are not read.

const SRC = fileURLToPath(new URL('..', import.meta.url))
const STAFF_DIRECTORIES = ['staff', 'box', 'stats', 'admin']

function stylesOf(directory) {
	return readdirSync(join(SRC, directory))
		.filter((name) => /\.(vue|css)$/.test(name))
		.map((name) => {
			const source = readFileSync(join(SRC, directory, name), 'utf8')
			const css = name.endsWith('.css') ? source : [...source.matchAll(/<style[^>]*>([\s\S]*?)<\/style>/g)].map((m) => m[1]).join('\n')
			return { file: `src/${directory}/${name}`, css: css.replace(/\/\*[\s\S]*?\*\//g, '') }
		})
		.filter((style) => style.css.trim() !== '')
}

const STYLES = STAFF_DIRECTORIES.flatMap(stylesOf)

describe('the styles of the staff pages', () => {
	it('are found: the check is not reading nothing', () => {
		expect(STYLES.map((s) => s.file)).toEqual(expect.arrayContaining(['src/staff/staff.css', 'src/box/BoxView.vue', 'src/stats/StatsView.vue', 'src/stats/StatsTable.vue']))
		expect(STYLES.length).toBeGreaterThanOrEqual(7)
	})

	it('set no font size in pixels', () => {
		const offenders = STYLES.flatMap(({ file, css }) => [...css.matchAll(/font-size:\s*[0-9.]+px/g)].map((m) => `${file}: ${m[0]}`))

		expect(offenders).toEqual([])
	})

	it('use no literal colour: only Nextcloud variables', () => {
		const offenders = STYLES.flatMap(({ file, css }) => [...css.matchAll(/#[0-9a-fA-F]{3,8}\b|\brgba?\(|\bhsla?\(/g)].map((m) => `${file}: ${m[0]}`))

		expect(offenders).toEqual([])
	})
})
