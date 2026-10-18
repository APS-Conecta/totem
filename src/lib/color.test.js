import { describe, expect, it } from 'vitest'
import { INK, contrastRatio, hasLegibleText, readableOn } from './color.js'

describe('contrastRatio', () => {
	it('is 21:1 for black on white and 1:1 for a color on itself', () => {
		expect(contrastRatio('#000000', '#ffffff')).toBeCloseTo(21, 5)
		expect(contrastRatio('#0b6e69', '#0b6e69')).toBeCloseTo(1, 5)
	})

	it('does not depend on which color is the text', () => {
		expect(contrastRatio('#0b6e69', '#ffffff')).toBeCloseTo(contrastRatio('#ffffff', '#0b6e69'), 10)
	})
})

describe('readableOn', () => {
	it('picks white on a dark servicio color', () => {
		expect(readableOn('#0b6e69')).toBe('#ffffff')
		expect(readableOn('#a32035')).toBe('#ffffff')
	})

	it('picks the ink on a light servicio color', () => {
		expect(readableOn('#f2c400')).toBe(INK)
	})
})

describe('hasLegibleText', () => {
	it('accepts the mockup servicio colors, text at 4.5:1 or better', () => {
		for (const color of ['#0b6e69', '#a32035', '#8a5a00', '#1f4fb4', '#2e7d32', '#f2c400', '#4a4f57']) {
			expect(hasLegibleText(color), color).toBe(true)
		}
	})

	it('rejects a mid tone where neither white nor the ink reaches 4.5:1', () => {
		expect(hasLegibleText('#7a7a7a')).toBe(false)
	})
})
