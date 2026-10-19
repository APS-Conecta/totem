// SPDX-License-Identifier: AGPL-3.0-or-later

// The words of the statistics board. Written out rather than read from Intl, so the text is the same on
// every browser and ICU build: the board is read aloud across a desk and compared with a paper count.

const WEEKDAYS = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado']
const MONTHS = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre']

/** A Y-m-d day as a UTC date, so the machine's time zone never moves it. */
const parse = (day) => new Date(day + 'T00:00:00Z')

/** A whole number with a dot between thousands, as es-CL writes it. */
export const count = (n) => String(n).replace(/\B(?=(\d{3})+(?!\d))/g, '.')

/** "lun, 19 oct". */
export function dayLabel(day) {
	const d = parse(day)
	return `${WEEKDAYS[d.getUTCDay()].slice(0, 3)}, ${d.getUTCDate()} ${MONTHS[d.getUTCMonth()].slice(0, 3)}`
}

/** What the arrows are moving over: "Semana del 19 al 25 de octubre de 2026". */
export function periodLabel({ kind, from, to }) {
	const a = parse(from)
	const b = parse(to)
	const year = b.getUTCFullYear()
	if (kind === 'day') {
		return `${WEEKDAYS[a.getUTCDay()]} ${a.getUTCDate()} de ${MONTHS[a.getUTCMonth()]} de ${year}`
	}
	if (kind === 'month') {
		return `${MONTHS[a.getUTCMonth()]} de ${year}`
	}
	if (a.getUTCFullYear() !== year) {
		return `Semana del ${a.getUTCDate()} de ${MONTHS[a.getUTCMonth()]} de ${a.getUTCFullYear()} al ${b.getUTCDate()} de ${MONTHS[b.getUTCMonth()]} de ${year}`
	}
	if (a.getUTCMonth() !== b.getUTCMonth()) {
		return `Semana del ${a.getUTCDate()} de ${MONTHS[a.getUTCMonth()]} al ${b.getUTCDate()} de ${MONTHS[b.getUTCMonth()]} de ${year}`
	}
	return `Semana del ${a.getUTCDate()} al ${b.getUTCDate()} de ${MONTHS[b.getUTCMonth()]} de ${year}`
}
