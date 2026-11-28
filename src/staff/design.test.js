// SPDX-License-Identifier: AGPL-3.0-or-later
import { readFileSync } from 'node:fs'
import { describe, expect, it } from 'vitest'

// The staff pages are ordinary Nextcloud pages, so they take their look from Nextcloud's variables: that is what
// makes theming and dark mode work without a line of code here. The org's shared style check (CI's `style` job)
// keeps literal colours, pixel font sizes and plain buttons out of the whole app; this test holds the two rules
// only Tótem's staff sheet has.

const sheet = readFileSync(new URL('./staff.css', import.meta.url), 'utf8').replace(/\/\*[\s\S]*?\*\//g, '')

describe('the staff sheet', () => {
	it('gives touch screens the large control size, and leaves a mouse the platform default', () => {
		expect(sheet).toMatch(/@media \(pointer: coarse\)\s*\{\s*\.totem-staff\s*\{\s*--default-clickable-area:\s*var\(--clickable-area-large\);/)
	})

	it('keeps the content clear of the navigation toggle Nextcloud floats over its top-left corner, at every width', () => {
		const pane = sheet.match(/\.totem-pane\s*\{[^}]*\}/)[0]

		expect(pane).toMatch(/padding-inline-start:\s*calc\(var\(--default-clickable-area\)/)
	})
})
