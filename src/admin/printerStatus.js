// SPDX-License-Identifier: AGPL-3.0-or-later

const pad = (n) => String(n).padStart(2, '0')

/** The time of day of a Unix timestamp, HH:MM. */
function clock(seconds) {
	const date = new Date(seconds * 1000)
	return `${pad(date.getHours())}:${pad(date.getMinutes())}`
}

/**
 * How the last print at a totem's printer went, as the configuration says it: a dash when the totem has no
 * network printer, "Sin probar" before any attempt, then when it printed or when it did not answer.
 *
 * @param {{ printerHost: ?string, printerOk: ?boolean, printerCheckedAt: ?number }} totem
 * @return {{ text: string, bad: boolean }}
 */
export function printerStatus({ printerHost, printerOk, printerCheckedAt }) {
	if (!printerHost) return { text: '–', bad: false }
	if (printerOk === null || printerCheckedAt === null) return { text: 'Sin probar', bad: false }
	return printerOk
		? { text: `Imprimió a las ${clock(printerCheckedAt)}`, bad: false }
		: { text: `No respondió a las ${clock(printerCheckedAt)}`, bad: true }
}
