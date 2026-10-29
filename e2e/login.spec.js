// SPDX-License-Identifier: AGPL-3.0-or-later
//
// The guard for the warm-up login in global-setup.js: six fresh contexts log the admin in
// together, the way the parallel workers' first tests do. On a fresh instance without the
// warm-up those concurrent first logins race in Nextcloud core — global-setup.js tells the
// full story — and all but one get core's Internal Server Error page. With the setting
// already written, every one of them reaches /apps/.

const { test, expect } = require('@playwright/test')
const { login } = require('./helpers')

test('six simultaneous admin logins all reach /apps/', async ({ browser }) => {
	const contexts = await Promise.all(Array.from({ length: 6 }, () => browser.newContext()))
	try {
		const pages = await Promise.all(contexts.map((context) => context.newPage()))
		const failures = (await Promise.allSettled(pages.map((page) => login(page))))
			.filter((result) => result.status === 'rejected')
			.map((result) => result.reason?.message ?? String(result.reason))
		expect(
			failures,
			'a concurrent login never reached /apps/ — without the warm-up login, Nextcloud core ' +
			'answers it with its Internal Server Error page: the admin\'s `core`/`timezone` user setting ' +
			'raced (an OCP\\Config\\Exceptions\\UnknownKeyException in the instance log). global-setup.js ' +
			'is what keeps them apart (#31)',
		).toEqual([])
	} finally {
		await Promise.all(contexts.map((context) => context.close()))
	}
})
