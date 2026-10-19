// SPDX-License-Identifier: AGPL-3.0-or-later
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('./chime.js', () => ({ playChime: vi.fn(() => 0) }))
vi.mock('./voice.js', () => ({ speak: vi.fn(async () => 'piper'), browserEngine: vi.fn(() => ({ available: () => false })) }))
vi.mock('../tts/piper.js', () => ({ piperEngine: vi.fn(() => ({ available: () => true, prepare: () => Promise.resolve() })) }))

import { testAnnouncement } from './announce.js'
import { speak } from './voice.js'

describe('the sample an admin hears from "Probar aviso"', () => {
	beforeEach(() => vi.clearAllMocks())

	it('is said the way a real call is said: unidad, kind and number, then where to go, with no "Turno"', async () => {
		await testAnnouncement({ volume: 60, voice: 'mujer' })

		expect(speak).toHaveBeenCalledTimes(1)
		expect(speak.mock.calls[0][0]).toBe('Farmacia, preferente 12. Diríjase a ventanilla 2.')
	})
})
