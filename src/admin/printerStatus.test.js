// SPDX-License-Identifier: AGPL-3.0-or-later
import { describe, expect, it } from 'vitest'
import { printerStatus } from './printerStatus.js'

const at = (hour, minute) => Math.floor(new Date(2026, 9, 19, hour, minute).getTime() / 1000)

describe('how the last print is said', () => {
	it('is a dash for a totem with no network printer: the browser prints', () => {
		expect(printerStatus({ printerHost: null, printerOk: null, printerCheckedAt: null })).toEqual({ text: '–', bad: false })
		expect(printerStatus({ printerHost: '', printerOk: true, printerCheckedAt: at(10, 0) })).toEqual({ text: '–', bad: false })
	})

	it('says a printer was never tried', () => {
		expect(printerStatus({ printerHost: '192.168.1.50', printerOk: null, printerCheckedAt: null })).toEqual({ text: 'Sin probar', bad: false })
	})

	it('says when a print went through, to the minute', () => {
		expect(printerStatus({ printerHost: '192.168.1.50', printerOk: true, printerCheckedAt: at(9, 5) })).toEqual({ text: 'Imprimió a las 09:05', bad: false })
	})

	it('says when the printer did not answer, and marks it', () => {
		expect(printerStatus({ printerHost: '192.168.1.50', printerOk: false, printerCheckedAt: at(14, 32) })).toEqual({ text: 'No respondió a las 14:32', bad: true })
	})
})
