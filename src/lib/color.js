// SPDX-License-Identifier: AGPL-3.0-or-later

// The text color on a servicio's color is computed, not chosen: a servicio color is
// admin data, so legibility cannot be assumed (WCAG 2.x relative luminance).

export const INK = '#13202b'
const WHITE = '#ffffff'

function luminance(hex) {
	const n = parseInt(hex.slice(1), 16)
	const [r, g, b] = [(n >> 16) & 255, (n >> 8) & 255, n & 255].map((v) => {
		const c = v / 255
		return c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4
	})
	return 0.2126 * r + 0.7152 * g + 0.0722 * b
}

export function contrastRatio(a, b) {
	const [x, y] = [luminance(a), luminance(b)]
	return (Math.max(x, y) + 0.05) / (Math.min(x, y) + 0.05)
}

/** White or the ink, whichever reads better on this background. */
export function readableOn(background) {
	return contrastRatio(background, WHITE) >= contrastRatio(background, INK) ? WHITE : INK
}

/** Whether the best text color reaches 4.5:1 (WCAG AA for body text) on this background. */
export function hasLegibleText(background) {
	return contrastRatio(background, readableOn(background)) >= 4.5
}
