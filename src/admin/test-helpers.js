// SPDX-License-Identifier: AGPL-3.0-or-later

// Shared by the admin component tests; not part of the bundle.

/** The control a visible name belongs to, found the way a person finds it: by the text, through <label for> or aria-labelledby. */
export function field(wrapper, text) {
	const label = wrapper.findAll('label').find((candidate) => candidate.text().startsWith(text))
	if (label) return label.element.control
	const named = wrapper.findAll('[aria-labelledby]').find((candidate) => {
		const namer = document.getElementById(candidate.attributes('aria-labelledby'))
		return namer?.textContent.trim().startsWith(text)
	})
	if (!named) throw new Error(`No visible name starts with "${text}"`)
	return named.element
}
