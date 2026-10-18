// SPDX-License-Identifier: AGPL-3.0-or-later

import { messageFrom } from '../api.js'

/** A 404 on save or delete: someone else removed it meanwhile. */
export const isGone = (error) => error?.response?.status === 404

/** The sentence to show next to a form: the server's refusal when it sent one, else `gone` for a 404, else `fallback`. */
export async function reasonFor(error, { gone, fallback }) {
	return isGone(error) ? gone : messageFrom(error, fallback)
}
